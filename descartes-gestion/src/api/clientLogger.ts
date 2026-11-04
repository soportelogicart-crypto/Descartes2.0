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

// #region agent log
function logDebug(hypothesisId: string, location: string, message: string, data: Record<string, unknown>): void {
  fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Debug-Session-Id': '0d3608' },
    body: JSON.stringify({ sessionId: '0d3608', location, message, data, timestamp: Date.now(), hypothesisId }),
  }).catch(() => {})
}
// #endregion

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
    // #region agent log
    logDebug('F', 'clientLogger.ts:onerror', 'error js global', {
      msg: String(msg).slice(0, 300),
      filename: event.filename,
      lineno: event.lineno,
      url: window.location.href,
    })
    // #endregion
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
    // #region agent log
    logDebug('F', 'clientLogger.ts:unhandledrejection', 'promesa sin capturar', {
      msg: String(msg).slice(0, 300),
      stack: reason instanceof Error ? String(reason.stack ?? '').slice(0, 500) : undefined,
      url: window.location.href,
    })
    // #endregion
  })
}
