/**
 * Operaciones de la carpeta compartida de la instalación (red local Windows).
 * Solo sobre la ruta UNC que llega de la API; cualquier intento de salir de
 * esa raíz se rechaza en carpeta-compartida-rutas.js.
 */
const fs = require('fs')
const os = require('os')
const path = require('path')
const { execFileSync } = require('child_process')
const { dialog, shell } = require('electron')
const {
  rutaRaizValida,
  nombreValido,
  rutaCarpetaCliente,
  rutaDentro,
  explicarAcceso,
} = require('./carpeta-compartida-rutas')

const PREFIJO_PRUEBA = '.descartes-prueba-'

function error(message) {
  return { ok: false, message }
}

function raizOError(ruta) {
  const raiz = rutaRaizValida(ruta)
  if (!raiz) {
    return { error: error('La carpeta compartida debe ser una ruta de red (\\\\EQUIPO\\Carpeta).') }
  }
  return { raiz }
}

function hostDe(raiz) {
  return (String(raiz).match(/^\\\\([^\\]+)/) || [])[1] || ''
}

function esEsteEquipo(host) {
  return host.toUpperCase() === os.hostname().toUpperCase()
}

/** `net share` lista sin privilegios de administrador. Exit 2 = no existe. */
function recursoCompartido(nombre) {
  try {
    execFileSync('net', ['share', nombre], { windowsHide: true, stdio: 'pipe' })
    return true
  } catch {
    return false
  }
}

function mensajeDe(raiz, err) {
  const codigo = err && err.code
  const mismoEquipo = esEsteEquipo(hostDe(raiz))
  const recursoExiste = codigo === 'UNKNOWN' && mismoEquipo
    ? recursoCompartido((String(raiz).match(/^\\\\[^\\]+\\([^\\]+)/) || [])[1] || '')
    : undefined
  return (
    explicarAcceso(raiz, codigo, { mismoEquipo, recursoExiste }) ||
    (err && err.message) ||
    'Error de acceso a la carpeta compartida'
  )
}

/** Comprueba lectura, escritura y borrado en la raíz. */
function probar(ruta) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  try {
    if (!fs.statSync(raiz).isDirectory()) {
      return error('La ruta existe pero no es una carpeta.')
    }
    fs.readdirSync(raiz)
    const temporal = path.win32.join(raiz, `${PREFIJO_PRUEBA}${Date.now()}.tmp`)
    fs.writeFileSync(temporal, 'ok', 'utf8')
    fs.readFileSync(temporal, 'utf8')
    fs.unlinkSync(temporal)
    return { ok: true, message: 'Carpeta accesible: lectura y escritura correctas.' }
  } catch (err) {
    return error(mensajeDe(raiz, err))
  }
}

function listar(ruta, relativa) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  const dir = rutaDentro(raiz, relativa)
  if (!dir) return error('Ruta de carpeta no válida')
  try {
    const elementos = fs
      .readdirSync(dir, { withFileTypes: true })
      .filter((e) => !e.name.startsWith(PREFIJO_PRUEBA))
      .map((e) => {
        const full = path.win32.join(dir, e.name)
        let tamano = 0
        let modificado = null
        try {
          const st = fs.statSync(full)
          tamano = e.isDirectory() ? 0 : st.size
          modificado = st.mtime.toISOString()
        } catch {
          /* elemento que desaparece mientras se lista */
        }
        return { nombre: e.name, carpeta: e.isDirectory(), tamano, modificado }
      })
      .sort((a, b) => (a.carpeta === b.carpeta ? a.nombre.localeCompare(b.nombre, 'es') : a.carpeta ? -1 : 1))
    return { ok: true, elementos }
  } catch (err) {
    return error(mensajeDe(raiz, err))
  }
}

function crearCarpeta(ruta, relativa, nombre) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  if (!nombreValido(nombre)) return error('Nombre de carpeta no válido')
  const destino = rutaDentro(raiz, relativa, nombre)
  if (!destino) return error('Ruta de carpeta no válida')
  try {
    fs.mkdirSync(destino)
    return { ok: true, message: 'Carpeta creada.' }
  } catch (err) {
    return error(mensajeDe(raiz, err))
  }
}

