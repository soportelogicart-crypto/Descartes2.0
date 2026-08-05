/**
 * Si un modulo hijo no viene en la sesion, puede heredar del padre
 * (misma regla que RolService::MODULO_PADRE en la API).
 */
export const MODULO_PADRE: Record<string, string> = {
  macrofamilias: 'articulos',
  familias: 'articulos',
  subfamilias: 'articulos',
  agrupaciones: 'articulos',
  secciones: 'articulos',
  subsecciones: 'articulos',
  actividades: 'clientes',
  'intereses-comerciales': 'clientes',
  'oferta-clientes': 'clientes',
  campanas: 'clientes',
  'oferta-proveedores': 'proveedores',
  'puestos-parametros': 'puestos',
  'puestos-trabajo': 'puestos',
  'ventas-arqueo': 'ventas',
  'ventas-arqueo-desglose': 'ventas',
  'ventas-anulaciones': 'ventas',
  'ventas-cobros-pagos': 'ventas',
  'ventas-vales': 'ventas',
  'ventas-pedidos': 'ventas',
  'ventas-abc': 'ventas',
  'facturacion-manual': 'facturacion',
  'facturacion-generacion': 'facturacion',
  'facturacion-impresion': 'facturacion',
  'facturacion-diario': 'facturacion',
  'facturacion-albaranes-pendientes': 'facturacion',
  'facturacion-retroceso': 'facturacion',
}
