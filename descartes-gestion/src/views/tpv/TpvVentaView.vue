<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  borrarBotonTeclado,
  guardarBotonTeclado,
  obtenerNivelTeclado,
  obtenerNivelesTeclado,
} from '@/api/tpv'
import { enviarVentaPorEmail } from '@/api/ventas'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import TpvAbonoModal from '@/components/tpv/TpvAbonoModal.vue'
import TpvBotonConfigModal from '@/components/tpv/TpvBotonConfigModal.vue'
import TpvClienteModal from '@/components/tpv/TpvClienteModal.vue'
import TpvCobroModal from '@/components/tpv/TpvCobroModal.vue'
import TpvCodigoModal from '@/components/tpv/TpvCodigoModal.vue'
import TpvDescuentoModal from '@/components/tpv/TpvDescuentoModal.vue'
import TpvPrecioModal from '@/components/tpv/TpvPrecioModal.vue'
import TpvTecladoGrid from '@/components/tpv/TpvTecladoGrid.vue'
import TpvTicketPanel from '@/components/tpv/TpvTicketPanel.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import VentaPostFinalizacionModal from '@/components/ventas/VentaPostFinalizacionModal.vue'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import { extractApiError } from '@/composables/extractApiError'
import {
  imprimirA4Preparado,
  prepararOImprimirVenta,
  type PrepImpresionA4,
} from '@/composables/useImpresionVentaDocumento'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useTpvVentaStore } from '@/stores/tpvVenta'
import type {
  TpvBoton,
  TpvBotonAsignacion,
  TpvCliente,
  TpvNivel,
  TpvNivelResumen,
} from '@/types/tpv'
import { CLIENTE_RAPIDO_TPV } from '@/types/tpv'
import type { VentaDetalle } from '@/types/ventas'

const router = useRouter()
const { puede } = usePermisos()
const puestoStore = usePuestoContextoStore()
const tpv = useTpvVentaStore()
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => imprimirDocumentoA4() })

const initError = ref<string | null>(null)
const nivelData = ref<TpvNivel | null>(null)
const cargandoTeclado = ref(false)
const stackNiveles = ref<string[]>(['000'])
const seleccion = ref(-1)
const cantidadTecleada = ref('')
const editandoPrecioLinea = ref(-1)
const editandoDescuentoLinea = ref(-1)
const codigoManual = ref('')
const inputCodigo = ref<HTMLInputElement | null>(null)
const cobroAbierto = ref(false)
const confirmarAnulacion = ref(false)
const abonoAbierto = ref(false)
const codigoTecladoAbierto = ref(false)
const configurandoBotones = ref(false)
const botonConfigurando = ref<TpvBoton | null>(null)
const nivelesConfigurables = ref<TpvNivelResumen[]>([])
const cobrado = ref<string | null>(null)
const clienteAbierto = ref(false)
/** Último ticket cerrado: permite reintentar la impresión sin rehacer la venta. */
const ultimoTicket = ref<VentaDetalle | null>(null)
const ultimoTipoDocumento = ref('T')
const errorImpresion = ref<string | null>(null)
const imprimiendo = ref(false)
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const postVentaOpen = ref(false)
const postVentaEnviando = ref(false)
const postVentaError = ref<string | null>(null)
const ultimoDocumento = ref('')

const cabecera = computed(() => {
  const c = tpv.contexto
  if (!c) return 'TPV'
  return `TPV · Tienda ${c.empresa} · Puesto ${c.puesto} · Sesion ${c.sesion}`
})

const etiquetaTicket = computed(() => {
  if (tpv.numeroTicket === null) return 'Sin ticket'
  return tpv.ventaGrabada ? `Ticket ${tpv.numeroTicket}` : `Ticket ${tpv.numeroTicket} (propuesto)`
})

const puedeVolver = computed(() => stackNiveles.value.length > 1)
const lineaSeleccionada = computed(() => seleccion.value >= 0 && seleccion.value < tpv.lineas.length)
const etiquetaLineaMarcada = computed(() => {
  const l = lineaSeleccionada.value ? tpv.lineas[seleccion.value] : null
  if (!l) return 'Ninguna línea marcada'
  return `Línea ${seleccion.value + 1}: ${l.descripcion || l.articulo}`
})
const lineaEnEdicion = computed(() =>
  editandoPrecioLinea.value >= 0 ? tpv.lineas[editandoPrecioLinea.value] : null
)

const modalPrecio = computed(() => {
  if (tpv.pendientePrecio) {
    const p = tpv.pendientePrecio
    return {
      open: true,
      titulo: 'Articulo sin precio de tarifa',
      articulo: p.articulo,
      descripcion: p.descripcion,
      cantidad: p.cantidad,
      precioInicial: 0,
    }
  }
  const l = lineaEnEdicion.value
  if (l) {
    return {
      open: true,
      titulo: 'Modificar precio de linea',
      articulo: l.articulo,
      descripcion: l.descripcion,
      cantidad: l.cantidad,
      precioInicial: l.precio,
    }
  }
  return { open: false, titulo: '', articulo: '', descripcion: '', cantidad: 0, precioInicial: 0 }
})

