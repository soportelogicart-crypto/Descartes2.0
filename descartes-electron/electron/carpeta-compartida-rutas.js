/**
 * Reglas de rutas de la carpeta compartida. Sin dependencias de Electron
 * para poder probarlas con node --test.
 *
 * La raíz es siempre UNC (\\EQUIPO\Recurso). Nunca se sale de la raíz.
 */
const path = require('path')

const LONGITUD_MAX = 500
const CARACTERES_PROHIBIDOS = /[<>:"|?*\\/\x00-\x1f]/
const RAIZ_UNC = /^\\\\[\p{L}\p{N}._ -]{1,63}\\[^\\/:*?"<>|\x00-\x1f]{1,120}(\\[^\\/:*?"<>|\x00-\x1f]{1,120})*$/u

/** Devuelve la raíz normalizada o null si no es una ruta de red válida. */
function rutaRaizValida(ruta) {
  const limpia = String(ruta ?? '').trim().replace(/\\+$/, '')
  if (!limpia || limpia.length > LONGITUD_MAX) return null
  if (limpia.includes('..')) return null
  return RAIZ_UNC.test(limpia) ? limpia : null
}

/**
 * Nombre de la carpeta de un cliente. Quita los caracteres que Windows no
 * admite en un nombre. `..` o una ruta no pueden salir de `Clientes`.
 * @returns {string | null}
 */
function nombreCarpetaCliente(codigo) {
  let n = String(codigo ?? '').replace(/[<>:"|?*\\/\x00-\x1f]/g, '')
  while (n.includes('..')) n = n.replaceAll('..', '')
  n = n.trim().replace(/[. ]+$/g, '')
  return nombreValido(n) ? n : null
}

/**
 * UNC de la carpeta del cliente, o null si el código no vale como nombre.
 * Siempre cuelga de `Clientes` dentro de la raíz compartida.
 */
function rutaCarpetaCliente(raiz, codigo) {
  const base = rutaRaizValida(raiz)
  const nombre = nombreCarpetaCliente(codigo)
  if (!base || !nombre) return null
  return rutaDentro(base, 'Clientes', nombre)
}

/** Nombre de archivo o carpeta sin separadores ni caracteres prohibidos. */
function nombreValido(nombre) {
  const n = String(nombre ?? '').trim()
  if (!n || n.length > 180) return false
  if (n === '.' || n === '..') return false
  if (CARACTERES_PROHIBIDOS.test(n)) return false
  if (/[. ]$/.test(n)) return false
  return true
}

/** Partes de una ruta relativa (`a\b`, `a/b`). Lanza si alguna parte no es válida. */
function partesRelativas(relativa) {
  const partes = String(relativa ?? '')
    .split(/[\\/]+/)
    .map((p) => p.trim())
    .filter((p) => p !== '')
  for (const p of partes) {
    if (!nombreValido(p)) throw new Error('Ruta de carpeta no válida')
  }
  return partes
}

/**
 * Ruta absoluta dentro de la raíz, o null si la relativa intenta salir.
 * @param {string} raiz ya validada con rutaRaizValida
 * @param {string} relativa ruta dentro de la carpeta compartida
 * @param {string} [nombre] elemento dentro de esa ruta
 */
function rutaDentro(raiz, relativa = '', nombre = '') {
  let partes
  try {
    partes = partesRelativas(relativa)
    if (nombre !== '') {
      if (!nombreValido(nombre)) return null
      partes = [...partes, String(nombre).trim()]
    }
  } catch {
    return null
  }
  if (partes.length === 0) return raiz
  const completa = path.win32.join(raiz, ...partes)
  const prefijo = raiz.endsWith('\\') ? raiz : raiz + '\\'
  if (completa !== raiz && !completa.startsWith(prefijo)) return null
  return completa
}

/**
 * Texto para el usuario cuando el acceso a la ruta UNC falla.
 * Node en Windows devuelve UNKNOWN si el recurso compartido no existe.
 * @param {{ mismoEquipo?: boolean, recursoExiste?: boolean }} [opciones]
 * @returns {string | null} null si el código no se reconoce
 */
function explicarAcceso(raiz, codigo, opciones = {}) {
  const ruta = String(raiz || '')
  const recurso = (ruta.match(/^\\\\[^\\]+\\([^\\]+)/) || [])[1] || ''
  switch (codigo) {
    case 'ENOENT':
      return 'No se encuentra la carpeta. Compruebe que el PC anfitrión está encendido y que la carpeta está compartida.'
    case 'EACCES':
    case 'EPERM':
      return 'Sin permiso para esta carpeta. Revise el uso compartido y los permisos de escritura en el anfitrión.'
    case 'EEXIST':
      return 'Ya existe un elemento con ese nombre.'
    case 'ENOTEMPTY':
      return 'La carpeta no está vacía. Bórrela desde el Explorador de Windows o vacíela antes.'
    case 'ENOTDIR':
      return 'La ruta existe pero no es una carpeta.'
    case 'UNKNOWN':
      if (opciones.mismoEquipo && opciones.recursoExiste === false && recurso) {
        return (
          `En este PC no hay un recurso compartido llamado «${recurso}». ` +
          'Hay que compartirlo una vez en Windows: clic derecho en la carpeta, Propiedades, ' +
          `Compartir, Uso compartido avanzado, con el nombre «${recurso}» y permiso de cambio. ` +
          'Hasta entonces los otros ordenadores no pueden entrar.'
        )
      }
      return (
        `No se llega a ${ruta}. Compruebe que ese PC está encendido, que la carpeta está ` +
        'compartida con ese nombre y que este equipo tiene permiso de escritura.'
      )
    default:
      return null
  }
}

module.exports = {
  rutaRaizValida,
  nombreValido,
  nombreCarpetaCliente,
  rutaCarpetaCliente,
  rutaDentro,
  explicarAcceso,
}
