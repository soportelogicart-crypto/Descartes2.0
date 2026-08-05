import type { AbcVentasResponse } from '@/types/abc-ventas'

export type AbcPreviewPayload = {
  data: AbcVentasResponse
  titulo: string
  vendedorLabel: string
  returnTo: string
}

const STORAGE_KEY = 'abc-ventas-preview-v1'

export function saveAbcPreview(payload: AbcPreviewPayload): void {
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
}

export function loadAbcPreview(): AbcPreviewPayload | null {
  const raw = sessionStorage.getItem(STORAGE_KEY)
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw) as AbcPreviewPayload
    if (!parsed?.data?.grupos) return null
    return parsed
  } catch {
    return null
  }
}
