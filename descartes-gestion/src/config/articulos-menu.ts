export type ArticulosMenuItem = {
  slug: string
  titulo: string
  ruta: string
}

export const articulosMenuItems: ArticulosMenuItem[] = [
  { slug: 'macrofamilias', titulo: 'Macrofamilias', ruta: '/mantenimiento/macrofamilias' },
  { slug: 'familias', titulo: 'Familias', ruta: '/mantenimiento/familias' },
  { slug: 'subfamilias', titulo: 'Subfamilias', ruta: '/mantenimiento/subfamilias' },
  { slug: 'agrupaciones', titulo: 'Agrupaciones', ruta: '/mantenimiento/agrupaciones' },
  { slug: 'secciones', titulo: 'Secciones', ruta: '/mantenimiento/secciones' },
  { slug: 'subsecciones', titulo: 'Subsecciones', ruta: '/mantenimiento/subsecciones' },
  { slug: 'articulos', titulo: 'Articulos', ruta: '/mantenimiento/articulos' },
]

export const articulosMenuRutas = articulosMenuItems.map((item) => item.ruta)

export function esRutaArticulos(ruta: string): boolean {
  return articulosMenuRutas.some((path) => ruta === path || ruta.startsWith(`${path}/`))
}
