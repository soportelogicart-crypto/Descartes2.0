<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { getDescartesBridge, imprimirEtiquetaViaBridge } from '@/bridge/electron'
import { dibujarCode128 } from '@/utils/code128'
import { extractApiError } from '@/composables/extractApiError'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useVentasBusquedaStore } from '@/stores/ventasBusqueda'

type Tienda = { codigo: string; nombre: string }
type Cliente = { codigo: string; nombre: string; telefono1?: string; email?: string }
type FichaCliente = {
  codigo: string
  nombre: string
  nif: string
  telefono1: string
  email: string
  direccion: string
  codigoPostal: string
  poblacion: string
  provincia: string
  formaPago: string
}
type Encargo = {
  numero: number
  cliente: string
  nombre: string
  telefono: string
  email: string
  albaranEmpresa: string
  albaranTipo: string
  albaran: number
  tiendaOrigen: string
  tiendaDestino: string
  origenNombre: string
  destinoNombre: string
  modalidad: string
  mismaTienda: boolean
  rodillos: number
  etiquetasPedidas: number
  etiquetasImpresas: number
  etiquetaDesde?: number
  etiquetaHasta?: number
  tipo: string
  estado: string
  direccion?: string
  codigoPostal?: string
  poblacion?: string
  provincia?: string
  fechaAlta: string
  qr: string
}

const router = useRouter()
const puesto = usePuestoContextoStore()
const busquedaVentas = useVentasBusquedaStore()
const tiendaPuesto = ref<Tienda | null>(null)
const formasPago = ref<{ codigo: string; descripcion: string }[]>([])
const clientes = ref<Cliente[]>([])
const encargos = ref<Encargo[]>([])
const filtroEncargo = ref('')
const buscandoCliente = ref(false)
let filtroTimer: ReturnType<typeof setTimeout> | undefined
let listaSeq = 0
let clienteSeq = 0
const trabajos = ref<Encargo[]>([])
const error = ref<string | null>(null)
const aviso = ref<string | null>(null)
const cargando = ref(false)
const cliente = ref<FichaCliente | null>(null)
const modoAlta = ref(false)
const guardandoCliente = ref(false)
const creando = ref(false)
const alta = ref(fichaVacia())
const form = ref({
  tipo: 'revelado' as 'revelado' | 'impresion',
  tiendaEntrega: '',
  modalidad: 'recogida',
  rodillos: 1,
})

function fichaVacia(): FichaCliente {
  return {
    codigo: '',
    nombre: '',
    nif: '',
    telefono1: '',
    email: '',
    direccion: '',
    codigoPostal: '',
    poblacion: '',
    provincia: '',
    formaPago: '',
  }
}

function fichaDesdeApi(data: Record<string, unknown>, codigo: string): FichaCliente {
  return {
    codigo: String(data.codigo ?? codigo).trim(),
    nombre: String(data.nombre ?? '').trim(),
    nif: String(data.nif ?? '').trim(),
    telefono1: String(data.telefono1 ?? data.telefono ?? '').trim(),
    email: String(data.email ?? '').trim(),
    direccion: String(data.direccion ?? '').trim(),
    codigoPostal: String(data.codigoPostal ?? '').trim(),
    poblacion: String(data.poblacion ?? '').trim(),
    provincia: String(data.provincia ?? '').trim(),
    formaPago: String(data.formaPago ?? '').trim(),
  }
}

const estadoTxt: Record<string, string> = {
  pendiente_revelar: 'Pendiente de revelar',
  pendiente_entrega: 'Ya revelado, pendiente de entrega',
  entregado: 'Entregado',
}

function textoEstado(e: { estado: string; tipo?: string }): string {
  if (e.estado === 'pendiente_revelar' && e.tipo === 'impresion') return 'Pendiente de imprimir'
  if (e.estado === 'pendiente_entrega' && e.tipo === 'impresion') return 'Pendiente de entrega'
  return estadoTxt[e.estado] || e.estado
}

const pendientesCliente = computed(() => trabajos.value.filter((e) => e.estado !== 'entregado'))
const consultaClienteActiva = computed(() => {
  const texto = filtroEncargo.value.trim()
  return texto.length >= 3 && !/^DCTB/i.test(texto)
})
const rutaTxt = computed(() => {
  if (!tiendaPuesto.value) return 'El puesto no tiene tienda configurada. Indíquela en los parámetros del puesto.'
  return `El encargo queda en ${tiendaPuesto.value.nombre}.`
})

onMounted(async () => {
  await Promise.all([cargarTiendaPuesto(), cargarFormas(), cargar()])
  tomarClienteDeMantenimiento()
})

