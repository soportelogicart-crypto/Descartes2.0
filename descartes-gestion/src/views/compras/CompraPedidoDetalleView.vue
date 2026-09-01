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
  reservarPedidoProveedor,
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
const pedidoReservado = ref<number | null>(null)
const reservando = ref(false)
/** Alta guiada: tienda → reservar nº → proveedor → cabecera + líneas. */
const pasoAlta = ref<'tienda' | 'proveedor' | 'listo'>('listo')
const tiendaSelectRef = ref<HTMLSelectElement | null>(null)
const pedidoInputRef = ref<HTMLInputElement | null>(null)
const proveedorInputRef = ref<HTMLInputElement | null>(null)

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

const camposEditables = computed(() => modoEdicion.value && !bloqueado.value)

const proveedorBusquedaHabilitada = computed(() => camposEditables.value)

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

async function intentarReservarNumeroAutomatico() {
  if (!esNuevo.value || pedidoReservado.value || reservando.value) return
  const empresa = form.value.empresa.trim()
  if (!empresa) {
    mensaje.value = 'Elija la tienda para asignar el número de pedido'
    pasoAlta.value = 'tienda'
    return
  }
  await reservarNumero()
}

async function iniciarNuevo() {
  esNuevo.value = true
  modoEdicion.value = true
  ficha.value = null
  pedidoReservado.value = null
  pasoAlta.value = 'tienda'
  error.value = null
  mensaje.value = null
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
  await intentarReservarNumeroAutomatico()
  if (!pedidoReservado.value && !form.value.empresa.trim()) {
    mensaje.value = 'Nuevo pedido: elija tienda (se asignará el número automáticamente)'
    await nextTick(() => tiendaSelectRef.value?.focus())
  }
}

async function onEmpresaNuevoChange() {
  if (!esNuevo.value) return
  pedidoReservado.value = null
  pasoAlta.value = 'tienda'
  await intentarReservarNumeroAutomatico()
}

async function reservarNumero() {
  const empresa = form.value.empresa.trim()
  if (!empresa) {
    error.value = 'Seleccione la tienda'
    pedidoReservado.value = null
    return
  }
  if (!puedeCrear.value) {
    error.value = 'No tiene permiso para crear pedidos a proveedor'
    return
  }
  reservando.value = true
  error.value = null
  try {
    const res = await reservarPedidoProveedor({ empresa })
    pedidoReservado.value = res.pedido
    if (res.almacen != null && form.value.almacen == null) {
      form.value.almacen = res.almacen
    }
    if (esNuevo.value && pasoAlta.value === 'tienda') {
      pasoAlta.value = 'proveedor'
      mensaje.value = `Pedido ${res.pedido} reservado. Pulse Intro para buscar proveedor (F4).`
      await nextTick(() => proveedorInputRef.value?.focus())
    }
  } catch (e: unknown) {
    pedidoReservado.value = null
    error.value = extractApiError(e, 'No se pudo reservar el número de pedido')
  } finally {
    reservando.value = false
  }
}

async function onKeyEnter(e: KeyboardEvent) {
  if (!esNuevo.value || pasoAlta.value === 'listo') return
  if (buscarProveedorOpen.value || buscarArticuloOpen.value) return
  const t = e.target
  if (t instanceof HTMLElement) {
    const tag = t.tagName
    if (tag === 'TEXTAREA' || (tag === 'INPUT' && t.closest('.panel.lineas'))) return
  }
  e.preventDefault()
  if (pasoAlta.value === 'tienda') {
    if (!form.value.empresa.trim()) {
      error.value = 'Seleccione la tienda'
      return
    }
    await reservarNumero()
    return
  }
  if (pasoAlta.value === 'proveedor') {
    abrirBuscarProveedor()
  }
}

function onNuevo() {
  if (!puedeCrear.value) return
  if (esRutaNuevo()) {
    void iniciarNuevo()
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
    pedido:
      pedidoReservado.value && pedidoReservado.value > 0 ? pedidoReservado.value : undefined,
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
  buscarProveedorOpen.value = true
}

function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  form.value.proveedor = r.codigo
  form.value.razonSocial = r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta
  buscarProveedorOpen.value = false
  if (esNuevo.value && pasoAlta.value === 'proveedor') {
    pasoAlta.value = 'listo'
    mensaje.value = 'Complete cabecera y líneas; luego Guardar.'
  }
}

