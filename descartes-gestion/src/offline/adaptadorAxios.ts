import axios, {
  AxiosError,
  type AxiosAdapter,
  type AxiosResponse,
  type InternalAxiosRequestConfig,
} from 'axios'
import { aplicarEstado, modoLocal, puenteOffline, useEstadoConexion } from './estadoConexion'

/** Ticket local (tipo L): siempre lo atiende el servidor local, aunque vuelva la conexión. */
const RE_TICKET_LOCAL = /\/api\/(?:ventas\/albaranes|tpv\/ventas)\/[^/]+\/L\/\d+/

/** Lecturas que, si falla la red antes de que salte el aviso, se contestan en local. */
const LECTURAS_LOCALES = [
  '/api/tpv/',
  '/api/ventas/descuento-oferta',
  '/api/auth/me',
  '/api/instalacion/estado',
  '/api/mantenimiento/puestos-trabajo/',
  '/api/mantenimiento/tiendas/',
  '/api/mantenimiento/trabajadores/',
  '/api/mantenimiento/documento-plantillas',
]

function rutaDe(config: InternalAxiosRequestConfig): string {
  const url = String(config.url ?? '')
  try {
    const u = new URL(url, window.location.href)
    return /^https?:|^descartes:/.test(url) ? u.pathname + u.search : url
  } catch {
    return url
  }
}

/** Un 500 con JSON lo ha escrito el PHP. Sin JSON es el proxy: Apache no está. */
function cuerpoDeApi(data: unknown): boolean {
  return !!data && typeof data === 'object'
}

function esErrorDeRed(e: unknown): boolean {
  if (!axios.isAxiosError(e)) return false
  if (!e.response) return true
  const s = e.response.status
  if (s === 502 || s === 503 || s === 504) return true
  return s === 500 && !cuerpoDeApi(e.response.data)
}

/** Ventas y login se reintentan en local en cuanto el corte queda confirmado. */
function reintentoLocal(metodo: string, ruta: string): boolean {
  if (metodo === 'get' && LECTURAS_LOCALES.some((pre) => ruta.startsWith(pre))) return true
  if (metodo !== 'post' && metodo !== 'put' && metodo !== 'delete') return false
  return (
    ruta.startsWith('/api/tpv/') ||
    ruta.startsWith('/api/ventas/albaranes') ||
    ruta.startsWith('/api/auth/')
  )
}

function debeIrLocal(ruta: string): boolean {
  if (RE_TICKET_LOCAL.test(ruta)) return true
  if (!modoLocal()) return false
  // Mientras se envían las ventas, el login va al servidor para recuperar la sesión.
  const { estado } = useEstadoConexion()
  if (estado.estado === 'sincronizando' && ruta.startsWith('/api/auth/')) return false
  return true
}

async function respuestaLocal(config: InternalAxiosRequestConfig, ruta: string): Promise<AxiosResponse> {
  const p = puenteOffline()!
  const r = await p.peticion({
    method: String(config.method ?? 'get'),
    url: ruta,
    params: (config.params ?? undefined) as Record<string, unknown> | undefined,
    data: config.data,
  })
  const response: AxiosResponse = {
    data: r.data,
    status: r.status,
    statusText: '',
    headers: {},
    config,
    request: { local: true },
  }
  const valida = config.validateStatus ?? ((s: number) => s >= 200 && s < 300)
  if (valida(r.status)) return response
  const mensaje =
    (r.data && typeof r.data === 'object' && 'error' in r.data && String((r.data as { error: unknown }).error)) ||
    `HTTP ${r.status}`
  throw new AxiosError(
    mensaje,
    r.status >= 500 ? AxiosError.ERR_BAD_RESPONSE : AxiosError.ERR_BAD_REQUEST,
    config,
    response.request,
    response
  )
}

/**
 * Adaptador de axios con modo sin conexión. Sin Electron (navegador) es el
 * adaptador normal.
 */
export function crearAdaptadorOffline(): AxiosAdapter {
  const red = axios.getAdapter(axios.defaults.adapter)
  return async (config) => {
    const p = puenteOffline()
    if (!p) return red(config)
    const ruta = rutaDe(config)
    if (!ruta.includes('/api/')) return red(config)
    if (debeIrLocal(ruta)) return respuestaLocal(config, ruta)

    const metodo = String(config.method ?? 'get').toLowerCase()
    try {
      const res = await red(config)
      if (metodo === 'get' && res.status >= 200 && res.status < 300) {
        void p
          .guardarCache({
            method: 'get',
            url: ruta,
            params: (config.params ?? undefined) as Record<string, unknown> | undefined,
            data: res.data,
          })
          .catch(() => {})
      }
      return res
    } catch (e) {
      if (esErrorDeRed(e)) {
        try {
          aplicarEstado(await p.falloRed())
        } catch {
          // Sin respuesta del proceso local: se mantiene el error de red.
        }
        const lectura = metodo === 'get' && LECTURAS_LOCALES.some((pre) => ruta.startsWith(pre))
        if (lectura || (modoLocal() && reintentoLocal(metodo, ruta))) {
          try {
            return await respuestaLocal(config, ruta)
          } catch (local) {
            if (axios.isAxiosError(local) && local.response) throw local
          }
        }
      }
      throw e
    }
  }
}
