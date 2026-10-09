<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  borrarBotonTeclado,
  contarSubnivelesTeclado,
  guardarBotonTeclado,
  intercambiarBotonesTeclado,
  obtenerNivelTeclado,
  obtenerVentaTpv,
} from '@/api/tpv'
import {
  enviarVentaPorEmail,
  registrarAutorizacionTarjetaAlbaran,
  puntosCanjeDisponible,
  valeFidelizacionDisponible,
} from '@/api/ventas'
import TpvDevolucionDatafonoModal from '@/components/tpv/TpvDevolucionDatafonoModal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import TpvAbonoModal from '@/components/tpv/TpvAbonoModal.vue'
import TpvTicketFacturaModal from '@/components/tpv/TpvTicketFacturaModal.vue'
import TpvArticuloBuscarModal from '@/components/tpv/TpvArticuloBuscarModal.vue'
import TpvBotonConfigModal from '@/components/tpv/TpvBotonConfigModal.vue'
import TpvClienteModal from '@/components/tpv/TpvClienteModal.vue'
import TpvCobroModal from '@/components/tpv/TpvCobroModal.vue'
import TpvCodigoModal from '@/components/tpv/TpvCodigoModal.vue'
import TpvConsultaVentasModal from '@/components/tpv/TpvConsultaVentasModal.vue'
import TpvDescripcionModal from '@/components/tpv/TpvDescripcionModal.vue'
import TpvDescuentoModal from '@/components/tpv/TpvDescuentoModal.vue'
import TpvOtrasFuncionesModal from '@/components/tpv/TpvOtrasFuncionesModal.vue'
import TpvPrecioModal from '@/components/tpv/TpvPrecioModal.vue'
import TpvTecladoGrid from '@/components/tpv/TpvTecladoGrid.vue'
import TpvTicketsEsperaModal from '@/components/tpv/TpvTicketsEsperaModal.vue'
import TpvTicketPanel from '@/components/tpv/TpvTicketPanel.vue'
import TpvTicketVisorModal from '@/components/tpv/TpvTicketVisorModal.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import VentaPostFinalizacionModal from '@/components/ventas/VentaPostFinalizacionModal.vue'
import {
  crearOperacionCobroDatafono,
  type DevolucionDatafonoContexto,
  type OperacionCobroDatafono,
} from '@/composables/cobroDatafono'
import { autorizacionDesdeXmlRedsys } from '@/composables/comprobanteTarjeta'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import { imprimirValesDeCierre } from '@/composables/comprobanteVale'
import { extractApiError } from '@/composables/extractApiError'
import {
  imprimirA4Preparado,
  imprimirCopiaEstablecimientoDatafono,
  pdfBase64DesdeHtmlPlantilla,
  pdfBase64TicketVenta,
  prepararOImprimirVenta,
  type PrepImpresionA4,
} from '@/composables/useImpresionVentaDocumento'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useTpvVentaStore } from '@/stores/tpvVenta'
import type {
  TpvArticuloPrecio,
  TpvBoton,
  TpvBotonAsignacion,
  TpvCliente,
  TpvFuncionExtra,
  TpvNivel,
  TpvTicketEspera,
  TpvZonaTeclado,
} from '@/types/tpv'
import { CLIENTE_RAPIDO_TPV, TPV_NIVEL_GRUPOS, TPV_NIVEL_INICIAL } from '@/types/tpv'
import type { VentaDetalle, VentaResumen } from '@/types/ventas'

const router = useRouter()
const { puede } = usePermisos()
const puestoStore = usePuestoContextoStore()
const tpv = useTpvVentaStore()
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => imprimirDocumentoA4() })

const initError = ref<string | null>(null)
const nivelData = ref<TpvNivel | null>(null)
const cargandoTeclado = ref(false)
/**
 * Camino recorrido por el teclado (legacy a_plu). Guarda la etiqueta del botón que
 * abrió cada nivel porque `DefPlus` no nombra los niveles. El primer paso es el grupo
 * de la columna izquierda; `nivelVuelta` es f_plu: el nivel al que se vuelve tras vender.
 */
type PasoTeclado = { nivel: string; etiqueta: string }
const NIVEL_RAIZ: PasoTeclado = { nivel: TPV_NIVEL_INICIAL, etiqueta: 'Venta rápida' }
const stackNiveles = ref<PasoTeclado[]>([{ ...NIVEL_RAIZ }])
const nivelVuelta = ref(TPV_NIVEL_INICIAL)
/**
 * Pantallas de complementos pendientes del último artículo (H_NIVOB obligatorias,
 * H_NIVOP opcionales). Mientras hay alguna, el teclado muestra esa pantalla.
 */
const complementos = ref<{ nivel: string; obligatorio: boolean }[]>([])
const complementoActual = computed(() => complementos.value[0] ?? null)
const seleccion = ref(-1)
const cantidadTecleada = ref('')
const editandoPrecioLinea = ref(-1)
const editandoDescuentoLinea = ref(-1)
const editandoDescripcionLinea = ref(-1)
const codigoManual = ref('')
const inputCodigo = ref<HTMLInputElement | null>(null)
const cobroAbierto = ref(false)
const cobrandoDatafono = ref(false)
const operacionDatafono = ref<OperacionCobroDatafono | null>(null)
const confirmarAnulacion = ref(false)
const abonoAbierto = ref(false)
const ticketFacturaAbierto = ref(false)
const codigoTecladoAbierto = ref(false)
const buscarArticuloAbierto = ref(false)
const buscarArticuloInicial = ref('')
const buscarArticuloCantidad = ref(1)
const otrasFuncionesAbierto = ref(false)
const consultaVentasAbierta = ref(false)
const consultaVentasError = ref<string | null>(null)
const ticketsEsperaAbierto = ref(false)
const visorTicketAbierto = ref(false)
const configurandoBotones = ref(false)
const botonConfigurando = ref<{ boton: TpvBoton; zona: TpvZonaTeclado } | null>(null)
const avisoBorrarBoton = ref<string | null>(null)
const moviendoBoton = ref<{ zona: TpvZonaTeclado; posicion: number } | null>(null)
const cobrado = ref<string | null>(null)
const clienteAbierto = ref(false)
/** Último ticket cerrado: permite reintentar la impresión sin rehacer la venta. */
const ultimoTicket = ref<VentaDetalle | null>(null)
const ultimoTipoDocumento = ref('T')
/** XML Redsys del último cobro con datáfono (para comprobante en el ticket). */
const ultimoReceiptDatafono = ref<string | null>(null)
/** Forma de pago sugerida al abrir cobro (p. ej. tras abono del ticket origen). */
const formaPagoSugeridaCobro = ref('')
const devolucionDatafonoAbierto = ref(false)
const devolucionAlbaranOrigen = ref(0)

type CobroPendienteTrasDevolucion = {
  datos: {
    tipoDocumento: string
    formaPago: string
    entregado: number
    valeCodigo?: number
    valeImporte?: number
    formaPago2?: string
  }
  importeDatafono: number
  aplicarValeFidelizacion: boolean
  aplicarPuntosFidelizacion: boolean
}

const cobroPendienteTrasDevolucion = ref<CobroPendienteTrasDevolucion | null>(null)

function mensajeErrorDevolucionRedsys(mensaje: string): string {
  const m = mensaje.trim()
  if (/TPV-PC0091|TPV-PC_EMV0001|operaci[oó]n original no existe|operacion especificada no existe/i.test(m)) {
    return (
      `${m} — Redsys no encuentra el cobro original. ` +
      'Revise el PED del ticket (no use OPE). Si el ticket es antiguo, copie también el RTS o busque el cobro en el TPV legacy.'
    )
  }
  return m
}

async function ejecutarCobroEnDatafono(
  importeDatafono: number,
  referencia: string,
  devolucionCtx: DevolucionDatafonoContexto
): Promise<boolean> {
  const contexto = tpv.contexto
  if (!contexto) {
    tpv.error = 'No hay contexto de TPV para el datáfono'
    return false
  }
  ultimoReceiptDatafono.value = null
  const operacion = crearOperacionCobroDatafono(
    contexto,
    importeDatafono,
    referencia,
    devolucionCtx
  )
  operacionDatafono.value = operacion
  cobrandoDatafono.value = true
  tpv.error = null
  try {
    const resultado = await operacion.ejecutar()
    if (!resultado.ok || !resultado.approved) {
      const base =
        resultado.message ||
        (importeDatafono < -0.005
          ? 'El datáfono no autorizó la devolución'
          : 'El datáfono no autorizó el cobro')
      tpv.error =
        importeDatafono < -0.005 ? mensajeErrorDevolucionRedsys(base) : base
      return false
    }
    if (resultado.receipt) {
      ultimoReceiptDatafono.value = resultado.receipt
    }
    return true
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo comunicar con el datáfono')
    return false
  } finally {
    cobrandoDatafono.value = false
    operacionDatafono.value = null
  }
}

