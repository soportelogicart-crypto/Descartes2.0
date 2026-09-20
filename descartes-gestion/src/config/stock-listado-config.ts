import type { StockAgruparPor } from '@/api/listados'
import type { EntidadLookupId } from '@/config/entidad-lookup'

export type StockListadoVariant = 'articulos' | 'agrupado'

export type StockIntervalDef = {
  /** Prefijo de claves form: `${key}Desde` / `${key}Hasta`. */
  key: string
  label: string
  entidad?: EntidadLookupId
  input?: 'text' | 'number' | 'date'
  /** Legacy: año/mes = un solo valor (no rango desde/hasta). */
  modo?: 'rango' | 'valor'
}

export type StockListadoDef = {
  catalogId: string
  agruparPor: StockAgruparPor
  variant: StockListadoVariant
  titulo: string
  subtitulo: string
  intervalos: StockIntervalDef[]
}

const INTERVALOS_AGRUPADO: StockIntervalDef[] = [
  { key: 'ano', label: 'Año', input: 'number', modo: 'valor' },
  { key: 'mes', label: 'Mes', input: 'number', modo: 'valor' },
  { key: 'almacen', label: 'Almacén', entidad: 'almacenes', input: 'number' },
  { key: 'macrofamilia', label: 'Macrofamilia', entidad: 'macrofamilias' },
  { key: 'familia', label: 'Familia', entidad: 'familias' },
  { key: 'subfamilia', label: 'Subfamilia', entidad: 'subfamilias' },
  { key: 'agrupacion', label: 'Agrupación', entidad: 'agrupaciones' },
  { key: 'articulo', label: 'Artículo', entidad: 'articulos' },
  { key: 'proveedor', label: 'Proveedor', entidad: 'proveedores' },
  { key: 'seccion', label: 'Sección', entidad: 'secciones' },
  { key: 'subseccion', label: 'Subsección', entidad: 'subsecciones' },
]

const INTERVALOS_ARTICULOS: StockIntervalDef[] = [
  ...INTERVALOS_AGRUPADO,
  { key: 'ultimaVenta', label: 'Última venta', input: 'date' },
  { key: 'fechaAlta', label: 'Fecha alta', input: 'date' },
  { key: 'ultCompra', label: 'Ult. compra', input: 'date' },
  { key: 'ubicacion', label: 'Ubicación', input: 'text' },
]

export const STOCK_LISTADOS: StockListadoDef[] = [
  {
    catalogId: 'stock-macrofamilias',
    agruparPor: 'macrofamilia',
    variant: 'agrupado',
    titulo: 'Listado de stock (Macrofamilias)',
    subtitulo: 'Existencias agrupadas por macrofamilia (legacy inventario).',
    intervalos: INTERVALOS_AGRUPADO,
  },
  {
    catalogId: 'stock-subfamilias',
    agruparPor: 'subfamilia',
    variant: 'agrupado',
    titulo: 'Listado de stock (Subfamilias)',
    subtitulo: 'Existencias agrupadas por subfamilia.',
    intervalos: INTERVALOS_AGRUPADO,
  },
  {
    catalogId: 'stock-familias',
    agruparPor: 'familia',
    variant: 'agrupado',
    titulo: 'Listado de stock (Familias)',
    subtitulo: 'Existencias agrupadas por familia.',
    intervalos: INTERVALOS_AGRUPADO,
  },
  {
    catalogId: 'stock-articulos',
    agruparPor: 'articulo',
    variant: 'articulos',
    titulo: 'Listado de stock (Artículos)',
    subtitulo: 'Existencias desglosadas por artículo.',
    intervalos: INTERVALOS_ARTICULOS,
  },
  {
    catalogId: 'stock-agrupaciones',
    agruparPor: 'agrupacion',
    variant: 'agrupado',
    titulo: 'Listado de stock (Agrupaciones)',
    subtitulo: 'Existencias agrupadas por agrupación.',
    intervalos: INTERVALOS_AGRUPADO,
  },
  {
    catalogId: 'stock-proveedores',
    agruparPor: 'proveedor',
    variant: 'agrupado',
    titulo: 'Listado de stock (Proveedores)',
    subtitulo: 'Existencias agrupadas por proveedor habitual.',
    intervalos: INTERVALOS_AGRUPADO,
  },
]

export function stockListadoPorAgrupar(agruparPor: string): StockListadoDef | undefined {
  return STOCK_LISTADOS.find((s) => s.agruparPor === agruparPor)
}

export function stockListadoPorCatalogId(id: string): StockListadoDef | undefined {
  return STOCK_LISTADOS.find((s) => s.catalogId === id)
}

/** Claves API para rangos de código (desde/hasta en query). */
export const STOCK_RANGO_API_KEYS: Record<string, { column: string; prefix: string }> = {
  macrofamilia: { column: 'f.[MacroFamilia]', prefix: 'mf' },
  familia: { column: 'a.[Familia]', prefix: 'fam' },
  subfamilia: { column: 'a.[Subfamilia]', prefix: 'sf' },
  agrupacion: { column: 'a.[Agrupacion]', prefix: 'ag' },
  articulo: { column: 'a.[Codigo]', prefix: 'art' },
  proveedor: { column: 'a.[UltProveedor]', prefix: 'prov' },
  seccion: { column: 'a.[Seccion]', prefix: 'sec' },
  subseccion: { column: 'a.[SubSeccion]', prefix: 'ssec' },
}