async function cargarNivel(nivel: string) {
  if (!tpv.contexto) return
  cargandoTeclado.value = true
  try {
    nivelData.value = await obtenerNivelTeclado(tpv.contexto.tecladoGeneral, nivel)
  } finally {
    cargandoTeclado.value = false
  }
}

async function editarBoton(boton: TpvBoton) {
  if (!tpv.contexto || !puede('tpv', 'editar')) {
    tpv.error = 'Su rol no tiene permiso para configurar los botones'
    return
  }
  try {
    nivelesConfigurables.value = await obtenerNivelesTeclado(tpv.contexto.tecladoGeneral)
    botonConfigurando.value = boton
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudieron cargar los grupos del teclado')
  }
}

async function guardarConfiguracionBoton(asignacion: TpvBotonAsignacion) {
  if (!tpv.contexto || !nivelData.value || !botonConfigurando.value) return
  try {
    const nivelDestino = await guardarBotonTeclado(
      tpv.contexto.tecladoGeneral,
      nivelData.value.nivel,
      botonConfigurando.value.tecla,
      asignacion
    )
    botonConfigurando.value = null

    // Un grupo nuevo nace vacío: entramos en él para que se vea qué hay que rellenar.
    if (asignacion.tipo === 'nivel' && asignacion.crearGrupo && nivelDestino) {
      stackNiveles.value.push(nivelDestino)
      await cargarNivel(nivelDestino)
      return
    }
    await cargarNivel(nivelData.value.nivel)
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo guardar el botón')
  }
}

async function borrarConfiguracionBoton() {
  if (!tpv.contexto || !nivelData.value || !botonConfigurando.value) return
  try {
    await borrarBotonTeclado(
      tpv.contexto.tecladoGeneral,
      nivelData.value.nivel,
      botonConfigurando.value.tecla
    )
    botonConfigurando.value = null
    await cargarNivel(nivelData.value.nivel)
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo dejar vacío el botón')
  }
}

function cancelarConfiguracionBoton() {
  botonConfigurando.value = null
  void foco()
}

/**
 * Deja la caja lista pero sin ticket: el número se pide en NUEVA VENTA para no
 * consumir contador de albaranes por el simple hecho de entrar en el TPV.
 */
async function abrirCaja() {
  if (!puede('tpv', 'ver')) {
    initError.value = 'Sin permiso para usar el TPV'
    return
  }

  initError.value = null
  try {
    await puestoStore.hydrateFromApi()
    if (!puestoStore.configurado || !puestoStore.empresaCodigo || !puestoStore.puestoCodigo) {
      initError.value = 'Configure empresa y puesto del equipo antes de abrir el TPV'
      return
    }
    await tpv.abrirCaja(puestoStore.empresaCodigo, puestoStore.puestoCodigo)
    stackNiveles.value = ['000']
    await cargarNivel('000')
    await foco()
  } catch (e: unknown) {
    initError.value = extractApiError(e, 'No se pudo iniciar el TPV')
  }
}

/** Cierra el borrador actual de forma segura y propone un número nuevo. */
async function nuevaVenta() {
  if (
    tpv.lineas.length > 0 &&
    !window.confirm('Se anulará el ticket en curso sin cobrar. ¿Iniciar una venta nueva?')
  ) {
    return
  }
  if ((tpv.lineas.length > 0 || tpv.ventaGrabada) && !(await tpv.anularVentaActual())) {
    await foco()
    return
  }
  seleccion.value = -1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  cobrado.value = null
  if (!tpv.cajaAbierta) {
    await abrirCaja()
    if (!tpv.cajaAbierta) return
  }
  await tpv.abrirTicket()
  await foco()
}

function pedirAnularVenta() {
  confirmarAnulacion.value = true
}

/** Anular deja la caja sin ticket: el siguiente número se pide en NUEVA VENTA. */
async function confirmarAnularVenta() {
  confirmarAnulacion.value = false
  if (!(await tpv.anularVentaActual())) {
    await foco()
    return
  }
  seleccion.value = -1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  cobrado.value = null
  await foco()
}

function cancelarAnulacion() {
  confirmarAnulacion.value = false
  void foco()
}

function abrirAbono() {
  if (tpv.lineas.length || tpv.ventaGrabada) {
    tpv.error = 'Finalice o anule la compra actual antes de realizar un abono'
    return
  }
  if (!puede('ventas', 'crear')) {
    tpv.error = 'Su rol no tiene permiso para crear abonos (ventas.crear)'
    return
  }
  abonoAbierto.value = true
}

async function onAbonoCreado(abono: VentaDetalle) {
  abonoAbierto.value = false
  await router.push(
    `/ventas/${encodeURIComponent(abono.empresa)}/${encodeURIComponent(abono.tipo)}/${abono.albaran}`
  )
}

function cancelarAbono() {
  abonoAbierto.value = false
  void foco()
}

/** En caja táctil no hay teclado físico para teclear un código a mano. */
async function onCodigoTecleado(codigo: string) {
  codigoTecladoAbierto.value = false
  codigoManual.value = codigo
  await onCodigoIntro()
}

function cancelarCodigoTeclado() {
  codigoTecladoAbierto.value = false
  void foco()
}

