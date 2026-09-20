import type { EntidadLookupId } from '@/config/entidad-lookup'
import type { AbcComprasDimensionId } from '@/config/abc-compras-dimensiones'

export type AbcComprasFiltroRango = {
  label: string
  desde: string
  hasta: string
  maxLength: number
  formato: string
  modo: number
  entidad?: EntidadLookupId
}

const RANGO_MACRO_FAMILIA: AbcComprasFiltroRango = {
  label: 'MacroFamilia',
  desde: 'macroFamiliaDesde',
  hasta: 'macroFamiliaHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'macrofamilias',
}

const RANGO_FAMILIA: AbcComprasFiltroRango = {
  label: 'Familia',
  desde: 'familiaDesde',
  hasta: 'familiaHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'familias',
}

const RANGO_SUBFAMILIA: AbcComprasFiltroRango = {
  label: 'Subfamilia',
  desde: 'subfamiliaDesde',
  hasta: 'subfamiliaHasta',
  maxLength: 5,
  formato: '####0',
  modo: 6,
  entidad: 'subfamilias',
}

const RANGO_AGRUPACION: AbcComprasFiltroRango = {
  label: 'Agrupacion',
  desde: 'agrupacionDesde',
  hasta: 'agrupacionHasta',
  maxLength: 4,
  formato: '###0',
  modo: 6,
  entidad: 'agrupaciones',
}

const RANGO_ARTICULO: AbcComprasFiltroRango = {
  label: 'Articulo',
  desde: 'articuloDesde',
  hasta: 'articuloHasta',
  maxLength: 18,
  formato: '',
  modo: 5,
  entidad: 'articulos',
}

const RANGO_TIENDA: AbcComprasFiltroRango = {
  label: 'Tienda',
  desde: 'tiendaDesde',
  hasta: 'tiendaHasta',
  maxLength: 3,
  formato: '',
  modo: 5,
  entidad: 'tiendas',
}

const RANGO_PROVEEDOR: AbcComprasFiltroRango = {
  label: 'Proveedor',
  desde: 'proveedorDesde',
  hasta: 'proveedorHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'proveedores',
}

const RANGO_ALMACEN: AbcComprasFiltroRango = {
  label: 'Almacen',
  desde: 'almacenDesde',
  hasta: 'almacenHasta',
  maxLength: 4,
  formato: '####0',
  modo: 6,
  entidad: 'almacenes',
}

const RANGO_SECCION: AbcComprasFiltroRango = {
  label: 'Seccion',
  desde: 'seccionDesde',
  hasta: 'seccionHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'secciones',
}

const RANGO_SUBSECCION: AbcComprasFiltroRango = {
  label: 'SubSeccion',
  desde: 'subSeccionDesde',
  hasta: 'subSeccionHasta',
  maxLength: 6,
  formato: '',
  modo: 5,
  entidad: 'subsecciones',
}

const RANGO_ULTIMA_VENTA: AbcComprasFiltroRango = {
  label: 'Ultima venta',
  desde: 'ultimaVentaDesde',
  hasta: 'ultimaVentaHasta',
  maxLength: 10,
  formato: '',
  modo: 1,
}

/** Almacén central (flag 0/1 legacy ComprasABC SubFam). */
const RANGO_CENTRAL: AbcComprasFiltroRango = {
  label: 'Central',
  desde: 'centralDesde',
  hasta: 'centralHasta',
  maxLength: 1,
  formato: '#',
  modo: 6,
}

const RANGO_LOTE: AbcComprasFiltroRango = {
  label: 'Lote',
  desde: 'loteDesde',
  hasta: 'loteHasta',
  maxLength: 30,
  formato: '',
  modo: 5,
}

/** Intervalos habituales ComprasABC (familias, proveedor cabecera, almacén…). */
export const ABC_COMPRAS_INTERVALOS_BASE: AbcComprasFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_PROVEEDOR,
  RANGO_ALMACEN,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_LOTE,
]

/** ComprasABC SubFam — orden intervalos legacy. */
export const ABC_COMPRAS_INTERVALOS_SUBFAMILIAS: AbcComprasFiltroRango[] = [
  RANGO_MACRO_FAMILIA,
  RANGO_FAMILIA,
  RANGO_SUBFAMILIA,
  RANGO_AGRUPACION,
  RANGO_ARTICULO,
  RANGO_TIENDA,
  RANGO_PROVEEDOR,
  RANGO_SECCION,
  RANGO_SUBSECCION,
  RANGO_ULTIMA_VENTA,
  RANGO_LOTE,
  RANGO_CENTRAL,
  RANGO_ALMACEN,
]

export function abcComprasIntervalosPorDimension(dimension: string): AbcComprasFiltroRango[] {
  if (dimension.trim().toLowerCase() === 'subfamilias') {
    return ABC_COMPRAS_INTERVALOS_SUBFAMILIAS
  }
  return ABC_COMPRAS_INTERVALOS_BASE
}

export function abcFiltroSoloDigitosCompras(formato: string): boolean {
  return formato.includes('#')
}

export type AbcComprasDimensionIdFilter = AbcComprasDimensionId