onActivated(() => {
  tomarClienteDeMantenimiento()
})

async function cargarTiendaPuesto() {
  const empresa = String(puesto.empresaCodigo ?? '').trim()
  if (!empresa) {
    tiendaPuesto.value = null
    form.value.tiendaEntrega = ''
    return
  }
  const { data } = await api.get('/api/encargos/tiendas', { params: { empresa } })
  tiendaPuesto.value = data.tienda ?? null
  form.value.tiendaEntrega = tiendaPuesto.value?.codigo ?? ''
}

watch(
  () => puesto.empresaCodigo,
  () => {
    void cargarTiendaPuesto()
  }
)

async function cargarFormas() {
  const { data } = await api.get('/api/mantenimiento/formas-pago', { params: { pageSize: 100 } })
  formasPago.value = (data.items ?? []).map((f: { codigo: string; descripcion?: string }) => ({
    codigo: String(f.codigo).trim(),
    descripcion: String(f.descripcion ?? f.codigo).trim(),
  }))
  alta.value.formaPago = formasPago.value[0]?.codigo ?? ''
}

async function cargar() {
  const texto = filtroEncargo.value.trim()
  if (/^DCTB/i.test(texto) && codigoBarrasCompleto(texto)) {
    await buscarPorCodigo(texto)
    return
  }
  const seq = ++listaSeq
  cargando.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/encargos', {
      params: texto ? { q: texto } : {},
    })
    if (seq !== listaSeq) return
    encargos.value = data.items ?? []
  } catch (e: unknown) {
    if (seq !== listaSeq) return
    error.value = extractApiError(e, 'No se pudieron cargar los encargos')
  } finally {
    if (seq === listaSeq) cargando.value = false
  }
}

