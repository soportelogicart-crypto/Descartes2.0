/**
 * Login sin conexión: solo usuarios que ya entraron en este puesto con
 * conexión y tienen permiso de TPV. Se guarda un hash scrypt, nunca la
 * contraseña. La sesión local dura 12 horas.
 */
const db = require('./db')
const { hashPassword, verificarPassword } = require('./cifrado')

const DURACION_SESION_MS = 12 * 60 * 60 * 1000
const DIAS_SIN_LOGIN_ONLINE = 30

let sesion = null

function tienePermisoTpv(permisos) {
  const tpv = permisos && typeof permisos === 'object' ? permisos.tpv : null
  return !!(tpv && (tpv.ver || tpv.editar))
}

/** Tras un login correcto contra el servidor. */
function recordarUsuario({ codigo, password, usuario, permisos }) {
  const c = String(codigo || '').trim().toUpperCase()
  if (!c || !password || !tienePermisoTpv(permisos)) {
    db.ejecutar('DELETE FROM usuarios WHERE codigo = ?', [c])
    db.guardar()
    return { recordado: false }
  }
  const { sal, hash } = hashPassword(password)
  db.ejecutar(
    `INSERT OR REPLACE INTO usuarios (codigo, sal, hash, usuario, permisos, ultimo_login)
     VALUES (?, ?, ?, ?, ?, ?)`,
    [c, sal, hash, JSON.stringify(usuario || {}), JSON.stringify(permisos || {}), new Date().toISOString()]
  )
  db.guardar()
  return { recordado: true }
}

/** Permisos sin conexión: TPV y consulta de los catálogos que sí están copiados. */
const CONSULTA_LOCAL = ['articulos', 'clientes', 'familias', 'macrofamilias', 'subfamilias']

function permisosOffline(permisos) {
  const tpv = (permisos && permisos.tpv) || {}
  const out = { tpv: { ver: !!tpv.ver, editar: !!tpv.editar, eliminar: !!tpv.eliminar, crear: !!tpv.crear } }
  for (const modulo of CONSULTA_LOCAL) {
    const p = permisos && permisos[modulo]
    if (p && (p.ver || p.editar)) out[modulo] = { ver: true, crear: false, editar: false, eliminar: false }
  }
  return out
}

function login(codigo, password) {
  const c = String(codigo || '').trim().toUpperCase()
  const fila = db.fila('SELECT * FROM usuarios WHERE codigo = ?', [c])
  if (!fila || !verificarPassword(password, fila.sal, fila.hash)) {
    return {
      ok: false,
      error:
        'Sin conexión solo pueden entrar los usuarios que ya iniciaron sesión en este puesto con conexión. Revise usuario y contraseña.',
    }
  }
  const dias = (Date.now() - Date.parse(fila.ultimo_login)) / 86400000
  if (!(dias <= DIAS_SIN_LOGIN_ONLINE)) {
    return {
      ok: false,
      error: `Hace más de ${DIAS_SIN_LOGIN_ONLINE} días que este usuario no entra con conexión. Conéctese para seguir.`,
    }
  }
  const permisos = JSON.parse(fila.permisos || '{}')
  sesion = {
    usuario: JSON.parse(fila.usuario || '{}'),
    permisos: permisosOffline(permisos),
    expira: Date.now() + DURACION_SESION_MS,
  }
  return { ok: true, usuario: sesion.usuario, permisos: sesion.permisos }
}

/** Sesión del renderer ya validada con el servidor antes del corte. */
function adoptar(usuario, permisos) {
  if (!usuario || !tienePermisoTpv(permisos)) return
  sesion = { usuario, permisos: permisosOffline(permisos), expira: Date.now() + DURACION_SESION_MS }
}

function actual() {
  if (sesion && sesion.expira > Date.now()) return sesion
  sesion = null
  return null
}

function cerrar() {
  sesion = null
}

module.exports = { recordarUsuario, login, adoptar, actual, cerrar }
