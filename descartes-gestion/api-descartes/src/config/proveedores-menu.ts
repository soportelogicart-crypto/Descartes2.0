export type ProveedoresMenuItem = {
  slug: string
  titulo: string
  ruta: string
}

export const proveedoresMenuItems: ProveedoresMenuItem[] = [
  { slug: 'proveedores', titulo: 'Proveedores', ruta: '/mantenimiento/proveedores' },
  {
    slug: 'oferta-proveedores',
    titulo: 'Ofertas proveedores',
    ruta: '/mantenimiento/oferta-proveedores',
  },
]

export const proveedoresMenuRutas = proveedoresMenuItems.map((item) => item.ruta)

export function esRutaProveedores(ruta: string): boolean {
  return proveedoresMenuRutas.some((path) => ruta === path || ruta.startsWith(`${path}/`))
}
