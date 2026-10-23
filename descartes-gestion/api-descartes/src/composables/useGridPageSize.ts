/** Preferencia global de "Por pagina" en listados grid (localStorage). */

export const GRID_PAGE_SIZE_KEY = 'descartes.gridPageSize'
export const GRID_PAGE_SIZE_OPTIONS = [25, 50, 100, 200] as const
export type GridPageSize = (typeof GRID_PAGE_SIZE_OPTIONS)[number]

/** Al abrir un grid sin filtrar, no se vuelcan todos los registros. */
export const GRID_LIMITE_INICIAL = 200

const DEFAULT_PAGE_SIZE: GridPageSize = 200

export function leerGridPageSize(fallback: number = DEFAULT_PAGE_SIZE): number {
  try {
    const raw = localStorage.getItem(GRID_PAGE_SIZE_KEY)
    if (raw == null || raw === '') return fallback
    const n = Number(raw)
    if (!Number.isFinite(n) || n <= 0) return fallback
    if ((GRID_PAGE_SIZE_OPTIONS as readonly number[]).includes(n)) return n
    // Si guardaron un valor raro, acercar al option mas cercano permitido.
    return GRID_PAGE_SIZE_OPTIONS.reduce((best, opt) =>
      Math.abs(opt - n) < Math.abs(best - n) ? opt : best
    )
  } catch {
    return fallback
  }
}

export function guardarGridPageSize(size: number): void {
  const n = Number(size)
  if (!Number.isFinite(n) || n <= 0) return
  try {
    localStorage.setItem(GRID_PAGE_SIZE_KEY, String(Math.trunc(n)))
  } catch {
    /* sin persistencia (modo privado / cuota) */
  }
}
