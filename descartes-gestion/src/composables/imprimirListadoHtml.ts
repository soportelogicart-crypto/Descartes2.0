import { getDescartesBridge } from '@/bridge/electron'
import { cargarPuesto, resolverNombreImpresoraDoc } from '@/composables/impresionDocumentoA4Shared'
import {
  abrirVentanaPreview,
  escribirVentanaPreview,
  PREVIEW_MSG_ERROR,
  PREVIEW_MSG_IMPRIMIR,
  PREVIEW_MSG_RESULTADO,
} from '@/composables/previewDocumentoVentana'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import {
  aplicarApaisadoHtml,
  ESTILO_LISTADO_APAISADO,
  ESTILOS_LISTADO_A4,
  orientacionDocumentoHtml,
  orientacionPorContenido,
  type ListadoAlineacion,
  type ListadoColumnaImpresion,
  type ListadoOrientacion,
} from '@/composables/listadoPrintStyles'

const MAX_FILAS_IMPRESION = 2500

type ResultadoImpresion = { ok: boolean; message: string }

const impresionDesdePreview = new WeakMap<Window, () => Promise<ResultadoImpresion>>()
let previewActiva: { ventana: Window; ejecutar: () => Promise<ResultadoImpresion> } | null = null

let listenerPreviewRegistrado = false

function notificarPreview(ventana: Window, res: ResultadoImpresion): void {
  if (ventana.closed) return
  ventana.postMessage(
    {
      tipo: res.ok ? PREVIEW_MSG_RESULTADO : PREVIEW_MSG_ERROR,
      mensaje: res.message,
    },
    '*'
  )
}

function registrarListenerImpresionPreview(): void {
  if (listenerPreviewRegistrado || typeof window === 'undefined') return
  listenerPreviewRegistrado = true
  window.addEventListener('message', (e: MessageEvent) => {
    if ((e.data as { tipo?: string } | null)?.tipo !== PREVIEW_MSG_IMPRIMIR) return
    if (e.origin !== window.location.origin && e.origin !== 'null') return
    const fuente = e.source
    const porVentana = fuente instanceof Window ? impresionDesdePreview.get(fuente) : undefined
    const actual = previewActiva
    const ejecutar =
      porVentana ?? (actual && !actual.ventana.closed ? actual.ejecutar : undefined)
    const ventana = fuente instanceof Window ? fuente : actual?.ventana
    if (!ejecutar || !ventana) return
    void ejecutar().then((res) => notificarPreview(ventana, res))
  })
}

type ImpresoraResuelta = { nombre: string; id: number | null }

const SIN_IMPRESORA: ImpresoraResuelta = { nombre: '', id: null }

/** Impresora «Listados» en Generales II del puesto (legacy imp80 / impresora80). */
export async function resolverImpresoraListadosPuesto(): Promise<ImpresoraResuelta> {
  const { listados } = await impresorasDelPuesto()
  return listados
}

async function impresorasDelPuesto(): Promise<{ listados: ImpresoraResuelta; tickets: ImpresoraResuelta }> {
  const puestoCodigo = usePuestoContextoStore().puestoCodigo?.trim() ?? ''
  if (!puestoCodigo) {
    return { listados: SIN_IMPRESORA, tickets: SIN_IMPRESORA }
  }
  try {
    const puesto = await cargarPuesto(puestoCodigo)
    const [listados, tickets] = await Promise.all([
      resolverNombreImpresoraDoc(puesto, 'imp80', 'impresora80'),
      resolverNombreImpresoraDoc(puesto, 'impresoraTickets', null),
    ])
    return { listados, tickets }
  } catch {
    return { listados: SIN_IMPRESORA, tickets: SIN_IMPRESORA }
  }
}

function esLaImpresoraDeTickets(listados: ImpresoraResuelta, tickets: ImpresoraResuelta): boolean {
  const a = listados.nombre.trim().toLowerCase()
  const b = tickets.nombre.trim().toLowerCase()
  return a !== '' && a === b
}