function abrirBuscarArticulo(idx: number) {
  if (!camposEditables.value) return
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
  if (!camposEditables.value) return
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
  if (!camposEditables.value) return
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
  if (!camposEditables.value) return
  barcodeLineaIdx = idx
  barcodeWatcher.onInput(String(form.value.lineas[idx]?.articulo ?? ''))
}

function quitarLinea(idx: number) {
  if (!camposEditables.value) return
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
    a4Prep.value = await prepararImpresionPedidoProveedor(ficha.value, {
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
    error.value = extractApiError(e, 'No se pudo imprimir el pedido a proveedor')
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
  await intentarReservarNumeroAutomatico()
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
    pasoAlta.value = 'listo'
    mensaje.value = 'Complete cabecera y líneas; luego Guardar.'
  }
)
</script>

<template>
  <section class="compra-detalle pedido-proveedor" tabindex="-1" @keydown.enter="onKeyEnter">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar && !!ficha && !bloqueado"
      :puede-eliminar="false"
      :puede-guardar="camposEditables"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="false"
      :puede-abonar="false"
      :puede-buscar="true"
      buscar-label="Listado"
      buscar-title="Volver al listado de pedidos"
      :puede-navegar="false"
      :modo-edicion="modoEdicion"
      :bloqueado="bloqueado"
      :hay-documento="!!ficha || esNuevo"
      :loading="loading || saving || recibiendo || reservando"
      :indice="-1"
      :total="0"
      @nuevo="onNuevo"
      @modificar="onModificar"
      @buscar="volverListado"
      @guardar="onGuardar"
      @cancelar="onCancelar"
      @imprimir="onImprimir"
    />

    <div class="head">
      <div>
        <h2>{{ titulo }}</h2>
        <p class="hint">
          {{
            esNuevo
              ? pasoAlta === 'tienda' && !pedidoReservado
                ? 'Elija tienda: se asignará el número de pedido automáticamente.'
                : pasoAlta === 'proveedor'
                  ? `Pedido ${pedidoReservado ?? '—'}. Busque proveedor (Intro / F4).`
                  : 'Complete cabecera y líneas; luego Guardar para crear el pedido.'
              : 'Cant. pedida / servida. Recibir genera albarán de compra al proveedor.'
          }}
        </p>
      </div>
      <p v-if="soloLecturaMotivo" class="badge-bloqueo">Solo lectura — {{ soloLecturaMotivo }}</p>
      <p v-else-if="modoEdicion" class="badge-ok">Editando</p>
      <button
        v-if="puedeRecibir"
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
                <span class="cab-lbl cab-lbl-gap">Pedido</span>
                <div class="con-lupa w-col-b">
                  <input
                    ref="pedidoInputRef"
                    class="w-col-b-in"
                    :value="esNuevo ? (pedidoReservado ?? (reservando ? '…' : '—')) : ficha?.pedido"
                    readonly
                    title="Número de pedido"
                  />
                  <button
                    v-if="esNuevo && camposEditables && !pedidoReservado"
                    type="button"
                    class="btn-lupa"
                    :disabled="reservando || !form.empresa"
                    title="Reservar número de pedido"
                    @click="reservarNumero"
                  >
                    Nº
                  </button>
                </div>
              </div>

              <div class="cab-row">
                <span class="cab-lbl">Fecha</span>
                <input
                  v-model="form.fechaPedido"
                  class="w-col-a"
                  type="date"
                  :readonly="!camposEditables"
                />
                <span class="cab-lbl cab-lbl-gap">F. máx. recep.</span>
                <input
                  v-model="form.fechaMaxRecepcion"
                  class="w-col-b"
                  type="date"
                  :readonly="!camposEditables"
                  title="Fecha máxima de recepción"
                />
              </div>

              <div class="cab-row">
                <span class="cab-lbl">Proveedor</span>
                <div class="con-lupa w-eq">
                  <input
                    ref="proveedorInputRef"
                    v-model="form.proveedor"
                    class="w-cod"
                    :readonly="!camposEditables"
                    @keydown.f4.prevent="abrirBuscarProveedor"
                  />
                  <button
                    type="button"
                    class="btn-lupa"
                    :disabled="!proveedorBusquedaHabilitada"
                    title="Buscar proveedor (F4)"
                    @click="abrirBuscarProveedor"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <input
                  v-model="form.razonSocial"
                  class="w-col-rest"
                  :readonly="!camposEditables"
                  title="Razón social"
                />
              </div>

              <div class="cab-row">
                <span class="cab-lbl">Vendedor</span>
                <input
                  v-model="form.vendedor"
                  class="w-col-a"
                  maxlength="4"
                  :readonly="!camposEditables"
                  title="Vendedor del proveedor"
                />
                <span class="cab-lbl cab-lbl-gap">Almacén</span>
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
                  :value="form.almacen ?? ''"
                  readonly
                />
              </div>

              <div v-if="ficha && !esNuevo" class="cab-row">
                <span class="cab-lbl">Situación</span>
                <input class="w-col-rest sit-readonly" :value="situacionBadge.text" readonly />
              </div>

              <div class="cab-row cab-row-obs">
                <span class="cab-lbl">Observaciones</span>
                <input
                  v-model="form.observaciones"
                  class="w-obs"
                  :readonly="!camposEditables"
                />
              </div>

              <div class="cab-row cab-row-obs">
                <span class="cab-lbl">Obs. internas</span>
                <input
                  v-model="form.observInternas"
                  class="w-obs"
                  :readonly="!camposEditables"
                />
              </div>
            </div>
          </div>

          <aside class="panel cab-side">
            <div class="side-box totales">
              <div class="side-row">
                <span>Importe</span>
                <span class="num-red">{{ fmtNum(importeMostrado) }}</span>
              </div>
              <div class="side-row">
                <span>Líneas</span>
                <span class="num-red">{{ lineasConArticulo }}</span>
              </div>
            </div>
          </aside>
        </div>

        <div class="panel lineas">
        <div class="lineas-head">
          <h3>Líneas</h3>
          <button
            v-if="camposEditables"
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
                <th class="col-art">Artículo</th>
                <th class="col-desc">Descripción</th>
                <th class="num col-q">Pedida</th>
                <th class="num col-q">Servida</th>
                <th class="num col-p">Precio</th>
                <th class="num col-d">% Dto</th>
                <th v-if="camposEditables" class="col-act" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, idx) in form.lineas" :key="l.numLin ?? `n-${idx}`">
                <td class="col-n">{{ l.numLin ?? idx + 1 }}</td>
                <td class="col-art">
                  <div v-if="camposEditables && l.cantidadSer <= 0" class="con-lupa">
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
                    v-if="camposEditables"
                    v-model="l.descripcion"
                    class="desc-input"
                  />
                  <span v-else class="desc-text">{{ l.descripcion || '—' }}</span>
                </td>
                <td class="num col-q">
                  <DecimalInput
                    v-if="camposEditables"
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
                    v-if="camposEditables"
                    v-model="l.precioPed"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.precioPed) }}</span>
                </td>
                <td class="num col-d">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.pjeDto"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.pjeDto) }}</span>
                </td>
                <td v-if="camposEditables" class="col-act">
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
      @cerrar="a4Open = false"
      @imprimir="onImprimirA4Confirmado"
    />
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
.badge-sit {
  padding: 0.15rem 0.45rem;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 600;
}
.sit-pendiente {
  background: #e2e8f0;
  color: #334155;
}
.sit-parcial {
  background: #fef3c7;
  color: #92400e;
}
.sit-servido {
  background: #dcfce7;
  color: #166534;
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
}
.ok {
  color: #166534;
}
.msg {
  color: #475569;
}
.ser-parcial {
  color: #b45309;
  font-weight: 600;
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
  --pedido-ancho: 50rem;
  width: var(--pedido-ancho);
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
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
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
  min-width: 0;
  justify-content: space-between;
}
.cab-lbl {
  flex: 0 0 5.2rem;
  font-size: 0.7rem;
  color: #334155;
  text-align: right;
  white-space: nowrap;
}
.cab-lbl-gap {
  flex: 0 0 5.2rem;
  margin-left: 0;
  width: auto;
  text-align: right;
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
  width: 11rem;
  flex: 0 0 11rem;
  max-width: 11rem;
  box-sizing: border-box;
}
.w-col-b,
.w-col-b-in,
.w-eq {
  width: 8rem;
  flex: 0 0 8rem;
  max-width: 8rem;
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
  width: 8rem;
  flex: 0 0 8rem;
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
  flex: 1 1 0;
  min-width: 0;
  max-width: none;
}
.w-obs {
  flex: 1 1 0;
  min-width: 0;
  max-width: none;
}
.sit-readonly {
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.75rem;
}
.cab-side {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.4rem;
  background: #f1f5f9;
  min-width: 0;
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
.side-box.totales .num-red {
  color: #b91c1c;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
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
.col-n {
  width: 2.5rem;
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
  .cab-layout {
    grid-template-columns: 1fr;
  }
}
</style>
