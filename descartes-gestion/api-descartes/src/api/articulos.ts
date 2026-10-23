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
export type ArticuloListadoItem = {
  codigo: string
  descripcion?: string | null
}

/** Búsqueda parcial (listado mantenimiento). */
export async function buscarArticulos(
  query: string,
  pageSize = 50,
): Promise<ArticuloListadoItem[]> {
  const q = String(query ?? '').trim()
  if (!q) return []
  const { data } = await api.get<{ items?: Record<string, unknown>[] }>(
    '/api/mantenimiento/articulos',
    { params: { q, page: 1, pageSize } },
  )
  return (data.items ?? []).map((item) => ({
    codigo: String(item.codigo ?? '').trim(),
    descripcion: item.descripcion != null ? String(item.descripcion) : null,
  }))
}

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

/** Legacy albarán compra: al confirmar, UltProveedor = proveedor del albarán. */
export async function asignarProveedorHabitualArticulo(
  codigo: string,
  proveedorHabitual: string
): Promise<void> {
  const c = String(codigo ?? '').trim()
  const p = String(proveedorHabitual ?? '').trim()
  if (!c || !p) return
  await api.put(`/api/mantenimiento/articulos/${encodeURIComponent(c)}`, {
    proveedorHabitual: p,
  })
}
