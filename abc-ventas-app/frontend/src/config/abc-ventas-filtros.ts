import type { LookupEntidad } from '@/api/lookup'

/**
 * Intervalos del informe legado VentasABCVen (informes.mdb / tabla Intervalos).
 * Fuente: MaxLength1/2 + Formato1/2 + Modo.
 */
export type AbcFiltroRango = {
  label: string
  desde: string
  hasta: string
  /** MaxLength legado */
  maxLength: number
  /** Formato legado (###0, ####0, vacio=texto). */
  formato: string
  /** Modo legado: 1=fecha, 5=texto, 6=codigo numerico enmascarado, 0=texto libre */
  modo: number
  /** Si true, el filtro no se muestra en la UI. */
  oculto?: boolean
  /** Catalogo maestro para lupa de busqueda (desde/hasta). */
  lookup?: LookupEntidad
}

/** Orden = NumeroIntervalo en Intervalos (excepto Fecha, que va aparte en UI). */
export const abcVentasFiltrosRangos: AbcFiltroRango[] = [
  { label: 'MacroFamilia', desde: 'macroFamiliaDesde', hasta: 'macroFamiliaHasta', maxLength: 6, formato: '', modo: 5, lookup: 'macroFamilias' },
  { label: 'Familia', desde: 'familiaDesde', hasta: 'familiaHasta', maxLength: 6, formato: '', modo: 5, lookup: 'familias' },
  { label: 'Subfamilia', desde: 'subfamiliaDesde', hasta: 'subfamiliaHasta', maxLength: 6, formato: '', modo: 5, lookup: 'subfamilias' },
  { label: 'Agrupacion', desde: 'agrupacionDesde', hasta: 'agrupacionHasta', maxLength: 6, formato: '', modo: 5, lookup: 'agrupaciones' },
  { label: 'Articulo', desde: 'articuloDesde', hasta: 'articuloHasta', maxLength: 18, formato: '', modo: 5, lookup: 'articulos' },
  { label: 'Tienda', desde: 'tiendaDesde', hasta: 'tiendaHasta', maxLength: 3, formato: '', modo: 5, lookup: 'empresas' },
  { label: 'Agente', desde: 'agenteDesde', hasta: 'agenteHasta', maxLength: 4, formato: '', modo: 5, oculto: true },
  { label: 'Representante', desde: 'representanteDesde', hasta: 'representanteHasta', maxLength: 4, formato: '', modo: 5, oculto: true },
  { label: 'Vendedor', desde: 'vendedorDesde', hasta: 'vendedorHasta', maxLength: 4, formato: '', modo: 5 },
  { label: 'Cliente', desde: 'clienteDesde', hasta: 'clienteHasta', maxLength: 9, formato: '', modo: 5, lookup: 'clientes' },
  { label: 'Proveedor', desde: 'proveedorDesde', hasta: 'proveedorHasta', maxLength: 6, formato: '', modo: 5, lookup: 'proveedores' },
  { label: 'Seccion', desde: 'seccionDesde', hasta: 'seccionHasta', maxLength: 6, formato: '', modo: 5, lookup: 'secciones' },
  { label: 'SubSeccion', desde: 'subSeccionDesde', hasta: 'subSeccionHasta', maxLength: 6, formato: '', modo: 5, lookup: 'subSecciones' },
  { label: 'Actividad', desde: 'actividadDesde', hasta: 'actividadHasta', maxLength: 4, formato: '###0', modo: 6, lookup: 'actividades' },
  { label: 'Tipo Descuento', desde: 'tipoDescuentoDesde', hasta: 'tipoDescuentoHasta', maxLength: 6, formato: '', modo: 0 },
]

/** Ancho visual del input en ch (acortado respecto a un campo full-width). */
export function abcFiltroInputCh(maxLength: number): number {
  return Math.min(Math.max(maxLength + 1, 4), 20)
}

/** Solo digitos si el formato legado es mascara numerica (###0 / ####0 / #####0). */
export function abcFiltroSoloDigitos(formato: string): boolean {
  return /^[#0]+$/.test(formato.trim())
}
