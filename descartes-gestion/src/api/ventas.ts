import { api } from '@/api/client'
import type {
  AbcVentasFiltros,
  AbcVentasResponse,
  Anulacion,
  ArqueoDesgloseResponse,
  ArqueoResponse,
  CobroPago,
  DesgloseArqueoVentasResponse,
  Paged,
  PedidoDetalle,
  PedidoResumen,
  Vale,
  VentaDetalle,
  VentaPayload,
  VentaResumen,
} from '@/types/ventas'
import type { PaymentTerminalPayload, PaymentTerminalResult } from '@/bridge/electron'

export async function listarVentas(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Paged<VentaResumen>>('/api/ventas/albaranes', { params })
  return data
}

export async function obtenerVenta(empresa: string, tipo: string, albaran: number) {
  const { data } = await api.get<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
  return data
}

export type AutorizacionTarjetaRegistro = {
  puesto: string
  sesion: number
  formaPago?: number
  pedidoRedsys?: string
  identificadorRts?: string
  autorizacion?: string
  clr?: string
  tarjeta?: string
  importe?: number
  comercio?: string
  tpv?: string
  tipoOperacion?: string
  aid?: string
  lbl?: string
  arc?: string
  marcaTarjeta?: string
}

export type AutorizacionTarjetaItem = {
  pedidoRedsys?: string
  identificadorRts?: string
  autorizacion?: string
  clr?: string
  tarjeta?: string
  importe?: number
}

export async function obtenerAutorizacionTarjetaAlbaran(
  empresa: string,
  albaran: number,
  tipo = 'A'
) {
  const { data } = await api.get<{ item: AutorizacionTarjetaItem | null }>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/autorizacion-tarjeta`
  )
  return data.item
}

export async function buscarAutorizacionTarjetaPorAut(
  empresa: string,
  aut: string,
  clr: string,
  importe?: number,
  albaranOrigen?: number
) {
  const { data } = await api.get<{ item: AutorizacionTarjetaItem | null }>(
    `/api/ventas/autorizacion-tarjeta/${encodeURIComponent(empresa)}/buscar`,
    {
      params: {
        aut,
        clr,
        importe,
        albaran: albaranOrigen && albaranOrigen > 0 ? albaranOrigen : undefined,
      },
    }
  )
  return data.item
}

export async function registrarAutorizacionTarjetaAlbaran(
  empresa: string,
  albaran: number,
  payload: AutorizacionTarjetaRegistro,
  tipo = 'A'
) {
  await api.post(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/autorizacion-tarjeta`,
    payload
  )
}

export async function crearVenta(payload: VentaPayload) {
  const { data } = await api.post<VentaDetalle>('/api/ventas/albaranes', payload)
  return data
}

/** Vendedor configurado en el puesto (permiso ventas.ver, no Mantenimiento). */
export async function obtenerVendedorPuesto(puesto: string) {
  const { data } = await api.get<{
    puesto: string
    existe?: boolean
    vendedor: string | null
    vendedorNombre?: string | null
  }>(`/api/ventas/puestos/${encodeURIComponent(puesto)}`)
  return data
}

export async function reservarAlbaran(payload: { empresa: string; puesto?: string | null }) {
  const { data } = await api.post<{
    empresa: string
    tipo: string
    albaran: number
    puesto: string | null
    vendedor: string | null
    almacen: number | null
  }>('/api/ventas/albaranes/reservar', payload)
  return data
}

export async function actualizarVenta(
  empresa: string,
  tipo: string,
  albaran: number,
  payload: VentaPayload
) {
  const { data } = await api.put<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`,
    payload
  )
  return data
}

export async function eliminarVenta(empresa: string, tipo: string, albaran: number) {
  await api.delete(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
}

export async function finalizarVenta(
  empresa: string,
  tipo: string,
  albaran: number,
  nuevoTipo: string,
  fpago1?: string,
  opciones: { aplicarValeFidelizacion?: boolean } = {}
) {
  const { data } = await api.post<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/finalizar`,
    { tipo: nuevoTipo, ...(fpago1 ? { fpago1 } : {}), ...opciones }
  )
  return data
}

export async function cobrarDatafonoDispositivo(
  puesto: string,
  payload: PaymentTerminalPayload
) {
  const { data } = await api.post<PaymentTerminalResult>(
    `/api/ventas/puestos/${encodeURIComponent(puesto)}/dispositivo/datafono/cobrar`,
    payload
  )
  return data
}

