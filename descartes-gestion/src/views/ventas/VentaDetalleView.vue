<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  actualizarVenta,
  crearAbonoDesdeVenta,
  crearVenta,
  eliminarVenta,
  enviarVentaPorEmail,
  finalizarVenta,
  obtenerVenta,
} from '@/api/ventas'
import { crearAlbaranPeriodico, eliminarAlbaranPeriodico } from '@/api/facturacion'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import { api } from '@/api/client'
import type { VentaDetalle, VentaLinea, VentaPayload, VentaResumen } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useVentasBusquedaStore } from '@/stores/ventasBusqueda'
import { usePlantillaPeriodicaStore } from '@/stores/plantillaPeriodica'
import {
  imprimirA4Preparado,
  prepararOImprimirVenta,
  type PrepImpresionA4,
} from '@/composables/useImpresionVentaDocumento'
import { etiquetaA4ImpresionRecuperado } from '@/composables/ventaDocumentoPreview'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import ElegirFormatoImpresionModal from '@/components/common/ElegirFormatoImpresionModal.vue'
import EntidadBuscarModal from '@/components/common/EntidadBuscarModal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import VentaCabeceraForm from '@/components/ventas/VentaCabeceraForm.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import VentaPostFinalizacionModal from '@/components/ventas/VentaPostFinalizacionModal.vue'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const puesto = usePuestoContextoStore()
const busqueda = useVentasBusquedaStore()
const plantillaPeriodica = usePlantillaPeriodicaStore()
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => onImprimirA4Confirmado() })

const PERIODICIDAD_PRESETS = [
  { value: 7, label: 'Semanal (7 días)' },
  { value: 30, label: 'Mensual' },
  { value: 60, label: 'Bimestral' },
  { value: 90, label: 'Trimestral' },
  { value: 365, label: 'Anual' },
  { value: 0, label: 'Personalizado…' },
] as const

const esPlantillaAlta = computed(
  () => plantillaPeriodica.esAlta || route.query.plantillaPeriodica === '1'
)
const esPlantillaConsulta = computed(
  () => plantillaPeriodica.esConsulta || route.query.plantillaConsulta === '1'
)

function destinoVenta(empresa: string, tipo: string, albaran: number) {
  const path = `/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  if (esPlantillaConsulta.value) {
    return { path, query: { plantillaConsulta: '1' } }
  }
  return path
}

/**
 * KeepAlive cachea por fullPath. Al cambiar de /nuevo a /empresa/tipo/nº la instancia
 * antigua sigue recibiendo el cambio de route y puede consumir abrirEnEdicion.
 */
const pathInstancia = route.fullPath

function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const puedeCrear = computed(() => puede('ventas', 'crear'))
const puedeEditar = computed(() => puede('ventas', 'editar'))
const puedeEliminar = computed(() => puede('ventas', 'eliminar'))

const loading = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<VentaDetalle | null>(null)
const lineas = ref<VentaLinea[]>([])
const confirmBorrar = ref(false)
const confirmCancelarAlta = ref(false)
const finalizarOpen = ref(false)
const abonoOpen = ref(false)
const abonoNroLins = ref<number[]>([])
const abonoObservacion = ref('')
const tipoFinal = ref('A')
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const formatoImpresionOpen = ref(false)
const etiquetaA4Impresion = computed(() =>
  ficha.value ? etiquetaA4ImpresionRecuperado(ficha.value) : 'Albarán'
)
const a4Imprimiendo = ref(false)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const postVentaOpen = ref(false)
const postVentaDocumento = ref('')
const postVentaEnviando = ref(false)
const postVentaError = ref<string | null>(null)
const plantillaPeriodicaOpen = ref(false)
const plantillaPeriodicaSaving = ref(false)
const plantillaPeriodicaForm = reactive({
  presetPeriodicidad: 30,
  periodicidadCustom: 30,
  ultimaGeneracion: new Date().toISOString().slice(0, 10),
  marcarReferenciaPeriodico: true,
})
const periodicidadPlantilla = computed(() =>
  plantillaPeriodicaForm.presetPeriodicidad === 0
    ? plantillaPeriodicaForm.periodicidadCustom
    : plantillaPeriodicaForm.presetPeriodicidad
)
/** Flujo legacy: tienda → reservar albaran → buscar cliente → grabar cabecera */
const pasoAlta = ref<'tienda' | 'cliente' | 'listo'>('listo')
const buscarClienteOpen = ref(false)
const buscarArticuloOpen = ref(false)
const buscarVendedorOpen = ref(false)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
/** Tras alta de cabecera: no recargar (ya tenemos la ficha) y quedar en edicion. */
const omitirProximaCarga = ref(false)
const cabeceraForm = ref<{
  focusTienda: () => Promise<void>
  focusCliente: () => Promise<void>
} | null>(null)
const articuloInputRefs = ref<HTMLInputElement[]>([])
const descripcionInputRefs = ref<HTMLInputElement[]>([])
const vendedorNombre = ref('')
/** Agente del cliente: se guarda al grabar la venta nueva (legacy Agente). */
const agenteCliente = ref('')

function setArticuloInputRef(el: unknown, index: number) {
  if (el instanceof HTMLInputElement) {
    articuloInputRefs.value[index] = el
  }
}

function setDescripcionInputRef(el: unknown, index: number) {
  if (el instanceof HTMLInputElement) {
    descripcionInputRefs.value[index] = el
  }
}

function esLineaComentario(l: VentaLinea | null | undefined): boolean {
  return String(l?.articulo ?? '').trim().toUpperCase() === 'NO'
}

function redondear2(n: number): number {
  return Math.round((Number(n) || 0) * 100) / 100
}

function redondearCampoLinea(l: VentaLinea, campo: 'cantidad' | 'precio' | 'pjeDto') {
  if (esLineaComentario(l)) return
  l[campo] = redondear2(l[campo])
}

async function focusDescripcionLinea(index: number) {
  await nextTick()
  const el = descripcionInputRefs.value[index]
  if (el) {
    el.focus()
    el.select()
  }
}

async function resolverNombreVendedor(codigo: string) {
  const c = String(codigo ?? '').trim()
  if (!c) {
    vendedorNombre.value = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/trabajadores/${encodeURIComponent(c)}`)
    vendedorNombre.value = String(data.nombre ?? data.descripcion ?? '').trim()
  } catch {
    vendedorNombre.value = ''
  }
}

/** Vendedor por defecto = trabajador del puesto (Mantenimiento → Puestos → Vendedor). */
async function precargarVendedorDelPuesto() {
  if (!ficha.value) return
  const codigoPuesto = String(ficha.value.puesto || puesto.puestoCodigo || '').trim()
  if (!codigoPuesto || String(ficha.value.vendedor ?? '').trim()) return
  try {
    const { data } = await api.get(
      `/api/mantenimiento/puestos-trabajo/${encodeURIComponent(codigoPuesto)}`
    )
    const vendedor = String(data.trabajadorCodigo ?? '').trim()
    // La ficha puede haber cambiado mientras se resolvía el puesto.
    if (!vendedor || !ficha.value || String(ficha.value.vendedor ?? '').trim()) return
    ficha.value = { ...ficha.value, vendedor }
    await resolverNombreVendedor(vendedor)
  } catch {
    /* Sin vendedor por defecto: se introduce a mano en la cabecera. */
  }
}

function abrirBuscarVendedor() {
  if (soloLectura.value) {
    if (!puedeEditar.value || bloqueado.value) return
    modoEdicion.value = true
  }
  buscarVendedorOpen.value = true
}

function onVendedorSeleccionado(sel: { codigo: string; etiqueta: string }) {
  buscarVendedorOpen.value = false
  if (!ficha.value) return
  ficha.value = { ...ficha.value, vendedor: sel.codigo }
  vendedorNombre.value = sel.etiqueta
}