/** Vista previa / impresión A4 sencilla: cabecera + tabla HTML. */
export function construirListadoHtml(opciones: {
  titulo: string
  subtitulo?: string
  metaLineas?: string[]
  thead: string[]
  filas: (string | number)[][]
  pie?: string[]
  columnas?: ListadoColumnaImpresion[]
  /** Sin indicar: apaisado si las columnas no caben en A4 vertical. */
  orientacion?: ListadoOrientacion
}): string {
  const esc = (s: string) =>
    s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
  const encabezadoTexto = /fecha|c[oó]digo|documento|cliente|art[ií]culo|descripci[oó]n|concepto|tienda|almac[eé]n|nombre|tipo/i
  const pareceNumero = (valor: string | number) => {
    if (typeof valor === 'number') return Number.isFinite(valor)
    const texto = String(valor).trim()
    if (!texto) return true
    return /^[-+]?\s*\d{1,3}(?:[.\s]\d{3})*(?:,\d+)?\s*(?:€|%)?$/.test(texto)
  }
  const alineacionColumna = (i: number): ListadoAlineacion => {
    const explicita = opciones.columnas?.[i]?.alineacion
    if (explicita) return explicita
    if (encabezadoTexto.test(opciones.thead[i] ?? '')) return 'left'
    const valores = opciones.filas.map((fila) => fila[i]).filter((valor) => String(valor ?? '').trim() !== '')
    return valores.length > 0 && valores.every(pareceNumero) ? 'right' : 'left'
  }
  const alineaciones = opciones.thead.map((_, i) => alineacionColumna(i))
  const colgroup = opciones.thead
    .map((_, i) => {
      const ancho = opciones.columnas?.[i]?.ancho
      return `<col${ancho ? ` style="width:${esc(ancho)}"` : ''}/>`
    })
    .join('')
  const thead = opciones.thead
    .map((h, i) => `<th class="align-${alineaciones[i]}">${esc(h)}</th>`)
    .join('')
  const body = opciones.filas
    .map(
      (fila) =>
        `<tr>${fila
          .map((c, i) => `<td class="align-${alineaciones[i] ?? 'left'}">${esc(String(c))}</td>`)
          .join('')}</tr>`
    )
    .join('')
  const meta = (opciones.metaLineas ?? [])
    .map((l) => `<p>${esc(l)}</p>`)
    .join('')
  const pie =
    opciones.pie && opciones.pie.length
      ? `<p class="listado-totales">${opciones.pie.map(esc).join(' · ')}</p>`
      : ''
  const subt = opciones.subtitulo
    ? `<p class="listado-subtitulo">${esc(opciones.subtitulo)}</p>`
    : ''
  const fechaImpresion = new Date().toLocaleString('es-ES')
  const orientacion =
    opciones.orientacion ?? orientacionPorContenido(opciones.thead, opciones.filas)
  const estiloApaisado = orientacion === 'horizontal' ? ESTILO_LISTADO_APAISADO : ''
  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opciones.titulo)}</title>
<style>${ESTILOS_LISTADO_A4}${estiloApaisado}</style></head><body><main class="folio">
<header class="listado-cabecera">
  <div><h1 class="listado-titulo">${esc(opciones.titulo)}</h1>${subt}</div>
  <div class="listado-meta">${meta}<p><strong>Impresión:</strong> ${esc(fechaImpresion)}</p></div>
