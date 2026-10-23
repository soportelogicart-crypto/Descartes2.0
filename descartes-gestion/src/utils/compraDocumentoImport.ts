/** Utilidades importación documento proveedor (albarán compras). */

export type CompraArchivoKind = 'pdf' | 'imagen' | 'csv' | 'texto' | 'desconocido'

const IMG_EXT = new Set(['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])

export function kindFromFile(file: File): CompraArchivoKind {
  const name = file.name.toLowerCase()
  const type = (file.type || '').toLowerCase()
  if (type === 'application/pdf' || name.endsWith('.pdf')) return 'pdf'
  if (type.startsWith('image/') || [...IMG_EXT].some((e) => name.endsWith('.' + e))) return 'imagen'
  if (name.endsWith('.csv') || type.includes('csv')) return 'csv'
  if (name.endsWith('.txt') || type.startsWith('text/')) return 'texto'
  return 'desconocido'
}

/** Sugiere «Su albarán» desde el nombre del fichero (sin extensión). */
export function suAlbaranDesdeNombreArchivo(nombre: string): string {
  const base = nombre.replace(/\.[^.]+$/, '').trim()
  if (!base) return ''
  const m =
    base.match(/(?:albar[aá]n|alb|delivery|doc)[\s._-]*([a-z0-9][a-z0-9./-]{2,18})/i) ??
    base.match(/\b([0-9]{4,20})\b/)
  if (m?.[1]) return m[1].slice(0, 20)
  if (base.length <= 20) return base
  return base.slice(0, 20)
}

export type CsvParseResult = {
  headers: string[]
  rows: string[][]
  delimiter: string
}

function detectDelimiter(line: string): string {
  const counts: Record<string, number> = { ';': 0, ',': 0, '\t': 0 }
  for (const ch of line) {
    if (ch in counts) counts[ch]++
  }
  let best = ';'
  let max = -1
  for (const [d, c] of Object.entries(counts)) {
    if (c > max) {
      max = c
      best = d
    }
  }
  return best
}

function parseCsvLine(line: string, delimiter: string): string[] {
  const out: string[] = []
  let cur = ''
  let inQuotes = false
  for (let i = 0; i < line.length; i++) {
    const ch = line[i]
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        cur += '"'
        i++
      } else {
        inQuotes = !inQuotes
      }
      continue
    }
    if (!inQuotes && ch === delimiter) {
      out.push(cur.trim())
      cur = ''
      continue
    }
    cur += ch
  }
  out.push(cur.trim())
  return out
}

/** Parsea CSV/TSV en cliente (Fase 0). */
export function parseCsvText(raw: string): CsvParseResult {
  const normalized = raw.replace(/\r\n/g, '\n').replace(/\r/g, '\n')
  const lines = normalized.split('\n').filter((l) => l.trim() !== '')
  if (lines.length === 0) {
    return { headers: [], rows: [], delimiter: ';' }
  }
  const delimiter = detectDelimiter(lines[0])
  const headers = parseCsvLine(lines[0], delimiter)
  const rows = lines.slice(1).map((l) => parseCsvLine(l, delimiter))
  return { headers, rows, delimiter }
}

export type ColumnMapKey = 'codigo' | 'ean' | 'descripcion' | 'cantidad' | 'precio' | 'dto'

export function guessColumnMap(headers: string[]): Partial<Record<ColumnMapKey, number>> {
  const map: Partial<Record<ColumnMapKey, number>> = {}
  const norm = headers.map((h) =>
    h
      .toLowerCase()
      .normalize('NFD')
      .replace(/\p{M}/gu, '')
      .trim()
  )
  const find = (...needles: string[]) => {
    const idx = norm.findIndex((h) => needles.some((n) => h.includes(n)))
    return idx >= 0 ? idx : undefined
  }
  map.codigo = find('codigo', 'codi', 'articulo', 'art', 'ref', 'sku')
  map.ean = find('ean', 'barcode', 'codbar', 'barras')
  map.descripcion = find('descripcion', 'desc', 'nombre', 'producto')
  map.cantidad = find('cantidad', 'cant', 'uds', 'unidades', 'qty')
  map.precio = find('precio', 'pvp', 'importe', 'coste', 'unit')
  map.dto = find('dto', 'descuento', 'discount')
  return map
}

export type FilaImportCompra = {
  codigo: string
  cantidad: number
  precio: number
  descripcion: string
  dto: number
}

export function filasDesdeCsv(
  parsed: CsvParseResult,
  columnMap: Partial<Record<ColumnMapKey, number>>
): FilaImportCompra[] {
  const out: FilaImportCompra[] = []
  const col = (key: ColumnMapKey, row: string[]) => {
    const i = columnMap[key]
    if (i == null || i < 0 || i >= row.length) return ''
    return String(row[i] ?? '').trim()
  }
  const num = (s: string) => {
    const t = s.replace(/\s/g, '').replace(',', '.')
    const n = Number(t)
    return Number.isFinite(n) ? n : 0
  }
  for (const row of parsed.rows) {
    if (row.every((c) => !String(c).trim())) continue
    const codigo = col('codigo', row) || col('ean', row)
    if (!codigo) continue
    out.push({
      codigo,
      cantidad: num(col('cantidad', row)) || 1,
      precio: num(col('precio', row)),
      descripcion: col('descripcion', row),
      dto: num(col('dto', row)),
    })
  }
  return out
}

export async function readFileAsText(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result ?? ''))
    reader.onerror = () => reject(reader.error ?? new Error('No se pudo leer el fichero'))
    reader.readAsText(file, 'UTF-8')
  })
}

export async function readFileAsBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const data = String(reader.result ?? '')
      const idx = data.indexOf(',')
      resolve(idx >= 0 ? data.slice(idx + 1) : data)
    }
    reader.onerror = () => reject(reader.error ?? new Error('No se pudo leer el fichero'))
    reader.readAsDataURL(file)
  })
}