async function onVendedorKeydown(e: KeyboardEvent) {
  if (soloLectura.value) return
  if (e.key === 'F4') {
    e.preventDefault()
    abrirBuscarVendedor()
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  const codigo = String(ficha.value?.vendedor ?? '').trim()
  if (!codigo) {
    abrirBuscarVendedor()
    return
  }
  await resolverNombreVendedor(codigo)
  if (!vendedorNombre.value) {
    abrirBuscarVendedor()
  }
}

async function focusArticuloLinea(index = 0) {
  const tryFocus = () => {
    const el = articuloInputRefs.value[index]
    if (!el || el.readOnly) return false
    el.focus({ preventScroll: false })
    el.select()
    return document.activeElement === el
  }

  await nextTick()
  if (tryFocus()) return

  // Tras cerrar modal de cliente el overlay puede devolver el foco; reintentar.
  await new Promise<void>((r) => requestAnimationFrame(() => requestAnimationFrame(() => r())))
  if (tryFocus()) return

  for (let i = 0; i < 8; i++) {
    await new Promise((r) => setTimeout(r, 40))
    if (tryFocus()) return
  }
}

function abrirBuscarArticulo(index: number) {
  if (!puedeEditarLineas.value) {
    if (!tieneCliente.value) {
      error.value = 'Seleccione el cliente antes de introducir artículos'
      abrirBuscarCliente()
    }
    return
  }
  lineaArticuloIdx.value = index
  articuloBusquedaInicial.value = String(lineas.value[index]?.articulo ?? '').trim()
  buscarArticuloOpen.value = true
}

const TIPOS_FINAL = [
  { codigo: 'T', label: 'Ticket' },
  { codigo: 'A', label: 'Albaran' },
  { codigo: 'P', label: 'Presupuesto' },
  { codigo: 'F', label: 'Factura' },
]

/** Contado = forma de pago con CobroDeArqueo → no Albarán. */
const clienteContado = ref(false)
const formaPagoCliente = ref('')
/** Formas de pago de contado: la regla de negocio es CobroDeArqueo. */
const formasPagoContado = ref<{ codigo: string; descripcion: string }[]>([])
const fpagoFinal = ref('')
/** Empresas.SW_IVA: precios de linea con IVA incluido (yIVA). */
const preciosIvaIncluido = ref(false)

/** Legacy: cliente anónimo / venta rápida sin datos fiscales. */
const CLIENTE_SIN_NOMBRE = 'ZZZZZZZZZ'

/** Datos fiscales mínimos para tipificar Factura (contado o ticket→factura). */
function esClienteSinNombre(codigo: string | null | undefined): boolean {
  const c = String(codigo ?? '').trim().toUpperCase()
  return !c || c === CLIENTE_SIN_NOMBRE
}

function nifValidoParaFactura(nif: string | null | undefined): boolean {
  const n = String(nif ?? '')
    .trim()
    .toUpperCase()
    .replace(/[\s.\-]/g, '')
  if (n.length < 7) return false
  // Placeholders habituales (00000000T, XXXXXXXX, etc.)
  if (/^[0X]+$/.test(n)) return false
  return true
}

const tieneDatosFactura = computed(() => {
  const f = ficha.value
  if (!f) return false
  if (esClienteSinNombre(f.cliente)) return false
  if (!nifValidoParaFactura(f.nif)) return false
  return Boolean(String(f.razonSocial ?? '').trim())
})

const tiposFinalDisponibles = computed(() => {
  if (esPlantillaAlta.value) {
    return TIPOS_FINAL.filter((t) => {
      if (t.codigo !== 'P' && t.codigo !== 'A') return false
      if (clienteContado.value && t.codigo === 'A') return false
      return true
    })
  }
  if (esTicketCerrado.value) {
    return TIPOS_FINAL.filter((t) => t.codigo === 'F')
  }
  return TIPOS_FINAL.filter((t) => {
    if (clienteContado.value && t.codigo === 'A') return false
    // Sin cliente/NIF/razón: solo Ticket o Presupuesto (no Factura).
    if (t.codigo === 'F' && !tieneDatosFactura.value) return false
    return true
  })
})

const mostrarSelectorFpago = computed(
  () => clienteContado.value || tipoFinal.value === 'T' || tipoFinal.value === 'F' || esTicketCerrado.value
)

function lineaVacia(): VentaLinea {
  return {
    articulo: '',
    descripcion: '',
    loteVenta: '',
    cantidad: 1,
    precio: 0,
    pjeDto: 0,
    importe: 0,
    pjeIva: 21,
  }
}

function vacia(): VentaDetalle {
  const hoy = new Date().toISOString()
  return {
    empresa: puesto.empresaCodigo || '',
    tipo: 'A',
    albaran: 0,
    fecha: hoy,
    cliente: '',
    razonSocial: '',
    razonSocial2: '',
    nif: '',
    puesto: puesto.puestoCodigo || '',
    vendedor: '',
    representante: '',
    transporte: '',
    direccionEnvio: '',
    poblacionEnvio: '',
    codigoPostalEnvio: '',
    provinciaEnvio: '',
    paisEnvio: '',
    telefono: '',
    telefono2: '',
    fax: '',
    email: '',
    almacen: null,
    pedido: null,
    referencia1: '',
    referencia2: '',
    numeroDeSerie: '',
    sujetoPasivo: false,
    portes: '',
    fechaEntrega: null,
    estado: null,
    importe: 0,
    bruto: 0,
    descuento: 0,
    iva: 0,
    pjeIva1: 21,
    pjeDto: 0,
    facturada: false,
    bloqueado: false,
    factura: null,
    facturaTipo: null,
    sesion: null,
    formasPago: [],
    importesIva: [],
    lineas: [],
  }
}

const bloqueado = computed(() => {
  const f = ficha.value
  if (!f) return false
  const ft = String(f.facturaTipo ?? '').trim().toUpperCase()
  // Factura/abono: bloqueo total. Ticket cerrado: no editar, pero se puede pasar a factura.
  if (ft === 'F' || ft === 'A' || Boolean(f.bloqueado)) return true
  return false
})
/** Ticket tipificado (FacturaTipo=T y numero asignado): legacy TransformacionTicketaFactura. */
const esTicketCerrado = computed(() => {
  const f = ficha.value
  if (!f) return false
  if (f.esTicket) return true
  const ft = String(f.facturaTipo ?? '').trim().toUpperCase()
  return ft === 'T' && Number(f.factura ?? 0) > 0
})
const ticketNoEditable = computed(() => esTicketCerrado.value)
const soloLectura = computed(
  () => !(modoEdicion.value || esNuevo.value) || bloqueado.value || ticketNoEditable.value
)
const tieneLineas = computed(() =>
  lineas.value.some((l) => {
    const art = String(l.articulo ?? '').trim()
    return art !== '' && art.toUpperCase() !== 'NO'
  })
)
const tieneCliente = computed(() => Boolean(String(ficha.value?.cliente ?? '').trim()))
/** Líneas solo tras tener cliente (evita alta sin cabecera usable). */
const puedeEditarLineas = computed(() => !soloLectura.value && tieneCliente.value)
const puedeGuardar = computed(
  () =>
    (esNuevo.value ? puedeCrear.value : puedeEditar.value) &&
    !bloqueado.value &&
    !ticketNoEditable.value &&
    tieneCliente.value &&
    tieneLineas.value
)
/** Albaran listo: cabecera creada + al menos una linea de articulo. */
const albaranCompleto = computed(() => Boolean(ficha.value) && !esNuevo.value && tieneLineas.value)
/** Venta nueva aun sin grabar: lista para Guardar y finalizar. */
const ventaNuevaLista = computed(
  () => esNuevo.value && puedeCrear.value && tieneCliente.value && tieneLineas.value
)
/** Imprimir en cualquier documento recuperado con líneas (también albarán periódico, sin sesión). */
const puedeImprimir = computed(() => !modoEdicion.value && albaranCompleto.value)
const puedePasarAFactura = computed(() => {
  if (!esTicketCerrado.value || !puedeEditar.value || modoEdicion.value) return false
  return tieneDatosFactura.value
})
const puedeFinalizar = computed(
  () =>
    !esPlantillaConsulta.value &&
    Boolean(String(ficha.value?.vendedor ?? '').trim()) &&
    (ventaNuevaLista.value ||
      (albaranCompleto.value && !bloqueado.value && !ticketNoEditable.value && puedeEditar.value) ||
      puedePasarAFactura.value)
)
const esAbono = computed(() => {
  const f = ficha.value
  if (!f) return false
  return (Number(f.albaranOrigenAbono) || 0) > 0 || Number(f.importe) < 0
})
/** Tras Finalizar → Albarán: Sesion asignada, sin tipificar F/T/A. */
const albaranFinalizado = computed(() => {
  const f = ficha.value
  if (!f || esNuevo.value) return false
  const ft = String(f.facturaTipo ?? '').trim().toUpperCase()
  if (ft === 'F' || ft === 'A' || ft === 'T' || ft === 'R') return false
  if ((Number(f.factura) || 0) > 0) return false
  return (Number(f.sesion) || 0) > 0
})
/** Factura tipificada de contado (Estado F). Crédito G → rectificativa. */
const esFacturaContado = computed(() => {
  const f = ficha.value
  if (!f || esNuevo.value) return false
  const ft = String(f.facturaTipo ?? '').trim().toUpperCase()
  if (ft !== 'F' || (Number(f.factura) || 0) <= 0) return false
  if (f.facturaContadoDiferida) return false
  const fe = String(f.facturaEstado ?? '').trim().toUpperCase()
  return fe === 'F'
})
const documentoAbonable = computed(() => {
  const f = ficha.value
  if (f && typeof f.permiteAbonoParcial === 'boolean') {
    return f.permiteAbonoParcial
  }
  return albaranFinalizado.value || esTicketCerrado.value || esFacturaContado.value
})
const puedeAbonar = computed(
  () =>
    !esPlantillaConsulta.value &&
    puedeCrear.value &&
    albaranCompleto.value &&
    documentoAbonable.value &&
    !modoEdicion.value &&
    !esAbono.value &&
    lineasAbonables().length > 0
)
const nroLinsAbonados = computed(() => ficha.value?.nroLinsAbonados ?? [])
const abonosExistentes = computed(() => ficha.value?.abonosExistentes ?? [])
/** Durante alta/edicion no salir al listado ni navegar entre documentos. */
const enTrabajo = computed(() => esNuevo.value || modoEdicion.value)
const puedeBuscar = computed(() => !enTrabajo.value)
const puedeNavegar = computed(() => !enTrabajo.value)

function importeLinea(l: VentaLinea): number {
  const lineaBruto = Number(l.cantidad || 0) * Number(l.precio || 0)
  const lineaDto = lineaBruto * (Number(l.pjeDto || 0) / 100)
  return Math.round((lineaBruto - lineaDto) * 100) / 100
}

const totales = computed(() => {
  let bruto = 0
  const acumPorIva = new Map<number, number>()
  for (const l of lineas.value) {
    const art = String(l.articulo ?? '').trim()
    if (!art || art.toUpperCase() === 'NO') continue
    const lineaBruto = Number(l.cantidad || 0) * Number(l.precio || 0)
    const lineaDto = lineaBruto * (Number(l.pjeDto || 0) / 100)
    const neto = Math.round((lineaBruto - lineaDto) * 100) / 100
    bruto += lineaBruto
    const pje =
      Number(l.pjeIva) > 0
        ? Number(l.pjeIva)
        : Number(ficha.value?.pjeIva1) > 0
          ? Number(ficha.value?.pjeIva1)
          : 21
    acumPorIva.set(pje, (acumPorIva.get(pje) ?? 0) + neto)
  }
  let iva = 0
  let importe = 0
  let baseTotal = 0
  let descuentoCabecera = 0
  const pjeDtoCabecera = Number(ficha.value?.pjeDto ?? 0)
  for (const [pje, acum] of acumPorIva) {
    const total = Math.round(acum * 100) / 100
    const baseInicial = preciosIvaIncluido.value && pje > 0
      ? Math.round((total / (1 + pje / 100)) * 100) / 100
      : total
    const dtoBase = Math.round(baseInicial * (pjeDtoCabecera / 100) * 100) / 100
    const base = Math.round((baseInicial - dtoBase) * 100) / 100
    const cuota = pjeDtoCabecera !== 0 || !preciosIvaIncluido.value
      ? Math.round(base * (pje / 100) * 100) / 100
      : Math.round((total - baseInicial) * 100) / 100
    descuentoCabecera += dtoBase
    baseTotal += base
    iva += cuota
    importe += preciosIvaIncluido.value && pjeDtoCabecera === 0 ? total : base + cuota
  }
  return {
    bruto: Math.round(bruto * 100) / 100,
    descuento: Math.round(descuentoCabecera * 100) / 100,
    iva: Math.round(iva * 100) / 100,
    importe: Math.round(importe * 100) / 100,
    base: Math.round(baseTotal * 100) / 100,
  }
})

async function cargarPreciosIvaIncluido(empresa: string, opts?: { aplicarAlmacen?: boolean }) {
  const cod = String(empresa ?? '').trim()
  if (!cod) {
    preciosIvaIncluido.value = false
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(cod)}`)
    preciosIvaIncluido.value = Boolean(data.swIva)
    if (opts?.aplicarAlmacen && ficha.value) {
      const alm = data.almacenCodigo
      ficha.value = {
        ...ficha.value,
        almacen: alm === null || alm === undefined || alm === '' ? null : Number(alm),
      }
    }
  } catch {
    preciosIvaIncluido.value = false
  }
}

async function cargarFormasPagoContado() {
  try {
    const { data } = await api.get('/api/mantenimiento/formas-pago', {
      params: { pageSize: 500, activo: true },
    })
    const items = Array.isArray(data?.items) ? data.items : Array.isArray(data) ? data : []
    formasPagoContado.value = items
      .filter((f: { cobroDeArqueo?: boolean; codigo?: string }) =>
        Boolean(f.cobroDeArqueo && String(f.codigo ?? '').trim())
      )
      .map((f: { codigo: string; descripcion?: string }) => ({
        codigo: String(f.codigo).trim(),
        descripcion: String(f.descripcion ?? f.codigo).trim(),
      }))
  } catch {
    formasPagoContado.value = []
  }
}

const navItems = computed(() => busqueda.items)

const indiceNav = computed(() => {
  if (!ficha.value || esNuevo.value) return -1
  return busqueda.indiceDe(ficha.value)
})

function resumenDesdeDetalle(d: VentaDetalle): VentaResumen {
  return {
    empresa: d.empresa,
    tipo: d.tipo,
    albaran: d.albaran,
    fecha: d.fecha,
    cliente: d.cliente,
    razonSocial: d.razonSocial,
    puesto: d.puesto,
    vendedor: d.vendedor,
    estado: d.estado,
    importe: d.importe,
    facturada: d.facturada,
    bloqueado: d.bloqueado,
    factura: d.factura,
    facturaTipo: d.facturaTipo,
    sesion: d.sesion,
    albaranOrigenAbono: d.albaranOrigenAbono ?? null,
  }
}

/** Alta: cabecera completa (legacy PreGrabacion) para el INSERT; el nº lo asigna la API. */
function payloadAltaCompleto(): VentaPayload {
  const f = ficha.value!
  const vendedorPuesto = String(f.vendedor ?? '').trim()
  return {
    ...payloadDesdeFicha(),
    albaran: undefined,
    representante: String(f.representante ?? '').trim() || ' ',
    transporte: String(f.transporte ?? '').trim() || ' ',
    fpago1: String(f.formasPago?.[0]?.codigo ?? '').trim() || ' ',
    fpago2: '',
    actividad: 0,
    agente: agenteCliente.value || ' ',
    facturaTipo: 'R',
    empresaFacturacion: f.empresa,
    vendedor: vendedorPuesto || null,
    vendedorApertura: vendedorPuesto || null,
    tarifa: f.tarifa ?? 0,
    tipo: 'A',
  }
}

function payloadDesdeFicha(): VentaPayload {
  const f = ficha.value!
  return {
    empresa: f.empresa,
    tipo: f.tipo || 'A',
    albaran: f.albaran > 0 ? f.albaran : undefined,
    puesto: f.puesto,
    cliente: f.cliente,
    razonSocial: f.razonSocial,
    razonSocial2: f.razonSocial2,
    nif: f.nif,
    fecha: f.fecha || undefined,
    vendedor: f.vendedor,
    vendedorApertura: f.vendedor,
    representante: f.representante,
    transporte: f.transporte,
    direccionEnvio: f.direccionEnvio,
    poblacionEnvio: f.poblacionEnvio,
    codigoPostalEnvio: f.codigoPostalEnvio,
    provinciaEnvio: f.provinciaEnvio,
    paisEnvio: f.paisEnvio,
    telefono: f.telefono,
    telefono2: f.telefono2,
    fax: f.fax,
    email: f.email,
    almacen: f.almacen,
    pedido: f.pedido,
    referencia1: f.referencia1,
    referencia2: f.referencia2,
    numeroDeSerie: f.numeroDeSerie,
    sujetoPasivo: !!f.sujetoPasivo,
    portes: f.portes,
    pjeIva1: Number(f.pjeIva1) > 0 ? Number(f.pjeIva1) : 21,
    fpago1: f.formasPago?.[0]?.codigo ?? '',
    fpago2: f.formasPago?.[1]?.codigo ?? '',
    impFpago1: Number(f.formasPago?.[0]?.importe ?? 0),
    impFpago2: Number(f.formasPago?.[1]?.importe ?? 0),
    lineas: lineas.value
      .filter((l) => String(l.articulo ?? '').trim())
      .map((l) => {
        if (String(l.articulo ?? '').trim().toUpperCase() === 'NO') {
          return {
            ...l,
            articulo: 'NO',
            cantidad: 0,
            precio: 0,
            pjeDto: 0,
            importe: 0,
            pjeIva: 0,
          }
        }
        return { ...l, importe: importeLinea(l) }
      }),
  }
}

async function cargar() {
  if (!esEstaInstanciaActiva()) return

  if (route.query.plantillaConsulta === '1') {
    plantillaPeriodica.iniciarConsulta()
  } else if (route.query.plantillaPeriodica === '1') {
    plantillaPeriodica.iniciarAlta()
  }

  const esRutaNuevo = route.name === 'ventas-nuevo'
  if (esRutaNuevo) {
    iniciarNuevaVenta()
    return
  }

  if (omitirProximaCarga.value) {
    omitirProximaCarga.value = false
    esNuevo.value = false
    modoEdicion.value = true
    pasoAlta.value = 'listo'
    if (!lineas.value.length) lineas.value = [lineaVacia()]
    mensaje.value = 'Introduzca articulos (Intro en codigo para buscar)'
    await focusArticuloLinea(0)
    return
  }

  loading.value = true
  error.value = null
  try {
    const data = await obtenerVenta(
      String(route.params.empresa),
      String(route.params.tipo),
      Number(route.params.albaran)
    )
    if (!esEstaInstanciaActiva()) return
    aplicarDetalle(data)
    esNuevo.value = false
    pasoAlta.value = 'listo'
    const forzarEdicion = busqueda.consumirAbrirEnEdicion({
      empresa: data.empresa,
      tipo: data.tipo,
      albaran: data.albaran,
    })
    const bloqueadoDoc = Boolean(data.bloqueado || data.facturada)
    const sinArticulos = !(data.lineas ?? []).some((l) => {
      const art = String(l.articulo ?? '').trim()
      return art !== '' && art.toUpperCase() !== 'NO'
    })
    const sinCliente = !String(data.cliente ?? '').trim()
    // Tras alta de cabecera el layout remonta (:key=path). Tambien reabrir borradores sin lineas
    // o documentos sin cliente (estado inconsistente).
    modoEdicion.value = !bloqueadoDoc && (forzarEdicion || sinArticulos || sinCliente)
    if (modoEdicion.value) {
      if (!lineas.value.length) lineas.value = [lineaVacia()]
      if (sinCliente) {
        mensaje.value =
          'Falta el cliente. Intro vacío en Código = venta rápida; F4 o … para buscar.'
      } else {
        mensaje.value = sinArticulos
          ? 'Puede completar cabecera (p. ej. Referencia) e introducir articulos. Luego Guardar.'
          : 'Introduzca articulos (Intro en codigo para buscar)'
        // Con cliente listo → foco en 1ª línea de artículo (pistola sin clic).
        await focusArticuloLinea(0)
      }
    }
  } catch (e: unknown) {
    if (!esEstaInstanciaActiva()) return
    error.value = extractApiError(e, 'No se pudo cargar la venta')
    ficha.value = null
  } finally {
    if (esEstaInstanciaActiva()) {
      loading.value = false
    }
  }
}

function aplicarDetalle(data: VentaDetalle) {
  ficha.value = data
  lineas.value = data.lineas?.length ? data.lineas.map((l) => ({ ...l })) : [lineaVacia()]
  void cargarPreciosIvaIncluido(data.empresa)
  void resolverNombreVendedor(String(data.vendedor ?? ''))
}

function irA(v: VentaResumen) {
  // Misma pestaña al recorrer resultados (no apilar pestañas por albaran).
  router.replace(`/ventas/${encodeURIComponent(v.empresa)}/${encodeURIComponent(v.tipo)}/${v.albaran}`)
}

function iniciarNuevaVenta() {
  error.value = null
  mensaje.value = null
  esNuevo.value = true
  modoEdicion.value = true
  omitirProximaCarga.value = false
  pasoAlta.value = 'tienda'
  buscarClienteOpen.value = false
  buscarArticuloOpen.value = false
  buscarVendedorOpen.value = false
  clienteContado.value = false
  formaPagoCliente.value = ''
  vendedorNombre.value = ''
  agenteCliente.value = ''
  ficha.value = vacia()
  lineas.value = [lineaVacia()]
  void precargarVendedorDelPuesto()
  void nextTick(async () => {
    await cabeceraForm.value?.focusTienda()
    mensaje.value = esPlantillaAlta.value
      ? 'Nueva plantilla periódica: elija tienda, cliente y líneas. Guarde y Finalice como Presupuesto.'
      : 'Nueva venta: elija tienda y pulse Intro'
  })
}

function onNuevo() {
  if (route.name === 'ventas-nuevo') {
    iniciarNuevaVenta()
    mensaje.value = 'Nueva venta: elija tienda y pulse Intro'
    return
  }
  router.push({ name: 'ventas-nuevo' })
}

function onKeyEnter(e: KeyboardEvent) {
  // Solo captura Intro durante el alta de cabecera; no interferir con lineas/modales.
  if (!esNuevo.value || pasoAlta.value === 'listo') return
  if (buscarClienteOpen.value || buscarArticuloOpen.value) return
  const t = e.target
  if (t instanceof HTMLElement) {
    const tag = t.tagName
    if (tag === 'TEXTAREA' || (tag === 'INPUT' && t.closest('.lineas-panel'))) return
  }
  e.preventDefault()
  void onIntroCabecera()
}

function precioSegunTarifa(art: Record<string, unknown>, tarifa: number | null | undefined): number {
  const n = tarifa && tarifa >= 1 && tarifa <= 9 ? tarifa : 1
  const key = `precioVen${n}`
  const raw = art[key] ?? art.precioVenta ?? art.precioVen1 ?? 0
  return Number(raw) || 0
}

async function resolverFormaPagoContado(codigoFpago: string): Promise<boolean> {
  const codigo = codigoFpago.trim()
  formaPagoCliente.value = codigo
  if (!codigo) {
    clienteContado.value = false
    return false
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/formas-pago/${encodeURIComponent(codigo)}`)
    const contado = Boolean(data.cobroDeArqueo)
    clienteContado.value = contado
    return contado
  } catch {
    clienteContado.value = false
    return false
  }
}

