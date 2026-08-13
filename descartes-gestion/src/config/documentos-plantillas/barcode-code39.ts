/**
 * Code 39 (estándar) → SVG para barras de etiqueta.
 * Suficiente para EAN numérico / códigos alfanuméricos cortos sin librería externa.
 */

const CODE39: Record<string, string> = {
  '0': 'nnnwwnwnn',
  '1': 'wnnwnnnnw',
  '2': 'nnwwnnnnw',
  '3': 'wnwwnnnnn',
  '4': 'nnnwwnnnw',
  '5': 'wnnwwnnnn',
  '6': 'nnwwwnnnn',
  '7': 'nnnwnnwnw',
  '8': 'wnnwnnwnn',
  '9': 'nnwwnnwnn',
  A: 'wnnnnwnnw',
  B: 'nnwnnwnnw',
  C: 'wnwnnwnnn',
  D: 'nnnnwwnnw',
  E: 'wnnnwwnnn',
  F: 'nnwnwwnnn',
  G: 'nnnnnwwnw',
  H: 'wnnnnwwnn',
  I: 'nnwnnwwnn',
  J: 'nnnnwwwnn',
  K: 'wnnnnnnww',
  L: 'nnwnnnnww',
  M: 'wnwnnnnwn',
  N: 'nnnnwnnww',
  O: 'wnnnwnnwn',
  P: 'nnwnwnnwn',
  Q: 'nnnnnnwww',
  R: 'wnnnnnwwn',
  S: 'nnwnnnwwn',
  T: 'nnnnwnwwn',
  U: 'wwnnnnnnw',
  V: 'nwwnnnnnw',
  W: 'wwwnnnnnn',
  X: 'nwnnwnnnw',
  Y: 'wwnnwnnnn',
  Z: 'nwwnwnnnn',
  '-': 'nwnnnnwnw',
  '.': 'wwnnnnwnn',
  ' ': 'nwwnnnwnn',
  '*': 'nwnnwnwnn',
  $: 'nwnwnwnnn',
  '/': 'nwnwnnnwn',
  '+': 'nwnnnwnwn',
  '%': 'nnnwnwnwn',
}

const NARROW = 1
const WIDE = 2.4
const GAP = 1

function patternForChar(ch: string): string | null {
  return CODE39[ch] ?? null
}

/** Normaliza a caracteres Code39; filtra el resto. */
export function normalizeCode39(value: string): string {
  return String(value ?? '')
    .toUpperCase()
    .replace(/[^0-9A-Z\-. $/+%]/g, '')
}

/**
 * SVG Code39 (start/stop `*`). Ancho/alto en unidades relativas; se escala con viewBox.
 */
export function code39Svg(
  value: string,
  opts?: { height?: number; showText?: boolean; moduleWidth?: number }
): string {
  const raw = normalizeCode39(value)
  if (!raw) {
    return `<svg xmlns="http://www.w3.org/2000/svg" width="100" height="40"><text x="4" y="24" font-size="10" fill="#94a3b8">sin EAN</text></svg>`
  }

  const scale = opts?.moduleWidth && opts.moduleWidth > 0 ? opts.moduleWidth / NARROW : 1
  const payload = `*${raw}*`
  const modules: { bar: boolean; w: number }[] = []
  for (let i = 0; i < payload.length; i++) {
    const pat = patternForChar(payload[i])
    if (!pat) continue
    for (let j = 0; j < pat.length; j++) {
      modules.push({
        bar: j % 2 === 0,
        w: (pat[j] === 'w' ? WIDE : NARROW) * scale,
      })
    }
    if (i < payload.length - 1) {
      modules.push({ bar: false, w: GAP * scale })
    }
  }

  let total = 0
  for (const m of modules) total += m.w
  const h = opts?.height ?? 40
  const textH = opts?.showText === false ? 0 : 12
  const barH = h - textH
  let x = 0
  let rects = ''
  for (const m of modules) {
    if (m.bar) {
      rects += `<rect x="${x.toFixed(2)}" y="0" width="${m.w.toFixed(2)}" height="${barH}" fill="#000"/>`
    }
    x += m.w
  }

  const text =
    opts?.showText === false
      ? ''
      : `<text x="${(total / 2).toFixed(2)}" y="${h - 1}" text-anchor="middle" font-family="monospace" font-size="9" fill="#000">${escapeXml(raw)}</text>`

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${total.toFixed(2)} ${h}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet">${rects}${text}</svg>`
}

function escapeXml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
