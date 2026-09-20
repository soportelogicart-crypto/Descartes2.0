import { ABC_COMPRAS_DIMENSIONES } from '@/config/abc-compras-dimensiones'
import { ABC_VENTAS_DIMENSIONES } from '@/config/abc-ventas-dimensiones'
import { STOCK_LISTADOS } from '@/config/stock-listado-config'

export function stockListadoModuloPermiso(agruparPor: string): string {
  const key = agruparPor.trim().toLowerCase() || 'articulo'
  const ok = STOCK_LISTADOS.some((s) => s.agruparPor === key)
  return `listados-stock-${ok ? key : 'articulo'}`
}

export function abcVentasModuloPermiso(dimension: string): string {
  const key = dimension.trim().toLowerCase()
  const ok = ABC_VENTAS_DIMENSIONES.some((d) => d.value === key)
  return `ventas-abc-${ok ? key : 'vendedores'}`
}

export function abcComprasModuloPermiso(dimension: string): string {
  const key = dimension.trim().toLowerCase()
  const ok = ABC_COMPRAS_DIMENSIONES.some((d) => d.value === key)
  return `compras-abc-${ok ? key : 'macrofamilias'}`
}

export const STOCK_LISTADO_MODULOS_PERMISO = STOCK_LISTADOS.map((s) => ({
  id: s.catalogId,
  titulo: s.titulo.replace(/^Listado de stock\s*/i, ''),
  modulo: stockListadoModuloPermiso(s.agruparPor),
  agruparPor: s.agruparPor,
}))

export const ABC_VENTAS_MODULOS_PERMISO = ABC_VENTAS_DIMENSIONES.map((d) => ({
  id: `abc-${d.value}`,
  titulo: d.label,
  modulo: abcVentasModuloPermiso(d.value),
  dimension: d.value,
}))

export const ABC_COMPRAS_MODULOS_PERMISO = ABC_COMPRAS_DIMENSIONES.map((d) => ({
  id: `abc-compras-${d.value}`,
  titulo: d.label,
  modulo: abcComprasModuloPermiso(d.value),
  dimension: d.value,
}))

type PuedeFn = (modulo: string, accion: 'ver' | 'crear' | 'editar' | 'eliminar') => boolean

export function puedeVerSubmenuStock(puede: PuedeFn, agruparPor: string): boolean {
  return puede(stockListadoModuloPermiso(agruparPor), 'ver')
}

export function puedeAccederHubStock(puede: PuedeFn): boolean {
  return (
    puede('listados-stock', 'ver') ||
    STOCK_LISTADOS.some((s) => puede(stockListadoModuloPermiso(s.agruparPor), 'ver'))
  )
}

export function puedeVerSubmenuAbc(puede: PuedeFn, dimension: string): boolean {
  return puede(abcVentasModuloPermiso(dimension), 'ver')
}

export function puedeAccederHubAbc(puede: PuedeFn): boolean {
  return (
    puede('ventas-abc', 'ver') ||
    ABC_VENTAS_DIMENSIONES.some((d) => puede(abcVentasModuloPermiso(d.value), 'ver'))
  )
}

export function puedeVerSubmenuAbcCompras(puede: PuedeFn, dimension: string): boolean {
  return puede(abcComprasModuloPermiso(dimension), 'ver')
}

export function puedeAccederHubAbcCompras(puede: PuedeFn): boolean {
  return (
    puede('compras-abc', 'ver') ||
    ABC_COMPRAS_DIMENSIONES.some((d) => puede(abcComprasModuloPermiso(d.value), 'ver'))
  )
}
