<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import {
  cerrarSesionArqueo,
  descargarInformeArqueoPdf,
  entradaCajaArqueo,
  introducirArqueo,
  leerCajonDispositivo,
  obtenerArqueo,
  salidaCajaArqueo,
} from '@/api/ventas'
import type { ArqueoLinea, ArqueoResponse } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { ANCHO_TICKET, centrarTicket } from '@/composables/comprobanteTarjeta'
import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import { TICKET_MARCA_GRANDE } from '@/config/documentos-plantillas/ticket-texto'
import { usePdfPreview } from '@/composables/usePdfPreview'
import { usePermisos } from '@/composables/usePermisos'
import { useAuthStore } from '@/stores/auth'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { api } from '@/api/client'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

type Opt = { value: string; label: string }
type PuestoOpt = Opt & { tiendaCodigo: string; ultSesion: number }
type MovTipo = 'entrada' | 'salida'

const puestoContexto = usePuestoContextoStore()
const auth = useAuthStore()
const { puede } = usePermisos()
const puedeVer = computed(() => puede('ventas-arqueo', 'ver'))
const puedeIntroducir = computed(() => puede('ventas-arqueo', 'editar'))
const puedeRepetir = computed(() => puede('ventas-arqueo', 'crear'))
const puedeMovimiento = computed(() => puede('ventas-arqueo', 'crear'))
const puedeCerrar = computed(() => puede('ventas-arqueo', 'eliminar'))

const loading = ref(false)
const loadingOpts = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const data = ref<ArqueoResponse | null>(null)
const tiendas = ref<Opt[]>([])
const puestosAll = ref<PuestoOpt[]>([])
const form = ref({ puesto: '', sesion: 1 })
const editando = ref(false)
const { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf } = usePdfPreview('Informe de arqueo')
const entrados = ref<Record<string, number>>({})
const monedasPorForma = ref<Record<string, number[]>>({})
const printArea = ref<HTMLElement | null>(null)

const modalCerrar = ref(false)
const cierreForm = ref({
  salidaBanco: 0,
  salidaSiguienteSesion: 0,
  aplicarDescuadre: true,
  forzar: false,
  formaPagoEfectivo: '',
})
const descuadrePreview = ref(0)

const modalMov = ref(false)
const movTipo = ref<MovTipo>('entrada')
const movForm = ref({ formaPago: '', importe: 0, concepto: '' })

const puestoSel = computed(() => puestosAll.value.find((p) => p.value === form.value.puesto) ?? null)
const empresaArqueo = computed(() => String(puestoSel.value?.tiendaCodigo ?? '').trim())
const tiendaLabel = computed(() => {
  const cod = empresaArqueo.value
  if (!cod) return '—'
  const t = tiendas.value.find((x) => x.value === cod)
  return t ? t.label : cod
})

const estadoLabel = computed(() => {
  const e = data.value?.estado
  if (e === 'abierta_arqueada') return 'Abierta (arqueada)'
  if (e === 'cerrada') return 'Cerrada'
  if (e === 'abierta') return 'Abierta'
  return e ?? '—'
})

const lineasEdit = computed(() => data.value?.lineas ?? [])

const formasMovimiento = computed(() =>
  (data.value?.lineas ?? []).filter((l) => l.cuentaParaArqueo)
)

/** Formas Agrupacion=0 para salida banco / fondo siguiente sesion. */
const formasEfectivoCierre = computed(() =>
  (data.value?.lineas ?? []).filter((l) => l.cuentaParaArqueo && (l.agrupacion ?? 0) === 0)
)

const totalesEdit = computed(() => {
  let acum = 0
  let ent = 0
  for (const l of lineasEdit.value) {
    if (!l.cuentaParaArqueo) continue
    acum += Number(l.acumulado) || 0
    ent += Number(entrados.value[l.formaPago] ?? l.entrado) || 0
  }
  return {
    acumulado: round2(acum),
    entrado: round2(ent),
    diferencia: round2(ent - acum),
  }
})

const puedeAbrirIntroducir = computed(() => {
  if (!data.value || !puedeIntroducir.value) return false
  return Boolean(data.value.accionesPermitidas?.introducir)
})

const puedeAbrirRepetir = computed(() => {
  if (!data.value || !puedeRepetir.value) return false
  return Boolean(data.value.accionesPermitidas?.repetir)
})

const puedeAbrirCerrar = computed(() => {
  if (!data.value || !puedeCerrar.value || editando.value) return false
  return Boolean(data.value.accionesPermitidas?.cerrar)
})

const puedeAbrirEntrada = computed(() => {
  if (!data.value || !puedeMovimiento.value || editando.value) return false
  return Boolean(data.value.accionesPermitidas?.entrada)
})

const puedeAbrirSalida = computed(() => {
  if (!data.value || !puedeMovimiento.value || editando.value) return false
  return Boolean(data.value.accionesPermitidas?.salida)
})

const puedeLeerCajon = computed(() => {
  if (!data.value || !puedeIntroducir.value) return false
  return Boolean(data.value.accionesPermitidas?.leerCajon)
})

const puedeImprimirTermica = computed(() => {
  if (!data.value || !puedeVer.value) return false
  return Boolean(data.value.accionesPermitidas?.imprimirTermica ?? true)
})

function round2(n: number) {
  return Math.round(n * 100) / 100
}

