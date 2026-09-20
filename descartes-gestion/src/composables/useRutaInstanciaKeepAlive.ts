import { useRoute } from 'vue-router'

/** Segmento `agruparPor` en rutas `/listados/stock/:agruparPor`. */
export function agruparPorDesdePathStock(fullPath: string): string {
  const path = (fullPath.split('?')[0] || '/').replace(/\/+$/, '') || '/'
  const m = path.match(/^\/listados\/stock\/([^/]+)$/)
  return m?.[1]?.trim() ?? ''
}

/** Segmento `dimension` en rutas `/listados/abc-ventas/:dimension`. */
export function dimensionDesdePathAbcVentas(fullPath: string): string {
  const path = (fullPath.split('?')[0] || '/').replace(/\/+$/, '') || '/'
  const m = path.match(/^\/listados\/abc-ventas\/([^/]+)$/)
  return m?.[1]?.trim() ?? ''
}

/**
 * KeepAlive cachea por fullPath; useRoute() sigue cambiando en instancias inactivas.
 * Usar pathInstancia + esEstaInstanciaActiva() para no reaccionar a otras pestañas.
 */
export function useRutaInstanciaKeepAlive() {
  const route = useRoute()
  const pathInstancia = route.fullPath

  function esEstaInstanciaActiva(): boolean {
    return route.fullPath === pathInstancia
  }

  return { pathInstancia, esEstaInstanciaActiva }
}
