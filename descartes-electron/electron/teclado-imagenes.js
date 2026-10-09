/**
 * Imágenes de los botones del teclado táctil del TPV.
 * Como en legacy (DefPlus.H_ICON), el botón guarda la ruta del fichero en el disco
 * de cada PC de caja; aquí solo se lee y se devuelve como data URL.
 */
const fs = require('fs')
const path = require('path')

const MIME = {
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.bmp': 'image/bmp',
  '.ico': 'image/x-icon',
}

/** H_ICON es varchar(100). */
const LONGITUD_MAXIMA_RUTA = 100
const TAMANO_MAXIMO = 5 * 1024 * 1024

function leerImagen(ruta) {
  try {
    const file = String(ruta || '').trim()
    if (!file) return { ok: true, dataUrl: '' }
    const mime = MIME[path.extname(file).toLowerCase()]
    if (!mime) return { ok: false, dataUrl: '', message: 'Formato de imagen no admitido' }
    if (!fs.existsSync(file)) return { ok: true, dataUrl: '', noExiste: true }
    const stat = fs.statSync(file)
    if (!stat.isFile() || stat.size > TAMANO_MAXIMO) {
      return { ok: false, dataUrl: '', message: 'La imagen no es válida o supera 5 MB' }
    }
    const buf = fs.readFileSync(file)
    if (!buf.length) return { ok: true, dataUrl: '' }
    return { ok: true, dataUrl: `data:${mime};base64,${buf.toString('base64')}` }
  } catch (err) {
    return { ok: false, dataUrl: '', message: err instanceof Error ? err.message : String(err) }
  }
}

/**
 * @param {import('electron').BrowserWindow | null | undefined} parentWin
 */
async function elegirImagen(parentWin) {
  const { dialog } = require('electron')
  try {
    const { canceled, filePaths } = await dialog.showOpenDialog(parentWin ?? undefined, {
      title: 'Imagen del botón',
      filters: [{ name: 'Imagen', extensions: Object.keys(MIME).map((e) => e.slice(1)) }],
      properties: ['openFile'],
    })
    if (canceled || !filePaths?.[0]) return { ok: true, cancelado: true }
    const ruta = filePaths[0]
    if (ruta.length > LONGITUD_MAXIMA_RUTA) {
      return {
        ok: false,
        message: `La ruta no puede superar ${LONGITUD_MAXIMA_RUTA} caracteres. Copie la imagen a una carpeta más corta (p. ej. C:\\Imagenes).`,
      }
    }
    const leida = leerImagen(ruta)
    if (!leida.ok) return { ok: false, message: leida.message }
    return { ok: true, ruta, dataUrl: leida.dataUrl }
  } catch (err) {
    return { ok: false, message: err instanceof Error ? err.message : String(err) }
  }
}

module.exports = { leerImagen, elegirImagen }