function lecturaNormalizada(texto: string): string {
  let normal = texto.trim().replace(/Ñ/gi, ':')
  if (/^DCTB/i.test(normal)) normal = normal.replace(/[-'´`’‘?]/g, ':')
  return normal
}

function codigoBarrasCompleto(texto: string): boolean {
  const normal = lecturaNormalizada(texto)
  return /^DCTB:E:.+:\d+$/i.test(normal) || /^DCTB:C:.+$/i.test(normal)
}

function esCodigoEncargo(texto: string): boolean {
  return /^DCTB:E:.+:\d+$/i.test(lecturaNormalizada(texto))
}

function esCodigoCliente(texto: string): boolean {
  return /^DCTB:C:.+$/i.test(lecturaNormalizada(texto))
}

function descartarBusquedaCliente() {
  clienteSeq += 1
  clientes.value = []
  buscandoCliente.value = false
}

function buscarAhora() {
  if (filtroTimer) clearTimeout(filtroTimer)
  void aplicarFiltro()
}

async function buscarPorCodigo(texto: string) {
  const codigo = texto.trim()
  const seq = ++listaSeq
  cargando.value = true
  error.value = null
  aviso.value = null
  try {
    const { data } = await api.get('/api/encargos/qr', { params: { codigo } })
    if (seq !== listaSeq) return
    if (data?.tipo === 'encargo' && data.encargo) {
      encargos.value = [data.encargo as Encargo]
      return
    }
    encargos.value = []
    error.value = 'Este QR no tiene encargo.'
  } catch (e: unknown) {
    if (seq !== listaSeq) return
    encargos.value = []
    error.value = extractApiError(e, 'No se pudo leer el QR')
  } finally {
    if (seq === listaSeq) cargando.value = false
  }
}

async function leerQrCliente(codigo: string) {
  error.value = null
  aviso.value = null
  try {
    const { data } = await api.get('/api/encargos/qr', { params: { codigo } })
    if (data?.tipo === 'cliente' && data.cliente) {
      await cargarFicha(String(data.cliente.codigo))
      aviso.value = `Cliente ${data.cliente.codigo} ${data.cliente.nombre}. Este QR no tiene encargo.`
      return
    }
    if (data?.tipo === 'encargo' && data.encargo) {
      if (filtroEncargo.value === codigo) void buscarPorCodigo(codigo)
      else filtroEncargo.value = codigo
      return
    }
    error.value = 'QR no reconocido'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo leer el QR')
  }
}

watch(filtroEncargo, (texto) => {
  if (filtroTimer) clearTimeout(filtroTimer)
  const trimmed = texto.trim()
  if (/^DCTB/i.test(trimmed)) {
    descartarBusquedaCliente()
    if (!codigoBarrasCompleto(trimmed)) return
    filtroTimer = setTimeout(() => {
      void aplicarFiltro()
    }, 120)
    return
  }
  if (trimmed.length < 3) descartarBusquedaCliente()
  filtroTimer = setTimeout(() => {
    void aplicarFiltro()
  }, 300)
})

async function aplicarFiltro() {
  const texto = filtroEncargo.value.trim()
  if (/^DCTB/i.test(texto) && !codigoBarrasCompleto(texto)) {
    descartarBusquedaCliente()
    encargos.value = []
    error.value = 'QR no reconocido'
    return
  }
  if (esCodigoCliente(texto)) {
    descartarBusquedaCliente()
    await leerQrCliente(texto)
    return
  }
  await cargar()
  if (filtroEncargo.value.trim() !== texto) return
  if (texto.length >= 3 && !esCodigoEncargo(texto)) {
    await buscarCliente(texto)
    return
  }
  descartarBusquedaCliente()
}

async function buscarCliente(consulta: string) {
  const seq = ++clienteSeq
  buscandoCliente.value = true
  try {
    const { data } = await api.get('/api/encargos/clientes', { params: { q: consulta } })
    if (seq !== clienteSeq) return
    clientes.value = (data.items ?? []).map((c: { codigo: string; nombre: string; telefono?: string; email?: string }) => ({
      codigo: c.codigo,
      nombre: c.nombre,
      telefono1: c.telefono,
      email: c.email,
    }))
  } catch (e: unknown) {
    if (seq !== clienteSeq) return
    clientes.value = []
    if (!error.value) error.value = extractApiError(e, 'No se pudo buscar el cliente')
  } finally {
    if (seq === clienteSeq) buscandoCliente.value = false
  }
}

function elegir(c: Cliente) {
  modoAlta.value = false
  void cargarFicha(c.codigo)
}

function empezarAlta() {
  const texto = filtroEncargo.value.trim()
  alta.value = fichaVacia()
  alta.value.formaPago = cliente.value?.formaPago || formasPago.value[0]?.codigo || ''
  if (!/^DCTB/i.test(texto)) {
    if (/^\+?[\d\s()-]{3,}$/.test(texto)) alta.value.telefono1 = texto.replace(/[^\d+]/g, '')
    else if (texto.length >= 3) alta.value.nombre = texto
  }
  modoAlta.value = true
  descartarBusquedaCliente()
}

function tomarClienteDeMantenimiento() {
  const codigo = sessionStorage.getItem('descartes.encargoCliente')?.trim() ?? ''
  if (!codigo) return
  sessionStorage.removeItem('descartes.encargoCliente')
  void cargarFicha(codigo)
}

function cancelarAlta() {
  modoAlta.value = false
  alta.value = fichaVacia()
  alta.value.formaPago = formasPago.value[0]?.codigo ?? ''
}

async function cargarFicha(codigo: string) {
  error.value = null
  try {
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(codigo)}`)
    cliente.value = fichaDesdeApi(data as Record<string, unknown>, codigo)
    modoAlta.value = false
    void cargarTiendaPuesto()
    if (!cliente.value.formaPago) cliente.value.formaPago = formasPago.value[0]?.codigo ?? ''
    clientes.value = []
    await cargarTrabajos(cliente.value.codigo)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el cliente')
  }
}

async function crearCliente() {
  error.value = null
  aviso.value = null
  if (!alta.value.nombre.trim()) {
    error.value = 'Indique el nombre del cliente'
    return
  }
  if (!alta.value.nif.trim()) {
    error.value = 'Indique el NIF del cliente'
    return
  }
  if (!alta.value.formaPago) alta.value.formaPago = formasPago.value[0]?.codigo ?? ''
  guardandoCliente.value = true
  try {
    const { data } = await api.post('/api/mantenimiento/clientes', {
      nombre: alta.value.nombre,
      nif: alta.value.nif,
      telefono1: alta.value.telefono1,
      email: alta.value.email,
      direccion: alta.value.direccion,
      codigoPostal: alta.value.codigoPostal,
      poblacion: alta.value.poblacion,
      provincia: alta.value.provincia,
      formaPago: alta.value.formaPago,
      tiendaCodigo: form.value.tiendaEntrega,
    })
    const codigo = String(data.codigo).trim()
    cliente.value = fichaDesdeApi(
      {
        ...alta.value,
        codigo,
        nombre: data.nombre ?? alta.value.nombre,
        telefono1: data.telefono1 ?? alta.value.telefono1,
        email: data.email ?? alta.value.email,
      },
      codigo
    )
    alta.value = fichaVacia()
    alta.value.formaPago = formasPago.value[0]?.codigo ?? ''
    modoAlta.value = false
    aviso.value = 'Cliente creado. Si tiene email, se le envía el QR de alta.'
    await cargarTrabajos(codigo)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo dar de alta el cliente')
  } finally {
    guardandoCliente.value = false
  }
}

function datosCliente(f: FichaCliente) {
  return {
    nombre: f.nombre,
    nif: f.nif,
    telefono1: f.telefono1,
    email: f.email,
    direccion: f.direccion,
    codigoPostal: f.codigoPostal,
    poblacion: f.poblacion,
    provincia: f.provincia,
    formaPago: f.formaPago,
  }
}

let cpSeq = 0

async function alCambiarCodigoPostal(destino: 'cliente' | 'alta', raw: string) {
  const ficha = destino === 'cliente' ? cliente.value : alta.value
  if (!ficha) return
  ficha.codigoPostal = raw
  const seq = ++cpSeq
  try {
    const data = await lookupCodigoPostal(raw)
    if (seq !== cpSeq || !data) return
    const actual = destino === 'cliente' ? cliente.value : alta.value
    if (!actual) return
    if (data.poblacion) actual.poblacion = data.poblacion
    if (data.provincia) actual.provincia = data.provincia
  } catch {
    // El código postal no está en la tabla: se dejan población y provincia como estén.
  }
}

async function guardarCliente() {
  const f = cliente.value
  if (!f) return
  error.value = null
  aviso.value = null
  guardandoCliente.value = true
  try {
    await api.put(`/api/mantenimiento/clientes/${encodeURIComponent(f.codigo)}`, datosCliente(f))
    aviso.value = `Datos del cliente ${f.codigo} actualizados.`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron actualizar los datos del cliente')
  } finally {
    guardandoCliente.value = false
  }
}

async function crearEncargo() {
  if (creando.value) return
  if (!cliente.value) {
    error.value = 'Seleccione o cree el cliente antes del encargo'
    return
  }
  if (!form.value.tiendaEntrega) {
    error.value = 'El puesto no tiene tienda configurada. Indíquela en los parámetros del puesto.'
    return
  }
  const f = cliente.value
  error.value = null
  aviso.value = null
  creando.value = true
  try {
    await api.put(`/api/mantenimiento/clientes/${encodeURIComponent(f.codigo)}`, datosCliente(f))
    const revelado = form.value.tipo === 'revelado'
    if (!String(puesto.vendedorCodigo ?? '').trim()) {
      await puesto.cargarVendedorPuesto()
    }
    const { data } = await api.post('/api/encargos', {
      cliente: f.codigo,
      puesto: String(puesto.puestoCodigo ?? '').trim(),
      vendedor: String(puesto.vendedorCodigo ?? '').trim(),
      nombre: f.nombre,
      nif: f.nif,
      telefono: f.telefono1,
      email: f.email,
      direccion: f.direccion,
      codigoPostal: f.codigoPostal,
      poblacion: f.poblacion,
      provincia: f.provincia,
      formaPago: f.formaPago,
      tipo: form.value.tipo,
      tiendaEntrega: form.value.tiendaEntrega,
      modalidad: form.value.modalidad,
      rodillos: revelado ? Math.max(1, Number(form.value.rodillos) || 1) : 0,
    })
    const empresa = String(data.albaranEmpresa ?? '').trim()
    const tipo = String(data.albaranTipo ?? '').trim()
    const albaran = Number(data.albaran)
    limpiarCabecera()
    if (revelado) await imprimir(Number(data.numero), false)
    busquedaVentas.marcarAbrirEnEdicion({ empresa, tipo, albaran })
    await router.push({ name: 'ventas-detalle', params: { empresa, tipo, albaran: String(albaran) } })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el encargo')
  } finally {
    creando.value = false
  }
}

function limpiarCabecera() {
  cliente.value = null
  modoAlta.value = false
  alta.value = fichaVacia()
  alta.value.formaPago = formasPago.value[0]?.codigo ?? ''
  clientes.value = []
  form.value.rodillos = 1
  trabajos.value = []
}

async function cargarTrabajos(codigo: string) {
  const { data } = await api.get('/api/encargos', { params: { cliente: codigo } })
  trabajos.value = data.items ?? []
}

function textoEtiqueta(valor: unknown): string {
  return String(valor ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

function fechaEtiqueta(valor: unknown): string {
  const m = String(valor ?? '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/)
  if (!m) return String(valor ?? '')
  return m[4] ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : `${m[3]}/${m[2]}/${m[1]}`
}

const PUNTOS_MM = 300 / 25.4

function pxEtiqueta(mm: number): number {
  return Math.round(mm * PUNTOS_MM)
}

async function etiquetaHorizontal(datos: {
  nombre: string
  tel: string
  email: string
  fecha: string
  sobre: string
  qr: string
}): Promise<string> {
  const ancho = pxEtiqueta(76)
  const alto = pxEtiqueta(40)
  const lienzo = document.createElement('canvas')
  lienzo.width = ancho
  lienzo.height = alto
  const g = lienzo.getContext('2d')
  if (!g) return ''
  g.imageSmoothingEnabled = false
  g.fillStyle = '#fff'
  g.fillRect(0, 0, ancho, alto)
  g.fillStyle = '#000'
  g.textBaseline = 'top'
  const x = pxEtiqueta(10)
  const max = ancho - x - pxEtiqueta(2)
  const bh = pxEtiqueta(12)
  const yBarra = alto - bh - pxEtiqueta(0.6)
  let yBase = yBarra - pxEtiqueta(0.3)
  const encima = (texto: string, tamMm: number, negrita = false) => {
    const limpio = texto.trim()
    if (!limpio) return
    yBase -= pxEtiqueta(tamMm)
    g.font = `${negrita ? '700 ' : ''}${pxEtiqueta(tamMm)}px Arial`
    g.fillText(limpio, x, yBase, max)
    yBase -= pxEtiqueta(0.25)
  }
  encima(datos.qr, 2.4)
  encima(datos.sobre, 2.7, true)
  let y = pxEtiqueta(8)
  const linea = (texto: string, tamMm: number, negrita = false) => {
    const limpio = texto.trim()
    if (!limpio) return
    if (y + pxEtiqueta(tamMm) > yBase) return
    g.font = `${negrita ? '700 ' : ''}${pxEtiqueta(tamMm)}px Arial`
    g.fillText(limpio, x, y, max)
    y += pxEtiqueta(tamMm + 0.35)
  }
  linea(datos.nombre, 4.4, true)
  if (datos.tel) linea(`Tel. ${datos.tel}`, 2.5)
  linea(datos.email, 2.5)
  linea(`Creado: ${datos.fecha}`, 2.5)
  if (datos.qr) {
    dibujarCode128(g, datos.qr, x, yBarra, ancho - x - pxEtiqueta(1), bh)
  }
  return lienzo.toDataURL('image/png')
}

async function impresoraEtiquetas(): Promise<string | undefined> {
  const bridge = getDescartesBridge()
  if (!bridge?.listPrinters) return undefined
  try {
    const res = await bridge.listPrinters()
    const hit = (res.printers ?? []).find((p) => /344|phomemo/i.test(`${p.name} ${p.displayName ?? ''}`))
    return hit?.name
  } catch {
    return undefined
  }
}

function imprimirHtml(titulo: string, cuerpo: string, estilo: string) {
  const marco = document.createElement('iframe')
  marco.setAttribute('aria-hidden', 'true')
  marco.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0'
  document.body.appendChild(marco)
  const doc = marco.contentDocument
  const ventana = marco.contentWindow
  if (!doc || !ventana) {
    marco.remove()
    return
  }
  doc.open()
  doc.write(`<!doctype html><html><head><meta charset="utf-8"><title>${titulo}</title><style>${estilo}</style></head><body>${cuerpo}</body></html>`)
  doc.close()
  const cerrar = () => marco.remove()
  ventana.addEventListener('afterprint', cerrar)
  window.setTimeout(() => {
    ventana.focus()
    ventana.print()
  }, 200)
  window.setTimeout(cerrar, 60000)
}

async function imprimir(numero: number, vuelta: boolean) {
  error.value = null
  try {
    const { data } = await api.post(`/api/encargos/${numero}/etiqueta`, { vuelta })
    const copiasN = Math.max(1, Number(data.copias) || (Array.isArray(data.numeros) ? data.numeros.length : 1))
    const telefonos = [data.telefono, data.telefono2].filter((linea) => String(linea ?? '').trim() !== '')
    const email = String(data.email ?? '').trim()
    const tel = telefonos.map((linea) => textoEtiqueta(linea)).join(' · ')
    const paginas: string[] = []
    for (let i = 1; i <= copiasN; i++) {
      const png = await etiquetaHorizontal({
        nombre: String(data.nombre ?? ''),
        tel,
        email,
        fecha: fechaEtiqueta(data.fecha),
        sobre: `sobre ${i}/${copiasN}`,
        qr: String(data.qr ?? ''),
      })
      paginas.push(`<div class="pagina"><img src="${png}" alt=""></div>`)
    }
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>@page{size:101.6mm 45.8mm;margin:0}html,body{margin:0;padding:0}.pagina{page-break-after:always}.pagina:last-child{page-break-after:auto}img{display:block;width:76mm;height:40mm;margin-left:13mm}</style></head><body>${paginas.join('')}</body></html>`
    const impresora = await impresoraEtiquetas()
    await imprimirEtiquetaViaBridge({
      html,
      impresora,
      pageWidthMm: 45.8,
      pageHeightMm: 101.6,
      landscape: true,
      copies: 1,
      silent: Boolean(impresora),
    })
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir la etiqueta')
  }
}

async function imprimirTransporte(e: Encargo) {
  error.value = null
  try {
    const { data } = await api.post(`/api/encargos/${e.numero}/etiqueta-transporte`)
    const direccion = [data.direccion, data.codigoPostal, data.poblacion, data.provincia].filter(Boolean).join(', ')
    const cuerpo = `<section>
        <h1>Encargo ${data.numero}</h1>
        <p><strong>${data.nombre}</strong><br>${direccion}<br>${data.telefono}</p>
        <p>Tienda: ${data.tienda}</p>
      </section>`
    imprimirHtml(`Transporte ${data.numero}`, cuerpo, 'body{font-family:Arial,sans-serif} section{width:90mm;margin:8mm auto} h1{font-size:18px}')
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudo imprimir la etiqueta de transporte')
  }
}

function abrirAlbaran(e: Encargo) {
  const empresa = String(e.albaranEmpresa ?? '').trim()
  const tipo = String(e.albaranTipo ?? '').trim()
  const albaran = Number(e.albaran)
  if (empresa && tipo && albaran > 0 && e.estado !== 'entregado') {
    busquedaVentas.marcarAbrirEnEdicion({ empresa, tipo, albaran })
  }
  void router.push({ name: 'ventas-detalle', params: { empresa, tipo, albaran: String(albaran) } })
}
</script>

<template>
  <section class="encargos">
    <h1>Encargos de revelado e impresión</h1>
    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="aviso" class="aviso">{{ aviso }}</p>

    <label class="filtro-lista">Buscar encargo por número, cliente o teléfono
      <input v-model="filtroEncargo" placeholder="Número, nombre, teléfono o código DCTB" @keydown.enter.prevent="buscarAhora" />
    </label>

    <div class="rejilla">
      <form class="panel" @submit.prevent="crearEncargo">
        <h2>Comanda</h2>
        <div class="buscar-fila">
          <button type="button" @click="empezarAlta">Nuevo cliente</button>
        </div>
        <p v-if="buscandoCliente" class="pista">Buscando cliente…</p>
        <p v-else-if="!modoAlta && consultaClienteActiva && !clientes.length && !encargos.length && !cargando" class="pista">
          No hay encargos ni clientes con ese dato.
          <button type="button" @click="empezarAlta">Crear nuevo cliente</button>
        </p>
        <table v-if="clientes.length" class="grid-clientes">
          <thead>
            <tr>
              <th>Código</th>
              <th>Nombre</th>
              <th>Teléfono</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in clientes" :key="c.codigo" role="button" tabindex="0" @click="elegir(c)" @keydown.enter.prevent="elegir(c)">
              <td>{{ c.codigo }}</td>
              <td>{{ c.nombre }}</td>
              <td>{{ c.telefono1 }}</td>
            </tr>
          </tbody>
        </table>
        <fieldset v-if="cliente && !modoAlta" class="bloque-cliente">
          <legend>Cliente {{ cliente.codigo }}</legend>
          <label class="campo">Nombre <input v-model="cliente.nombre" maxlength="50" /></label>
          <label class="campo">NIF <input v-model="cliente.nif" maxlength="16" /></label>
          <label class="campo">Teléfono <input v-model="cliente.telefono1" maxlength="15" /></label>
          <label class="campo span-2">Dirección <input v-model="cliente.direccion" maxlength="50" /></label>
          <label class="campo">C.P. <input v-model="cliente.codigoPostal" maxlength="8" @input="alCambiarCodigoPostal('cliente', ($event.target as HTMLInputElement).value)" /></label>
          <label class="campo">Población <input v-model="cliente.poblacion" maxlength="50" /></label>
          <label class="campo">Provincia <input v-model="cliente.provincia" maxlength="50" /></label>
          <label class="campo span-2">Email <input v-model="cliente.email" type="email" maxlength="200" /></label>
          <label class="campo">Pago
            <select v-model="cliente.formaPago">
              <option v-for="f in formasPago" :key="f.codigo" :value="f.codigo">{{ f.descripcion }}</option>
            </select>
          </label>
          <div class="span-3 acciones-alta">
            <button type="button" :disabled="guardandoCliente" @click="guardarCliente">
              {{ guardandoCliente ? 'Guardando…' : 'Actualizar cliente' }}
            </button>
          </div>
        </fieldset>

        <p v-if="cliente && !modoAlta && !tiendaPuesto" class="error">{{ rutaTxt }}</p>
        <template v-else-if="cliente && !modoAlta">
          <div class="modalidad">
            <label class="radio"><input v-model="form.tipo" type="radio" value="revelado" /> Revelado</label>
            <label class="radio"><input v-model="form.tipo" type="radio" value="impresion" /> Impresión</label>
          </div>
          <p class="pista">Tienda: {{ tiendaPuesto?.nombre }}</p>
          <div class="modalidad">
            <label class="radio"><input v-model="form.modalidad" type="radio" value="recogida" /> Lo recogerá el cliente</label>
            <label class="radio"><input v-model="form.modalidad" type="radio" value="envio" /> Envío por mensajero</label>
          </div>
          <label v-if="form.tipo === 'revelado'" class="etiquetas">Número de etiquetas
            <input v-model.number="form.rodillos" type="number" min="1" />
          </label>
          <p v-if="form.tipo === 'revelado'" class="pista">
            El número del encargo es el del albarán y se repite en todas las etiquetas. Indique cuántas se imprimen.
            Si la tienda tiene un artículo por defecto y ese artículo existe, el albarán lleva una línea con esta cantidad. Si no, se abre vacío. Queda pendiente de cobro y el encargo, pendiente de revelar.
          </p>
          <p v-else class="pista">La impresión no lleva etiquetas. Al crearlo se abre el albarán para añadir los productos. Queda pendiente de cobro. El número del encargo es el del albarán.</p>
          <p class="pista">{{ rutaTxt }}</p>
          <button type="submit" :disabled="creando">{{ creando ? 'Creando…' : 'Crear encargo' }}</button>
        </template>

        <div v-if="cliente && !modoAlta" class="trabajos">
          <h3>Albaranes pendientes de cobro</h3>
          <p v-if="!pendientesCliente.length" class="pista">Este cliente no tiene encargos pendientes.</p>
          <table v-else class="grid-clientes">
            <thead>
              <tr><th>Albarán</th><th>Encargo</th><th>Situación</th></tr>
            </thead>
            <tbody>
              <tr v-for="e in pendientesCliente" :key="'p' + e.numero" role="button" tabindex="0" @click="abrirAlbaran(e)" @keydown.enter.prevent="abrirAlbaran(e)">
                <td>{{ e.albaranEmpresa }}/{{ e.albaranTipo }}/{{ e.albaran }}</td>
                <td>{{ e.albaran }}</td>
                <td>{{ textoEstado(e) }}</td>
              </tr>
            </tbody>
          </table>
          <h3>Histórico</h3>
          <p v-if="!trabajos.length" class="pista">Todavía no hay trabajos de este cliente.</p>
          <table v-else class="grid-clientes">
            <thead>
              <tr><th>Albarán</th><th>Encargo</th><th>Situación</th></tr>
            </thead>
            <tbody>
              <tr v-for="e in trabajos" :key="'h' + e.numero" role="button" tabindex="0" @click="abrirAlbaran(e)" @keydown.enter.prevent="abrirAlbaran(e)">
                <td>{{ e.albaranEmpresa }}/{{ e.albaranTipo }}/{{ e.albaran }}</td>
                <td>{{ e.albaran }}</td>
                <td>{{ textoEstado(e) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="!cliente && !modoAlta" class="pista">Elija un cliente en los resultados de la búsqueda de encargos (nombre, código o teléfono), o pulse Nuevo cliente para darlo de alta.</p>
        <fieldset v-if="modoAlta" class="bloque-cliente">
          <legend>Nuevo cliente</legend>
          <label class="campo">Nombre <input v-model="alta.nombre" maxlength="50" /></label>
          <label class="campo">NIF <input v-model="alta.nif" maxlength="16" /></label>
          <label class="campo">Teléfono <input v-model="alta.telefono1" maxlength="15" /></label>
          <label class="campo span-2">Dirección <input v-model="alta.direccion" maxlength="50" /></label>
          <label class="campo">C.P. <input v-model="alta.codigoPostal" maxlength="8" @input="alCambiarCodigoPostal('alta', ($event.target as HTMLInputElement).value)" /></label>
          <label class="campo">Población <input v-model="alta.poblacion" maxlength="50" /></label>
          <label class="campo">Provincia <input v-model="alta.provincia" maxlength="50" /></label>
          <label class="campo span-2">Email <input v-model="alta.email" type="email" maxlength="200" /></label>
          <label class="campo">Pago
            <select v-model="alta.formaPago">
              <option v-for="f in formasPago" :key="'a' + f.codigo" :value="f.codigo">{{ f.descripcion }}</option>
            </select>
          </label>
          <div class="span-3 acciones-alta">
            <button type="button" :disabled="guardandoCliente" @click="crearCliente">
              {{ guardandoCliente ? 'Creando…' : 'Crear cliente' }}
            </button>
            <button type="button" :disabled="guardandoCliente" @click="cancelarAlta">Cancelar</button>
          </div>
        </fieldset>
      </form>
    </div>

    <table v-if="!cargando">
      <thead>
        <tr>
          <th>Encargo</th>
          <th>Albarán</th>
          <th>Sobres</th>
          <th>Cliente</th>
          <th>Tiendas</th>
          <th>Situación</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="e in encargos" :key="e.numero">
          <td><strong>{{ e.albaran }}</strong></td>
          <td>{{ e.albaranEmpresa }}/{{ e.albaranTipo }}/{{ e.albaran }}</td>
          <td>{{ e.tipo === 'impresion' ? '—' : e.etiquetasPedidas }}</td>
          <td>{{ e.nombre }}<br><small>{{ e.telefono }}</small></td>
          <td>{{ e.origenNombre }} → {{ e.destinoNombre }}<br>{{ e.tipo === 'impresion' ? 'Impresión' : 'Revelado' }} · {{ e.modalidad === 'envio' ? 'Mensajero' : 'Recogida' }}</td>
          <td>{{ textoEstado(e) }}</td>
          <td class="acc">
            <button type="button" @click="abrirAlbaran(e)">Albarán</button>
            <button v-if="e.tipo !== 'impresion'" type="button" @click="imprimir(e.numero, false)">Etiqueta</button>
            <button v-if="e.modalidad === 'envio' && e.estado === 'pendiente_entrega'" type="button" @click="imprimirTransporte(e)">Transporte</button>
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>

<style scoped>
.encargos { padding: 1rem 1.25rem; }
.rejilla { display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 1rem; }
.panel { display: flex; flex-direction: column; gap: 0.4rem; border: 1px solid #ccc; padding: 0.8rem; }
label { display: flex; flex-direction: column; gap: 0.15rem; font-size: 0.9rem; }
.radio { flex-direction: row; align-items: center; gap: 0.4rem; }
.error { color: #8a1f1f; }
.aviso { color: #1d4d2c; }
table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
th, td { border-bottom: 1px solid #ddd; text-align: left; padding: 0.35rem; vertical-align: top; }
.acc { display: flex; flex-wrap: wrap; gap: 0.25rem; }
button { cursor: pointer; }
.filtro-lista { max-width: 28rem; margin-top: 0.8rem; }
.buscar-fila { display: flex; align-items: flex-end; gap: 0.5rem; }
.crece { flex: 1; }
.acciones-alta { display: flex; gap: 0.5rem; align-items: center; }
.bloque-cliente {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.15rem 0.4rem;
  margin: 0;
  padding: 0.28rem 0.45rem 0.4rem;
  border: 1px solid #c5d0c2;
  background: #f6f8f5;
}
.bloque-cliente legend { font-weight: 600; padding: 0 0.25rem; font-size: 0.82rem; }
.bloque-cliente .campo {
  display: grid;
  grid-template-columns: 4.2rem minmax(0, 1fr);
  align-items: center;
  gap: 0.2rem;
  margin: 0;
  font-size: 0.75rem;
}
.bloque-cliente input,
.bloque-cliente select {
  height: 1.4rem;
  min-width: 0;
  padding: 0 0.28rem;
  font-size: 0.78rem;
}
.span-2 { grid-column: span 2; }
.span-3 { grid-column: 1 / -1; }
.tiendas { display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem 0.55rem; }
.modalidad { display: flex; gap: 1rem; }
.etiquetas input { max-width: 6rem; }
.grid-clientes { margin-top: 0; }
.grid-clientes tbody tr { cursor: pointer; }
.grid-clientes tbody tr:hover { background: #eef4ea; }
.trabajos { display: flex; flex-direction: column; gap: 0.25rem; }
.trabajos h3 { margin: 0.4rem 0 0; font-size: 0.95rem; }
</style>