/** El cursor vive en el campo de código: la pistola escribe ahí sin tocar nada. */
async function foco() {
  if (
    modalPrecio.value.open ||
    editandoDescuentoLinea.value >= 0 ||
    cobroAbierto.value ||
    confirmarAnulacion.value ||
    abonoAbierto.value ||
    codigoTecladoAbierto.value ||
    botonConfigurando.value !== null ||
    postVentaOpen.value ||
    clienteAbierto.value
  ) {
    return
  }
  await nextTick()
  inputCodigo.value?.focus()
}

const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  codigoManual.value = ''
  await anadirCodigo(codigo)
})

function onCodigoInput() {
  barcodeWatcher.onInput(codigoManual.value)
}

async function anadirCodigo(codigo: string) {
  const cantidad = Number(cantidadTecleada.value) || 1
  cantidadTecleada.value = ''
  await tpv.anadirPorCodigo(codigo, cantidad)
  const i = tpv.lineas.length - 1
  if (i >= 0 && !tpv.pendientePrecio) seleccion.value = i
  await foco()
}

async function onCodigoIntro() {
  barcodeWatcher.cancel()
  const codigo = codigoManual.value.trim()
  if (!codigo) return
  codigoManual.value = ''
  await anadirCodigo(codigo)
}

async function onBoton(b: TpvBoton) {
  if (b.tipo === 'nivel' && b.nivelDestino) {
    stackNiveles.value.push(b.nivelDestino)
    await cargarNivel(b.nivelDestino)
    return
  }
  if (b.tipo === 'articulo' && b.articulo) {
    // Navegar por los grupos sí se permite sin ticket; vender, no.
    if (!tpv.ticketListo) {
      tpv.error = 'Pulse NUEVA VENTA para abrir un ticket'
      await foco()
      return
    }
    const cantidad = Number(cantidadTecleada.value) || 1
    cantidadTecleada.value = ''
    await tpv.anadirArticulo(b.articulo, cantidad)
    const i = tpv.lineas.findIndex((l) => l.articulo === b.articulo)
    if (i >= 0) seleccion.value = i
    // Legacy: tras vender, el teclado vuelve al nivel indicado en H_NIVOB si existe.
    if (b.nivelVolver && b.nivelVolver !== stackNiveles.value[stackNiveles.value.length - 1]) {
      stackNiveles.value.push(b.nivelVolver)
      await cargarNivel(b.nivelVolver)
    }
    await foco()
  }
}

async function volverNivel() {
  if (!puedeVolver.value) return
  stackNiveles.value.pop()
  const prev = stackNiveles.value[stackNiveles.value.length - 1] ?? '000'
  await cargarNivel(prev)
  await foco()
}

function pulsarDigito(d: string) {
  if (cantidadTecleada.value.length >= 4) return
  cantidadTecleada.value = (cantidadTecleada.value + d).replace(/^0+(?=\d)/, '')
  void foco()
}

function borrarDigito() {
  cantidadTecleada.value = cantidadTecleada.value.slice(0, -1)
  void foco()
}

function limpiarCantidad() {
  cantidadTecleada.value = ''
  void foco()
}

async function borrarLinea() {
  if (!lineaSeleccionada.value) return
  const i = seleccion.value
  await tpv.quitarLinea(i)
  seleccion.value = Math.min(i, tpv.lineas.length - 1)
  await foco()
}

async function cambiarCantidad(delta: number) {
  if (!lineaSeleccionada.value) return
  await tpv.cambiarCantidad(seleccion.value, delta)
  if (seleccion.value >= tpv.lineas.length) {
    seleccion.value = tpv.lineas.length - 1
  }
  await foco()
}

function onSeleccionarLinea(indice: number) {
  seleccion.value = indice
  void foco()
}

function abrirPrecioLinea() {
  if (!lineaSeleccionada.value) return
  editandoPrecioLinea.value = seleccion.value
}

function abrirDescuentoLinea() {
  // Sin feedback el cajero no distingue "no tengo permiso" de "no hay línea marcada".
  if (!tpv.lineas.length) {
    tpv.error = 'Añada un artículo antes de aplicar un descuento'
    return
  }
  if (!lineaSeleccionada.value) {
    tpv.error = 'Toque en el ticket la línea a la que aplicar el descuento'
    return
  }
  if (!puede('tpv', 'editar')) {
    tpv.error = 'Su rol no tiene permiso para aplicar descuentos (tpv.editar)'
    return
  }
  editandoDescuentoLinea.value = seleccion.value
}

async function onDescuentoConfirmado(descuento: number) {
  const indice = editandoDescuentoLinea.value
  editandoDescuentoLinea.value = -1
  await tpv.cambiarDescuentoLinea(indice, descuento)
  await foco()
}

function onDescuentoCancelado() {
  editandoDescuentoLinea.value = -1
  void foco()
}

async function onPrecioConfirmado(precio: number) {
  if (tpv.pendientePrecio) {
    await tpv.confirmarPrecioPendiente(precio)
    seleccion.value = tpv.lineas.length - 1
    await foco()
    return
  }
  const i = editandoPrecioLinea.value
  editandoPrecioLinea.value = -1
  await tpv.cambiarPrecioLinea(i, precio)
  await foco()
}

