/**
 * Cliente HTTP para ABC Ventas en XAMPP (cuando no hay Electron).
 * Expone la misma forma que window.abcVentas.
 */
const API_BASE = (import.meta.env.VITE_API_BASE_URL || './api').replace(/\/$/, '')

async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const res = await fetch(`${API_BASE}${path}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(init?.headers || {}),
    },
    ...init,
  })

  if (res.status === 204) {
    return undefined as T
  }

  const text = await res.text()
  let data: unknown = null
  if (text) {
    try {
      data = JSON.parse(text)
    } catch {
      data = { error: text }
    }
  }

  if (!res.ok) {
    const msg =
      data && typeof data === 'object' && data !== null && 'error' in data
        ? String((data as { error: unknown }).error)
        : `HTTP ${res.status}`
    const err = new Error(msg) as Error & { code?: string; status?: number }
    err.status = res.status
    if (data && typeof data === 'object' && data !== null && 'code' in data) {
      err.code = String((data as { code: unknown }).code)
    }
    throw err
  }

  return data as T
}

export function createHttpBridge() {
  return {
    isElectron: false,
    platform: 'web',

    login: (usuario: string, password: string) =>
      request('/auth/login', {
        method: 'POST',
        body: JSON.stringify({ usuario, password }),
      }),

    logout: () =>
      request<{ ok: boolean }>('/auth/logout', { method: 'POST' }).then(() => ({ ok: true })),

    me: () => request('/auth/me'),

    getConfig: () => request('/config'),

    saveConfig: (payload: unknown) =>
      request('/config', {
        method: 'PUT',
        body: JSON.stringify(payload),
      }),

    obtenerAbc: (filtros: unknown) =>
      request('/abc', {
        method: 'POST',
        body: JSON.stringify(filtros || {}),
      }),

    testDb: () => request('/db/test'),

    buscarLookup: (payload: { entidad: string; q?: string; limit?: number }) => {
      const q = new URLSearchParams()
      q.set('entidad', payload.entidad)
      if (payload.q) q.set('q', payload.q)
      if (payload.limit) q.set('limit', String(payload.limit))
      return request(`/lookup?${q.toString()}`)
    },

    saveExport: async () => {
      // En web el export lo hace el navegador (Blob/download).
      return { ok: false as const }
    },

    onMenuConfiguracion: (_handler: () => void) => () => {},
  }
}

export function getAbcBridge() {
  if (typeof window !== 'undefined' && window.abcVentas?.isElectron) {
    return window.abcVentas
  }
  return createHttpBridge()
}
