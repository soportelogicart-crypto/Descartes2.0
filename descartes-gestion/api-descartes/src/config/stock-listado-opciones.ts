/** Combos legacy (frmListado stock inventario) — etiquetas como en 1.0. */

export const STOCK_DIVISAS = [{ value: 'EU', label: 'Eu' }] as const

export const STOCK_SI_NO = [
  { value: 'si', label: 'Si' },
  { value: 'no', label: 'No' },
] as const

/** Tipo búsqueda (artículos) / Stock (agrupado). */
export const STOCK_FILTRO_EXISTENCIAS = [
  { value: 'todos', label: 'TODOS' },
  { value: 'superior_0', label: 'STOCK SUPERIOR 0' },
  { value: 'menor_0', label: 'STOCK MENOR 0' },
  { value: 'diferente_0', label: 'STOCK DIFERENTE 0' },
  { value: 'igual_0', label: 'STOCK IGUAL 0' },
  { value: 'bloqueo_venta', label: 'BLOQUEADO VENTA' },
] as const

export type StockFiltroExistencias = (typeof STOCK_FILTRO_EXISTENCIAS)[number]['value']

export const STOCK_TARIFAS = [
  { value: 'sin_valorar', label: 'Sin valorar tarifa' },
  { value: 'tarifa_1', label: 'Tarifa 1' },
  { value: 'tarifa_2', label: 'Tarifa 2' },
  { value: 'tarifa_3', label: 'Tarifa 3' },
  { value: 'tarifa_4', label: 'Tarifa 4' },
  { value: 'tarifa_5', label: 'Tarifa 5' },
  { value: 'tarifa_6', label: 'Tarifa 6' },
  { value: 'tarifa_7', label: 'Tarifa 7' },
  { value: 'tarifa_8', label: 'Tarifa 8' },
  { value: 'tarifa_9', label: 'Tarifa 9' },
] as const

export const STOCK_ALMACENES_MODO = [
  { value: 'desglosado', label: 'Desglosado' },
  { value: 'resumido', label: 'Resumido' },
] as const

export const STOCK_FORMATOS_ARTICULOS = [
  { value: 'normal', label: 'Normal' },
  { value: 'ean', label: 'Ean' },
  { value: 'grafico', label: 'Grafico' },
  { value: 'control_stock', label: 'Control Stock' },
  { value: 'movimiento_codigo', label: 'Movimiento stock (Codigo)' },
  { value: 'movimiento_orden', label: 'Movimiento stock (Orden codigo)' },
  { value: 'compra_ventas', label: 'Compra/Ventas' },
  { value: 'ver_tarifa', label: 'Ver Tarifa' },
] as const

export const STOCK_FORMATOS_AGRUPADO = [
  { value: 'normal', label: 'Normal' },
  { value: 'ean', label: 'Ean' },
  { value: 'grafico', label: 'Grafico' },
  { value: 'control_stock', label: 'Control Stock' },
  { value: 'extendido', label: 'Extendido' },
] as const

export const STOCK_FORMATO_CLIENTE_ARTICULOS = [
  { value: 'si', label: 'Si' },
  { value: 'matricial', label: 'Matricial' },
  { value: 'ubicacion', label: 'Ubicación' },
] as const

export const STOCK_FORMATO_CLIENTE_AGRUPADO = [
  { value: 'no', label: 'No' },
  { value: 'si', label: 'Si' },
  { value: 'matricial', label: 'Matricial' },
] as const
