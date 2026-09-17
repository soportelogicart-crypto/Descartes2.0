import { api } from '@/api/client'

export type StockAgruparPor =
  | 'articulo'
  | 'familia'
  | 'subfamilia'
  | 'macrofamilia'
  | 'agrupacion'
  | 'proveedor'

export type StockListadoFila = {
  grupoCodigo: string
  grupoNombre: string
  unidades: number
  numArticulos: number
}

export type StockListadoResult = {
  agruparPor: StockAgruparPor
  almacen: number
  items: StockListadoFila[]
  totales: { unidades: number; filas: number }
  truncado: boolean
  limite: number
}

export async function generarListadoStock(params: {
  agruparPor: StockAgruparPor
  almacen?: number
  ocultarCero?: boolean
}): Promise<StockListadoResult> {
  const { data } = await api.get<StockListadoResult>('/api/listados/stock', { params })
  return data
}

export type StockMinimosFila = {
  articulo: string
  descripcion: string
  almacen: number
  almacenNombre: string
  minimo: number
  optimo: number
  stockActual: number
  faltan: number
  proveedorCodigo: string
}

export type StockMinimosResult = {
  almacen: number
  items: StockMinimosFila[]
  totales: { filas: number }
  truncado: boolean
  limite: number
}

export async function generarListadoStockMinimos(params: {
  almacen?: number
}): Promise<StockMinimosResult> {
  const { data } = await api.get<StockMinimosResult>('/api/listados/stock-minimos', { params })
  return data
}

export type InformeIvaFila = {
  impuestoCodigo: string
  impuestoNombre: string
  pjeIva: number
  baseImponible: number
  cuotaIva: number
  importeTotal: number
  numLineas: number
}

export type InformeIvaResult = {
  fechaDesde: string
  fechaHasta: string
  empresa: string
  items: InformeIvaFila[]
  totales: {
    baseImponible: number
    cuotaIva: number
    importeTotal: number
    filas: number
  }
  truncado: boolean
  limite: number
}

export async function generarInformeIva(params: {
  fechaDesde: string
  fechaHasta: string
  empresa?: string
}): Promise<InformeIvaResult> {
  const { data } = await api.get<InformeIvaResult>('/api/listados/informe-iva', { params })
  return data
}

export type InformeTicketsFila = {
  empresa: string
  albaran: number
  fecha: string
  numeroTicket: number | null
  puesto: string
  vendedor: string
  cliente: string
  razonSocial: string
  importe: number
  sesion: number | null
  estado: string
  formaPago: string
}

export type InformeTicketsResult = {
  fechaDesde: string
  fechaHasta: string
  empresa: string
  puesto: string
  vendedor: string
  soloNumerados: boolean
  items: InformeTicketsFila[]
  totales: { tickets: number; importe: number }
  truncado: boolean
  limite: number
}

export async function generarInformeTickets(params: {
  fechaDesde: string
  fechaHasta: string
  empresa?: string
  puesto?: string
  vendedor?: string
  soloNumerados?: boolean
}): Promise<InformeTicketsResult> {
  const { data } = await api.get<InformeTicketsResult>('/api/listados/informe-tickets', { params })
  return data
}

export type ExtractoClientesFila = {
  fecha: string
  tipo: 'factura' | 'albaran' | 'cobro'
  documento: string
  empresa: string
  concepto: string
  debe: number
  haber: number
  saldo: number
}

export type ExtractoClientesResult = {
  fechaDesde: string
  fechaHasta: string
  cliente: string
  razonSocial: string
  empresa: string
  saldoAnterior: number
  saldoFinal: number
  riesgoAcumulado: number
  items: ExtractoClientesFila[]
  totales: { movimientos: number; debe: number; haber: number; neto: number }
  truncado: boolean
  limite: number
}

export async function generarExtractoClientes(params: {
  fechaDesde: string
  fechaHasta: string
  cliente: string
  empresa?: string
}): Promise<ExtractoClientesResult> {
  const { data } = await api.get<ExtractoClientesResult>('/api/listados/extracto-clientes', { params })
  return data
}
