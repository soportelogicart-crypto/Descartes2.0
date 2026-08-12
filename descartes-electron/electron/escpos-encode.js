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

/**
 * @param {object} opts
 * @param {string} opts.texto
 * @param {boolean} [opts.cortar]
 * @param {boolean} [opts.abrirCajon]
 * @param {number} [opts.feed]
 * @param {number} [opts.ancho] caracteres por línea (default 42)
 */
function buildTicketBuffer(opts = {}) {
  const texto = String(opts.texto ?? '')
  const ancho = Math.max(24, Math.min(64, Number(opts.ancho) || 42))
  const parts = [escInit()]

  const lines = texto.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')
  for (const line of lines) {
    let l = line
    if (l.length > ancho) {
      // wrap simple
      while (l.length > ancho) {
        parts.push(toPrinterBytes(l.slice(0, ancho) + '\n'))
        l = l.slice(ancho)
      }
    }
    parts.push(toPrinterBytes(l + '\n'))
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

module.exports = {
  buildTicketBuffer,
  escInit,
  escCut,
  escOpenDrawer,
  escFeed,
  escAlign,
  escBold,
  toPrinterBytes,
}
