import { getAbcBridge } from '@/api/httpBridge'

export type LookupEntidad =
  | 'empresas'
  | 'macroFamilias'
  | 'familias'
  | 'subfamilias'
  | 'agrupaciones'
  | 'articulos'
  | 'clientes'
  | 'proveedores'
  | 'secciones'
  | 'subSecciones'
  | 'actividades'

export type LookupItem = {
  codigo: string
  etiqueta: string
}

export type LookupResultado = {
  entidad: string
  labelHeader: string
  items: LookupItem[]
}

export async function buscarLookup(
  entidad: LookupEntidad,
  q = '',
  limit = 200,
): Promise<LookupResultado> {
  return getAbcBridge().buscarLookup({ entidad, q, limit }) as Promise<LookupResultado>
}
