import { api } from '@/api/client'
import type { VentaDetalle } from '@/types/ventas'
import type {
  AbcComprasFiltros,
  AbcComprasResponse,
  AlbaranCompraDetalle,
  AlbaranCompraListParams,
  AlbaranCompraPayload,
  AlbaranCompraResumen,
  FacturaCompraDetalle,
  FacturaCompraListParams,
  FacturaCompraResumen,
  Paged,
  PedidoProveedorDetalle,
  PedidoProveedorListParams,
  PedidoProveedorPayload,
  PedidoProveedorResumen,
  RecepcionPedidoPayload,
  RecepcionPedidoResultado,
} from '@/types/compras'

export async function obtenerAbcCompras(filtros: AbcComprasFiltros) {
  const params: Record<string, string | number | boolean | undefined> = { ...filtros }
  if (params.soloActualizado === true) params.soloActualizado = 'true'
  const { data } = await api.get<AbcComprasResponse>('/api/compras/abc', { params })
  return data
}

export async function pingCompras(): Promise<{
  ok: boolean
  modulo: string
  message: string
}> {
  const { data } = await api.get<{ ok: boolean; modulo: string; message: string }>(
    '/api/compras/ping'
  )
  return data
}

export async function listarAlbaranesCompra(
  params: AlbaranCompraListParams | Record<string, string | number | undefined> = {}
): Promise<Paged<AlbaranCompraResumen>> {
  const { data } = await api.get<Paged<AlbaranCompraResumen>>('/api/compras/albaranes', {
    params,
  })
  return data
}

export async function obtenerAlbaranCompra(
  empresa: string,
  albaran: number
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.get<AlbaranCompraDetalle>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}`
  )
  if (!data || typeof data !== 'object') {
    throw new Error('Albarán no encontrado o respuesta inválida del servidor')
  }
  return data
}

export async function crearAlbaranCompra(
  payload: AlbaranCompraPayload
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.post<AlbaranCompraDetalle>('/api/compras/albaranes', payload)
  return data
}

/** Reserva el siguiente nº (UltAlbaranCom) sin grabar cabecera. */
export async function reservarAlbaranCompra(payload: {
  empresa: string
  albaranDevolucion?: boolean
}): Promise<{
  empresa: string
  albaran: number
  albaranDevolucion: boolean
  almacen: number | null
}> {
  const { data } = await api.post<{
    empresa: string
    albaran: number
    albaranDevolucion: boolean
    almacen: number | null
  }>('/api/compras/albaranes/reservar', payload)
  return data
}

export async function actualizarAlbaranCompra(
  empresa: string,
  albaran: number,
  payload: AlbaranCompraPayload
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.put<AlbaranCompraDetalle>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}`,
    payload
  )
  return data
}

export async function eliminarAlbaranCompra(empresa: string, albaran: number): Promise<void> {
  await api.delete(`/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}`)
}

export async function actualizarStockAlbaranCompra(
  empresa: string,
  albaran: number
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.post<AlbaranCompraDetalle>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}/actualizar-stock`
  )
  return data
}

/** Legacy «Recuperar»: revierte stock y Actualizado=0. */
export async function recuperarAlbaranCompra(
  empresa: string,
  albaran: number
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.post<AlbaranCompraDetalle>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}/recuperar`
  )
  return data
}

/** Albarán compra ACTUALIZADO → albarán venta al cliente (cliente obligatorio). */
export async function convertirAlbaranCompraAVenta(
  empresa: string,
  albaran: number,
  payload: { cliente: string; puesto?: string; vendedor?: string }
): Promise<{ albaranCompra?: AlbaranCompraDetalle; venta: VentaDetalle }> {
  const { data } = await api.post<{ albaranCompra: AlbaranCompraDetalle; venta: VentaDetalle }>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}/convertir-venta`,
    payload
  )
  if (!data || typeof data !== 'object') {
    throw new Error('Respuesta vacía al convertir el albarán a venta')
  }
  const venta = (data as { venta?: VentaDetalle }).venta
  if (!venta || typeof venta !== 'object') {
    throw new Error('El servidor no devolvió la venta creada')
  }
  const albaranCompra = (data as { albaranCompra?: AlbaranCompraDetalle }).albaranCompra
  return { albaranCompra, venta }
}

/** Abono/devolución parcial por líneas (como ventas). */
export async function crearAbonoAlbaranCompra(
  empresa: string,
  albaran: number,
  payload: { nroLins: number[]; observacion?: string }
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.post<AlbaranCompraDetalle>(
    `/api/compras/albaranes/${encodeURIComponent(empresa)}/${albaran}/abono`,
    payload
  )
  return data
}

export async function listarPedidosProveedor(
  params: PedidoProveedorListParams | Record<string, string | number | undefined> = {}
): Promise<Paged<PedidoProveedorResumen>> {
  const { data } = await api.get<Paged<PedidoProveedorResumen>>('/api/compras/pedidos', {
    params,
  })
  return data
}

export async function obtenerPedidoProveedor(
  empresa: string,
  pedido: number
): Promise<PedidoProveedorDetalle> {
  const { data } = await api.get<PedidoProveedorDetalle>(
    `/api/compras/pedidos/${encodeURIComponent(empresa)}/${pedido}`
  )
  return data
}

export async function crearPedidoProveedor(
  payload: PedidoProveedorPayload
): Promise<PedidoProveedorDetalle> {
  const { data } = await api.post<PedidoProveedorDetalle>('/api/compras/pedidos', payload)
  return data
}

/** Reserva el siguiente nº (UltPedidoCom) sin grabar cabecera. */
export async function reservarPedidoProveedor(payload: { empresa: string }): Promise<{
  empresa: string
  pedido: number
  almacen: number | null
}> {
  const { data } = await api.post<{
    empresa: string
    pedido: number
    almacen: number | null
  }>('/api/compras/pedidos/reservar', payload)
  return data
}

export async function actualizarPedidoProveedor(
  empresa: string,
  pedido: number,
  payload: PedidoProveedorPayload
): Promise<PedidoProveedorDetalle> {
  const { data } = await api.put<PedidoProveedorDetalle>(
    `/api/compras/pedidos/${encodeURIComponent(empresa)}/${pedido}`,
    payload
  )
  return data
}

export async function recibirPedidoProveedor(
  empresa: string,
  pedido: number,
  payload: RecepcionPedidoPayload
): Promise<RecepcionPedidoResultado> {
  const { data } = await api.post<RecepcionPedidoResultado>(
    `/api/compras/pedidos/${encodeURIComponent(empresa)}/${pedido}/recibir`,
    payload
  )
  return data
}

export async function listarFacturasCompra(
  params: FacturaCompraListParams | Record<string, string | number | undefined> = {}
): Promise<Paged<FacturaCompraResumen>> {
  const { data } = await api.get<Paged<FacturaCompraResumen>>('/api/compras/facturas', {
    params,
  })
  return data
}

export async function obtenerFacturaCompra(factura: number): Promise<FacturaCompraDetalle> {
  const { data } = await api.get<FacturaCompraDetalle>(
    `/api/compras/facturas/${factura}`
  )
  return data
}
