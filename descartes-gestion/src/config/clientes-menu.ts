export type ClientesMenuItem = {
  slug: string
  titulo: string
  ruta: string
}

export const clientesMenuItems: ClientesMenuItem[] = [
  { slug: 'actividades', titulo: 'Actividades', ruta: '/mantenimiento/actividades' },
  {
    slug: 'intereses-comerciales',
    titulo: 'Intereses comerciales',
    ruta: '/mantenimiento/intereses-comerciales',
  },
  { slug: 'clientes', titulo: 'Clientes', ruta: '/mantenimiento/clientes' },
  { slug: 'oferta-clientes', titulo: 'Oferta de clientes', ruta: '/mantenimiento/oferta-clientes' },
  { slug: 'campanas', titulo: 'Campañas', ruta: '/mantenimiento/campanas' },
]

export const clientesMenuRutas = clientesMenuItems.map((item) => item.ruta)

export function esRutaClientes(ruta: string): boolean {
  return clientesMenuRutas.some((path) => ruta === path || ruta.startsWith(`${path}/`))
}
