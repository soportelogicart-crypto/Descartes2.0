const fs = require('fs')
const path = require('path')
const zlib = require('zlib')

const SIZE = 256
const BG = [15, 23, 42, 255]
const FG = [248, 250, 252, 255]

function crc32(buf) {
  let c = ~0
  for (let i = 0; i < buf.length; i++) {
    c ^= buf[i]
    for (let k = 0; k < 8; k++) c = (c >>> 1) ^ (0xedb88320 & -(c & 1))
  }
  return ~c >>> 0
}

function chunk(type, data) {
  const typeBuf = Buffer.from(type, 'ascii')
  const len = Buffer.alloc(4)
  len.writeUInt32BE(data.length)
  const crcBuf = Buffer.alloc(4)
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])))
  return Buffer.concat([len, typeBuf, data, crcBuf])
}

function pixel(x, y) {
  const cx = (x - SIZE / 2) / (SIZE / 2)
  const cy = (y - SIZE / 2) / (SIZE / 2)
  const inCircle = cx * cx + cy * cy <= 0.84
  const stem = x >= 78 && x <= 108 && y >= 58 && y <= 198
  const topBar = x >= 78 && x <= 168 && y >= 58 && y <= 86
  const midBar = x >= 78 && x <= 156 && y >= 118 && y <= 144
  const letter = stem || topBar || midBar
  return inCircle && letter ? FG : BG
}

const raw = Buffer.alloc((SIZE * 4 + 1) * SIZE)
for (let y = 0; y < SIZE; y++) {
  const row = y * (SIZE * 4 + 1)
  raw[row] = 0
  for (let x = 0; x < SIZE; x++) {
    const [r, g, b, a] = pixel(x, y)
    const o = row + 1 + x * 4
    raw[o] = r
    raw[o + 1] = g
    raw[o + 2] = b
    raw[o + 3] = a
  }
}

const ihdr = Buffer.alloc(13)
ihdr.writeUInt32BE(SIZE, 0)
ihdr.writeUInt32BE(SIZE, 4)
ihdr[8] = 8
ihdr[9] = 6

const png = Buffer.concat([
  Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
  chunk('IHDR', ihdr),
  chunk('IDAT', zlib.deflateSync(raw, { level: 9 })),
  chunk('IEND', Buffer.alloc(0)),
])

const outDir = path.join(__dirname, '..', 'icono')
fs.mkdirSync(outDir, { recursive: true })
const outFile = path.join(outDir, 'icon.png')
fs.writeFileSync(outFile, png)
console.log('Icono:', outFile)