async function aplicarComentarioEnLinea(index: number) {
  const linea = lineas.value[index]
  if (!linea) return
  linea.articulo = 'NO'
  linea.cantidad = 0
  linea.precio = 0
  linea.pjeDto = 0
  linea.importe = 0
  linea.pjeIva = 0
  if (!String(linea.descripcion ?? '').trim()) {
    linea.descripcion = ''
  }
  if (index === lineas.value.length - 1) {
    lineas.value.push(lineaVacia())
  }
  await focusDescripcionLinea(index)
}

async function aplicarArticuloEnLinea(
  index: number,
  art: Record<string, unknown>,
  fallbackCodigo: string,
  opts?: { unidadesPaquete?: number }
) {
  const linea = lineas.value[index]
  if (!linea) return
  const codigo = String(art.codigo ?? fallbackCodigo).trim()
  if (codigo.toUpperCase() === 'NO') {
    await aplicarComentarioEnLinea(index)
    return
  }
  linea.articulo = codigo
  linea.descripcion = String(art.descripcion ?? '').trim()
  linea.precio = redondear2(precioSegunTarifa(art, ficha.value?.tarifa))
  const uds = Number(opts?.unidadesPaquete)
  if (uds > 0 && (!linea.cantidad || linea.cantidad === 1)) {
    linea.cantidad = redondear2(uds)
  } else if (!linea.cantidad) {
    linea.cantidad = 1
  } else {
    linea.cantidad = redondear2(linea.cantidad)
  }
  let pjeIva = Number(linea.pjeIva) > 0 ? Number(linea.pjeIva) : 21
  const impuestoCodigo = String(art.impuestoCodigo ?? '').trim()
  if (impuestoCodigo) {
    try {
      const { data: imp } = await api.get(
        `/api/mantenimiento/impuestos/${encodeURIComponent(impuestoCodigo)}`
      )
      const pct = Number(imp.porcentajeIVA ?? imp.pjeIva ?? 0)
      if (pct > 0) pjeIva = pct
    } catch {
      /* mantener default */
    }
  }
  linea.pjeIva = pjeIva
  linea.importe = importeLinea(linea)
  if (index === lineas.value.length - 1) {
    lineas.value.push(lineaVacia())
  }
  await focusArticuloLinea(index + 1)
}

