import type { ToolIconName } from '@/components/common/ToolIcon.vue'

export type MenuPrincipalSeccion = {
  id: string
  titulo: string
  /** Modulo de RolPermisos; si falta, la seccion se muestra a cualquier usuario autenticado. */
  modulo?: string
  /** Ruta placeholder de la seccion (sin submenus aun). */
  ruta: string
  /** Icono del menu lateral compacto (TPV 1024x768: sin texto). */
  icono: ToolIconName
}

/**
 * Secciones del menu principal de Gestion.
 * Mantenimiento, Compras, Ventas y Facturacion tienen submenus; TPV abre venta tactil en /tpv.
 */
export const menuPrincipalSecciones: MenuPrincipalSeccion[] = [
  {
    id: 'mantenimiento',
    titulo: 'Mantenimiento',
    modulo: 'mantenimiento',
    ruta: '/',
    icono: 'mantenimiento',
  },
  { id: 'compras', titulo: 'Compras', modulo: 'compras', ruta: '/compras', icono: 'compras' },
  {
    id: 'etiquetas',
    titulo: 'Etiquetas',
    modulo: 'etiquetas',
    ruta: '/etiquetas',
    icono: 'etiquetas',
  },
  { id: 'ventas', titulo: 'Ventas', modulo: 'ventas', ruta: '/ventas', icono: 'ventas' },
  {
    id: 'facturacion',
    titulo: 'Facturacion',
    modulo: 'facturacion',
    ruta: '/facturacion',
    icono: 'facturacion',
  },
  {
    id: 'inventario',
    titulo: 'Inventario',
    modulo: 'inventario',
    ruta: '/inventario',
    icono: 'inventario',
  },
  { id: 'listados', titulo: 'Listados', ruta: '/listados', icono: 'listado' },
  { id: 'tpv', titulo: 'TPV', modulo: 'tpv', ruta: '/tpv', icono: 'tpv' },
]
