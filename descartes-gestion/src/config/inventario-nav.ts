export type InventarioMenuItem = {
  id: string
  titulo: string
  ruta: string
  /** Informe servido por API listados. */
  modulo: string
}

/** Inventario → un acceso; dentro se elige macrofamilia, artículos, etc. */
export const inventarioMenuItems: InventarioMenuItem[] = [
  {
    id: 'stock',
    titulo: 'Listado de stock',
    ruta: '/listados/stock',
    modulo: 'listados-stock',
  },
]
