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

export type StockListadoParams = {
  agruparPor: StockAgruparPor
  almacen?: number
  ocultarCero?: boolean
  stockFiltro?:
    | 'todos'
    | 'superior_0'
    | 'menor_0'
    | 'diferente_0'
    | 'igual_0'
    | 'bloqueo_venta'
  anoDesde?: number
  anoHasta?: number
  mesDesde?: number
  mesHasta?: number
  almacenDesde?: number
  almacenHasta?: number
  macrofamiliaDesde?: string
  macrofamiliaHasta?: string
  familiaDesde?: string
  familiaHasta?: string
  subfamiliaDesde?: string
  subfamiliaHasta?: string
  agrupacionDesde?: string
  agrupacionHasta?: string
  articuloDesde?: string
  articuloHasta?: string
  proveedorDesde?: string
  proveedorHasta?: string
  seccionDesde?: string
  seccionHasta?: string
  subseccionDesde?: string
  subseccionHasta?: string
  ultimaVentaDesde?: string
  ultimaVentaHasta?: string
  fechaAltaDesde?: string
  fechaAltaHasta?: string
  ultCompraDesde?: string
  ultCompraHasta?: string
  ubicacionDesde?: string
  ubicacionHasta?: string
}

export async function generarListadoStock(params: StockListadoParams): Promise<StockListadoResult> {
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

export type InformeIvaDesgloseFila = {
  pjeIva: number
  baseImponible: number
  cuotaIva: number
}

export type InformeIvaFila = {
  empresa: string
  albaran: number
  fecha: string
  numeroTicket: number | null
  facturaTipo: string
  cliente: string
  razonSocial: string
  trasCtb?: boolean
  trasModem?: boolean
  desgloseIva: InformeIvaDesgloseFila[]
  baseImponible: number
  cuotaIva: number
  importeTotal: number
}

export type InformeIvaResumenFila = {
  pjeIva: number
  baseImponible: number
  cuotaIva: number
}

export type InformeIvaResult = {
  fechaDesde: string
  fechaHasta: string
  empresa: string
  tipoDocumento: 'facturas' | 'tickets'
  estado?: string
  formato?: string
  divisa?: string
  facturaDesde: number | null
  facturaHasta: number | null
  soloNumerados: boolean
  items: InformeIvaFila[]
  resumenPorIva: InformeIvaResumenFila[]
  totales: {
    tickets: number
    baseImponible: number
    cuotaIva: number
    importeTotal: number
  }
  truncado: boolean
  limite: number
}

export type InformeIvaParams = {
  fechaDesde: string
  fechaHasta: string
  estado?: string
  formato?: string
  empresaDesde?: string
  empresaHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  origenDesde?: string
  origenHasta?: string
  cierreSesionDesde?: number
  cierreSesionHasta?: number
  puestoDesde?: string
  puestoHasta?: string
  sesionDesde?: number
  sesionHasta?: number
  facturaDesde?: number
  facturaHasta?: number
  tipoDocumento?: 'facturas' | 'tickets'
}

export async function generarInformeIva(params: InformeIvaParams): Promise<InformeIvaResult> {
  const { data } = await api.get<InformeIvaResult>('/api/listados/informe-iva', { params })
  return data
}

export async function descargarInformeIvaPdf(body: InformeIvaParams): Promise<Blob> {
  const { data } = await api.post('/api/listados/informe-iva/pdf', body, { responseType: 'blob' })
  return data as Blob
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
