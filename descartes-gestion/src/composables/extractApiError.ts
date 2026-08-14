export type ApiErrorBody = {
  error?: string
  codigo?: string
  dependencias?: string[]
}

/**
 * Mensaje de usuario a partir de errores Axios / API (`{ error, codigo, dependencias? }`).
 * Usado en mantenimiento, ventas, facturación, compras y etiquetas (005).
 */
export function extractApiError(e: unknown, fallback: string): string {
  const err = e as {
    response?: { data?: ApiErrorBody | string }
    message?: string
    code?: string
  }
  const data = err.response?.data

  if (data && typeof data === 'object' && typeof data.error === 'string' && data.error.trim()) {
    if (data.dependencias && data.dependencias.length > 0) {
      return `${data.error} (${data.dependencias.join(', ')})`
    }
    return data.error
  }

  if (!err.response) {
    const msg = String(err.message ?? '')
    if (err.code === 'ERR_NETWORK' || msg === 'Network Error') {
      return 'No hay conexión con el servidor'
    }
  }

  if (e instanceof Error && e.message.trim()) {
    return e.message
  }

  return fallback
}

/** true si la API respondió 404 / NO_ENCONTRADO (p. ej. artículo inexistente). */
export function isApiNotFound(e: unknown): boolean {
  const err = e as { response?: { status?: number; data?: ApiErrorBody } }
  if (err.response?.status === 404) return true
  const codigo = err.response?.data?.codigo
  return codigo === 'NO_ENCONTRADO'
}