export async function cancelarDatafonoDispositivo(
  puesto: string,
  payload: Pick<PaymentTerminalPayload, 'operationId'>
) {
  const { data } = await api.post<PaymentTerminalResult>(
    `/api/ventas/puestos/${encodeURIComponent(puesto)}/dispositivo/datafono/cancelar`,
    payload
  )
  return data
}

export async function crearAbonoDesdeVenta(
  empresa: string,
  tipo: string,
  albaran: number,
  payload: { nroLins?: number[]; observacion?: string } = {}
) {
  const { data } = await api.post<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/abono`,
    payload
  )
  return data
}

export async function marcarVentaImpresa(empresa: string, tipo: string, albaran: number) {
  const { data } = await api.post<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/impreso`
  )
  return data
}

export async function enviarVentaPorEmail(
  empresa: string,
  tipo: string,
  albaran: number,
  email: string,
  canal: 'ventas' | 'tpv' = 'ventas'
): Promise<{ destinatario: string; documento: string }> {
  const { data } = await api.post<{ destinatario: string; documento: string }>(
    canal === 'tpv'
      ? `/api/tpv/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/email`
      : `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/email`,
    { email }
  )
  return data
}

export async function obtenerArqueo(empresa: string, puesto: string, sesion: number) {
  const { data } = await api.get<ArqueoResponse>('/api/ventas/arqueos', {
    params: { empresa, puesto, sesion },
  })
  return data
}

export async function introducirArqueo(
  empresa: string,
  puesto: string,
  sesion: number,
  payload: {
    lineas: { formaPago: string; entrado: number; monedas?: number[] }[]
    forzarRepeticion?: boolean
  }
) {
  const { data } = await api.post<ArqueoResponse>(
    `/api/ventas/arqueos/${encodeURIComponent(empresa)}/${encodeURIComponent(puesto)}/${sesion}/introducir`,
    payload
  )
  return data
}

export type CerrarSesionResponse = {
  sesionCerrada: ArqueoResponse
  sesionNueva: ArqueoResponse
  descuadreEfectivo: number
}

export async function cerrarSesionArqueo(
  empresa: string,
  puesto: string,
  sesion: number,
  payload: {
    salidaBanco?: number
    salidaSiguienteSesion?: number
    aplicarDescuadre?: boolean
    forzar?: boolean
    formaPagoEfectivo?: string
  } = {}
) {
  const { data } = await api.post<CerrarSesionResponse>(
    `/api/ventas/sesiones/${encodeURIComponent(empresa)}/${encodeURIComponent(puesto)}/${sesion}/cerrar`,
    payload
  )
  return data
}

export async function entradaCajaArqueo(
  empresa: string,
  puesto: string,
  sesion: number,
  payload: { formaPago: string; importe: number; concepto?: string }
) {
  const { data } = await api.post<ArqueoResponse>(
    `/api/ventas/sesiones/${encodeURIComponent(empresa)}/${encodeURIComponent(puesto)}/${sesion}/entrada-caja`,
    payload
  )
  return data
}

export async function salidaCajaArqueo(
  empresa: string,
  puesto: string,
  sesion: number,
  payload: { formaPago: string; importe: number; concepto?: string }
) {
  const { data } = await api.post<ArqueoResponse>(
    `/api/ventas/sesiones/${encodeURIComponent(empresa)}/${encodeURIComponent(puesto)}/${sesion}/salida-caja`,
    payload
  )
  return data
}

/** Descarga el informe de arqueo en PDF real. */
export async function descargarInformeArqueoPdf(empresa: string, puesto: string, sesion: number) {
  const { data } = await api.get<Blob>(
    `/api/ventas/arqueos/${encodeURIComponent(empresa)}/${encodeURIComponent(puesto)}/${sesion}/informe`,
    { params: { formato: 'pdf' }, responseType: 'blob' }
  )
  return data
}

export type LeerCajonResponse = {
  ok: boolean
  stub?: boolean
  agenteOnline?: boolean
  puesto?: string
  formaPago?: string
  importe?: number
  monedas?: number[]
  message?: string
}

export async function leerCajonDispositivo(puesto: string, payload: { formaPago?: string } = {}) {
  const { data } = await api.post<LeerCajonResponse>(
    `/api/ventas/puestos/${encodeURIComponent(puesto)}/dispositivo/leer-cajon`,
    payload
  )
  return data
}

export type ImprimirDispositivoResponse = {
  ok: boolean
  stub?: boolean
  agenteOnline?: boolean
  message?: string
}

