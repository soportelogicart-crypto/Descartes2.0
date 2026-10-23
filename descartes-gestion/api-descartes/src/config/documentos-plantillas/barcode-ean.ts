/**
 * EAN-13 / EAN-8 → SVG (simbología retail; legible por escáneres de tienda).
 * Si el valor no es 8 u 13 dígitos válidos, devolver null y usar otro simbología.
 */

/** Patrones L/G/R: 7 módulos; 1 = barra, 0 = espacio. */
const L: string[] = [
  '0001101',
  '0011001',
  '0010011',
  '0111101',
  '0100011',
  '0110001',
  '0101111',
  '0111011',
  '0110111',
  '0001011',
]
const G: string[] = [
  '0100111',
  '0110011',
  '0011011',
  '0100001',
  '0011101',
  '0111001',
  '0000101',
  '0010001',
  '0001001',
  '0010111',
]
const R: string[] = [
  '1110010',
  '1100110',
  '1101100',
  '1000010',
  '1011100',
  '1001110',
  '1010000',
  '1000100',
  '1001000',
  '1110100',
]

/** Primer dígito EAN-13 → patrón L/G de los 6 siguientes. */
const FIRST: string[] = [
  'LLLLLL',
  'LLGLGG',
  'LLGGLG',
  'LLGGGL',
  'LGLLGG',
  'LGGLLG',
  'LGGGLL',
  'LGLGLG',
  'LGLGGL',
  'LGGLGL',
]

function onlyDigits(s: string): string {
  return String(s ?? '').replace(/\D/g, '')
}

/** Calcula dígito de control EAN (mod 10). `body` = dígitos sin el check. */
export function eanCheckDigit(body: string): number {
  const d = onlyDigits(body)
  let sum = 0
  for (let i = 0; i < d.length; i++) {
    const n = Number(d[d.length - 1 - i])
    sum += i % 2 === 0 ? n * 3 : n
  }
  return (10 - (sum % 10)) % 10
}

/**
 * Normaliza a EAN-13 o EAN-8 con check digit correcto.
 * Acepta 7/8 → EAN-8; 12/13 → EAN-13 (recalcula check si hace falta).
 */
export function normalizeEan(value: string): { digits: string; kind: 'ean8' | 'ean13' } | null {
  let d = onlyDigits(value)
  if (!d) return null

  // Float legacy / notación científica ya debería venir limpia; por si acaso
  if (d.length > 13) d = d.slice(0, 13)

  if (d.length === 7) {
    d = d + String(eanCheckDigit(d))
  }
  if (d.length === 8) {
    const body = d.slice(0, 7)
    const check = eanCheckDigit(body)
    return { digits: body + String(check), kind: 'ean8' }
  }

  if (d.length === 12) {
    d = d + String(eanCheckDigit(d))
  }
  if (d.length === 13) {
    const body = d.slice(0, 12)
    const check = eanCheckDigit(body)
    return { digits: body + String(check), kind: 'ean13' }
  }

  return null
}

function modulesFromPattern(bits: string, modules: { bar: boolean }[]): void {
  for (let i = 0; i < bits.length; i++) {
    modules.push({ bar: bits[i] === '1' })
  }
}

function encodeEan13(digits: string): { bar: boolean }[] {
  const modules: { bar: boolean }[] = []
  const first = Number(digits[0])
  const leftPattern = FIRST[first] ?? FIRST[0]

  modulesFromPattern('101', modules) // start
  for (let i = 0; i < 6; i++) {
    const digit = Number(digits[i + 1])
    const side = leftPattern[i]
    const pat = side === 'G' ? G[digit] : L[digit]
    modulesFromPattern(pat, modules)
  }
  modulesFromPattern('01010', modules) // center
  for (let i = 7; i < 13; i++) {
    modulesFromPattern(R[Number(digits[i])], modules)
  }
  modulesFromPattern('101', modules) // end
  return modules
}

function encodeEan8(digits: string): { bar: boolean }[] {
  const modules: { bar: boolean }[] = []
  modulesFromPattern('101', modules)
  for (let i = 0; i < 4; i++) {
    modulesFromPattern(L[Number(digits[i])], modules)
  }
  modulesFromPattern('01010', modules)
  for (let i = 4; i < 8; i++) {
    modulesFromPattern(R[Number(digits[i])], modules)
  }
  modulesFromPattern('101', modules)
  return modules
}

function escapeXml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

/**
 * SVG EAN-13/8. `null` si el valor no es un EAN válido.
 * Barras con ancho uniforme (1 módulo); no estirar con preserveAspectRatio=none.
 */
export function eanSvg(
  value: string,
  opts?: { height?: number; showText?: boolean; moduleWidth?: number }
): string | null {
  const norm = normalizeEan(value)
  if (!norm) return null

  const modules =
    norm.kind === 'ean8' ? encodeEan8(norm.digits) : encodeEan13(norm.digits)
  const mw = opts?.moduleWidth ?? 1.2
  const h = opts?.height ?? 48
  const textH = opts?.showText === false ? 0 : 11
  const barH = h - textH
  const quiet = 8 * mw
  const barsW = modules.length * mw
  const totalW = quiet * 2 + barsW

  let x = quiet
  let rects = ''
  for (const m of modules) {
    if (m.bar) {
      rects += `<rect x="${x.toFixed(2)}" y="0" width="${mw.toFixed(2)}" height="${barH}" fill="#000"/>`
    }
    x += mw
  }

  const text =
    opts?.showText === false
      ? ''
      : `<text x="${(totalW / 2).toFixed(2)}" y="${h - 1}" text-anchor="middle" font-family="monospace" font-size="9" fill="#000">${escapeXml(norm.digits)}</text>`

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${totalW.toFixed(2)} ${h}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet">${rects}${text}</svg>`
}
