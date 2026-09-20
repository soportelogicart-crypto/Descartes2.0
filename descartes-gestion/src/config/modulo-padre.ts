import {
  ABC_COMPRAS_MODULOS_PERMISO,
  ABC_VENTAS_MODULOS_PERMISO,
  STOCK_LISTADO_MODULOS_PERMISO,
} from '@/config/listados-permisos'

const padreStockSubmodulos = Object.fromEntries(
  STOCK_LISTADO_MODULOS_PERMISO.map((s) => [s.modulo, 'listados-stock']),
) as Record<string, string>

const padreAbcSubmodulos = Object.fromEntries(
  ABC_VENTAS_MODULOS_PERMISO.map((s) => [s.modulo, 'ventas-abc']),
) as Record<string, string>

const padreAbcComprasSubmodulos = Object.fromEntries(
  ABC_COMPRAS_MODULOS_PERMISO.map((s) => [s.modulo, 'compras-abc']),
) as Record<string, string>

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
  'albaranes-periodicos': 'facturacion-manual',
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
  'compras-abc': 'compras',
  'listados-informe-tickets': 'listados',
  'listados-extracto-clientes': 'listados',
  'listados-stock': 'listados',
  'listados-stock-minimos': 'listados',
  'listados-informe-iva': 'listados',
  'facturacion-manual': 'facturacion',
  'facturacion-generacion': 'facturacion',
  'facturacion-impresion': 'facturacion',
  'facturacion-diario': 'facturacion',
  'facturacion-albaranes-pendientes': 'facturacion',
  'facturacion-retroceso': 'facturacion',
  ...padreStockSubmodulos,
  ...padreAbcSubmodulos,
  ...padreAbcComprasSubmodulos,
}
