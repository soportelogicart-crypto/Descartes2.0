import { api } from '@/api/client'
import { getDescartesBridge, type DescartesPrinter } from '@/bridge/electron'

export type ImpresoraSistema = DescartesPrinter

export type ListarImpresorasResult = {
  ok: boolean
  agenteOnline: boolean
  printers: ImpresoraSistema[]
  message: string
  source: 'electron' | 'agente' | 'api' | 'none'
}

const AGENTE_URL = 'http://127.0.0.1:17321'

function normalizePrinters(raw: unknown[]): ImpresoraSistema[] {
  return raw
    .map((item, i) => {
      const p = (item ?? {}) as Record<string, unknown>
      const name = String(p.name ?? p.displayName ?? '').trim()
      return {
        id: Number(p.id) > 0 ? Number(p.id) : i + 1,
        name,
        displayName: String(p.displayName ?? p.name ?? name).trim(),
        description: String(p.description ?? '').trim(),
        isDefault: Boolean(p.isDefault),
        status: p.status == null ? null : Number(p.status),
      }
    })
    .filter((p) => p.name !== '')
}

/** Preferencia: puente Electron → agente local → API PHP. */
export async function listarImpresorasSistema(): Promise<ListarImpresorasResult> {
  const bridge = getDescartesBridge()
  if (bridge?.listPrinters) {
    try {
      const res = await bridge.listPrinters()
      const printers = normalizePrinters(res.printers ?? [])
      // Si el bridge falla o no lista, seguir con agente/API (no quedarse a ciegas).
      if (res.ok && printers.length > 0) {
        return {
          ok: true,
          agenteOnline: true,
          printers,
          message: res.message || '',
          source: 'electron',
        }
      }
      console.warn('[impresoras] bridge sin lista útil:', res.message || res.ok)
    } catch (e: unknown) {
      console.warn('[impresoras] bridge falló', e)
    }
  }

  try {
    const ctrl = new AbortController()
    const timer = window.setTimeout(() => ctrl.abort(), 2500)
    const res = await fetch(`${AGENTE_URL}/impresoras`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      signal: ctrl.signal,
    })
    window.clearTimeout(timer)
    if (res.ok) {
      const data = (await res.json()) as {
        ok?: boolean
        printers?: unknown[]
        message?: string
      }
      const printers = normalizePrinters(data.printers ?? [])
      if (printers.length > 0 || data.ok) {
        return {
          ok: !!data.ok || printers.length > 0,
          agenteOnline: true,
          printers,
          message: data.message || '',
          source: 'agente',
        }
      }
    }
  } catch {
    // agente no disponible
  }

  try {
    const { data } = await api.get<{
      ok?: boolean
      agenteOnline?: boolean
      printers?: unknown[]
      message?: string
    }>('/api/ventas/dispositivo/impresoras')
    return {
      ok: !!data.ok,
      agenteOnline: !!data.agenteOnline,
      printers: normalizePrinters(data.printers ?? []),
      message: data.message || '',
      source: 'api',
    }
  } catch (e: unknown) {
    return {
      ok: false,
      agenteOnline: false,
      printers: [],
      message:
        e instanceof Error
          ? e.message
          : 'No se pudieron detectar impresoras. Ejecute Descartes Electron en este equipo.',
      source: 'none',
    }
  }
}
