<script setup lang="ts">
import { computed, nextTick, onActivated, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import {
  actualizarPedido,
  convertirPedidoAVenta,
  crearPedido,
  marcarPedidoImpreso,
  obtenerPedido,
  reservarPedido,
} from '@/api/ventas'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import type { PedidoDetalle, PedidoLinea, PedidoResumen } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { usePedidosBusquedaStore } from '@/stores/pedidosBusqueda'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import EntidadBuscarModal from '@/components/common/EntidadBuscarModal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const puedeCrear = computed(() => puede('ventas-pedidos', 'crear'))
const puedeEditar = computed(() => puede('ventas-pedidos', 'editar'))
const puestoContexto = usePuestoContextoStore()
const busqueda = usePedidosBusquedaStore()

/**
 * KeepAlive cachea esta vista por fullPath. Al ir al listado, useRoute() sigue
 * actualizándose en la instancia cacheada; hay que ignorar esos cambios o se
 * pinta "Pedido no válido" y al volver a Nuevo/ficha queda roto.
 */
const pathInstancia = route.fullPath

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const msg = ref<string | null>(null)
const detalle = ref<PedidoDetalle | null>(null)
const editando = ref(false)
/** Como en ventas: flag local, no solo route.name (más fiable al montar). */
const esNuevo = ref(false)
/** Flujo legacy: tienda → reservar nº → buscar cliente → líneas */
const pasoAlta = ref<'tienda' | 'cliente' | 'listo'>('listo')
const buscarClienteOpen = ref(false)
const omitirProximaCarga = ref(false)
const buscarArticuloOpen = ref(false)
const buscarVendedorOpen = ref(false)
const confirmConvertirOpen = ref(false)
const confirmConvertirPendiente = ref(false)
const ventaCreadaOpen = ref(false)
const ventaCreadaRef = ref<{ empresa: string; tipo: string; albaran: number } | null>(null)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
const articuloInputRefs = ref<HTMLInputElement[]>([])
const tiendas = ref<{ value: string; label: string }[]>([])
const tiendaSelect = ref<HTMLSelectElement | null>(null)
const pedidoNum = ref<number | null>(null)
const nifVista = ref('')
const razonVista = ref('')
const vendedorNombre = ref('')

function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const editForm = ref({
  empresa: '',
  cliente: '',
  puesto: '',
  vendedor: '',
  suPedido: '',
  email: '',
  transporte: '',
  direccionEnvio: '',
  codigoPostalEnvio: '',
  poblacionEnvio: '',
  provinciaEnvio: '',
  paisEnvio: '',
  observaciones: '',
  lineas: [] as PedidoLinea[],
})

const indice = computed(() => {
  if (!detalle.value || esNuevo.value) return -1
  return busqueda.indiceDe(detalle.value)
})

const totalNav = computed(() => busqueda.items.length)

function esRutaNuevoPedido(): boolean {
  if (route.name === 'ventas-pedidos-nuevo') return true
  const path = (route.path || '').replace(/\/+$/, '')
  return path.endsWith('/ventas/pedidos/nuevo')
}

function lineaVacia(): PedidoLinea {
  return {
    nroLin: 0,
    articulo: '',
    descripcion: null,
    cantidadPedida: 1,
    cantidadServida: 0,
    cantidadAServir: 0,
    pendiente: 1,
    precio: 0,
    pjeDto: 0,
    importe: 0,
    zona: null,
    loteVenta: null,
  }
}

function tieneLineasArticulo() {
  return editForm.value.lineas.some((l) => String(l.articulo ?? '').trim() !== '')
}

const puedeModificarPedido = computed(
  () => !!detalle.value?.editable && (puedeEditar.value || puedeCrear.value)
)

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

function cargarEnForm(d: PedidoDetalle) {
  pedidoNum.value = d.pedido
  nifVista.value = d.nif ?? ''
  razonVista.value = d.razonSocial ?? ''
  editForm.value = {
    empresa: d.empresa,
    cliente: d.cliente ?? '',
    puesto: d.puesto ?? '',
    vendedor: d.vendedor ?? '',
    suPedido: d.suPedido ?? '',
    email: d.email ?? '',
    transporte: d.transporte ?? '',
    direccionEnvio: d.direccionEnvio ?? '',
    codigoPostalEnvio: d.codigoPostalEnvio ?? '',
    poblacionEnvio: d.poblacionEnvio ?? '',
    provinciaEnvio: d.provinciaEnvio ?? '',
    paisEnvio: d.paisEnvio ?? '',
    observaciones: d.observaciones ?? '',
    lineas: (d.lineas.length ? d.lineas : [lineaVacia()]).map((l) => ({
      ...l,
      cantidadServida: Number(l.cantidadServida) || 0,
      cantidadAServir: Number(l.cantidadAServir) || 0,
      pendiente: Number(l.pendiente) || 0,
      pjeDto: Number(l.pjeDto) || 0,
    })),
  }
  void resolverNombreVendedor(editForm.value.vendedor)
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

async function focusTienda() {
  await nextTick()
  tiendaSelect.value?.focus()
}

function prepararNuevo() {
  error.value = null
  detalle.value = null
  esNuevo.value = true
  editando.value = true
  pasoAlta.value = 'tienda'
  buscarClienteOpen.value = false
  buscarArticuloOpen.value = false
  omitirProximaCarga.value = false
  pedidoNum.value = null
  nifVista.value = ''
  razonVista.value = ''
  vendedorNombre.value = ''
  buscarVendedorOpen.value = false
  editForm.value = {
    empresa: String(puestoContexto.empresaCodigo || '').trim(),
    cliente: '',
    puesto: puestoContexto.puestoCodigo || '',
    vendedor: '',
    suPedido: '',
    email: '',
    transporte: '',
    direccionEnvio: '',
    codigoPostalEnvio: '',
    poblacionEnvio: '',
    provinciaEnvio: '',
    paisEnvio: 'España',
    observaciones: '',
    lineas: [lineaVacia()],
  }
  msg.value = 'Elija la tienda y pulse Intro para reservar el número de pedido'
  void cargarTiendas().then(() => focusTienda())
}

async function cargar() {
  // No tocar estado si esta instancia está cacheada (KeepAlive) y la ruta ya no es suya.
  if (!esEstaInstanciaActiva()) return

  if (esRutaNuevoPedido()) {
    // Reentrada KeepAlive: no borrar el borrador si ya estamos en alta.
    if (!esNuevo.value) {
      prepararNuevo()
    } else {
      error.value = null
    }
    return
  }

  if (omitirProximaCarga.value) {
    omitirProximaCarga.value = false
    esNuevo.value = false
    editando.value = true
    pasoAlta.value = 'listo'
    if (!editForm.value.lineas.length) editForm.value.lineas = [lineaVacia()]
    msg.value = 'Introduzca artículos y pulse Grabar'
    return
  }

  const empresa = String(route.params.empresa ?? '').trim()
  const pedido = Number(route.params.pedido)
  if (!empresa || !Number.isFinite(pedido) || pedido <= 0) {
    if (String(route.params.empresa ?? '') === 'nuevo' || route.path.includes('/pedidos/nuevo')) {
      if (!esNuevo.value) prepararNuevo()
      return
    }
    error.value = 'Pedido no válido'
    detalle.value = null
    esNuevo.value = false
    return
  }

  loading.value = true
  error.value = null
  msg.value = null
  editando.value = false
  esNuevo.value = false
  pasoAlta.value = 'listo'
  try {
    detalle.value = await obtenerPedido(empresa, pedido)
    cargarEnForm(detalle.value)
    const forzarEdicion = busqueda.consumirAbrirEnEdicion({
      empresa: detalle.value.empresa,
      pedido: detalle.value.pedido,
    })
    // Tras alta de cabecera el layout remonta la vista (:key=path) y hay que reentrar en edicion.
    editando.value = forzarEdicion && !!detalle.value.editable
    if (editando.value) {
      if (!editForm.value.lineas.length) editForm.value.lineas = [lineaVacia()]
      msg.value = 'Introduzca artículos y pulse Grabar'
      await focusArticuloLinea(0)
    } else if (
      detalle.value.editable &&
      !(puedeEditar.value || puedeCrear.value)
    ) {
      msg.value = null
    } else if (detalle.value.editable && !tieneLineasArticulo()) {
      msg.value = 'Pedido sin líneas. Pulse Modificar para añadir artículos'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el pedido')
    detalle.value = null
  } finally {
    loading.value = false
  }
}

function irA(p: PedidoResumen) {
  router.replace(`/ventas/pedidos/${encodeURIComponent(p.empresa)}/${p.pedido}`)
}

function onNuevo() {
  if (esRutaNuevoPedido()) {
    prepararNuevo()
    return
  }
  void router.push('/ventas/pedidos/nuevo')
}

async function onIntroCabecera() {
  if (!esNuevo.value || loading.value || saving.value) return
  error.value = null

  if (pasoAlta.value === 'tienda') {
    if (!editForm.value.empresa.trim()) {
      error.value = 'Seleccione la tienda'
      return
    }
    if (!puedeCrear.value) {
      error.value = 'No tiene permiso para crear pedidos'
      return
    }
    loading.value = true
    try {
      const reserva = await reservarPedido({
        empresa: editForm.value.empresa.trim(),
        puesto: editForm.value.puesto || puestoContexto.puestoCodigo || undefined,
      })
      pedidoNum.value = reserva.pedido
      editForm.value.puesto = reserva.puesto || editForm.value.puesto
      editForm.value.vendedor = reserva.vendedor || editForm.value.vendedor
      void resolverNombreVendedor(editForm.value.vendedor)
      pasoAlta.value = 'cliente'
      msg.value = `Pedido ${reserva.pedido} reservado. Pulse Intro o Buscar para elegir cliente`
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo reservar el número de pedido')
    } finally {
      loading.value = false
    }
    return
  }

  if (pasoAlta.value === 'cliente') {
    buscarClienteOpen.value = true
  }
}

function onKeyEnter(e: KeyboardEvent) {
  if (!esNuevo.value || pasoAlta.value === 'listo') return
  if (buscarClienteOpen.value || buscarArticuloOpen.value || buscarVendedorOpen.value) return
  const t = e.target
  if (t instanceof HTMLTextAreaElement) return
  if (t instanceof HTMLElement && t.closest('.lineas')) return
  e.preventDefault()
  void onIntroCabecera()
}

function setArticuloInputRef(el: unknown, index: number) {
  if (el instanceof HTMLInputElement) {
    articuloInputRefs.value[index] = el
  }
}

async function focusArticuloLinea(index = 0) {
  await nextTick()
  const el = articuloInputRefs.value[index]
  if (el) {
    el.focus()
    el.select()
  }
}

function precioSegunTarifa(art: Record<string, unknown>): number {
  const raw = art.precioVen1 ?? art.precioVenta ?? art.pvp ?? art.precio ?? 0
  return Number(raw) || 0
}

function abrirBuscarArticulo(index: number) {
  if (!editando.value) {
    if (!puedeModificarPedido.value) return
    empezarEditar()
  }
  if (!editando.value) return
  lineaArticuloIdx.value = index
  articuloBusquedaInicial.value = String(editForm.value.lineas[index]?.articulo ?? '').trim()
  buscarArticuloOpen.value = true
}

async function aplicarArticuloEnLinea(
  index: number,
  art: Record<string, unknown>,
  fallbackCodigo: string
) {
  const linea = editForm.value.lineas[index]
  if (!linea) return
  linea.articulo = String(art.codigo ?? fallbackCodigo).trim()
  linea.descripcion = String(art.descripcion ?? '').trim()
  linea.precio = precioSegunTarifa(art)
  if (!linea.cantidadPedida) linea.cantidadPedida = 1
  let pjeIva = Number(linea.pjeIva) > 0 ? Number(linea.pjeIva) : 0
  const impuestoCodigo = String(art.impuestoCodigo ?? '').trim()
  if (impuestoCodigo) {
    try {
      const { data: imp } = await api.get(
        `/api/mantenimiento/impuestos/${encodeURIComponent(impuestoCodigo)}`
      )
      const pct = Number(imp.porcentajeIVA ?? imp.pjeIva ?? 0)
      if (pct > 0) pjeIva = pct
    } catch {
      /* mantener */
    }
  }
  if (pjeIva > 0) linea.pjeIva = pjeIva
  if (index === editForm.value.lineas.length - 1) {
    editForm.value.lineas.push(lineaVacia())
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
    const linea = editForm.value.lineas[idx]
    if (!linea) return
    linea.articulo = sel.codigo
    linea.descripcion = sel.etiqueta
    if (idx === editForm.value.lineas.length - 1) {
      editForm.value.lineas.push(lineaVacia())
    }
    await focusArticuloLinea(idx + 1)
  }
}

async function onArticuloKeydown(e: KeyboardEvent, index: number) {
  if (!editando.value) {
    if (!puedeModificarPedido.value) return
    empezarEditar()
  }
  if (!editando.value) return
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
  await resolverArticuloEnLinea(index, String(editForm.value.lineas[index]?.articulo ?? ''))
}

let barcodeLineaIdx = 0
const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  if (!editando.value) return
  await resolverArticuloEnLinea(barcodeLineaIdx, codigo)
})

async function resolverArticuloEnLinea(index: number, codigo: string) {
  const q = String(codigo ?? '').trim()
  if (!q) {
    abrirBuscarArticulo(index)
    return
  }
  try {
    const art = await resolverArticulo(q)
    await aplicarArticuloEnLinea(index, art, art.codigo)
    const linea = editForm.value.lineas[index]
    if (linea && art.unidadesPaquete > 1 && (!linea.cantidadPedida || linea.cantidadPedida === 1)) {
      linea.cantidadPedida = art.unidadesPaquete
    }
  } catch {
    abrirBuscarArticulo(index)
  }
}

function onArticuloInput(index: number) {
  if (!editando.value) return
  barcodeLineaIdx = index
  barcodeWatcher.onInput(String(editForm.value.lineas[index]?.articulo ?? ''))
}

function abrirBuscarVendedor() {
  if (!editando.value) return
  buscarVendedorOpen.value = true
}

function onVendedorSeleccionado(sel: { codigo: string; etiqueta: string }) {
  buscarVendedorOpen.value = false
  editForm.value.vendedor = sel.codigo
  vendedorNombre.value = sel.etiqueta
}

async function onVendedorKeydown(e: KeyboardEvent) {
  if (!editando.value) return
  if (e.key === 'F4') {
    e.preventDefault()
    abrirBuscarVendedor()
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  const codigo = editForm.value.vendedor.trim()
  if (!codigo) {
    abrirBuscarVendedor()
    return
  }
  await resolverNombreVendedor(codigo)
  if (!vendedorNombre.value) {
    abrirBuscarVendedor()
  }
}

async function onClienteSeleccionado(sel: { codigo: string; etiqueta: string }) {
  buscarClienteOpen.value = false
  if (!puedeCrear.value) {
    error.value = 'No tiene permiso para crear pedidos'
    return
  }
  if (!editForm.value.empresa.trim() || !pedidoNum.value) {
    error.value = 'Reserve primero el número de pedido (tienda + Intro)'
    pasoAlta.value = 'tienda'
    return
  }

  loading.value = true
  error.value = null
  try {
    const { data: cli } = await api.get(
      `/api/mantenimiento/clientes/${encodeURIComponent(sel.codigo)}`
    )
    const codigo = String(cli.codigo ?? sel.codigo).trim()
    const razon = String(cli.nombre ?? cli.razonSocial ?? sel.etiqueta ?? '').trim()
    const nif = cli.nif != null ? String(cli.nif) : ''
    const transporte = String(cli.transportista ?? cli.transporte ?? '').trim()

    editForm.value.cliente = codigo
    nifVista.value = nif
    razonVista.value = razon
    editForm.value.direccionEnvio = String(cli.direccionEnvio || cli.direccion || '')
    editForm.value.poblacionEnvio = String(cli.poblacionEnvio || cli.poblacion || '')
    editForm.value.codigoPostalEnvio = String(cli.codigoPostalEnvio || cli.codigoPostal || '')
    editForm.value.provinciaEnvio = String(cli.provinciaEnvio || cli.provincia || '')
    editForm.value.paisEnvio = String(cli.paisEnvio || cli.pais || 'España')
    editForm.value.email = cli.email != null ? String(cli.email) : ''
    editForm.value.transporte = transporte

    const created = await crearPedido({
      empresa: editForm.value.empresa.trim(),
      pedido: pedidoNum.value,
      cliente: codigo,
      razonSocial: razon,
      nif,
      puesto: editForm.value.puesto || undefined,
      vendedor: editForm.value.vendedor || undefined,
      email: editForm.value.email || undefined,
      transporte: transporte || undefined,
      direccionEnvio: editForm.value.direccionEnvio || undefined,
      codigoPostalEnvio: editForm.value.codigoPostalEnvio || undefined,
      poblacionEnvio: editForm.value.poblacionEnvio || undefined,
      provinciaEnvio: editForm.value.provinciaEnvio || undefined,
      paisEnvio: editForm.value.paisEnvio || undefined,
      lineas: [],
    })

    detalle.value = created
    busqueda.upsertResumen(created)
    cargarEnForm(created)
    if (!editForm.value.lineas.length) editForm.value.lineas = [lineaVacia()]
    esNuevo.value = false
    editando.value = true
    pasoAlta.value = 'listo'
    omitirProximaCarga.value = true
    busqueda.marcarAbrirEnEdicion({
      empresa: created.empresa,
      pedido: created.pedido,
    })
    msg.value = `Pedido ${created.pedido} creado. Introduzca artículos y pulse Grabar`
    const mismaRuta =
      route.name === 'ventas-pedidos-detalle' &&
      String(route.params.empresa) === String(created.empresa).trim() &&
      Number(route.params.pedido) === created.pedido
    if (mismaRuta) {
      omitirProximaCarga.value = false
      busqueda.consumirAbrirEnEdicion({
        empresa: created.empresa,
        pedido: created.pedido,
      })
      await focusArticuloLinea(0)
    } else {
      await router.replace(
        `/ventas/pedidos/${encodeURIComponent(String(created.empresa).trim())}/${created.pedido}`
      )
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el pedido')
  } finally {
    loading.value = false
  }
}

function primero() {
  const v = busqueda.items[0]
  if (v) irA(v)
}
function anterior() {
  if (indice.value <= 0) return
  const v = busqueda.items[indice.value - 1]
  if (v) irA(v)
}
function siguiente() {
  if (indice.value < 0 || indice.value >= busqueda.items.length - 1) return
  const v = busqueda.items[indice.value + 1]
  if (v) irA(v)
}
function ultimo() {
  const v = busqueda.items[busqueda.items.length - 1]
  if (v) irA(v)
}

function empezarEditar() {
  if (!detalle.value?.editable || !(puedeEditar.value || puedeCrear.value)) return
  cargarEnForm(detalle.value)
  if (!editForm.value.lineas.length) editForm.value.lineas = [lineaVacia()]
  editando.value = true
  error.value = null
  msg.value = 'Introduzca artículos y pulse Grabar'
  void focusArticuloLinea(0)
}

function cancelarEditar() {
  if (esNuevo.value) {
    void router.push({ name: 'ventas-pedidos' })
    return
  }
  editando.value = false
  if (detalle.value) cargarEnForm(detalle.value)
}

function addLinea() {
  editForm.value.lineas.push(lineaVacia())
}

function removeLinea(i: number) {
  editForm.value.lineas.splice(i, 1)
  if (!editForm.value.lineas.length) addLinea()
}

function pendienteDe(l: PedidoLinea) {
  const pedida = Number(l.cantidadPedida) || 0
  const servida = Number(l.cantidadServida) || 0
  const p = Number(l.pendiente)
  if (Number.isFinite(p) && p >= 0) return Math.max(0, p)
  return Math.max(0, pedida - servida)
}

function servirTodoPendiente() {
  for (const l of editForm.value.lineas) {
    if (!String(l.articulo ?? '').trim()) continue
    l.cantidadAServir = pendienteDe(l)
  }
  msg.value = 'Cantidades a servir rellenadas con el pendiente'
}

function resumentLineasAServir(usarPendiente: boolean) {
  let lineas = 0
  let unidades = 0
  for (const l of editForm.value.lineas) {
    if (!String(l.articulo ?? '').trim()) continue
    const qty = usarPendiente ? pendienteDe(l) : Number(l.cantidadAServir) || 0
    if (qty <= 0.00001) continue
    lineas += 1
    unidades += qty
  }
  return { lineas, unidades }
}

const totalPendiente = computed(() =>
  editForm.value.lineas.reduce((acc, l) => {
    if (!String(l.articulo ?? '').trim()) return acc
    return acc + pendienteDe(l)
  }, 0)
)

const totalAServir = computed(() =>
  editForm.value.lineas.reduce((acc, l) => {
    if (!String(l.articulo ?? '').trim()) return acc
    return acc + (Number(l.cantidadAServir) || 0)
  }, 0)
)

const puedeConvertirBase = computed(
  () =>
    !esNuevo.value &&
    !!detalle.value?.editable &&
    (puedeEditar.value || puedeCrear.value) &&
    String(detalle.value?.cliente ?? editForm.value.cliente ?? '').trim() !== '' &&
    tieneLineasArticulo() &&
    totalPendiente.value > 0.00001
)

/** Tras guardar el pedido (no en edición): pasar a albarán de venta al cliente. */
const puedeGenerarAlbaranCliente = computed(
  () => puedeConvertirBase.value && !editando.value
)

const puedeConvertir = computed(() => puedeGenerarAlbaranCliente.value)

const mensajeConfirmConvertir = computed(() => {
  const r = resumentLineasAServir(confirmConvertirPendiente.value)
  if (confirmConvertirPendiente.value) {
    return `Se generará un albarán con todo el pendiente: ${r.lineas} línea(s), ${r.unidades.toFixed(2)} uds. ¿Continuar?`
  }
  return `Se generará un albarán con las cantidades a servir: ${r.lineas} línea(s), ${r.unidades.toFixed(2)} uds. ¿Continuar?`
})

const mensajeVentaCreada = computed(() => {
  const v = ventaCreadaRef.value
  if (!v) return 'Albarán generado.'
  return `Venta ${v.tipo}-${v.albaran} creada. ¿Abrirla ahora?`
})

function pedirConvertir(servirPendiente: boolean) {
  if (!puedeConvertir.value || !detalle.value) return
  error.value = null
  msg.value = null

  if (servirPendiente) {
    if (totalPendiente.value <= 0.00001) {
      error.value = 'No hay cantidad pendiente para servir'
      return
    }
  } else {
    if (totalAServir.value <= 0.00001) {
      if (!editando.value && puedeModificarPedido.value) {
        empezarEditar()
      }
      servirTodoPendiente()
      if (totalAServir.value <= 0.00001) {
        error.value = 'No hay cantidad pendiente para servir'
        return
      }
      msg.value = 'Cantidades a servir rellenadas con el pendiente'
    }
    for (const l of editForm.value.lineas) {
      if (!String(l.articulo ?? '').trim()) continue
      const aServir = Number(l.cantidadAServir) || 0
      const pend = pendienteDe(l)
      if (aServir > pend + 0.0001) {
        error.value = `«${l.articulo}»: a servir (${aServir}) supera el pendiente (${pend})`
        return
      }
    }
  }

  confirmConvertirPendiente.value = servirPendiente
  confirmConvertirOpen.value = true
}

async function confirmarConvertir() {
  confirmConvertirOpen.value = false
  await ejecutarConvertir(confirmConvertirPendiente.value)
}

async function ejecutarConvertir(servirPendiente: boolean) {
  if (!detalle.value || !(puedeEditar.value || puedeCrear.value) || !detalle.value.editable) return

  saving.value = true
  error.value = null
  msg.value = null
  try {
    if (editando.value) {
      const lineas = payloadLineas()
      detalle.value = await actualizarPedido(detalle.value.empresa, detalle.value.pedido, {
        ...payloadCab(),
        lineas,
      })
      editando.value = false
      cargarEnForm(detalle.value)
    }
    const res = await convertirPedidoAVenta(detalle.value.empresa, detalle.value.pedido, {
      puesto: detalle.value.puesto || editForm.value.puesto || undefined,
      vendedor: detalle.value.vendedor || editForm.value.vendedor || undefined,
      servirPendiente,
    })
    detalle.value = res.pedido
    busqueda.upsertResumen(res.pedido)
    cargarEnForm(res.pedido)
    const v = res.venta
    msg.value = `Albarán ${v.tipo}-${v.albaran} generado`
    ventaCreadaRef.value = {
      empresa: String(v.empresa).trim(),
      tipo: String(v.tipo).trim(),
      albaran: Number(v.albaran),
    }
    ventaCreadaOpen.value = true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el albarán')
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

function payloadLineas() {
  return editForm.value.lineas
    .filter((l) => String(l.articulo ?? '').trim())
    .map((l) => ({
      articulo: String(l.articulo).trim(),
      descripcion: l.descripcion ?? undefined,
      cantidadPedida: Number(l.cantidadPedida) || 0,
      cantidadServida: Number(l.cantidadServida) || 0,
      cantidadAServir: Number(l.cantidadAServir) || 0,
      precio: Number(l.precio) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      zona: l.zona || undefined,
      loteVenta: l.loteVenta || undefined,
    }))
}

function payloadCab() {
  return {
    cliente: editForm.value.cliente.trim(),
    puesto: editForm.value.puesto.trim() || undefined,
    vendedor: editForm.value.vendedor.trim() || undefined,
    suPedido: editForm.value.suPedido,
    email: editForm.value.email.trim() || undefined,
    transporte: editForm.value.transporte.trim() || undefined,
    direccionEnvio: editForm.value.direccionEnvio.trim() || undefined,
    codigoPostalEnvio: editForm.value.codigoPostalEnvio.trim() || undefined,
    poblacionEnvio: editForm.value.poblacionEnvio.trim() || undefined,
    provinciaEnvio: editForm.value.provinciaEnvio.trim() || undefined,
    paisEnvio: editForm.value.paisEnvio.trim() || undefined,
    observaciones: editForm.value.observaciones,
  }
}

async function onGuardar() {
  if (esNuevo.value) {
    error.value = 'Complete tienda y cliente (Intro) antes de grabar líneas'
    return
  }
  if (!(puedeEditar.value || puedeCrear.value) || !detalle.value) return
  const lineas = payloadLineas()
  saving.value = true
  error.value = null
  msg.value = null
  try {
    detalle.value = await actualizarPedido(detalle.value.empresa, detalle.value.pedido, {
      ...payloadCab(),
      lineas,
    })
    busqueda.upsertResumen({
      ...detalle.value,
      vendedor: editForm.value.vendedor.trim() || detalle.value.vendedor,
      puesto: editForm.value.puesto.trim() || detalle.value.puesto,
      cliente: editForm.value.cliente.trim() || detalle.value.cliente,
      suPedido: editForm.value.suPedido || detalle.value.suPedido,
      importe: detalle.value.importe,
    })
    cargarEnForm(detalle.value)
    editando.value = false
    msg.value = `Pedido ${detalle.value.pedido} guardado. Use Albarán para generar venta al cliente.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el pedido')
  } finally {
    saving.value = false
  }
}

async function onImpreso() {
  if (!detalle.value || !(puedeEditar.value || puedeCrear.value)) return
  try {
    detalle.value = await marcarPedidoImpreso(detalle.value.empresa, detalle.value.pedido)
    busqueda.upsertResumen(detalle.value)
    msg.value = 'Pedido marcado como impreso'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo marcar impreso')
  }
}

function irVenta() {
  const v = detalle.value?.ventaAsociada
  if (!detalle.value || !v) return
  router.push(`/ventas/${detalle.value.empresa}/${v.tipo}/${v.albaran}`)
}

function irListado() {
  void router.push({ name: 'ventas-pedidos' })
}

const importeEdit = computed(() =>
  editForm.value.lineas.reduce((acc, l) => {
    const cant = Number(l.cantidadPedida) || 0
    const precio = Number(l.precio) || 0
    const dto = Number(l.pjeDto) || 0
    return acc + cant * precio * (1 - dto / 100)
  }, 0)
)
const bruto = computed(() =>
  editando.value ? importeEdit.value : Number(detalle.value?.importeBase1 ?? detalle.value?.importe ?? 0)
)
const iva = computed(() => (editando.value ? 0 : Number(detalle.value?.importeIva1 ?? 0)))
const importe = computed(() =>
  editando.value ? importeEdit.value : Number(detalle.value?.importe ?? 0)
)

const tiendaEditable = computed(() => esNuevo.value && pasoAlta.value === 'tienda' && editando.value)
const clienteBuscable = computed(
  () => (esNuevo.value && pasoAlta.value === 'cliente') || (editando.value && !!detalle.value?.editable)
)

onMounted(() => {
  void cargarTiendas()
})

// KeepAlive + :key=fullPath: cada URL es una instancia. activated cubre alta y reentrada.
onActivated(() => {
  void cargar()
})
</script>

<template>
  <section class="pedido-ficha" tabindex="-1" @keydown.enter="onKeyEnter">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeModificarPedido"
      :puede-guardar="editando && !esNuevo && (puedeEditar || puedeCrear)"
      :puede-imprimir="!!detalle && (puedeEditar || puedeCrear)"
      :puede-generar-albaran="puedeGenerarAlbaranCliente"
      generar-albaran-label="Albarán"
      :puede-buscar="true"
      :puede-navegar="!esNuevo && !editando && totalNav > 0"
      :modo-edicion="editando"
      :hay-documento="!!detalle || esNuevo"
      :loading="loading || saving"
      :indice="indice"
      :total="totalNav"
      @nuevo="onNuevo"
      @modificar="empezarEditar"
      @buscar="irListado"
      @guardar="onGuardar"
      @cancelar="cancelarEditar"
      @primero="primero"
      @anterior="anterior"
      @siguiente="siguiente"
      @ultimo="ultimo"
      @imprimir="onImpreso"
      @generar-albaran="pedirConvertir(false)"
    />

    <ol v-if="esNuevo" class="pasos-alta" aria-label="Pasos alta pedido">
      <li :class="{ activo: pasoAlta === 'tienda', hecho: pasoAlta !== 'tienda' }">1. Tienda</li>
      <li :class="{ activo: pasoAlta === 'cliente', hecho: pasoAlta === 'listo' }">2. Cliente</li>
      <li :class="{ activo: false, hecho: pasoAlta === 'listo' }">3. Líneas</li>
    </ol>

    <div class="head">
      <div>
        <h2>{{ esNuevo ? 'Nuevo pedido' : `Pedido ${detalle?.pedido ?? pedidoNum ?? ''}` }}</h2>
        <p v-if="detalle" class="hint">
          {{ detalle.actualizado ? 'CERRADO' : 'ABIERTO' }}
          — Impreso: {{ detalle.impreso ? 'Sí' : 'No' }}
          <template v-if="!editando && puedeGenerarAlbaranCliente">
            — Tras guardar, use <strong>Albarán</strong> para pasar a venta al cliente.
          </template>
          <template v-else-if="editando && !esNuevo">
            — Guarde el pedido para habilitar <strong>Albarán</strong>.
          </template>
          <template v-if="detalle.ventaAsociada">
            — Última venta:
            <button type="button" class="linkish" @click="irVenta">
              {{ detalle.ventaAsociada.tipo }}-{{ detalle.ventaAsociada.albaran }}
            </button>
          </template>
        </p>
      </div>
      <div class="head-acciones">
        <button type="button" class="btn-listado" :disabled="loading || saving" @click="irListado">
          Volver al listado
        </button>
        <div v-if="puedeConvertir" class="acciones-conv">
          <button
            type="button"
            class="convert"
            :disabled="saving"
            title="Usa las cantidades de la columna A servir"
            @click="pedirConvertir(false)"
          >
            Generar albarán
          </button>
          <button
            type="button"
            class="convert secondary"
            :disabled="saving"
            title="Sirve todo lo pendiente del pedido"
            @click="pedirConvertir(true)"
          >
            Todo pendiente
          </button>
        </div>
      </div>
    </div>

    <p v-if="esNuevo && pasoAlta === 'tienda'" class="ok">
      Elija la <strong>tienda</strong> y pulse <strong>Intro</strong> para reservar el número de pedido.
    </p>
    <p v-else-if="esNuevo && pasoAlta === 'cliente'" class="ok">
      Pedido <strong>{{ pedidoNum }}</strong> reservado. Pulse <strong>Intro</strong> o
      <strong>Buscar</strong> para elegir el cliente.
    </p>
    <p v-if="msg && !(esNuevo && (pasoAlta === 'tienda' || pasoAlta === 'cliente'))" class="ok">
      {{ msg }}
    </p>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="loading" class="hint">Cargando...</p>

    <div v-if="detalle || esNuevo" class="layout">
      <aside class="totales">
        <div class="tot-box">
          <div class="row"><span>Bruto</span><strong>{{ bruto.toFixed(2) }}</strong></div>
          <div class="row"><span>IVA</span><strong>{{ iva.toFixed(2) }}</strong></div>
          <div class="row importe">
            <span>Importe</span><strong>{{ importe.toFixed(2) }}</strong>
          </div>
        </div>

        <div v-if="!esNuevo && detalle" class="servir-box">
          <p class="radio-title">Pasar a venta</p>
          <div class="row"><span>Pendiente</span><strong>{{ totalPendiente.toFixed(2) }}</strong></div>
          <div class="row"><span>A servir</span><strong>{{ totalAServir.toFixed(2) }}</strong></div>
          <p v-if="!detalle.editable" class="hint-mini">Pedido cerrado: no se puede generar albarán.</p>
          <p v-else-if="totalPendiente <= 0" class="hint-mini">Todo servido. No queda pendiente.</p>
          <template v-else-if="puedeConvertirBase">
            <button
              v-if="editando"
              type="button"
              class="btn-servir"
              :disabled="saving"
              @click="servirTodoPendiente"
            >
              Rellenar a servir
            </button>
            <template v-if="puedeGenerarAlbaranCliente">
            <button
              type="button"
              class="convert"
              :disabled="saving"
              @click="pedirConvertir(false)"
            >
              Generar albarán
            </button>
            <button
              type="button"
              class="convert secondary"
              :disabled="saving"
              @click="pedirConvertir(true)"
            >
              Todo pendiente
            </button>
            <p class="hint-mini">
              <strong>Generar albarán</strong> usa la columna «A servir».
              <strong> Todo pendiente</strong> sirve el resto de golpe.
            </p>
            </template>
          </template>
        </div>

        <div v-if="editando" class="radio-box">
          <p class="radio-title">Opciones</p>
          <button
            v-if="esNuevo && pasoAlta === 'tienda'"
            type="button"
            class="primary"
            :disabled="loading"
            @click="onIntroCabecera"
          >
            Reservar nº
          </button>
          <button
            v-else-if="esNuevo && pasoAlta === 'cliente'"
            type="button"
            class="primary"
            :disabled="loading"
            @click="buscarClienteOpen = true"
          >
            Buscar cliente
          </button>
          <button
            v-else
            type="button"
            class="primary"
            :disabled="saving || esNuevo"
            @click="onGuardar"
          >
            Grabar pedido
          </button>
          <button type="button" :disabled="saving" @click="cancelarEditar">Cancelar</button>
        </div>
      </aside>

      <div class="cab">
        <div class="grid-cab">
          <label class="field-tienda">
            Tienda
            <select ref="tiendaSelect" v-model="editForm.empresa" :disabled="!tiendaEditable">
              <option value="">--</option>
              <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
          <label>
            Pedido
            <input :value="pedidoNum ?? detalle?.pedido ?? ''" disabled />
          </label>
          <label>
            Fecha
            <input :value="detalle?.fecha?.slice(0, 10) || ''" disabled />
          </label>
          <label>
            Situación
            <input :value="detalle?.actualizado ? 'CERRADO' : 'ABIERTO'" disabled />
          </label>

          <label class="cliente-field">
            Cliente
            <div class="cliente-row">
              <input v-model="editForm.cliente" :disabled="!editando || esNuevo" />
              <button
                type="button"
                class="btn-buscar"
                :disabled="!clienteBuscable || loading"
                title="Buscar cliente"
                @click="buscarClienteOpen = true"
              >
                ...
              </button>
            </div>
          </label>
          <label>
            N.I.F.
            <input :value="nifVista || detalle?.nif || ''" disabled />
          </label>
          <label class="span2">
            Razón social
            <input :value="razonVista || detalle?.razonSocial || ''" disabled />
          </label>

          <label>
            Transportista
            <input
              v-model="editForm.transporte"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label class="span2">
            Dirección envío
            <input
              v-model="editForm.direccionEnvio"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label>
            C.P.
            <input
              v-model="editForm.codigoPostalEnvio"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label>
            Población
            <input
              v-model="editForm.poblacionEnvio"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label>
            Provincia
            <input
              v-model="editForm.provinciaEnvio"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label>
            País
            <input
              v-model="editForm.paisEnvio"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label class="span2">
            E-Mail
            <input
              v-model="editForm.email"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
          <label class="vendedor-field">
            Vendedor
            <div class="cliente-row">
              <input
                v-model="editForm.vendedor"
                :disabled="!editando"
                maxlength="4"
                placeholder="Código"
                :title="vendedorNombre || 'Código de vendedor'"
                @keydown="onVendedorKeydown"
                @blur="resolverNombreVendedor(editForm.vendedor)"
              />
              <button
                type="button"
                class="btn-buscar"
                :disabled="!editando || loading"
                :title="vendedorNombre ? `Buscar vendedor — ${vendedorNombre}` : 'Buscar vendedor (Intro / F4)'"
                @click="abrirBuscarVendedor"
              >
                ...
              </button>
            </div>
          </label>
          <label>
            Puesto
            <input v-model="editForm.puesto" :disabled="!editando" maxlength="2" />
          </label>
          <label>
            Su pedido
            <input
              v-model="editForm.suPedido"
              :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            />
          </label>
        </div>

        <label class="obs">
          Observaciones pedido
          <textarea
            v-model="editForm.observaciones"
            :disabled="!editando || (esNuevo && pasoAlta !== 'listo')"
            rows="3"
          />
        </label>
      </div>
    </div>

    <div v-if="(detalle || esNuevo) && (!esNuevo || pasoAlta === 'listo')" class="lineas">
      <div v-if="editando" class="lin-toolbar">
        <button type="button" @click="servirTodoPendiente">Rellenar a servir (pendiente)</button>
        <button type="button" @click="addLinea">+ Línea</button>
      </div>
      <p v-if="editando" class="hint-lin">
        En código: <strong>Intro</strong> carga el artículo o abre búsqueda;
        <strong> F4</strong> / doble clic / <strong>...</strong> abre siempre la búsqueda.
        Para pasar a venta, rellene <strong>A servir</strong> y use Generar albarán.
      </p>
      <div class="grid-wrap">
        <table>
          <thead>
            <tr>
              <th>Artículo</th>
              <th>Descripción</th>
              <th>Zona</th>
              <th>Lote venta</th>
              <th class="num" title="Cantidad pedida">Pedida</th>
              <th class="num" title="Cantidad ya entregada">Entreg.</th>
              <th class="num" title="Pendiente de servir">Pend.</th>
              <th class="num" title="Cantidad a servir en el próximo albarán">A servir</th>
              <th class="num">Precio</th>
              <th class="num">Dto %</th>
              <th class="num">Importe</th>
              <th v-if="editando"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, i) in editForm.lineas" :key="i">
              <td>
                <div v-if="editando" class="celda-articulo">
                  <input
                    :ref="(el) => setArticuloInputRef(el, i)"
                    v-model="l.articulo"
                    maxlength="18"
                    placeholder="Código / buscar..."
                    @input="onArticuloInput(i)"
                    @keydown="onArticuloKeydown($event, i)"
                    @dblclick="abrirBuscarArticulo(i)"
                  />
                  <button
                    type="button"
                    class="btn-buscar-art"
                    title="Buscar artículo (Intro / F4)"
                    @click="abrirBuscarArticulo(i)"
                  >
                    ...
                  </button>
                </div>
                <span v-else>{{ l.articulo }}</span>
              </td>
              <td>
                <input v-if="editando" v-model="l.descripcion" />
                <span v-else>{{ l.descripcion }}</span>
              </td>
              <td>
                <input v-if="editando" v-model="l.zona" />
                <span v-else>{{ l.zona || '' }}</span>
              </td>
              <td>
                <input v-if="editando" v-model="l.loteVenta" />
                <span v-else>{{ l.loteVenta || '' }}</span>
              </td>
              <td class="num">
                <DecimalInput
                  v-if="editando"
                  v-model="l.cantidadPedida"
                  :empty-as-null="false"
                />
                <span v-else>{{ Number(l.cantidadPedida).toFixed(2) }}</span>
              </td>
              <td class="num">{{ Number(l.cantidadServida || 0).toFixed(2) }}</td>
              <td class="num pend">{{ pendienteDe(l).toFixed(2) }}</td>
              <td class="num">
                <DecimalInput
                  v-if="editando"
                  v-model="l.cantidadAServir"
                  :empty-as-null="false"
                  class="input-servir"
                />
                <span v-else>{{ Number(l.cantidadAServir || 0).toFixed(2) }}</span>
              </td>
              <td class="num">
                <DecimalInput
                  v-if="editando"
                  v-model="l.precio"
                  :empty-as-null="false"
                />
                <span v-else>{{ Number(l.precio).toFixed(4) }}</span>
              </td>
              <td class="num">
                <DecimalInput
                  v-if="editando"
                  v-model="l.pjeDto"
                  :empty-as-null="false"
                />
                <span v-else>{{ Number(l.pjeDto || 0).toFixed(2) }}</span>
              </td>
              <td class="num">
                {{
                  (
                    (Number(l.cantidadPedida) || 0) *
                    (Number(l.precio) || 0) *
                    (1 - (Number(l.pjeDto) || 0) / 100)
                  ).toFixed(2)
                }}
              </td>
              <td v-if="editando">
                <button type="button" @click="removeLinea(i)">×</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

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
      titulo="Buscar artículo"
      :busqueda-inicial="articuloBusquedaInicial"
      @seleccionar="onArticuloSeleccionado"
      @cerrar="buscarArticuloOpen = false"
    />

    <EntidadBuscarModal
      :open="buscarVendedorOpen"
      entidad="trabajadores"
      titulo="Buscar vendedor"
      :busqueda-inicial="editForm.vendedor"
      @seleccionar="onVendedorSeleccionado"
      @cerrar="buscarVendedorOpen = false"
    />

    <ConfirmDialog
      :open="confirmConvertirOpen"
      title="Generar albarán"
      :message="mensajeConfirmConvertir"
      confirm-label="Generar"
      cancel-label="Cancelar"
      :danger="false"
      @confirm="confirmarConvertir"
      @cancel="confirmConvertirOpen = false"
    />

    <ConfirmDialog
      :open="ventaCreadaOpen"
      title="Albarán generado"
      :message="mensajeVentaCreada"
      confirm-label="Abrir venta"
      cancel-label="Seguir en pedido"
      :danger="false"
      @confirm="abrirVentaCreada"
      @cancel="cerrarVentaCreada"
    />
  </section>
</template>

<style scoped>
.pedido-ficha {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.pedido-ficha h2 {
  margin: 0 0 0.25rem;
}
.head {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 0.25rem;
  max-width: 960px;
}
.head-acciones {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.4rem;
}
.btn-listado {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #64748b;
  background: #fff;
  color: #334155;
  cursor: pointer;
  font: inherit;
  white-space: nowrap;
}
.btn-listado:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #2563eb;
  color: #1d4ed8;
}
.btn-listado:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0;
}
.acciones-conv {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
  justify-content: flex-end;
}
.convert {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #14532d;
  background: #166534;
  color: #fff;
  cursor: pointer;
}
.convert.secondary {
  background: #fff;
  color: #166534;
  border-color: #166534;
}
.convert.secondary:hover:not(:disabled) {
  background: #ecfdf5;
}
.convert:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.servir-box {
  border: 2px solid #166534;
  border-radius: 4px;
  background: #fff;
  padding: 0.6rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.servir-box .row {
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
}
.hint-mini {
  margin: 0.15rem 0 0;
  font-size: 0.72rem;
  color: #64748b;
  line-height: 1.35;
}
.btn-servir {
  padding: 0.35rem 0.55rem;
  border-radius: 4px;
  border: 1px solid #64748b;
  background: #f8fafc;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
}
.td.pend,
.num.pend {
  color: #b45309;
  font-weight: 600;
}
.input-servir {
  border-color: #166534 !important;
  background: #ecfdf5 !important;
}
.primary {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
}
.layout {
  display: grid;
  grid-template-columns: 11rem 1fr;
  gap: 1rem;
  margin-bottom: 1rem;
  max-width: 960px;
  width: 100%;
  box-sizing: border-box;
}
.totales {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
.tot-box,
.radio-box {
  border: 2px solid #b91c1c;
  border-radius: 4px;
  background: #fff;
  padding: 0.6rem;
}
.tot-box .row {
  display: flex;
  justify-content: space-between;
  font-size: 0.85rem;
  margin-bottom: 0.25rem;
}
.tot-box .importe strong {
  color: #b91c1c;
  font-size: 1.25rem;
}
.radio-box {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.radio-title {
  margin: 0 0 0.25rem;
  font-size: 0.8rem;
  font-weight: 700;
}
.cab {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 0.75rem;
  background: #f8fafc;
}
.grid-cab {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.45rem 0.65rem;
}
.grid-cab label,
.obs {
  display: flex;
  flex-direction: column;
  font-size: 0.72rem;
  gap: 0.12rem;
  color: #475569;
}
.span2 {
  grid-column: span 2;
}
.obs {
  margin-top: 0.65rem;
}
input,
textarea,
select {
  padding: 0.28rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
}
input:disabled,
textarea:disabled {
  background: #e2e8f0;
  color: #334155;
}
.lineas {
  margin-top: 0.5rem;
  max-width: 960px;
  width: 100%;
  box-sizing: border-box;
}
.lin-toolbar {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 0.4rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.4rem;
  text-align: left;
  vertical-align: middle;
}
th {
  background: #f1f5f9;
  white-space: nowrap;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
td input {
  width: 100%;
  box-sizing: border-box;
  min-width: 3.5rem;
}
.linkish {
  background: none;
  border: none;
  color: #0369a1;
  text-decoration: underline;
  cursor: pointer;
  padding: 0;
  font: inherit;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #166534;
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
.field-tienda select:not(:disabled) {
  border-color: #2563eb;
  background: #eff6ff;
  font-weight: 600;
}
.cliente-row {
  display: flex;
  gap: 0.25rem;
}
.cliente-row input {
  flex: 1;
  min-width: 0;
}
.btn-buscar {
  padding: 0.28rem 0.55rem;
  border: 1px solid #64748b;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-weight: 700;
}
.btn-buscar:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.celda-articulo {
  display: flex;
  gap: 0.25rem;
  align-items: center;
}
.celda-articulo input {
  flex: 1;
  min-width: 0;
}
.btn-buscar-art {
  padding: 0.2rem 0.45rem;
  border: 1px solid #64748b;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-weight: 700;
  flex-shrink: 0;
}
.hint-lin {
  margin: 0 0 0.35rem;
  font-size: 0.78rem;
  color: #64748b;
}
select:disabled {
  background: #e2e8f0;
  color: #334155;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
  .grid-cab {
    grid-template-columns: 1fr 1fr;
  }
}
</style>
