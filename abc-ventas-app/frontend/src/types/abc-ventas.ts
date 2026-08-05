export type AbcVentasFiltros = {
  dimension: 'vendedores' | 'clientes'
  orden: 'margen' | 'importe' | 'cantidad' | 'coste' | 'vendedor'
  idioma?: 'castellano' | 'catalan' | string
  divisa?: string
  iva: 'incluido' | 'desglosado'
  imArticulos: boolean
  valor: 'precioMedio' | 'precioMedioActual' | 'ultimoPrecio' | 'sinValorTarifa'
  tipoVenta:
    | 'todos'
    | 'ticket'
    | 'facturas'
    | 'ticketFacturas'
    | 'albaranes'
    | 'ticketsFacturasContado'
    | string
  fechaDesde: string
  fechaHasta: string
  macroFamiliaDesde?: string
  macroFamiliaHasta?: string
  familiaDesde?: string
  familiaHasta?: string
  subfamiliaDesde?: string
  subfamiliaHasta?: string
  agrupacionDesde?: string
  agrupacionHasta?: string
  articuloDesde?: string
  articuloHasta?: string
  tiendaDesde?: string
  tiendaHasta?: string
  agenteDesde?: string
  agenteHasta?: string
  representanteDesde?: string
  representanteHasta?: string
  vendedorDesde?: string
  vendedorHasta?: string
  clienteDesde?: string
  clienteHasta?: string
  proveedorDesde?: string
  proveedorHasta?: string
  seccionDesde?: string
  seccionHasta?: string
  subSeccionDesde?: string
  subSeccionHasta?: string
  actividadDesde?: string
  actividadHasta?: string
  tipoDescuentoDesde?: string
  tipoDescuentoHasta?: string
  tarifa?: number | string
}

export type AbcVentasArticulo = {
  codigo: string
  descripcion: string
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen: number
  pjeSobreTotal: number
  mAgr: number
}

export type AbcVentasTotales = {
  unidades: number
  dto: number
  importe: number
  coste: number
  margen: number
  pjeMargen?: number
  pjeSobreTotal?: number
  mAgr?: number
}

export type AbcVentasGrupo = {
  codigo: string
  nombre: string
  totales: AbcVentasTotales
  articulos: AbcVentasArticulo[]
}

export type AbcVentasResponse = {
  dimension: string
  orden: string
  valor: string
  iva: string
  idioma?: string
  imArticulos: boolean
  tipoVenta: string
  divisa?: string
  fechaDesde: string
  fechaHasta: string
  totales: AbcVentasTotales
  grupos: AbcVentasGrupo[]
}
