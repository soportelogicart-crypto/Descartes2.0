/**
 * Codifica texto de ticket a bytes ESC/POS (Epson-compatible).
 * Sin dependencias npm nativas.
 */

'use strict'

/** Inicializa impresora + code page PC437 (Europa/USA básico). */
function escInit() {
  return Buffer.from([
    0x1b, 0x40, // ESC @ init
    0x1b, 0x74, 0x00, // ESC t 0 — PC437
    0x1b, 0x4d, 0x01, // ESC M 1 — Font B (más estrecha: caben las 4 columnas)
    0x1d, 0x21, 0x00, // GS ! 0 — tamaño normal
    0x1b, 0x61, 0x00, // ESC a 0 — align left
  ])
}

function escAlign(mode) {
  // 0 left, 1 center, 2 right
  const m = mode === 'center' ? 1 : mode === 'right' ? 2 : 0
  return Buffer.from([0x1b, 0x61, m])
}

function escBold(on) {
  return Buffer.from([0x1b, 0x45, on ? 1 : 0])
}

function escCut() {
  // GS V 66 0 — partial cut with feed
  return Buffer.from([0x1d, 0x56, 0x42, 0x00])
}

function escFeed(lines) {
  const n = Math.max(0, Math.min(20, Number(lines) || 3))
  return Buffer.from([0x1b, 0x64, n]) // ESC d n
}

/** Apertura cajón pin 2 (kick). */
function escOpenDrawer() {
  return Buffer.from([0x1b, 0x70, 0x00, 0x19, 0xfa])
}

/**
 * Convierte texto UTF-8 a bytes “seguros” para térmica (ASCII + sustituciones).
 * Las térmicas legacy suelen ir en CP437/850; evitamos caracteres rotos.
 */
function toPrinterBytes(text) {
  const map = {
    Á: 'A',
    À: 'A',
    Ä: 'A',
    É: 'E',
    È: 'E',
    Í: 'I',
    Ì: 'I',
    Ó: 'O',
    Ò: 'O',
    Ö: 'O',
    Ú: 'U',
    Ù: 'U',
    Ü: 'U',
    Ñ: 'N',
    Ç: 'C',
    á: 'a',
    à: 'a',
    ä: 'a',
    é: 'e',
    è: 'e',
    í: 'i',
    ì: 'i',
    ó: 'o',
    ò: 'o',
    ö: 'o',
    ú: 'u',
    ù: 'u',
    ü: 'u',
    ñ: 'n',
    ç: 'c',
    '€': 'EUR',
    '·': '-',
    '—': '-',
    '–': '-',
    '“': '"',
    '”': '"',
    '‘': "'",
    '’': "'",
  }
  let s = String(text ?? '')
  for (const [k, v] of Object.entries(map)) {
    s = s.split(k).join(v)
  }
  // Solo bytes 32-126 + CR/LF/TAB
  const out = []
  for (let i = 0; i < s.length; i++) {
    const c = s.charCodeAt(i)
    if (c === 10 || c === 13 || c === 9) {
      out.push(c)
    } else if (c >= 32 && c <= 126) {
      out.push(c)
    } else {
      out.push(63) // ?
    }
  }
  return Buffer.from(out)
}

/** Prefijos de línea que genera el frontend (ticket-texto.ts). */
const MARCA_GRANDE = '@@GRANDE@@'
const MARCA_DOBLE = '@@DOBLE@@'
const MARCA_BARRAS = '@@BARRAS@@'

function escFontB() {
  return Buffer.from([0x1b, 0x4d, 0x01, 0x1d, 0x21, 0x00, 0x1b, 0x45, 0x00])
}

/** Font A en negrita; `doble` duplica solo el alto (el ancho sigue en 42 columnas). */
function escFontGrande(doble) {
  return Buffer.from([0x1b, 0x4d, 0x00, 0x1d, 0x21, doble ? 0x01 : 0x00, 0x1b, 0x45, 0x01])
}

/**
 * GS1-128 / Code 128 centrado con el texto debajo.
 * Solo dígitos en número par → juego C (más corto); si no, juego B.
 */
