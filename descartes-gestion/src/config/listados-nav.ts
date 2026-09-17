/**
 * Catálogo declarativo de listados (spec 009). Informes nuevos viven bajo `/listados/...`;
 * accesos reutilizan rutas ya existentes en Ventas, Compras o Facturación.
 */
export type ListadosCategoriaId = 'maestros' | 'ventas' | 'compras' | 'stock' | 'fiscal'

export type ListadoCatalogoItem = {
  id: string
  titulo: string
  descripcion: string
  categoria: ListadosCategoriaId
  palabrasClave: string[]
  /** `informe` = consulta con plantilla común; `acceso` = enlace a pantalla operativa. */
  kind: 'informe' | 'acceso'
  ruta: string
  /** Módulo de permiso (`ver`). Hub además exige `listados`. */
  modulo: string
  /** false = visible en el hub pero aún sin pantalla/API (Próximamente). */
  disponible: boolean
}

export const LISTADOS_CATEGORIAS: { id: ListadosCategoriaId; titulo: string }[] = [
  { id: 'maestros', titulo: 'Maestros' },
  { id: 'ventas', titulo: 'Ventas' },
  { id: 'compras', titulo: 'Compras' },
  { id: 'stock', titulo: 'Stock' },
  { id: 'fiscal', titulo: 'Fiscal y cobros' },
]

