export type ApiErrorBody = {
  error?: string
  codigo?: string
  dependencias?: string[]
}

export function extractApiError(e: unknown, fallback: string): string {
  const err = e as { response?: { data?: ApiErrorBody }; message?: string }
  const data = err.response?.data
  if (!data?.error) {
    if (e instanceof Error && e.message) return e.message
    if (typeof err.message === 'string' && err.message) return err.message
    return fallback
  }
  if (data.dependencias && data.dependencias.length > 0) {
    return `${data.error} (${data.dependencias.join(', ')})`
  }
  return data.error
}
