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

export async function crearVenta(payload: VentaPayload) {
  const { data } = await api.post<VentaDetalle>('/api/ventas/albaranes', payload)
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
  fpago1?: string
) {
  const { data } = await api.post<VentaDetalle>(
    `/api/ventas/albaranes/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/finalizar`,
    { tipo: nuevoTipo, ...(fpago1 ? { fpago1 } : {}) }
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
