const baseURL = import.meta.env.VITE_API_BASE_URL || ''

let reporting = false

/** Envia un error/evento al API (fichero diario). No lanza. Usa fetch para no depender de Axios. */
export function reportClientError(
  message: string,
  opts: {
    level?: 'error' | 'warning' | 'info'
    action?: string
    context?: Record<string, unknown>
  } = {}
): void {
  if (reporting) return
  const payload = {
    message: String(message).slice(0, 2000),
    level: opts.level ?? 'error',
    action: opts.action ?? 'client.error',
    url: typeof window !== 'undefined' ? window.location.href : undefined,
    userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : undefined,
    context: opts.context ?? {},
  }

  reporting = true
  const endpoint = `${baseURL}/api/logs`.replace(/([^:]\/)\/+/g, '$1')
  fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  })
    .catch(() => {
      /* no reintentar ni spamear */
    })
    .finally(() => {
      reporting = false
    })
}

export function installGlobalErrorReporting(): void {
  window.addEventListener('error', (event) => {
    const msg = event.message || String(event.error ?? 'Error JS')
    reportClientError(msg, {
      action: 'window.onerror',
      context: {
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
      },
    })
  })

  window.addEventListener('unhandledrejection', (event) => {
    const reason = event.reason
    const msg =
      reason instanceof Error
        ? reason.message
        : typeof reason === 'string'
          ? reason
          : 'Unhandled promise rejection'
    reportClientError(msg, {
      action: 'unhandledrejection',
      context: {
        stack: reason instanceof Error ? reason.stack?.slice(0, 1500) : undefined,
      },
    })
  })
}