export const LISTADOS_CATALOGO: ListadoCatalogoItem[] = [
  {
    id: 'abc-ventas',
    titulo: 'ABC de ventas',
    descripcion: 'Análisis de ventas por periodo; elija la dimensión de agrupación.',
    categoria: 'ventas',
    palabrasClave: ['abc', 'ventas', 'margen', 'familia', 'articulo', 'cliente', 'vendedor'],
    kind: 'informe',
    ruta: '/ventas/abc',
    modulo: 'ventas-abc',
    disponible: true,
  },
  {
    id: 'informe-tickets',
    titulo: 'Informe de tickets / diario de ventas',
    descripcion: 'Resumen de tickets y ventas de mostrador en un periodo.',
    categoria: 'ventas',
    palabrasClave: ['tickets', 'diario', 'ventas', 'mostrador', 'tpv'],
    kind: 'informe',
    ruta: '/listados/informe-tickets',
    modulo: 'listados',
    disponible: true,
  },
  {
    id: 'extracto-clientes',
    titulo: 'Extracto de clientes',
    descripcion: 'Movimientos y saldo de clientes en un periodo.',
    categoria: 'ventas',
    palabrasClave: ['extracto', 'clientes', 'cobros', 'saldo', 'deuda'],
    kind: 'informe',
    ruta: '/listados/extracto-clientes',
    modulo: 'listados',
    disponible: true,
  },
  {
    id: 'ventas-arqueo',
    titulo: 'Arqueo de caja',
    descripcion: 'Consulta de arqueos por puesto y fecha (Ventas).',
    categoria: 'ventas',
    palabrasClave: ['arqueo', 'caja', 'efectivo'],
    kind: 'acceso',
    ruta: '/ventas/arqueo',
    modulo: 'ventas-arqueo',
    disponible: true,
  },
  {
    id: 'ventas-anulaciones',
    titulo: 'Diario de anulaciones',
    descripcion: 'Registro de anulaciones de venta.',
    categoria: 'ventas',
    palabrasClave: ['anulaciones', 'anulacion', 'ventas'],
    kind: 'acceso',
    ruta: '/ventas/anulaciones',
    modulo: 'ventas-anulaciones',
    disponible: true,
  },
  {
    id: 'ventas-cobros-pagos',
    titulo: 'Cobros y pagos',
    descripcion: 'Movimientos de cobros y pagos en caja.',
    categoria: 'ventas',
    palabrasClave: ['cobros', 'pagos', 'caja'],
    kind: 'acceso',
    ruta: '/ventas/cobros-pagos',
    modulo: 'ventas-cobros-pagos',
    disponible: true,
  },
  {
    id: 'compras-pendientes-stock',
    titulo: 'Pendientes de stock (compras)',
    descripcion: 'Material pendiente de servir desde compras.',
    categoria: 'compras',
    palabrasClave: ['pendientes', 'stock', 'compras', 'servir'],
    kind: 'acceso',
    ruta: '/compras/pendientes-stock',
    modulo: 'compras',
    disponible: true,
  },
  {
    id: 'stock',
    titulo: 'Stock',
    descripcion: 'Existencias por almacén; agrupe por artículo, familia, proveedor…',
    categoria: 'stock',
    palabrasClave: ['stock', 'existencias', 'almacen', 'inventario'],
    kind: 'informe',
    ruta: '/listados/stock',
    modulo: 'listados',
    disponible: true,
  },
  {
    id: 'stock-minimos',
    titulo: 'Stock bajo mínimos',
    descripcion: 'Artículos por debajo del stock mínimo.',
    categoria: 'stock',
    palabrasClave: ['minimos', 'minimo', 'stock', 'reposicion'],
    kind: 'informe',
    ruta: '/listados/stock-minimos',
    modulo: 'listados',
    disponible: true,
  },
  {
    id: 'informe-iva',
    titulo: 'Informe de IVA',
    descripcion: 'Ventas desglosadas por tipo de IVA en un periodo.',
    categoria: 'fiscal',
    palabrasClave: ['iva', 'impuesto', 'fiscal', 'bases'],
    kind: 'informe',
    ruta: '/listados/informe-iva',
    modulo: 'listados',
    disponible: true,
  },
  {
    id: 'facturacion-diario',
    titulo: 'Diario de facturación',
    descripcion: 'Facturas emitidas con filtros, Excel y PDF.',
    categoria: 'fiscal',
    palabrasClave: ['diario', 'facturacion', 'facturas'],
    kind: 'acceso',
    ruta: '/facturacion/diario',
    modulo: 'facturacion-diario',
    disponible: true,
  },
  {
    id: 'mantenimiento-clientes',
    titulo: 'Clientes (mantenimiento)',
    descripcion: 'Abrir el grid de clientes para consultar o exportar desde el mantenimiento.',
    categoria: 'maestros',
    palabrasClave: ['clientes', 'maestro'],
    kind: 'acceso',
    ruta: '/mantenimiento/clientes',
    modulo: 'clientes',
    disponible: true,
  },
  {
    id: 'mantenimiento-articulos',
    titulo: 'Artículos (mantenimiento)',
    descripcion: 'Abrir el grid de artículos.',
    categoria: 'maestros',
    palabrasClave: ['articulos', 'articulo', 'maestro'],
    kind: 'acceso',
    ruta: '/mantenimiento/articulos',
    modulo: 'articulos',
    disponible: true,
  },
  {
    id: 'mantenimiento-proveedores',
    titulo: 'Proveedores (mantenimiento)',
    descripcion: 'Abrir el grid de proveedores.',
    categoria: 'maestros',
    palabrasClave: ['proveedores', 'maestro'],
    kind: 'acceso',
    ruta: '/mantenimiento/proveedores',
    modulo: 'proveedores',
    disponible: true,
  },
]

export function listadoPorId(id: string): ListadoCatalogoItem | undefined {
  return LISTADOS_CATALOGO.find((i) => i.id === id)
}

/** Texto normalizado para buscador del hub (sin acentos, minúsculas). */
export function normalizarTextoBusqueda(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

/** Matriz de permisos en Mantenimiento → Roles (sección Listados). */
export const listadosNavPermisos = [
  { tipo: 'item' as const, id: 'listados-modulo', titulo: 'Listados (informes)', modulo: 'listados' },
]

export function itemCoincideBusqueda(item: ListadoCatalogoItem, consulta: string): boolean {
  const q = normalizarTextoBusqueda(consulta.trim())
  if (!q) return true
  const blob = normalizarTextoBusqueda(
    [item.titulo, item.descripcion, ...item.palabrasClave].join(' ')
  )
  return blob.includes(q)
}