function diffLinea(l: ArqueoLinea) {
  const ent = Number(entrados.value[l.formaPago] ?? l.entrado) || 0
  return round2(ent - (Number(l.acumulado) || 0))
}

/** Descuadre efectivo (Agrupacion=0) aproximado en cliente. */
function calcDescuadreEfectivo(res: ArqueoResponse) {
  let d = 0
  for (const l of res.lineas) {
    if (!l.cuentaParaArqueo) continue
    if ((l.agrupacion ?? 0) !== 0) continue
    d += (Number(l.entrado) || 0) - (Number(l.acumulado) || 0)
  }
  return round2(d)
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const [tiendasRes, puestosRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }),
      api.get('/api/mantenimiento/puestos-trabajo', { params: { pageSize: 500 } }),
    ])
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
    puestosAll.value = (puestosRes.data.items ?? [])
      .map(
        (p: {
          codigo?: string
          descripcion?: string
          tiendaCodigo?: string | null
          ultSesion?: number | null
        }) => {
          const codigo = String(p.codigo ?? '').trim()
          const tiendaCodigo = String(p.tiendaCodigo ?? '').trim()
          const ult = Number(p.ultSesion ?? 0)
          return {
            value: codigo,
            label: `${codigo} — ${p.descripcion ?? ''}${tiendaCodigo ? ` (tienda ${tiendaCodigo})` : ''}`,
            tiendaCodigo,
            ultSesion: ult > 0 ? ult : 1,
          }
        }
      )
      .sort((a: PuestoOpt, b: PuestoOpt) => a.value.localeCompare(b.value, undefined, { numeric: true }))

    const puestoPc = String(puestoContexto.puestoCodigo ?? '').trim()
    if (puestoPc && puestosAll.value.some((p) => p.value === puestoPc)) {
      form.value.puesto = puestoPc
    } else if (!form.value.puesto && puestosAll.value.length) {
      form.value.puesto = puestosAll.value[0].value
    }
    aplicarSesionDelPuesto()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar tiendas/puestos')
  } finally {
    loadingOpts.value = false
  }
}

function aplicarSesionDelPuesto() {
  const p = puestoSel.value
  if (!p) return
  form.value.sesion = p.ultSesion
}

watch(
  () => form.value.puesto,
  () => {
    editando.value = false
    aplicarSesionDelPuesto()
  }
)

function initEntrados(res: ArqueoResponse) {
  const map: Record<string, number> = {}
  for (const l of res.lineas) {
    map[l.formaPago] = Number(l.entrado) || 0
  }
  entrados.value = map
  monedasPorForma.value = {}
}

