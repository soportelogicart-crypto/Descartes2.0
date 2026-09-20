/** Dimensiones de agrupación ABC ventas (menú legacy VentasABC / VentasABCVen). */
export type AbcVentasDimensionId =
  | 'dias-semana'
  | 'semanal'
  | 'horas'
  | 'macrofamilias'
  | 'subfamilias'
  | 'familias'
  | 'articulos'
  | 'agrupaciones'
  | 'clientes'
  | 'proveedores'
  | 'secciones'
  | 'subsecciones'
  | 'perfiles'
  | 'vendedores'

export const ABC_VENTAS_DIMENSIONES: { value: AbcVentasDimensionId; label: string }[] = [
  { value: 'dias-semana', label: 'Días de la semana' },
  { value: 'semanal', label: 'Semanal' },
  { value: 'horas', label: 'Horas' },
  { value: 'macrofamilias', label: 'Macrofamilias' },
  { value: 'subfamilias', label: 'Subfamilias' },
  { value: 'familias', label: 'Familias' },
  { value: 'articulos', label: 'Artículos' },
  { value: 'agrupaciones', label: 'Agrupaciones' },
  { value: 'clientes', label: 'Clientes' },
  { value: 'proveedores', label: 'Proveedores' },
  { value: 'secciones', label: 'Secciones' },
  { value: 'subsecciones', label: 'Subsecciones' },
  { value: 'perfiles', label: 'Perfiles' },
  { value: 'vendedores', label: 'Vendedor' },
]

export function etiquetaDimensionAbc(id: string): string {
  return ABC_VENTAS_DIMENSIONES.find((d) => d.value === id)?.label ?? id
}

/** Fila de totales del bloque (Crystal «TOTAL SUBSECCION», etc.). */
const ETIQUETA_TOTAL_GRUPO: Partial<Record<AbcVentasDimensionId, string>> = {
  'dias-semana': 'TOTAL DIA',
  semanal: 'TOTAL SEMANA',
  horas: 'TOTAL HORA',
  macrofamilias: 'TOTAL MACROFAMILIA',
  subfamilias: 'TOTAL SUBFAMILIA',
  familias: 'TOTAL FAMILIA',
  articulos: 'TOTAL ARTICULO',
  agrupaciones: 'TOTAL AGRUPACION',
  clientes: 'TOTAL CLIENTE',
  proveedores: 'TOTAL PROVEEDOR',
  secciones: 'TOTAL SECCION',
  subsecciones: 'TOTAL SUBSECCION',
  perfiles: 'TOTAL PERFIL',
  vendedores: 'TOTAL VENDEDOR',
}

export function etiquetaTotalGrupoAbc(dimension: string): string {
  const key = dimension.trim().toLowerCase() as AbcVentasDimensionId
  return ETIQUETA_TOTAL_GRUPO[key] ?? 'TOTAL'
}

/** Cabecera del bloque sobre la tabla (legacy suele ir en mayúsculas). */
export function etiquetaBloqueGrupoAbc(dimension: string): string {
  const key = dimension.trim().toLowerCase() as AbcVentasDimensionId
  const map: Partial<Record<AbcVentasDimensionId, string>> = {
    subsecciones: 'SUBSECCION',
    secciones: 'SECCION',
    macrofamilias: 'MACROFAMILIA',
    subfamilias: 'SUBFAMILIA',
    familias: 'FAMILIA',
    agrupaciones: 'AGRUPACION',
    clientes: 'CLIENTE',
    proveedores: 'PROVEEDOR',
    perfiles: 'PERFIL',
    vendedores: 'VENDEDOR',
    articulos: 'ARTICULO',
  }
  return map[key] ?? etiquetaDimensionAbc(key).toUpperCase()
}

export type AbcVentasListadoDef = {
  catalogId: string
  dimension: AbcVentasDimensionId
  titulo: string
  subtitulo: string
}

/** Entradas del submenú (como listado de stock por agrupación). */
export const ABC_VENTAS_LISTADOS: AbcVentasListadoDef[] = ABC_VENTAS_DIMENSIONES.map((d) => ({
  catalogId: `abc-ventas-${d.value}`,
  dimension: d.value,
  titulo: `ABC de ventas (${d.label})`,
  subtitulo: `Ventas agregadas por ${d.label.toLowerCase()} en el periodo elegido.`,
}))

export function abcVentasListadoPorDimension(dimension: string): AbcVentasListadoDef | undefined {
  const key = dimension.trim().toLowerCase()
  return ABC_VENTAS_LISTADOS.find((x) => x.dimension === key)
}
