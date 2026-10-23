export type TpvMenuItem = {
  id: string
  titulo: string
  ruta: string
  modulo: string
}

export const tpvMenuItems: TpvMenuItem[] = [
  { id: 'tpv-venta', titulo: 'Venta táctil', ruta: '/tpv', modulo: 'tpv' },
]

/** Nodos planos para la matriz de permisos (sección TPV desplegable). */
export const tpvNavPermisos = tpvMenuItems.map((i) => ({
  tipo: 'item' as const,
  id: i.id,
  titulo: i.titulo,
  modulo: i.modulo,
}))
