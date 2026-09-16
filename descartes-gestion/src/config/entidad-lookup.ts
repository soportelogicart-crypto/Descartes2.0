/** Entidades del buscador modal usables como lookup (codigo + descripcion). */
export type EntidadLookupId =
  | 'articulos'
  | 'proveedores'
  | 'clientes'
  | 'trabajadores'
  | 'puestos-trabajo'
  | 'tiendas'
  | 'familias'
  | 'macrofamilias'
  | 'subfamilias'
  | 'secciones'
  | 'subsecciones'
  | 'agrupaciones'
  | 'impuestos'
  | 'formas-pago'
  | 'bancos'
  | 'cuentas'
  | 'cuentas-ultimo-nivel'
  | 'cuentas-banco'
  | 'actividades'
  | 'almacenes'
  | 'roles'
  | 'usuarios'
  | 'tipos-calculo-fidelizacion'

export type OptionsSourceLookup =
  | 'macrofamilias'
  | 'familias'
  | 'subfamilias'
  | 'secciones'
  | 'subsecciones'
  | 'agrupaciones'
  | 'impuestos'
  | 'proveedores'
  | 'tiendas'
  | 'trabajadores'
  | 'usuarios'
  | 'roles'
  | 'almacenes'
  | 'actividades'
  | 'formas-pago'
  | 'clientes'
  | 'cuentas'
  | 'cuentas-ultimo-nivel'
  | 'cuentas-banco'
  | 'tipos-calculo-fidelizacion'

const SOURCE_TO_ENTIDAD: Record<OptionsSourceLookup, EntidadLookupId> = {
  macrofamilias: 'macrofamilias',
  familias: 'familias',
  subfamilias: 'subfamilias',
  secciones: 'secciones',
  subsecciones: 'subsecciones',
  agrupaciones: 'agrupaciones',
  impuestos: 'impuestos',
  proveedores: 'proveedores',
  tiendas: 'tiendas',
  trabajadores: 'trabajadores',
  usuarios: 'usuarios',
  roles: 'roles',
  almacenes: 'almacenes',
  actividades: 'actividades',
  'formas-pago': 'formas-pago',
  clientes: 'clientes',
  cuentas: 'cuentas',
  'cuentas-ultimo-nivel': 'cuentas-ultimo-nivel',
  'cuentas-banco': 'cuentas-banco',
  'tipos-calculo-fidelizacion': 'tipos-calculo-fidelizacion',
}

const DEFAULT_MAX_LENGTH: Partial<Record<EntidadLookupId, number>> = {
  impuestos: 2,
  'formas-pago': 2,
  proveedores: 9,
  clientes: 9,
  trabajadores: 4,
  usuarios: 6,
  tiendas: 2,
  roles: 10,
  almacenes: 3,
  familias: 6,
  macrofamilias: 6,
  subfamilias: 6,
  secciones: 6,
  subsecciones: 6,
  agrupaciones: 6,
  actividades: 6,
  cuentas: 10,
  'cuentas-ultimo-nivel': 10,
  'cuentas-banco': 10,
  'tipos-calculo-fidelizacion': 10,
}

export function entidadDesdeOptionsSource(source: string | undefined): EntidadLookupId | null {
  if (!source) return null
  return (SOURCE_TO_ENTIDAD as Record<string, EntidadLookupId>)[source] ?? null
}

export function maxLengthLookup(entidad: EntidadLookupId, override?: number): number | undefined {
  if (override != null) return override
  return DEFAULT_MAX_LENGTH[entidad]
}

/** Etiqueta mostrada en el API GET detalle (campo descripcion/nombre). */
export function etiquetaDesdeDetalle(entidad: EntidadLookupId, data: Record<string, unknown>): string {
  if (entidad === 'proveedores' || entidad === 'clientes') {
    return String(data.nombre ?? data.razonSocial ?? '').trim()
  }
  if (entidad === 'trabajadores' || entidad === 'usuarios' || entidad === 'tiendas') {
    return String(data.nombre ?? data.descripcion ?? '').trim()
  }
  if (entidad === 'roles') {
    return String(data.nombre ?? '').trim()
  }
  if (entidad === 'tipos-calculo-fidelizacion') {
    return String(data.nombre ?? data.descripcion ?? '').trim()
  }
  if (entidad === 'almacenes') {
    return String(data.descripcion ?? '').trim()
  }
  return String(data.descripcion ?? data.nombre ?? '').trim()
}
