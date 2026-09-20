import type { NavPermisoNodo } from '@/config/mantenimiento-nav-permisos'
import {
  ABC_VENTAS_MODULOS_PERMISO,
  STOCK_LISTADO_MODULOS_PERMISO,
  puedeAccederHubAbc,
  puedeAccederHubStock,
} from '@/config/listados-permisos'

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
    ruta: '/listados/abc-ventas',
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
    modulo: 'listados-informe-tickets',
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
    modulo: 'listados-extracto-clientes',
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
    titulo: 'Listado de stock',
    descripcion:
      'Existencias por almacén; macrofamilia, familia, artículo, agrupación, proveedor…',
    categoria: 'stock',
    palabrasClave: [
      'stock',
      'existencias',
      'almacen',
      'inventario',
      'macrofamilia',
      'familia',
      'articulo',
      'agrupacion',
      'proveedor',
    ],
    kind: 'informe',
    ruta: '/listados/stock',
    modulo: 'listados-stock',
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
    modulo: 'listados-stock-minimos',
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
    modulo: 'listados-informe-iva',
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

const INFORMES_SIN_SUBMENU = LISTADOS_CATALOGO.filter(
  (i) => i.kind === 'informe' && i.id !== 'stock' && i.id !== 'abc-ventas',
)

/** Matriz de permisos en Mantenimiento → Roles (sección Listados). */
export const listadosNavPermisos: NavPermisoNodo[] = [
  { tipo: 'item', id: 'listados-hub', titulo: 'Catálogo de listados (hub)', modulo: 'listados' },
  {
    tipo: 'grupo',
    id: 'listados-informes',
    titulo: 'Informes del catálogo',
    children: INFORMES_SIN_SUBMENU.map((i) => ({
      id: i.id,
      titulo: i.titulo,
      modulo: i.modulo,
    })),
  },
  {
    tipo: 'grupo',
    id: 'listados-stock-submenu',
    titulo: 'Listado de stock (agrupaciones)',
    children: [
      { id: 'listados-stock-padre', titulo: 'Acceso al submenú (general)', modulo: 'listados-stock' },
      ...STOCK_LISTADO_MODULOS_PERMISO.map((s) => ({
        id: s.id,
        titulo: s.titulo,
        modulo: s.modulo,
      })),
    ],
  },
  {
    tipo: 'grupo',
    id: 'listados-abc-submenu',
    titulo: 'ABC de ventas (dimensiones)',
    children: [
      { id: 'ventas-abc-padre', titulo: 'Acceso al submenú (general)', modulo: 'ventas-abc' },
      ...ABC_VENTAS_MODULOS_PERMISO.map((s) => ({
        id: s.id,
        titulo: s.titulo,
        modulo: s.modulo,
      })),
    ],
  },
]

type PuedeFn = (modulo: string, accion: 'ver' | 'crear' | 'editar' | 'eliminar') => boolean

export function puedeVerListadoCatalogo(
  item: ListadoCatalogoItem,
  puede: PuedeFn,
): boolean {
  if (!item.disponible) return false
  return itemHabilitadoEnCatalogo(item, puede)
}

/** Si el usuario puede abrir la tarjeta / informe (submenús incluidos). */
export function itemHabilitadoEnCatalogo(item: ListadoCatalogoItem, puede: PuedeFn): boolean {
  if (!item.disponible) return false
  if (item.id === 'stock') return puedeAccederHubStock(puede)
  if (item.id === 'abc-ventas') return puedeAccederHubAbc(puede)
  return puede(item.modulo, 'ver')
}

/** Informes visibles en el hub (se muestran aunque estén deshabilitados). */
export function informesEnCatalogoHub(): ListadoCatalogoItem[] {
  return LISTADOS_CATALOGO.filter((i) => i.kind === 'informe' && i.disponible)
}

/** Algún informe del hub visible (sin contar accesos a otros módulos). */
export function algunInformeListadoVisible(puede: PuedeFn): boolean {
  return informesEnCatalogoHub().some((i) => itemHabilitadoEnCatalogo(i, puede))
}

export function puedeAccederHubListados(puede: PuedeFn): boolean {
  return puede('listados', 'ver') || algunInformeListadoVisible(puede)
}

export function itemCoincideBusqueda(item: ListadoCatalogoItem, consulta: string): boolean {
  const q = normalizarTextoBusqueda(consulta.trim())
  if (!q) return true
  const blob = normalizarTextoBusqueda(
    [item.titulo, item.descripcion, ...item.palabrasClave].join(' ')
  )
  return blob.includes(q)
}
