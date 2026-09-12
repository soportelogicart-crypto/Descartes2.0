<script setup lang="ts">
import { computed, nextTick, onActivated, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { routeNavigationFrom } from '@/router'
import { api } from '@/api/client'
import {
  actualizarPedidoProveedor,
  crearPedidoProveedor,
  obtenerPedidoProveedor,
  recibirPedidoProveedor,
} from '@/api/compras'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import type {
  PedidoProveedorDetalle,
  PedidoProveedorLinea,
  PedidoProveedorPayload,
} from '@/types/compras'
import { extractApiError, isApiNotFound } from '@/composables/extractApiError'
import {
  imprimirA4CompraPreparado,
  prepararImpresionPedidoProveedor,
  type PrepImpresionA4,
} from '@/composables/useImpresionCompraDocumento'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import PedidoRecepcionModal from '@/components/compras/PedidoRecepcionModal.vue'
import ArticuloAltaModal from '@/components/articulos/ArticuloAltaModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const puesto = usePuestoContextoStore()
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => onImprimirA4Confirmado() })

const pathInstancia = route.fullPath

/** KeepAlive: no reiniciar borrador al consultar otra pantalla en otra pestaña. */
function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const puedeCrear = computed(() => puede('compras', 'crear'))
const puedeEditar = computed(() => puede('compras', 'editar'))
const puedeCrearArticulo = computed(() => puede('articulos', 'crear'))

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ficha = ref<PedidoProveedorDetalle | null>(null)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const buscarProveedorOpen = ref(false)
const buscarArticuloOpen = ref(false)
const recepcionOpen = ref(false)
const recibiendo = ref(false)
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const a4Imprimiendo = ref(false)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
const confirmAltaArticulo = ref(false)
const altaArticuloOpen = ref(false)
const altaArticuloQuery = ref('')
const altaArticuloLineaIdx = ref(-1)
const tiendas = ref<{ value: string; label: string }[]>([])
const almacenes = ref<{ value: number; label: string }[]>([])
/** Alta guiada: tienda → proveedor → líneas. El nº se asigna al Guardar. */
const pasoAlta = ref<'tienda' | 'proveedor' | 'listo'>('listo')
const tiendaSelectRef = ref<HTMLSelectElement | null>(null)
const pedidoInputRef = ref<HTMLInputElement | null>(null)
const proveedorInputRef = ref<HTMLInputElement | null>(null)
const lineasPanelRef = ref<HTMLElement | null>(null)

type FormLinea = {
  numLin?: number
  articulo: string
  descripcion: string
  cantidadPed: number
  cantidadSer: number
  precioPed: number
  pjeDto: number
  almacen: number | null
}

const form = ref({
  empresa: '',
  fechaPedido: new Date().toISOString().slice(0, 10),
  fechaMaxRecepcion: '',
  proveedor: '',
  razonSocial: '',
  vendedor: '',
  almacen: null as number | null,
  observaciones: '',
  observInternas: '',
  lineas: [] as FormLinea[],
})

const titulo = computed(() => {
  if (esNuevo.value) return 'Nuevo pedido a proveedor'
  if (!ficha.value) return 'Pedido a proveedor'
  return `Pedido ${ficha.value.empresa}-${ficha.value.pedido}`
})

const bloqueado = computed(() => {
  if (esNuevo.value) return false
  if (!ficha.value) return true
  return ficha.value.editable === false || ficha.value.situacionLabel === 'servido'
})

const soloLecturaMotivo = computed(() => {
  if (!ficha.value || esNuevo.value) return null
  if (ficha.value.situacionLabel === 'servido') return 'Pedido completamente servido'
  if (ficha.value.editable === false) return 'Documento no editable'
  return null
})

const mostrarProveedor = computed(() => !esNuevo.value || pasoAlta.value !== 'tienda')
const mostrarExtras = computed(() => !esNuevo.value || pasoAlta.value === 'listo')
const cabeceraCompacta = computed(() => !esNuevo.value && !modoEdicion.value)

const cabeceraEditables = computed(() => (esNuevo.value || modoEdicion.value) && !bloqueado.value)
const camposEditables = cabeceraEditables

