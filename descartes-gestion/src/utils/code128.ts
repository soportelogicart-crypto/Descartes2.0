/**
 * Code 128, juego B, como SVG. Cubre el texto del encargo (DCTB:E:cliente:numero).
 * Los patrones son los 107 del estándar: 0-105 de 11 módulos y el stop de 13.
 */
const PATRONES = [
  '11011001100', '11001101100', '11001100110', '10010011000', '10010001100',
  '10001001100', '10011001000', '10011000100', '10001100100', '11001001000',
  '11001000100', '11000100100', '10110011100', '10011011100', '10011001110',
  '10111001100', '10011101100', '10011100110', '11001110010', '11001011100',
  '11001001110', '11011100100', '11001110100', '11101101110', '11101001100',
  '11100101100', '11100100110', '11101100100', '11100110100', '11100110010',
  '11011011000', '11011000110', '11000110110', '10100011000', '10001011000',
  '10001000110', '10110001000', '10001101000', '10001100010', '11010001000',
  '11000101000', '11000100010', '10110111000', '10110001110', '10001101110',
  '10111011000', '10111000110', '10001110110', '11101110110', '11010001110',
  '11000101110', '11011101000', '11011100010', '11011101110', '11101011000',
  '11101000110', '11100010110', '11101101000', '11101100010', '11100011010',
  '11101111010', '11001000010', '11110001010', '10100110000', '10100001100',
  '10010110000', '10010000110', '10000101100', '10000100110', '10110010000',
  '10110000100', '10011010000', '10011000010', '10000110100', '10000110010',
  '11000010010', '11001010000', '11110111010', '11000010100', '10001111010',
  '10100111100', '10010111100', '10010011110', '10111100100', '10011110100',
  '10011110010', '11110100100', '11110010100', '11110010010', '11011011110',
  '11011110110', '11110110110', '10101111000', '10100011110', '10001011110',
  '10111101000', '10111100010', '11110101000', '11110100010', '10111011110',
  '10111101110', '11101011110', '11110101110', '11010000100', '11010010000',
  '11010011100', '1100011101011',
]

const INICIO_B = 104
const STOP = 106
const ZONA = 10

function bitsCode128(texto: string): string {
  const limpio = String(texto ?? '')
  if (!limpio || [...limpio].some((c) => c.charCodeAt(0) < 32 || c.charCodeAt(0) > 126)) {
    return ''
  }
  const codigos = [INICIO_B]
  for (const c of limpio) codigos.push(c.charCodeAt(0) - 32)
  let suma = codigos[0]
  for (let i = 1; i < codigos.length; i++) suma += codigos[i] * i
  codigos.push(suma % 103)
  codigos.push(STOP)
  return codigos.map((n) => PATRONES[n] ?? '').join('')
}

export function code128Svg(texto: string): string {
  const bits = bitsCode128(texto)
  if (!bits) return ''
  const alto = 40
  let x = ZONA
  let barras = ''
  let i = 0
  while (i < bits.length) {
    if (bits[i] === '1') {
      let ancho = 1
      while (bits[i + ancho] === '1') ancho++
      barras += `<rect x="${x}" y="0" width="${ancho}" height="${alto}" fill="#000"/>`
      x += ancho
      i += ancho
    } else {
      x += 1
      i += 1
    }
  }
  x += ZONA
  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${x} ${alto}" width="100%" height="100%" preserveAspectRatio="none" shape-rendering="crispEdges">${barras}</svg>`
}

/** Dibuja el Code 128 con cada barra en píxeles enteros, para que el lector la distinga. */
export function dibujarCode128(
  g: CanvasRenderingContext2D,
  texto: string,
  x: number,
  y: number,
  maxAncho: number,
  alto: number,
): boolean {
  const bits = bitsCode128(texto)
  if (!bits || maxAncho < 20 || alto < 4) return false
  const total = bits.length + ZONA * 2
  const modulo = Math.max(2, Math.floor(maxAncho / total))
  const origen = Math.round(x)
  const fila = Math.round(y)
  const altoPx = Math.max(1, Math.round(alto))
  g.imageSmoothingEnabled = false
  g.fillStyle = '#000'
  let px = origen + ZONA * modulo
  let i = 0
  while (i < bits.length) {
    if (bits[i] === '1') {
      let ancho = 1
      while (bits[i + ancho] === '1') ancho++
      g.fillRect(px, fila, ancho * modulo, altoPx)
      px += ancho * modulo
      i += ancho
    } else {
      px += modulo
      i += 1
    }
  }
  return true
}