function onPrecioCancelado() {
  if (tpv.pendientePrecio) tpv.cancelarPrecioPendiente()
  editandoPrecioLinea.value = -1
  void foco()
}

const puedeCobrar = computed(() => tpv.lineas.length > 0 && tpv.ventaGrabada && !tpv.guardando)
const permiteFactura = computed(() => {
  const c = tpv.cliente
  return Boolean(c?.codigo && c.razonSocial.trim() && c.nif.trim().length >= 7)
})

function abrirCobro() {
  if (!puedeCobrar.value) return
  cobrado.value = null
  cobroAbierto.value = true
}

async function onCobroConfirmado(datos: {
  tipoDocumento: string
  formaPago: string
  entregado: number
}) {
  const cerrada = await tpv.cobrar(datos.tipoDocumento, datos.formaPago)
  if (!cerrada) return

  const cambio = datos.entregado > 0 ? datos.entregado - tpv.total : 0
  const etiquetas: Record<string, string> = {
    T: 'Ticket',
    A: 'Albaran',
    P: 'Presupuesto',
    F: 'Factura',
  }
  const etiqueta = etiquetas[datos.tipoDocumento] ?? 'Documento'
  const numero =
    datos.tipoDocumento === 'T' || datos.tipoDocumento === 'F'
      ? cerrada.factura ?? tpv.numeroTicket
      : cerrada.albaran
  cobroAbierto.value = false
  ultimoTicket.value = cerrada
  ultimoTipoDocumento.value = datos.tipoDocumento
  ultimoDocumento.value = `${etiqueta} ${numero}`
  errorImpresion.value = null
  cobrado.value =
    cambio > 0
      ? `${etiqueta} ${numero} finalizado. Cambio ${cambio.toFixed(2).replace('.', ',')} €`
      : `${etiqueta} ${numero} finalizado`

  postVentaError.value = null
  postVentaOpen.value = true

  // La caja queda libre: el número del siguiente ticket se pide en NUEVA VENTA.
  seleccion.value = -1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  tpv.cerrarTicket()
}

async function imprimirTrasCobro() {
  postVentaOpen.value = false
  if (ultimoTipoDocumento.value === 'T') {
    await imprimirTicket()
  } else if (ultimoTicket.value) {
    await prepararDocumentoA4(ultimoTicket.value)
  }
}

async function enviarTrasCobro(email: string) {
  const venta = ultimoTicket.value
  if (!venta || postVentaEnviando.value) return
  postVentaEnviando.value = true
  postVentaError.value = null
  try {
    const res = await enviarVentaPorEmail(venta.empresa, venta.tipo, venta.albaran, email, 'tpv')
    postVentaOpen.value = false
    cobrado.value = `${res.documento} enviado a ${res.destinatario}`
  } catch (e: unknown) {
    postVentaError.value = extractApiError(e, 'No se pudo enviar el documento por email')
  } finally {
    postVentaEnviando.value = false
  }
}