async function onDevolucionDatafonoConfirmada(ctx: DevolucionDatafonoContexto) {
  devolucionDatafonoAbierto.value = false
  const pending = cobroPendienteTrasDevolucion.value
  cobroPendienteTrasDevolucion.value = null
  if (!pending || !tpv.contexto || !tpv.venta) {
    tpv.error = 'No se pudo continuar la devolución en datáfono'
    cobroAbierto.value = true
    return
  }
  const referencia = `VENTA-${tpv.contexto.empresa}-${tpv.venta.tipo}-${tpv.venta.albaran}`
  const ok = await ejecutarCobroEnDatafono(pending.importeDatafono, referencia, ctx)
  if (!ok) {
    cobroAbierto.value = true
    return
  }
  await finalizarVentaTrasCobro(
    pending.datos,
    pending.importeDatafono,
    pending.aplicarValeFidelizacion,
    pending.aplicarPuntosFidelizacion
  )
}

function onDevolucionDatafonoCancelada() {
  devolucionDatafonoAbierto.value = false
  cobroPendienteTrasDevolucion.value = null
  tpv.error = 'Devolución en datáfono cancelada'
  cobroAbierto.value = true
}
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

function etiquetaDocumentoConsulta(venta: VentaDetalle): string {
  const ft = String(venta.facturaTipo ?? '').trim().toUpperCase()
  const factura = Number(venta.factura ?? 0)
  if (ft === 'T' && factura > 0) return `Consulta · Ticket T-${factura}`
  if (ft === 'F' && factura > 0) return `Consulta · Factura F-${factura}`
  if (ft === 'A' && factura > 0) return `Consulta · Abono A-${factura}`
  if (ft === 'R') return `Consulta · Presupuesto ${venta.albaran}`
  return `Consulta · Albarán ${venta.tipo}-${venta.albaran}`
}

const etiquetaTicket = computed(() => {
  if (tpv.enConsulta && tpv.venta) return etiquetaDocumentoConsulta(tpv.venta)
  if (tpv.numeroTicket === null) return 'Sin ticket'
  return tpv.ventaGrabada ? `Ticket ${tpv.numeroTicket}` : `Ticket ${tpv.numeroTicket} (propuesto)`
})

const puedeVolver = computed(() => stackNiveles.value.length > 1)
const rutaTeclado = computed(() => stackNiveles.value.map((n) => n.etiqueta))
const grupoActual = computed(
  () => stackNiveles.value[stackNiveles.value.length - 1] ?? NIVEL_RAIZ
)
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

/**
 * Carga un nivel del teclado. Como legacy, si el nivel no tiene botones se vuelve al
 * de vuelta (f_plu); en modo configuración no, para poder rellenarlo.
 * @returns false si el nivel estaba vacío y no se ha abierto
 */
async function cargarNivel(nivel: string): Promise<boolean> {
  if (!tpv.contexto) return false
  cargandoTeclado.value = true
  try {
    const data = await obtenerNivelTeclado(tpv.contexto.tecladoGeneral, nivel)
    const vacio = !data.botones.length && nivel !== nivelVuelta.value
    if (vacio && !configurandoBotones.value && !complementoActual.value) return false
    nivelData.value = data
    return true
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo cargar el teclado')
    return false
  } finally {
    cargandoTeclado.value = false
  }
}

function etiquetaBoton(b: TpvBoton, por: string): string {
  return [b.etiqueta1, b.etiqueta2].filter(Boolean).join(' ') || por
}

/** Abre un nivel desde un botón de grupo; con F (grupoVuelta) pasa a ser el de vuelta. */
async function abrirNivel(nivel: string, etiqueta: string, vuelta: boolean) {
  const anterior = [...stackNiveles.value]
  stackNiveles.value.push({ nivel, etiqueta })
  if (!(await cargarNivel(nivel))) {
    stackNiveles.value = anterior
    return
  }
  if (vuelta) nivelVuelta.value = nivel
}

/** Pulsar un grupo de la columna izquierda: nivel Format(posición + 1, "000"). */
async function abrirGrupo(g: TpvBoton) {
  const nivel = g.nivelDestino ?? String(g.posicion + 1).padStart(3, '0')
  const anterior = { pila: [...stackNiveles.value], vuelta: nivelVuelta.value }
  stackNiveles.value = [{ nivel, etiqueta: etiquetaBoton(g, `Grupo ${g.posicion + 1}`) }]
  nivelVuelta.value = nivel
  if (!(await cargarNivel(nivel))) {
    stackNiveles.value = anterior.pila
    nivelVuelta.value = anterior.vuelta
  }
}

/** Tras vender: vuelta al nivel f_plu (el último grupo o grupo F abierto). */
async function volverTrasVender() {
  const i = stackNiveles.value.findIndex((p) => p.nivel === nivelVuelta.value)
  if (i < 0 || i === stackNiveles.value.length - 1) {
    if (nivelData.value?.nivel !== nivelVuelta.value) await cargarNivel(nivelVuelta.value)
    return
  }
  stackNiveles.value = stackNiveles.value.slice(0, i + 1)
  await cargarNivel(nivelVuelta.value)
}

/** Primera pantalla de complementos, o vuelta al nivel f_plu si no quedan. */
async function siguienteComplemento() {
  complementos.value = complementos.value.slice(1)
  if (complementoActual.value) {
    await cargarNivel(complementoActual.value.nivel)
    return
  }
  await volverTrasVender()
  await foco()
}

async function salirComplementos() {
  complementos.value = []
  await volverTrasVender()
  await foco()
}

async function iniciarComplementos(b: TpvBoton) {
  complementos.value = [
    ...(b.obligatorios ?? []).map((id) => ({ nivel: `O${id}`, obligatorio: true })),
    ...(b.opcionales ?? []).map((id) => ({ nivel: `P${id}`, obligatorio: false })),
  ]
  if (complementoActual.value) {
    await cargarNivel(complementoActual.value.nivel)
    return true
  }
  return false
}

async function editarBoton(boton: TpvBoton, zona: TpvZonaTeclado) {
  if (!tpv.contexto || !nivelData.value || !puede('tpv', 'editar')) {
    tpv.error = 'Su rol no tiene permiso para configurar los botones'
    return
  }
  const mover = moviendoBoton.value
  if (mover) {
    moviendoBoton.value = null
    if (mover.zona !== zona) {
      tpv.error = 'Un grupo solo se puede mover a otra casilla de grupos, y un botón a otra de botones'
      return
    }
    if (mover.posicion === boton.posicion) return
    try {
      await intercambiarBotonesTeclado(
        tpv.contexto.tecladoGeneral,
        zona === 'grupo' ? TPV_NIVEL_GRUPOS : nivelData.value.nivel,
        mover.posicion,
        boton.posicion
      )
      await cargarNivel(nivelData.value.nivel)
    } catch (e: unknown) {
      tpv.error = extractApiError(e, 'No se pudo mover el botón')
    }
    return
  }
  avisoBorrarBoton.value = null
  botonConfigurando.value = { boton, zona }
}

/** En configuración, tocar un grupo entra en su página para ponerle botones. */
async function entrarEnGrupo(boton: TpvBoton, zona: TpvZonaTeclado) {
  if (zona === 'grupo') {
    await abrirGrupo(boton)
    return
  }
  if (boton.nivelDestino) {
    await abrirNivel(boton.nivelDestino, etiquetaBoton(boton, 'Grupo'), boton.tipo === 'grupoVuelta')
  }
}

function nivelDeEdicion(zona: TpvZonaTeclado): string {
  return zona === 'grupo' ? TPV_NIVEL_GRUPOS : (nivelData.value?.nivel ?? TPV_NIVEL_INICIAL)
}

async function guardarConfiguracionBoton(asignacion: TpvBotonAsignacion) {
  const editando = botonConfigurando.value
  if (!tpv.contexto || !nivelData.value || !editando) return
  const { boton, zona } = editando
  try {
    const nivelDestino = await guardarBotonTeclado(
      tpv.contexto.tecladoGeneral,
      nivelDeEdicion(zona),
      boton.posicion,
      asignacion
    )
    botonConfigurando.value = null

    // Grupo nuevo: se entra en él para que se vea qué hay que rellenar.
    const etiqueta = [asignacion.etiqueta1, asignacion.etiqueta2].filter(Boolean).join(' ')
    if (nivelDestino && zona === 'grupo' && !boton.visible) {
      stackNiveles.value = [{ nivel: nivelDestino, etiqueta: etiqueta || `Grupo ${boton.posicion + 1}` }]
      nivelVuelta.value = nivelDestino
      await cargarNivel(nivelDestino)
      return
    }
    if (nivelDestino && zona === 'boton' && boton.tipo === 'vacio') {
      stackNiveles.value.push({ nivel: nivelDestino, etiqueta: etiqueta || 'Grupo' })
      await cargarNivel(nivelDestino)
      return
    }
    await cargarNivel(nivelData.value.nivel)
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo guardar el botón')
  }
}

