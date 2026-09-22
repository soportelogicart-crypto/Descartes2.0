/**
 * Logo de documentos A4: se busca un fichero con el código de tienda.
 * Si no hay fichero, la factura/albarán sale sin logo.
 */
const fs = require('fs')
const path = require('path')
const { app } = require('electron')

const EXTENSIONES = ['.png', '.jpg', '.jpeg', '.gif', '.webp', '.bmp']
const MIME = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.bmp': 'image/bmp',
}

const README =
  'Logos de albarán y factura (este PC)\n' +
  '================================\n\n' +
  'Puede copiar aquí una imagen manualmente O usar en Descartes:\n' +
  '  Mantenimiento → Tiendas → [tienda] → «Elegir imagen…»\n\n' +
  'Nombre del fichero = código de tienda (ej. 21.png, 6.jpg).\n' +
  'Formatos: png, jpg, jpeg, gif, webp, bmp.\n' +
  'Si no hay fichero, los documentos se imprimen sin logo.\n'

function codigoSeguro(raw) {
  return String(raw || '')
    .trim()
    .replace(/[^A-Za-z0-9._-]/g, '')
    .slice(0, 32)
}

function userLogosDir() {
  return path.join(app.getPath('userData'), 'logos')
}

function ensureUserLogosDir() {
  const dir = userLogosDir()
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true })
  }
  const readme = path.join(dir, 'LEEME.txt')
  if (!fs.existsSync(readme)) {
    fs.writeFileSync(readme, README, 'utf8')
  }
  return dir
}

function candidateDirs() {
  const dirs = [ensureUserLogosDir()]
  // Repositorio: descartes-electron/electron → ../../logos
  dirs.push(path.join(__dirname, '..', '..', 'logos'))
  dirs.push(path.join(__dirname, '..', 'logos'))
  if (process.resourcesPath) {
    dirs.push(path.join(process.resourcesPath, 'logos'))
  }
  return [...new Set(dirs.map((d) => path.normalize(d)))]
}

/** Mismas variantes que en BD (p. ej. tienda `1` vs venta `001`). */
function codigoVariantes(raw) {
  const out = []
  const add = (s) => {
    const x = String(s ?? '').trim()
    if (x && !out.includes(x)) out.push(x)
  }
  add(raw)
  const safe = codigoSeguro(raw)
  add(safe)
  if (/^\d+$/.test(safe)) {
    add(String(parseInt(safe, 10)))
    if (safe.length <= 3) add(safe.padStart(3, '0'))
  }
  return out
}

function findLogoFile(codigo) {
  for (const variant of codigoVariantes(codigo)) {
    const nombres = [
      ...new Set(
        EXTENSIONES.flatMap((ext) => [variant + ext, variant.toLowerCase() + ext, variant.toUpperCase() + ext])
      ),
    ]
    for (const dir of candidateDirs()) {
      if (!fs.existsSync(dir)) continue
      for (const nombre of nombres) {
        const full = path.join(dir, nombre)
        try {
          if (fs.existsSync(full) && fs.statSync(full).isFile()) return full
        } catch {
          /* ignore */
        }
      }
    }
  }
  return null
}

function fileToDataUrl(file) {
  const ext = path.extname(file).toLowerCase()
  const mime = MIME[ext] || 'image/png'
  const buf = fs.readFileSync(file)
  if (!buf.length) return ''
  return `data:${mime};base64,${buf.toString('base64')}`
}

function resolveEmpresa(codigo) {
  try {
    ensureUserLogosDir()
    const code = codigoSeguro(codigo)
    if (!code) return { ok: true, dataUrl: '' }
    const file = findLogoFile(code)
    if (!file) return { ok: true, dataUrl: '' }
    return { ok: true, dataUrl: fileToDataUrl(file), ruta: file }
  } catch (err) {
    return { ok: false, dataUrl: '', message: err instanceof Error ? err.message : String(err) }
  }
}

function quitarLogosAnteriores(codigo, extConservar) {
  const dir = ensureUserLogosDir()
  for (const ext of EXTENSIONES) {
    if (ext === extConservar) continue
    for (const nombre of [codigo + ext, codigo.toLowerCase() + ext, codigo.toUpperCase() + ext]) {
      const full = path.join(dir, nombre)
      try {
        if (fs.existsSync(full) && fs.statSync(full).isFile()) fs.unlinkSync(full)
      } catch {
        /* ignore */
      }
    }
  }
}

/**
 * Elige una imagen y la guarda como logo de la tienda (nombre = código de tienda).
 * @param {import('electron').BrowserWindow | null | undefined} parentWin
 */
async function saveEmpresaFromDialog(codigo, parentWin) {
  const { dialog } = require('electron')
  try {
    const code = codigoSeguro(codigo)
    if (!code) {
      return { ok: false, message: 'Codigo de tienda invalido' }
    }
    const dir = ensureUserLogosDir()
    const { canceled, filePaths } = await dialog.showOpenDialog(parentWin ?? undefined, {
      title: `Logo tienda ${code}`,
      filters: [{ name: 'Imagen', extensions: ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'] }],
      properties: ['openFile'],
    })
    if (canceled || !filePaths?.[0]) {
      return { ok: true, cancelado: true }
    }
    const src = filePaths[0]
    const ext = path.extname(src).toLowerCase()
    if (!EXTENSIONES.includes(ext)) {
      return { ok: false, message: 'Formato de imagen no admitido' }
    }
    const dest = path.join(dir, code + ext)
    fs.copyFileSync(src, dest)
    quitarLogosAnteriores(code, ext)
    return {
      ok: true,
      ruta: dest,
      carpeta: dir,
      dataUrl: fileToDataUrl(dest),
    }
  } catch (err) {
    return { ok: false, message: err instanceof Error ? err.message : String(err) }
  }
}

async function openLogosFolder() {
  const { shell } = require('electron')
  try {
    const dir = ensureUserLogosDir()
    const err = await shell.openPath(dir)
    if (err) {
      return { ok: false, carpeta: dir, message: err }
    }
    return { ok: true, carpeta: dir }
  } catch (err) {
    return { ok: false, message: err instanceof Error ? err.message : String(err) }
  }
}

function getLogosDirectory() {
  return { ok: true, carpeta: ensureUserLogosDir() }
}

module.exports = {
  resolveEmpresa,
  saveEmpresaFromDialog,
  openLogosFolder,
  getLogosDirectory,
  userLogosDir,
}
