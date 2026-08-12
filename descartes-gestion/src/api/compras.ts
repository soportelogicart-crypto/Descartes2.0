import { api } from '@/api/client'
import type {
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
  return data
}

export async function crearAlbaranCompra(
  payload: AlbaranCompraPayload
): Promise<AlbaranCompraDetalle> {
  const { data } = await api.post<AlbaranCompraDetalle>('/api/compras/albaranes', payload)
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