export async function imprimirTermicaDispositivo(
  puesto: string,
  payload: {
    texto: string
    tipo?: string
    empresa?: string
    sesion?: number
    impresora?: string
    abrirCajon?: boolean
    cortar?: boolean
    ancho?: number
  }
) {
  const { data } = await api.post<ImprimirDispositivoResponse>(
    `/api/ventas/puestos/${encodeURIComponent(puesto)}/dispositivo/imprimir`,
    payload
  )
  return data
}

export async function obtenerDesgloseArqueoVentas(
  params: Record<string, string | number | undefined>
) {
  const { data } = await api.get<DesgloseArqueoVentasResponse>('/api/ventas/arqueos/desglose', {
    params,
  })
  return data
}

export async function descargarDesgloseArqueoPdf(
  params: Record<string, string | number | undefined>
) {
  const { data } = await api.get<Blob>('/api/ventas/arqueos/desglose', {
    params: { ...params, salida: 'pdf' },
    responseType: 'blob',
  })
  return data
}

export async function obtenerDesgloseArqueoTextoTermico(
  params: Record<string, string | number | undefined>
) {
  const { data } = await api.get<{ texto: string }>('/api/ventas/arqueos/desglose', {
    params: { ...params, salida: 'termica' },
  })
  return data
}

/** @deprecated */
export async function obtenerArqueoDesglose(
  empresa: string,
  puesto: string,
  sesion: number,
  _formaPago?: string
) {
  return obtenerDesgloseArqueoVentas({
    empresaDesde: empresa,
    empresaHasta: empresa,
    puestoDesde: puesto,
    puestoHasta: puesto,
    sesionDesde: sesion,
    sesionHasta: sesion,
  })
}

export async function listarAnulaciones(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Paged<Anulacion>>('/api/ventas/anulaciones', { params })
  return data
}

export async function listarCobrosPagos(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Paged<CobroPago>>('/api/ventas/cobros-pagos', { params })
  return data
}

export async function listarVales(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Paged<Vale>>('/api/ventas/vales', { params })
  return data
}

export async function valeFidelizacionDisponible(empresa: string, cliente: string) {
  const { data } = await api.get<{
    cliente: string
    saldo: number
    vales: { empresa?: string; codigo: number; saldo: number; fechaCaducidad: string | null }[]
  }>('/api/ventas/fidelizacion/vale-disponible', { params: { empresa, cliente } })
  return data
}

export async function semestreFidelizacion() {
  const { data } = await api.get<{ inicio: string; fin: string }>('/api/ventas/fidelizacion/semestre')
  return data
}

export type FidelizacionValeCliente = {
  cliente: string
  razonSocial: string
  tarjetaFidelizacion: string
  importeTotal: number
  puntos: number
  importeCanje: number
  vale?: number
}

export type FidelizacionValesSemestreResultado = {
  simulado: boolean
  generadoAhora?: boolean
  fueraDeVentana?: boolean
  empresa: string
  fechaInicio: string
  fechaFin: string
  fechaCaducidad: string
  pjeCanje: number
  formaPago: string
  yaGenerado?: boolean
  clientes: FidelizacionValeCliente[]
  vales: number
  importeTotal: number
}

export type FidelizacionModelo = {
  codigo: string
  nombre: string
  motor: string
  factor: number
  configuracion: { pjeCanje?: number; mesesCaducidad?: number }
}

export type FidelizacionConfiguracion = {
  empresa: string
  seleccionado: string
  modelos: FidelizacionModelo[]
}

export async function obtenerConfiguracionFidelizacion(empresa: string) {
  const { data } = await api.get<FidelizacionConfiguracion>(
    '/api/ventas/fidelizacion/configuracion',
    { params: { empresa } }
  )
  return data
}

export async function seleccionarModeloFidelizacion(empresa: string, codigo: string) {
  const { data } = await api.put<FidelizacionConfiguracion>(
    '/api/ventas/fidelizacion/configuracion',
    { empresa, codigo }
  )
  return data
}

export type FidelizacionEstadoAutomatico = {
  empresa: string
  aplica: boolean
  pendiente: boolean
  fechaInicio: string
  fechaFin: string
  ventanaInicio: string
  ventanaFin: string
  dentroVentana: boolean
  yaGenerado: boolean
}

export async function estadoAutomaticoFidelizacion(empresa: string) {
  const { data } = await api.get<FidelizacionEstadoAutomatico>(
    '/api/ventas/fidelizacion/automatico/estado',
    { params: { empresa } }
  )
  return data
}

