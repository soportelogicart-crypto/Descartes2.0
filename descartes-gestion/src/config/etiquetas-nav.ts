export type EtiquetasMenuItem = {
  id: string
  titulo: string
  ruta: string
  /** Módulo de RolPermisos. */
  modulo: string
}

/**
 * Entradas del menú Etiquetas (005-etiquetas-gestion).
 * MVP: la sección es la cola de impresión (`EtiquetasArticulo`).
 */
export const etiquetasMenuItems: EtiquetasMenuItem[] = [
  {
    id: 'etiquetas-cola',
    titulo: 'Cola de impresión',
    ruta: '/etiquetas',
    modulo: 'etiquetas',
  },
]

export function esRutaEtiquetas(ruta: string): boolean {
  return ruta === '/etiquetas' || ruta.startsWith('/etiquetas/')
}

/**
 * Matriz de permisos (roles): un solo módulo `etiquetas`.
 */
export const etiquetasNavPermisos = [
  { tipo: 'item' as const, id: 'etiquetas', titulo: 'Etiquetas', modulo: 'etiquetas' },
]
