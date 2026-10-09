import { api } from '@/api/client'
import type {
  TpvArticuloPrecio,
  TpvArticuloResuelto,
  TpvBotonAsignacion,
  TpvCliente,
  TpvContexto,
  TpvNivel,
  TpvTicketEspera,
} from '@/types/tpv'
import type { VentaDetalle, VentaResumen } from '@/types/ventas'

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

function rutaNivel(general: string, nivel: string): string {
  return `/api/tpv/teclados/${encodeURIComponent(general)}/niveles/${encodeURIComponent(nivel)}`
}

/** Guarda el botón de una posición fija (0..7 grupos en nivel 000, 0..13 resto). */
export async function guardarBotonTeclado(
  general: string,
  nivel: string,
  posicion: number,
  asignacion: TpvBotonAsignacion
): Promise<string | null> {
  const { data } = await api.put<{ nivelDestino: string | null }>(
    `${rutaNivel(general, nivel)}/posiciones/${posicion}`,
    asignacion
  )
  return data?.nivelDestino ?? null
}

/** Botones que se perderían al borrar un grupo. */
export async function contarSubnivelesTeclado(
  general: string,
  nivel: string,
  posicion: number
): Promise<number> {
  const { data } = await api.get<{ botones: number }>(
    `${rutaNivel(general, nivel)}/posiciones/${posicion}/subniveles`
  )
  return Number(data?.botones) || 0
}

/** Borra el botón; si es un grupo, también los botones de dentro. */
export async function borrarBotonTeclado(
  general: string,
  nivel: string,
  posicion: number
): Promise<void> {
  await api.delete(`${rutaNivel(general, nivel)}/posiciones/${posicion}`)
}

/** Intercambia dos posiciones del nivel (los grupos se llevan su contenido). */
export async function intercambiarBotonesTeclado(
  general: string,
  nivel: string,
  origen: number,
  destino: number
): Promise<void> {
  await api.post(`${rutaNivel(general, nivel)}/intercambio`, { origen, destino })
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

export async function listarVentasTpv(params: Record<string, string | number | undefined>) {
  const { data } = await api.get<{ items: VentaResumen[]; total: number }>('/api/tpv/ventas', {
    params,
  })
  return data
}

export async function obtenerVentaTpv(empresa: string, tipo: string, albaran: number) {
  const { data } = await api.get<VentaDetalle>(
    `/api/tpv/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
  )
  return data
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
