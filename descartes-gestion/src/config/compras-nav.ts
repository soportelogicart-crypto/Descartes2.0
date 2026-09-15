export type ComprasMenuItem = {
  id: string
  titulo: string
  ruta: string
  /** Modulo de RolPermisos. MVP: un solo módulo `compras` (research R-008). */
  modulo: string
}

/**
 * Submenus del modulo Compras (004-compras-gestion).
 */
export const comprasMenuItems: ComprasMenuItem[] = [
  {
    id: 'compras-albaranes',
    titulo: 'Albaranes de compra',
    ruta: '/compras/albaranes',
    modulo: 'compras',
  },
  {
    id: 'compras-pedidos',
    titulo: 'Pedidos a proveedor',
    ruta: '/compras/pedidos',
    modulo: 'compras',
  },
  {
    id: 'compras-pendientes-stock',
    titulo: 'Pendientes de stock',
    ruta: '/compras/pendientes-stock',
    modulo: 'compras',
  },
]

export function esRutaCompras(ruta: string): boolean {
  return ruta === '/compras' || ruta.startsWith('/compras/')
}

/**
 * Matriz de permisos: un solo módulo `compras` (MVP / research R-008).
 * Los submenus del nav comparten el mismo modulo.
 */
export const comprasNavPermisos = [
  { tipo: 'item' as const, id: 'compras', titulo: 'Compras', modulo: 'compras' },
]
