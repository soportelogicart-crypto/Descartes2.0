/**
 * Cifrado de los datos locales: AES-256-GCM con una clave aleatoria guardada con
 * safeStorage (DPAPI en Windows, ligada al usuario de Windows). Copiar los archivos
 * a otro PC no permite abrirlos.
 */
const { app, safeStorage } = require('electron')
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')

const MAGIA = Buffer.from('DSC1')
let clave = null
let protegida = false

function dirOffline() {
  const dir = path.join(app.getPath('userData'), 'offline')
  fs.mkdirSync(dir, { recursive: true })
  return dir
}

function cargarClave() {
  if (clave) return clave
  const archivo = path.join(dirOffline(), 'clave.bin')
  const disponible = safeStorage.isEncryptionAvailable()
  if (fs.existsSync(archivo)) {
    const raw = fs.readFileSync(archivo)
    if (raw.subarray(0, 4).toString() === 'SAFE') {
      clave = Buffer.from(safeStorage.decryptString(raw.subarray(4)), 'hex')
      protegida = true
    } else {
      clave = Buffer.from(raw.subarray(4).toString(), 'hex')
      protegida = false
    }
    return clave
  }
  clave = crypto.randomBytes(32)
  if (disponible) {
    fs.writeFileSync(archivo, Buffer.concat([Buffer.from('SAFE'), safeStorage.encryptString(clave.toString('hex'))]))
    protegida = true
  } else {
    // Sin DPAPI (no debería pasar en Windows): se avisa en el estado.
    fs.writeFileSync(archivo, Buffer.concat([Buffer.from('RAW_'), Buffer.from(clave.toString('hex'))]))
    protegida = false
  }
  return clave
}

function cifrar(datos) {
  const k = cargarClave()
  const iv = crypto.randomBytes(12)
  const cipher = crypto.createCipheriv('aes-256-gcm', k, iv)
  const cuerpo = Buffer.concat([cipher.update(datos), cipher.final()])
  return Buffer.concat([MAGIA, iv, cipher.getAuthTag(), cuerpo])
}

function descifrar(buffer) {
  if (buffer.length < 32 || !buffer.subarray(0, 4).equals(MAGIA)) {
    throw new Error('Archivo local no reconocido')
  }
  const k = cargarClave()
  const iv = buffer.subarray(4, 16)
  const tag = buffer.subarray(16, 32)
  const decipher = crypto.createDecipheriv('aes-256-gcm', k, iv)
  decipher.setAuthTag(tag)
  return Buffer.concat([decipher.update(buffer.subarray(32)), decipher.final()])
}

/** Escritura atómica: si se corta la luz a mitad queda el archivo anterior. */
function escribirCifrado(archivo, datos) {
  const tmp = `${archivo}.tmp`
  const fd = fs.openSync(tmp, 'w')
  try {
    fs.writeSync(fd, cifrar(datos))
    fs.fsyncSync(fd)
  } finally {
    fs.closeSync(fd)
  }
  fs.renameSync(tmp, archivo)
}

function leerCifrado(archivo) {
  if (!fs.existsSync(archivo)) return null
  return descifrar(fs.readFileSync(archivo))
}

/** Hash de contraseña para el login sin conexión (scrypt). */
function hashPassword(password, sal = crypto.randomBytes(16).toString('hex')) {
  const hash = crypto.scryptSync(String(password), sal, 32, { N: 16384, r: 8, p: 1 }).toString('hex')
  return { sal, hash }
}

function verificarPassword(password, sal, hash) {
  const calculado = hashPassword(password, sal).hash
  const a = Buffer.from(calculado, 'hex')
  const b = Buffer.from(String(hash || ''), 'hex')
  return a.length === b.length && crypto.timingSafeEqual(a, b)
}

function hmac(claveHex, texto) {
  return crypto.createHmac('sha256', Buffer.from(String(claveHex), 'hex')).update(texto, 'utf8').digest('hex')
}

module.exports = {
  dirOffline,
  cifrar,
  descifrar,
  escribirCifrado,
  leerCifrado,
  hashPassword,
  verificarPassword,
  hmac,
  claveProtegida: () => {
    cargarClave()
    return protegida
  },
}
