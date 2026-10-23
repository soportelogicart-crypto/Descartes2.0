import { api } from '@/api/client'

export type CodigoPostalLookup = {
  codigoPostal: string
  poblacion: string | null
  provincia: string | null
  provinciaCodigo: string | null
  poblaciones: string[]
}

const cache = new Map<string, CodigoPostalLookup>()

export function normalizeCodigoPostal(raw: string): string {
  return raw.replace(/\D/g, '')
}

export async function lookupCodigoPostal(codigoPostal: string): Promise<CodigoPostalLookup | null> {
  const digits = normalizeCodigoPostal(codigoPostal)
  const cp = digits || codigoPostal.trim()
  if (cp.length < 4) return null
  const cached = cache.get(cp)
  if (cached) return cached

  let data: CodigoPostalLookup
  try {
    data = (
      await api.get<CodigoPostalLookup>(`/api/ventas/codigos-postales/${encodeURIComponent(cp)}`)
    ).data
  } catch {
    data = (
      await api.get<CodigoPostalLookup>(
        `/api/mantenimiento/codigos-postales/${encodeURIComponent(cp)}`
      )
    ).data
  }
  if (data?.poblacion || data?.provincia) {
    cache.set(cp, data)
  }
  return data
}