export async function generarAutomaticamenteFidelizacion(empresa: string, puesto: string) {
  const { data } = await api.post<FidelizacionValesSemestreResultado>(
    '/api/ventas/fidelizacion/automatico/generar',
    { empresa, puesto }
  )
  return data
}

export async function generarValesFidelizacion(payload: {
  empresa: string
  fechaInicio: string
  fechaFin: string
  formaPago?: string
  pjeCanje?: number
  simular?: boolean
  forzar?: boolean
}) {
  const { data } = await api.post<FidelizacionValesSemestreResultado>(
    '/api/ventas/fidelizacion/vales-semestre',
    payload
  )
  return data
}

export async function emitirVale(payload: {
  empresa: string
  cliente: string
  importe: number
  fechaCaducidad?: string
  formaPago?: string
}) {
  const { data } = await api.post<Vale>('/api/ventas/vales', payload)
  return data
}

export async function liquidarVale(
  empresa: string,
  codigo: number,
  payload: { fechaLiquidacion: string; tipoLiquidacion: string }
) {
  const { data } = await api.post<Vale>(
    `/api/ventas/vales/${encodeURIComponent(empresa)}/${codigo}/liquidar`,
    payload
  )
  return data
}

export async function listarPedidos(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<Paged<PedidoResumen>>('/api/ventas/pedidos-clientes', { params })
  return data
}

export async function reservarPedido(payload: { empresa: string; puesto?: string | null }) {
  const { data } = await api.post<{
    empresa: string
    pedido: number
    puesto: string | null
    vendedor: string | null
  }>('/api/ventas/pedidos-clientes/reservar', payload)
  return data
}

export async function obtenerPedido(empresa: string, pedido: number) {
  const { data } = await api.get<PedidoDetalle>(
    `/api/ventas/pedidos-clientes/${encodeURIComponent(empresa)}/${pedido}`
  )
  return data
}

export async function crearPedido(payload: {
  empresa: string
  cliente: string
  pedido?: number
  puesto?: string
  vendedor?: string
  razonSocial?: string
  nif?: string
  suPedido?: string
  email?: string
  transporte?: string
  direccionEnvio?: string
  codigoPostalEnvio?: string
  poblacionEnvio?: string
  provinciaEnvio?: string
  paisEnvio?: string
  observaciones?: string
  lineas?: {
    articulo: string
    cantidadPedida: number
    precio: number
    descripcion?: string
    cantidadServida?: number
    cantidadAServir?: number
    pjeDto?: number
    zona?: string
    loteVenta?: string
  }[]
}) {
  const { data } = await api.post<PedidoDetalle>('/api/ventas/pedidos-clientes', payload)
  return data
}

export async function actualizarPedido(
  empresa: string,
  pedido: number,
  payload: {
    cliente?: string
    puesto?: string
    vendedor?: string
    suPedido?: string
    email?: string
    transporte?: string
    direccionEnvio?: string
    codigoPostalEnvio?: string
    poblacionEnvio?: string
    provinciaEnvio?: string
    paisEnvio?: string
    observaciones?: string
    lineas?: {
      articulo: string
      cantidadPedida: number
      precio: number
      cantidadServida?: number
      cantidadAServir?: number
      descripcion?: string
      pjeDto?: number
      zona?: string
      loteVenta?: string
    }[]
  }
) {
  const { data } = await api.put<PedidoDetalle>(
    `/api/ventas/pedidos-clientes/${encodeURIComponent(empresa)}/${pedido}`,
    payload
  )
  return data
}

export async function convertirPedidoAVenta(
  empresa: string,
  pedido: number,
  payload: { puesto?: string; vendedor?: string; servirPendiente?: boolean } = {}
) {
  const { data } = await api.post<{
    pedido: PedidoDetalle
    venta: VentaDetalle
  }>(
    `/api/ventas/pedidos-clientes/${encodeURIComponent(empresa)}/${pedido}/convertir-venta`,
    payload
  )
  return data
}

export async function marcarPedidoImpreso(empresa: string, pedido: number) {
  const { data } = await api.post<PedidoDetalle>(
    `/api/ventas/pedidos-clientes/${encodeURIComponent(empresa)}/${pedido}/impreso`
  )
  return data
}

export async function obtenerAbcVentas(filtros: AbcVentasFiltros) {
  const params: Record<string, string | number | boolean> = {}
  for (const [key, value] of Object.entries(filtros)) {
    if (value === undefined || value === null || value === '') continue
    params[key] = value as string | number | boolean
  }
  const { data } = await api.get<AbcVentasResponse>('/api/ventas/abc', { params })
  return data
}
