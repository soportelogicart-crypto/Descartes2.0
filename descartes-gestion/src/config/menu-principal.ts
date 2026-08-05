export type MenuPrincipalSeccion = {
  id: string
  titulo: string
  /** Modulo de RolPermisos; si falta, la seccion se muestra a cualquier usuario autenticado. */
  modulo?: string
  /** Ruta placeholder de la seccion (sin submenus aun). */
  ruta: string
}

/**
 * Secciones del menu principal de Gestion.
 * Solo Mantenimiento tiene submenus implementados; el resto es estructura para ir completando.
 */
export const menuPrincipalSecciones: MenuPrincipalSeccion[] = [
  { id: 'mantenimiento', titulo: 'Mantenimiento', modulo: 'mantenimiento', ruta: '/' },
  { id: 'compras', titulo: 'Compras', modulo: 'compras', ruta: '/compras' },
  { id: 'ventas', titulo: 'Ventas', modulo: 'ventas', ruta: '/ventas' },
  { id: 'facturacion', titulo: 'Facturacion', modulo: 'facturacion', ruta: '/facturacion' },
  { id: 'inventario', titulo: 'Inventario', modulo: 'inventario', ruta: '/inventario' },
  { id: 'listados', titulo: 'Listados', ruta: '/listados' },
  { id: 'tpv', titulo: 'TPV', modulo: 'tpv', ruta: '/tpv' },
]
