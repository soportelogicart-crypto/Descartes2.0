import { api } from '@/api/client'

export type CodigoPostalLookup = {
  codigoPostal: string
  poblacion: string | null
  provincia: string | null
  provinciaCodigo: string | null
  poblaciones: string[]
}

const cache = new Map<string, CodigoPostalLookup>()

export async function lookupCodigoPostal(codigoPostal: string): Promise<CodigoPostalLookup | null> {
  const cp = codigoPostal.trim()
  if (cp.length < 4) return null
  const cached = cache.get(cp)
  if (cached) return cached

  const { data } = await api.get<CodigoPostalLookup>(
    `/api/mantenimiento/codigos-postales/${encodeURIComponent(cp)}`
  )
  cache.set(cp, data)
  return data
}