const lineasEditables = computed(() => {
  if (esNuevo.value) return pasoAlta.value === 'listo'
  return cabeceraEditables.value
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

const importeBorrador = computed(() => {
  let sum = 0
  for (const l of form.value.lineas) {
    const art = String(l.articulo ?? '').trim()
    if (!art || art.toUpperCase() === 'NO') continue
    const cant = Number(l.cantidadPed) || 0
    const precio = Number(l.precioPed) || 0
    const dto = Number(l.pjeDto) || 0
    sum += cant * precio * (1 - dto / 100)
  }
  return sum
})

const importeMostrado = computed(() =>
  ficha.value && !modoEdicion.value ? Number(ficha.value.importe ?? 0) : importeBorrador.value
)

const lineasConArticulo = computed(
  () => form.value.lineas.filter((l) => String(l.articulo ?? '').trim()).length
)

const situacionBadge = computed(() => {
  const label = ficha.value?.situacionLabel || 'pendiente'
  if (label === 'servido') return { text: 'Servido', cls: 'sit-servido' }
  if (label === 'parcial') return { text: 'Parcial', cls: 'sit-parcial' }
  return { text: 'Pendiente', cls: 'sit-pendiente' }
})

const puedeRecibir = computed(
  () =>
    puedeEditar.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    ficha.value.situacionLabel !== 'servido'
)

const puedeImprimir = computed(() => !!ficha.value && !esNuevo.value)

function lineaVacia(): FormLinea {
  return {
    articulo: '',
    descripcion: '',
    cantidadPed: 0,
    cantidadSer: 0,
    precioPed: 0,
    pjeDto: 0,
    almacen: form.value.almacen,
  }
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function fmtNum(n: number | null | undefined, dec = 2) {
  return Number(n ?? 0).toFixed(dec)
}

function esRutaNuevo(): boolean {
  if (route.name === 'compras-pedido-nuevo') return true
  return (route.path || '').replace(/\/+$/, '').endsWith('/compras/pedidos/nuevo')
}

/** Conservar borrador solo al volver de otra pestaña (p. ej. consultar artículo). */
function debeConservarBorradorAlta(rutaAnterior: string | undefined): boolean {
  if (!esNuevo.value || !rutaAnterior) return false
  const prev = (rutaAnterior.split('?')[0] || '').replace(/\/+$/, '') || '/'
  return !prev.startsWith('/compras/pedidos')
}

function aplicarFicha(data: PedidoProveedorDetalle) {
  ficha.value = data
  form.value = {
    empresa: data.empresa,
    fechaPedido: fmtFecha(data.fechaPedido) || new Date().toISOString().slice(0, 10),
    fechaMaxRecepcion: fmtFecha(data.fechaMaxRecepcion),
    proveedor: data.proveedor || '',
    razonSocial: data.razonSocial || '',
    vendedor: data.vendedor || '',
    almacen: data.almacen ?? null,
    observaciones: data.observaciones || '',
    observInternas: data.observInternas || '',
    lineas: (data.lineas?.length ? data.lineas : [lineaVacia()]).map((l) => ({
      numLin: l.numLin,
      articulo: l.articulo || '',
      descripcion: l.descripcion || '',
      cantidadPed: Number(l.cantidadPed ?? 0),
      cantidadSer: Number(l.cantidadSer ?? 0),
      precioPed: Number(l.precioPed ?? 0),
      pjeDto: Number(l.pjeDto ?? 0),
      almacen: l.almacen ?? data.almacen ?? null,
    })),
  }
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

  if (modoEdicion.value) {
    error.value = null
    return
  }

  const empresa = String(route.params.empresa ?? '').trim()
  const pedido = Number(route.params.pedido ?? 0)
  if (!empresa || !Number.isFinite(pedido) || pedido <= 0) {
    error.value = 'Pedido no válido'
    ficha.value = null
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarFicha(await obtenerPedidoProveedor(empresa, pedido))
    modoEdicion.value = false
    esNuevo.value = false
    pasoAlta.value = 'listo'
  } catch (e: unknown) {
    ficha.value = null
    error.value = extractApiError(e, 'No se pudo cargar el pedido a proveedor')
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
  form.value = {
    empresa,
    fechaPedido: new Date().toISOString().slice(0, 10),
    fechaMaxRecepcion: '',
    proveedor: '',
    razonSocial: '',
    vendedor: '',
    almacen: null,
    observaciones: '',
    observInternas: '',
    lineas: [lineaVacia()],
  }
  mensaje.value = empresa.trim()
    ? 'Confirme la tienda para comenzar'
    : 'Nuevo pedido: elija tienda y pulse Continuar'
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
    : 'Nuevo pedido: elija tienda y pulse Continuar'
  void nextTick(() => tiendaSelectRef.value?.focus())
}

function onKeyEnter(e: KeyboardEvent) {
  if (!esNuevo.value || pasoAlta.value === 'listo') return
  if (
    buscarProveedorOpen.value ||
    buscarArticuloOpen.value ||
    confirmAltaArticulo.value ||
    altaArticuloOpen.value ||
    recepcionOpen.value
  ) {
    return
  }
  const t = e.target
  if (t instanceof HTMLElement) {
    const tag = t.tagName
    if (tag === 'TEXTAREA' || (tag === 'INPUT' && t.closest('.lineas-panel, .panel.lineas'))) return
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
      error.value = 'No tiene permiso para crear pedidos a proveedor'
      return
    }
    loading.value = true
    try {
      await aplicarAlmacenTienda(form.value.empresa.trim())
      pasoAlta.value = 'proveedor'
      mensaje.value = null
      await focusProveedorCabecera()
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo preparar el pedido')
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

function onNuevo() {
  if (!puedeCrear.value) return
  if (esRutaNuevo()) {
    void onIntroCabecera()
    return
  }
  router.push({ name: 'compras-pedido-nuevo' })
}

function volverListado() {
  modoEdicion.value = false
  void router.push({ name: 'compras-pedidos' })
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

function buildPayload(): PedidoProveedorPayload {
  const lineas: PedidoProveedorLinea[] = form.value.lineas
    .filter((l) => l.articulo.trim() !== '')
    .map((l) => ({
      numLin: l.numLin,
      articulo: l.articulo.trim(),
      descripcion: l.descripcion.trim() || null,
      cantidadPed: Number(l.cantidadPed) || 0,
      cantidadSer: Number(l.cantidadSer) || 0,
      precioPed: Number(l.precioPed) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      almacen: l.almacen,
    }))
  return {
    empresa: form.value.empresa.trim(),
    pedido: esNuevo.value ? undefined : ficha.value?.pedido,
    fechaPedido: form.value.fechaPedido || null,
    fechaMaxRecepcion: form.value.fechaMaxRecepcion || null,
    proveedor: form.value.proveedor.trim() || null,
    vendedor: form.value.vendedor.trim() || null,
    observaciones: form.value.observaciones.trim() || null,
    observInternas: form.value.observInternas.trim() || null,
    almacen: form.value.almacen,
    lineas,
  }
}

async function onGuardar() {
  if (esNuevo.value && pasoAlta.value !== 'listo') return
  if (!camposEditables.value) return
  const payload = buildPayload()
  if (!payload.empresa) {
    error.value = 'Seleccione tienda'
    return
  }
  if (!payload.proveedor) {
    error.value = 'Indique proveedor'
    return
  }
  if (!payload.lineas.length) {
    error.value = 'Añada al menos una línea con artículo'
    return
  }

  saving.value = true
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    if (esNuevo.value) {
      const created = await crearPedidoProveedor(payload)
      mensaje.value = 'Pedido creado'
      await router.replace({
        name: 'compras-pedido-detalle',
        params: { empresa: created.empresa, pedido: String(created.pedido) },
      })
      if (esEstaInstanciaActiva()) {
        aplicarFicha(created)
        modoEdicion.value = false
        esNuevo.value = false
      }
    } else if (ficha.value) {
      const updated = await actualizarPedidoProveedor(
        ficha.value.empresa,
        ficha.value.pedido,
        payload
      )
      aplicarFicha(updated)
      modoEdicion.value = false
      mensaje.value = 'Pedido guardado'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el pedido')
  } finally {
    saving.value = false
    loading.value = false
  }
}

function abrirBuscarProveedor() {
  if (!proveedorBusquedaHabilitada.value) return
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
    await aplicarProveedorEnForm({
      codigo: String(data.codigo ?? cod).trim(),
      etiqueta: String(data.nombre ?? data.razonSocial ?? ''),
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Proveedor no encontrado. Selecciónelo en la búsqueda.')
    abrirBuscarProveedor()
  } finally {
    loading.value = false
  }
}

async function aplicarProveedorEnForm(r: { codigo: string; etiqueta: string }) {
  form.value.proveedor = r.codigo
  form.value.razonSocial = r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta
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
    await aplicarProveedorEnForm({
      codigo: String(data.codigo ?? r.codigo).trim(),
      etiqueta: String(data.nombre ?? data.razonSocial ?? r.etiqueta),
    })
  } catch {
    await aplicarProveedorEnForm({
      codigo: r.codigo,
      etiqueta: r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta,
    })
  } finally {
    loading.value = false
  }
}

function focusLineaArticulo(idx: number) {
  void nextTick(() => {
    requestAnimationFrame(() => {
      const root = lineasPanelRef.value
      const row = root?.querySelector(`tr[data-linea-idx="${idx}"]`)
      const el = row?.querySelector<HTMLInputElement>('.col-art input')
      if (el && lineasEditables.value) {
        el.focus()
        el.select()
      }
    })
  })
}

function abrirBuscarArticulo(idx: number) {
  if (!lineasEditables.value) return
  lineaArticuloIdx.value = idx
  articuloBusquedaInicial.value = form.value.lineas[idx]?.articulo ?? ''
  buscarArticuloOpen.value = true
}

async function onArticuloSeleccionado(r: EntidadBuscarResultado) {
  const idx = lineaArticuloIdx.value
  const linea = form.value.lineas[idx]
  if (!linea) return
  linea.articulo = r.codigo
  const parts = r.etiqueta.split(/\s*[-–]\s*/)
  linea.descripcion = parts.length > 1 ? parts.slice(1).join(' - ').trim() : r.etiqueta
  buscarArticuloOpen.value = false
  if (idx === form.value.lineas.length - 1) {
    form.value.lineas.push(lineaVacia())
  }
  await nextTick()
}

async function aplicarArticuloResuelto(idx: number, art: Awaited<ReturnType<typeof resolverArticulo>>) {
  const linea = form.value.lineas[idx]
  if (!linea) return
  linea.articulo = art.codigo
  linea.descripcion = String(art.descripcion ?? '').trim()
  const precio = Number(art.precioUltimo ?? art.precioMedio ?? art.precioVen1 ?? 0)
  if (precio > 0 && !linea.precioPed) linea.precioPed = precio
  const uds = Number(art.unidadesPaquete)
  if (uds > 0 && (!linea.cantidadPed || linea.cantidadPed === 1)) {
    linea.cantidadPed = uds
  } else if (!linea.cantidadPed) {
    linea.cantidadPed = 1
  }
  if (idx === form.value.lineas.length - 1) {
    form.value.lineas.push(lineaVacia())
  }
  await nextTick()
  const next = document.querySelector<HTMLInputElement>(
    `tr:nth-child(${idx + 2}) .col-art input`
  )
  next?.focus()
  next?.select()
}

async function onArticuloKeydown(e: KeyboardEvent, idx: number) {
  if (!lineasEditables.value) return
  if (e.key === 'F4') {
    e.preventDefault()
    barcodeWatcher.cancel()
    abrirBuscarArticulo(idx)
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  barcodeWatcher.cancel()
  await resolverArticuloEnLinea(idx, String(form.value.lineas[idx]?.articulo ?? ''))
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
    await aplicarArticuloResuelto(idx, art)
  } catch (err: unknown) {
    if (isApiNotFound(err) && puedeCrearArticulo.value) {
      pedirAltaArticulo(idx, codigo)
      return
    }
    error.value = extractApiError(err, 'Artículo no encontrado')
    abrirBuscarArticulo(idx)
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
    await aplicarArticuloResuelto(idx, art)
    mensaje.value = `Artículo ${codigo} creado y aplicado a la línea`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Artículo creado pero no se pudo cargar en la línea')
  }
}

function onArticuloInput(idx: number) {
  if (!lineasEditables.value) return
  barcodeLineaIdx = idx
  barcodeWatcher.onInput(String(form.value.lineas[idx]?.articulo ?? ''))
}

function quitarLinea(idx: number) {
  if (!lineasEditables.value) return
  const linea = form.value.lineas[idx]
  if (linea && Number(linea.cantidadSer) > 0) {
    error.value = `No se puede quitar la línea ${linea.articulo}: ya tiene cantidad servida`
    return
  }
  if (form.value.lineas.length <= 1) {
    form.value.lineas = [lineaVacia()]
    return
  }
  form.value.lineas.splice(idx, 1)
}

function abrirRecepcion() {
  if (!puedeRecibir.value) return
  error.value = null
  mensaje.value = null
  recepcionOpen.value = true
}

async function onRecepcionConfirmar(payload: {
  fechaAlbaran: string
  suAlbaran: string
  almacen: number | null
  lineas: { numLin: number; cantidad: number }[]
}) {
  if (!ficha.value) return
  recibiendo.value = true
  error.value = null
  mensaje.value = null
  try {
    const result = await recibirPedidoProveedor(ficha.value.empresa, ficha.value.pedido, {
      fechaAlbaran: payload.fechaAlbaran || null,
      suAlbaran: payload.suAlbaran || null,
      almacen: payload.almacen,
      lineas: payload.lineas,
    })
    recepcionOpen.value = false
    aplicarFicha(result.pedido)
    mensaje.value = `Albarán ${result.albaran.empresa}-${result.albaran.albaran} creado`
    await router.push({
      name: 'compras-albaran-detalle',
      params: {
        empresa: result.albaran.empresa,
        albaran: String(result.albaran.albaran),
      },
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo recibir el pedido')
  } finally {
    recibiendo.value = false
  }
}

async function onImprimir() {
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  loading.value = true
  try {
    await mostrarPreviewA4(
      await prepararImpresionPedidoProveedor(ficha.value, {
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
    const msg = extractApiError(e, 'No se pudo imprimir el pedido a proveedor')
    error.value = msg
    preview.notificarError(msg)
  } finally {
    a4Imprimiendo.value = false
  }
}

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarAlmacenes()])
  if (!esEstaInstanciaActiva()) return
  if (esNuevo.value && !form.value.empresa.trim() && tiendas.value.length) {
    form.value.empresa = puesto.empresaCodigo || tiendas.value[0]?.value || ''
  }
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

</script>

<template>
  <section
    class="compra-detalle pedido-proveedor"
    tabindex="-1"
    :aria-label="titulo"
    @keydown.enter="onKeyEnter"
  >
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar && !!ficha && !bloqueado"
      :puede-eliminar="false"
      :puede-guardar="esNuevo ? pasoAlta === 'listo' : camposEditables"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="false"
      :mostrar-finalizar="false"
      :puede-abonar="false"
      :puede-buscar="true"
      buscar-label="Listado"
      buscar-title="Volver al listado de pedidos"
      :puede-navegar="false"
      :modo-edicion="modoEdicion || esNuevo"
      :bloqueado="bloqueado"
      :hay-documento="!!ficha || esNuevo"
      :loading="loading || saving || recibiendo"
      :indice="-1"
      :total="0"
      @nuevo="onNuevo"
      @modificar="onModificar"
      @buscar="volverListado"
      @guardar="onGuardar"
      @cancelar="onCancelar"
      @imprimir="onImprimir"
    />

    <ol v-if="esNuevo" class="pasos-alta" aria-label="Pasos alta pedido">
      <li :class="{ activo: pasoAlta === 'tienda', hecho: pasoAlta !== 'tienda' }">1. Tienda</li>
      <li :class="{ activo: pasoAlta === 'proveedor', hecho: pasoAlta === 'listo' }">2. Proveedor</li>
      <li :class="{ activo: pasoAlta === 'listo' }">3. Líneas</li>
    </ol>
    <ol v-else-if="modoEdicion && ficha" class="pasos-alta" aria-label="Pasos pedido">
      <li class="hecho">1. Tienda</li>
      <li class="hecho">2. Proveedor</li>
      <li class="activo">3. Líneas · Guardar</li>
    </ol>

    <p v-if="soloLecturaMotivo" class="banner-doc">Solo lectura — {{ soloLecturaMotivo }}</p>
    <div v-if="esNuevo && pasoAlta === 'tienda'" class="paso-accion">
      <span>Confirme la <strong>tienda</strong> para comenzar.</span>
      <button type="button" class="btn-paso primary" :disabled="loading" @click="onIntroCabecera">
        Continuar
      </button>
    </div>
    <div v-else-if="esNuevo && pasoAlta === 'proveedor'" class="paso-accion">
      <span>¿A qué proveedor se hace el pedido?</span>
      <button type="button" class="btn-paso primary" :disabled="loading" @click="abrirBuscarProveedor">
        Buscar proveedor
      </button>
    </div>
    <p v-else-if="esNuevo && pasoAlta === 'listo'" class="ok">
      Introduzca los artículos: código + <strong>Intro</strong> (o F4 / … para buscar). El número de
      pedido se asigna al pulsar <strong>Guardar</strong>.
    </p>
    <p v-else-if="modoEdicion && !esNuevo" class="ok">Editando. <strong>Guardar</strong> actualiza cabecera y líneas.</p>
    <p v-else-if="!esNuevo && !modoEdicion && ficha && puedeRecibir" class="ok">
      Pedido {{ situacionBadge.text.toLowerCase() }}. Puede <strong>Recibir</strong> mercancía o
      <strong>Imprimir</strong>.
    </p>
    <div v-if="puedeRecibir" class="paso-accion recibir-bar">
      <span>Reciba mercancía y genere el albarán de compra.</span>
      <button
        type="button"
        class="btn-recibir"
        :disabled="loading || saving || recibiendo"
        title="Recibir mercancía del proveedor (albarán de compra)"
        @click="abrirRecepcion"
      >
        Recibir
      </button>
    </div>

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
                  <option value="">—</option>
                  <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
                <input v-else :value="form.empresa" readonly />
              </label>
              <label class="field">
                <span class="label">N. pedido</span>
                <input
                  ref="pedidoInputRef"
                  :value="esNuevo ? '—' : ficha?.pedido"
                  readonly
                  title="Número de pedido (se asigna al Guardar)"
                />
              </label>
              <label class="field">
                <span class="label">Fecha</span>
                <input v-model="form.fechaPedido" type="date" :readonly="!camposEditables" />
              </label>
              <label class="field">
                <span class="label">F. máx. recep.</span>
                <input
                  v-model="form.fechaMaxRecepcion"
                  type="date"
                  :readonly="!camposEditables"
                  title="Fecha máxima de recepción"
                />
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
                <span class="label">Situación</span>
                <input
                  :value="esNuevo ? '—' : situacionBadge.text"
                  readonly
                  :class="{ 'sit-readonly': !esNuevo }"
                />
              </label>
            </div>
          </section>
          <div class="totales-box">
            <div><span>Importe</span><strong>{{ fmtNum(importeMostrado) }}</strong></div>
            <div class="imp"><span>Líneas</span><strong>{{ lineasConArticulo }}</strong></div>
          </div>
        </div>

        <div v-if="mostrarProveedor" class="section-row paired single">
          <section class="section">
            <h3>Proveedor</h3>
            <div class="fields cols-3">
              <label class="field">
                <span class="label">Código</span>
                <div class="combo-input" :class="{ locked: proveedorBloqueado }">
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
                <span class="label">Vendedor</span>
                <input
                  v-model="form.vendedor"
                  maxlength="4"
                  :readonly="!camposEditables"
                  title="Vendedor del proveedor"
                />
              </label>
              <label class="field span-2">
                <span class="label">Observaciones</span>
                <input v-model="form.observaciones" :readonly="!camposEditables" />
              </label>
            </div>
          </section>
        </div>

        <details v-if="mostrarExtras" class="detalles-adicionales" :open="!cabeceraCompacta">
          <summary>Más datos del pedido</summary>
          <section class="section">
            <h3>Interno</h3>
            <div class="fields cols-3">
              <label class="field span-2">
                <span class="label">Observaciones internas</span>
                <input v-model="form.observInternas" :readonly="!camposEditables" />
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
                <th class="col-art">Artículo</th>
                <th class="col-desc">Descripción</th>
                <th class="num col-q">Pedida</th>
                <th class="num col-q">Servida</th>
                <th class="num col-p">Precio</th>
                <th class="num col-d">% Dto</th>
                <th v-if="lineasEditables" class="col-act" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="(l, idx) in form.lineas"
                :key="l.numLin ?? `n-${idx}`"
                :data-linea-idx="idx"
              >
                <td class="col-art">
                  <div v-if="lineasEditables && l.cantidadSer <= 0" class="con-lupa">
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
                <td class="num col-q">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.cantidadPed"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.cantidadPed) }}</span>
                </td>
                <td class="num col-q">
                  <span :class="{ 'ser-parcial': l.cantidadSer > 0 && l.cantidadSer < l.cantidadPed }">
                    {{ fmtNum(l.cantidadSer) }}
                  </span>
                </td>
                <td class="num col-p">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.precioPed"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.precioPed) }}</span>
                </td>
                <td class="num col-d">
                  <DecimalInput
                    v-if="lineasEditables"
                    v-model="l.pjeDto"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.pjeDto) }}</span>
                </td>
                <td v-if="lineasEditables" class="col-act">
                  <button
                    type="button"
                    class="btn-del"
                    title="Quitar"
                    :disabled="l.cantidadSer > 0"
                    @click="quitarLinea(idx)"
                  >
                    ×
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="lineasEditables" class="lineas-hint">
          Código + Intro o F4 para buscar. La cantidad servida solo cambia al Recibir.
        </p>
      </div>
      </div>
    </template>

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
    <PedidoRecepcionModal
      :open="recepcionOpen"
      :pedido="ficha"
      :loading="recibiendo"
      @confirmar="onRecepcionConfirmar"
      @cancelar="recepcionOpen = false"
    />
    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Pedido a proveedor'"
      :plantilla="a4Prep?.plantilla ?? null"
      :datos="a4Prep?.datos ?? null"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="a4Imprimiendo"
      oculto
      @cerrar="a4Open = false"
      @imprimir="onImprimirA4Confirmado"
    />
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
.paso-accion.recibir-bar {
  border-color: #fcd34d;
  background: #fffbeb;
  color: #78350f;
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
.btn-recibir {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #b45309;
  background: #f59e0b;
  color: #1c1917;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
}
.btn-recibir:disabled {
  opacity: 0.6;
  cursor: not-allowed;
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
.ser-parcial {
  color: #b45309;
  font-weight: 600;
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
.sit-readonly {
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
.lineas-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.45rem;
  flex-wrap: wrap;
}
.lineas-head h3 {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
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
.btn-add {
  padding: 0.25rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #f8fafc;
  cursor: pointer;
  font-size: 0.8rem;
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
  overflow-x: hidden;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: min(50vh, 28rem);
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.35rem;
  text-align: left;
  vertical-align: middle;
}
th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-art {
  width: 9rem;
}
.col-q,
.col-p,
.col-d {
  width: 5.5rem;
}
.col-act {
  width: 2rem;
}
.btn-del {
  border: none;
  background: transparent;
  color: #b91c1c;
  font-size: 1.1rem;
  cursor: pointer;
  line-height: 1;
}
.btn-del:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

@media (max-width: 820px) {
  .pedido-proveedor .ficha-compra-body {
    width: 100%;
  }
  .doc-row {
    flex-direction: column;
  }
}
</style>