function escCode128(data) {
  const limpio = String(data ?? '').replace(/\*/g, '').trim()
  if (!limpio) return Buffer.alloc(0)
  let cuerpo
  if (/^\d+$/.test(limpio) && limpio.length % 2 === 0) {
    const pares = []
    for (let i = 0; i < limpio.length; i += 2) pares.push(Number(limpio.slice(i, i + 2)))
    cuerpo = Buffer.concat([Buffer.from('{C', 'ascii'), Buffer.from(pares)])
  } else {
    cuerpo = Buffer.concat([Buffer.from('{B', 'ascii'), toPrinterBytes(limpio)])
  }
  if (cuerpo.length > 255) return toPrinterBytes(limpio + '\n')
  return Buffer.concat([
    escAlign('center'),
    Buffer.from([0x1d, 0x68, 80]), // GS h — alto en puntos
    Buffer.from([0x1d, 0x77, 2]), // GS w — ancho de módulo
    Buffer.from([0x1d, 0x48, 2]), // GS H — texto legible debajo
    Buffer.from([0x1d, 0x66, 0]), // GS f — fuente A del texto legible
    Buffer.from([0x1d, 0x6b, 73, cuerpo.length]), // GS k m=73 (Code 128)
    cuerpo,
    Buffer.from('\n'),
    escAlign('left'),
  ])
}

function pushLineaTexto(parts, line, ancho) {
  let l = line
  while (l.length > ancho) {
    parts.push(toPrinterBytes(l.slice(0, ancho) + '\n'))
    l = l.slice(ancho)
  }
  parts.push(toPrinterBytes(l + '\n'))
}

/**
 * @param {object} opts
 * @param {string} opts.texto
 * @param {boolean} [opts.cortar]
 * @param {boolean} [opts.abrirCajon]
 * @param {number} [opts.feed]
 * @param {number} [opts.ancho] caracteres por línea (default 56, Font B en 80 mm)
 */
function buildTicketBuffer(opts = {}) {
  const texto = String(opts.texto ?? '')
  const ancho = Math.max(24, Math.min(64, Number(opts.ancho) || 56))
  const parts = [escInit()]

  const lines = texto.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')
  // Font A tiene 42 columnas en 80 mm (también a doble alto); Font B, 56.
  const anchoGrande = Math.round((ancho * 42) / 56)
  for (const line of lines) {
    if (line.startsWith(MARCA_BARRAS)) {
      parts.push(escCode128(line.slice(MARCA_BARRAS.length)))
    } else if (line.startsWith(MARCA_DOBLE)) {
      parts.push(escFontGrande(true))
      pushLineaTexto(parts, line.slice(MARCA_DOBLE.length), anchoGrande)
      parts.push(escFontB())
    } else if (line.startsWith(MARCA_GRANDE)) {
      parts.push(escFontGrande(false))
      pushLineaTexto(parts, line.slice(MARCA_GRANDE.length), anchoGrande)
      parts.push(escFontB())
    } else {
      pushLineaTexto(parts, line, ancho)
    }
  }

  parts.push(escFeed(opts.feed != null ? opts.feed : 4))
  if (opts.abrirCajon) {
    parts.push(escOpenDrawer())
  }
  if (opts.cortar !== false) {
    parts.push(escCut())
  }

  return Buffer.concat(parts)
}

/**
 * Logo centrado (PNG/JPEG data URL) como raster ESC/POS.
 * @param {string} dataUrl
 * @param {typeof import('electron').nativeImage} nativeImage
 */
function buildLogoEscPos(dataUrl, nativeImage) {
  if (!dataUrl || !nativeImage) return Buffer.alloc(0)
  try {
    const img = nativeImage.createFromDataURL(String(dataUrl))
    if (!img.isEmpty()) {
      const maxW = 320
      const size = img.getSize()
      if (size.width < 8 || size.height < 8) return Buffer.alloc(0)
      const nw = Math.min(maxW, size.width)
      const nh = Math.max(1, Math.round((size.height * nw) / size.width))
      const resized = img.resize({ width: nw, height: nh, quality: 'good' })
      const bmp = resized.toBitmap()
      const bytesPerRow = Math.ceil(nw / 8)
      const raster = Buffer.alloc(bytesPerRow * nh)
      for (let y = 0; y < nh; y++) {
        for (let x = 0; x < nw; x++) {
          const i = (y * nw + x) * 4
          const lum = (bmp[i + 2] + bmp[i + 1] + bmp[i]) / 3
          if (lum < 210) {
            raster[y * bytesPerRow + Math.floor(x / 8)] |= 0x80 >> (x % 8)
          }
        }
      }
      const header = Buffer.from([
        0x1d, 0x76, 0x30, 0x00,
        bytesPerRow & 0xff,
        (bytesPerRow >> 8) & 0xff,
        nh & 0xff,
        (nh >> 8) & 0xff,
      ])
      return Buffer.concat([escAlign('center'), header, raster, escAlign('left'), Buffer.from('\n')])
    }
  } catch {
    /* sin logo */
  }
  return Buffer.alloc(0)
}

module.exports = {
  buildTicketBuffer,
  buildLogoEscPos,
  escInit,
  escCut,
  escOpenDrawer,
  escFeed,
  escAlign,
  escBold,
  toPrinterBytes,
}
