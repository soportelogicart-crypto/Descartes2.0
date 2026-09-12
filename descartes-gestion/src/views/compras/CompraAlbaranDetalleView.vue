<script setup lang="ts">
import { computed, nextTick, onActivated, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { routeNavigationFrom, albaranCompraFocusLineas } from '@/router'
import { api } from '@/api/client'
import {
  actualizarAlbaranCompra,
  actualizarStockAlbaranCompra,
  convertirAlbaranCompraAVenta,
  crearAbonoAlbaranCompra,
  crearAlbaranCompra,
  eliminarAlbaranCompra,
  obtenerAlbaranCompra,
  recuperarAlbaranCompra,
} from '@/api/compras'
import { encolarDesdeAlbaranCompra } from '@/api/etiquetas'
import { resolverArticulo, asignarProveedorHabitualArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import type { AlbaranCompraDetalle, AlbaranCompraLinea, AlbaranCompraPayload } from '@/types/compras'
import { extractApiError, isApiNotFound } from '@/composables/extractApiError'
import {
  imprimirA4CompraPreparado,
  prepararImpresionAlbaranCompra,
  type PrepImpresionA4,
} from '@/composables/useImpresionCompraDocumento'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import CompraLineaPvpModal from '@/components/compras/CompraLineaPvpModal.vue'
import ArticuloAltaModal from '@/components/articulos/ArticuloAltaModal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const puesto = usePuestoContextoStore()
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => onImprimirA4Confirmado() })

const pathInstancia = route.fullPath

/**
 * KeepAlive cachea por fullPath. Al abrir otra pestaña (p. ej. consultar artículo),
 * useRoute() sigue cambiando en la instancia cacheada: hay que ignorar esas rutas
 * y no volver a cargar/reiniciar el borrador al reactivar esta pestaña.
 */
function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const puedeCrear = computed(() => puede('compras', 'crear'))
const puedeEditar = computed(() => puede('compras', 'editar'))
const puedeEliminar = computed(() => puede('compras', 'eliminar'))
const puedeCrearVenta = computed(() => puede('ventas', 'crear'))
const puedeCrearArticulo = computed(() => puede('articulos', 'crear'))
/** Generar cola de etiquetas desde albarán (005 / US5). */
const puedeGenerarEtiquetas = computed(
  () =>
    puede('etiquetas', 'crear') &&
    !!ficha.value &&
    !esNuevo.value &&
    (ficha.value.lineas?.some((l) => String(l.articulo ?? '').trim()) ?? false)
)

const loading = ref(false)
const saving = ref(false)
const guardandoLineas = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ficha = ref<AlbaranCompraDetalle | null>(null)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const confirmBorrar = ref(false)
const confirmStock = ref(false)
const confirmRecuperar = ref(false)
const buscarClienteVentaOpen = ref(false)
const confirmConvertirVentaOpen = ref(false)
const clienteVentaCodigo = ref('')
const clienteVentaNombre = ref('')
const ventaCreadaOpen = ref(false)
const ventaCreadaRef = ref<{ empresa: string; tipo: string; albaran: number } | null>(null)
const confirmProveedorArticulo = ref(false)
const confirmProveedorArticuloMsg = ref('')
const confirmAltaArticulo = ref(false)
const altaArticuloOpen = ref(false)
const altaArticuloQuery = ref('')
const altaArticuloLineaIdx = ref(-1)
const pendingArticuloApply = ref<(() => Promise<void>) | null>(null)
const pendingArticuloLineaIdx = ref(-1)
const pendingArticuloProveedor = ref<{ codigo: string; proveedor: string } | null>(null)
const generandoEtiquetas = ref(false)
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const a4Imprimiendo = ref(false)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const buscarProveedorOpen = ref(false)
const buscarArticuloOpen = ref(false)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
const tiendas = ref<{ value: string; label: string }[]>([])
const almacenes = ref<{ value: number; label: string }[]>([])
const abonoOpen = ref(false)
const abonoNroLins = ref<number[]>([])
const abonoObservacion = ref('')
const confirmTransporte = ref(false)
const confirmPvpCoste = ref(false)
const pvpModalOpen = ref(false)
const pvpLineaIdx = ref(-1)
const pendingLineaIdx = ref(-1)
const pvpPorArticulo = ref(new Map<string, number>())

type FormLinea = {
  nroLin?: number
  articulo: string
  descripcion: string
  cantidad: number
  precio: number
  pjeDto: number
  dto1: number
  dto2: number
  dto3: number
  lote: string
  almacen: number | null
  pedido: number | null
  /** PVP tarifa 1 (PrecioVen1) para Total a PVP / % margen. */
  pvp: number
  costeRef?: { precio: number; dto1: number; dto2: number; dto3: number }
}

const LINEA_CAMPOS: Array<'cantidad' | 'precio' | 'dto1' | 'dto2' | 'dto3'> = [
  'cantidad',
  'precio',
  'dto1',
  'dto2',
  'dto3',
]

const form = ref({
  empresa: '',
  fechaAlbaran: new Date().toISOString().slice(0, 10),
  suAlbaran: '',
  proveedor: '',
  razonSocial: '',
  /** Se toma del proveedor; no se pide en UI (como legacy). */
  fpago: '',
  almacen: null as number | null,
  /** Solo lectura; legacy no permite teclear serie. */
  serie: '',
  proyecto: '',
  albaranDevolucion: false,
  observaciones: '',
  importeTransporte: 0,
  /** Editable como en ponc; con líneas se sincroniza a bruto+transporte. */
  brutoConTransporte: 0,
  coeficienteTransporte: 0,
  lineas: [] as FormLinea[],
})

/** Origen del último ajuste: recalcula el resto al estilo ponc. */
const ajusteTransporteOrigen = ref<'transporte' | 'bruto' | 'coeficiente'>('transporte')

/** Alta guiada: tienda → proveedor → líneas. El nº se asigna al Guardar. */
const pasoAlta = ref<'tienda' | 'proveedor' | 'listo'>('listo')
const tiendaSelectRef = ref<HTMLSelectElement | null>(null)
const albaranInputRef = ref<HTMLInputElement | null>(null)
const proveedorInputRef = ref<HTMLInputElement | null>(null)
const lineasPanelRef = ref<HTMLElement | null>(null)

const mostrarProveedor = computed(() => !esNuevo.value || pasoAlta.value !== 'tienda')
const mostrarExtras = computed(() => !esNuevo.value || pasoAlta.value === 'listo')
const cabeceraCompacta = computed(() => !esNuevo.value && !modoEdicion.value)

const bloqueado = computed(() => {
  if (esNuevo.value) return false
  if (!ficha.value) return true
  return !ficha.value.editable || !!ficha.value.trasCtb || !!ficha.value.actualizado
})

const soloLecturaMotivo = computed(() => {
  if (!ficha.value || esNuevo.value) return null
  if (ficha.value.trasCtb) return 'Traspasado a contabilidad (TrasCtb)'
  if (ficha.value.actualizado) return 'Stock ya actualizado — use Recuperar para editar'
  if (ficha.value.editable === false) return 'Documento no editable'
  return null
})

/** Cabecera: alta o modo Modificar. */
const cabeceraEditables = computed(() => (esNuevo.value || modoEdicion.value) && !bloqueado.value)

const camposEditables = cabeceraEditables

/** Líneas: en alta, tras proveedor (en memoria). En ficha, si el documento es editable. */
const lineasEditables = computed(() => {
  if (esNuevo.value) return pasoAlta.value === 'listo'
  return !!ficha.value && !bloqueado.value
})

const proveedorBusquedaHabilitada = computed(
  () => cabeceraEditables.value && (!esNuevo.value || pasoAlta.value !== 'tienda')
)

const proveedorBloqueado = computed(
  () => !camposEditables.value || (esNuevo.value && pasoAlta.value === 'tienda')
)

const almacenNombre = computed(() => {
  const cod = form.value.almacen
  if (cod == null) return ''
  const found = almacenes.value.find((a) => a.value === cod)
  return found?.label?.replace(/^\s*\d+\s*[-–]\s*/, '') || found?.label || ''
})

const puedeActualizarStock = computed(
  () =>
    puedeEditar.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    !ficha.value.actualizado &&
    !ficha.value.trasCtb
)

/** Legacy «Recuperar»: revertir stock y volver a editable. */
const puedeRecuperar = computed(
  () =>
    puedeEditar.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    !!ficha.value.actualizado &&
    !ficha.value.trasCtb
)

/** Albarán ACTUALIZADO → venta al cliente (PVP tarifa). */
const puedeGenerarVenta = computed(
  () =>
    puedeEditar.value &&
    puedeCrearVenta.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    !!ficha.value.actualizado &&
    !ficha.value.trasCtb &&
    (ficha.value.lineas?.some((l) => String(l.articulo ?? '').trim()) ?? false)
)

const mensajeConfirmConvertirVenta = computed(() => {
  const n = ficha.value?.lineas?.filter((l) => String(l.articulo ?? '').trim()).length ?? 0
  const cli = clienteVentaNombre.value || clienteVentaCodigo.value
  return `Se generará un albarán de venta para el cliente ${cli} con ${n} línea(s) a PVP de tarifa. ¿Continuar?`
})

const mensajeVentaCreada = computed(() => {
  const v = ventaCreadaRef.value
  if (!v) return 'Venta generada.'
  return `Venta ${v.tipo}-${v.albaran} creada. ¿Abrirla ahora?`
})

const puedeAbonar = computed(
  () =>
    puedeCrear.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    !ficha.value.albaranDevolucion &&
    !ficha.value.trasCtb &&
    (ficha.value.lineas?.length ?? 0) > 0
)

const puedeImprimir = computed(() => !!ficha.value && !esNuevo.value)

/** Pedido proveedor; no mostrar el NroLin de la línea (antes iba en #). */
function pedidoVisible(pedido: number | null | undefined, nroLin?: number): number | null {
  const n = Number(pedido)
  if (!Number.isFinite(n) || n <= 0) return null
  if (nroLin != null && Number(nroLin) > 0 && n === Number(nroLin)) return null
  return n
}

