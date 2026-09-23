import { api } from '@/api/client'
import type {
  TpvArticuloPrecio,
  TpvArticuloResuelto,
  TpvBotonAsignacion,
  TpvCliente,
  TpvContexto,
  TpvNivel,
  TpvNivelResumen,
  TpvTicketEspera,
} from '@/types/tpv'
import type { VentaDetalle } from '@/types/ventas'

export async function pingTpv(): Promise<{ ok: boolean; modulo: string }> {
  const { data } = await api.get<{ ok: boolean; modulo: string }>('/api/tpv/ping')
  return data
}

export async function obtenerContextoTpv(empresa: string, puesto: string): Promise<TpvContexto> {
  const { data } = await api.get<TpvContexto>('/api/tpv/contexto', {
    params: { empresa, puesto },
  })
  return {
    ...data,
    formasPago: Array.isArray(data.formasPago) ? data.formasPago : [],
  }
}

export async function obtenerNivelTeclado(general: string, nivel: string): Promise<TpvNivel> {
  const { data } = await api.get<TpvNivel>(`/api/tpv/teclados/${encodeURIComponent(general)}/niveles/${encodeURIComponent(nivel)}`)
  return data
}

export async function obtenerNivelesTeclado(general: string): Promise<TpvNivelResumen[]> {
  const { data } = await api.get<{ items: TpvNivelResumen[] }>(
    `/api/tpv/teclados/${encodeURIComponent(general)}/niveles`
  )
  return Array.isArray(data.items) ? data.items : []
}

export async function guardarBotonTeclado(
  general: string,
  nivel: string,
  tecla: number,
  asignacion: TpvBotonAsignacion
): Promise<string | null> {
  const { data } = await api.put<{ nivelDestino: string | null }>(
    `/api/tpv/teclados/${encodeURIComponent(general)}/niveles/${encodeURIComponent(nivel)}/botones/${tecla}`,
    asignacion
  )
  return data?.nivelDestino ?? null
}

export async function borrarBotonTeclado(
  general: string,
  nivel: string,
  tecla: number
): Promise<void> {
  await api.delete(
    `/api/tpv/teclados/${encodeURIComponent(general)}/niveles/${encodeURIComponent(nivel)}/botones/${tecla}`
  )
}

/** Clientes por código, NIF, nombre o teléfono (permiso tpv.ver). */
export async function buscarClientesTpv(query: string, limite = 30): Promise<TpvCliente[]> {
  const { data } = await api.get<{ items: TpvCliente[] }>('/api/tpv/clientes', {
    params: { q: query, limite },
  })
  return Array.isArray(data.items) ? data.items : []
}

/** Código, Alternativo o EAN (tecleado o pistola) → artículo con precio de tarifa. */
export async function resolverArticuloTpv(
  query: string,
  params: { tarifa?: number } = {}
): Promise<TpvArticuloResuelto> {
  const { data } = await api.get<TpvArticuloResuelto>('/api/tpv/articulos/resolver', {
    params: { q: query, ...params },
  })
  return {
    ...data,
    unidadesPaquete: Number(data.unidadesPaquete) > 0 ? Number(data.unidadesPaquete) : 1,
  }
}

/** Búsqueda de artículos y de su clasificación para configurar teclas rápidas. */
export async function buscarArticulosTpv(
  query: string,
  params: {
    tarifa?: number
    limite?: number
    ambito?: 'todos' | 'articulo' | 'macrofamilia' | 'familia' | 'subfamilia' | 'agrupacion'
  } = {}
): Promise<TpvArticuloPrecio[]> {
  const { data } = await api.get<{ items: TpvArticuloPrecio[] }>('/api/tpv/articulos', {
    params: { q: query, ...params },
  })
  return Array.isArray(data.items) ? data.items : []
}

export async function obtenerPrecioArticuloTpv(
  codigo: string,
  params: { tarifa?: number; empresa?: string } = {}
): Promise<TpvArticuloPrecio> {
  const { data } = await api.get<TpvArticuloPrecio>(
    `/api/tpv/articulos/${encodeURIComponent(codigo)}/precio`,
    { params }
  )
  return data
}

/** Anula un ticket abierto con el permiso propio `tpv.eliminar`. */
export async function anularVentaTpv(empresa: string, tipo: string, albaran: number): Promise<void> {
  await api.delete(
    `/api/tpv/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
}

export async function obtenerTicketsEsperaTpv(
  empresa: string,
  puesto: string
): Promise<TpvTicketEspera[]> {
  const { data } = await api.get<{ items: TpvTicketEspera[] }>('/api/tpv/tickets-en-espera', {
    params: { empresa, puesto },
  })
  return Array.isArray(data.items) ? data.items : []
}

export async function ponerTicketEnEsperaTpv(
  empresa: string,
  tipo: string,
  albaran: number,
  puesto: string
): Promise<VentaDetalle> {
  const { data } = await api.post<VentaDetalle>(
    `/api/tpv/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/en-espera`,
    { puesto }
  )
  return data
}

export async function recuperarTicketEsperaTpv(
  empresa: string,
  tipo: string,
  albaran: number,
  puesto: string
): Promise<VentaDetalle> {
  const { data } = await api.post<VentaDetalle>(
    `/api/tpv/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}/recuperar`,
    { puesto }
  )
  return data
}
