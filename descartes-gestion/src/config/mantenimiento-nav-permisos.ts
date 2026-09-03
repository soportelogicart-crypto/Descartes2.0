import { articulosMenuItems } from '@/config/articulos-menu'
import { clientesMenuItems } from '@/config/clientes-menu'
import { puestosMenuItems } from '@/config/puestos-menu'
import { proveedoresMenuItems } from '@/config/proveedores-menu'

export type NavPermisoItem = {
  id: string
  titulo: string
  modulo: string
}

export type NavPermisoGrupo = {
  id: string
  titulo: string
  children: NavPermisoItem[]
}

export type NavPermisoNodo =
  | ({ tipo: 'item' } & NavPermisoItem)
  | ({ tipo: 'grupo' } & NavPermisoGrupo)

const moduloPorSlugArticulos: Record<string, string> = {
  macrofamilias: 'macrofamilias',
  familias: 'familias',
  subfamilias: 'subfamilias',
  agrupaciones: 'agrupaciones',
  secciones: 'secciones',
  subsecciones: 'subsecciones',
  articulos: 'articulos',
}

const moduloPorSlugClientes: Record<string, string> = {
  actividades: 'actividades',
  'intereses-comerciales': 'intereses-comerciales',
  'tipos-calculo-fidelizacion': 'clientes',
  clientes: 'clientes',
  'oferta-clientes': 'oferta-clientes',
  campanas: 'campanas',
  'albaranes-periodicos': 'albaranes-periodicos',
}

const moduloPorSlugProveedores: Record<string, string> = {
  proveedores: 'proveedores',
  'oferta-proveedores': 'oferta-proveedores',
}

const moduloPorSlugPuestos: Record<string, string> = {
  parametros: 'puestos-parametros',
  'puestos-trabajo': 'puestos-trabajo',
}

/** Resuelve el modulo de permiso de una entrada del menu (slug de ruta). */
export function moduloDeEntradaMenu(slug: string): string {
  return (
    moduloPorSlugArticulos[slug] ??
    moduloPorSlugClientes[slug] ??
    moduloPorSlugProveedores[slug] ??
    moduloPorSlugPuestos[slug] ??
    slug
  )
}

/** Misma estructura visual que el sidebar de Mantenimiento. */
export const mantenimientoNavPermisos: NavPermisoNodo[] = [
  { tipo: 'item', id: 'empresas', titulo: 'Empresa', modulo: 'empresas' },
  { tipo: 'item', id: 'tiendas', titulo: 'Tiendas', modulo: 'tiendas' },
  { tipo: 'item', id: 'almacenes', titulo: 'Almacenes', modulo: 'almacenes' },
  { tipo: 'item', id: 'trabajadores', titulo: 'Trabajadores', modulo: 'trabajadores' },
  { tipo: 'item', id: 'impuestos', titulo: 'Impuestos', modulo: 'impuestos' },
  { tipo: 'item', id: 'formas-pago', titulo: 'Formas de pago', modulo: 'formas-pago' },
  { tipo: 'item', id: 'roles', titulo: 'Roles', modulo: 'roles' },
  { tipo: 'item', id: 'usuarios', titulo: 'Usuarios', modulo: 'usuarios' },
  {
    tipo: 'grupo',
    id: 'articulos',
    titulo: 'Articulos',
    children: articulosMenuItems.map((i) => ({
      id: i.slug,
      titulo: i.titulo,
      modulo: moduloPorSlugArticulos[i.slug] ?? i.slug,
    })),
  },
  {
    tipo: 'grupo',
    id: 'clientes',
    titulo: 'Clientes',
    children: clientesMenuItems.map((i) => ({
      id: i.slug,
      titulo: i.titulo,
      modulo: moduloPorSlugClientes[i.slug] ?? i.slug,
    })),
  },
  {
    tipo: 'grupo',
    id: 'proveedores',
    titulo: 'Proveedores',
    children: proveedoresMenuItems.map((i) => ({
      id: i.slug,
      titulo: i.titulo,
      modulo: moduloPorSlugProveedores[i.slug] ?? i.slug,
    })),
  },
  {
    tipo: 'grupo',
    id: 'puestos',
    titulo: 'Puestos de trabajo',
    children: puestosMenuItems.map((i) => ({
      id: i.slug,
      titulo: i.titulo,
      modulo: moduloPorSlugPuestos[i.slug] ?? i.slug,
    })),
  },
]

/** Modulos de permiso que no aparecen como entrada del menu de Mantenimiento ni de Ventas. */
export const otrosModulosPermisos: NavPermisoItem[] = [
  { id: 'mantenimiento', titulo: 'Mantenimiento (general)', modulo: 'mantenimiento' },
  { id: 'puestos', titulo: 'Puestos (legado)', modulo: 'puestos' },
  { id: 'albaranes-periodicos', titulo: 'Albaranes periódicos', modulo: 'albaranes-periodicos' },
  { id: 'inventario', titulo: 'Inventario', modulo: 'inventario' },
  { id: 'listados', titulo: 'Listados', modulo: 'listados' },
]