function lineaVacia(): FormLinea {
  return {
    articulo: '',
    descripcion: '',
    cantidad: 0,
    precio: 0,
    pjeDto: 0,
    dto1: 0,
    dto2: 0,
    dto3: 0,
    lote: '',
    almacen: form.value.almacen,
    pedido: null,
    pvp: 0,
  }
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function fmtNum(n: number | null | undefined, dec = 2) {
  return Number(n ?? 0).toFixed(dec)
}

function roundN(n: number, dec = 2) {
  const f = 10 ** dec
  return Math.round((n + Number.EPSILON) * f) / f
}

/** Bruto mercancía a precio base (sin transporte), tras dto línea. */
const brutoMercancia = computed(() => {
  let neto = 0
  for (const l of form.value.lineas) {
    if (!String(l.articulo ?? '').trim()) continue
    neto += importeLineaBase(l)
  }
  return roundN(neto, 2)
})

const avisoConsulta = computed(() => {
  if (esNuevo.value || modoEdicion.value) return ''
  if (ficha.value?.trasCtb) return 'Traspasado a contabilidad. Solo lectura.'
  if (ficha.value?.actualizado) {
    return 'Stock actualizado. Use Albarán cliente para generar venta, o Recuperar para modificar.'
  }
  if (soloLecturaMotivo.value) return `Solo lectura — ${soloLecturaMotivo.value}`
  return ''
})

function precioBaseDesdeAlmacenado(precioCt: number, coef: number) {
  if (coef <= 0.0000001) return precioCt
  return roundN(precioCt / (1 + coef), 4)
}

/** Coef. legacy: transporte / bruto mercancía (o transporte / (brutoCon − transporte)). */
function coefDesdeCabecera(
  stored: number,
  transporte: number,
  importeAlb = 0,
  brutoCon = 0,
  brutoLineas?: number
): number {
  if (stored > 0.0000001) return stored
  if (transporte <= 0.0000001) return 0
  if (brutoLineas != null && brutoLineas > 0.0001) return roundN(transporte / brutoLineas, 4)
  if (brutoCon > transporte + 0.0001) return roundN(transporte / (brutoCon - transporte), 4)
  if (importeAlb > transporte + 0.0001) return roundN(transporte / (importeAlb - transporte), 4)
  return 0
}

/** Coeficiente aplicado a Precio CT (form o inferido por transporte/bruto). */
const coeficienteEfectivo = computed(() => {
  const stored = Number(form.value.coeficienteTransporte) || 0
  if (stored > 0.0000001) return stored
  const t = Number(form.value.importeTransporte) || 0
  if (t <= 0.0000001) return 0
  const bt = Number(form.value.brutoConTransporte) || 0
  if (bt > t + 0.0001) return roundN(t / (bt - t), 6)
  const bruto = brutoMercancia.value
  if (bruto > 0.0001) return roundN(t / bruto, 6)
  return coefDesdeCabecera(
    0,
    t,
    Number(ficha.value?.importeAlb ?? 0),
    bt
  )
})

/** Precio con coeficiente (coef = transporte/bruto → Precio×(1+coef)). */
function precioCt(precio: number) {
  return roundN(precio * (1 + coeficienteEfectivo.value), 4)
}

function importeLineaBase(l: FormLinea) {
  const cant = Number(l.cantidad) || 0
  const precio = Number(l.precio) || 0
  return roundN(cant * precio * factorDtoLinea(l), 2)
}

function importeLinea(l: FormLinea) {
  const cant = Number(l.cantidad) || 0
  return roundN(cant * precioCt(Number(l.precio) || 0) * factorDtoLinea(l), 2)
}

/** Precio base en columna Precio (solo lectura: deshacer CT almacenado). */
function precioBaseLinea(l: FormLinea): number {
  if (camposEditables.value) return Number(l.precio) || 0
  const f = ficha.value
  const coef = coefDesdeCabecera(
    Number(f?.coeficienteTransporte ?? form.value?.coeficienteTransporte) || 0,
    Number(f?.importeTransporte ?? form.value?.importeTransporte) || 0,
    Number(f?.importeAlb ?? 0),
    Number(f?.brutoConTransporte ?? form.value.brutoConTransporte) || 0
  )
  return precioBaseDesdeAlmacenado(Number(l.precio) || 0, coef)
}

/** Precio CT: el form guarda coste base; la columna siempre aplica (1+coef). */
function precioCtLinea(l: FormLinea): number {
  return precioCt(Number(l.precio) || 0)
}

/** Precio inicial de línea al resolver artículo (Legacy: PrecioUltimoST, 2 decimales). */
function precioInicialArticulo(art: Record<string, unknown>): number {
  const st = Number(art.precioUltimoST ?? 0)
  if (st > 0) return roundN(st, 2)
  const ult = Number(art.precioUltimo ?? 0)
  if (ult > 0) return roundN(ult, 2)
  const base = Number(art.precioBase ?? 0)
  if (base > 0) return roundN(base, 2)
  const medio = Number(art.precioMedio ?? 0)
  if (medio > 0) return roundN(medio, 2)
  return 0
}

function factorDtoLinea(l: Pick<FormLinea, 'pjeDto' | 'dto1' | 'dto2' | 'dto3'>) {
  const pje = Number(l.pjeDto) || 0
  const d1 = Number(l.dto1) || 0
  const d2 = Number(l.dto2) || 0
  const d3 = Number(l.dto3) || 0
  return (1 - pje / 100) * (1 - d1 / 100) * (1 - d2 / 100) * (1 - d3 / 100)
}

const importeConTransporteMostrado = computed(() => {
  if (ficha.value && !modoEdicion.value) return Number(ficha.value.importeAlb) || 0
  let sum = 0
  for (const l of form.value.lineas) {
    if (!String(l.articulo ?? '').trim()) continue
    sum += importeLinea(l)
  }
  return roundN(sum, 2)
})

function pvpDeLinea(l: FormLinea): number {
  const propio = Number(l.pvp ?? 0)
  if (propio > 0) return propio
  const art = String(l.articulo ?? '').trim()
  if (!art) return 0
  return pvpPorArticulo.value.get(art) ?? 0
}

const totalPvpMostrado = computed(() => {
  let sum = 0
  for (const l of form.value.lineas) {
    const art = String(l.articulo ?? '').trim()
    if (!art) continue
    sum += pvpDeLinea(l) * (Number(l.cantidad) || 0)
  }
  return roundN(sum, 2)
})

/** Coste unitario para margen (con CT en edición; almacenado en lectura). */
function costeUnitMargenLinea(l: FormLinea): number {
  return precioCt(Number(l.precio) || 0)
}

/** Margen % de una línea: (PVP1 − coste) / PVP1. */
function margenLineaPct(l: FormLinea): number {
  const art = String(l.articulo ?? '').trim()
  if (!art) return 0
  const pvp = pvpDeLinea(l)
  if (pvp <= 0.0001) return 0
  const coste = costeUnitMargenLinea(l)
  return roundN(((pvp - coste) / pvp) * 100, 2)
}

/**
 * Legacy: % margen = media ponderada por importe de línea (no margen global del total).
 * Ej. 9999 (PVP≈coste) + 9998 (PVP>>coste) → ~39 %, no ~50 %.
 */
const margenMostrado = computed(() => {
  let peso = 0
  let acum = 0
  for (const l of form.value.lineas) {
    if (!String(l.articulo ?? '').trim()) continue
    const imp = importeLineaBase(l)
    if (imp <= 0.0001) continue
    peso += imp
    acum += imp * margenLineaPct(l)
  }
  if (peso <= 0.0001) return 0
  return roundN(acum / peso, 2)
})
const brutoMostrado = computed(() => {
  if (ficha.value && !modoEdicion.value) {
    const f = ficha.value
    const coef = coefDesdeCabecera(
      Number(f.coeficienteTransporte ?? 0),
      Number(f.importeTransporte ?? 0),
      Number(f.importeAlb ?? 0),
      Number(f.brutoConTransporte ?? 0)
    )
    const importe = Number(f.importeAlb ?? 0)
    const t = Number(f.importeTransporte ?? 0)
    if (coef > 0 && importe > 0) return roundN(importe / (1 + coef), 2)
    return roundN(importe - t, 2)
  }
  return brutoMercancia.value
})

const importeMostrado = computed(() => {
  if (ficha.value && !modoEdicion.value) {
    const f = ficha.value
    const coef = coefDesdeCabecera(
      Number(f.coeficienteTransporte ?? 0),
      Number(f.importeTransporte ?? 0),
      Number(f.importeAlb ?? 0),
      Number(f.brutoConTransporte ?? 0)
    )
    const importe = Number(f.importeAlb ?? 0)
    if (coef > 0 && importe > 0) return roundN(importe / (1 + coef), 2)
    return roundN(importe - Number(f.importeTransporte ?? 0), 2)
  }
  return brutoMercancia.value
})

/**
 * Ponc (script.js): coeficiente = transporte / (brutoConTransporte - transporte)
 * Bruto+Trans. es valor manual de cabecera; no se recalcula al añadir líneas.
 */
function sincronizarTransporteDesdeOrigen() {
  const t = Number(form.value.importeTransporte) || 0
  const bt = Number(form.value.brutoConTransporte) || 0
  const coef = Number(form.value.coeficienteTransporte) || 0
  const brutoLin = brutoMercancia.value
  const brutoDesdeBt = roundN(bt - t, 2)

  if (ajusteTransporteOrigen.value === 'coeficiente') {
    const bruto = brutoLin > 0 ? brutoLin : Math.max(0, brutoDesdeBt)
    const nuevoT = roundN(bruto * Math.max(0, coef), 2)
    form.value.importeTransporte = nuevoT
    form.value.brutoConTransporte = roundN(bruto + nuevoT, 2)
    return
  }

  if (ajusteTransporteOrigen.value === 'transporte') {
    if (bt > t + 0.0001) {
      form.value.coeficienteTransporte = roundN(t / brutoDesdeBt, 4)
    } else if (brutoLin > 0) {
      form.value.coeficienteTransporte = roundN(t / brutoLin, 4)
      form.value.brutoConTransporte = roundN(brutoLin + t, 2)
    } else {
      form.value.coeficienteTransporte = 0
    }
    return
  }

  // Origen: Bruto+Trans (legacy: coef = transporte / (brutoCon - transporte))
  form.value.coeficienteTransporte = brutoDesdeBt > 0 ? roundN(t / brutoDesdeBt, 4) : 0
}

/** Asegura coeficiente coherente antes de guardar (sin tocar Bruto+Trans.). */
function asegurarCoeficienteTransporte() {
  const t = Number(form.value.importeTransporte) || 0
  if (t <= 0.0000001) return
  const bt = Number(form.value.brutoConTransporte) || 0
  if (bt > t + 0.0001) {
    form.value.coeficienteTransporte = roundN(t / (bt - t), 4)
    return
  }
  const bruto = brutoMercancia.value
  if (bruto > 0.0001 && !(Number(form.value.coeficienteTransporte) > 0.0000001)) {
    form.value.coeficienteTransporte = roundN(t / bruto, 4)
  }
}

function onImporteTransporteInput() {
  ajusteTransporteOrigen.value = 'transporte'
  sincronizarTransporteDesdeOrigen()
}

function onBrutoConTransporteInput() {
  ajusteTransporteOrigen.value = 'bruto'
  sincronizarTransporteDesdeOrigen()
}

function onCoeficienteTransporteInput() {
  ajusteTransporteOrigen.value = 'coeficiente'
  sincronizarTransporteDesdeOrigen()
}

function esRutaNuevo(): boolean {
  if (route.name === 'compras-albaran-nuevo') return true
  return (route.path || '').replace(/\/+$/, '').endsWith('/compras/albaranes/nuevo')
}

/** Conservar borrador solo al volver de otra pestaña (p. ej. consultar artículo). */
function debeConservarBorradorAlta(rutaAnterior: string | undefined): boolean {
  if (!esNuevo.value || !rutaAnterior) return false
  const prev = (rutaAnterior.split('?')[0] || '').replace(/\/+$/, '') || '/'
  return !prev.startsWith('/compras/albaranes')
}

function aplicarFicha(data: AlbaranCompraDetalle | null | undefined) {
  if (!data || typeof data !== 'object') {
    throw new Error('Datos de albarán no válidos')
  }
  ficha.value = data
  pasoAlta.value = 'listo'
  ajusteTransporteOrigen.value = 'transporte'
  const coef = coefDesdeCabecera(
    Number(data.coeficienteTransporte ?? 0),
    Number(data.importeTransporte ?? 0),
    Number(data.importeAlb ?? 0),
    Number(data.brutoConTransporte ?? 0)
  )
  form.value = {
    empresa: data.empresa,
    fechaAlbaran: fmtFecha(data.fechaAlbaran) || new Date().toISOString().slice(0, 10),
    suAlbaran: data.suAlbaran || '',
    proveedor: data.proveedor || '',
    razonSocial: data.razonSocial || '',
    fpago: data.fpago || '',
    almacen: data.almacen ?? null,
    serie: data.serie || '',
    proyecto: data.proyecto || '',
    albaranDevolucion: !!data.albaranDevolucion,
    observaciones: data.observaciones || '',
    importeTransporte: Number(data.importeTransporte ?? 0),
    brutoConTransporte: Number(data.brutoConTransporte ?? 0),
    coeficienteTransporte: coef,
    lineas: (data.lineas?.length ? data.lineas : [lineaVacia()]).map((l) => ({
      nroLin: l.nroLin,
      articulo: l.articulo || '',
      descripcion: l.descripcion || '',
      cantidad: Number(l.cantidad ?? 0),
      precio: precioBaseDesdeAlmacenado(Number(l.precio ?? 0), coef),
      pjeDto: Number(l.pjeDto ?? 0),
      dto1: Number(l.dto1 ?? 0),
      dto2: Number(l.dto2 ?? 0),
      dto3: Number(l.dto3 ?? 0),
      lote: l.lote || '',
      almacen: l.almacen ?? data.almacen ?? null,
      pedido: pedidoVisible(l.pedido, l.nroLin),
      pvp: Number(l.precioVenta ?? 0) || 0,
      costeRef: {
        precio: precioBaseDesdeAlmacenado(Number(l.precio ?? 0), coef),
        dto1: Number(l.dto1 ?? 0),
        dto2: Number(l.dto2 ?? 0),
        dto3: Number(l.dto3 ?? 0),
      },
    })),
  }
  void cargarPvpsLineas(data.lineas ?? [])
}

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', {
      params: { activo: true, pageSize: 200 },
    })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
  } catch {
    tiendas.value = []
  }
}