</header>
<table class="listado-tabla"><colgroup>${colgroup}</colgroup><thead><tr>${thead}</tr></thead><tbody>${body}</tbody></table>
${pie}</main></body></html>`
}

/** Envía el HTML del listado a la impresora (Electron o diálogo del sistema). */
export async function enviarListadoHtmlAImpresora(
  html: string,
  ventanaPreview?: Window | null,
  opciones?: { apaisado?: boolean }
): Promise<ResultadoImpresion> {
  const bridge = getDescartesBridge()
  if (bridge?.printHtml) {
    const { listados, tickets } = await impresorasDelPuesto()
    const intentos: { impresora?: string; impresoraId?: number; silent: boolean }[] = []
    // La de tickets (TICKETU) es RAW: un listado A4 entra en la cola y no sale en papel.
    if ((listados.nombre || listados.id) && !esLaImpresoraDeTickets(listados, tickets)) {
      intentos.push({
        impresora: listados.nombre || undefined,
        impresoraId: listados.id ?? undefined,
        silent: true,
      })
    }
    // Sin impresora de listados no se manda a la predeterminada: en caja suele ser la de tickets.
    intentos.push({ silent: false })

    let ultimoError = 'No se pudo imprimir'
    for (const intento of intentos) {
      const res = await bridge.printHtml({ html, landscape: opciones?.apaisado === true, ...intento })
      if (res.ok) {
        return {
          ok: true,
          message: res.message ?? (res.impresora ? `Enviado a ${res.impresora}` : 'Impresión enviada'),
        }
      }
      ultimoError = res.message?.trim() || ultimoError
    }
    return { ok: false, message: ultimoError }
  }

  const w = ventanaPreview && !ventanaPreview.closed ? ventanaPreview : null
  if (w) {
    w.focus()
    w.print()
    return { ok: true, message: 'Diálogo de impresión abierto' }
  }

  return { ok: false, message: 'No hay bridge de impresión; use Imprimir en la vista previa' }
}

export async function imprimirListadoHtml(opciones: {
  titulo: string
  subtitulo?: string
  metaLineas?: string[]
  thead?: string[]
  filas?: (string | number)[][]
  pie?: string[]
  columnas?: ListadoColumnaImpresion[]
  /** Sin indicar se decide midiendo las columnas (tabla o HTML completo). */
  orientacion?: ListadoOrientacion
  filenameFallback?: string
  /** Si false, imprime directo sin ventana de previsualización. */
  preview?: boolean
  /** Documento HTML completo (p. ej. informe ABC por bloques). Omite thead/filas. */
  html?: string
}): Promise<ResultadoImpresion> {
  let html: string
  let orientacion: ListadoOrientacion
  if (opciones.html) {
    orientacion = opciones.orientacion ?? orientacionDocumentoHtml(opciones.html)
    html = orientacion === 'horizontal' ? aplicarApaisadoHtml(opciones.html) : opciones.html
  } else {
    const thead = opciones.thead ?? []
    const filasRaw = opciones.filas ?? []
    const filas = filasRaw.slice(0, MAX_FILAS_IMPRESION)
    const pie = [...(opciones.pie ?? [])]
    if (filasRaw.length > MAX_FILAS_IMPRESION) {
      pie.push(`Impresión limitada a ${MAX_FILAS_IMPRESION} filas (${filasRaw.length} en pantalla)`)
    }
    orientacion = opciones.orientacion ?? orientacionPorContenido(thead, filas)
    html = construirListadoHtml({ ...opciones, thead, filas, pie, orientacion })
  }

  const apaisado = orientacion === 'horizontal'
  const usarPreview = opciones.preview !== false

  if (usarPreview) {
    registrarListenerImpresionPreview()
    const ventana = abrirVentanaPreview(opciones.titulo)
    if (!ventana) {
      const name = opciones.filenameFallback ?? 'listado.html'
      const blob = new Blob([html], { type: 'text/html;charset=utf-8' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = name
      a.click()
      URL.revokeObjectURL(url)
      return { ok: false, message: 'Popup bloqueado: se descargó HTML para abrir manualmente' }
    }

    const ejecutar = () => enviarListadoHtmlAImpresora(html, ventana, { apaisado })
    impresionDesdePreview.set(ventana, ejecutar)
    previewActiva = { ventana, ejecutar }

    const imp = await resolverImpresoraListadosPuesto()
    escribirVentanaPreview(ventana, html, {
      impresoraNombre: imp.nombre,
      puedeImprimir: true,
    })

    return {
      ok: true,
      message: 'Vista previa abierta. Revise el listado y pulse Imprimir en la ventana.',
    }
  }

  return enviarListadoHtmlAImpresora(html, null, { apaisado })
}
