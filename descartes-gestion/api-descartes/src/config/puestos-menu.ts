export type PuestosMenuItem = {
  slug: string
  titulo: string
  ruta: string
}

export const puestosMenuItems: PuestosMenuItem[] = [
  { slug: 'parametros', titulo: 'Parametros', ruta: '/mantenimiento/puestos/parametros' },
  { slug: 'puestos-trabajo', titulo: 'Puestos', ruta: '/mantenimiento/puestos-trabajo' },
]

export const puestosMenuRutas = puestosMenuItems.map((item) => item.ruta)

export function esRutaPuestos(ruta: string): boolean {
  return puestosMenuRutas.some((path) => ruta === path || ruta.startsWith(`${path}/`))
}