async function cargarAlmacenes() {
  try {
    const { data } = await api.get('/api/mantenimiento/almacenes', {
      params: { activo: true, pageSize: 200 },
    })
    almacenes.value = (data.items ?? []).map(
      (a: { codigo: string | number; nombre?: string }) => {
        const n = Number(a.codigo)
        return {
          value: n,
          label: a.nombre ? `${n} - ${a.nombre}` : String(n),
        }
      }
    )
  } catch {
    almacenes.value = []
  }
}

async function cargar(rutaAnterior?: string) {
  if (!esEstaInstanciaActiva()) return

  if (esRutaNuevo()) {
    if (debeConservarBorradorAlta(rutaAnterior)) {
      error.value = null
      return
    }
    await iniciarNuevo()
    return
  }

  const empresa = String(route.params.empresa ?? '').trim()
  const albaran = Number(route.params.albaran ?? 0)
  if (!empresa || !Number.isFinite(albaran) || albaran <= 0) {
    error.value = 'Albarán no válido'
    ficha.value = null
    return
  }

  // Edición en curso: no recargar desde API al volver de otra pestaña.
  if (
    modoEdicion.value &&
    ficha.value &&
    ficha.value.empresa.trim() === empresa &&
    ficha.value.albaran === albaran
  ) {
    error.value = null
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarFicha(await obtenerAlbaranCompra(empresa, albaran))
    esNuevo.value = false
    modoEdicion.value = false
    const focus = albaranCompraFocusLineas.value
    if (
      focus &&
      focus.empresa.trim() === empresa &&
      focus.albaran === albaran
    ) {
      albaranCompraFocusLineas.value = null
      mensaje.value = 'Cabecera guardada. Cada línea se guarda al completarla (Intro).'
      void focusLineaArticulo(0)
    }
  } catch (e: unknown) {
    ficha.value = null
    error.value = extractApiError(e, 'No se pudo cargar el albarán de compra')
  } finally {
    loading.value = false
  }
}

async function iniciarNuevo() {
  esNuevo.value = true
  modoEdicion.value = true
  ficha.value = null
  pasoAlta.value = 'tienda'
  error.value = null
  const empresa = puesto.empresaCodigo || tiendas.value[0]?.value || ''
  ajusteTransporteOrigen.value = 'transporte'
  form.value = {
    empresa,
    fechaAlbaran: new Date().toISOString().slice(0, 10),
    suAlbaran: '',
    proveedor: '',
    razonSocial: '',
    fpago: '',
    almacen: null as number | null,
    serie: '',
    proyecto: '',
    albaranDevolucion: false,
    observaciones: '',
    importeTransporte: 0,
    brutoConTransporte: 0,
    coeficienteTransporte: 0,
    lineas: [lineaVacia()],
  }
  mensaje.value = empresa.trim()
    ? 'Confirme la tienda para comenzar'
    : 'Nuevo albarán: elija tienda y pulse Continuar'
  void nextTick(() => tiendaSelectRef.value?.focus())
}

async function focusProveedorCabecera() {
  if (!esNuevo.value || pasoAlta.value !== 'proveedor' || !camposEditables.value) return
  await nextTick()
  proveedorInputRef.value?.focus()
}