async function borrarConfiguracionBoton(confirmado: boolean) {
  const editando = botonConfigurando.value
  if (!tpv.contexto || !nivelData.value || !editando) return
  const { boton, zona } = editando
  const general = tpv.contexto.tecladoGeneral
  const nivel = nivelDeEdicion(zona)
  const esGrupo = zona === 'grupo' || boton.tipo === 'grupo' || boton.tipo === 'grupoVuelta'
  try {
    if (esGrupo && !confirmado) {
      const dentro = await contarSubnivelesTeclado(general, nivel, boton.posicion)
      if (dentro > 0) {
        avisoBorrarBoton.value =
          `Este grupo tiene ${dentro} ${dentro === 1 ? 'botón' : 'botones'} dentro. ` +
          'Se borrarán también.'
        return
      }
    }
    await borrarBotonTeclado(general, nivel, boton.posicion)
    botonConfigurando.value = null
    avisoBorrarBoton.value = null

    // Si se estaba dentro del grupo borrado, se vuelve al principio.
    const prefijo =
      zona === 'grupo'
        ? String(boton.posicion + 1).padStart(3, '0')
        : boton.nivelDestino
    if (esGrupo && prefijo && nivelData.value.nivel.startsWith(prefijo)) {
      await volverAlInicio()
      return
    }
    await cargarNivel(nivelData.value.nivel)
  } catch (e: unknown) {
    tpv.error = extractApiError(e, 'No se pudo dejar vacío el botón')
  }
}

function moverConfiguracionBoton() {
  const editando = botonConfigurando.value
  if (!editando) return
  moviendoBoton.value = { zona: editando.zona, posicion: editando.boton.posicion }
  botonConfigurando.value = null
}

function cancelarConfiguracionBoton() {
  botonConfigurando.value = null
  avisoBorrarBoton.value = null
  void foco()
}

watch(configurandoBotones, async (activo) => {
  moviendoBoton.value = null
  // Al salir de configurar, un nivel que se quedó vacío vuelve al de vuelta, como legacy.
  if (!activo && nivelData.value && !nivelData.value.botones.length) await volverAlInicio()
})

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
    await volverAlInicio()
  } catch (e: unknown) {
    initError.value = extractApiError(e, 'No se pudo iniciar el TPV')
  }
}

/** Cierra el borrador actual de forma segura y propone un número nuevo. */
async function nuevaVenta() {
  if (tpv.enConsulta) tpv.cerrarConsulta()
  // Ya hay número reservado: otra pulsación consumiría otro albarán.
  if (tpv.ticketListo) return
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
    tpv.error = 'Finalice o anule la venta actual antes de realizar un abono'
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
  ultimoReceiptDatafono.value = null
  tpv.cargarVentaRecuperada(abono)
  const fp = String(abono.formasPago?.[0]?.codigo ?? '').trim()
  formaPagoSugeridaCobro.value = fp
  const importe = Number(abono.importe ?? tpv.total)
  const eurosTxt = Math.abs(importe).toFixed(2).replace('.', ',')
  tpv.aviso =
    `Abono ${abono.albaran} listo. Finalice como ticket y devuelva ${eurosTxt} €` +
    (fp ? ` (forma sugerida: ${fp}).` : '.')
  cobrado.value = null
  cobroAbierto.value = true
}

function cancelarAbono() {
  abonoAbierto.value = false
  void foco()
}

function abrirTicketFactura() {
  ticketFacturaAbierto.value = true
}

function cancelarTicketFactura() {
  ticketFacturaAbierto.value = false
  void foco()
}

