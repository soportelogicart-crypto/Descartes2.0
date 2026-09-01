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
  reservarAlbaranCompra,
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

/** Nº reservado en alta (como legacy / ventas). */
const albaranReservado = ref<number | null>(null)
const reservando = ref(false)
/** Alta guiada: tienda → reservar nº → proveedor → cabecera → guardar → líneas. */
const pasoAlta = ref<'tienda' | 'proveedor' | 'cabecera' | 'listo'>('listo')
const tiendaSelectRef = ref<HTMLSelectElement | null>(null)
const albaranInputRef = ref<HTMLInputElement | null>(null)
const proveedorInputRef = ref<HTMLInputElement | null>(null)
const lineasPanelRef = ref<HTMLElement | null>(null)

const titulo = computed(() => {
  if (esNuevo.value) return 'Nuevo albarán de compra'
  if (!ficha.value) return 'Albarán de compra'
  return `Albarán compra ${ficha.value.empresa}-${ficha.value.albaran}`
})

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

/** Líneas: tras existir ficha guardada y documento editable. */
const lineasEditables = computed(() => !!ficha.value && !bloqueado.value)

const proveedorBusquedaHabilitada = computed(
  () =>
    cabeceraEditables.value &&
    (!esNuevo.value || !!(albaranReservado.value && albaranReservado.value > 0))
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

const situacionLabel = computed(() => {
  if (!ficha.value && esNuevo.value) return 'PENDIENTE DE ACTUALIZAR'
  if (!ficha.value) return '—'
  if (ficha.value.trasCtb) return 'TRASPASADO CTB'
  if (ficha.value.actualizado) return 'ACTUALIZADO'
  return 'PENDIENTE DE ACTUALIZAR'
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
  const precioUnit = camposEditables.value
    ? precioCt(Number(l.precio) || 0)
    : Number(l.precio) || 0
  return roundN(cant * precioUnit * factorDtoLinea(l), 2)
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

/** Precio CT en columna (edición: calculado; lectura: valor almacenado). */
function precioCtLinea(l: FormLinea): number {
  if (camposEditables.value) return precioCt(Number(l.precio) || 0)
  return Number(l.precio) || 0
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

const totalPvpMostrado = computed(() => {
  let sum = 0
  for (const l of form.value.lineas) {
    const art = String(l.articulo ?? '').trim()
    if (!art) continue
    const pvp = pvpPorArticulo.value.get(art) ?? 0
    sum += pvp * (Number(l.cantidad) || 0)
  }
  return roundN(sum, 2)
})

/** Coste unitario para margen (con CT en edición; almacenado en lectura). */
function costeUnitMargenLinea(l: FormLinea): number {
  if (camposEditables.value) return precioCt(Number(l.precio) || 0)
  return Number(l.precio) || 0
}

/** Margen % de una línea: (PVP1 − coste) / PVP1. */
function margenLineaPct(l: FormLinea): number {
  const art = String(l.articulo ?? '').trim()
  if (!art) return 0
  const pvp = pvpPorArticulo.value.get(art) ?? 0
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
      pedido: l.pedido ?? null,
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
  albaranReservado.value = null
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
    ? 'Pulse Intro o el botón Nuevo para reservar el número de albarán'
    : 'Nuevo albarán: elija tienda y pulse Intro o Nuevo'
  void nextTick(() => focusCabeceraSinNumero())
}

async function focusProveedorCabecera() {
  if (!esNuevo.value || pasoAlta.value !== 'proveedor' || !camposEditables.value) return
  await nextTick()
  proveedorInputRef.value?.focus()
}

async function focusCabeceraSinNumero() {
  if (!esNuevo.value || albaranReservado.value || !camposEditables.value || pasoAlta.value !== 'tienda') {
    return
  }
  await nextTick()
  albaranInputRef.value?.focus()
}

function onAlbaranSinNumeroBlur(e: FocusEvent) {
  if (pasoAlta.value !== 'tienda' || albaranReservado.value || reservando.value) return
  const next = e.relatedTarget as HTMLElement | null
  if (next === tiendaSelectRef.value || next?.closest('select.w-col-a')) return
  if (next?.closest('.btn-lupa')) return
  void nextTick(() => albaranInputRef.value?.focus())
}

/** Reserva UltAlbaranCom (o Dev) y muestra el nº en cabecera. */
async function reservarNumero() {
  const empresa = form.value.empresa.trim()
  if (!empresa) {
    error.value = 'Seleccione la tienda'
    albaranReservado.value = null
    return
  }
  if (!puedeCrear.value) {
    error.value = 'No tiene permiso para crear albaranes de compra'
    return
  }
  reservando.value = true
  error.value = null
  try {
    const res = await reservarAlbaranCompra({
      empresa,
      albaranDevolucion: form.value.albaranDevolucion,
    })
    albaranReservado.value = res.albaran
    if (res.almacen != null && (form.value.almacen == null || form.value.almacen <= 0)) {
      form.value.almacen = res.almacen
      form.value.lineas = form.value.lineas.map((l) => ({
        ...l,
        almacen: l.almacen ?? res.almacen,
      }))
    }
    if (esNuevo.value && pasoAlta.value === 'tienda') {
      pasoAlta.value = 'proveedor'
      mensaje.value = `Albarán ${res.albaran} reservado. Pulse Intro para buscar proveedor`
      void focusProveedorCabecera()
    } else {
      mensaje.value = `Albarán ${res.albaran} reservado. Indique proveedor y complete la cabecera.`
    }
  } catch (e: unknown) {
    albaranReservado.value = null
    error.value = extractApiError(e, 'No se pudo reservar el número de albarán')
  } finally {
    reservando.value = false
    if (!albaranReservado.value && esNuevo.value && pasoAlta.value === 'tienda') {
      void focusCabeceraSinNumero()
    }
  }
}

function onEmpresaNuevoChange() {
  albaranReservado.value = null
  if (!esNuevo.value) return
  pasoAlta.value = 'tienda'
  mensaje.value = form.value.empresa.trim()
    ? 'Pulse Intro o el botón Nuevo para reservar el número de albarán'
    : 'Nuevo albarán: elija tienda y pulse Intro o Nuevo'
  void nextTick(() => focusCabeceraSinNumero())
}

function onDevolucionChange() {
  if (!esNuevo.value) return
  albaranReservado.value = null
  pasoAlta.value = 'tienda'
  mensaje.value = form.value.empresa.trim()
    ? 'Pulse Intro o el botón Nuevo para reservar el número de albarán'
    : 'Nuevo albarán: elija tienda y pulse Intro o Nuevo'
  void nextTick(() => focusCabeceraSinNumero())
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
    if (tag === 'TEXTAREA' || (tag === 'INPUT' && t.closest('.panel.lineas'))) return
  }
  e.preventDefault()
  void onIntroCabecera()
}

async function onIntroCabecera() {
  if (!esNuevo.value || loading.value || saving.value || reservando.value) return
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
    await reservarNumero()
    return
  }

  if (pasoAlta.value === 'proveedor') {
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

function buildPayloadCabecera(): AlbaranCompraPayload {
  return {
    empresa: form.value.empresa.trim(),
    albaran:
      albaranReservado.value && albaranReservado.value > 0 ? albaranReservado.value : undefined,
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
    lineas: [],
  }
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
      pedido: l.pedido,
    }))
  return {
    empresa: form.value.empresa.trim(),
    albaran: albaranReservado.value && albaranReservado.value > 0 ? albaranReservado.value : undefined,
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

async function cargarPvpsLineas(lineas: AlbaranCompraLinea[]) {
  const map = new Map(pvpPorArticulo.value)
  for (const l of lineas) {
    const art = String(l.articulo ?? '').trim()
    if (!art || map.has(art)) continue
    try {
      const { data } = await api.get<Record<string, unknown>>(
        `/api/mantenimiento/articulos/${encodeURIComponent(art)}`
      )
      const pvp = Number(data.precioVen1 ?? data.precioVenta ?? 0) || 0
      if (pvp > 0) map.set(art, pvp)
    } catch {
      /* opcional */
    }
  }
  pvpPorArticulo.value = map
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
  if (!target.closest('.panel.lineas tbody')) return

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
    const pvp = Number(data.precioVen1 ?? data.precioVenta ?? 0) || 0
    if (pvp > 0) {
      const map = new Map(pvpPorArticulo.value)
      map.set(codigo, pvp)
      pvpPorArticulo.value = map
    }
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

async function guardarCabecera() {
  if (!cabeceraEditables.value) return
  asegurarCoeficienteTransporte()
  const cabecera = buildPayloadCabecera()
  if (!cabecera.empresa) {
    error.value = 'Seleccione tienda'
    return
  }
  if (esNuevo.value && !(albaranReservado.value && albaranReservado.value > 0)) {
    error.value = 'Pulse Intro para reservar el número de albarán (o el botón Nº)'
    return
  }
  if (!cabecera.proveedor) {
    error.value = 'Indique proveedor'
    return
  }

  saving.value = true
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    if (esNuevo.value) {
      const created = await crearAlbaranCompra(cabecera)
      albaranCompraFocusLineas.value = {
        empresa: String(created.empresa).trim(),
        albaran: Number(created.albaran),
      }
      await router.replace({
        name: 'compras-albaran-detalle',
        params: { empresa: created.empresa, albaran: String(created.albaran) },
      })
    } else if (ficha.value) {
      const { lineas: _omit, ...soloCabecera } = buildPayload()
      const updated = await actualizarAlbaranCompra(
        ficha.value.empresa,
        ficha.value.albaran,
        soloCabecera as AlbaranCompraPayload
      )
      aplicarFicha(updated)
      modoEdicion.value = false
      mensaje.value = 'Cabecera guardada'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar la cabecera')
  } finally {
    saving.value = false
    loading.value = false
  }
}

async function onGuardar() {
  if (!cabeceraEditables.value) return
  const t = Number(form.value.importeTransporte) || 0
  if (t > 0.0001 && brutoMercancia.value > 0) {
    confirmTransporte.value = true
    return
  }
  await guardarCabecera()
}

function onConfirmTransporte() {
  confirmTransporte.value = false
  void guardarCabecera()
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
    a4Prep.value = await prepararImpresionAlbaranCompra(ficha.value, {
      puestoCodigo: String(puesto.puestoCodigo || ''),
    })
    a4Open.value = true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo preparar la impresión A4')
  } finally {
    loading.value = false
  }
}

async function onImprimirA4Confirmado() {
  if (!a4Prep.value) return
  a4Imprimiendo.value = true
  error.value = null
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    mensaje.value = await imprimirA4CompraPreparado(a4Prep.value, html)
    a4Open.value = false
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir el albarán de compra')
  } finally {
    a4Imprimiendo.value = false
  }
}

function abrirBuscarProveedor() {
  if (!camposEditables.value) return
  if (esNuevo.value && !(albaranReservado.value && albaranReservado.value > 0)) {
    error.value = 'Reserve el número de albarán antes de buscar proveedor'
    return
  }
  buscarProveedorOpen.value = true
}

async function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  form.value.proveedor = r.codigo
  form.value.razonSocial = r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta
  buscarProveedorOpen.value = false
  if (esNuevo.value && pasoAlta.value === 'proveedor') {
    pasoAlta.value = 'cabecera'
    mensaje.value = 'Complete la cabecera y pulse Guardar (solo cabecera).'
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/proveedores/${encodeURIComponent(r.codigo)}`)
    const fp = String(data?.formaPago ?? '').trim()
    if (fp) form.value.fpago = fp.slice(0, 2)
    const coef = Number(data?.coeficienteTransporte ?? 0)
    if (coef > 0) {
      form.value.coeficienteTransporte = coef
      ajusteTransporteOrigen.value = 'coeficiente'
      sincronizarTransporteDesdeOrigen()
    }
  } catch {
    /* ficha proveedor opcional */
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
  const pvp = Number(art.precioVen1 ?? art.precioVenta ?? 0) || 0
  if (pvp > 0) {
    const map = new Map(pvpPorArticulo.value)
    map.set(art.codigo, pvp)
    pvpPorArticulo.value = map
  }
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
    if (!String(codigo ?? '').trim()) return
    pasoAlta.value = 'cabecera'
    mensaje.value = 'Complete la cabecera y pulse Guardar (solo cabecera).'
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
      :puede-guardar="cabeceraEditables"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="false"
      :puede-abonar="puedeAbonar"
      :puede-recuperar="puedeRecuperar"
      :puede-actualizar-stock="puedeActualizarStock"
      :puede-generar-albaran="puedeGenerarVenta"
      generar-albaran-label="Venta"
      generar-albaran-title="Generar albarán de venta al cliente desde este albarán de compra actualizado"
      :puede-buscar="true"
      buscar-label="Listado"
      buscar-title="Volver al listado de albaranes"
      :puede-navegar="false"
      :modo-edicion="modoEdicion"
      :bloqueado="bloqueado"
      :hay-documento="!!ficha || esNuevo"
      :loading="loading || saving || guardandoLineas || reservando"
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

    <div class="head">
      <div>
        <h2>{{ titulo }}</h2>
        <p class="hint">
          {{
            esNuevo
              ? pasoAlta === 'tienda'
                ? 'Elija tienda y pulse Intro o Nuevo para reservar el número.'
                : pasoAlta === 'proveedor'
                  ? `Nº ${albaranReservado ?? '—'} reservado. Pulse Intro para buscar proveedor.`
                  : 'Complete la cabecera y pulse Guardar (solo cabecera).'
              : ficha?.actualizado
                ? 'Documento ACTUALIZADO. Use Venta para generar albarán al cliente, o Recuperar para modificar.'
                : 'Guardar = cabecera. Las líneas se guardan al completarlas (Intro). Modificar para editar cabecera.'
          }}
        </p>
      </div>
      <p v-if="soloLecturaMotivo" class="badge-bloqueo">Solo lectura — {{ soloLecturaMotivo }}</p>
      <div class="head-actions">
        <button
          v-if="puedeGenerarEtiquetas"
          type="button"
          class="btn-etiquetas"
          :disabled="loading || generandoEtiquetas || modoEdicion"
          title="Añadir líneas del albarán a la cola de etiquetas"
          @click="onGenerarEtiquetas"
        >
          <ToolIcon name="etiquetas" />
          {{ generandoEtiquetas ? 'Generando…' : 'Generar etiquetas' }}
        </button>
        <button
          v-if="puedeGenerarEtiquetas"
          type="button"
          class="btn-etiquetas-sec"
          :disabled="loading || generandoEtiquetas"
          title="Abrir cola de etiquetas"
          @click="irAColaEtiquetas"
        >
          Ver cola
        </button>
      </div>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    <p v-if="loading && !ficha && !esNuevo" class="msg">Cargando...</p>

    <template v-if="ficha || esNuevo">
      <div class="ficha-compra-body">
      <div class="cab-layout">
        <div class="panel cab-main">
          <div class="cab-rows">
            <div class="cab-row">
              <span class="cab-lbl">Tienda</span>
              <select
                v-if="esNuevo && camposEditables"
                ref="tiendaSelectRef"
                v-model="form.empresa"
                class="w-col-a"
                title="Tienda"
                @change="onEmpresaNuevoChange"
              >
                <option value="">—</option>
                <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
              <input v-else class="w-col-a" :value="form.empresa" readonly />
              <span class="cab-lbl cab-lbl-gap">Albarán</span>
              <div class="con-lupa w-col-b">
                <input
                  ref="albaranInputRef"
                  class="w-col-b-in"
                  tabindex="0"
                  :value="esNuevo ? (albaranReservado ?? (reservando ? '…' : '—')) : ficha?.albaran"
                  readonly
                  @blur="onAlbaranSinNumeroBlur"
                />
                <button
                  v-if="esNuevo && camposEditables && !albaranReservado"
                  type="button"
                  class="btn-lupa"
                  :disabled="reservando || !form.empresa"
                  title="Reservar número de albarán"
                  @click="reservarNumero"
                >
                  Nº
                </button>
              </div>
            </div>

            <div class="cab-row">
              <span class="cab-lbl">Fecha</span>
              <input
                v-model="form.fechaAlbaran"
                class="w-col-a"
                type="date"
                :readonly="!camposEditables"
              />
              <span class="cab-lbl cab-lbl-gap">Su albarán</span>
              <input
                v-model="form.suAlbaran"
                class="w-col-b"
                maxlength="20"
                :readonly="!camposEditables"
              />
            </div>

            <div class="cab-row">
              <span class="cab-lbl">Proveedor</span>
              <div class="con-lupa w-eq">
                <input
                  ref="proveedorInputRef"
                  v-model="form.proveedor"
                  class="w-cod"
                  :readonly="!camposEditables || (esNuevo && !albaranReservado)"
                  @keydown.f4.prevent="abrirBuscarProveedor"
                />
                <button
                  type="button"
                  class="btn-lupa"
                  :disabled="!proveedorBusquedaHabilitada"
                  title="Buscar proveedor"
                  @click="abrirBuscarProveedor"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <input
                v-model="form.razonSocial"
                class="w-col-rest"
                :readonly="!camposEditables || (esNuevo && !albaranReservado)"
                title="Razón social"
              />
            </div>

            <div class="cab-row">
              <span class="cab-lbl">Almacén</span>
              <select
                v-if="camposEditables"
                v-model.number="form.almacen"
                class="w-col-rest"
                title="Almacén"
              >
                <option :value="null">—</option>
                <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
              </select>
              <input
                v-else
                class="w-col-rest"
                :value="form.almacen != null ? `${form.almacen}${almacenNombre ? ' - ' + almacenNombre : ''}` : ''"
                readonly
              />
              <span class="cab-lbl cab-lbl-gap">Serie</span>
              <input class="w-eq" :value="form.serie" readonly title="Serie (solo lectura)" />
            </div>

            <div class="cab-row">
              <span class="cab-lbl">Proyecto</span>
              <input
                v-model="form.proyecto"
                class="w-col-a"
                maxlength="15"
                :readonly="!camposEditables"
              />
              <span class="cab-lbl cab-lbl-gap">Situación</span>
              <input class="w-col-rest" :value="situacionLabel" readonly />
            </div>

            <div class="cab-row cab-row-obs">
              <span class="cab-lbl">Observaciones</span>
              <input
                v-model="form.observaciones"
                class="w-obs"
                :readonly="!camposEditables"
              />
            </div>
            <p v-if="ficha?.albaranDevolucion" class="cab-dev-flag">Albarán de devolución / abono</p>
          </div>
        </div>

        <aside class="panel cab-side">
          <div class="side-box">
            <div class="side-row">
              <span>Transporte</span>
              <DecimalInput
                v-if="camposEditables"
                v-model="form.importeTransporte"
                class="num side-num"
                :empty-as-null="false"
                @update:model-value="onImporteTransporteInput"
              />
              <input
                v-else
                class="num side-num"
                :value="fmtNum(form.importeTransporte)"
                readonly
              />
            </div>
            <div class="side-row">
              <span>Bruto+Trans.</span>
              <DecimalInput
                v-if="camposEditables"
                v-model="form.brutoConTransporte"
                class="num side-num"
                :empty-as-null="false"
                @update:model-value="onBrutoConTransporteInput"
              />
              <input
                v-else
                class="num side-num"
                :value="fmtNum(form.brutoConTransporte)"
                readonly
              />
            </div>
            <div class="side-row side-row-coef">
              <span>Coeficiente Transporte</span>
              <DecimalInput
                v-if="camposEditables"
                v-model="form.coeficienteTransporte"
                class="num side-num side-num-coef"
                :empty-as-null="false"
                @update:model-value="onCoeficienteTransporteInput"
              />
              <input
                v-else
                class="num side-num side-num-coef"
                :value="fmtNum(form.coeficienteTransporte, 4)"
                readonly
              />
            </div>
          </div>

          <div class="side-box totales">
            <div class="side-row">
              <span>Bruto</span>
              <span class="num-red">{{ fmtNum(brutoMostrado) }}</span>
            </div>
            <div class="side-row">
              <span>Dtos</span>
              <span class="num-red">{{ fmtNum(ficha?.importeDtos) }}</span>
            </div>
            <div class="side-row">
              <span>Iva</span>
              <span class="num-red">{{ fmtNum(ficha?.importeIva) }}</span>
            </div>
            <div class="side-row importe">
              <span>Importe</span>
              <span class="num-red">{{ fmtNum(importeMostrado) }}</span>
            </div>
            <div class="side-row">
              <span>Importe + Transporte</span>
              <span class="num-red">{{ fmtNum(importeConTransporteMostrado) }}</span>
            </div>
            <div class="side-row">
              <span>Total a PVP</span>
              <span class="num-red">{{ fmtNum(totalPvpMostrado) }}</span>
            </div>
            <div class="side-row">
              <span>% Margen</span>
              <span class="num-red">{{ fmtNum(margenMostrado, 2) }}</span>
            </div>
          </div>

          <div class="side-flags">
            <label v-if="ficha" class="check">
              <input type="checkbox" :checked="ficha.actualizado" disabled />
              Stock actualizado
            </label>
            <label v-if="ficha" class="check">
              <input type="checkbox" :checked="ficha.trasCtb" disabled />
              Tras. contabilidad
            </label>
          </div>
        </aside>
      </div>

      <div
        ref="lineasPanelRef"
        class="panel lineas"
        :class="{ 'lineas-bloqueadas': !lineasEditables }"
      >
        <div class="lineas-head">
          <h3>Líneas</h3>
          <p v-if="!lineasEditables && esNuevo" class="lineas-bloqueo-msg">
            Guarde la cabecera para poder añadir líneas
          </p>
          <button
            v-if="lineasEditables"
            type="button"
            class="btn-add"
            @click="form.lineas.push(lineaVacia())"
          >
            + Línea
          </button>
        </div>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-n">#</th>
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
                <td class="col-n">{{ l.nroLin ?? idx + 1 }}</td>
                <td class="num col-ped">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.pedido"
                    :empty-as-null="true"
                    :integer="true"
                  />
                  <span v-else>{{ l.pedido || '—' }}</span>
                </td>
                <td class="col-art">
                  <div v-if="lineasEditables" class="con-lupa">
                    <input
                      v-model="l.articulo"
                      @input="onArticuloInput(idx)"
                      @keydown="onArticuloKeydown($event, idx)"
                    />
                    <button
                      type="button"
                      class="btn-lupa"
                      title="Buscar artículo"
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
.compra-detalle h2 {
  margin: 0 0 0.25rem;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.hint {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}
.badge-bloqueo {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  background: #fef3c7;
  color: #92400e;
  font-size: 0.8rem;
  font-weight: 600;
}
.badge-ok {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  background: #dcfce7;
  color: #166534;
  font-size: 0.8rem;
  font-weight: 600;
}
.head-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}
.btn-etiquetas {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
}
.btn-etiquetas:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn-etiquetas :deep(.tool-icon) {
  width: 1rem;
  height: 1rem;
}
.btn-etiquetas-sec {
  padding: 0.4rem 0.65rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  color: #1e40af;
  font-weight: 600;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
}
.btn-etiquetas-sec:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #166534;
}
.msg {
  color: #475569;
}

.panel {
  margin-bottom: 0.85rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
.panel h3 {
  margin: 0 0 0.55rem;
  font-size: 0.9rem;
  color: #0f172a;
}

.ficha-compra-body {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  width: max-content;
  max-width: 100%;
  box-sizing: border-box;
}

.cab-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 14.5rem;
  gap: 0.45rem;
  align-items: start;
  width: 100%;
  box-sizing: border-box;
}
.cab-layout > .panel {
  margin-bottom: 0;
}
.cab-main {
  background: #f1f5f9;
  padding: 0.4rem 0.5rem;
  width: auto;
  min-width: 0;
  max-width: none;
}
.cab-rows {
  display: flex;
  flex-direction: column;
  gap: 0.22rem;
}
.cab-row {
  display: flex;
  flex-wrap: nowrap;
  align-items: center;
  gap: 0.2rem 0.28rem;
  min-height: 1.55rem;
}
.cab-lbl {
  flex: 0 0 4.8rem;
  font-size: 0.7rem;
  color: #334155;
  text-align: right;
  white-space: nowrap;
}
.cab-lbl-gap {
  flex: 0 0 4.8rem;
  margin-left: 0;
  width: auto;
  text-align: right;
}
.cab-dev-flag {
  margin: 0.35rem 0 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: #9a3412;
}
.cab-main input,
.cab-main select {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  font: inherit;
  font-size: 0.8rem;
  background: #fff;
  color: #0f172a;
  box-sizing: border-box;
  height: 1.65rem;
}
.cab-main input:read-only {
  background: #e8eef5;
}
.w-cod {
  width: 4.2rem;
  flex: 0 0 4.2rem;
}
.w-col-a {
  width: 9rem;
  flex: 0 0 9rem;
  max-width: 9rem;
  box-sizing: border-box;
}
.w-col-a.con-lupa,
.con-lupa.w-col-a {
  display: flex;
  width: 9rem;
  flex: 0 0 9rem;
}
.w-col-b,
.w-col-b-in,
.w-eq {
  width: 7rem;
  flex: 0 0 7rem;
  max-width: 7rem;
  box-sizing: border-box;
}
.w-col-b,
.w-col-b-in {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.con-lupa.w-col-b,
.con-lupa.w-eq {
  display: flex;
  width: 7rem;
  flex: 0 0 7rem;
}
.con-lupa.w-col-b .w-col-b-in {
  flex: 1;
  width: auto;
  max-width: none;
}
.con-lupa.w-eq .w-cod {
  flex: 1;
  width: auto;
  max-width: none;
}
.w-col-rest {
  flex: 1 1 auto;
  min-width: 5rem;
  max-width: none;
}
.w-obs {
  flex: 1 1 auto;
  min-width: 8rem;
  max-width: none;
}
.cab-side {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.4rem;
  background: #f1f5f9;
}
.side-box {
  border: 1px solid #94a3b8;
  border-radius: 2px;
  padding: 0.35rem 0.45rem;
  background: #fff;
}
.side-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.4rem;
  margin-bottom: 0.25rem;
  font-size: 0.72rem;
  color: #334155;
}
.side-row:last-child {
  margin-bottom: 0;
}
.side-row-coef {
  flex-wrap: wrap;
}
.side-row-coef > span {
  flex: 1 1 100%;
  margin-bottom: 0.15rem;
}
.side-num {
  width: 6.5rem;
  flex-shrink: 0;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  font: inherit;
  font-size: 0.8rem;
  background: #fff;
  text-align: right;
  font-variant-numeric: tabular-nums;
  box-sizing: border-box;
  height: 1.65rem;
}
.side-num-coef {
  width: 100%;
}
.side-row :deep(.side-num),
.side-row :deep(input) {
  width: 6.5rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  height: 1.65rem;
  font-size: 0.8rem;
}
.side-row :deep(.side-num-coef) {
  width: 100%;
}
.side-box.totales .num-red {
  color: #b91c1c;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}
.side-box.totales .importe {
  margin-top: 0.1rem;
  padding-top: 0.3rem;
  border-top: 1px solid #cbd5e1;
  font-weight: 700;
  color: #0f172a;
}
.side-box.totales .importe .num-red {
  font-size: 0.9rem;
}
.side-flags {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.72rem;
  color: #475569;
  padding: 0 0.15rem;
}
.side-flags .check {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  flex-direction: row;
  padding-top: 0;
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
.lineas-bloqueadas .grid-wrap {
  pointer-events: none;
  opacity: 0.55;
}
.lineas-head h3 {
  margin: 0;
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
.panel.lineas table {
  width: max-content;
  border-collapse: collapse;
  font-size: 0.75rem;
  table-layout: fixed;
}
.panel.lineas th,
.panel.lineas td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.12rem 0.15rem;
  text-align: left;
  vertical-align: middle;
}
.panel.lineas th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
  font-size: 0.7rem;
  font-weight: 600;
}
.panel.lineas td input,
.panel.lineas td select,
.panel.lineas td :deep(input) {
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
.panel.lineas .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-n {
  width: 1.6rem;
}
.col-ped {
  width: 2.6rem;
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
.panel.lineas {
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
  .panel.lineas table {
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
</style>
