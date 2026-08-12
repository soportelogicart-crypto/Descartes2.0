/** Parseo de importes/cantidades con coma o punto decimal (locale ES). */

export type ParseDecimalOptions = {
  integer?: boolean
}

export type ParseDecimalResult = {
  /** Valor numérico si es válido; null si vacío o incompleto. */
  value: number | null
  /** true si el texto es un número completo (no termina en "," / "."). */
  complete: boolean
}

export function parseDecimalInput(
  raw: string,
  options: ParseDecimalOptions = {}
): ParseDecimalResult {
  const t = String(raw ?? '')
    .trim()
    .replace(/\s/g, '')
    .replace(',', '.')

  if (t === '' || t === '-' || t === '.' || t === '-.') {
    return { value: null, complete: t === '' }
  }

  if (options.integer) {
    if (!/^-?\d*$/.test(t)) return { value: null, complete: false }
    if (t === '-' ) return { value: null, complete: false }
    const n = Number(t)
    return Number.isFinite(n) ? { value: Math.trunc(n), complete: true } : { value: null, complete: false }
  }

  if (!/^-?\d*\.?\d*$/.test(t)) {
    return { value: null, complete: false }
  }
  if (t.endsWith('.')) {
    return { value: null, complete: false }
  }
  const n = Number(t)
  return Number.isFinite(n) ? { value: n, complete: true } : { value: null, complete: false }
}

/** Muestra el número con coma decimal (estilo ES) sin forzar decimales. */
export function formatDecimalDisplay(value: number | null | undefined): string {
  if (value == null || Number.isNaN(value)) return ''
  return String(value).replace('.', ',')
}