async function onTicketConvertido(payload: {
  factura: VentaDetalle
  ticketNegativo: VentaDetalle | null
}) {
  ticketFacturaAbierto.value = false
  ultimoReceiptDatafono.value = null
  const negativo = payload.ticketNegativo?.factura
  cobrado.value =
    `Ticket compensado con el negativo T-${negativo ?? '?'}. Factura ${payload.factura.factura ?? ''}.`
  if (payload.ticketNegativo) {
    try {
      const res = await prepararOImprimirVenta(payload.ticketNegativo, {
        puestoCodigo: puestoStore.puestoCodigo ?? '',
        formato: 'ticket',
      })
      if (res.kind !== 'ticket') {
        errorImpresion.value = 'El ticket negativo no salió por la impresora de tickets'
      }
    } catch (e: unknown) {
      errorImpresion.value = extractApiError(
        e,
        'La factura está creada, pero no se pudo imprimir el ticket negativo'
      )
    }
  }
  ultimoTicket.value = payload.factura
  ultimoTipoDocumento.value = 'F'
  await prepararDocumentoA4(payload.factura)
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
    editandoDescripcionLinea.value >= 0 ||
    cobroAbierto.value ||
    devolucionDatafonoAbierto.value ||
    cobrandoDatafono.value ||
    confirmarAnulacion.value ||
    abonoAbierto.value ||
    ticketFacturaAbierto.value ||
    codigoTecladoAbierto.value ||
    buscarArticuloAbierto.value ||
    otrasFuncionesAbierto.value ||
    ticketsEsperaAbierto.value ||
    consultaVentasAbierta.value ||
    visorTicketAbierto.value ||
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

function esLineaNota(indice: number): boolean {
  return String(tpv.lineas[indice]?.articulo ?? '').trim().toUpperCase() === 'NO'
}

async function anadirCodigo(codigo: string) {
  const cantidad = Number(cantidadTecleada.value) || 1
  cantidadTecleada.value = ''
  const resultado = await tpv.anadirPorCodigo(codigo, cantidad)
  if (resultado === 'elegir') {
    buscarArticuloCantidad.value = cantidad
    buscarArticuloInicial.value = codigo
    buscarArticuloAbierto.value = true
    return
  }
  if (resultado === 'nota') {
    seleccion.value = tpv.lineas.length - 1
    editandoDescripcionLinea.value = seleccion.value
    return
  }
  const i = tpv.lineas.length - 1
  if (i >= 0 && !tpv.pendientePrecio) seleccion.value = i
  await foco()
}

function abrirBuscarArticulo() {
  if (!tpv.ticketListo) return
  buscarArticuloCantidad.value = Number(cantidadTecleada.value) || 1
  buscarArticuloInicial.value = codigoManual.value.trim()
  buscarArticuloAbierto.value = true
}

async function onArticuloBuscado(art: TpvArticuloPrecio) {
  buscarArticuloAbierto.value = false
  codigoManual.value = ''
  cantidadTecleada.value = ''
  await tpv.anadirArticulo(art.codigo, buscarArticuloCantidad.value)
  const i = tpv.lineas.length - 1
  if (i >= 0 && !tpv.pendientePrecio) seleccion.value = i
  await foco()
}

function cancelarBuscarArticulo() {
  buscarArticuloAbierto.value = false
  void foco()
}

async function anadirNota() {
  if (!(await tpv.anadirNota())) return
  seleccion.value = tpv.lineas.length - 1
  editandoDescripcionLinea.value = seleccion.value
}

async function onCodigoIntro() {
  barcodeWatcher.cancel()
  const codigo = codigoManual.value.trim()
  if (!codigo) return
  codigoManual.value = ''
  await anadirCodigo(codigo)
}

async function onBoton(b: TpvBoton, zona: TpvZonaTeclado) {
  if (tpv.enConsulta) return
  if (zona === 'grupo') {
    complementos.value = []
    await abrirGrupo(b)
    return
  }
  if ((b.tipo === 'grupo' || b.tipo === 'grupoVuelta') && b.nivelDestino) {
    await abrirNivel(b.nivelDestino, etiquetaBoton(b, 'Grupo'), b.tipo === 'grupoVuelta')
    return
  }
  if ((b.tipo !== 'articulo' || !b.articulo) && (b.tipo !== 'texto' || !b.texto)) return

  // Navegar por los grupos sí se permite sin ticket; vender, no.
  if (!tpv.ticketListo) {
    tpv.error = 'Pulse NUEVA VENTA para abrir un ticket'
    await foco()
    return
  }
  if (b.tipo === 'texto') {
    if (await tpv.anadirNota(b.texto ?? '')) seleccion.value = tpv.lineas.length - 1
  } else if (b.articulo) {
    const cantidad = Number(cantidadTecleada.value) || 1
    cantidadTecleada.value = ''
    await tpv.anadirArticulo(b.articulo, cantidad, b.pedirPrecio === true)
    const i = tpv.lineas.findIndex((l) => l.articulo === b.articulo)
    if (i >= 0) seleccion.value = i
  }

  // Dentro de complementos: la obligatoria pasa a la siguiente con una elección; la opcional
  // admite varias hasta SIGUIENTE.
  if (complementoActual.value) {
    if (complementoActual.value.obligatorio) await siguienteComplemento()
    await foco()
    return
  }
  if (!(await iniciarComplementos(b))) await volverTrasVender()
  await foco()
}

async function volverNivel() {
  if (!puedeVolver.value) return
  stackNiveles.value.pop()
  const actual = grupoActual.value.nivel
  // Si se sube por encima del grupo de vuelta, el de vuelta pasa a ser este.
  if (!stackNiveles.value.some((p) => p.nivel === nivelVuelta.value)) nivelVuelta.value = actual
  await cargarNivel(actual)
  await foco()
}

/** Primer grupo (nivel 001), como al abrir la caja en legacy. */
async function volverAlInicio() {
  complementos.value = []
  stackNiveles.value = [{ ...NIVEL_RAIZ }]
  nivelVuelta.value = TPV_NIVEL_INICIAL
  await cargarNivel(TPV_NIVEL_INICIAL)
  const g = nivelData.value?.grupos.find((x) => x.posicion === 0 && x.visible)
  if (g) stackNiveles.value = [{ nivel: TPV_NIVEL_INICIAL, etiqueta: etiquetaBoton(g, NIVEL_RAIZ.etiqueta) }]
  await foco()
}

function pulsarDigito(d: string) {
  if (tpv.enConsulta) return
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
  if (esLineaNota(seleccion.value)) {
    tpv.error = 'Una nota no tiene cantidad. Use DESCRIP. para escribir el texto'
    return
  }
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
  if (esLineaNota(seleccion.value)) {
    tpv.error = 'Una nota no tiene precio. Use DESCRIP. para escribir el texto'
    return
  }
  editandoPrecioLinea.value = seleccion.value
}

function abrirDescripcionLinea() {
  if (!lineaSeleccionada.value) return
  editandoDescripcionLinea.value = seleccion.value
}

async function onDescripcionConfirmada(descripcion: string) {
  const indice = editandoDescripcionLinea.value
  editandoDescripcionLinea.value = -1
  await tpv.cambiarDescripcionLinea(indice, descripcion)
  await foco()
}

function onDescripcionCancelada() {
  editandoDescripcionLinea.value = -1
  void foco()
}

function abrirDescuentoLinea() {
  // Sin feedback el cajero no distingue "no tengo permiso" de "no hay línea marcada".
  if (lineaSeleccionada.value && esLineaNota(seleccion.value)) {
    tpv.error = 'Una nota no admite descuento'
    return
  }
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
  formaPagoSugeridaCobro.value = ''
  cobroAbierto.value = true
}

function abrirAccionesConsulta() {
  const venta = tpv.venta
  if (!tpv.enConsulta || !venta) return
  ultimoTicket.value = venta
  const ft = String(venta.facturaTipo ?? '').trim().toUpperCase()
  ultimoTipoDocumento.value = ft === 'T' ? 'T' : ft === 'F' || ft === 'A' ? 'F' : 'A'
  ultimoDocumento.value = etiquetaDocumentoConsulta(venta).replace(/^Consulta · /, '')
  ultimoReceiptDatafono.value = null
  postVentaError.value = null
  postVentaOpen.value = true
}

async function finalizarVentaTrasCobro(
  datos: {
    tipoDocumento: string
    formaPago: string
    entregado: number
    valeCodigo?: number
    valeImporte?: number
    formaPago2?: string
  },
  importeDatafono: number,
  aplicarValeFidelizacion: boolean,
  aplicarPuntosFidelizacion: boolean
) {
  const contexto = tpv.contexto
  const formaTarjeta = datos.formaPago2
    ? contexto?.formasPago.find((item) => item.codigo === datos.formaPago2)
    : contexto?.formasPago.find((item) => item.codigo === datos.formaPago)
  const cerrada = await tpv.cobrar(datos.tipoDocumento, datos.formaPago, {
    aplicarValeFidelizacion,
    aplicarPuntosFidelizacion,
    ...(datos.valeCodigo ? { valeCodigo: datos.valeCodigo } : {}),
    ...(datos.formaPago2 ? { fpago2: datos.formaPago2 } : {}),
  })
  if (!cerrada) return

  if (
    ultimoReceiptDatafono.value &&
    importeDatafono > 0.005 &&
    contexto &&
    formaTarjeta?.datafono &&
    formaTarjeta.chipAcumuladoMenu
  ) {
    try {
      const auth = autorizacionDesdeXmlRedsys(
        ultimoReceiptDatafono.value,
        importeDatafono
      )
      if (auth) {
        await registrarAutorizacionTarjetaAlbaran(cerrada.empresa, cerrada.albaran, {
          puesto: contexto.puesto,
          sesion: Number(cerrada.sesion ?? contexto.sesion ?? 0),
          formaPago: datos.formaPago2 ? 2 : 1,
          ...auth,
        })
      }
    } catch {
      // El cobro ya está hecho; fallar el registro no debe bloquear la venta.
    }
  }

  const cobradoEnCaja = datos.valeCodigo
    ? Math.max(0, Number(cerrada.importe ?? tpv.total) - Number(datos.valeImporte ?? 0))
    : Number(cerrada.importe ?? tpv.total)
  const cambio = datos.entregado > 0 ? datos.entregado - cobradoEnCaja : 0
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
  formaPagoSugeridaCobro.value = ''
  ultimoTicket.value = cerrada
  ultimoTipoDocumento.value = datos.tipoDocumento
  ultimoDocumento.value = `${etiqueta} ${numero}`
  errorImpresion.value = null
  cobrado.value =
    cambio > 0
      ? `${etiqueta} ${numero} finalizado. Cambio ${cambio.toFixed(2).replace('.', ',')} €`
      : `${etiqueta} ${numero} finalizado`
  if (cerrada.valeFidelizacion?.aplicado) {
    const aplicado = cerrada.valeFidelizacion.aplicado.toFixed(2).replace('.', ',')
    const saldo = cerrada.valeFidelizacion.saldoRestante.toFixed(2).replace('.', ',')
    cobrado.value +=
      cerrada.valeFidelizacion.saldoRestante > 0
        ? `. Vale de fidelización aplicado: ${aplicado} €. Saldo restante: ${saldo} €.`
        : `. Vale de fidelización aplicado: ${aplicado} €. Vale agotado.`
  }
  if (cerrada.puntosCanje) {
    const euros = cerrada.puntosCanje.euros.toFixed(2).replace('.', ',')
    cobrado.value += `. Puntos usados: ${cerrada.puntosCanje.puntosUsables} (${euros} €).`
  }
  if (cerrada.valeAplicado?.aplicado) {
    const aplicado = cerrada.valeAplicado.aplicado.toFixed(2).replace('.', ',')
    cobrado.value += `. Vale ${cerrada.valeAplicado.codigo} aplicado: ${aplicado} €.`
    if (cerrada.valeAplicado.valeResto?.codigo) {
      const resto = Number(cerrada.valeAplicado.valeResto.importe).toFixed(2).replace('.', ',')
      cobrado.value += ` Resto en el vale ${cerrada.valeAplicado.valeResto.codigo}: ${resto} €.`
    }
  }
  if (cerrada.valeEmitido?.codigo) {
    const importeVale = Number(cerrada.valeEmitido.importe).toFixed(2).replace('.', ',')
    cobrado.value += `. Vale ${cerrada.valeEmitido.codigo} emitido por ${importeVale} €.`
  }
  const puestoVale = String(puestoStore.puestoCodigo ?? '').trim()
  if (puestoVale && (cerrada.valeEmitido?.codigo || cerrada.valeAplicado?.valeResto?.codigo)) {
    try {
      await imprimirValesDeCierre(puestoVale, cerrada)
    } catch (e: unknown) {
      errorImpresion.value = extractApiError(e, 'La venta está hecha, pero no se pudo imprimir el vale')
    }
  }
  if (cerrada.fidelizacionPuntos) {
    const compra = Number(cerrada.fidelizacionPuntos.compra)
    const acumulados = Number(cerrada.fidelizacionPuntos.acumulados)
    const compraTxt = cerrada.fidelizacionPuntos.fechaInicio
      ? compra.toFixed(2).replace('.', ',')
      : String(compra)
    const acumTxt = cerrada.fidelizacionPuntos.fechaInicio
      ? acumulados.toFixed(2).replace('.', ',')
      : String(acumulados)
    cobrado.value += cerrada.fidelizacionPuntos.fechaInicio
      ? `. Puntos de esta compra: ${compraTxt}. Acumulados del semestre: ${acumTxt}.`
      : `. Puntos de esta venta: ${compraTxt}. Acumulados: ${acumTxt}.`
  }

  postVentaError.value = null
  postVentaOpen.value = true

  // La caja queda libre: el número del siguiente ticket se pide en NUEVA VENTA.
  seleccion.value = -1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  tpv.cerrarTicket()
}

async function onCobroConfirmado(datos: {
  tipoDocumento: string
  formaPago: string
  entregado: number
  valeCodigo?: number
  valeImporte?: number
  formaPago2?: string
}) {
  const contexto = tpv.contexto
  const forma = datos.formaPago2
    ? contexto?.formasPago.find((item) => item.codigo === datos.formaPago2)
    : contexto?.formasPago.find((item) => item.codigo === datos.formaPago)
  const requierePago = datos.tipoDocumento === 'T' || datos.tipoDocumento === 'F'
  const totalCobro = Number(tpv.venta?.importe ?? tpv.total)
  let aplicarValeFidelizacion = false
  let aplicarPuntosFidelizacion = false
  let descuentoValePrevisto = 0
  let descuentoPuntosPrevisto = 0
  if (requierePago && totalCobro > 0.005 && contexto && tpv.cliente?.codigo) {
    try {
      const disponible = await valeFidelizacionDisponible(contexto.empresa, tpv.cliente.codigo)
      if (disponible.saldo > 0) {
        const aplicable = Math.min(disponible.saldo, totalCobro)
        aplicarValeFidelizacion = window.confirm(
          `Tiene ${disponible.saldo.toFixed(2).replace('.', ',')} € en vales de fidelización. ` +
            `¿Aplicar ${aplicable.toFixed(2).replace('.', ',')} € de descuento a esta compra?`
        )
        descuentoValePrevisto = aplicarValeFidelizacion ? aplicable : 0
      }
    } catch (e: unknown) {
      tpv.error = extractApiError(e, 'No se pudo consultar el vale de fidelización')
      return
    }
    try {
      const puntos = await puntosCanjeDisponible(
        contexto.empresa,
        tpv.cliente.codigo,
        totalCobro - descuentoValePrevisto
      )
      if (puntos.aplica && puntos.euros > 0) {
        const euros = puntos.euros.toFixed(2).replace('.', ',')
        aplicarPuntosFidelizacion = window.confirm(
          `Tiene ${puntos.puntos} puntos de fidelización. ` +
            `¿Usar ${puntos.puntosUsables} (${euros} €) en esta compra?`
        )
        descuentoPuntosPrevisto = aplicarPuntosFidelizacion ? puntos.euros : 0
      }
    } catch {
      aplicarPuntosFidelizacion = false
    }
  }
  const topeTrasFidelizacion = totalCobro - descuentoValePrevisto - descuentoPuntosPrevisto
  const descuentoValePapel = datos.valeCodigo
    ? Math.min(Number(datos.valeImporte ?? 0), Math.max(0, topeTrasFidelizacion))
    : 0
  const importeDatafono = topeTrasFidelizacion - descuentoValePapel
  const datafonoIntegrado = Boolean(forma?.datafono && forma.chipAcumuladoMenu)
  if (requierePago && datafonoIntegrado && Math.abs(importeDatafono) > 0.005) {
    if (!contexto || !tpv.venta) {
      tpv.error = 'No se puede identificar la venta para iniciar el datáfono'
      return
    }
    if (importeDatafono < -0.005) {
      cobroPendienteTrasDevolucion.value = {
        datos,
        importeDatafono,
        aplicarValeFidelizacion,
        aplicarPuntosFidelizacion,
      }
      devolucionAlbaranOrigen.value = Number(tpv.venta.albaranOrigenAbono ?? 0)
      cobroAbierto.value = false
      devolucionDatafonoAbierto.value = true
      return
    }
    const referencia = `VENTA-${contexto.empresa}-${tpv.venta.tipo}-${tpv.venta.albaran}`
    const ok = await ejecutarCobroEnDatafono(importeDatafono, referencia, {})
    if (!ok) return
  }

  await finalizarVentaTrasCobro(
    datos,
    importeDatafono,
    aplicarValeFidelizacion,
    aplicarPuntosFidelizacion
  )
}

async function imprimirTrasCobro() {
  postVentaOpen.value = false
  if (ultimoTipoDocumento.value === 'T') {
    await imprimirTicket()
  } else if (ultimoTicket.value) {
    await prepararDocumentoA4(ultimoTicket.value)
  }
}

async function pdfPlantillaParaEmail(venta: VentaDetalle): Promise<string | undefined> {
  const ft = String(venta.facturaTipo ?? '').trim().toUpperCase()
  if (ft === 'T' && Number(venta.factura) > 0) {
    return pdfBase64TicketVenta(venta, {
      puestoCodigo: puestoStore.puestoCodigo ?? '',
      receiptDatafono: ultimoReceiptDatafono.value,
    })
  }
  const res = await prepararOImprimirVenta(venta, {
    puestoCodigo: puestoStore.puestoCodigo ?? '',
    formato: 'a4',
  })
  if (res.kind !== 'a4') {
    throw new Error('No se pudo preparar la plantilla del documento')
  }
  const abierto = a4Open.value
  const prep = a4Prep.value
  try {
    a4Prep.value = res.prep
    a4Open.value = true
    await nextTick()
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    return await pdfBase64DesdeHtmlPlantilla(html)
  } finally {
    a4Open.value = abierto
    a4Prep.value = prep
  }
}

async function enviarTrasCobro(email: string) {
  const venta = ultimoTicket.value
  if (!venta || postVentaEnviando.value) return
  postVentaEnviando.value = true
  postVentaError.value = null
  try {
    const pdf = await pdfPlantillaParaEmail(venta)
    const res = await enviarVentaPorEmail(venta.empresa, venta.tipo, venta.albaran, email, 'tpv', pdf)
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
    try {
      await imprimirCopiaEstablecimientoDatafono(ultimoTicket.value, {
        puestoCodigo: puestoStore.puestoCodigo ?? '',
        receiptDatafono: ultimoReceiptDatafono.value,
      })
    } catch (error) {
      errorImpresion.value = extractApiError(
        error,
        'El documento se imprimió, pero no la copia del establecimiento'
      )
    }
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
      receiptDatafono: ultimoReceiptDatafono.value,
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

/**
 * Cajón OTRAS FUNCIONES: lo que no se usa en cada venta sale de la botonera
 * para dejarla despejada. Añadir una función es añadir una entrada aquí y su
 * caso en `onOtraFuncion`.
 */
const otrasFunciones = computed<TpvFuncionExtra[]>(() => [
  {
    id: 'espera',
    etiqueta: 'TICKET EN ESPERA',
    ayuda: 'Guardar la venta actual para continuarla después',
    deshabilitada: !tpv.ventaGrabada || !tpv.lineas.length || tpv.guardando,
    tono: 'activo',
  },
  {
    id: 'recuperar-espera',
    etiqueta: 'RECUPERAR TICKET EN ESPERA',
    ayuda: tpv.lineas.length
      ? 'Ponga primero la venta actual en espera o anúlela'
      : 'Continuar una venta guardada',
    deshabilitada: tpv.lineas.length > 0 || tpv.guardando,
  },
  {
    id: 'consulta',
    etiqueta: 'CONSULTA TICKETS',
    ayuda: 'Buscar un ticket, albarán, factura o presupuesto y verlo',
    deshabilitada: tpv.guardando,
  },
  {
    id: 'abono',
    etiqueta: 'ABONO',
    ayuda: 'Devolver un documento ya cobrado',
    deshabilitada: tpv.loading || tpv.guardando,
    tono: 'aviso',
  },
  {
    id: 'ticket-factura',
    etiqueta: 'TICKET A FACTURA',
    ayuda: 'Compensar un ticket ya cobrado y crear la factura de contado',
    deshabilitada: tpv.loading || tpv.guardando,
  },
  {
    id: 'codigo',
    etiqueta: 'TECLEAR CÓDIGO',
    ayuda: 'Introducir el código sin teclado físico',
    deshabilitada: !tpv.ticketListo || tpv.guardando,
  },
  {
    id: 'reimprimir',
    etiqueta: 'REIMPRIMIR',
    ayuda: ultimoDocumento.value || 'Todavía no se ha cerrado ningún documento',
    deshabilitada: !ultimoTicket.value || imprimiendo.value,
  },
  {
    id: 'configurar',
    etiqueta: configurandoBotones.value ? 'TERMINAR CONFIGURACIÓN' : 'CONFIGURAR BOTONES',
    ayuda: puede('tpv', 'editar')
      ? 'Editar las teclas de venta rápida'
      : 'Sin permiso para configurar botones',
    deshabilitada: !puede('tpv', 'editar'),
    tono: configurandoBotones.value ? 'activo' : 'normal',
  },
])

async function onOtraFuncion(id: string) {
  otrasFuncionesAbierto.value = false
  switch (id) {
    case 'espera':
      if (await tpv.ponerEnEspera()) {
        seleccion.value = -1
        cantidadTecleada.value = ''
        codigoManual.value = ''
        cobrado.value = null
      }
      break
    case 'recuperar-espera':
      ticketsEsperaAbierto.value = true
      await tpv.cargarTicketsEspera()
      return
    case 'consulta':
      consultaVentasError.value = null
      consultaVentasAbierta.value = true
      return
    case 'abono':
      abrirAbono()
      break
    case 'ticket-factura':
      abrirTicketFactura()
      return
    case 'codigo':
      codigoTecladoAbierto.value = true
      return
    case 'reimprimir':
      await reimprimirUltimo()
      break
    case 'configurar':
      configurandoBotones.value = !configurandoBotones.value
      break
  }
  await foco()
}

async function verConsulta(venta: VentaResumen) {
  if (!tpv.enConsulta && (tpv.lineas.length > 0 || tpv.ventaGrabada)) {
    consultaVentasError.value = 'Ponga primero la venta actual en espera o anúlela'
    return
  }
  consultaVentasError.value = null
  try {
    const detalle = await obtenerVentaTpv(venta.empresa, venta.tipo, venta.albaran)
    if (!tpv.mostrarConsulta(detalle)) {
      consultaVentasError.value = tpv.error
      return
    }
    ultimoTicket.value = detalle
    ultimoReceiptDatafono.value = null
    consultaVentasAbierta.value = false
    seleccion.value = Math.max(0, tpv.lineas.length - 1)
    cantidadTecleada.value = ''
    codigoManual.value = ''
    cobrado.value = null
  } catch (e: unknown) {
    consultaVentasError.value = extractApiError(e, 'No se pudo abrir el documento')
  }
}

function cerrarConsultaVentas() {
  consultaVentasAbierta.value = false
  consultaVentasError.value = null
  void foco()
}

async function repetirVenta() {
  const ok = await tpv.repetirVentaConsultada()
  if (!ok) {
    await foco()
    return
  }
  seleccion.value = Math.max(0, tpv.lineas.length - 1)
  cantidadTecleada.value = ''
  codigoManual.value = ''
  cobrado.value = null
  await foco()
}

function cerrarConsultaPantalla() {
  tpv.cerrarConsulta()
  seleccion.value = -1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  cobrado.value = null
  void foco()
}

async function recuperarTicketEspera(ticket: TpvTicketEspera) {
  if (!(await tpv.recuperarEnEspera(ticket))) return
  ticketsEsperaAbierto.value = false
  seleccion.value = tpv.lineas.length - 1
  cantidadTecleada.value = ''
  codigoManual.value = ''
  cobrado.value = null
  await foco()
}

function cerrarTicketsEspera() {
  ticketsEsperaAbierto.value = false
  void foco()
}

function cerrarOtrasFunciones() {
  otrasFuncionesAbierto.value = false
  void foco()
}

function cerrarVisorTicket() {
  visorTicketAbierto.value = false
  void foco()
}

async function onCobroCancelado() {
  if (operacionDatafono.value) {
    try {
      await operacionDatafono.value.cancelar()
    } catch {
      // El cobro principal devolverá el error de cancelación.
    }
  }
  cobroAbierto.value = false
  formaPagoSugeridaCobro.value = ''
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
  if (tpv.enConsulta) {
    tpv.cerrarConsulta()
    await router.push({ name: 'home' })
    return
  }
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
      <span class="ticket-num" :class="{ 'sin-num': tpv.numeroTicket === null, consulta: tpv.enConsulta }">
        {{ etiquetaTicket }}
      </span>
      <span class="cliente-num">{{ etiquetaCliente }}</span>
      <span v-if="nivelData" class="nivel">
        {{ nivelData.nombre }} · {{ grupoActual.etiqueta }}
      </span>
    </header>

    <p v-if="tpv.loading" class="aviso">Abriendo caja…</p>
    <p v-else-if="initError" class="aviso error">{{ initError }}</p>

    <template v-else-if="tpv.cajaAbierta">
      <div class="tpv-cuerpo" @mousedown="mantenerFoco">
        <div class="col-venta">
          <TpvTicketPanel
            class="col-ticket"
            :lineas="tpv.lineas"
            :total="tpv.totalFormateado"
            :seleccion="seleccion"
            :guardando="tpv.guardando"
            @seleccionar="onSeleccionarLinea"
            @ver="visorTicketAbierto = true"
          />

          <p class="linea-marcada" :class="{ vacia: !lineaSeleccionada }">
            {{ etiquetaLineaMarcada }}
          </p>

          <!-- Tres columnas: las parejas (cantidad, precio/dto., nueva/anular)
               van a la izquierda y la acción suelta de cada fila a la derecha.
               Lo que no se usa en cada venta vive en OTRAS FUNCIONES. -->
          <div class="acciones-tpv">
            <button
              type="button"
              class="btn-tpv"
              :disabled="!lineaSeleccionada || tpv.guardando || tpv.enConsulta"
              @click="cambiarCantidad(1)"
            >
              CANT +
            </button>
            <button
              type="button"
              class="btn-tpv"
              :disabled="!lineaSeleccionada || tpv.guardando || tpv.enConsulta"
              @click="cambiarCantidad(-1)"
            >
              CANT −
            </button>
            <button
              type="button"
              class="btn-tpv borrar-linea"
              :disabled="!lineaSeleccionada || tpv.guardando || tpv.enConsulta"
              @click="borrarLinea"
            >
              BORRAR LINEA
            </button>

            <button
              type="button"
              class="btn-tpv"
              :disabled="!lineaSeleccionada || tpv.guardando || tpv.enConsulta"
              @click="abrirPrecioLinea"
            >
              PRECIO
            </button>
            <button
              type="button"
              class="btn-tpv"
              :disabled="!tpv.ticketListo || tpv.guardando || tpv.enConsulta"
              :title="etiquetaLineaMarcada"
              @click="abrirDescuentoLinea"
            >
              DTO.
            </button>
            <button
              type="button"
              class="btn-tpv cliente"
              :class="{ 'con-cliente': !!tpv.cliente }"
              :disabled="!tpv.ticketListo || tpv.guardando || tpv.enConsulta"
              :title="etiquetaCliente"
              @click="clienteAbierto = true"
            >
              CLIENTE
            </button>

            <button
              type="button"
              class="btn-tpv nueva"
              :class="{ 'en-curso': tpv.ticketListo && !tpv.enConsulta, repetir: tpv.enConsulta }"
              :disabled="
                tpv.enConsulta
                  ? !tpv.venta || tpv.guardando
                  : tpv.ticketListo || tpv.loading || tpv.guardando
              "
              :title="
                tpv.enConsulta
                  ? 'Abrir una venta nueva con las mismas líneas'
                  : tpv.ticketListo
                    ? 'Ya hay una venta abierta'
                    : 'Abrir una venta'
              "
              @click="tpv.enConsulta ? repetirVenta() : nuevaVenta()"
            >
              {{
                tpv.enConsulta
                  ? 'REPETIR VENTA'
                  : tpv.ticketListo
                    ? 'VENTA ABIERTA'
                    : 'NUEVA VENTA'
              }}
            </button>
            <button
              type="button"
              class="btn-tpv anular"
              :disabled="tpv.enConsulta || !tpv.ticketListo || tpv.loading || tpv.guardando"
              @click="pedirAnularVenta"
            >
              ANULAR VENTA
            </button>
            <button
              type="button"
              class="btn-tpv otras"
              :class="{ activo: configurandoBotones }"
              :disabled="tpv.enConsulta"
              @click="otrasFuncionesAbierto = true"
            >
              OTRAS FUNCIONES
            </button>

            <button
              type="button"
              class="btn-tpv cobrar"
              :class="{ consulta: tpv.enConsulta }"
              :disabled="tpv.enConsulta ? !tpv.venta : !puedeCobrar"
              @click="tpv.enConsulta ? abrirAccionesConsulta() : abrirCobro()"
            >
              {{ tpv.enConsulta ? 'IMPRIMIR / EMAIL' : 'COBRAR' }}
            </button>
            <button
              type="button"
              class="btn-tpv salir"
              :title="tpv.enConsulta ? 'Quitar el documento de la pantalla' : 'Salir del TPV'"
              @click="tpv.enConsulta ? cerrarConsultaPantalla() : salir()"
            >
              SALIR
            </button>
            <button
              type="button"
              class="btn-tpv"
              :disabled="!lineaSeleccionada || tpv.guardando || tpv.enConsulta"
              @click="abrirDescripcionLinea"
            >
              DESCRIP.
            </button>
            <button
              type="button"
              class="btn-tpv"
              :disabled="!tpv.ticketListo || tpv.guardando || tpv.enConsulta"
              @click="anadirNota"
            >
              NOTA
            </button>
          </div>

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
                class="btn-tpv num"
                :disabled="tpv.enConsulta"
                @click="pulsarDigito(d)"
              >
                {{ d }}
              </button>
              <button type="button" class="btn-tpv num aux" :disabled="tpv.enConsulta" @click="limpiarCantidad">C</button>
              <button type="button" class="btn-tpv num" :disabled="tpv.enConsulta" @click="pulsarDigito('0')">0</button>
              <button type="button" class="btn-tpv num aux" :disabled="tpv.enConsulta" @click="borrarDigito">←</button>
            </div>
          </div>
        </div>

        <div class="col-articulos">
          <div class="barra-codigo">
            <label for="tpv-codigo">CODIGO / DESCRIPCION</label>
            <input
              id="tpv-codigo"
              ref="inputCodigo"
              v-model="codigoManual"
              type="text"
              autocomplete="off"
              spellcheck="false"
              :disabled="!tpv.ticketListo || tpv.enConsulta"
              :placeholder="
                tpv.enConsulta
                  ? 'Consulta: el documento no se modifica'
                  : tpv.ticketListo
                    ? 'Codigo, descripcion o pistola'
                    : 'Pulse NUEVA VENTA'
              "
              @input="onCodigoInput"
              @keydown.enter.prevent="onCodigoIntro"
            />
            <button
              type="button"
              class="btn-tpv"
              :disabled="!tpv.ticketListo || tpv.guardando || tpv.enConsulta"
              title="Buscar por descripción"
              @click="abrirBuscarArticulo"
            >
              BUSCAR
            </button>
            <button
              type="button"
              class="btn-tpv"
              :disabled="!tpv.ticketListo || tpv.guardando || tpv.enConsulta"
              title="Teclear el código sin teclado físico"
              @click="codigoTecladoAbierto = true"
            >
              TECLADO
            </button>
          </div>

          <p v-if="tpv.error" class="aviso error linea-error">{{ tpv.error }}</p>
          <p v-else-if="tpv.aviso" class="aviso linea-aviso">{{ tpv.aviso }}</p>
          <p v-else-if="cobrado" class="aviso linea-ok">
            <span>{{ cobrado }}</span>
            <span v-if="errorImpresion" class="impresion-ko">{{ errorImpresion }}</span>
            <button
              v-if="ultimoTicket"
              type="button"
              class="btn-tpv reimprimir"
              :disabled="imprimiendo"
              @click="reimprimirUltimo"
            >
              {{ imprimiendo ? 'IMPRIMIENDO…' : errorImpresion ? 'REINTENTAR' : 'REIMPRIMIR' }}
            </button>
          </p>

          <!-- Ruta de grupos: dentro de un grupo hay que ver por qué botón se
               entró, porque las teclas de dentro no lo dicen. -->
          <nav class="ruta-teclado" aria-label="Grupo de teclas abierto">
            <button
              type="button"
              class="ruta-inicio"
              :disabled="!puedeVolver"
              title="Volver al primer grupo"
              @click="volverAlInicio"
            >
              ⌂
            </button>
            <template v-for="(paso, i) in rutaTeclado" :key="`${paso}-${i}`">
              <span v-if="i" class="ruta-sep">›</span>
              <span class="ruta-paso" :class="{ actual: i === rutaTeclado.length - 1 }">
                {{ paso }}
              </span>
            </template>
          </nav>

          <TpvTecladoGrid
            :nivel="nivelData"
            :cargando="cargandoTeclado"
            :configurando="configurandoBotones && !complementoActual"
            :moviendo="moviendoBoton"
            @boton="onBoton"
            @editar="editarBoton"
            @entrar="entrarEnGrupo"
          />

          <div v-if="complementoActual" class="acciones-teclado">
            <span class="pista-teclado">
              {{ complementoActual.obligatorio ? 'Elija un complemento' : 'Complementos opcionales' }}
            </span>
            <button type="button" class="btn-tpv atras-teclado" @click="salirComplementos">
              SALIDA
            </button>
            <button type="button" class="btn-tpv configurar" @click="siguienteComplemento">
              SIGUIENTE ►
            </button>
          </div>
          <div v-else class="acciones-teclado">
            <!-- Modo configuración: se entra desde OTRAS FUNCIONES, pero salir
                 tiene que estar a la vista mientras dure. -->
            <button
              v-if="configurandoBotones"
              type="button"
              class="btn-tpv configurar activo"
              @click="configurandoBotones = false"
            >
              TERMINAR CONFIGURACIÓN
            </button>
            <span v-if="configurandoBotones" class="pista-teclado">
              Toque un grupo para entrar. El lápiz lo edita o lo elimina.
            </span>
            <span v-else class="pista-teclado">
              {{ puedeVolver ? `Dentro de ${grupoActual.etiqueta}` : grupoActual.etiqueta }}
            </span>
            <span v-if="moviendoBoton" class="pista-teclado">
              Toque la casilla de destino
              <button type="button" class="btn-tpv" @click="moviendoBoton = null">CANCELAR</button>
            </span>
            <button
              type="button"
              class="btn-tpv atras-teclado"
              :disabled="!puedeVolver"
              @click="volverNivel"
            >
              ◄ ATRÁS
            </button>
          </div>
        </div>
      </div>
    </template>

    <!-- Sin contexto de caja no hay nada que operar: se ofrece reintento y
         salida en vez de dejar la pantalla en blanco. -->
    <div v-else class="sin-ticket">
      <p>{{ tpv.error || 'No se pudo abrir la caja de este puesto.' }}</p>
      <div class="sin-ticket-acciones">
        <button type="button" class="btn-tpv nueva" :disabled="tpv.loading" @click="abrirCaja">
          REINTENTAR
        </button>
        <button type="button" class="btn-tpv salir" @click="salir">SALIR</button>
      </div>
    </div>

    <TpvClienteModal
      :open="clienteAbierto"
      :actual="tpv.cliente"
      @seleccionar="onClienteSeleccionado"
      @quitar="onClienteQuitado"
      @cancelar="onClienteCancelado"
    />

    <TpvDevolucionDatafonoModal
      :open="devolucionDatafonoAbierto"
      :empresa="tpv.contexto?.empresa ?? ''"
      :albaran-origen="devolucionAlbaranOrigen"
      :importe-devolucion="Math.abs(Number(tpv.total))"
      @confirmar="onDevolucionDatafonoConfirmada"
      @cancelar="onDevolucionDatafonoCancelada"
    />

    <Teleport to="body">
      <div
        v-if="cobrandoDatafono"
        class="overlay-datafono-global"
        role="status"
        aria-live="polite"
      >
        <div class="overlay-datafono-panel">
          <p class="overlay-datafono-titulo">Esperando datáfono…</p>
          <p class="overlay-datafono-hint">
            Siga las instrucciones en el pinpad. No cierre la aplicación.
          </p>
          <p v-if="tpv.error" class="overlay-datafono-error">{{ tpv.error }}</p>
        </div>
      </div>
    </Teleport>

    <TpvCobroModal
      :open="cobroAbierto"
      :total="tpv.total"
      :formas-pago="tpv.contexto?.formasPago ?? []"
      :forma-pago-inicial="formaPagoSugeridaCobro"
      :empresa="tpv.contexto?.empresa"
      :permite-factura="permiteFactura"
      :guardando="tpv.guardando || cobrandoDatafono"
      :cobrando-datafono="cobrandoDatafono"
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
      :titulo="tpv.enConsulta ? 'DOCUMENTO RECUPERADO' : 'VENTA FINALIZADA'"
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

    <TpvDescripcionModal
      :open="editandoDescripcionLinea >= 0"
      :articulo="tpv.lineas[editandoDescripcionLinea]?.articulo ?? ''"
      :descripcion="tpv.lineas[editandoDescripcionLinea]?.descripcion ?? ''"
      @confirmar="onDescripcionConfirmada"
      @cancelar="onDescripcionCancelada"
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
      title="Anular venta"
      message="Se eliminará completamente el ticket en curso y se limpiará la pantalla. Esta acción no se puede deshacer."
      confirm-label="Anular venta"
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

    <TpvTicketFacturaModal
      :open="ticketFacturaAbierto"
      :empresa="tpv.contexto?.empresa ?? ''"
      @convertido="onTicketConvertido"
      @cancelar="cancelarTicketFactura"
    />

    <TpvArticuloBuscarModal
      :open="buscarArticuloAbierto"
      :tarifa="tpv.contexto?.tarifa || 1"
      :inicial="buscarArticuloInicial"
      @seleccionar="onArticuloBuscado"
      @cancelar="cancelarBuscarArticulo"
    />

    <TpvCodigoModal
      :open="codigoTecladoAbierto"
      :inicial="codigoManual"
      @confirmar="onCodigoTecleado"
      @cancelar="cancelarCodigoTeclado"
    />

    <TpvOtrasFuncionesModal
      :open="otrasFuncionesAbierto"
      :funciones="otrasFunciones"
      @ejecutar="onOtraFuncion"
      @cerrar="cerrarOtrasFunciones"
    />

    <TpvTicketVisorModal
      :open="visorTicketAbierto"
      :lineas="tpv.lineas"
      :total="tpv.totalFormateado"
      :seleccion="seleccion"
      @seleccionar="onSeleccionarLinea"
      @cerrar="cerrarVisorTicket"
    />

    <TpvTicketsEsperaModal
      :open="ticketsEsperaAbierto"
      :tickets="tpv.ticketsEspera"
      :cargando="tpv.cargandoTicketsEspera"
      @recuperar="recuperarTicketEspera"
      @cerrar="cerrarTicketsEspera"
    />

    <TpvConsultaVentasModal
      :open="consultaVentasAbierta"
      :empresa="tpv.contexto?.empresa ?? ''"
      :aviso="consultaVentasError"
      @ver="verConsulta"
      @cerrar="cerrarConsultaVentas"
    />

    <TpvBotonConfigModal
      :open="botonConfigurando !== null"
      :boton="botonConfigurando?.boton ?? null"
      :zona="botonConfigurando?.zona ?? 'boton'"
      :puede-tener-grupos="nivelData?.puedeTenerGrupos ?? true"
      :aviso-borrar="avisoBorrarBoton"
      :tarifa="tpv.contexto?.tarifa ?? 1"
      @guardar="guardarConfiguracionBoton"
      @borrar="borrarConfiguracionBoton"
      @mover="moverConfiguracionBoton"
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
  background: #eef2f7;
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.tpv-titulo {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.5rem 0.8rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
}

.txt {
  font-size: 0.88rem;
  font-weight: 600;
  letter-spacing: 0.01em;
}

.ticket-num,
.cliente-num {
  padding: 0.15rem 0.55rem;
  background: rgba(148, 163, 184, 0.18);
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 999px;
  font-size: 0.76rem;
  font-weight: 600;
}

/* Sin número reservado: se ve que la caja está en espera de NUEVA VENTA. */
.ticket-num.sin-num {
  background: transparent;
  border-style: dashed;
  color: #94a3b8;
  font-weight: 400;
}

.ticket-num.consulta {
  background: #dbeafe;
  border-color: #60a5fa;
  color: #1e3a8a;
}

.cliente-num {
  max-width: 22rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.nivel {
  margin-left: auto;
  font-size: 0.76rem;
  color: #94a3b8;
}

.aviso {
  margin: 0;
  padding: 0.75rem;
  font-size: 0.88rem;
  color: #475569;
}

.aviso.error {
  color: #be123c;
  font-weight: 600;
}

.linea-ok {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.65rem;
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 10px;
  color: #065f46;
  font-size: 0.82rem;
  font-weight: 600;
}

.impresion-ko {
  flex: 1;
  color: #be123c;
  font-size: 0.76rem;
}

.reimprimir {
  margin-left: auto;
  min-height: 2rem;
  font-size: 0.72rem;
}

.linea-aviso {
  padding: 0.45rem 0.65rem;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 10px;
  color: #92400e;
  font-size: 0.8rem;
}

/* Junto a la barra de código: un fallo al añadir no puede pasar desapercibido. */
.linea-error {
  padding: 0.45rem 0.65rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 10px;
  color: #be123c;
  font-size: 0.82rem;
  font-weight: 600;
}

/*
 * Dos columnas: a la izquierda toda la caja (ticket, botonera y pad numérico
 * de arriba abajo) y a la derecha el teclado de venta rápida.
 */
.tpv-cuerpo {
  flex: 1;
  display: grid;
  /* Columna de caja estrecha: el ancho sobrante va al teclado de artículos. */
  grid-template-columns: min(12cm, 30%) minmax(0, 1fr);
  gap: 8px;
  margin: 8px;
  min-height: 0;
}

.col-venta {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
  min-height: 0;
}

/* El display y la botonera se reparten a partes iguales el alto que deja el
   pad numérico: así no queda hueco muerto sobre el pad. */
.col-ticket {
  flex: 1 1 0;
  min-height: 6rem;
}

/*
 * Tres columnas de teclas cuadradas. Las dos de la izquierda llevan las
 * acciones que van en pareja (cantidad, precio/dto., nueva/anular) y la
 * tercera las que van solas.
 */
.acciones-tpv {
  flex: 1 1 0;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  /* Las filas se estiran con la columna: la botonera absorbe su mitad del
     hueco en vez de dejarlo en blanco bajo los botones. */
  grid-auto-rows: minmax(1.9rem, 1fr);
  gap: 5px;
  min-height: 0;
}

.acciones-tpv .btn-tpv {
  min-height: 1.9rem;
  padding: 0.1rem 0.2rem;
  font-size: 0.72rem;
  line-height: 1.05;
}

/* Columna del teclado de artículos: ocupa todo el alto disponible. */
.col-articulos {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-height: 0;
  min-width: 0;
}

/* Los botones de artículos llegan hasta abajo. */
.col-articulos > .teclado {
  flex: 1 1 auto;
}

.acciones-teclado {
  display: grid;
  grid-template-columns: 1fr auto;
  align-items: center;
  gap: 6px;
  flex: 0 0 auto;
}

.acciones-teclado .btn-tpv {
  min-height: 2.8rem;
  font-size: 0.8rem;
}

.pista-teclado {
  overflow: hidden;
  font-size: 0.72rem;
  color: #64748b;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Migas de pan del teclado: el último paso es el grupo que se está viendo. */
.ruta-teclado {
  display: flex;
  align-items: center;
  flex: 0 0 auto;
  gap: 0.3rem;
  overflow-x: auto;
  padding: 5px 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.ruta-inicio {
  flex: 0 0 auto;
  width: 1.7rem;
  height: 1.7rem;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  color: #475569;
  font-size: 0.9rem;
  line-height: 1;
  cursor: pointer;
}

.ruta-inicio:disabled {
  opacity: 0.4;
  cursor: default;
}

.ruta-paso {
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  color: #64748b;
  font-size: 0.75rem;
  font-weight: 600;
  white-space: nowrap;
}

.ruta-paso.actual {
  background: #eef2ff;
  color: #3730a3;
}

.ruta-sep {
  color: #cbd5e1;
  font-size: 0.8rem;
}

.acciones-teclado .configurar.activo {
  background: #4338ca;
  border-color: #4338ca;
  color: #fff;
}

/* Navegar entre grupos es lo más frecuente del teclado: tecla ancha y con
   color propio para no confundirla con un artículo. */
.acciones-teclado .atras-teclado {
  min-width: 11rem;
  background: #eef2ff;
  border-color: #c7d2fe;
  color: #3730a3;
  font-weight: 700;
}

.acciones-teclado .atras-teclado:hover:not(:disabled) {
  background: #e0e7ff;
  border-color: #a5b4fc;
}

/* Cabe en una fila: la columna de artículos es la ancha de las dos. */
.barra-codigo {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

.barra-codigo label {
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: #64748b;
}

/* Entrada principal de la caja: es lo que debe dominar la barra. */
.barra-codigo input {
  min-width: 0;
  min-height: 2.9rem;
  padding: 0.3rem 0.7rem;
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font-family: 'Cascadia Mono', Consolas, monospace;
  font-size: 1.45rem;
  color: #0f172a;
}

.barra-codigo input::placeholder {
  font-size: 0.8rem;
  color: #94a3b8;
}

.barra-codigo .btn-tpv {
  min-width: 5.5rem;
}

.barra-codigo input:focus {
  outline: none;
  background: #fff;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
}

/* Alto propio: el pad no se estira, lo que sobra se lo quedan display y botonera. */
.numerico {
  display: flex;
  flex: 0 0 auto;
  flex-direction: column;
  gap: 6px;
  padding: 8px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}

/* Deja claro sobre qué línea actúan CANT, PRECIO, DTO. y BORRAR. */
.linea-marcada {
  flex: 0 0 auto;
  margin: 0;
  padding: 0.3rem 0.6rem;
  background: #1e293b;
  border-radius: 8px;
  color: #e2e8f0;
  font-size: 0.7rem;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.linea-marcada.vacia {
  background: #e2e8f0;
  color: #64748b;
}

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.4rem;
  padding: 0.4rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font-family: 'Cascadia Mono', Consolas, monospace;
}

.visor-lbl {
  font-size: 0.66rem;
  letter-spacing: 0.06em;
  color: #64748b;
}

.visor-val {
  margin-left: auto;
  font-size: 1.5rem;
  font-weight: 600;
}

.num-grid {
  display: grid;
  flex: 0 0 auto;
  grid-template-columns: repeat(3, 1fr);
  grid-template-rows: repeat(4, minmax(2.1rem, 2.6rem));
  gap: 5px;
  min-height: 0;
}

.btn-tpv {
  min-height: 2.7rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.btn-tpv:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

/* Realimentación táctil: la tecla se hunde en vez de cambiar el relieve. */
.btn-tpv:active:not(:disabled) {
  transform: translateY(1px);
}

.btn-tpv:focus-visible {
  outline: none;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.35);
}

.btn-tpv:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.num {
  min-height: 2.1rem;
  font-size: 1.2rem;
  font-weight: 500;
}

.aux {
  background: #f1f5f9;
  color: #475569;
  font-size: 1rem;
}

.nueva {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.nueva:hover:not(:disabled) {
  background: #1d4ed8;
  border-color: #1d4ed8;
}

/* Estado, no tecla: se lee el nombre y no se puede pulsar otra vez. */
.nueva.repetir {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.nueva.repetir:hover:not(:disabled) {
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.nueva.en-curso:disabled {
  opacity: 1;
  background: #059669;
  border-color: #059669;
  color: #fff;
  cursor: default;
}

.anular,
.borrar-linea {
  background: #fff1f2;
  border-color: #fecdd3;
  color: #be123c;
}

.anular:hover:not(:disabled),
.borrar-linea:hover:not(:disabled) {
  background: #ffe4e6;
  border-color: #fda4af;
}

.otras {
  background: #eef2ff;
  border-color: #c7d2fe;
  color: #3730a3;
}

.otras:hover:not(:disabled) {
  background: #e0e7ff;
  border-color: #a5b4fc;
}

/* Configurando teclas: desde aquí se entró y por aquí se sale. */
.otras.activo {
  background: #4338ca;
  border-color: #4338ca;
  color: #fff;
}

.con-cliente {
  background: #eff6ff;
  border-color: #bfdbfe;
  color: #1d4ed8;
}

/* Última fila: COBRAR ocupa las dos columnas de la izquierda y SALIR la tercera,
   así no queda ninguna celda hueca. */
.acciones-tpv > .cobrar {
  grid-column: span 2;
  font-size: 0.82rem;
}

.cobrar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.cobrar:hover:not(:disabled) {
  background: #047857;
  border-color: #047857;
}

.cobrar.consulta {
  background: #2563eb;
  border-color: #2563eb;
}

.cobrar.consulta:hover:not(:disabled) {
  background: #1d4ed8;
  border-color: #1d4ed8;
}

.salir {
  background: #e2e8f0;
  border-color: #cbd5e1;
  color: #334155;
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
  font-weight: 600;
  color: #475569;
}

.sin-ticket-acciones {
  display: flex;
  gap: 6px;
}

.sin-ticket-acciones .btn-tpv {
  min-width: 10rem;
  min-height: 3rem;
}

/*
 * A 1024x768 no cabe la columna del ticket a 15 cm: se reparte en proporción.
 * El tamaño de las teclas lo resuelve el escalado proporcional (ver bloque
 * global), no recortes sueltos.
 */
@media (max-width: 1200px) {
  .tpv-cuerpo {
    grid-template-columns: minmax(0, 34%) minmax(0, 1fr);
  }

  .cliente-num {
    max-width: 14rem;
  }

  .acciones-teclado .atras-teclado {
    min-width: 7rem;
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

.overlay-datafono-global {
  position: fixed;
  inset: 0;
  z-index: 8000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(15 23 42 / 72%);
}

.overlay-datafono-panel {
  max-width: 22rem;
  padding: 1.25rem 1.5rem;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 48px rgb(0 0 0 / 35%);
  text-align: center;
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.overlay-datafono-titulo {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
  font-weight: 700;
  color: #0f172a;
}

.overlay-datafono-hint {
  margin: 0;
  font-size: 0.85rem;
  color: #64748b;
  line-height: 1.4;
}

.overlay-datafono-error {
  margin: 0.75rem 0 0;
  padding: 0.5rem 0.65rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 8px;
  color: #be123c;
  font-size: 0.82rem;
  text-align: left;
}
</style>
