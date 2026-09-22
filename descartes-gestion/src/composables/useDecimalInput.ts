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

/** Decimales máximos al mostrar: recorta la basura de coma flotante (17,120000000000001). */
const MAX_DECIMALES_DISPLAY = 6

/** Muestra el número con coma decimal (estilo ES) sin forzar decimales. */
export function formatDecimalDisplay(value: number | null | undefined): string {
  if (value == null || Number.isNaN(value)) return ''
  let texto = String(value)
  if (texto.includes('e') || texto.includes('E')) {
    texto = value.toFixed(MAX_DECIMALES_DISPLAY)
  }
  const punto = texto.indexOf('.')
  if (punto >= 0 && texto.length - punto - 1 > MAX_DECIMALES_DISPLAY) {
    texto = value.toFixed(MAX_DECIMALES_DISPLAY).replace(/0+$/, '').replace(/\.$/, '')
  }
  return texto.replace('.', ',')
}
