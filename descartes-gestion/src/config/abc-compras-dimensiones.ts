/** Dimensiones ABC compras (menú legacy ComprasAbc* / DVCOMPRASABC). */
export type AbcComprasDimensionId =
  | 'macrofamilias'
  | 'subfamilias'
  | 'familias'
  | 'articulos'
  | 'agrupaciones'
  | 'proveedores'
  | 'secciones'
  | 'subsecciones'
  | 'almacenes'

export const ABC_COMPRAS_DIMENSIONES: { value: AbcComprasDimensionId; label: string }[] = [
  { value: 'macrofamilias', label: 'Macrofamilias' },
  { value: 'subfamilias', label: 'Subfamilias' },
  { value: 'familias', label: 'Familias' },
  { value: 'articulos', label: 'Artículos' },
  { value: 'agrupaciones', label: 'Agrupaciones' },
  { value: 'proveedores', label: 'Proveedores' },
  { value: 'secciones', label: 'Secciones' },
  { value: 'subsecciones', label: 'Subsecciones' },
  { value: 'almacenes', label: 'Almacenes' },
]

export function etiquetaDimensionAbcCompras(id: string): string {
  return ABC_COMPRAS_DIMENSIONES.find((d) => d.value === id)?.label ?? id
}

const ETIQUETA_TOTAL_GRUPO: Partial<Record<AbcComprasDimensionId, string>> = {
  macrofamilias: 'TOTAL MACROFAMILIA',
  subfamilias: 'TOTAL SUBFAMILIA',
  familias: 'TOTAL FAMILIA',
  articulos: 'TOTAL ARTICULO',
  agrupaciones: 'TOTAL AGRUPACION',
  proveedores: 'TOTAL PROVEEDOR',
  secciones: 'TOTAL SECCION',
  subsecciones: 'TOTAL SUBSECCION',
  almacenes: 'TOTAL ALMACEN',
}

export function etiquetaTotalGrupoAbcCompras(dimension: string): string {
  const key = dimension.trim().toLowerCase() as AbcComprasDimensionId
  return ETIQUETA_TOTAL_GRUPO[key] ?? 'TOTAL'
}

export function etiquetaBloqueGrupoAbcCompras(dimension: string): string {
  const key = dimension.trim().toLowerCase() as AbcComprasDimensionId
  const map: Partial<Record<AbcComprasDimensionId, string>> = {
    subsecciones: 'SUBSECCION',
    secciones: 'SECCION',
    macrofamilias: 'MACROFAMILIA',
    subfamilias: 'SUBFAMILIA',
    familias: 'FAMILIA',
    agrupaciones: 'AGRUPACION',
    proveedores: 'PROVEEDOR',
    articulos: 'ARTICULO',
    almacenes: 'ALMACEN',
  }
  return map[key] ?? etiquetaDimensionAbcCompras(key).toUpperCase()
}

export type AbcComprasListadoDef = {
  catalogId: string
  dimension: AbcComprasDimensionId
  titulo: string
  subtitulo: string
}

export const ABC_COMPRAS_LISTADOS: AbcComprasListadoDef[] = ABC_COMPRAS_DIMENSIONES.map((d) => ({
  catalogId: `abc-compras-${d.value}`,
  dimension: d.value,
  titulo: `ABC de compras (${d.label})`,
  subtitulo: `Compras agregadas por ${d.label.toLowerCase()} en el periodo elegido.`,
}))

export function abcComprasListadoPorDimension(dimension: string): AbcComprasListadoDef | undefined {
  const key = dimension.trim().toLowerCase()
  return ABC_COMPRAS_LISTADOS.find((x) => x.dimension === key)
}
