/** Combos legacy ABC Compras (DVCOMPRASABC). */

export const ABC_COMPRAS_ORDEN = [
  { value: 'importe', label: 'Importe' },
  { value: 'cantidad', label: 'Cantidad' },
  { value: 'coste', label: 'Coste' },
] as const

export const ABC_COMPRAS_ORDEN_MARGEN = [
  { value: 'margen', label: 'Margen' },
  ...ABC_COMPRAS_ORDEN,
] as const

export const ABC_COMPRAS_DIMENSIONES_ORDEN_MARGEN = [
  'secciones',
  'subsecciones',
  'proveedores',
  'agrupaciones',
  'articulos',
  'almacenes',
] as const

export const ABC_COMPRAS_DIMENSIONES_JERARQUIA = [
  'macrofamilias',
  'subfamilias',
  'familias',
] as const

export const ABC_COMPRAS_FORMATO_JERARQUIA = [
  { value: 'normal', label: 'Normal' },
  { value: 'pesoKilogramos', label: 'Peso Kilogramos' },
  { value: 'mediaImporte', label: 'Media Importe' },
  { value: 'agrSinTotales', label: 'Agr. sin totales' },
  { value: 'agrConTotales', label: 'Agr. con totales' },
] as const

/** DVCOMPRASABC — Im. artículos (Si / No / Desglosado). */
export const ABC_COMPRAS_IM_ARTICULOS = [
  { value: 'si', label: 'Si' },
  { value: 'no', label: 'No' },
  { value: 'desglosado', label: 'Desglosado' },
] as const

export type AbcComprasImArticulos = (typeof ABC_COMPRAS_IM_ARTICULOS)[number]['value']

export const ABC_COMPRAS_DIVISAS = [
  { value: 'EU', label: 'Eu' },
  { value: 'PES', label: 'Pes' },
] as const

export type AbcComprasDivisa = (typeof ABC_COMPRAS_DIVISAS)[number]['value']

const ORDEN_IMPORTE_CANTIDAD = [
  { value: 'importe', label: 'Importe' },
  { value: 'cantidad', label: 'Cantidad' },
] as const

/** Macro / SubFam / Fam — Orden legacy (sin Margen). */
export const ABC_COMPRAS_ORDEN_JERARQUIA: Record<
  (typeof ABC_COMPRAS_DIMENSIONES_JERARQUIA)[number],
  readonly { value: string; label: string }[]
> = {
  macrofamilias: [
    ...ORDEN_IMPORTE_CANTIDAD,
    { value: 'macrofamilias', label: 'MacroFamilias' },
  ],
  subfamilias: [...ORDEN_IMPORTE_CANTIDAD, { value: 'subfamilias', label: 'SubFamilias' }],
  familias: [...ORDEN_IMPORTE_CANTIDAD, { value: 'familias', label: 'Familias' }],
}

export const ABC_COMPRAS_VALOR = [
  { value: 'precioMedio', label: 'Precio medio' },
  { value: 'precioMedioActual', label: 'Precio medio actual' },
  { value: 'ultimoPrecio', label: 'Último precio' },
  { value: 'sinValorTarifa', label: 'Sin valor tarifa' },
] as const

export type AbcComprasOrden =
  | (typeof ABC_COMPRAS_ORDEN_MARGEN)[number]['value']
  | (typeof ABC_COMPRAS_ORDEN)[number]['value']
  | 'macrofamilias'
  | 'subfamilias'
  | 'familias'

export function abcComprasOrdenPorDimension(dimension: string) {
  const key = dimension.trim().toLowerCase()
  if ((ABC_COMPRAS_DIMENSIONES_JERARQUIA as readonly string[]).includes(key)) {
    return ABC_COMPRAS_ORDEN_JERARQUIA[key as keyof typeof ABC_COMPRAS_ORDEN_JERARQUIA]
  }
  if ((ABC_COMPRAS_DIMENSIONES_ORDEN_MARGEN as readonly string[]).includes(key)) {
    return ABC_COMPRAS_ORDEN_MARGEN
  }
  return ABC_COMPRAS_ORDEN
}

export function abcComprasOrdenDefectoPorDimension(dimension: string): AbcComprasOrden {
  const key = dimension.trim().toLowerCase()
  if ((ABC_COMPRAS_DIMENSIONES_JERARQUIA as readonly string[]).includes(key)) {
    return 'importe'
  }
  if ((ABC_COMPRAS_DIMENSIONES_ORDEN_MARGEN as readonly string[]).includes(key)) {
    return 'margen'
  }
  return 'importe'
}

export function abcComprasMuestraOpcionesJerarquia(dimension: string): boolean {
  return (ABC_COMPRAS_DIMENSIONES_JERARQUIA as readonly string[]).includes(
    dimension.trim().toLowerCase(),
  )
}

/** ComprasABC SubFam — combo Formato (legacy). */
export const ABC_COMPRAS_FORMATO_SUBFAMILIAS = [
  { value: 'normal', label: 'Normal' },
  { value: 'extendido', label: 'Extendido' },
] as const

export type AbcComprasFormatoSubfamilias =
  (typeof ABC_COMPRAS_FORMATO_SUBFAMILIAS)[number]['value']

export function abcComprasMuestraComboFormato(dimension: string): boolean {
  const k = dimension.trim().toLowerCase()
  return k === 'familias' || k === 'subfamilias'
}

/** @deprecated use abcComprasMuestraComboFormato */
export function abcComprasMuestraFormatoFamilias(dimension: string): boolean {
  return abcComprasMuestraComboFormato(dimension)
}

export function abcComprasFormatoOpcionesPorDimension(dimension: string) {
  const k = dimension.trim().toLowerCase()
  if (k === 'familias') return ABC_COMPRAS_FORMATO_JERARQUIA
  if (k === 'subfamilias') return ABC_COMPRAS_FORMATO_SUBFAMILIAS
  return [] as const
}

/** SubFam — formulario legacy sin Valor ni «Solo actualizado stock». */
export function abcComprasOcultaValorYSoloActualizado(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'subfamilias'
}

export function abcComprasUsaFormatoExtendidoSubfamilias(
  dimension: string,
  formatoJerarquia: string | undefined,
): boolean {
  return (
    dimension.trim().toLowerCase() === 'subfamilias' &&
    (formatoJerarquia ?? 'normal').trim().toLowerCase() === 'extendido'
  )
}

export function abcComprasEtiquetaUnidadesJerarquia(formatoJerarquia: string | undefined): string {
  const k = (formatoJerarquia ?? 'normal').trim().toLowerCase()
  if (k === 'pesokilogramos') return 'Kilos'
  if (k === 'mediaimporte') return 'Media Imp.'
  return 'Unidades'
}

export function abcComprasUsaTablaPlanaArticulos(dimension: string): boolean {
  return dimension.trim().toLowerCase() === 'articulos'
}