async function cargar() {
  const emp = empresaArqueo.value
  const pue = form.value.puesto.trim()
  const ses = Number(form.value.sesion)
  if (!emp || !pue || ses <= 0) {
    error.value =
      !emp && pue
        ? `El puesto ${pue} no tiene EmpresaArqueo configurada en Puestos`
        : 'Seleccione puesto y sesion'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  editando.value = false
  modalCerrar.value = false
  modalMov.value = false
  try {
    data.value = await obtenerArqueo(emp, pue, ses)
    initEntrados(data.value)
    mensaje.value = `Arqueo actualizado: puesto ${pue}, sesión ${ses}.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el arqueo')
    data.value = null
  } finally {
    loading.value = false
  }
}

function empezarIntroducir(repetir: boolean) {
  if (!data.value) return
  if (repetir && !puedeAbrirRepetir.value) {
    error.value = 'No tiene permiso para repetir el arqueo'
    return
  }
  if (!repetir && !puedeAbrirIntroducir.value) {
    error.value = 'No se puede introducir arqueo en esta sesion'
    return
  }
  error.value = null
  mensaje.value = null
  if (repetir) {
    const map: Record<string, number> = {}
    for (const l of data.value.lineas) {
      map[l.formaPago] = 0
    }
    entrados.value = map
  } else {
    initEntrados(data.value)
  }
  editando.value = true
}

function cancelarEdicion() {
  editando.value = false
  if (data.value) initEntrados(data.value)
}

async function confirmarIntroducir() {
  if (!data.value) return
  const emp = empresaArqueo.value
  const pue = form.value.puesto.trim()
  const ses = Number(form.value.sesion)
  const forzar = Boolean(data.value.arqueada)
  const dif = totalesEdit.value.diferencia
  if (dif !== 0) {
    const ok = window.confirm(
      `Hay una diferencia total de ${dif.toFixed(2)}. ¿Confirmar el arqueo igualmente?`
    )
    if (!ok) return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const lineas = lineasEdit.value
      .filter((l) => l.cuentaParaArqueo)
      .map((l) => {
        const monedas = monedasPorForma.value[l.formaPago]
        return {
          formaPago: l.formaPago,
          entrado: Number(entrados.value[l.formaPago] ?? 0) || 0,
          ...(monedas && monedas.some((m) => m !== 0) ? { monedas } : {}),
        }
      })
    data.value = await introducirArqueo(emp, pue, ses, {
      lineas,
      forzarRepeticion: forzar,
    })
    initEntrados(data.value)
    editando.value = false
    mensaje.value = forzar ? 'Arqueo repetido correctamente' : 'Arqueo introducido correctamente'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el arqueo')
  } finally {
    saving.value = false
  }
}

function abrirCerrar() {
  if (!data.value || !puedeAbrirCerrar.value) return
  descuadrePreview.value = calcDescuadreEfectivo(data.value)
  const sugerida =
    String(data.value.formaPagoEfectivoSugerida ?? '').trim() ||
    formasEfectivoCierre.value.find((l) => (Number(l.entrado) || 0) > 0)?.formaPago ||
    formasEfectivoCierre.value[0]?.formaPago ||
    ''
  cierreForm.value = {
    salidaBanco: 0,
    salidaSiguienteSesion: 0,
    aplicarDescuadre: true,
    forzar: false,
    formaPagoEfectivo: sugerida,
  }
  error.value = null
  modalCerrar.value = true
}

async function confirmarCerrar() {
  if (!data.value) return
  const emp = empresaArqueo.value
  const pue = form.value.puesto.trim()
  const ses = Number(form.value.sesion)
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await cerrarSesionArqueo(emp, pue, ses, {
      salidaBanco: Number(cierreForm.value.salidaBanco) || 0,
      salidaSiguienteSesion: Number(cierreForm.value.salidaSiguienteSesion) || 0,
      aplicarDescuadre: cierreForm.value.aplicarDescuadre,
      forzar: cierreForm.value.forzar,
      formaPagoEfectivo: cierreForm.value.formaPagoEfectivo.trim() || undefined,
    })
    modalCerrar.value = false
    form.value.sesion = res.sesionNueva.sesion
    data.value = res.sesionNueva
    initEntrados(data.value)
    const p = puestosAll.value.find((x) => x.value === pue)
    if (p) p.ultSesion = res.sesionNueva.sesion
    const fp = cierreForm.value.formaPagoEfectivo.trim()
    mensaje.value = `Sesion ${ses} cerrada. Nueva sesion ${res.sesionNueva.sesion} abierta. Descuadre: ${res.descuadreEfectivo.toFixed(2)}${fp ? ` · Efectivo: ${fp}` : ''}`
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo cerrar la sesion')
    error.value = msg
    if (msg.toLowerCase().includes('sin movimientos') || msg.toLowerCase().includes('forzar')) {
      cierreForm.value.forzar = true
    }
  } finally {
    saving.value = false
  }
}

function abrirMovimiento(tipo: MovTipo) {
  if (tipo === 'entrada' && !puedeAbrirEntrada.value) return
  if (tipo === 'salida' && !puedeAbrirSalida.value) return
  movTipo.value = tipo
  const primera = formasMovimiento.value[0]?.formaPago ?? ''
  movForm.value = { formaPago: primera, importe: 0, concepto: '' }
  error.value = null
  modalMov.value = true
}

async function confirmarMovimiento() {
  if (!data.value) return
  const emp = empresaArqueo.value
  const pue = form.value.puesto.trim()
  const ses = Number(form.value.sesion)
  const importe = Number(movForm.value.importe) || 0
  if (!movForm.value.formaPago || importe <= 0) {
    error.value = 'Indique forma de pago e importe > 0'
    return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const payload = {
      formaPago: movForm.value.formaPago,
      importe,
      concepto: movForm.value.concepto.trim() || undefined,
    }
    const esSalida = movTipo.value === 'salida'
    data.value = esSalida
      ? await salidaCajaArqueo(emp, pue, ses, payload)
      : await entradaCajaArqueo(emp, pue, ses, payload)
    initEntrados(data.value)
    modalMov.value = false
    const mov = data.value.ultimoMovimiento
    if (!esSalida) {
      const despues = mov?.acumuladoDespues
      mensaje.value =
        despues != null
          ? `Entrada de caja +${importe.toFixed(2)} en ${mov?.formaPago ?? movForm.value.formaPago}. Acumulado: ${Number(despues).toFixed(2)} (la Diferencia negativa es normal hasta introducir el arqueo físico).`
          : `Entrada de caja registrada (+${importe.toFixed(2)} en Acumulado)`
    } else {
      const base =
        mov?.acumuladoDespues != null
          ? `Salida de caja −${importe.toFixed(2)}. Acumulado ${mov.formaPago}: ${Number(mov.acumuladoDespues).toFixed(2)}`
          : `Salida de caja registrada (−${importe.toFixed(2)} en Acumulado)`
      const avisoTicket = await imprimirComprobanteSalida(data.value)
      mensaje.value = avisoTicket ? `${base}. ${avisoTicket}` : base
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo registrar el movimiento')
  } finally {
    saving.value = false
  }
}

function lineaTicket(izq: string, der: string, ancho = ANCHO_TICKET): string {
  const derecha = der.trim()
  const izquierda = izq.trim()
  if (!derecha) return izquierda.slice(0, ancho)
  const hueco = Math.max(0, ancho - derecha.length - 1)
  return `${izquierda.slice(0, hueco).padEnd(hueco)} ${derecha}`.slice(0, ancho)
}

function eurosTicket(n: number): string {
  return `${n.toFixed(2).replace('.', ',')} EUR`
}

function textoComprobanteSalida(res: ArqueoResponse): string {
  const mov = res.ultimoMovimiento
  const forma = String(mov?.formaPago ?? '').trim()
  const linea = res.lineas.find((l) => l.formaPago === forma)
  const desc = String(linea?.descripcion ?? '').trim()
  const ahora = new Date()
  const fecha = ahora.toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
  const cajero = String(auth.usuario?.nombre || auth.usuario?.codigo || '').trim() || '-'
  const sep = ''.padEnd(ANCHO_TICKET, '-')
  const lines = [
    TICKET_MARCA_GRANDE + centrarTicket('SALIDA DE CAJA', 42),
    '',
    lineaTicket('Empresa', res.empresa),
    lineaTicket('Puesto', res.puesto),
    lineaTicket('Sesion', String(res.sesion)),
    lineaTicket('Fecha', fecha),
    lineaTicket('Cajero', cajero),
    sep,
    lineaTicket('Forma', desc ? `${forma} ${desc}` : forma),
  ]
  const concepto = String(mov?.concepto ?? '').trim()
  if (concepto) lines.push(lineaTicket('Concepto', concepto))
  lines.push(sep)
  lines.push(lineaTicket('IMPORTE', `-${eurosTicket(Number(mov?.importe) || 0)}`))
  if (mov?.acumuladoDespues != null) {
    lines.push(lineaTicket('Acumulado', eurosTicket(Number(mov.acumuladoDespues))))
  }
  if (mov?.valeCodigo) lines.push(lineaTicket('Vale', String(mov.valeCodigo)))
  lines.push(sep)
  return lines.join('\n')
}

/** Imprime el comprobante. Si falla, la salida ya está guardada. */
async function imprimirComprobanteSalida(res: ArqueoResponse): Promise<string> {
  const forma = String(res.ultimoMovimiento?.formaPago ?? '').trim()
  const linea = res.lineas.find((l) => l.formaPago === forma)
  const esEfectivo = (linea?.agrupacion ?? 0) === 0
  try {
    const print = await imprimirTicketTermica({
      puestoCodigo: form.value.puesto.trim(),
      texto: textoComprobanteSalida(res),
      tipo: 'salida-caja',
      empresa: res.empresa,
      sesion: res.sesion,
      abrirCajon: esEfectivo,
    })
    return print.stub
      ? `Comprobante (prueba): ${print.message}`
      : `Comprobante enviado a «${print.impresora}»`
  } catch (e: unknown) {
    error.value = extractApiError(
      e,
      'La salida quedó registrada, pero no se pudo imprimir el comprobante'
    )
    return ''
  }
}

function descargarBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
}

function nombreInforme(ext: string) {
  const d = data.value
  if (!d) return `arqueo.${ext}`
  return `arqueo_${d.empresa}_${d.puesto}_${d.sesion}.${ext}`
}

async function imprimirPantalla() {
  if (!data.value) return
  await nextTick()
  const html = printArea.value?.innerHTML ?? ''
  const doc = `<!doctype html><html><head><title>Arqueo ${data.value.empresa}/${data.value.puesto}/${data.value.sesion}</title>
<style>
body{font-family:Arial,sans-serif;font-size:12px;padding:16px;color:#111}
h1{font-size:16px;margin:0 0 8px}
table{width:auto;border-collapse:collapse;margin-top:10px}
th,td{border-bottom:1px solid #ccc;padding:4px 6px;text-align:left}
td.desc{max-width:42mm;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
td.num,th.num{text-align:right}
.tot{font-weight:700;margin-top:10px}
.meta{color:#444;margin:2px 0}
</style></head><body>${html}</body></html>`
  // Abrir en el mismo gesto del clic (antes de cualquier await async largo).
  const w = window.open('', '_blank', 'width=720,height=900')
  if (!w) {
    descargarBlob(new Blob([doc], { type: 'text/html;charset=utf-8' }), nombreInforme('html'))
    mensaje.value = 'Popup bloqueado: se ha descargado el informe HTML'
    return
  }
  w.document.write(doc)
  w.document.close()
  w.focus()
  w.print()
}

async function abrirInformePdf() {
  if (!data.value) return
  const emp = empresaArqueo.value
  const pue = form.value.puesto.trim()
  const ses = Number(form.value.sesion)
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarInformeArqueoPdf(emp, pue, ses)
    if (blob.type && blob.type.includes('json')) {
      const text = await blob.text()
      let msg = 'Error al generar PDF'
      try {
        const j = JSON.parse(text) as { error?: string }
        if (j.error) msg = j.error
      } catch {
        /* ignore */
      }
      throw new Error(msg)
    }
    abrirPdf(blob, `Arqueo ${emp}/${pue}/${ses}`)
    mensaje.value = 'PDF listo para previsualizar'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    saving.value = false
  }
}

function formaPagoCajon(): string {
  const sugerida = String(data.value?.formaPagoEfectivoSugerida ?? '').trim()
  if (sugerida) return sugerida

  const lineas = (data.value?.lineas ?? []).filter((l) => l.cuentaParaArqueo)
  // Preferir efectivo de cajón físico (AbrirCajon suele ir en Agrupacion 0), no Bizum/digital.
  const conCajonYEfectivo = lineas.find(
    (l) => l.cajonElectronico && (l.agrupacion ?? 0) === 0
  )
  if (conCajonYEfectivo) return conCajonYEfectivo.formaPago

  const efectivo = lineas.find((l) => (l.agrupacion ?? 0) === 0)
  if (efectivo) return efectivo.formaPago

  return formasMovimiento.value[0]?.formaPago || 'EU'
}

async function leerCajon() {
  if (!data.value || !puedeLeerCajon.value) return
  const pue = form.value.puesto.trim()
  const forma = formaPagoCajon()
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await leerCajonDispositivo(pue, { formaPago: forma })
    if (!res.agenteOnline) {
      error.value =
        res.message ||
        'Agente local no disponible (abra Descartes Electron). Para recuento manual use Introducir arqueo.'
      return
    }
    // Stub / sin driver: no es lo mismo que Introducir arqueo; no abrir edición con 0.
    if (res.stub || !res.ok) {
      error.value =
        (res.message || 'Lectura de cajón no disponible') +
        ' Use «Introducir arqueo» para contar a mano.'
      return
    }
    const importe = Number(res.importe) || 0
    if (!editando.value) {
      empezarIntroducir(Boolean(data.value.arqueada))
    }
    entrados.value = { ...entrados.value, [forma]: importe }
    if (Array.isArray(res.monedas) && res.monedas.length) {
      const m = Array.from({ length: 20 }, (_, i) => Number(res.monedas?.[i]) || 0)
      monedasPorForma.value = { ...monedasPorForma.value, [forma]: m }
    }
    mensaje.value = `Cajón leído: ${forma} = ${importe.toFixed(2)}. Revise y pulse Confirmar arqueo.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo leer el cajón')
  } finally {
    saving.value = false
  }
}

function textoTermicoArqueo(): string {
  const d = data.value
  if (!d) return ''
  const lines: string[] = [
    'ARQUEO DE CAJA',
    `Empresa ${d.empresa}  Puesto ${d.puesto}  Sesion ${d.sesion}`,
    `Estado: ${estadoLabel.value}`,
    `Cajero: ${d.cajeroArqueo || '-'}`,
    ''.padEnd(42, '-'),
    'FORMA'.padEnd(14) + 'ENTR'.padStart(9) + 'ACUM'.padStart(9) + 'DIF'.padStart(9),
  ]
  for (const l of d.lineas) {
    if (!l.cuentaParaArqueo) continue
    const dif = l.diferencia ?? l.entrado - l.acumulado
    const desc = (l.descripcion || l.formaPago).slice(0, 14).padEnd(14)
    lines.push(
      desc +
        l.entrado.toFixed(2).padStart(9) +
        l.acumulado.toFixed(2).padStart(9) +
        dif.toFixed(2).padStart(9)
    )
  }
  lines.push(''.padEnd(42, '-'))
  lines.push(
    'TOTALES'.padEnd(14) +
      (d.totalEntrado ?? 0).toFixed(2).padStart(9) +
      (d.totalAcumulado ?? d.totalArqueo).toFixed(2).padStart(9) +
      (d.totalDiferencia ?? 0).toFixed(2).padStart(9)
  )
  return lines.join('\n')
}

async function imprimirTermica() {
  if (!data.value) return
  const pue = form.value.puesto.trim()
  const texto = textoTermicoArqueo()
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await imprimirTicketTermica({
      puestoCodigo: pue,
      texto,
      tipo: 'arqueo',
      empresa: data.value.empresa,
      sesion: data.value.sesion,
    })
    mensaje.value = res.stub ? `Térmica (stub): ${res.message}` : res.message
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir en térmica')
  } finally {
    saving.value = false
  }
}

/** Exporta a Excel (CSV con BOM, separador ; — abre bien en Excel ES). */
function exportarExcel() {
  if (!data.value) return
  const d = data.value
  const esc = (v: string | number) => {
    const s = String(v ?? '')
    if (/[;"\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`
    return s
  }
  const num = (n: number) => (Math.round(n * 100) / 100).toFixed(2).replace('.', ',')
  const lines: string[] = [
    ['Empresa', 'Puesto', 'Sesion', 'Estado', 'Cajero arqueo'].map(esc).join(';'),
    [d.empresa, d.puesto, d.sesion, estadoLabel.value, d.cajeroArqueo || ''].map(esc).join(';'),
    '',
    ['Forma', 'Descripcion', 'Acumulado', 'Entrado', 'Diferencia'].map(esc).join(';'),
  ]
  for (const l of d.lineas) {
    const dif = l.diferencia ?? l.entrado - l.acumulado
    lines.push(
      [l.formaPago, l.descripcion ?? '', num(l.acumulado), num(l.entrado), num(dif)].map(esc).join(';')
    )
  }
  lines.push('')
  lines.push(
    [
      'TOTALES',
      '',
      num(d.totalAcumulado ?? d.totalArqueo),
      num(d.totalEntrado ?? 0),
      num(d.totalDiferencia ?? 0),
    ]
      .map(esc)
      .join(';')
  )
  const bom = '\uFEFF'
  descargarBlob(
    new Blob([bom + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }),
    nombreInforme('csv')
  )
  mensaje.value = 'Excel (CSV) descargado'
}

onMounted(async () => {
  await cargarOpciones()
  if (empresaArqueo.value && form.value.puesto) {
    await cargar()
  }
})
</script>

<template>
  <section class="arqueo-view">
    <h2>Arqueo de caja</h2>
    <p class="hint">
      Puesto de este equipo
      <template v-if="puestoContexto.puestoCodigo">
        (<code>{{ puestoContexto.puestoCodigo }}</code>)</template
      >. La tienda es la empresa de arqueo configurada en el puesto.
    </p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>

    <div class="listado-panel">
    <form class="ficha-header" @submit.prevent="cargar">
      <label class="campo-puesto">
        Puesto
        <select v-model="form.puesto" required :disabled="loadingOpts || !puestosAll.length || editando">
          <option value="" disabled>— seleccionar —</option>
          <option v-for="p in puestosAll" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </label>
      <label class="campo-tienda">
        Tienda arqueo
        <input :value="tiendaLabel" type="text" readonly tabindex="-1" />
      </label>
      <label class="campo-sesion">
        Sesión
        <DecimalInput v-model="form.sesion" :empty-as-null="false" :integer="true" :required="true" :disabled="editando" />
      </label>
      <button type="submit" class="tool-btn primary" :disabled="loading || loadingOpts || editando">
        {{ loading ? 'Consultando…' : 'Consultar' }}
      </button>
    </form>

    <template v-if="data">
      <div class="cab-sesion">
        <div><span class="k">Estado</span> {{ estadoLabel }}</div>
        <div><span class="k">Inicio</span> {{ data.fechaInicio || '—' }}</div>
        <div><span class="k">Fin</span> {{ data.fechaFin || '—' }}</div>
        <div><span class="k">Cajero arqueo</span> {{ data.cajeroArqueo || '—' }}</div>
        <div><span class="k">Tickets</span> {{ data.contadores?.tickets ?? 0 }}</div>
        <div><span class="k">Facturas</span> {{ data.contadores?.facturas ?? 0 }}</div>
        <div><span class="k">Entradas caja</span> {{ data.contadores?.entradaEfectivo ?? 0 }}</div>
        <div><span class="k">Salidas caja</span> {{ data.contadores?.salidaEfectivo ?? 0 }}</div>
      </div>

      <div class="toolbar" v-if="puedeVer">
        <button
          v-if="puedeAbrirIntroducir && !editando"
          type="button"
          class="tool-btn primary"
          :disabled="loading || saving"
          @click="empezarIntroducir(false)"
        >
          Introducir arqueo
        </button>
        <button
          v-if="puedeAbrirRepetir && !editando"
          type="button"
          class="tool-btn"
          :disabled="loading || saving"
          @click="empezarIntroducir(true)"
        >
          Repetir arqueo
        </button>
        <button v-if="editando" type="button" class="tool-btn primary" :disabled="saving" @click="confirmarIntroducir">
          {{ saving ? 'Guardando…' : 'Confirmar Entrado' }}
        </button>
        <button v-if="editando" type="button" class="tool-btn" :disabled="saving" @click="cancelarEdicion">Cancelar</button>
        <button
          v-if="puedeAbrirEntrada"
          type="button"
          class="tool-btn"
          :disabled="loading || saving"
          @click="abrirMovimiento('entrada')"
        >
          Entrada de caja
        </button>
        <button
          v-if="puedeAbrirSalida"
          type="button"
          class="tool-btn"
          :disabled="loading || saving"
          title="Registra la salida e imprime un comprobante en la impresora de tickets"
          @click="abrirMovimiento('salida')"
        >
          Salida de caja
        </button>
        <button
          v-if="puedeAbrirCerrar"
          type="button"
          class="tool-btn danger"
          :disabled="loading || saving"
          @click="abrirCerrar"
        >
          Cerrar sesión
        </button>
        <button
          v-if="puedeLeerCajon"
          type="button"
          class="tool-btn"
          :disabled="loading || saving"
          title="Solo con cajón electrónico (Cashlogy/PayDesk/OPOS). Para contar a mano use Introducir arqueo."
          @click="leerCajon"
        >
          Leer cajón
        </button>
        <span class="toolbar-sep" aria-hidden="true"></span>
        <button type="button" class="tool-btn" :disabled="loading || !data.lineas.length" @click="imprimirPantalla">
          Imprimir pantalla
        </button>
        <button type="button" class="tool-btn" :disabled="loading || saving || !data.lineas.length" @click="abrirInformePdf">
          Informe PDF
        </button>
        <button type="button" class="tool-btn" :disabled="loading || !data.lineas.length" @click="exportarExcel">
          Excel
        </button>
        <button
          v-if="puedeImprimirTermica"
          type="button"
          class="tool-btn"
          :disabled="loading || saving || !data.lineas.length"
          title="Envía el arqueo a la impresora térmica del puesto"
          @click="imprimirTermica"
        >
          Imprimir térmica
        </button>
      </div>

      <p class="total">
        Acumulado: <strong>{{ (data.totalAcumulado ?? data.totalArqueo).toFixed(2) }}</strong>
        · Entrado:
        <strong>{{ editando ? totalesEdit.entrado.toFixed(2) : (data.totalEntrado ?? 0).toFixed(2) }}</strong>
        · Diferencia:
        <strong
          :class="{
            descuadre: (editando ? totalesEdit.diferencia : data.totalDiferencia ?? 0) !== 0,
          }"
          >{{
            (editando ? totalesEdit.diferencia : data.totalDiferencia ?? 0).toFixed(2)
          }}</strong
        >
      </p>
      <p class="hint">
        Acumulado = teórico (ventas + entradas − salidas). Entrado = recuento físico. Diferencia =
        Entrado − Acumulado.
      </p>

      <div class="grid-wrap">
        <table>
          <thead>
            <tr>
              <th class="c-forma">Forma</th>
              <th class="c-desc">Descripción</th>
              <th class="num">Acumulado</th>
              <th class="num">Entrado</th>
              <th class="num">Diferencia</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in data.lineas" :key="l.formaPago" :class="{ dim: !l.cuentaParaArqueo }">
              <td class="c-forma">{{ l.formaPago }}</td>
              <td class="c-desc" :title="l.descripcion || ''">{{ l.descripcion }}</td>
              <td class="num">{{ l.acumulado.toFixed(2) }}</td>
              <td class="num">
                <DecimalInput
                  v-if="editando && l.cuentaParaArqueo"
                  v-model="entrados[l.formaPago]"
                  :empty-as-null="false"
                  class="inp-ent"
                />
                <template v-else>{{ l.entrado.toFixed(2) }}</template>
              </td>
              <td
                class="num"
                :class="{ descuadre: (editando ? diffLinea(l) : l.diferencia ?? l.entrado - l.acumulado) !== 0 }"
              >
                {{ (editando ? diffLinea(l) : l.diferencia ?? l.entrado - l.acumulado).toFixed(2) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div ref="printArea" class="print-only" aria-hidden="true">
        <h1>Arqueo de caja</h1>
        <p class="meta">Empresa {{ data.empresa }} · Puesto {{ data.puesto }} · Sesion {{ data.sesion }}</p>
        <p class="meta">Estado: {{ estadoLabel }} · Cajero: {{ data.cajeroArqueo || '—' }}</p>
        <p class="meta">Inicio: {{ data.fechaInicio || '—' }} · Fin: {{ data.fechaFin || '—' }}</p>
        <table>
          <thead>
            <tr>
              <th>Forma</th>
              <th>Descripcion</th>
              <th class="num">Acumulado</th>
              <th class="num">Entrado</th>
              <th class="num">Diferencia</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="l in data.lineas" :key="'p-' + l.formaPago">
              <td>{{ l.formaPago }}</td>
              <td class="desc">{{ l.descripcion }}</td>
              <td class="num">{{ l.acumulado.toFixed(2) }}</td>
              <td class="num">{{ l.entrado.toFixed(2) }}</td>
              <td class="num">{{ (l.diferencia ?? l.entrado - l.acumulado).toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
        <p class="tot">
          Totales — Acumulado {{ (data.totalAcumulado ?? data.totalArqueo).toFixed(2) }} · Entrado
          {{ (data.totalEntrado ?? 0).toFixed(2) }} · Diferencia
          {{ (data.totalDiferencia ?? 0).toFixed(2) }}
        </p>
      </div>
    </template>
    </div>

    <!-- Modal cerrar sesión -->
    <div v-if="modalCerrar" class="modal-backdrop" @click.self="modalCerrar = false">
      <div class="modal" role="dialog" aria-labelledby="cerrar-title">
        <header class="modal-head">
          <h3 id="cerrar-title">Cerrar sesión de caja</h3>
          <button type="button" class="btn-close" title="Cerrar" @click="modalCerrar = false">×</button>
        </header>
        <form class="ficha-campos" @submit.prevent="confirmarCerrar">
        <p class="hint">
          Descuadre efectivo (Entrado − Acumulado):
          <strong :class="{ descuadre: descuadrePreview !== 0 }">{{ descuadrePreview.toFixed(2) }}</strong>
        </p>
        <p class="hint">
          Salida a banco y a siguiente sesión restan del Acumulado de la forma de efectivo elegida.
          La salida a siguiente sesión se registra como entrada en la sesión nueva.
        </p>
        <label>
          Forma de pago efectivo
          <select v-model="cierreForm.formaPagoEfectivo" required>
            <option v-for="f in formasEfectivoCierre" :key="f.formaPago" :value="f.formaPago">
              {{ f.formaPago }} — {{ f.descripcion || '' }}
              (entrado {{ Number(f.entrado || 0).toFixed(2) }})
            </option>
          </select>
        </label>
        <label>
          Salida a banco
          <DecimalInput v-model="cierreForm.salidaBanco" :empty-as-null="false" />
        </label>
        <label>
          Salida a siguiente sesión (fondo inicial)
          <DecimalInput v-model="cierreForm.salidaSiguienteSesion" :empty-as-null="false" />
        </label>
        <label class="check">
          <input v-model="cierreForm.aplicarDescuadre" type="checkbox" />
          Ajustar Acumulado al Entrado (aplicar descuadre)
        </label>
        <label v-if="cierreForm.forzar" class="check">
          <input v-model="cierreForm.forzar" type="checkbox" />
          Forzar cierre (sesión sin movimientos)
        </label>
        <div class="modal-actions">
          <button type="button" class="tool-btn" :disabled="saving" @click="modalCerrar = false">Cancelar</button>
          <button type="submit" class="tool-btn primary" :disabled="saving">
            {{ saving ? 'Cerrando…' : 'Confirmar cierre' }}
          </button>
        </div>
        </form>
      </div>
    </div>

    <!-- Modal entrada/salida -->
    <div v-if="modalMov" class="modal-backdrop" @click.self="modalMov = false">
      <div class="modal" role="dialog" aria-labelledby="mov-title">
        <header class="modal-head">
          <h3 id="mov-title">{{ movTipo === 'entrada' ? 'Entrada de caja' : 'Salida de caja' }}</h3>
          <button type="button" class="btn-close" title="Cerrar" @click="modalMov = false">×</button>
        </header>
        <form class="ficha-campos" @submit.prevent="confirmarMovimiento">
        <p class="hint">
          <template v-if="movTipo === 'entrada'">
            Suma el importe al <strong>Acumulado</strong> (teórico). La columna Diferencia puede
            ponerse en negativo hasta que cuente el dinero en Entrado.
          </template>
          <template v-else>
            Resta el importe del <strong>Acumulado</strong>. No puede dejarlo por debajo de cero.
            Al registrar se imprime un comprobante en la impresora de tickets.
          </template>
        </p>
        <label>
          Forma de pago
          <select v-model="movForm.formaPago" required>
            <option v-for="f in formasMovimiento" :key="f.formaPago" :value="f.formaPago">
              {{ f.formaPago }} — {{ f.descripcion || '' }}
              (acum. {{ Number(f.acumulado || 0).toFixed(2) }})
            </option>
          </select>
        </label>
        <label>
          Importe
          <DecimalInput v-model="movForm.importe" :empty-as-null="false" :required="true" />
        </label>
        <label>
          Concepto
          <input v-model="movForm.concepto" type="text" maxlength="50" placeholder="Opcional" />
        </label>
        <div class="modal-actions">
          <button type="button" class="tool-btn" :disabled="saving" @click="modalMov = false">Cancelar</button>
          <button type="submit" class="tool-btn primary" :disabled="saving">
            {{ saving ? 'Guardando…' : 'Registrar' }}
          </button>
        </div>
        </form>
      </div>
    </div>
  </section>

  <PdfPreviewModal
    :open="pdfOpen"
    :url="pdfUrl"
    :titulo="pdfTitulo"
    @cerrar="cerrarPdf"
  />
</template>

<style scoped>
.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0.25rem 0 0.6rem;
}
.arqueo-view .listado-panel {
  min-width: 0;
  width: fit-content;
  max-width: 100%;
}
.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.65rem;
  align-items: end;
  margin: 0 0 0.35rem;
  padding: 0.4rem 0.5rem;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
}
.ficha-header label {
  display: grid;
  gap: 0.1rem;
  font-size: 0.75rem;
}
.ficha-header input,
.ficha-header select {
  padding: 0.15rem 0.35rem;
  border: 1px solid #c5cdd8;
  border-radius: 3px;
  font-size: 0.78rem;
  height: 1.65rem;
  box-sizing: border-box;
}
.campo-puesto select {
  width: 16rem;
  max-width: 100%;
}
.campo-tienda input {
  width: 11rem;
  background: #f8fafc;
  color: #334155;
}
.campo-sesion :deep(input) {
  width: 4.5rem;
}
.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}
.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}
.tool-btn.danger {
  color: #b91c1c;
  border-color: #fecaca;
}
.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.toolbar-sep {
  width: 1px;
  align-self: stretch;
  margin: 0.1rem 0.15rem;
  background: #cbd5e1;
}
.cab-sesion {
  display: grid;
  grid-template-columns: repeat(4, max-content);
  gap: 0.35rem 1.1rem;
  font-size: 0.78rem;
  margin-bottom: 0.35rem;
  padding: 0.45rem 0.55rem;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
}
.cab-sesion .k {
  display: block;
  font-size: 0.7rem;
  color: #64748b;
}
.grid-wrap {
  width: fit-content;
  max-width: 100%;
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
.grid-wrap table {
  width: auto;
  border-collapse: collapse;
  font-size: 0.8rem;
  table-layout: fixed;
}
.grid-wrap th,
.grid-wrap td {
  border: 1px solid #cbd5e1;
  padding: 0.15rem 0.35rem;
  vertical-align: middle;
}
.grid-wrap th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  font-weight: 600;
  text-align: center;
}
.c-forma {
  width: 3.4rem;
}
.c-desc {
  width: 14rem;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.num {
  width: 6.2rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.inp-ent {
  width: 100%;
  max-width: 5.6rem;
  text-align: right;
  box-sizing: border-box;
}
.dim {
  opacity: 0.55;
}
.total {
  font-size: 0.95rem;
  margin: 0.45rem 0;
}
.descuadre {
  color: #b91c1c;
  font-weight: 600;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #166534;
}
.print-only {
  position: absolute;
  left: -9999px;
  top: 0;
  width: 1px;
  height: 1px;
  overflow: hidden;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1.5rem 1rem;
  overflow: auto;
  background: rgb(15 23 42 / 45%);
}
.modal {
  width: min(28rem, 96vw);
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border: 1px solid #94a3b8;
  border-radius: 10px;
  box-shadow: 0 12px 40px rgb(15 23 42 / 25%);
}
.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.55rem 0.75rem;
  background: linear-gradient(180deg, #e8eef5 0%, #d7e0ea 100%);
  border-bottom: 1px solid #94a3b8;
  border-radius: 10px 10px 0 0;
}
.modal-head h3 {
  margin: 0;
  font-size: 1rem;
}
.btn-close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
  color: #475569;
}
.ficha-campos {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.7rem 0.8rem 0.85rem;
}
.ficha-campos label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}
.ficha-campos input,
.ficha-campos select {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
}
.ficha-campos label.check {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
  margin-top: 0.25rem;
}
</style>
