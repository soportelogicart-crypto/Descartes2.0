import { api } from '@/api/client'

export type ArticuloMatchPor = 'codigo' | 'alternativo' | 'ean'

export type ArticuloResuelto = Record<string, unknown> & {
  codigo: string
  descripcion?: string | null
  matchPor: ArticuloMatchPor
  /** Unidades del EAN en ArtBarras (1 si match por código/alternativo). */
  unidadesPaquete: number
  query?: string
}

/**
 * Busca artículo por código, Alternativo o EAN (escáner / Intro).
 * GET /api/mantenimiento/articulos/resolver?q=
 */
export async function resolverArticulo(query: string): Promise<ArticuloResuelto> {
  const q = String(query ?? '').trim()
  const { data } = await api.get<ArticuloResuelto>('/api/mantenimiento/articulos/resolver', {
    params: { q },
  })
  return {
    ...data,
    codigo: String(data.codigo ?? '').trim(),
    matchPor: (data.matchPor as ArticuloMatchPor) || 'codigo',
    unidadesPaquete: Number(data.unidadesPaquete) > 0 ? Number(data.unidadesPaquete) : 1,
  }
}

/** Alta rápida (mismo payload que mantenimiento /api/mantenimiento/articulos). */
export async function crearArticulo(payload: Record<string, unknown>): Promise<Record<string, unknown>> {
  const { data } = await api.post<Record<string, unknown>>('/api/mantenimiento/articulos', payload)
  return data
}