/** Albarán, presupuesto y factura usan la misma previsualización A4 de Gestión. */
async function prepararDocumentoA4(venta: VentaDetalle) {
  imprimiendo.value = true
  errorImpresion.value = null
  try {
    const res = await prepararOImprimirVenta(venta, {
      puestoCodigo: puestoStore.puestoCodigo ?? '',
    })
    if (res.kind === 'a4') {
      await mostrarPreviewA4(res.prep)
    }
  } catch (e: unknown) {
    errorImpresion.value = extractApiError(e, 'No se pudo preparar el documento')
  } finally {
    imprimiendo.value = false
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

async function imprimirDocumentoA4() {
  if (!ultimoTicket.value || !a4Prep.value || imprimiendo.value) return
  imprimiendo.value = true
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    await imprimirA4Preparado(a4Prep.value, html, ultimoTicket.value)
    preview.cerrar()
    a4Open.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo imprimir el documento')
    errorImpresion.value = msg
    preview.notificarError(msg)
  } finally {
    imprimiendo.value = false
  }
}

/** Manda el ticket a la térmica del puesto (mismo camino que Ventas). */
async function imprimirTicket() {
  const venta = ultimoTicket.value
  if (!venta || imprimiendo.value) return
  imprimiendo.value = true
  errorImpresion.value = null
  try {
    const res = await prepararOImprimirVenta(venta, {
      puestoCodigo: puestoStore.puestoCodigo ?? '',
    })
    if (res.kind !== 'ticket') {
      errorImpresion.value = 'El documento no salió como ticket térmico; revise la plantilla'
    }
  } catch (e: unknown) {
    // El cobro ya está cerrado: un fallo de impresora no puede parecer un fallo de venta.
    errorImpresion.value = extractApiError(e, 'No se pudo imprimir el ticket')
  } finally {
    imprimiendo.value = false
  }
}

async function reimprimirUltimo() {
  if (!ultimoTicket.value) return
  if (ultimoTipoDocumento.value === 'T') {
    await imprimirTicket()
  } else {
    await prepararDocumentoA4(ultimoTicket.value)
  }
}

function onCobroCancelado() {
  cobroAbierto.value = false
  void foco()
}

const etiquetaCliente = computed(() => {
  const c = tpv.cliente
  if (!c) return `Sin cliente · ${CLIENTE_RAPIDO_TPV}`
  return c.razonSocial || c.codigo
})

async function onClienteSeleccionado(cli: TpvCliente) {
  clienteAbierto.value = false
  await tpv.asignarCliente(cli)
  await foco()
}

async function onClienteQuitado() {
  clienteAbierto.value = false
  await tpv.asignarCliente(null)
  await foco()
}

function onClienteCancelado() {
  clienteAbierto.value = false
  void foco()
}

/** Los botones no roban el foco: la pistola siempre escribe en el campo de código. */
function mantenerFoco(evento: MouseEvent) {
  const destino = evento.target as HTMLElement | null
  if (destino?.closest('input, textarea, select')) return
  evento.preventDefault()
}

async function salir() {
  if (
    (tpv.lineas.length > 0 || tpv.ventaGrabada) &&
    !window.confirm('Hay una venta sin cobrar. ¿Anularla y salir del TPV?')
  ) {
    return
  }
  if ((tpv.lineas.length > 0 || tpv.ventaGrabada) && !(await tpv.anularVentaActual())) {
    await foco()
    return
  }
  await router.push({ name: 'home' })
}

watch(
  () => tpv.lineas.length,
  (n) => {
    if (n === 0) seleccion.value = -1
    else if (seleccion.value < 0) seleccion.value = n - 1
    if (n > 0) cobrado.value = null
  }
)

onMounted(() => {
  void abrirCaja()
})
</script>

<template>
  <section class="tpv">
    <header class="tpv-titulo">
      <span class="txt">{{ cabecera }}</span>
      <span class="ticket-num" :class="{ 'sin-num': tpv.numeroTicket === null }">
        {{ etiquetaTicket }}
      </span>
      <span class="cliente-num">{{ etiquetaCliente }}</span>
      <span v-if="nivelData" class="nivel">
        {{ nivelData.nombre }} — nivel {{ nivelData.nivel }}
      </span>
    </header>

    <p v-if="tpv.loading" class="aviso">Abriendo caja…</p>
    <p v-else-if="initError" class="aviso error">{{ initError }}</p>

    <template v-else-if="tpv.cajaAbierta">
      <div class="tpv-cuerpo" @mousedown="mantenerFoco">
        <TpvTicketPanel
          class="col-ticket"
          :lineas="tpv.lineas"
          :total="tpv.totalFormateado"
          :seleccion="seleccion"
          :guardando="tpv.guardando"
          @seleccionar="onSeleccionarLinea"
        />

        <div class="col-centro">
          <div class="barra-codigo">
            <label for="tpv-codigo">CODIGO / EAN</label>
            <div class="barra-codigo-entrada">
              <input
                id="tpv-codigo"
                ref="inputCodigo"
                v-model="codigoManual"
                type="text"
                autocomplete="off"
                spellcheck="false"
                :disabled="!tpv.ticketListo"
                :placeholder="
                  tpv.ticketListo ? 'Codigo o pistola, Intro' : 'Pulse NUEVA VENTA'
                "
                @input="onCodigoInput"
                @keydown.enter.prevent="onCodigoIntro"
              />
              <button
                type="button"
                class="btn-legacy"
                :disabled="!tpv.ticketListo || tpv.guardando"
                title="Teclear el código sin teclado físico"
                @click="codigoTecladoAbierto = true"
              >
                TECLADO
              </button>
            </div>
          </div>

          <p v-if="tpv.error" class="aviso error linea-error">{{ tpv.error }}</p>
          <p v-else-if="tpv.aviso" class="aviso linea-aviso">{{ tpv.aviso }}</p>
          <p v-else-if="cobrado" class="aviso linea-ok">
            <span>{{ cobrado }}</span>
            <span v-if="errorImpresion" class="impresion-ko">{{ errorImpresion }}</span>
            <button
              v-if="ultimoTicket"
              type="button"
              class="btn-legacy reimprimir"
              :disabled="imprimiendo"
              @click="reimprimirUltimo"
            >
              {{ imprimiendo ? 'IMPRIMIENDO…' : errorImpresion ? 'REINTENTAR' : 'REIMPRIMIR' }}
            </button>
          </p>

          <TpvTecladoGrid
            :nivel="nivelData"
            :cargando="cargandoTeclado"
            :configurando="configurandoBotones"
            @boton="onBoton"
            @editar="editarBoton"
          />

          <div class="acciones-centro">
            <button
              type="button"
              class="btn-legacy configurar"
              :class="{ activo: configurandoBotones }"
              :disabled="!puede('tpv', 'editar')"
              :title="!puede('tpv', 'editar') ? 'Sin permiso para configurar botones' : ''"
              @click="configurandoBotones = !configurandoBotones"
            >
              {{ configurandoBotones ? 'TERMINAR CONFIGURACIÓN' : 'CONFIGURAR BOTONES' }}
            </button>
            <button
              type="button"
              class="btn-legacy atras-centro"
              :disabled="!puedeVolver"
              @click="volverNivel"
            >
              ◄ ATRÁS
            </button>
          </div>
        </div>

        <div class="col-acciones">
          <div class="numerico">
            <div class="visor">
              <span class="visor-lbl">CANTIDAD</span>
              <span class="visor-val">{{ cantidadTecleada || '1' }}</span>
            </div>

            <div class="num-grid">
              <button
                v-for="d in ['7', '8', '9', '4', '5', '6', '1', '2', '3']"
                :key="d"
                type="button"
                class="btn-legacy num"
                @click="pulsarDigito(d)"
              >
                {{ d }}
              </button>
              <button type="button" class="btn-legacy num aux" @click="limpiarCantidad">C</button>
              <button type="button" class="btn-legacy num" @click="pulsarDigito('0')">0</button>
              <button type="button" class="btn-legacy num aux" @click="borrarDigito">←</button>
            </div>
          </div>

          <button
            type="button"
            class="btn-legacy nueva"
            :disabled="tpv.loading || tpv.guardando"
            @click="nuevaVenta"
          >
            NUEVA VENTA
          </button>

          <button
            type="button"
            class="btn-legacy anular"
            :disabled="!tpv.ticketListo || tpv.loading || tpv.guardando"
            @click="pedirAnularVenta"
          >
            ANULAR COMPRA
          </button>

          <button
            type="button"
            class="btn-legacy abono"
            :disabled="tpv.loading || tpv.guardando"
            @click="abrirAbono"
          >
            ABONO
          </button>

          <p class="linea-marcada" :class="{ vacia: !lineaSeleccionada }">
            {{ etiquetaLineaMarcada }}
          </p>

          <div class="par">
            <button
              type="button"
              class="btn-legacy"
              :disabled="!lineaSeleccionada || tpv.guardando"
              @click="cambiarCantidad(1)"
            >
              CANT +
            </button>
            <button
              type="button"
              class="btn-legacy"
              :disabled="!lineaSeleccionada || tpv.guardando"
              @click="cambiarCantidad(-1)"
            >
              CANT −
            </button>
          </div>

          <button
            type="button"
            class="btn-legacy"
            :disabled="!lineaSeleccionada || tpv.guardando"
            @click="borrarLinea"
          >
            BORRAR LINEA
          </button>

          <button
            type="button"
            class="btn-legacy"
            :disabled="!lineaSeleccionada || tpv.guardando"
            @click="abrirPrecioLinea"
          >
            PRECIO
          </button>

          <button
            type="button"
            class="btn-legacy"
            :class="{ 'con-cliente': !!tpv.cliente }"
            :disabled="!tpv.ticketListo || tpv.guardando"
            :title="etiquetaCliente"
            @click="clienteAbierto = true"
          >
            CLIENTE
          </button>
          <button
            type="button"
            class="btn-legacy"
            :disabled="!tpv.ticketListo || tpv.guardando"
            :title="etiquetaLineaMarcada"
            @click="abrirDescuentoLinea"
          >
            DTO.
          </button>

          <button
            type="button"
            class="btn-legacy cobrar"
            :disabled="!puedeCobrar"
            @click="abrirCobro"
          >
            COBRAR
          </button>
          <button type="button" class="btn-legacy salir" @click="salir">SALIR</button>
        </div>
      </div>
    </template>

    <!-- Sin contexto de caja no hay nada que operar: se ofrece reintento y
         salida en vez de dejar la pantalla en blanco. -->
    <div v-else class="sin-ticket">
      <p>{{ tpv.error || 'No se pudo abrir la caja de este puesto.' }}</p>
      <div class="sin-ticket-acciones">
        <button type="button" class="btn-legacy nueva" :disabled="tpv.loading" @click="abrirCaja">
          REINTENTAR
        </button>
        <button type="button" class="btn-legacy salir" @click="salir">SALIR</button>
      </div>
    </div>

    <TpvClienteModal
      :open="clienteAbierto"
      :actual="tpv.cliente"
      @seleccionar="onClienteSeleccionado"
      @quitar="onClienteQuitado"
      @cancelar="onClienteCancelado"
    />

    <TpvCobroModal
      :open="cobroAbierto"
      :total="tpv.total"
      :formas-pago="tpv.contexto?.formasPago ?? []"
      :permite-factura="permiteFactura"
      :guardando="tpv.guardando"
      @confirmar="onCobroConfirmado"
      @cancelar="onCobroCancelado"
    />

    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Documento'"
      :plantilla="a4Prep?.plantilla ?? null"
      :datos="a4Prep?.datos ?? null"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="imprimiendo"
      oculto
      @cerrar="a4Open = false"
      @imprimir="imprimirDocumentoA4"
    />

    <VentaPostFinalizacionModal
      :open="postVentaOpen"
      :documento="ultimoDocumento"
      :email-inicial="ultimoTicket?.email"
      :procesando="postVentaEnviando"
      :error="postVentaError"
      @imprimir="imprimirTrasCobro"
      @email="enviarTrasCobro"
      @omitir="postVentaOpen = false; foco()"
    />

    <TpvPrecioModal
      :open="modalPrecio.open"
      :titulo="modalPrecio.titulo"
      :articulo="modalPrecio.articulo"
      :descripcion="modalPrecio.descripcion"
      :cantidad="modalPrecio.cantidad"
      :precio-inicial="modalPrecio.precioInicial"
      @confirmar="onPrecioConfirmado"
      @cancelar="onPrecioCancelado"
    />

    <TpvDescuentoModal
      :open="editandoDescuentoLinea >= 0"
      :articulo="tpv.lineas[editandoDescuentoLinea]?.articulo ?? ''"
      :descripcion="tpv.lineas[editandoDescuentoLinea]?.descripcion ?? ''"
      :descuento-inicial="tpv.lineas[editandoDescuentoLinea]?.pjeDto ?? 0"
      @confirmar="onDescuentoConfirmado"
      @cancelar="onDescuentoCancelado"
    />

    <ConfirmDialog
      :open="confirmarAnulacion"
      title="Anular compra"
      message="Se eliminará completamente el ticket en curso y se limpiará la pantalla. Esta acción no se puede deshacer."
      confirm-label="Anular compra"
      cancel-label="Continuar venta"
      danger
      @confirm="confirmarAnularVenta"
      @cancel="cancelarAnulacion"
    />

    <TpvAbonoModal
      :open="abonoAbierto"
      :empresa="tpv.contexto?.empresa ?? ''"
      @creado="onAbonoCreado"
      @cancelar="cancelarAbono"
    />

    <TpvCodigoModal
      :open="codigoTecladoAbierto"
      :inicial="codigoManual"
      @confirmar="onCodigoTecleado"
      @cancelar="cancelarCodigoTeclado"
    />

    <TpvBotonConfigModal
      :open="botonConfigurando !== null"
      :boton="botonConfigurando"
      :nivel-actual="nivelData?.nivel ?? '000'"
      :niveles="nivelesConfigurables"
      :tarifa="tpv.contexto?.tarifa ?? 1"
      @guardar="guardarConfiguracionBoton"
      @borrar="borrarConfiguracionBoton"
      @cancelar="cancelarConfiguracionBoton"
    />
  </section>
</template>

<style scoped>
.tpv {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  height: 100%;
  /* Sin padding: la barra de título va de borde a borde, igual que la cabecera
     global. La holgura la pone el cuerpo. */
  padding: 0;
  box-sizing: border-box;
  background: #d4d0c8;
  color: #000;
  font-family: 'Segoe UI', Tahoma, sans-serif;
}

.tpv-titulo {
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
  padding: 0.35rem 0.6rem;
  background: linear-gradient(#00309c, #000060);
  border: 2px outset #f0f0f0;
  color: #fff;
}

.txt {
  font-size: 0.9rem;
  font-weight: 700;
}

.ticket-num,
.cliente-num {
  padding: 0.05rem 0.4rem;
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid #7a8ec0;
  font-size: 0.8rem;
  font-weight: 700;
}

/* Sin número reservado: se ve que la caja está en espera de NUEVA VENTA. */
.ticket-num.sin-num {
  background: rgba(0, 0, 0, 0.25);
  border-color: #5a6a96;
  color: #c3cdea;
  font-weight: 400;
  font-style: italic;
}

.cliente-num {
  max-width: 22rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.nivel {
  margin-left: auto;
  font-size: 0.78rem;
  color: #c3cdea;
}

.btn-atras {
  padding: 0.15rem 0.6rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: inherit;
  font-size: 0.75rem;
  font-weight: 700;
  cursor: pointer;
}

.btn-atras:active {
  border-style: inset;
}

.aviso {
  margin: 0;
  padding: 0.75rem;
  font-size: 0.9rem;
  color: #303030;
}

.aviso.error {
  color: #8b0000;
  font-weight: 600;
}

.linea-ok {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.35rem 0.5rem;
  background: #d8f0d8;
  border: 1px solid #2e7d32;
  font-size: 0.85rem;
  font-weight: 700;
}

.impresion-ko {
  flex: 1;
  color: #8b0000;
  font-size: 0.78rem;
}

.reimprimir {
  margin-left: auto;
  min-height: 2rem;
  font-size: 0.72rem;
}

.linea-aviso {
  padding: 0.4rem 0.6rem;
  background: #fff6d0;
  border: 1px solid #b09000;
  font-size: 0.82rem;
}

/* Junto a la barra de código: un fallo al añadir no puede pasar desapercibido. */
.linea-error {
  padding: 0.4rem 0.6rem;
  background: #ffd0cc;
  border: 2px solid #a00000;
  font-size: 0.85rem;
  font-weight: 700;
}

.tpv-cuerpo {
  flex: 1;
  display: grid;
  grid-template-columns: minmax(24rem, 46%) 1fr 1fr;
  gap: 4px;
  margin: 4px;
  min-height: 0;
}

.col-ticket {
  min-height: 0;
}

/* Contenido pegado arriba: el teclado no se estira hasta el fondo. */
.col-centro {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-height: 0;
  min-width: 0;
}

/* Los botones de artículos llegan hasta abajo. */
.col-centro > .teclado {
  flex: 1 1 auto;
}

.acciones-centro {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 4px;
  flex: 0 0 auto;
}

.acciones-centro .btn-legacy {
  min-height: 2.8rem;
}

.acciones-centro .configurar {
  background: #d0dcf0;
}

.acciones-centro .configurar.activo {
  background: #ffd36a;
  border-style: inset;
}

.acciones-centro .atras-centro {
  min-width: 7.5rem;
}

/*
 * Etiqueta arriba y entrada abajo: en una sola fila, la columna central
 * (~289 px a 1024) dejaba al input poco más de 110 px de ancho útil.
 */
.barra-codigo {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 4px 5px;
  background: #d4d0c8;
  border: 2px inset #f0f0f0;
}

.barra-codigo label {
  font-size: 0.72rem;
  font-weight: 700;
  color: #303030;
}

.barra-codigo-entrada {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: stretch;
  gap: 6px;
}

/* Entrada principal de la caja: es lo que debe dominar la barra. */
.barra-codigo input {
  min-width: 0;
  min-height: 2.9rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 2px inset #f0f0f0;
  font-family: Consolas, monospace;
  font-size: 1.45rem;
  color: #000;
}

.barra-codigo input::placeholder {
  font-size: 0.8rem;
}

.barra-codigo .btn-legacy {
  min-width: 5.5rem;
}

.barra-codigo input:focus {
  outline: 2px solid #00309c;
  outline-offset: -2px;
}

/* Croquis: teclado numérico arriba y funciones apiladas debajo, ocupando todo el alto. */
.col-acciones {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
  min-height: 0;
  overflow: auto;
}

/* flex-shrink 0: el pad nunca se recorta, aunque falte alto en la pantalla. */
.numerico {
  display: flex;
  flex: 1 0 auto;
  flex-direction: column;
  gap: 4px;
  padding: 3px;
  background: #d4d0c8;
  border: 2px inset #f0f0f0;
}

.col-acciones > .btn-legacy,
.par {
  flex: 1 1 auto;
  min-height: 2.4rem;
}

.col-acciones > .btn-legacy,
.par .btn-legacy {
  font-size: 0.9rem;
}

.par {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px;
}

/* Deja claro sobre qué línea actúan CANT, PRECIO, DTO. y BORRAR. */
.linea-marcada {
  flex: 0 0 auto;
  margin: 0;
  padding: 0.25rem 0.4rem;
  background: #000080;
  border: 2px inset #f0f0f0;
  color: #fff;
  font-size: 0.72rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.linea-marcada.vacia {
  background: #b0aca4;
  color: #4a4a4a;
  font-style: italic;
}

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  padding: 0.3rem 0.5rem;
  background: #000;
  border: 2px inset #808080;
  color: #4ade80;
  font-family: Consolas, monospace;
}

.visor-lbl {
  font-size: 0.7rem;
  opacity: 0.8;
}

.visor-val {
  margin-left: auto;
  font-size: 2rem;
  font-weight: 700;
}

.num-grid {
  display: grid;
  flex: 1;
  grid-template-columns: repeat(3, 1fr);
  grid-template-rows: repeat(4, minmax(3rem, 1fr));
  gap: 4px;
  min-height: 0;
}

.btn-legacy {
  min-height: 2.7rem;
  padding: 0.3rem 0.5rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: inherit;
  font-size: 0.82rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  cursor: pointer;
  touch-action: manipulation;
}

.btn-legacy:active:not(:disabled) {
  border-style: inset;
}

.btn-legacy:disabled {
  color: #8a8a8a;
  text-shadow: 1px 1px 0 #fff;
  cursor: not-allowed;
}

.num {
  font-size: 1.9rem;
}

.aux {
  background: #e8e0d0;
  font-size: 1.4rem;
}

.nueva {
  background: #d0dcf0;
}

.anular {
  background: #f0c8c8;
  color: #7a0000;
}

.abono {
  background: #f4dfb8;
  color: #633c00;
}

.con-cliente {
  background: #e0e8f8;
  border-style: inset;
}

.col-acciones > .cobrar {
  min-height: 3.4rem;
  background: #c8e0c8;
  font-size: 1.05rem;
}

.salir {
  background: #e0c8c8;
}

.sin-ticket {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  text-align: center;
}

.sin-ticket p {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #303030;
}

.sin-ticket-acciones {
  display: flex;
  gap: 6px;
}

.sin-ticket-acciones .btn-legacy {
  min-width: 10rem;
  min-height: 3rem;
}

/*
 * A 1024x768 el ancho no da para el ticket al 46%: se reparte mejor dejando
 * dos columnas de acciones más holgadas. El tamaño lo resuelve el escalado
 * proporcional (ver bloque global), no recortes sueltos.
 */
@media (max-width: 1200px) {
  .tpv-cuerpo {
    grid-template-columns: minmax(19rem, 40%) 1fr 1fr;
  }

  .cliente-num {
    max-width: 14rem;
  }

  .acciones-centro .atras-centro {
    min-width: 5.5rem;
  }
}
</style>

<style>
/*
 * El TPV usa la escala global (`--escala-ui` en style.css) con un factor algo
 * más ajustado: es la pantalla más densa (ticket + teclado + pad numérico + 10
 * funciones), así que necesita algo más de margen vertical que el resto.
 *
 * El selector depende de `.tpv`, de modo que al salir de la caja —o al cambiar
 * de pestaña, que desmonta el DOM aunque KeepAlive conserve la venta— la
 * escala vuelve sola a la del resto de la aplicación.
 */
html:has(.tpv) {
  --escala-ui: min(1.65vh, 1.35vw);
}
</style>