async function onArticuloSeleccionado(sel: { codigo: string; etiqueta: string }) {
  buscarArticuloOpen.value = false
  const idx = lineaArticuloIdx.value
  try {
    const { data: art } = await api.get(
      `/api/mantenimiento/articulos/${encodeURIComponent(sel.codigo)}`
    )
    await aplicarArticuloEnLinea(idx, art as Record<string, unknown>, sel.codigo)
  } catch {
    const linea = lineas.value[idx]
    if (!linea) return
    linea.articulo = sel.codigo
    linea.descripcion = sel.etiqueta
    if (idx === lineas.value.length - 1) {
      lineas.value.push(lineaVacia())
    }
    await focusArticuloLinea(idx + 1)
  }
}

/** Índice de la línea que está recibiendo la ráfaga del escáner. */
let barcodeLineaIdx = 0
const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  if (!puedeEditarLineas.value) return
  await resolverArticuloEnLinea(barcodeLineaIdx, codigo)
})

async function resolverArticuloEnLinea(index: number, codigo: string) {
  const q = String(codigo ?? '').trim()
  if (!q) {
    abrirBuscarArticulo(index)
    return
  }
  if (q.toUpperCase() === 'NO') {
    await aplicarComentarioEnLinea(index)
    return
  }
  try {
    const art = await resolverArticulo(q)
    await aplicarArticuloEnLinea(index, art, art.codigo, {
      unidadesPaquete: art.unidadesPaquete,
    })
  } catch {
    abrirBuscarArticulo(index)
  }
}

function onArticuloInput(index: number) {
  if (!puedeEditarLineas.value) return
  barcodeLineaIdx = index
  barcodeWatcher.onInput(String(lineas.value[index]?.articulo ?? ''))
}