async function aplicarAlmacenTienda(empresa: string) {
  try {
    const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(empresa)}`)
    const n = Number(data.almacenCodigo ?? data.almacen ?? 0)
    if (!Number.isFinite(n) || n <= 0) return
    form.value.almacen = n
    form.value.lineas = form.value.lineas.map((l) => ({
      ...l,
      almacen: l.almacen ?? n,
    }))
  } catch {
    /* el almacén se puede elegir a mano */
  }
}

function onEmpresaNuevoChange() {
  if (!esNuevo.value) return
  pasoAlta.value = 'tienda'
  form.value.proveedor = ''
  form.value.razonSocial = ''
  mensaje.value = form.value.empresa.trim()
    ? 'Confirme la tienda para comenzar'
    : 'Nuevo albarán: elija tienda y pulse Continuar'
  void nextTick(() => tiendaSelectRef.value?.focus())
}


function onKeyEnter(e: KeyboardEvent) {
  if (!esNuevo.value || pasoAlta.value === 'listo') return
  if (
    buscarProveedorOpen.value ||
    buscarArticuloOpen.value ||
    abonoOpen.value ||
    confirmProveedorArticulo.value ||
    confirmAltaArticulo.value ||
    altaArticuloOpen.value ||
    confirmStock.value ||
    confirmRecuperar.value ||
    confirmTransporte.value ||
    confirmPvpCoste.value ||
    pvpModalOpen.value
  ) {
    return
  }
  const t = e.target
  if (t instanceof HTMLElement) {
    const tag = t.tagName
    if (tag === 'TEXTAREA' || (tag === 'INPUT' && t.closest('.lineas-panel, .lineas-panel'))) return
  }
  e.preventDefault()
  void onIntroCabecera()
}

async function onIntroCabecera() {
  if (!esNuevo.value || loading.value || saving.value) return
  error.value = null

  if (pasoAlta.value === 'tienda') {
    if (!form.value.empresa.trim()) {
      error.value = 'Seleccione la tienda'
      return
    }
    if (!puedeCrear.value) {
      error.value = 'No tiene permiso para crear albaranes de compra'
      return
    }
    loading.value = true
    try {
      await aplicarAlmacenTienda(form.value.empresa.trim())
      pasoAlta.value = 'proveedor'
      mensaje.value = null
      await focusProveedorCabecera()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo preparar el albarán')
    } finally {
      loading.value = false
    }
    return
  }

  if (pasoAlta.value === 'proveedor') {
    const codigo = form.value.proveedor.trim()
    if (codigo) {
      await confirmarProveedorPorCodigo(codigo)
      return
    }
    abrirBuscarProveedor()
  }
}

function volverListado() {
  router.push({ name: 'compras-albaranes' })
}

function onNuevo() {
  if (!puedeCrear.value) return
  if (esRutaNuevo()) {
    void onIntroCabecera()
    return
  }
  router.push({ name: 'compras-albaran-nuevo' })
}

function onModificar() {
  if (!puedeEditar.value || bloqueado.value || !ficha.value) return
  modoEdicion.value = true
  mensaje.value = null
}

async function onCancelar() {
  if (esNuevo.value) {
    volverListado()
    return
  }
  modoEdicion.value = false
  await cargar()
}

function buildPayload(): AlbaranCompraPayload {
  const lineas: AlbaranCompraLinea[] = form.value.lineas
    .filter((l) => l.articulo.trim() !== '')
    .map((l) => ({
      articulo: l.articulo.trim(),
      descripcion: l.descripcion.trim() || null,
      cantidad: Number(l.cantidad) || 0,
      precio: Number(l.precio) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      dto1: Number(l.dto1) || 0,
      dto2: Number(l.dto2) || 0,
      dto3: Number(l.dto3) || 0,
      lote: l.lote.trim() || null,
      almacen: l.almacen,
      pedido: pedidoVisible(l.pedido, l.nroLin),
    }))
  return {
    empresa: form.value.empresa.trim(),
    albaran: esNuevo.value ? undefined : ficha.value?.albaran,
    fechaAlbaran: form.value.fechaAlbaran || null,
    suAlbaran: form.value.suAlbaran.trim() || null,
    proveedor: form.value.proveedor.trim() || null,
    fpago: form.value.fpago.trim() || null,
    observaciones: form.value.observaciones.trim() || null,
    albaranDevolucion: form.value.albaranDevolucion,
    almacen: form.value.almacen,
    serie: form.value.serie.trim() || null,
    proyecto: form.value.proyecto.trim() || null,
    importeTransporte: Number(form.value.importeTransporte) || 0,
    coeficienteTransporte: coeficienteEfectivo.value,
    brutoConTransporte: Number(form.value.brutoConTransporte) || 0,
    lineas,
  }
}

function pvpDesdeDatosArticulo(data: Record<string, unknown>): number {
  const directo = Number(data.precioVen1 ?? data.precioVenta ?? 0) || 0
  if (directo > 0) return directo
  for (let i = 2; i <= 9; i++) {
    const n = Number(data[`precioVen${i}`] ?? 0) || 0
    if (n > 0) return n
  }
  const precios = data.precios
  if (Array.isArray(precios)) {
    for (const p of precios) {
      const n = Number((p as { precio?: unknown }).precio ?? 0) || 0
      if (n > 0) return n
    }
  }
  return 0
}

function registrarPvp(codigo: string, pvp: number) {
  const art = codigo.trim()
  if (!art || pvp <= 0) return
  const map = new Map(pvpPorArticulo.value)
  map.set(art, pvp)
  pvpPorArticulo.value = map
  for (const l of form.value.lineas) {
    if (String(l.articulo ?? '').trim() === art) l.pvp = pvp
  }
}

async function cargarPvpsLineas(lineas: Array<{ articulo?: string | null; precioVenta?: number | null }>) {
  for (const l of lineas) {
    const art = String(l.articulo ?? '').trim()
    if (!art) continue
    const ya = Number(l.precioVenta ?? 0) || pvpPorArticulo.value.get(art) || 0
    if (ya > 0) {
      registrarPvp(art, ya)
      continue
    }
    try {
      const artRes = await resolverArticulo(art)
      const pvp = pvpDesdeDatosArticulo(artRes)
      if (pvp > 0) registrarPvp(String(artRes.codigo ?? art).trim() || art, pvp)
    } catch {
      /* el artículo puede no tener PVP */
    }
  }
}

function lineaCosteCambio(l: FormLinea): boolean {
  const ref = l.costeRef
  if (!ref) return false
  // Legacy (hoja_albCompras.js): comparación con toFixed(2)
  const igual =
    Number(l.precio || 0).toFixed(2) === Number(ref.precio || 0).toFixed(2) &&
    Number(l.dto1 || 0).toFixed(2) === Number(ref.dto1 || 0).toFixed(2) &&
    Number(l.dto2 || 0).toFixed(2) === Number(ref.dto2 || 0).toFixed(2) &&
    Number(l.dto3 || 0).toFixed(2) === Number(ref.dto3 || 0).toFixed(2)
  return !igual
}

function focusLineaArticulo(idx: number) {
  void nextTick(() => {
    void nextTick(() => {
      requestAnimationFrame(() => {
        void intentarFocusLineaArticulo(idx, 12)
      })
    })
  })
}

async function intentarFocusLineaArticulo(idx: number, restantes: number) {
  const root = lineasPanelRef.value
  const row = root?.querySelector(`tr[data-linea-idx="${idx}"]`)
  const el = row?.querySelector<HTMLInputElement>('.col-art input')
  if (el && lineasEditables.value) {
    el.focus()
    el.select()
    return
  }
  if (restantes <= 0) return
  await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
  await intentarFocusLineaArticulo(idx, restantes - 1)
}

function focusLineaField(idx: number, field: (typeof LINEA_CAMPOS)[number]) {
  void nextTick(() => {
    void nextTick(() => {
      const row = document.querySelector(`tr[data-linea-idx="${idx}"]`)
      const celda = row?.querySelector(`td[data-linea-field="${field}"]`)
      const el = celda?.querySelector('input')
      el?.focus()
      el?.select()
    })
  })
}

function lineaIdxDesdeTarget(target: EventTarget | null): number {
  const row = (target as HTMLElement | null)?.closest?.('tr[data-linea-idx]')
  if (!row) return -1
  const idx = Number(row.getAttribute('data-linea-idx'))
  return Number.isFinite(idx) ? idx : -1
}

async function onArticuloIntro(idx: number) {
  barcodeWatcher.cancel()
  const linea = form.value.lineas[idx]
  const codigo = String(linea?.articulo ?? '').trim()
  if (!codigo) {
    abrirBuscarArticulo(idx)
    return
  }
  if (linea?.descripcion?.trim()) {
    focusLineaField(idx, 'cantidad')
    return
  }
  await resolverArticuloEnLinea(idx, codigo)
}

function onLineasTbodyKeydown(e: KeyboardEvent) {
  if (!lineasEditables.value || !esTeclaIntro(e)) return
  const target = e.target
  if (!(target instanceof HTMLInputElement) || target.readOnly || target.disabled) return
  if (!target.closest('.lineas-panel tbody')) return

  const idx = lineaIdxDesdeTarget(target)
  if (idx < 0) return

  if (target.closest('.col-art')) {
    e.preventDefault()
    e.stopPropagation()
    void onArticuloIntro(idx)
    return
  }

  const td = target.closest('td[data-linea-field]')
  if (!td) return
  const field = td.getAttribute('data-linea-field') as (typeof LINEA_CAMPOS)[number] | null
  if (!field || !LINEA_CAMPOS.includes(field)) return

  e.preventDefault()
  e.stopPropagation()
  target.blur()

  const pos = LINEA_CAMPOS.indexOf(field)
  if (pos >= 0 && pos < LINEA_CAMPOS.length - 1) {
    focusLineaField(idx, LINEA_CAMPOS[pos + 1])
    return
  }
  void finalizarLinea(idx)
}

async function guardarLineasAlMomento(): Promise<boolean> {
  if (!ficha.value || bloqueado.value) return false
  asegurarCoeficienteTransporte()
  const payload = buildPayload()
  if (!payload.lineas.length) return true

  guardandoLineas.value = true
  error.value = null
  try {
    const updated = await actualizarAlbaranCompra(
      ficha.value.empresa,
      ficha.value.albaran,
      payload
    )
    aplicarFicha(updated)
    return true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron guardar las líneas')
    return false
  } finally {
    guardandoLineas.value = false
  }
}

async function avanzarSiguienteLinea(idx: number) {
  if (ficha.value) {
    const ok = await guardarLineasAlMomento()
    if (!ok) return
  }
  const linea = form.value.lineas[idx]
  if (!linea) return
  if (idx === form.value.lineas.length - 1) {
    form.value.lineas.push(lineaVacia())
  }
  await nextTick()
  focusLineaArticulo(idx + 1)
}

async function finalizarLinea(idx: number) {
  const linea = form.value.lineas[idx]
  if (!linea?.articulo.trim()) return
  if (Math.abs(Number(linea.cantidad) || 0) < 0.0001) {
    error.value = 'Indique cantidad en la línea'
    focusLineaField(idx, 'cantidad')
    return
  }
  if (lineaCosteCambio(linea)) {
    pendingLineaIdx.value = idx
    confirmPvpCoste.value = true
    return
  }
  await avanzarSiguienteLinea(idx)
}

function esTeclaIntro(e: KeyboardEvent): boolean {
  return e.key === 'Enter' || e.key === 'NumpadEnter'
}

function onConfirmPvpCosteSi() {
  confirmPvpCoste.value = false
  pvpLineaIdx.value = pendingLineaIdx.value
  pvpModalOpen.value = true
}

async function onConfirmPvpCosteNo() {
  confirmPvpCoste.value = false
  const idx = pendingLineaIdx.value
  pendingLineaIdx.value = -1
  if (idx >= 0) await avanzarSiguienteLinea(idx)
}

async function onPvpGuardado(codigo: string) {
  try {
    const { data } = await api.get<Record<string, unknown>>(
      `/api/mantenimiento/articulos/${encodeURIComponent(codigo)}`
    )
    const pvp = pvpDesdeDatosArticulo(data)
    if (pvp > 0) registrarPvp(codigo, pvp)
  } catch {
    /* ignore */
  }
  const idx = pvpLineaIdx.value
  pvpLineaIdx.value = -1
  if (idx >= 0) await avanzarSiguienteLinea(idx)
}

function onPvpModalCerrar() {
  pvpModalOpen.value = false
  const idx = pvpLineaIdx.value
  pvpLineaIdx.value = -1
  if (idx >= 0) void avanzarSiguienteLinea(idx)
}

const pvpModalLinea = computed(() => {
  const idx = pvpLineaIdx.value
  if (idx < 0) return null
  return form.value.lineas[idx] ?? null
})

async function guardarAltaCompleta() {
  asegurarCoeficienteTransporte()
  const payload = buildPayload()
  if (!payload.empresa) {
    error.value = 'Seleccione la tienda'
    return
  }
  if (!payload.proveedor) {
    error.value = 'Indique el proveedor'
    abrirBuscarProveedor()
    return
  }
  if (!payload.lineas.length) {
    error.value = 'Introduzca al menos un artículo antes de guardar'
    void focusLineaArticulo(0)
    return
  }

  saving.value = true
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const created = await crearAlbaranCompra(payload)
    albaranCompraFocusLineas.value = {
      empresa: String(created.empresa).trim(),
      albaran: Number(created.albaran),
    }
    await router.replace({
      name: 'compras-albaran-detalle',
      params: { empresa: created.empresa, albaran: String(created.albaran) },
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el albarán')
  } finally {
    saving.value = false
    loading.value = false
  }
}

async function guardarCabeceraExistente() {
  if (!ficha.value || !cabeceraEditables.value) return
  asegurarCoeficienteTransporte()
  const payload = buildPayload()
  saving.value = true
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const updated = await actualizarAlbaranCompra(
      ficha.value.empresa,
      ficha.value.albaran,
      payload
    )
    aplicarFicha(updated)
    modoEdicion.value = false
    mensaje.value = 'Cabecera actualizada'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la cabecera')
  } finally {
    saving.value = false
    loading.value = false
  }
}

async function onGuardar() {
  if (esNuevo.value) {
    await guardarAltaCompleta()
    return
  }
  if (!cabeceraEditables.value) return
  const t = Number(form.value.importeTransporte) || 0
  if (t > 0.0001 && brutoMercancia.value > 0) {
    confirmTransporte.value = true
    return
  }
  await guardarCabeceraExistente()
}

function onConfirmTransporte() {
  confirmTransporte.value = false
  void guardarCabeceraExistente()
}

function pedirBorrar() {
  if (!puedeEliminar.value || bloqueado.value || !ficha.value) return
  confirmBorrar.value = true
}

async function onBorrarConfirmado() {
  confirmBorrar.value = false
  if (!ficha.value) return
  loading.value = true
  error.value = null
  try {
    await eliminarAlbaranCompra(ficha.value.empresa, ficha.value.albaran)
    volverListado()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo borrar el albarán')
  } finally {
    loading.value = false
  }
}

function pedirActualizarStock() {
  if (!puedeActualizarStock.value) return
  confirmStock.value = true
}

function pedirRecuperar() {
  if (!puedeRecuperar.value) return
  confirmRecuperar.value = true
}

function preguntarActualizarStockTrasGuardar(doc: AlbaranCompraDetalle) {
  if (doc.actualizado || doc.trasCtb || !puedeEditar.value) return
  confirmStock.value = true
}

function normalizarCodigoProveedor(c: string): string {
  return String(c ?? '').trim().toUpperCase()
}

function articuloBloqueadoCompra(art: Awaited<ReturnType<typeof resolverArticulo>>): boolean {
  return Boolean(art.bloqueoCompra)
}

function articuloCoincideProveedor(
  art: Awaited<ReturnType<typeof resolverArticulo>>,
  proveedorCabecera: string
): boolean {
  const prov = normalizarCodigoProveedor(proveedorCabecera)
  if (!prov) return true
  const habitual = normalizarCodigoProveedor(String(art.proveedorHabitual ?? ''))
  if (!habitual) return true
  return habitual === prov
}

function mensajeConfirmProveedorArticulo(
  art: Awaited<ReturnType<typeof resolverArticulo>>,
  proveedorCabecera: string,
  razonSocialProveedor: string
): string {
  const codigo = String(art.codigo ?? '').trim()
  const prov = proveedorCabecera.trim()
  const nombre = razonSocialProveedor.trim()
  const etiquetaProv = nombre ? `${prov} — ${nombre}` : prov
  return (
    `El artículo ${codigo} no pertenece al proveedor del albarán` +
    (etiquetaProv ? ` (${etiquetaProv})` : '') +
    '. Si continúa, el artículo quedará asignado a este proveedor. ¿Desea continuar?'
  )
}

async function validarYAplicarArticulo(
  idx: number,
  art: Awaited<ReturnType<typeof resolverArticulo>>,
  apply: () => Promise<void>
) {
  if (articuloBloqueadoCompra(art)) {
    error.value = `Artículo bloqueado para la compra: ${art.codigo}`
    return
  }
  const proveedor = form.value.proveedor.trim()
  if (proveedor && !articuloCoincideProveedor(art, proveedor)) {
    confirmProveedorArticuloMsg.value = mensajeConfirmProveedorArticulo(
      art,
      proveedor,
      form.value.razonSocial
    )
    pendingArticuloLineaIdx.value = idx
    pendingArticuloApply.value = apply
    pendingArticuloProveedor.value = {
      codigo: String(art.codigo ?? '').trim(),
      proveedor,
    }
    confirmProveedorArticulo.value = true
    return
  }
  await apply()
}

async function onConfirmProveedorArticulo() {
  confirmProveedorArticulo.value = false
  const fn = pendingArticuloApply.value
  const provUpd = pendingArticuloProveedor.value
  const idx = pendingArticuloLineaIdx.value
  pendingArticuloApply.value = null
  pendingArticuloLineaIdx.value = -1
  pendingArticuloProveedor.value = null
  error.value = null
  try {
    if (provUpd) {
      await asignarProveedorHabitualArticulo(provUpd.codigo, provUpd.proveedor)
    }
    await fn?.()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo asignar el proveedor al artículo')
    const linea = idx >= 0 ? form.value.lineas[idx] : null
    if (linea) {
      linea.articulo = ''
      linea.descripcion = ''
    }
  }
}

function onCancelProveedorArticulo() {
  confirmProveedorArticulo.value = false
  pendingArticuloApply.value = null
  pendingArticuloProveedor.value = null
  const idx = pendingArticuloLineaIdx.value
  pendingArticuloLineaIdx.value = -1
  const linea = idx >= 0 ? form.value.lineas[idx] : null
  if (linea) {
    linea.articulo = ''
    linea.descripcion = ''
  }
}

function pedirAltaArticulo(idx: number, query: string) {
  altaArticuloLineaIdx.value = idx
  altaArticuloQuery.value = query
  confirmAltaArticulo.value = true
}

function onCancelAltaArticulo() {
  confirmAltaArticulo.value = false
  altaArticuloQuery.value = ''
  altaArticuloLineaIdx.value = -1
}

function onConfirmAltaArticulo() {
  confirmAltaArticulo.value = false
  altaArticuloOpen.value = true
}

function onCerrarAltaArticulo() {
  altaArticuloOpen.value = false
  altaArticuloQuery.value = ''
  altaArticuloLineaIdx.value = -1
}

async function onArticuloCreadoDesdeAlta(creado: Record<string, unknown>) {
  altaArticuloOpen.value = false
  const idx = altaArticuloLineaIdx.value
  altaArticuloQuery.value = ''
  altaArticuloLineaIdx.value = -1
  if (idx < 0) return
  error.value = null
  try {
    const codigo = String(creado.codigo ?? '').trim()
    const art = await resolverArticulo(codigo)
    await validarYAplicarArticulo(idx, art, async () => {
      await aplicarArticuloResuelto(idx, art)
    })
    mensaje.value = `Artículo ${codigo} creado y aplicado a la línea`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Artículo creado pero no se pudo cargar en la línea')
  }
}

async function onStockConfirmado() {
  confirmStock.value = false
  if (!ficha.value) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const updated = await actualizarStockAlbaranCompra(ficha.value.empresa, ficha.value.albaran)
    aplicarFicha(updated)
    modoEdicion.value = false
    mensaje.value = 'Stock actualizado — situación ACTUALIZADO'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo actualizar el stock')
  } finally {
    loading.value = false
  }
}

async function onRecuperarConfirmado() {
  confirmRecuperar.value = false
  if (!ficha.value) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const updated = await recuperarAlbaranCompra(ficha.value.empresa, ficha.value.albaran)
    aplicarFicha(updated)
    modoEdicion.value = false
    mensaje.value = 'Albarán recuperado — puede modificar y volver a actualizar stock'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo recuperar el albarán')
  } finally {
    loading.value = false
  }
}

function pedirGenerarVenta() {
  if (!puedeGenerarVenta.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  clienteVentaCodigo.value = String(ficha.value.cliente ?? '').trim()
  clienteVentaNombre.value = ''
  buscarClienteVentaOpen.value = true
}

function onClienteVentaSeleccionado(r: EntidadBuscarResultado) {
  buscarClienteVentaOpen.value = false
  clienteVentaCodigo.value = String(r.codigo ?? '').trim()
  clienteVentaNombre.value = String(r.etiqueta ?? '').trim()
  if (!clienteVentaCodigo.value) {
    error.value = 'Debe seleccionar un cliente'
    return
  }
  confirmConvertirVentaOpen.value = true
}

async function confirmarConvertirVenta() {
  confirmConvertirVentaOpen.value = false
  const snap = ficha.value
  if (!snap || !clienteVentaCodigo.value.trim()) return
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await convertirAlbaranCompraAVenta(snap.empresa, snap.albaran, {
      cliente: clienteVentaCodigo.value.trim(),
      puesto: puesto.puestoCodigo || undefined,
    })
    const compra =
      res.albaranCompra ?? (await obtenerAlbaranCompra(snap.empresa, snap.albaran))
    aplicarFicha(compra)
    const v = res.venta
    mensaje.value = `Venta ${v.tipo}-${v.albaran} generada`
    ventaCreadaRef.value = {
      empresa: String(v.empresa).trim(),
      tipo: String(v.tipo ?? 'A').trim(),
      albaran: Number(v.albaran),
    }
    ventaCreadaOpen.value = true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar la venta')
    if (!ficha.value) {
      try {
        aplicarFicha(await obtenerAlbaranCompra(snap.empresa, snap.albaran))
      } catch {
        /* mantener error principal */
      }
    }
  } finally {
    saving.value = false
  }
}

function abrirVentaCreada() {
  const v = ventaCreadaRef.value
  ventaCreadaOpen.value = false
  if (!v) return
  void router.push(
    `/ventas/${encodeURIComponent(v.empresa)}/${encodeURIComponent(v.tipo)}/${v.albaran}`
  )
}

function cerrarVentaCreada() {
  ventaCreadaOpen.value = false
}

/** Vuelca líneas del albarán a la cola Etiquetas (005 / T032). */
async function onGenerarEtiquetas() {
  if (!puedeGenerarEtiquetas.value || !ficha.value) return
  generandoEtiquetas.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await encolarDesdeAlbaranCompra({
      empresa: ficha.value.empresa,
      albaran: ficha.value.albaran,
      puesto: puesto.puestoCodigo || undefined,
    })
    const n = res.items?.length ?? 0
    const omit = res.omitidas ?? 0
    if (n === 0) {
      mensaje.value =
        omit > 0
          ? `No se generaron etiquetas (${omit} línea(s) omitida(s): sin EAN o sin artículo)`
          : 'No hay líneas para generar etiquetas'
    } else {
      mensaje.value =
        `Generadas ${n} etiqueta(s) en cola` +
        (omit > 0 ? ` (${omit} omitida(s))` : '') +
        '. Abra menú Etiquetas para imprimir.'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar las etiquetas')
  } finally {
    generandoEtiquetas.value = false
  }
}

function irAColaEtiquetas() {
  void router.push({ name: 'etiquetas-cola' })
}

async function onImprimir() {
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  loading.value = true
  try {
    await mostrarPreviewA4(
      await prepararImpresionAlbaranCompra(ficha.value, {
        puestoCodigo: String(puesto.puestoCodigo || ''),
      })
    )
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo preparar la impresión A4')
  } finally {
    loading.value = false
  }
}

/** Previsualización en ventana aparte: el modal solo renderiza el folio oculto. */
async function mostrarPreviewA4(prep: PrepImpresionA4) {
  if (!preview.abrir(prep.titulo)) {
    throw new Error('Permita las ventanas emergentes para previsualizar el documento')
  }
  a4Prep.value = prep
  a4Open.value = true
  await nextTick()
  const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
  if (!html) {
    preview.cerrar()
    throw new Error('No hay plantilla configurada para este documento en el puesto')
  }
  preview.mostrar(html, { impresoraNombre: prep.impresoraNombre })
}

async function onImprimirA4Confirmado() {
  if (!a4Prep.value) return
  a4Imprimiendo.value = true
  error.value = null
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    mensaje.value = await imprimirA4CompraPreparado(a4Prep.value, html)
    preview.cerrar()
    a4Open.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo imprimir el albarán de compra')
    error.value = msg
    preview.notificarError(msg)
  } finally {
    a4Imprimiendo.value = false
  }
}

function abrirBuscarProveedor() {
  if (!camposEditables.value) return
  if (esNuevo.value && pasoAlta.value === 'tienda') {
    error.value = 'Primero confirme la tienda.'
    void nextTick(() => tiendaSelectRef.value?.focus())
    return
  }
  buscarProveedorOpen.value = true
}

async function confirmarProveedorPorCodigo(codigo: string) {
  const cod = codigo.trim()
  if (!cod) {
    abrirBuscarProveedor()
    return
  }
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get(`/api/mantenimiento/proveedores/${encodeURIComponent(cod)}`)
    await aplicarProveedorEnForm(
      {
        codigo: String(data.codigo ?? cod).trim(),
        etiqueta: String(data.nombre ?? data.razonSocial ?? ''),
      },
      data as Record<string, unknown>
    )
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Proveedor no encontrado. Selecciónelo en la búsqueda.')
    abrirBuscarProveedor()
  } finally {
    loading.value = false
  }
}

async function aplicarProveedorEnForm(
  r: { codigo: string; etiqueta: string },
  data?: Record<string, unknown>
) {
  form.value.proveedor = r.codigo
  form.value.razonSocial = r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta
  const fp = String(data?.formaPago ?? '').trim()
  if (fp) form.value.fpago = fp.slice(0, 2)
  const coef = Number(data?.coeficienteTransporte ?? 0)
  if (coef > 0) {
    form.value.coeficienteTransporte = coef
    ajusteTransporteOrigen.value = 'coeficiente'
    sincronizarTransporteDesdeOrigen()
  }
  if (esNuevo.value && (pasoAlta.value === 'proveedor' || pasoAlta.value === 'tienda')) {
    pasoAlta.value = 'listo'
    mensaje.value = null
    await nextTick()
    void focusLineaArticulo(0)
  }
}

async function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  buscarProveedorOpen.value = false
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get(`/api/mantenimiento/proveedores/${encodeURIComponent(r.codigo)}`)
    await aplicarProveedorEnForm(
      {
        codigo: String(data.codigo ?? r.codigo).trim(),
        etiqueta: String(data.nombre ?? data.razonSocial ?? r.etiqueta),
      },
      data as Record<string, unknown>
    )
  } catch {
    await aplicarProveedorEnForm({
      codigo: r.codigo,
      etiqueta: r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta,
    })
  } finally {
    loading.value = false
  }
}

function abrirBuscarArticulo(idx: number) {
  if (!lineasEditables.value) return
  lineaArticuloIdx.value = idx
  articuloBusquedaInicial.value = form.value.lineas[idx]?.articulo ?? ''
  buscarArticuloOpen.value = true
}

async function onArticuloSeleccionado(r: EntidadBuscarResultado) {
  const idx = lineaArticuloIdx.value
  if (!form.value.lineas[idx]) return
  buscarArticuloOpen.value = false
  error.value = null
  try {
    const art = await resolverArticulo(r.codigo)
    await validarYAplicarArticulo(idx, art, async () => {
      await aplicarArticuloResuelto(idx, art)
    })
  } catch (err: unknown) {
    error.value = extractApiError(err, 'Artículo no encontrado')
    abrirBuscarArticulo(idx)
  }
}

async function aplicarArticuloResuelto(idx: number, art: Awaited<ReturnType<typeof resolverArticulo>>) {
  const linea = form.value.lineas[idx]
  if (!linea) return
  linea.articulo = art.codigo
  linea.descripcion = String(art.descripcion ?? '').trim()
  const precioIni = precioInicialArticulo(art)
  if (precioIni > 0) linea.precio = precioIni
  linea.costeRef = {
    precio: Number(linea.precio) || 0,
    dto1: Number(linea.dto1) || 0,
    dto2: Number(linea.dto2) || 0,
    dto3: Number(linea.dto3) || 0,
  }
  const pvp = pvpDesdeDatosArticulo(art)
  linea.pvp = pvp
  if (pvp > 0) registrarPvp(art.codigo, pvp)
  const uds = Number(art.unidadesPaquete)
  if (uds > 0 && (!linea.cantidad || linea.cantidad === 1)) {
    linea.cantidad = uds
  } else if (!linea.cantidad) {
    linea.cantidad = 1
  }
  await nextTick()
  focusLineaField(idx, 'cantidad')
}

function onArticuloKeydown(e: KeyboardEvent, idx: number) {
  if (!lineasEditables.value || e.key !== 'F4') return
  e.preventDefault()
  e.stopPropagation()
  barcodeWatcher.cancel()
  abrirBuscarArticulo(idx)
}

let barcodeLineaIdx = 0
const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  if (!lineasEditables.value) return
  await resolverArticuloEnLinea(barcodeLineaIdx, codigo)
})

async function resolverArticuloEnLinea(idx: number, q: string) {
  const codigo = String(q ?? '').trim()
  if (!codigo) {
    abrirBuscarArticulo(idx)
    return
  }
  error.value = null
  try {
    const art = await resolverArticulo(codigo)
    await validarYAplicarArticulo(idx, art, async () => {
      await aplicarArticuloResuelto(idx, art)
    })
  } catch (err: unknown) {
    if (isApiNotFound(err) && puedeCrearArticulo.value) {
      pedirAltaArticulo(idx, codigo)
      return
    }
    error.value = extractApiError(err, 'Artículo no encontrado')
    abrirBuscarArticulo(idx)
  }
}

function onArticuloInput(idx: number) {
  if (!lineasEditables.value) return
  barcodeLineaIdx = idx
  barcodeWatcher.onInput(String(form.value.lineas[idx]?.articulo ?? ''))
}

async function quitarLinea(idx: number) {
  if (!lineasEditables.value) return
  if (form.value.lineas.length <= 1) {
    form.value.lineas = [lineaVacia()]
  } else {
    form.value.lineas.splice(idx, 1)
  }
  await guardarLineasAlMomento()
}

function lineasAbonables() {
  const lineas = ficha.value?.lineas ?? []
  return lineas.filter((l) => {
    const art = String(l.articulo ?? '').trim()
    if (!art || art.toUpperCase() === 'NO') return false
    if (Math.abs(Number(l.cantidad) || 0) < 0.0001) return false
    return (Number(l.nroLin) || 0) > 0
  })
}

function abrirAbono() {
  if (!puedeAbonar.value || !ficha.value) return
  const abonables = lineasAbonables()
  if (!abonables.length) {
    error.value = 'No hay líneas abonables en este albarán'
    return
  }
  abonoNroLins.value = abonables.map((l) => Number(l.nroLin))
  abonoObservacion.value = ''
  error.value = null
  abonoOpen.value = true
}

function toggleAbonoLinea(nroLin: number, checked: boolean) {
  if (checked) {
    if (!abonoNroLins.value.includes(nroLin)) {
      abonoNroLins.value = [...abonoNroLins.value, nroLin]
    }
    return
  }
  abonoNroLins.value = abonoNroLins.value.filter((n) => n !== nroLin)
}

function seleccionarTodasAbono(todas: boolean) {
  abonoNroLins.value = todas ? lineasAbonables().map((l) => Number(l.nroLin)) : []
}

async function confirmarAbono() {
  if (!ficha.value) return
  if (!abonoNroLins.value.length) {
    error.value = 'Seleccione al menos una línea para abonar'
    return
  }
  abonoOpen.value = false
  loading.value = true
  error.value = null
  try {
    const done = await crearAbonoAlbaranCompra(ficha.value.empresa, ficha.value.albaran, {
      nroLins: abonoNroLins.value,
      observacion: abonoObservacion.value.trim() || undefined,
    })
    mensaje.value = `Albarán devolución ${done.albaran} creado (origen ${ficha.value.albaran})`
    await router.push({
      name: 'compras-albaran-detalle',
      params: { empresa: done.empresa, albaran: String(done.albaran) },
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el abono/devolución')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarAlmacenes()])
})

onActivated(() => {
  if (!esEstaInstanciaActiva()) return
  const prev = esRutaNuevo() ? routeNavigationFrom.value : undefined
  void cargar(prev)
})

watch(
  () => route.fullPath,
  (_newPath, oldPath) => {
    if (!esEstaInstanciaActiva()) return
    void cargar(oldPath)
  },
  { immediate: true }
)

watch(
  () => form.value.proveedor,
  (codigo) => {
    if (!esNuevo.value || pasoAlta.value !== 'proveedor') return
    if (String(codigo ?? '').trim()) return
    form.value.razonSocial = ''
  }
)

watch(
  () =>
    form.value.lineas.map(
      (l) => [l.articulo, l.cantidad, l.precio, l.pjeDto, l.dto1, l.dto2, l.dto3] as const
    ),
  () => {
    if (!ficha.value) return
    if (brutoMercancia.value <= 0) return
    ajusteTransporteOrigen.value = 'transporte'
    sincronizarTransporteDesdeOrigen()
  }
)
</script>

<template>
  <section class="compra-detalle" tabindex="-1" @keydown.enter="onKeyEnter">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar && !!ficha && !bloqueado"
      :puede-eliminar="puedeEliminar && !!ficha && !bloqueado"
      :puede-guardar="esNuevo ? pasoAlta === 'listo' : cabeceraEditables"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="false"
      :mostrar-finalizar="false"
      :puede-abonar="puedeAbonar"
      :puede-recuperar="puedeRecuperar"
      :puede-actualizar-stock="puedeActualizarStock"
      :puede-generar-albaran="puedeGenerarVenta"
      generar-albaran-label="Albarán cliente"
      generar-albaran-title="Generar albarán de venta al cliente desde este albarán de compra actualizado"
      :puede-buscar="true"
      buscar-label="Listado"
      buscar-title="Volver al listado de albaranes"
      :puede-navegar="false"
      :modo-edicion="modoEdicion || esNuevo"
      :bloqueado="bloqueado"
      :hay-documento="!!ficha || esNuevo"
      :loading="loading || saving || guardandoLineas"
      :indice="-1"
      :total="0"
      @nuevo="onNuevo"
      @modificar="onModificar"
      @borrar="pedirBorrar"
      @buscar="volverListado"
      @guardar="onGuardar"
      @cancelar="onCancelar"
      @imprimir="onImprimir"
      @abonar="abrirAbono"
      @recuperar="pedirRecuperar"
      @actualizar-stock="pedirActualizarStock"
      @generar-albaran="pedirGenerarVenta"
    />

    <ol v-if="esNuevo" class="pasos-alta" aria-label="Pasos alta albarán">
      <li :class="{ activo: pasoAlta === 'tienda', hecho: pasoAlta !== 'tienda' }">1. Tienda</li>
      <li :class="{ activo: pasoAlta === 'proveedor', hecho: pasoAlta === 'listo' }">2. Proveedor</li>
      <li :class="{ activo: pasoAlta === 'listo' }">3. Líneas</li>
    </ol>
    <ol v-else-if="modoEdicion && ficha" class="pasos-alta" aria-label="Pasos albarán">
      <li class="hecho">1. Tienda</li>
      <li class="hecho">2. Proveedor</li>
      <li class="activo">3. Líneas · Guardar</li>
    </ol>

    <p v-if="avisoConsulta" class="banner-doc">{{ avisoConsulta }}</p>
    <div v-if="esNuevo && pasoAlta === 'tienda'" class="paso-accion">
      <span>Confirme la <strong>tienda</strong> para comenzar.</span>
      <button type="button" class="btn-paso primary" :disabled="loading" @click="onIntroCabecera">
        Continuar
      </button>
    </div>
    <div v-else-if="esNuevo && pasoAlta === 'proveedor'" class="paso-accion">
      <span>¿De qué proveedor es el albarán?</span>
      <button type="button" class="btn-paso primary" :disabled="loading" @click="abrirBuscarProveedor">
        Buscar proveedor
      </button>
    </div>
    <p v-else-if="esNuevo && pasoAlta === 'listo'" class="ok">
      Introduzca los artículos: código + <strong>Intro</strong> (o F4 / … para buscar). El número de
      albarán se asigna al pulsar <strong>Guardar</strong>.
    </p>
    <p v-else-if="modoEdicion && !esNuevo" class="ok">
      Editando. <strong>Guardar</strong> actualiza la cabecera. Las líneas se graban al pulsar
      <strong>Intro</strong>.
    </p>
    <p v-else-if="!esNuevo && !modoEdicion && ficha && !ficha.actualizado && !ficha.trasCtb" class="ok">
      Albarán listo. Puede <strong>Actualizar</strong> stock o <strong>Imprimir</strong>.
    </p>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje && !esNuevo" class="ok">{{ mensaje }}</p>
    <p v-if="loading && !ficha && !esNuevo" class="msg">Cargando...</p>

    <template v-if="ficha || esNuevo">
      <div class="ficha-compra-body">
      <div class="tab-form">
        <div class="doc-row">
          <section class="section grow">
            <h3>Documento</h3>
            <div class="fields cols-6">
              <label class="field field-tienda">
                <span class="label">Tienda</span>
                <select
                  v-if="esNuevo && camposEditables"
                  ref="tiendaSelectRef"
                  v-model="form.empresa"
                  title="Tienda"
                  @change="onEmpresaNuevoChange"
                >
                  <option value="">--</option>
                  <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <input v-else :value="form.empresa" readonly />
              </label>
              <label class="field">
                <span class="label">N. albarán</span>
                <input
                  ref="albaranInputRef"
                  tabindex="0"
                  :value="esNuevo ? '—' : ficha?.albaran"
                  readonly
                />
              </label>
              <label class="field">
                <span class="label">Fecha</span>
                <input v-model="form.fechaAlbaran" type="date" :readonly="!camposEditables" />
              </label>
              <label class="field">
                <span class="label">Su albarán</span>
                <input v-model="form.suAlbaran" maxlength="20" :readonly="!camposEditables" />
              </label>
              <label class="field">
                <span class="label">Almacén</span>
                <select
                  v-if="camposEditables"
                  v-model.number="form.almacen"
                  title="Almacén"
                >
                  <option :value="null">—</option>
                  <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
                </select>
                <input
                  v-else
                  :value="form.almacen != null ? `${form.almacen}${almacenNombre ? ' - ' + almacenNombre : ''}` : ''"
                  readonly
                />
              </label>
              <label class="field">
                <span class="label">Serie</span>
                <input :value="form.serie" readonly title="Serie (solo lectura)" />
              </label>
            </div>
          </section>
          <div class="totales-box">
            <div><span>Bruto</span><strong>{{ fmtNum(brutoMostrado) }}</strong></div>
            <div><span>Dtos</span><strong>{{ fmtNum(ficha?.importeDtos) }}</strong></div>
            <div><span>IVA</span><strong>{{ fmtNum(ficha?.importeIva) }}</strong></div>
            <div class="imp"><span>Importe</span><strong>{{ fmtNum(importeMostrado) }}</strong></div>
          </div>
        </div>

        <div v-if="mostrarProveedor" class="section-row paired single">
          <section class="section">
            <h3>Proveedor</h3>
            <div class="fields cols-3">
              <label class="field">
                <span class="label">Código</span>
                <div
                  class="combo-input"
                  :class="{ locked: proveedorBloqueado }"
                >
                  <input
                    ref="proveedorInputRef"
                    v-model="form.proveedor"
                    class="combo-codigo"
                    maxlength="6"
                    placeholder="Cód."
                    :readonly="proveedorBloqueado"
                    :title="form.razonSocial || 'Proveedor. Intro / F4 para buscar.'"
                    @keydown.f4.prevent="abrirBuscarProveedor"
                  />
                  <span class="combo-nombre" :title="form.razonSocial">{{ form.razonSocial }}</span>
                  <button
                    type="button"
                    class="btn-buscar"
                    :disabled="!proveedorBusquedaHabilitada"
                    :title="form.razonSocial ? `Buscar proveedor — ${form.razonSocial}` : 'Buscar proveedor (Intro / F4)'"
                    @click="abrirBuscarProveedor"
                  >
                    ...
                  </button>
                </div>
              </label>
              <label class="field span-2">
                <span class="label">Razón social</span>
                <input
                  v-model="form.razonSocial"
                  maxlength="50"
                  :readonly="proveedorBloqueado"
                />
              </label>
              <label class="field">
                <span class="label">Proyecto</span>
                <input v-model="form.proyecto" maxlength="15" :readonly="!camposEditables" />
              </label>
              <label class="field span-2">
                <span class="label">Observaciones</span>
                <input v-model="form.observaciones" :readonly="!camposEditables" />
              </label>
            </div>
            <p v-if="ficha?.albaranDevolucion" class="cab-dev-flag">Albarán de devolución / abono</p>
          </section>
        </div>

        <details v-if="mostrarExtras" class="detalles-adicionales" :open="!cabeceraCompacta">
          <summary>Más datos de la compra</summary>
          <section class="section">
            <h3>Transporte y márgenes</h3>
            <div class="fields cols-6">
              <label class="field">
                <span class="label">Transporte</span>
                <DecimalInput
                  v-if="camposEditables"
                  v-model="form.importeTransporte"
                  :empty-as-null="false"
                  @update:model-value="onImporteTransporteInput"
                />
                <input v-else class="num" :value="fmtNum(form.importeTransporte)" readonly />
              </label>
              <label class="field">
                <span class="label">Bruto + trans.</span>
                <DecimalInput
                  v-if="camposEditables"
                  v-model="form.brutoConTransporte"
                  :empty-as-null="false"
                  @update:model-value="onBrutoConTransporteInput"
                />
                <input v-else class="num" :value="fmtNum(form.brutoConTransporte)" readonly />
              </label>
              <label class="field">
                <span class="label">Coef. transporte</span>
                <DecimalInput
                  v-if="camposEditables"
                  v-model="form.coeficienteTransporte"
                  :empty-as-null="false"
                  @update:model-value="onCoeficienteTransporteInput"
                />
                <input v-else class="num" :value="fmtNum(form.coeficienteTransporte, 4)" readonly />
              </label>
              <label class="field">
                <span class="label">Imp. + transporte</span>
                <input class="num" :value="fmtNum(importeConTransporteMostrado)" readonly />
              </label>
              <label class="field">
                <span class="label">Total a PVP</span>
                <input class="num" :value="fmtNum(totalPvpMostrado)" readonly />
              </label>
              <label class="field">
                <span class="label">% Margen</span>
                <input class="num" :value="fmtNum(margenMostrado, 2)" readonly />
              </label>
            </div>
          </section>
        </details>
      </div>

      <div
        v-if="!esNuevo || pasoAlta === 'listo'"
        ref="lineasPanelRef"
        class="lineas-panel"
        :class="{ 'lineas-bloqueadas': !lineasEditables }"
      >
        <div class="lineas-head">
          <h3>Líneas</h3>
          <p v-if="!lineasEditables && esNuevo" class="lineas-bloqueo-msg">
            Indique el proveedor para añadir líneas
          </p>
          <div class="lineas-acciones">
            <button
              v-if="puedeGenerarEtiquetas"
              type="button"
              class="btn-add"
              :disabled="loading || generandoEtiquetas || modoEdicion"
              title="Añadir líneas del albarán a la cola de etiquetas"
              @click="onGenerarEtiquetas"
            >
              {{ generandoEtiquetas ? 'Generando…' : 'Etiquetas' }}
            </button>
            <button
              v-if="puedeGenerarEtiquetas"
              type="button"
              class="btn-add"
              :disabled="loading || generandoEtiquetas"
              title="Abrir cola de etiquetas"
              @click="irAColaEtiquetas"
            >
              Ver cola
            </button>
            <button
              v-if="lineasEditables"
              type="button"
              class="btn-add"
              @click="form.lineas.push(lineaVacia())"
            >
              + Línea
            </button>
          </div>
        </div>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="num col-ped">Pedido</th>
                <th class="col-art">Artículo</th>
                <th class="col-desc">Descripción</th>
                <th class="col-alm">Almacén</th>
                <th class="col-lote">Lote</th>
                <th class="num col-q">Cant.</th>
                <th class="num col-p">Precio</th>
                <th class="num col-pct">Precio CT</th>
                <th class="num col-d">Dto1</th>
                <th class="num col-d">Dto2</th>
                <th class="num col-d">Dto3</th>
                <th class="num col-imp">Importe</th>
                <th v-if="lineasEditables" class="col-act" />
              </tr>
            </thead>
            <tbody @keydown="onLineasTbodyKeydown">
              <tr
                v-for="(l, idx) in form.lineas"
                :key="l.nroLin ?? `n-${idx}`"
                :data-linea-idx="idx"
              >
                <td class="num col-ped">
                  <DecimalInput
                    v-if="lineasEditables"
                    :model-value="pedidoVisible(l.pedido, l.nroLin)"
                    :empty-as-null="true"
                    :integer="true"
                    placeholder=""
                    @update:model-value="(v) => (l.pedido = pedidoVisible(v, l.nroLin))"
                  />
                  <span v-else>{{ pedidoVisible(l.pedido, l.nroLin) ?? '' }}</span>
                </td>
                <td class="col-art">
                  <div v-if="lineasEditables" class="con-lupa">
                    <input
                      v-model="l.articulo"
                      placeholder="Código / buscar…"
                      title="Intro carga el artículo o abre búsqueda; F4 abre siempre la búsqueda"
                      @input="onArticuloInput(idx)"
                      @keydown="onArticuloKeydown($event, idx)"
                    />
                    <button
                      type="button"
                      class="btn-lupa"
                      title="Buscar artículo (Intro / F4)"
                      @click="abrirBuscarArticulo(idx)"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                  <span v-else>{{ l.articulo || '—' }}</span>
                </td>
                <td class="col-desc">
                  <input
                    v-if="lineasEditables"
                    v-model="l.descripcion"
                    class="desc-input"
                  />
                  <span v-else class="desc-text">{{ l.descripcion || '—' }}</span>
                </td>
                <td class="col-alm">
                  <select
                    v-if="lineasEditables"
                    v-model.number="l.almacen"
                    title="Almacén línea"
                  >
                    <option :value="null">Cab.</option>
                    <option v-for="a in almacenes" :key="a.value" :value="a.value">
                      {{ a.value }}
                    </option>
                  </select>
                  <span v-else>{{ l.almacen ?? form.almacen ?? '—' }}</span>
                </td>
                <td class="col-lote">
                  <input v-if="lineasEditables" v-model="l.lote" />
                  <span v-else>{{ l.lote || '—' }}</span>
                </td>
                <td class="num col-q" data-linea-field="cantidad">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.cantidad"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.cantidad) }}</span>
                </td>
                <td class="num col-p" data-linea-field="precio">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.precio"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(precioBaseLinea(l), 4) }}</span>
                </td>
                <td class="num col-pct">
                  <input class="num" :value="fmtNum(precioCtLinea(l), 4)" readonly />
                </td>
                <td class="num col-d" data-linea-field="dto1">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.dto1"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.dto1) }}</span>
                </td>
                <td class="num col-d" data-linea-field="dto2">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.dto2"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.dto2) }}</span>
                </td>
                <td class="num col-d" data-linea-field="dto3">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.dto3"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.dto3) }}</span>
                </td>
                <td class="num col-imp">{{ fmtNum(importeLinea(l)) }}</td>
                <td v-if="lineasEditables" class="col-act">
                  <button type="button" class="btn-del" title="Quitar" @click="quitarLinea(idx)">
                    ×
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="lineasEditables" class="lineas-hint">
          En código: <strong>Intro</strong> carga el artículo (si existe) o abre búsqueda;
          <strong>F4</strong> / … abre siempre la búsqueda. Cada línea se guarda al completarla.
        </p>
      </div>
      </div>
    </template>

    <EntidadBuscarModal
      :open="buscarClienteVentaOpen"
      entidad="clientes"
      titulo="Cliente para la venta"
      :busqueda-inicial="clienteVentaCodigo"
      @seleccionar="onClienteVentaSeleccionado"
      @cerrar="buscarClienteVentaOpen = false"
    />
    <EntidadBuscarModal
      :open="buscarProveedorOpen"
      entidad="proveedores"
      titulo="Buscar proveedor"
      :busqueda-inicial="form.proveedor"
      @seleccionar="onProveedorSeleccionado"
      @cerrar="buscarProveedorOpen = false"
    />
    <EntidadBuscarModal
      :open="buscarArticuloOpen"
      entidad="articulos"
      titulo="Buscar artículo"
      :busqueda-inicial="articuloBusquedaInicial"
      @seleccionar="onArticuloSeleccionado"
      @cerrar="buscarArticuloOpen = false"
    />
    <ConfirmDialog
      :open="confirmBorrar"
      title="Borrar albarán"
      message="¿Eliminar este albarán de compra? Solo es posible si no tiene stock actualizado ni TrasCtb."
      confirm-label="Borrar"
      @confirm="onBorrarConfirmado"
      @cancel="confirmBorrar = false"
    />
    <ConfirmDialog
      :open="confirmProveedorArticulo"
      title="Proveedor del artículo"
      :message="confirmProveedorArticuloMsg"
      confirm-label="Sí"
      cancel-label="No"
      :danger="false"
      @confirm="onConfirmProveedorArticulo"
      @cancel="onCancelProveedorArticulo"
    />
    <ConfirmDialog
      :open="confirmAltaArticulo"
      title="Artículo inexistente"
      message="Código de artículo inexistente, ¿desea darlo de alta?"
      confirm-label="Sí"
      cancel-label="No"
      :danger="false"
      @confirm="onConfirmAltaArticulo"
      @cancel="onCancelAltaArticulo"
    />
    <ArticuloAltaModal
      :open="altaArticuloOpen"
      :query-inicial="altaArticuloQuery"
      :proveedor-habitual="form.proveedor"
      :empresa="form.empresa"
      @cerrar="onCerrarAltaArticulo"
      @creado="onArticuloCreadoDesdeAlta"
    />
    <ConfirmDialog
      :open="confirmStock"
      title="Actualizar stock"
      message="¿Desea actualizar el stock? Se aplicarán las entradas (o salidas si es devolución) en Stock del mes de la fecha del albarán. El documento quedará en situación ACTUALIZADO y no podrá modificarse hasta Recuperar."
      confirm-label="Sí, actualizar"
      cancel-label="No, más tarde"
      :danger="false"
      @confirm="onStockConfirmado"
      @cancel="confirmStock = false"
    />
    <ConfirmDialog
      :open="confirmRecuperar"
      title="Recuperar albarán"
      message="¿Desea recuperar el albarán? Se revertirán los movimientos de stock aplicados y el documento volverá a PENDIENTE DE ACTUALIZAR para poder modificarlo."
      confirm-label="Sí, recuperar"
      cancel-label="Cancelar"
      :danger="false"
      @confirm="onRecuperarConfirmado"
      @cancel="confirmRecuperar = false"
    />
    <ConfirmDialog
      :open="confirmConvertirVentaOpen"
      title="Generar venta"
      :message="mensajeConfirmConvertirVenta"
      confirm-label="Generar"
      cancel-label="Cancelar"
      :danger="false"
      @confirm="confirmarConvertirVenta"
      @cancel="confirmConvertirVentaOpen = false"
    />
    <ConfirmDialog
      :open="ventaCreadaOpen"
      title="Venta generada"
      :message="mensajeVentaCreada"
      confirm-label="Abrir venta"
      cancel-label="Seguir en albarán"
      :danger="false"
      @confirm="abrirVentaCreada"
      @cancel="cerrarVentaCreada"
    />
    <ConfirmDialog
      :open="confirmTransporte"
      title="Transporte"
      message="Se va a repercutir el coste del transporte en todas las líneas del albarán al guardar."
      confirm-label="Aceptar"
      cancel-label="Cancelar"
      :danger="false"
      @confirm="onConfirmTransporte"
      @cancel="confirmTransporte = false"
    />
    <ConfirmDialog
      :open="confirmPvpCoste"
      title="Precio de venta"
      message="¿Desea modificar el precio de venta?"
      confirm-label="Sí"
      cancel-label="No"
      :danger="false"
      @confirm="onConfirmPvpCosteSi"
      @cancel="onConfirmPvpCosteNo"
    />
    <CompraLineaPvpModal
      :open="pvpModalOpen"
      :codigo="pvpModalLinea?.articulo ?? ''"
      :descripcion="pvpModalLinea?.descripcion ?? ''"
      :coste-base="Number(pvpModalLinea?.precio ?? 0)"
      :coste-con-transporte="pvpModalLinea ? precioCt(Number(pvpModalLinea.precio) || 0) : 0"
      @cerrar="onPvpModalCerrar"
      @guardado="onPvpGuardado"
    />
    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Albarán de compra'"
      :plantilla="a4Prep?.plantilla ?? null"
      :datos="a4Prep?.datos ?? null"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="a4Imprimiendo"
      oculto
      @cerrar="a4Open = false"
      @imprimir="onImprimirA4Confirmado"
    />

    <Teleport to="body">
      <div v-if="abonoOpen" class="overlay" @click.self="abonoOpen = false">
        <div class="modal-abono">
          <h3>Generar abono / devolución</h3>
          <p>
            Desmarque las líneas que <strong>no</strong> quiera abonar. Se creará un albarán de
            devolución nuevo con las líneas seleccionadas.
          </p>
          <div class="abono-acciones">
            <button type="button" class="linkish" @click="seleccionarTodasAbono(true)">Todas</button>
            <button type="button" class="linkish" @click="seleccionarTodasAbono(false)">Ninguna</button>
          </div>
          <div class="abono-tabla-wrap">
            <table class="abono-tabla">
              <thead>
                <tr>
                  <th />
                  <th>Artículo</th>
                  <th>Descripción</th>
                  <th class="num">Cant.</th>
                  <th class="num">Precio</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in lineasAbonables()" :key="l.nroLin">
                  <td>
                    <input
                      type="checkbox"
                      :checked="abonoNroLins.includes(Number(l.nroLin))"
                      @change="
                        toggleAbonoLinea(
                          Number(l.nroLin),
                          ($event.target as HTMLInputElement).checked
                        )
                      "
                    />
                  </td>
                  <td>{{ l.articulo }}</td>
                  <td>{{ l.descripcion }}</td>
                  <td class="num">{{ fmtNum(-Math.abs(Number(l.cantidad) || 0)) }}</td>
                  <td class="num">{{ Number(l.precio).toFixed(2) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <label class="abono-obs">
            Observación (opcional)
            <input v-model="abonoObservacion" type="text" maxlength="200" />
          </label>
          <p class="hint">Seleccionadas: {{ abonoNroLins.length }}</p>
          <footer>
            <button type="button" @click="abonoOpen = false">Cancelar</button>
            <button
              type="button"
              class="primary"
              :disabled="!abonoNroLins.length"
              @click="confirmarAbono"
            >
              Crear abono
            </button>
          </footer>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.compra-detalle {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.pasos-alta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  list-style: none;
  margin: 0;
  padding: 0;
  max-width: 960px;
}
.pasos-alta li {
  padding: 0.25rem 0.65rem;
  border-radius: 999px;
  border: 1px solid #cbd5e1;
  background: #f8fafc;
  color: #64748b;
  font-size: 0.75rem;
  font-weight: 600;
}
.pasos-alta li.activo {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
}
.pasos-alta li.hecho {
  border-color: #86efac;
  background: #dcfce7;
  color: #166534;
}
.paso-accion {
  max-width: 960px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  background: #eff6ff;
  color: #1e3a8a;
}
.btn-paso {
  border: 1px solid #64748b;
  border-radius: 5px;
  background: #fff;
  color: #1e293b;
  padding: 0.4rem 0.7rem;
  cursor: pointer;
  font-weight: 600;
}
.btn-paso.primary {
  border-color: #1d4ed8;
  background: #2563eb;
  color: #fff;
}
.btn-paso:disabled {
  cursor: wait;
  opacity: 0.55;
}
.banner-doc {
  max-width: 960px;
  margin: 0;
  padding: 0.25rem 0.5rem;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  background: #fff;
  color: #334155;
  font-size: 0.78rem;
}
.hint {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.ok {
  color: #166534;
  margin: 0;
}
.msg {
  color: #475569;
}

.ficha-compra-body {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  max-width: 960px;
  width: 100%;
  box-sizing: border-box;
}
.tab-form {
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  padding: 0.4rem;
  max-width: 960px;
  border-radius: 0 0 6px 6px;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.doc-row {
  display: flex;
  gap: 0.35rem;
  align-items: stretch;
}
.doc-row .grow {
  flex: 1;
  min-width: 0;
}
.section-row.paired {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  align-items: stretch;
}
.section-row.paired.single {
  grid-template-columns: 1fr;
}
.detalles-adicionales {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #f8fafc;
  padding: 0.25rem;
}
.detalles-adicionales > summary {
  cursor: pointer;
  color: #1e40af;
  font-size: 0.75rem;
  font-weight: 700;
  padding: 0.2rem 0.35rem;
}
.detalles-adicionales[open] {
  display: grid;
  gap: 0.35rem;
}
.section {
  margin: 0;
  padding: 0.3rem 0.4rem 0.35rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
}
.section h3 {
  margin: 0 0 0.25rem;
  font-size: 0.68rem;
  font-weight: 700;
  color: #334155;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0.15rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}
.fields {
  display: grid;
  gap: 0.2rem 0.35rem;
}
.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.cols-6 {
  grid-template-columns: repeat(6, minmax(0, 1fr));
}
.field {
  display: grid;
  gap: 0.05rem;
  font-size: 0.68rem;
  min-width: 0;
}
.span-2 {
  grid-column: span 2;
}
.label {
  color: #64748b;
  white-space: nowrap;
}
.tab-form input,
.tab-form select {
  width: 100%;
  min-width: 0;
  height: 1.65rem;
  box-sizing: border-box;
  padding: 0.12rem 0.25rem;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  font-size: 0.75rem;
  line-height: 1.2;
  background: #fff;
  color: #0f172a;
}
.tab-form input:read-only,
.tab-form select:disabled {
  background: #f1f5f9;
}
.field-tienda select:not(:disabled) {
  border-color: #2563eb;
  background: #eff6ff;
  font-weight: 600;
}
.totales-box {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.1rem 0.45rem;
  align-content: center;
  min-width: 9.5rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.3rem 0.45rem;
  font-size: 0.68rem;
}
.totales-box span {
  color: #64748b;
}
.totales-box strong {
  font-variant-numeric: tabular-nums;
  display: block;
  text-align: right;
}
.totales-box .imp strong {
  font-size: 0.9rem;
  color: #0f172a;
}
.cab-dev-flag {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: #9a3412;
}
.combo-input {
  display: flex;
  align-items: stretch;
  min-width: 0;
  height: 1.65rem;
  box-sizing: border-box;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  background: #fff;
}
.combo-input.locked {
  background: #e8eef5;
}
.combo-input:focus-within {
  border-color: #2563eb;
}
.combo-codigo {
  flex: 0 0 4.2rem;
  width: 4.2rem;
  height: 100% !important;
  border: none !important;
  border-right: 1px solid #e2e8f0 !important;
  border-radius: 0 !important;
  background: transparent !important;
}
.combo-nombre {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.3rem;
  font-size: 0.75rem;
  line-height: 1.65rem;
  color: #334155;
}
.combo-input .btn-buscar {
  flex: 0 0 1.7rem;
  height: auto;
  border: none;
  border-left: 1px solid #e2e8f0;
  border-radius: 0;
  background: transparent;
  cursor: pointer;
  font-size: 0.7rem;
  padding: 0;
  color: #334155;
}
.combo-input .btn-buscar:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.lineas-panel {
  max-width: 960px;
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  padding: 0.4rem;
}
.lineas-acciones {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
}
.lineas-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.45rem;
  flex-wrap: wrap;
}
.lineas-bloqueo-msg {
  margin: 0;
  flex: 1 1 auto;
  font-size: 0.78rem;
  color: #b45309;
}
.lineas-hint {
  margin: 0.4rem 0 0;
  color: #64748b;
  font-size: 0.76rem;
}
.lineas-bloqueadas .grid-wrap {
  pointer-events: none;
  opacity: 0.55;
}
.lineas-head h3 {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
}
.btn-add {
  padding: 0.25rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #f8fafc;
  cursor: pointer;
  font-size: 0.8rem;
}

.grid-cab {
  display: none;
}

.con-lupa {
  display: flex;
  gap: 0.25rem;
  align-items: stretch;
  min-width: 0;
}
.con-lupa input {
  flex: 1;
  min-width: 0;
}
.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.65rem;
  height: 1.65rem;
  flex-shrink: 0;
  border: 1px solid #64748b;
  border-radius: 2px;
  background: #fff;
  cursor: pointer;
  padding: 0;
}
.btn-lupa:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn-lupa :deep(.tool-icon) {
  width: 0.9rem;
  height: 0.9rem;
}

.desc-input,
td input {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
  background: #fff;
  color: #0f172a;
  width: 100%;
  box-sizing: border-box;
}

.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: min(50vh, 28rem);
  width: max-content;
  max-width: 100%;
}
.lineas-panel table {
  width: max-content;
  border-collapse: collapse;
  font-size: 0.75rem;
  table-layout: fixed;
}
.lineas-panel th,
.lineas-panel td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.12rem 0.15rem;
  text-align: left;
  vertical-align: middle;
}
.lineas-panel th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 600;
}
.lineas-panel td input,
.lineas-panel td select,
.lineas-panel td :deep(input) {
  width: 100%;
  min-width: 0;
  padding: 0.15rem 0.2rem;
  border: 1px solid #cbd5e1;
  border-radius: 2px;
  font: inherit;
  font-size: 0.75rem;
  background: #fff;
  color: #0f172a;
  box-sizing: border-box;
  height: 1.45rem;
}
.lineas-panel .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-ped {
  width: 4.4rem;
}
.col-art {
  width: 8.2rem;
}
.col-art .con-lupa {
  display: flex;
  gap: 0.12rem;
  min-width: 0;
}
.col-art .con-lupa input {
  flex: 1;
  min-width: 0;
}
.col-art input {
  font-size: 0.8rem;
}
.col-art .btn-lupa {
  width: 1.35rem;
  height: 1.45rem;
  flex-shrink: 0;
}
.col-desc {
  width: 9rem;
}
.desc-input {
  width: 100%;
  max-width: none;
}
.desc-text {
  display: inline-block;
  max-width: 9rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  vertical-align: bottom;
}
.col-alm {
  width: 2.8rem;
}
.col-alm select {
  width: 100%;
  max-width: none;
  padding: 0.1rem;
  font-size: 0.7rem;
}
.col-lote {
  width: 3.2rem;
}
.col-q {
  width: 3.2rem;
}
.col-p,
.col-pct {
  width: 4rem;
}
.col-d {
  width: 2.1rem;
}
.col-d :deep(input) {
  padding: 0.15rem 0.1rem;
  font-size: 0.7rem;
}
.col-imp {
  width: 3.6rem;
  white-space: nowrap;
}
.col-pct input {
  width: 100%;
  max-width: none;
  padding: 0.15rem 0.2rem;
  border: 1px solid #cbd5e1;
  border-radius: 2px;
  background: #e8eef5;
  font: inherit;
  font-size: 0.75rem;
  height: 1.45rem;
  box-sizing: border-box;
}
.lineas-panel {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  padding: 0.45rem 0.5rem;
  margin-bottom: 0;
}
.col-act {
  width: 1.4rem;
  padding-left: 0.1rem !important;
  padding-right: 0.1rem !important;
}
.btn-del {
  border: none;
  background: transparent;
  color: #b91c1c;
  font-size: 1.1rem;
  cursor: pointer;
  line-height: 1;
}

@media (max-width: 820px) {
  .ficha-compra-body {
    width: 100%;
  }
  .cab-layout {
    grid-template-columns: 1fr;
  }
  .grid-wrap {
    width: 100%;
  }
  .lineas-panel table {
    width: 100%;
  }
}

.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 80;
  padding: 1rem;
}
.modal-abono {
  background: #fff;
  border-radius: 8px;
  border: 1px solid #94a3b8;
  padding: 1rem 1.1rem;
  width: min(36rem, 100%);
  max-height: min(80vh, 36rem);
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.2);
}
.modal-abono h3 {
  margin: 0;
  font-size: 1rem;
}
.modal-abono > p {
  margin: 0;
  font-size: 0.85rem;
  color: #475569;
}
.abono-acciones {
  display: flex;
  gap: 0.75rem;
}
.linkish {
  border: none;
  background: none;
  color: #1d4ed8;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  padding: 0;
}
.abono-tabla-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: 14rem;
}
.abono-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
.abono-tabla th,
.abono-tabla td {
  padding: 0.3rem 0.4rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}
.abono-tabla th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
}
.abono-obs {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  color: #475569;
}
.abono-obs input {
  padding: 0.35rem 0.45rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
}
.modal-abono footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
.modal-abono footer button {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
}
.modal-abono footer button.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
  font-weight: 600;
}
.modal-abono footer button.primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

@media (max-width: 900px) {
  .doc-row {
    flex-direction: column;
  }
  .cols-6 {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
