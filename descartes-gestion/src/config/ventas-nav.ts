export type VentasMenuItem = {
  id: string
  titulo: string
  ruta: string
  /** Modulo de RolPermisos (igual que entradas de Mantenimiento). */
  modulo: string
}

/**
 * Submenus del modulo Ventas.
 * Desglose de arqueo = informe de ventas (legacy Crystal/Registradora), no monedas.
 */
export const ventasMenuItems: VentasMenuItem[] = [
  { id: 'ventas-listado', titulo: 'Ventas', ruta: '/ventas', modulo: 'ventas' },
  { id: 'arqueo', titulo: 'Arqueo de caja', ruta: '/ventas/arqueo', modulo: 'ventas-arqueo' },
  {
    id: 'arqueo-desglose',
    titulo: 'Desglose de arqueo',
    ruta: '/ventas/arqueo/desglose',
    modulo: 'ventas-arqueo-desglose',
  },
  {
    id: 'anulaciones',
    titulo: 'Diario de anulaciones',
    ruta: '/ventas/anulaciones',
    modulo: 'ventas-anulaciones',
  },
  {
    id: 'cobros-pagos',
    titulo: 'Cobros y Pagos',
    ruta: '/ventas/cobros-pagos',
    modulo: 'ventas-cobros-pagos',
  },
  {
    id: 'vales',
    titulo: 'Liquidacion de Vales',
    ruta: '/ventas/vales',
    modulo: 'ventas-vales',
  },
  {
    id: 'pedidos',
    titulo: 'Pedido de Clientes',
    ruta: '/ventas/pedidos',
    modulo: 'ventas-pedidos',
  },
  {
    id: 'abc-ventas',
    titulo: 'Listado ABC Ventas',
    ruta: '/listados/abc-ventas',
    modulo: 'ventas-abc',
  },
]

export function esRutaVentas(ruta: string): boolean {
  return ruta === '/ventas' || ruta.startsWith('/ventas/')
}

/** Nodos planos para la matriz de permisos (seccion Ventas desplegable). */
export const ventasNavPermisos = ventasMenuItems.map((i) => ({
  tipo: 'item' as const,
  id: i.id,
  titulo: i.titulo,
  modulo: i.modulo,
}))
