export type FacturacionMenuItem = {
  id: string
  titulo: string
  ruta: string
  modulo: string
}

/**
 * Submenus del modulo Facturacion (orden cercano al legacy).
 */
export const facturacionMenuItems: FacturacionMenuItem[] = [
  {
    id: 'facturacion-albaranes-pendientes',
    titulo: 'Albaranes pendientes de facturar',
    ruta: '/facturacion/albaranes-pendientes',
    modulo: 'facturacion-albaranes-pendientes',
  },
  {
    id: 'facturacion-generacion',
    titulo: 'Generación de facturas',
    ruta: '/facturacion/generacion',
    modulo: 'facturacion-generacion',
  },
  {
    id: 'facturacion-manual',
    titulo: 'Generador de facturas Manual',
    ruta: '/facturacion/manual',
    modulo: 'facturacion-manual',
  },
  {
    id: 'facturacion-retroceso',
    titulo: 'Retroceso de facturas',
    ruta: '/facturacion/retroceso',
    modulo: 'facturacion-retroceso',
  },
  {
    id: 'facturacion-impresion',
    titulo: 'Impresión de facturas',
    ruta: '/facturacion/impresion',
    modulo: 'facturacion-impresion',
  },
  {
    id: 'facturacion-diario',
    titulo: 'Diario de facturación',
    ruta: '/facturacion/diario',
    modulo: 'facturacion-diario',
  },
]

export function esRutaFacturacion(ruta: string): boolean {
  return ruta === '/facturacion' || ruta.startsWith('/facturacion/')
}

export const facturacionNavPermisos = [
  { tipo: 'item' as const, id: 'facturacion', titulo: 'Facturacion (general)', modulo: 'facturacion' },
  ...facturacionMenuItems.map((i) => ({
    tipo: 'item' as const,
    id: i.id,
    titulo: i.titulo,
    modulo: i.modulo,
  })),
]