/** Copia archivos elegidos en el diálogo. No pisa: añade " (2)", " (3)"… */
async function subirArchivos(ruta, relativa, parentWin) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  const dir = rutaDentro(raiz, relativa)
  if (!dir) return error('Ruta de carpeta no válida')
  const { canceled, filePaths } = await dialog.showOpenDialog(parentWin ?? undefined, {
    title: 'Elegir archivos para la carpeta compartida',
    properties: ['openFile', 'multiSelections'],
  })
  if (canceled || !filePaths?.length) return { ok: true, cancelado: true, copiados: 0 }
  let copiados = 0
  try {
    for (const origen of filePaths) {
      const base = path.basename(origen)
      if (!nombreValido(base)) continue
      const ext = path.extname(base)
      const sinExt = base.slice(0, base.length - ext.length)
      let destino = path.win32.join(dir, base)
      for (let n = 2; fs.existsSync(destino); n++) {
        destino = path.win32.join(dir, `${sinExt} (${n})${ext}`)
      }
      fs.copyFileSync(origen, destino)
      copiados += 1
    }
    return { ok: true, copiados, message: `${copiados} archivo(s) copiados.` }
  } catch (err) {
    return { ...error(mensajeDe(raiz, err)), copiados }
  }
}

async function abrir(ruta, relativa, nombre) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  const destino = rutaDentro(raiz, relativa, nombre)
  if (!destino) return error('Ruta de archivo no válida')
  const err = await shell.openPath(destino)
  return err ? error(err) : { ok: true }
}

/** Borra un archivo o una carpeta vacía. Nunca borra árboles completos. */
function borrar(ruta, relativa, nombre) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  const destino = rutaDentro(raiz, relativa, nombre)
  if (!destino || destino === raiz) return error('Ruta no válida')
  try {
    const st = fs.statSync(destino)
    if (st.isDirectory()) {
      fs.rmdirSync(destino)
    } else {
      fs.unlinkSync(destino)
    }
    return { ok: true, message: 'Eliminado.' }
  } catch (err) {
    return error(mensajeDe(raiz, err))
  }
}

/**
 * Carpeta del cliente dentro de la compartida: Clientes\{codigo}.
 * La crea si no existe y devuelve su UNC para abrirla como raíz.
 */
function carpetaCliente(ruta, codigo) {
  const { raiz, error: fallo } = raizOError(ruta)
  if (fallo) return fallo
  const destino = rutaCarpetaCliente(raiz, codigo)
  if (!destino) return error('El código del cliente no vale como nombre de carpeta.')
  try {
    fs.mkdirSync(destino, { recursive: true })
    if (!fs.statSync(destino).isDirectory()) {
      return error('La ruta del cliente existe pero no es una carpeta.')
    }
    return { ok: true, ruta: destino }
  } catch (err) {
    return error(mensajeDe(raiz, err))
  }
}

/**
 * Elige una carpeta local de este PC y propone la ruta de red que tendría
 * si se comparte con el mismo nombre: \\EQUIPO\NombreCarpeta.
 */
async function elegirCarpetaLocal(parentWin) {
  const { canceled, filePaths } = await dialog.showOpenDialog(parentWin ?? undefined, {
    title: 'Carpeta que se compartirá en la red',
    properties: ['openDirectory', 'createDirectory'],
  })
  if (canceled || !filePaths?.[0]) return { ok: true, cancelado: true }
  const carpetaLocal = filePaths[0]
  const nombre = path.basename(carpetaLocal)
  const equipo = String(os.hostname() || 'EQUIPO').toUpperCase().replace(/[^A-Z0-9._-]/g, '-')
  return {
    ok: true,
    carpetaLocal,
    rutaCompartida: `\\\\${equipo}\\${nombre}`,
    message: `Comparta «${nombre}» en Windows con ese nombre y permiso de escritura.`,
  }
}

module.exports = {
  probar,
  listar,
  crearCarpeta,
  subirArchivos,
  abrir,
  borrar,
  elegirCarpetaLocal,
  carpetaCliente,
}
