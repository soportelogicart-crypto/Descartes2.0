import { getDescartesBridge } from '@/bridge/electron'
import { cargarPuesto, resolverNombreImpresoraDoc } from '@/composables/impresionDocumentoA4Shared'
import {
  abrirVentanaPreview,
  escribirVentanaPreview,
  PREVIEW_MSG_ERROR,
  PREVIEW_MSG_IMPRIMIR,
} from '@/composables/previewDocumentoVentana'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const MAX_FILAS_IMPRESION = 2500

type ResultadoImpresion = { ok: boolean; message: string }

const impresionDesdePreview = new WeakMap<Window, () => Promise<ResultadoImpresion>>()

let listenerPreviewRegistrado = false

function registrarListenerImpresionPreview(): void {
  if (listenerPreviewRegistrado || typeof window === 'undefined') return
  listenerPreviewRegistrado = true
  window.addEventListener('message', (e: MessageEvent) => {
    if ((e.data as { tipo?: string } | null)?.tipo !== PREVIEW_MSG_IMPRIMIR) return
    const ventana = e.source
    if (!(ventana instanceof Window)) return
    const ejecutar = impresionDesdePreview.get(ventana)
    if (!ejecutar) return
    void ejecutar().then((res) => {
      if (!res.ok && !ventana.closed) {
        ventana.postMessage({ tipo: PREVIEW_MSG_ERROR, mensaje: res.message }, '*')
      }
    })
  })
}

/** Impresora «Listados» en Generales II del puesto (legacy imp80 / impresora80). */
export async function resolverImpresoraListadosPuesto(): Promise<{ nombre: string; id: number | null }> {
  const puestoCodigo = usePuestoContextoStore().puestoCodigo?.trim() ?? ''
  if (!puestoCodigo) {
    return { nombre: '', id: null }
  }
  try {
    const puesto = await cargarPuesto(puestoCodigo)
    return await resolverNombreImpresoraDoc(puesto, 'imp80', 'impresora80')
  } catch {
    return { nombre: '', id: null }
  }
}

/** Vista previa / impresión A4 sencilla: cabecera + tabla HTML. */
export function construirListadoHtml(opciones: {
  titulo: string
  subtitulo?: string
  metaLineas?: string[]
  thead: string[]
  filas: (string | number)[][]
  pie?: string[]
}): string {
  const esc = (s: string) =>
    s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
  const thead = opciones.thead.map((h) => `<th>${esc(h)}</th>`).join('')
  const body = opciones.filas
    .map(
      (fila) =>
        `<tr>${fila.map((c, i) => `<td class="${i > 0 ? 'num' : ''}">${esc(String(c))}</td>`).join('')}</tr>`
    )
    .join('')
  const meta = (opciones.metaLineas ?? [])
    .map((l) => `<p class="meta">${esc(l)}</p>`)
    .join('')
  const pie =
    opciones.pie && opciones.pie.length
      ? `<p class="tot">${opciones.pie.map(esc).join(' · ')}</p>`
      : ''
  const subt = opciones.subtitulo ? `<p class="sub">${esc(opciones.subtitulo)}</p>` : ''
  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opciones.titulo)}</title>
<style>
@page { margin: 14mm; }
body{font-family:Arial,sans-serif;font-size:11px;color:#111;padding:0;margin:0}
h1{font-size:16px;margin:0 0 4px}
.sub{color:#444;margin:0 0 8px;font-size:12px}
.meta{color:#555;margin:2px 0;font-size:10px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border-bottom:1px solid #ccc;padding:3px 5px;text-align:left}
th{background:#f1f5f9;font-size:10px}
td.num,th.num{text-align:right}
.tot{font-weight:700;margin-top:12px;font-size:11px}
</style></head><body>
<h1>${esc(opciones.titulo)}</h1>${subt}${meta}
<table><thead><tr>${thead}</tr></thead><tbody>${body}</tbody></table>
${pie}
</body></html>`
}

/** Envía el HTML del listado a la impresora (Electron o diálogo del sistema). */
export async function enviarListadoHtmlAImpresora(
  html: string,
  ventanaPreview?: Window | null
): Promise<ResultadoImpresion> {
  const bridge = getDescartesBridge()
  if (bridge?.printHtml) {
    const imp = await resolverImpresoraListadosPuesto()
    const intentos: { impresora?: string; impresoraId?: number; silent: boolean }[] = []
    if (imp.nombre || imp.id) {
      intentos.push({
        impresora: imp.nombre || undefined,
        impresoraId: imp.id ?? undefined,
        silent: true,
      })
    }
    intentos.push({ silent: false })

    let ultimoError = 'No se pudo imprimir'
    for (const intento of intentos) {
      const res = await bridge.printHtml({ html, ...intento })
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
  filenameFallback?: string
  /** Si false, imprime directo sin ventana de previsualización. */
  preview?: boolean
  /** Documento HTML completo (p. ej. informe ABC por bloques). Omite thead/filas. */
  html?: string
}): Promise<ResultadoImpresion> {
  let html: string
  if (opciones.html) {
    html = opciones.html
  } else {
    const thead = opciones.thead ?? []
    const filasRaw = opciones.filas ?? []
    const filas = filasRaw.slice(0, MAX_FILAS_IMPRESION)
    const pie = [...(opciones.pie ?? [])]
    if (filasRaw.length > MAX_FILAS_IMPRESION) {
      pie.push(`Impresión limitada a ${MAX_FILAS_IMPRESION} filas (${filasRaw.length} en pantalla)`)
    }
    html = construirListadoHtml({ ...opciones, thead, filas, pie })
  }

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

    impresionDesdePreview.set(ventana, () => enviarListadoHtmlAImpresora(html, ventana))

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

  return enviarListadoHtmlAImpresora(html)
}
