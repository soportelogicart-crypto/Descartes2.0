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
  'Ponga aquí el logo de cada tienda. El nombre del fichero es el código de tienda\n' +
  '(por ejemplo 1.png o 6.jpg). Formatos: png, jpg, jpeg, gif, webp, bmp.\n' +
  'Si no hay fichero, albaranes y facturas se imprimen sin logo.\n'

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

function findLogoFile(codigo) {
  const nombres = [...new Set(EXTENSIONES.flatMap((ext) => [codigo + ext, codigo.toLowerCase() + ext, codigo.toUpperCase() + ext]))]
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
  return null
}

function resolveEmpresa(codigo) {
  try {
    ensureUserLogosDir()
    const code = codigoSeguro(codigo)
    if (!code) return { ok: true, dataUrl: '' }
    const file = findLogoFile(code)
    if (!file) return { ok: true, dataUrl: '' }
    const ext = path.extname(file).toLowerCase()
    const mime = MIME[ext] || 'image/png'
    const buf = fs.readFileSync(file)
    if (!buf.length) return { ok: true, dataUrl: '' }
    return { ok: true, dataUrl: `data:${mime};base64,${buf.toString('base64')}` }
  } catch (err) {
    return { ok: false, dataUrl: '', message: err instanceof Error ? err.message : String(err) }
  }
}

module.exports = {
  resolveEmpresa,
  userLogosDir,
}
