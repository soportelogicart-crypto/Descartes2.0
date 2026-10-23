export type InventarioMenuItem = {
  id: string
  titulo: string
  ruta: string
  /** Informe servido por API listados. */
  modulo: string
}

/** Inventario: recuento y listados de existencias. */
export const inventarioMenuItems: InventarioMenuItem[] = [
  {
    id: 'recuento',
    titulo: 'Recuento de inventario',
    ruta: '/inventario',
    modulo: 'inventario',
  },
  {
    id: 'stock',
    titulo: 'Listado de stock',
    ruta: '/listados/stock',
    modulo: 'listados-stock',
  },
]