async function onArticuloKeydown(e: KeyboardEvent, index: number) {
  if (!puedeEditarLineas.value) {
    if (!tieneCliente.value) {
      error.value = 'Seleccione el cliente antes de introducir artículos'
      abrirBuscarCliente()
    } else if (soloLectura.value && puedeEditar.value && !bloqueado.value) {
      modoEdicion.value = true
    }
    return
  }
  if (e.key === 'F4') {
    e.preventDefault()
    e.stopPropagation()
    barcodeWatcher.cancel()
    abrirBuscarArticulo(index)
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  e.stopPropagation()
  barcodeWatcher.cancel()
  await resolverArticuloEnLinea(index, String(lineas.value[index]?.articulo ?? ''))
}

async function onIntroCabecera() {
  if (!esNuevo.value || !ficha.value || loading.value) return
  error.value = null

  if (pasoAlta.value === 'tienda') {
    if (!ficha.value.empresa.trim()) {
      error.value = 'Seleccione la tienda'
      return
    }
    if (!puedeCrear.value) {
      error.value = 'No tiene permiso para crear ventas (rol sin accion Crear en Ventas)'
      return
    }
    loading.value = true
    try {
      // El numero de albaran se asigna al grabar (Guardar y finalizar), no al empezar:
      // asi una venta abandonada no consume numeracion.
      ficha.value = {
        ...ficha.value,
        tipo: 'A',
        albaran: 0,
        puesto: ficha.value.puesto || puesto.puestoCodigo || '',
      }
      await cargarPreciosIvaIncluido(ficha.value.empresa, { aplicarAlmacen: true })
      await precargarVendedorDelPuesto()
      pasoAlta.value = 'cliente'
      mensaje.value = null
      await cabeceraForm.value?.focusCliente()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo preparar la venta')
    } finally {
      loading.value = false
    }
    return
  }

  if (pasoAlta.value === 'cliente') {
    const codigo = String(ficha.value.cliente ?? '').trim()
    await confirmarCliente(codigo || CLIENTE_SIN_NOMBRE, { abrirBusquedaSiNoExiste: Boolean(codigo) })
  }
}

function abrirBuscarCliente() {
  if (esNuevo.value && pasoAlta.value === 'tienda') {
    error.value = 'Primero confirme la tienda para reservar el número de albarán.'
    void cabeceraForm.value?.focusTienda()
    return
  }
  if (soloLectura.value) {
    if (!puedeEditar.value || bloqueado.value || esTicketCerrado.value) return
    modoEdicion.value = true
  }
  buscarClienteOpen.value = true
}

async function onClienteKeydown(e: KeyboardEvent) {
  if (esNuevo.value && pasoAlta.value !== 'cliente') {
    if (e.key === 'Enter' || e.key === 'F4') {
      e.preventDefault()
      e.stopPropagation()
      error.value = 'Primero confirme la tienda para reservar el número de albarán.'
      await cabeceraForm.value?.focusTienda()
    }
    return
  }
  if (soloLectura.value) {
    if (!puedeEditar.value || bloqueado.value || esTicketCerrado.value) return
    modoEdicion.value = true
  }
  if (e.key === 'F4') {
    e.preventDefault()
    e.stopPropagation()
    abrirBuscarCliente()
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  e.stopPropagation()
  const codigo = String(ficha.value?.cliente ?? '').trim()
  await confirmarCliente(codigo || CLIENTE_SIN_NOMBRE, { abrirBusquedaSiNoExiste: Boolean(codigo) })
}

async function cargarDatosCliente(codigo: string): Promise<{
  sel: { codigo: string; etiqueta: string }
  cli: Record<string, unknown>
}> {
  const cod = codigo.trim()
  if (!cod) {
    throw new Error('Codigo de cliente vacio')
  }
  try {
    const { data: cli } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(cod)}`)
    return {
      sel: { codigo: String(cli.codigo ?? cod), etiqueta: String(cli.nombre ?? '') },
      cli: cli as Record<string, unknown>,
    }
  } catch (e: unknown) {
    if (cod.toUpperCase() === CLIENTE_SIN_NOMBRE) {
      return {
        sel: { codigo: CLIENTE_SIN_NOMBRE, etiqueta: '' },
        cli: { codigo: CLIENTE_SIN_NOMBRE, nombre: '', nif: '' },
      }
    }
    throw e
  }
}

async function confirmarCliente(
  codigo: string,
  opts?: { abrirBusquedaSiNoExiste?: boolean }
) {
  await onClienteSeleccionado({ codigo, etiqueta: '' }, opts)
}

async function aplicarClienteEnFicha(
  sel: { codigo: string; etiqueta: string },
  cli: Record<string, unknown>
) {
  if (!ficha.value) return
  const vendedorPuesto = String(ficha.value.vendedor ?? '').trim()
  const representanteCli = (() => {
    const v = cli.vendedor
    if (v === true || v === false || v == null) return ''
    const s = String(v).trim()
    if (!s || s.toLowerCase() === 'true' || s.toLowerCase() === 'false') return ''
    return s
  })()
  const transporteCli = String(cli.transportista ?? cli.transporte ?? '').trim()
  const fpagoCli = String(cli.formaPago ?? '').trim()
  ficha.value = {
    ...ficha.value,
    cliente: String(cli.codigo ?? sel.codigo).trim(),
    razonSocial: String(cli.nombre ?? sel.etiqueta ?? '').trim(),
    razonSocial2: cli.razonSocial2 != null ? String(cli.razonSocial2) : '',
    nif: cli.nif != null ? String(cli.nif) : '',
    direccionEnvio: String(cli.direccion ?? ''),
    poblacionEnvio: String(cli.poblacion ?? ''),
    codigoPostalEnvio: String(cli.codigoPostal ?? ''),
    provinciaEnvio: String(cli.provincia ?? ''),
    paisEnvio: String(cli.pais ?? ''),
    telefono: cli.telefono1 != null ? String(cli.telefono1) : '',
    telefono2: cli.telefono2 != null ? String(cli.telefono2) : '',
    fax: cli.fax != null ? String(cli.fax) : '',
    email: cli.email != null ? String(cli.email) : '',
    representante: representanteCli,
    transporte: transporteCli,
    portes: '',
    vendedor: vendedorPuesto,
    pjeDto: Number(cli.dto1 ?? 0),
    // La forma de pago pertenece al cliente seleccionado. Si el nuevo cliente
    // no tiene ninguna, no conservar la del cliente anterior.
    formasPago: [{ codigo: fpagoCli, importe: 0 }, { codigo: '', importe: 0 }],
    tarifa: cli.tarifa != null && cli.tarifa !== '' ? Number(cli.tarifa) : null,
  }
  await resolverFormaPagoContado(fpagoCli)
}

async function onClienteSeleccionado(
  sel: { codigo: string; etiqueta: string },
  opts?: { abrirBusquedaSiNoExiste?: boolean }
) {
  buscarClienteOpen.value = false
  if (!ficha.value) return
  if (esNuevo.value && pasoAlta.value === 'tienda') {
    error.value = 'Primero confirme la tienda.'
    await cabeceraForm.value?.focusTienda()
    return
  }
  loading.value = true
  error.value = null
  try {
    const { sel: resolved, cli } = await cargarDatosCliente(sel.codigo)
    await aplicarClienteEnFicha(resolved, cli)
    agenteCliente.value = String(cli.agenteOrigen ?? cli.agente ?? '').trim()

    // Alta: la venta vive en memoria hasta Guardar y finalizar (no consume numeracion).
    if (esNuevo.value) {
      pasoAlta.value = 'listo'
      mensaje.value = null
      await focusArticuloLinea(0)
      return
    }

    modoEdicion.value = true
    const saved = await actualizarVenta(
      ficha.value.empresa,
      ficha.value.tipo,
      ficha.value.albaran,
      payloadDesdeFicha()
    )
    aplicarDetalle(saved)
    modoEdicion.value = true
    mensaje.value = 'Cliente asignado. Puede editar líneas y Guardar.'
    busqueda.upsertResumen(resumenDesdeDetalle(saved))
    await focusArticuloLinea(0)
  } catch (e: unknown) {
    if (opts?.abrirBusquedaSiNoExiste) {
      error.value = 'Cliente no encontrado. Selecciónelo en la búsqueda.'
      abrirBuscarCliente()
    } else {
      error.value = extractApiError(e, 'No se pudo asignar el cliente')
    }
  } finally {
    loading.value = false
  }
}

async function usarVentaRapida() {
  if (!esNuevo.value || pasoAlta.value !== 'cliente' || loading.value) return
  await confirmarCliente(CLIENTE_SIN_NOMBRE)
}

function onModificar() {
  if (bloqueado.value || esTicketCerrado.value) {
    error.value = esTicketCerrado.value
      ? 'Ticket cerrado: no se puede modificar. Use Finalizar para pasarlo a factura.'
      : 'Documento facturado: no se puede modificar'
    return
  }
  modoEdicion.value = true
}

function onCancelar() {
  if (esPlantillaConsulta.value && modoEdicion.value) {
    modoEdicion.value = false
    void cargar()
    return
  }
  if (esPlantillaAlta.value && (esNuevo.value || !ficha.value?.albaran)) {
    plantillaPeriodica.cancelar()
    router.push({ name: 'albaranes-periodicos' })
    return
  }
  if (esNuevo.value) {
    // Nada grabado todavia: solo confirmar si hay datos escritos que se perderian.
    if (tieneCliente.value || tieneLineas.value) {
      confirmCancelarAlta.value = true
      return
    }
    router.push('/ventas')
    return
  }
  modoEdicion.value = false
  cargar()
}

function confirmarCancelarVentaNueva() {
  confirmCancelarAlta.value = false
  router.push('/ventas')
}

async function onGuardar() {
  if (!ficha.value || !puedeGuardar.value) return
  if (!ficha.value.empresa.trim()) {
    error.value = 'Seleccione la tienda'
    return
  }
  if (!tieneCliente.value) {
    error.value = 'Indique el cliente antes de guardar'
    abrirBuscarCliente()
    return
  }
  if (!tieneLineas.value) {
    error.value = 'Introduzca al menos un articulo antes de guardar'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const saved = esNuevo.value
      ? await crearVenta(payloadAltaCompleto())
      : await actualizarVenta(
          ficha.value.empresa,
          ficha.value.tipo,
          ficha.value.albaran,
          payloadDesdeFicha()
        )
    aplicarDetalle(saved)
    esNuevo.value = false
    modoEdicion.value = false
    mensaje.value = esPlantillaConsulta.value
      ? 'Plantilla actualizada.'
      : esPlantillaAlta.value
        ? 'Documento guardado. Finalice como Presupuesto para registrar la plantilla periódica.'
        : tieneLineas.value
          ? 'Albarán guardado. Ya puede finalizarlo.'
          : 'Venta guardada'
    busqueda.upsertResumen(resumenDesdeDetalle(saved))
    if (
      route.params.empresa !== saved.empresa ||
      route.params.tipo !== saved.tipo ||
      Number(route.params.albaran) !== saved.albaran
    ) {
      router.replace(destinoVenta(saved.empresa, saved.tipo, saved.albaran))
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar')
  } finally {
    loading.value = false
  }
}

function onBorrar() {
  if (!ficha.value || bloqueado.value) return
  confirmBorrar.value = true
}

async function confirmarBorrar() {
  confirmBorrar.value = false
  if (!ficha.value) return
  loading.value = true
  try {
    const key = {
      empresa: ficha.value.empresa,
      tipo: ficha.value.tipo,
      albaran: ficha.value.albaran,
    }
    if (esPlantillaConsulta.value) {
      try {
        await eliminarAlbaranPeriodico(key.empresa, key.tipo, key.albaran)
      } catch (e: unknown) {
        const status = (e as { response?: { status?: number } })?.response?.status
        if (status !== 404) throw e
      }
    }
    await eliminarVenta(key.empresa, key.tipo, key.albaran)
    busqueda.quitar(key)
    if (esPlantillaConsulta.value) {
      plantillaPeriodica.cancelar()
      router.push({ name: 'albaranes-periodicos' })
    } else {
      router.push('/ventas')
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo borrar')
  } finally {
    loading.value = false
  }
}

async function onFinalizar() {
  if (!ficha.value || esPlantillaConsulta.value) return
  if (bloqueado.value && !esTicketCerrado.value) return
  if (!String(ficha.value.vendedor ?? '').trim()) {
    error.value = 'No se puede finalizar la venta sin vendedor. Selecciónelo en la cabecera.'
    return
  }
  const fpago =
    String(ficha.value.formasPago?.[0]?.codigo ?? '').trim() || formaPagoCliente.value
  if (fpago) {
    await resolverFormaPagoContado(fpago)
  } else if (ficha.value.cliente) {
    try {
      const { data: cli } = await api.get(
        `/api/mantenimiento/clientes/${encodeURIComponent(ficha.value.cliente)}`
      )
      await resolverFormaPagoContado(String(cli.formaPago ?? ''))
    } catch {
      clienteContado.value = false
    }
  }
  if (esTicketCerrado.value) {
    // Legacy TransformacionTicketaFactura: solo Factura.
    tipoFinal.value = 'F'
  } else if (esPlantillaAlta.value) {
    tipoFinal.value = 'P'
  } else {
    tipoFinal.value = clienteContado.value ? 'T' : 'A'
  }
  if (!tiposFinalDisponibles.value.some((t) => t.codigo === tipoFinal.value)) {
    tipoFinal.value = tiposFinalDisponibles.value[0]?.codigo ?? (esPlantillaAlta.value ? 'P' : 'T')
  }
  await cargarFormasPagoContado()
  const preferida =
    fpago ||
    formaPagoCliente.value ||
    String(ficha.value.formasPago?.[0]?.codigo ?? '').trim()
  if (preferida && formasPagoContado.value.some((f) => f.codigo === preferida)) {
    fpagoFinal.value = preferida
  } else {
    fpagoFinal.value = formasPagoContado.value[0]?.codigo ?? preferida ?? ''
  }
  finalizarOpen.value = true
}

function onImprimir() {
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  formatoImpresionOpen.value = true
}

async function onElegirFormatoImpresion(formato: 'ticket' | 'a4') {
  formatoImpresionOpen.value = false
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  if (formato === 'a4') {
    const titulo = `${etiquetaA4Impresion.value} · Alb. ${ficha.value.albaran}`
    if (!preview.abrir(titulo)) {
      error.value = 'Permita las ventanas emergentes para previsualizar el documento'
      return
    }
  }
  loading.value = true
  try {
    const res = await prepararOImprimirVenta(ficha.value, {
      puestoCodigo: String(puesto.puestoCodigo || ficha.value.puesto || ''),
      formato,
    })
    if (res.kind === 'ticket') {
      const updated = await obtenerVenta(ficha.value.empresa, ficha.value.tipo, ficha.value.albaran)
      aplicarDetalle(updated)
      mensaje.value = res.message
      return
    }
    await completarPreviewA4(res.prep)
  } catch (e: unknown) {
    preview.cerrar()
    error.value = extractApiError(e, 'No se pudo imprimir')
  } finally {
    loading.value = false
  }
}

/** Previsualización en ventana aparte: el modal solo renderiza el folio oculto. */
async function mostrarPreviewA4(prep: PrepImpresionA4) {
  if (!preview.abrir(prep.titulo)) {
    throw new Error('Permita las ventanas emergentes para previsualizar el documento')
  }
  await completarPreviewA4(prep)
}

async function completarPreviewA4(prep: PrepImpresionA4) {
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
  if (!ficha.value || !a4Prep.value) return
  a4Imprimiendo.value = true
  error.value = null
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    const msg = await imprimirA4Preparado(a4Prep.value, html, ficha.value)
    const updated = await obtenerVenta(ficha.value.empresa, ficha.value.tipo, ficha.value.albaran)
    aplicarDetalle(updated)
    mensaje.value = msg
    preview.cerrar()
    a4Open.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo imprimir el documento A4')
    error.value = msg
    preview.notificarError(msg)
  } finally {
    a4Imprimiendo.value = false
  }
}

function abrirAccionesPostVenta(venta: VentaDetalle, opcionElegida: string) {
  const op = String(opcionElegida).toUpperCase()
  const etiqueta = TIPOS_FINAL.find((t) => t.codigo === op)?.label ?? 'Documento'
  const numero = ['T', 'F', 'A'].includes(op) && Number(venta.factura) > 0
    ? venta.factura
    : venta.albaran
  postVentaDocumento.value = `${etiqueta} ${numero ?? ''}`.trim()
  postVentaError.value = null
  postVentaOpen.value = true
}

async function imprimirTrasFinalizar() {
  const venta = ficha.value
  if (!venta) return
  postVentaOpen.value = false
  const pue = String(puesto.puestoCodigo || venta.puesto || '').trim()
  try {
    const res = await prepararOImprimirVenta(venta, { puestoCodigo: pue })
    if (res.kind === 'ticket') {
      mensaje.value = `${mensaje.value ? mensaje.value + ' · ' : ''}${res.message}`
    } else {
      await mostrarPreviewA4(res.prep)
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Documento finalizado, pero no se pudo imprimir')
  }
}

async function enviarTrasFinalizar(email: string) {
  const venta = ficha.value
  if (!venta || postVentaEnviando.value) return
  postVentaEnviando.value = true
  postVentaError.value = null
  try {
    const res = await enviarVentaPorEmail(venta.empresa, venta.tipo, venta.albaran, email)
    postVentaOpen.value = false
    mensaje.value = `${res.documento} enviado a ${res.destinatario}`
  } catch (e: unknown) {
    postVentaError.value = extractApiError(e, 'No se pudo enviar el documento por email')
  } finally {
    postVentaEnviando.value = false
  }
}

async function confirmarFinalizar() {
  if (!ficha.value) return
  if (!String(ficha.value.vendedor ?? '').trim()) {
    finalizarOpen.value = false
    error.value = 'No se puede finalizar la venta sin vendedor. Selecciónelo en la cabecera.'
    return
  }
  if (mostrarSelectorFpago.value && !String(fpagoFinal.value).trim()) {
    error.value = 'Seleccione una forma de pago de contado'
    return
  }
  const opcion = tipoFinal.value
  finalizarOpen.value = false
  loading.value = true
  error.value = null
  const prev = {
    empresa: ficha.value.empresa,
    tipo: ficha.value.tipo,
    albaran: ficha.value.albaran,
  }
  const eraNueva = esNuevo.value
  try {
    if (eraNueva) {
      // Aqui se graba por primera vez: la API asigna el numero de albaran.
      const payload = payloadAltaCompleto()
      if (mostrarSelectorFpago.value && fpagoFinal.value) {
        payload.fpago1 = fpagoFinal.value
        payload.impFpago1 = totales.value.importe
      }
      const creada = await crearVenta(payload)
      aplicarDetalle(creada)
      esNuevo.value = false
      pasoAlta.value = 'listo'
      busqueda.upsertResumen(resumenDesdeDetalle(creada))
    } else if (modoEdicion.value) {
      const payload = payloadDesdeFicha()
      if (mostrarSelectorFpago.value && fpagoFinal.value) {
        payload.fpago1 = fpagoFinal.value
        payload.impFpago1 = totales.value.importe
      }
      const saved = await actualizarVenta(
        ficha.value.empresa,
        ficha.value.tipo,
        ficha.value.albaran,
        payload
      )
      aplicarDetalle(saved)
    }
    const done = await finalizarVenta(
      ficha.value.empresa,
      ficha.value.tipo,
      ficha.value.albaran,
      opcion,
      mostrarSelectorFpago.value ? fpagoFinal.value : undefined
    )
    aplicarDetalle(done)
    modoEdicion.value = false
    if (String(done.facturaTipo ?? '').toUpperCase() === 'F' && opcion === 'F') {
      mensaje.value = `Ticket pasado a factura ${done.factura ?? ''}`
    } else {
      mensaje.value = `Documento tipificado como ${
        TIPOS_FINAL.find((t) => t.codigo === (done.facturaTipo || opcion))?.label ??
        done.facturaTipo ??
        opcion
      }`
    }
    if (
      prev.albaran > 0 &&
      (prev.empresa !== done.empresa || prev.tipo !== done.tipo || prev.albaran !== done.albaran)
    ) {
      busqueda.quitar(prev)
    }
    busqueda.upsertResumen(resumenDesdeDetalle(done))
    // Venta recien creada: no navegar. La ruta /ventas/nuevo cambiaria de instancia
    // (KeepAlive va por fullPath) y se perderia el dialogo de impresion / email.
    if (!eraNueva) {
      router.replace(destinoVenta(done.empresa, done.tipo, done.albaran))
    }
    if (esPlantillaAlta.value) {
      plantillaPeriodicaForm.ultimaGeneracion = new Date().toISOString().slice(0, 10)
      plantillaPeriodicaOpen.value = true
      mensaje.value = 'Documento listo. Indique la periodicidad y pulse Crear plantilla.'
    } else {
      abrirAccionesPostVenta(done, opcion)
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo finalizar')
  } finally {
    loading.value = false
  }
}

async function confirmarPlantillaPeriodica() {
  if (!ficha.value || plantillaPeriodicaSaving.value) return
  if (periodicidadPlantilla.value <= 0) {
    error.value = 'La periodicidad debe ser mayor que 0'
    return
  }
  plantillaPeriodicaSaving.value = true
  error.value = null
  try {
    await crearAlbaranPeriodico({
      empresa: ficha.value.empresa,
      tipo: ficha.value.tipo,
      albaran: ficha.value.albaran,
      periodicidad: periodicidadPlantilla.value,
      ultimaGeneracion: plantillaPeriodicaForm.ultimaGeneracion,
      marcarReferenciaPeriodico: plantillaPeriodicaForm.marcarReferenciaPeriodico,
    })
    plantillaPeriodicaOpen.value = false
    plantillaPeriodica.cancelar()
    router.push({ name: 'albaranes-periodicos' })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo registrar la plantilla periódica')
  } finally {
    plantillaPeriodicaSaving.value = false
  }
}

function cancelarPlantillaPeriodica() {
  plantillaPeriodicaOpen.value = false
  mensaje.value =
    'Documento guardado en Ventas. Puede volver a Albaranes periódicos o registrarlo más tarde.'
}

function onBuscarListado() {
  if (esPlantillaConsulta.value || esPlantillaAlta.value) {
    plantillaPeriodica.cancelar()
    router.push({ name: 'albaranes-periodicos' })
    return
  }
  router.push('/ventas')
}

function lineaYaAbonada(l: VentaLinea): boolean {
  const nro = Number(l.nroLin) || 0
  return nro > 0 && nroLinsAbonados.value.includes(nro)
}

function lineasAbonables(): VentaLinea[] {
  return lineas.value.filter((l) => {
    const art = String(l.articulo ?? '').trim()
    if (!art || art.toUpperCase() === 'NO') return false
    if (Math.abs(Number(l.cantidad) || 0) < 0.0001) return false
    if (lineaYaAbonada(l)) return false
    return (Number(l.nroLin) || 0) > 0
  })
}

function lineasConEstadoAbono(): VentaLinea[] {
  return lineas.value.filter((l) => {
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
    const done = await crearAbonoDesdeVenta(
      ficha.value.empresa,
      ficha.value.tipo,
      ficha.value.albaran,
      {
        nroLins: abonoNroLins.value,
        observacion: abonoObservacion.value.trim() || undefined,
      }
    )
    busqueda.upsertResumen(resumenDesdeDetalle(done))
    mensaje.value = `Albarán de abono ${done.albaran} creado (origen ${ficha.value.albaran})`
    await router.push(
      `/ventas/${encodeURIComponent(done.empresa)}/${encodeURIComponent(done.tipo)}/${done.albaran}`
    )
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el abono')
  } finally {
    loading.value = false
  }
}

function addLinea() {
  lineas.value.push(lineaVacia())
}

function removeLinea(i: number) {
  lineas.value.splice(i, 1)
  if (!lineas.value.length) lineas.value.push(lineaVacia())
}

function nav(dir: 'primero' | 'anterior' | 'siguiente' | 'ultimo') {
  if (!navItems.value.length) return
  let i = indiceNav.value
  if (dir === 'primero') i = 0
  else if (dir === 'ultimo') i = navItems.value.length - 1
  else if (dir === 'anterior') i = Math.max(0, i - 1)
  else i = Math.min(navItems.value.length - 1, i + 1)
  const v = navItems.value[i]
  if (v) irA(v)
}

watch(
  () => [route.params.empresa, route.params.tipo, route.params.albaran, route.name] as const,
  (next, prev) => {
    if (!esEstaInstanciaActiva()) return
    if (prev && next[0] === prev[0] && next[1] === prev[1] && next[2] === prev[2] && next[3] === prev[3]) {
      return
    }
    void cargar()
  }
)

onMounted(() => {
  void cargar()
})
</script>

<template>
  <section class="ficha-venta" tabindex="-1" @keydown.enter="onKeyEnter">
    <VentaToolbar
      :modo-plantilla-consulta="esPlantillaConsulta"
      :modo-alta="esNuevo && !esPlantillaConsulta"
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar"
      :puede-eliminar="puedeEliminar"
      :puede-guardar="puedeGuardar"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="puedeFinalizar"
      :puede-abonar="puedeAbonar"
      :puede-buscar="esPlantillaConsulta || puedeBuscar"
      :puede-navegar="puedeNavegar"
      :modo-edicion="modoEdicion || esNuevo"
      :bloqueado="bloqueado || esTicketCerrado"
      :es-ticket-cerrado="esTicketCerrado"
      :hay-documento="!!ficha && !esNuevo"
      :loading="loading"
      :indice="indiceNav"
      :total="navItems.length"
      @nuevo="onNuevo"
      @modificar="onModificar"
      @borrar="onBorrar"
      @buscar="onBuscarListado"
      @guardar="onGuardar"
      @cancelar="onCancelar"
      @finalizar="onFinalizar"
      @abonar="abrirAbono"
      @primero="nav('primero')"
      @anterior="nav('anterior')"
      @siguiente="nav('siguiente')"
      @ultimo="nav('ultimo')"
      @imprimir="onImprimir"
    />

    <ol v-if="esNuevo && !esPlantillaConsulta" class="pasos-alta" aria-label="Pasos alta albaran">
      <li :class="{ activo: pasoAlta === 'tienda', hecho: pasoAlta !== 'tienda' }">1. Tienda</li>
      <li :class="{ activo: pasoAlta === 'cliente', hecho: pasoAlta === 'listo' }">2. Cliente</li>
      <li :class="{ activo: pasoAlta === 'listo', hecho: false }">3. Artículos</li>
    </ol>
    <ol
      v-else-if="modoEdicion && ficha && !esPlantillaConsulta"
      class="pasos-alta"
      aria-label="Pasos albaran"
    >
      <li class="hecho">1. Tienda</li>
      <li class="hecho">2. Cliente</li>
      <li class="activo">3. Articulos · Guardar</li>
    </ol>

    <p v-if="esPlantillaConsulta" class="banner-plantilla">
      <strong>Plantilla periódica</strong> — consulta o edición de líneas. Use
      <strong>Modificar</strong> / <strong>Guardar</strong> para cambios; <strong>Volver</strong> al grid.
    </p>
    <p v-else-if="esPlantillaAlta" class="banner-plantilla">
      Creando <strong>plantilla periódica</strong>: complete cliente y líneas, guarde el documento y
      <strong>Finalice</strong> como Presupuesto (recomendado) o Albarán. Después indicará la periodicidad.
      <button type="button" class="link-inline" @click="onCancelar">Volver a Albaranes periódicos</button>
    </p>

    <p v-if="bloqueado && ficha && !esTicketCerrado" class="banner-lock">
      Documento facturado / tipo Factura - solo consulta
    </p>
    <p v-else-if="esTicketCerrado && ficha" class="banner-lock">
      Ticket {{ ficha.factura }} — no editable.
      <template v-if="puedePasarAFactura"> Puede <strong>Finalizar</strong> para pasarlo a factura.</template>
      <template v-else> Indique Cliente, NIF y razon social para pasarlo a factura.</template>
    </p>
    <p v-else-if="esAbono && ficha" class="banner-lock">
      Albarán de abono de
      <strong>{{ ficha.origenDocumento?.etiqueta || `albarán ${ficha.albaranOrigenAbono}` }}</strong>.
      Pendiente de facturar como el resto.
    </p>
    <div v-if="esNuevo && pasoAlta === 'tienda'" class="paso-accion">
      <span>Confirme la <strong>tienda</strong> para comenzar.</span>
      <button type="button" class="btn-paso primary" :disabled="loading" @click="onIntroCabecera">
        Continuar
      </button>
    </div>
    <div v-else-if="esNuevo && pasoAlta === 'cliente'" class="paso-accion">
      <span>¿A quién se realiza la venta?</span>
      <div class="paso-botones">
        <button type="button" class="btn-paso" :disabled="loading" @click="abrirBuscarCliente">
          Buscar cliente
        </button>
        <button type="button" class="btn-paso primary" :disabled="loading" @click="usarVentaRapida">
          Venta rápida
        </button>
      </div>
    </div>
    <p v-else-if="esNuevo && pasoAlta === 'listo'" class="ok">
      Introduzca los artículos: código + <strong>Intro</strong> (o F4 / … para buscar). La venta se graba
      al pulsar <strong>Guardar y finalizar</strong>, que es cuando recibe el número de albarán.
    </p>
    <p v-else-if="modoEdicion && !esNuevo && !tieneLineas" class="ok">
      Introduzca articulos: codigo + <strong>Intro</strong> (o F4 / … para buscar). Luego <strong>Guardar</strong>.
    </p>
    <p v-else-if="modoEdicion && !esNuevo && tieneLineas && !esPlantillaConsulta" class="ok">
      Líneas listas. Puede <strong>Guardar</strong> el borrador o <strong>Guardar y finalizar</strong>.
    </p>
    <p v-else-if="!modoEdicion && albaranCompleto && !bloqueado && !esTicketCerrado && !esPlantillaConsulta" class="ok">
      Albaran listo. Puede <strong>Finalizar</strong> (tipificar) o <strong>Imprimir</strong>.
    </p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje && !esNuevo" class="ok">{{ mensaje }}</p>
    <p v-if="loading && !ficha">Cargando...</p>

    <template v-if="ficha">
      <VentaCabeceraForm
        ref="cabeceraForm"
        v-model="ficha"
        :readonly="soloLectura"
        :es-nuevo="esNuevo"
        :paso-alta="pasoAlta"
        :compacto="modoEdicion && !esPlantillaConsulta"
        :vendedor-nombre="vendedorNombre"
        :totales="totales"
        @buscar-vendedor="abrirBuscarVendedor"
        @vendedor-keydown="onVendedorKeydown"
        @vendedor-blur="resolverNombreVendedor(ficha?.vendedor || '')"
        @buscar-cliente="abrirBuscarCliente"
        @cliente-keydown="onClienteKeydown"
      />

      <div v-if="!esNuevo || pasoAlta === 'listo'" class="lineas-panel">
        <div class="lineas-head">
          <h3>Lineas</h3>
          <button v-if="puedeEditarLineas" type="button" class="btn-add" @click="addLinea">+ Linea</button>
        </div>
        <p v-if="!tieneCliente && !soloLectura" class="hint">
          Confirme el <strong>cliente</strong> (Intro vacío = venta rapida, F4 / … para buscar) antes de añadir artículos.
        </p>
        <div class="grid-wrap">
          <table class="tabla-lineas">
            <colgroup>
              <col class="col-art" />
              <col class="col-desc" />
              <col class="col-lote" />
              <col class="col-cant" />
              <col class="col-precio" />
              <col class="col-dto" />
              <col class="col-imp" />
              <col v-if="puedeEditarLineas" class="col-x" />
            </colgroup>
            <thead>
              <tr>
                <th>Articulo</th>
                <th>Descripcion</th>
                <th>Lote</th>
                <th class="num">Cant.</th>
                <th class="num">Precio</th>
                <th class="num">%Dto</th>
                <th class="num">Importe</th>
                <th v-if="puedeEditarLineas"></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, i) in lineas" :key="i" :class="{ comentario: esLineaComentario(l) }">
                <td>
                  <div class="celda-articulo">
                    <input
                      :ref="(el) => setArticuloInputRef(el, i)"
                      v-model="l.articulo"
                      maxlength="18"
                      :readonly="!puedeEditarLineas"
                      :placeholder="esLineaComentario(l) ? 'NO' : 'Codigo / buscar...'"
                      @input="onArticuloInput(i)"
                      @keydown="onArticuloKeydown($event, i)"
                      @dblclick="puedeEditarLineas && abrirBuscarArticulo(i)"
                    />
                    <button
                      v-if="puedeEditarLineas && !esLineaComentario(l)"
                      type="button"
                      class="btn-buscar-art"
                      title="Buscar articulo (Intro / F4)"
                      @click="abrirBuscarArticulo(i)"
                    >
                      ...
                    </button>
                  </div>
                </td>
                <td>
                  <input
                    :ref="(el) => setDescripcionInputRef(el, i)"
                    v-model="l.descripcion"
                    maxlength="80"
                    :readonly="!puedeEditarLineas"
                    :placeholder="esLineaComentario(l) ? 'Texto del comentario…' : ''"
                  />
                </td>
                <td>
                  <input
                    v-model="l.loteVenta"
                    maxlength="30"
                    :readonly="!puedeEditarLineas || esLineaComentario(l)"
                  />
                </td>
                <td class="num">
                  <DecimalInput
                    v-model="l.cantidad"
                    :empty-as-null="false"
                    :readonly="!puedeEditarLineas || esLineaComentario(l)"
                    @blur="redondearCampoLinea(l, 'cantidad')"
                  />
                </td>
                <td class="num">
                  <DecimalInput
                    v-model="l.precio"
                    :empty-as-null="false"
                    :readonly="!puedeEditarLineas || esLineaComentario(l)"
                    @blur="redondearCampoLinea(l, 'precio')"
                  />
                </td>
                <td class="num">
                  <DecimalInput
                    v-model="l.pjeDto"
                    :empty-as-null="false"
                    :readonly="!puedeEditarLineas || esLineaComentario(l)"
                    @blur="redondearCampoLinea(l, 'pjeDto')"
                  />
                </td>
                <td class="num importe">
                  {{ esLineaComentario(l) ? '—' : importeLinea(l).toFixed(2) }}
                </td>
                <td v-if="puedeEditarLineas">
                  <button type="button" class="btn-x" title="Quitar" @click="removeLinea(i)">x</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="hint">
          En codigo: <strong>Intro</strong> carga el articulo (si existe) o abre busqueda;
          <strong> F4</strong> / doble clic abre siempre la busqueda.
          Código <strong>NO</strong> = línea de comentario (sin importe).
          Tipo actual: <strong>{{ ficha.tipo }}</strong>
          <span v-if="ficha.impreso"> · Impreso</span>
        </p>
      </div>
    </template>

    <ConfirmDialog
      :open="confirmBorrar"
      :title="esPlantillaConsulta ? 'Borrar plantilla periódica' : 'Borrar venta'"
      :message="
        esPlantillaConsulta
          ? 'Eliminar la base periódica del grid y el documento en Ventas con sus líneas?'
          : 'Eliminar este albaran y sus lineas?'
      "
      confirm-label="Borrar"
      @confirm="confirmarBorrar"
      @cancel="confirmBorrar = false"
    />

    <ConfirmDialog
      :open="confirmCancelarAlta"
      title="Descartar la venta"
      message="La venta no se ha grabado. Se perderán el cliente y las líneas introducidas. ¿Desea descartarla?"
      confirm-label="Descartar"
      @confirm="confirmarCancelarVentaNueva"
      @cancel="confirmCancelarAlta = false"
    />

    <EntidadBuscarModal
      :open="buscarClienteOpen"
      entidad="clientes"
      titulo="Buscar cliente"
      @seleccionar="onClienteSeleccionado"
      @cerrar="buscarClienteOpen = false"
    />

    <EntidadBuscarModal
      :open="buscarArticuloOpen"
      entidad="articulos"
      titulo="Buscar articulo"
      :busqueda-inicial="articuloBusquedaInicial"
      @seleccionar="onArticuloSeleccionado"
      @cerrar="buscarArticuloOpen = false"
    />

    <EntidadBuscarModal
      :open="buscarVendedorOpen"
      entidad="trabajadores"
      titulo="Buscar vendedor"
      :busqueda-inicial="ficha?.vendedor || ''"
      @seleccionar="onVendedorSeleccionado"
      @cerrar="buscarVendedorOpen = false"
    />

    <Teleport to="body">
      <div v-if="finalizarOpen" class="overlay" @click.self="finalizarOpen = false">
        <div class="modal-tipo">
          <h3>
            {{
              esTicketCerrado
                ? 'Pasar ticket a factura'
                : esPlantillaAlta
                  ? 'Finalizar plantilla'
                  : 'Finalizar documento'
            }}
          </h3>
          <p v-if="esPlantillaAlta && !esTicketCerrado" class="ok">
            Elija Presupuesto (recomendado) o Albarán. A continuación registrará la periodicidad.
          </p>
          <p v-else-if="esTicketCerrado">
            Se convertira el ticket {{ ficha?.factura }} en factura (legacy TransformacionTicketaFactura).
          </p>
          <p v-else>Que tipo de documento es?</p>
          <p v-if="clienteContado && !esTicketCerrado" class="ok">
            Cliente de contado (forma de pago
            <strong>{{ formaPagoCliente || '—' }}</strong>
            con cobro de arqueo): no se puede cerrar como albaran.
          </p>
          <p v-if="!tieneDatosFactura && !esTicketCerrado" class="warn">
            Venta sin datos fiscales (p. ej. cliente ZZZZZZZZZ / sin NIF válido): solo Ticket o
            Presupuesto. Para Factura indique Cliente, NIF y razón social reales.
          </p>
          <div class="tipos">
            <label v-for="t in tiposFinalDisponibles" :key="t.codigo" class="tipo-opt">
              <input v-model="tipoFinal" type="radio" :value="t.codigo" />
              {{ t.label }} ({{ t.codigo }})
            </label>
          </div>
          <div v-if="mostrarSelectorFpago" class="fpago-final">
            <label>
              Forma de pago
              <select v-model="fpagoFinal">
                <option disabled value="">Seleccione…</option>
                <option v-for="f in formasPagoContado" :key="f.codigo" :value="f.codigo">
                  {{ f.codigo }} — {{ f.descripcion }}
                </option>
              </select>
            </label>
            <p v-if="!formasPagoContado.length" class="warn">
              No hay formas de pago configuradas con cobro de arqueo.
            </p>
          </div>
          <p v-if="tipoFinal === 'F'" class="warn">Factura: el documento quedara bloqueado.</p>
          <p v-if="tipoFinal === 'T'" class="ok">
            Al finalizar podrá imprimir el ticket o enviarlo por email.
          </p>
          <p v-else-if="!esTicketCerrado" class="ok">
            Al finalizar podrá imprimir el documento o enviarlo por email.
          </p>
          <footer>
            <button type="button" @click="finalizarOpen = false">Cancelar</button>
            <button
              type="button"
              class="primary"
              :disabled="mostrarSelectorFpago && !fpagoFinal"
              @click="confirmarFinalizar"
            >
              Aceptar
            </button>
          </footer>
        </div>
      </div>

      <div v-if="plantillaPeriodicaOpen" class="overlay" @click.self="cancelarPlantillaPeriodica">
        <div class="modal-tipo modal-plantilla">
          <h3>Crear plantilla periódica</h3>
          <p class="ok">
            Documento {{ ficha?.tipo }}/{{ ficha?.albaran }} — {{ ficha?.razonSocial || ficha?.cliente }}
          </p>
          <label>
            Periodicidad
            <select v-model.number="plantillaPeriodicaForm.presetPeriodicidad">
              <option v-for="p in PERIODICIDAD_PRESETS" :key="p.value" :value="p.value">
                {{ p.label }}
              </option>
            </select>
          </label>
          <label v-if="plantillaPeriodicaForm.presetPeriodicidad === 0">
            Días
            <input
              v-model.number="plantillaPeriodicaForm.periodicidadCustom"
              type="number"
              min="1"
            />
          </label>
          <label>
            Fecha base (última generación)
            <input v-model="plantillaPeriodicaForm.ultimaGeneracion" type="date" />
          </label>
          <label class="check-plantilla">
            <input v-model="plantillaPeriodicaForm.marcarReferenciaPeriodico" type="checkbox" />
            Marcar Referencia1 = PERIODICO si está vacía
          </label>
          <footer>
            <button type="button" @click="cancelarPlantillaPeriodica">Más tarde</button>
            <button
              type="button"
              class="primary"
              :disabled="plantillaPeriodicaSaving"
              @click="confirmarPlantillaPeriodica"
            >
              {{ plantillaPeriodicaSaving ? 'Guardando…' : 'Crear plantilla' }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <ElegirFormatoImpresionModal
      :open="formatoImpresionOpen"
      :etiqueta-a4="etiquetaA4Impresion"
      @elegir="onElegirFormatoImpresion"
      @cancelar="formatoImpresionOpen = false"
    />

    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Documento'"
      :plantilla="a4Prep?.plantilla ?? null"
      :datos="a4Prep?.datos ?? null"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="a4Imprimiendo"
      oculto
      @cerrar="a4Open = false"
      @imprimir="onImprimirA4Confirmado"
    />

    <VentaPostFinalizacionModal
      :open="postVentaOpen"
      :documento="postVentaDocumento"
      :email-inicial="ficha?.email"
      :procesando="postVentaEnviando"
      :error="postVentaError"
      @imprimir="imprimirTrasFinalizar"
      @email="enviarTrasFinalizar"
      @omitir="postVentaOpen = false"
    />

    <Teleport to="body">
      <div v-if="abonoOpen" class="overlay" @click.self="abonoOpen = false">
        <div class="modal-abono">
          <h3>Generar abono</h3>
          <p>
            Desmarque las líneas que <strong>no</strong> quiera abonar. Se creará un albarán nuevo con
            cantidades negativas, listo para pendientes de facturación.
          </p>
          <p v-if="abonosExistentes.length" class="hint abono-aviso">
            Este documento ya tiene
            {{ abonosExistentes.length === 1 ? 'un abono' : `${abonosExistentes.length} abonos` }}
            ({{ abonosExistentes.map((a) => a.albaran).join(', ') }}).
            Las líneas ya abonadas no se pueden volver a seleccionar.
          </p>
          <div class="abono-acciones">
            <button type="button" class="linkish" @click="seleccionarTodasAbono(true)">Todas</button>
            <button type="button" class="linkish" @click="seleccionarTodasAbono(false)">Ninguna</button>
          </div>
          <div class="abono-tabla-wrap">
            <table class="abono-tabla">
              <thead>
                <tr>
                  <th></th>
                  <th>Artículo</th>
                  <th>Descripción</th>
                  <th class="num">Cant.</th>
                  <th class="num">Importe</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="l in lineasConEstadoAbono()"
                  :key="l.nroLin"
                  :class="{ 'fila-abonada': lineaYaAbonada(l) }"
                >
                  <td>
                    <input
                      type="checkbox"
                      :checked="abonoNroLins.includes(Number(l.nroLin))"
                      :disabled="lineaYaAbonada(l)"
                      @change="
                        toggleAbonoLinea(
                          Number(l.nroLin),
                          ($event.target as HTMLInputElement).checked
                        )
                      "
                    />
                  </td>
                  <td>{{ l.articulo }}</td>
                  <td>
                    {{ l.descripcion }}
                    <span v-if="lineaYaAbonada(l)" class="badge-abonada">Ya abonada</span>
                  </td>
                  <td class="num">{{ l.cantidad }}</td>
                  <td class="num">{{ Number(l.importe).toFixed(2) }}</td>
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
.ficha-venta {
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
.paso-botones {
  display: flex;
  gap: 0.4rem;
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
.banner-lock {
  background: #fef3c7;
  border: 1px solid #f59e0b;
  color: #92400e;
  padding: 0.45rem 0.75rem;
  border-radius: 6px;
  margin: 0;
  font-size: 0.85rem;
}
.banner-plantilla {
  margin: 0.5rem 0;
  padding: 0.6rem 0.85rem;
  background: #e8f4fd;
  border: 1px solid #90caf9;
  border-radius: 6px;
  font-size: 0.85rem;
}
.link-inline {
  margin-left: 0.5rem;
  padding: 0;
  border: none;
  background: none;
  color: #1565c0;
  text-decoration: underline;
  cursor: pointer;
  font: inherit;
}
.modal-plantilla label {
  display: block;
  margin: 0.75rem 0 0.25rem;
}
.modal-plantilla select,
.modal-plantilla input[type='date'],
.modal-plantilla input[type='number'] {
  width: 100%;
  max-width: 20rem;
}
.check-plantilla {
  display: flex !important;
  align-items: center;
  gap: 0.5rem;
  margin-top: 1rem !important;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.ok {
  color: #166534;
  margin: 0;
}
.lineas-panel {
  max-width: 960px;
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  padding: 0.4rem;
}
.lineas-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.4rem;
}
.lineas-head h3 {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
}
.btn-add {
  border: 1px solid #64748b;
  background: #fff;
  border-radius: 6px;
  padding: 0.25rem 0.6rem;
  cursor: pointer;
  font-size: 0.78rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
.tabla-lineas {
  width: 100%;
  table-layout: fixed;
  border-collapse: collapse;
  font-size: 0.82rem;
}
.tabla-lineas .col-art {
  width: 9.5rem;
}
.tabla-lineas .col-desc {
  width: auto;
}
.tabla-lineas .col-lote {
  width: 5.5rem;
}
.tabla-lineas .col-cant {
  width: 4.2rem;
}
.tabla-lineas .col-precio {
  width: 5rem;
}
.tabla-lineas .col-dto {
  width: 3.8rem;
}
.tabla-lineas .col-imp {
  width: 5rem;
}
.tabla-lineas .col-x {
  width: 2rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.2rem 0.3rem;
}
th {
  background: #f1f5f9;
  text-align: left;
  font-weight: 600;
}
th.num {
  text-align: right;
}
td input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid transparent;
  background: transparent;
  padding: 0.2rem;
  font: inherit;
}
td input:focus {
  border-color: #94a3b8;
  background: #fff;
  outline: none;
}
td input:read-only {
  cursor: default;
}
tr.comentario td {
  background: #f8fafc;
}
tr.comentario td input {
  font-style: italic;
  color: #475569;
}
.num {
  text-align: right;
}
.num input {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.celda-articulo {
  display: flex;
  align-items: center;
  gap: 0.15rem;
  min-width: 0;
}
.celda-articulo input {
  flex: 1;
  min-width: 0;
}
.btn-buscar-art {
  border: 1px solid #94a3b8;
  background: #f1f5f9;
  border-radius: 4px;
  padding: 0.1rem 0.35rem;
  cursor: pointer;
  font-size: 0.75rem;
  line-height: 1.2;
}
.importe {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.btn-x {
  border: none;
  background: transparent;
  color: #b91c1c;
  cursor: pointer;
  font-size: 1rem;
}
.hint {
  color: #64748b;
  font-size: 0.8rem;
  margin: 0.35rem 0 0;
}
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
}
.modal-tipo {
  background: #fff;
  border-radius: 10px;
  padding: 1.25rem;
  min-width: 18rem;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
}
.modal-abono {
  background: #fff;
  border-radius: 10px;
  padding: 1.25rem;
  width: min(40rem, 94vw);
  max-height: 90vh;
  overflow: auto;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
}
.modal-abono h3,
.modal-tipo h3 {
  margin: 0 0 0.5rem;
}
.abono-acciones {
  display: flex;
  gap: 0.75rem;
  margin: 0.5rem 0;
}
.abono-aviso {
  color: #b45309;
  margin: 0.35rem 0 0.5rem;
}
.fila-abonada {
  opacity: 0.65;
  background: #f8fafc;
}
.badge-abonada {
  display: inline-block;
  margin-left: 0.35rem;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  font-size: 0.72rem;
  background: #e2e8f0;
  color: #475569;
}
.linkish {
  border: none;
  background: transparent;
  color: #1d4ed8;
  cursor: pointer;
  padding: 0;
  font-size: 0.85rem;
  text-decoration: underline;
}
.abono-tabla-wrap {
  max-height: 16rem;
  overflow: auto;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  margin-bottom: 0.75rem;
}
.abono-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
.abono-tabla th,
.abono-tabla td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.4rem;
}
.abono-tabla th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
}
.abono-obs {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
  margin-bottom: 0.5rem;
}
.abono-obs input {
  padding: 0.4rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font: inherit;
}
.tipos {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin: 0.75rem 0;
}
.tipo-opt {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}
.fpago-final {
  margin: 0.5rem 0 0.75rem;
}
.fpago-final label {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.9rem;
}
.fpago-final select {
  padding: 0.4rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 0.9rem;
}
.warn {
  color: #92400e;
  font-size: 0.85rem;
}
.modal-tipo footer,
.modal-abono footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
.modal-tipo button,
.modal-abono button:not(.linkish) {
  padding: 0.35rem 0.85rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  cursor: pointer;
}
.modal-tipo button.primary,
.modal-abono button.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

@media print {
  :global(.app-layout),
  :global(aside),
  :global(nav) {
    display: none !important;
  }

  .ficha-venta :deep(.toolbar),
  .banner-lock,
  .ok,
  .error,
  .hint,
  .btn-add,
  .btn-x,
  .btn-buscar-art,
  .overlay {
    display: none !important;
  }

  .ficha-venta {
    padding: 0;
  }

  .lineas-panel,
  .ficha-venta :deep(.tab-form) {
    border: none;
    background: #fff;
  }
}
</style>
