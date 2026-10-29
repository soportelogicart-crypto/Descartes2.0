/**
 * Ejecuta los módulos del modo sin conexión en Node, sin Electron: carpeta de
 * datos temporal, sin safeStorage y con la red sustituida por `red.responder`.
 */
const Module = require('module')
const fs = require('fs')
const os = require('os')
const path = require('path')

const userData = fs.mkdtempSync(path.join(os.tmpdir(), 'descartes-offline-'))

const electron = {
  app: {
    getPath: () => userData,
    getAppPath: () => path.join(__dirname, '..', '..'),
    getVersion: () => '0.0.0-test',
    isPackaged: false,
  },
  safeStorage: { isEncryptionAvailable: () => false },
  net: {},
  session: { defaultSession: { cookies: { set: async () => {} } } },
  ipcMain: { handle() {}, on() {} },
  BrowserWindow: { getAllWindows: () => [], fromWebContents: () => null },
  dialog: {},
  protocol: {},
}

const cargarOriginal = Module._load
Module._load = function (peticion, ...resto) {
  if (peticion === 'electron') return electron
  return cargarOriginal.call(this, peticion, ...resto)
}

/** Peticiones hechas y respuesta configurable: `(req) => ({ status, datos })` o lanza (sin red). */
const red = {
  peticiones: [],
  responder: () => ({ status: 503, datos: { error: 'sin red' } }),
}

const rutaHttp = require.resolve('../../electron/offline/http.js')
require.cache[rutaHttp] = {
  id: rutaHttp,
  filename: rutaHttp,
  loaded: true,
  exports: {
    peticionJson: async (req) => {
      red.peticiones.push(req)
      return red.responder(req)
    },
    peticion: async () => {
      throw new Error('no disponible en pruebas')
    },
    esFalloDeRed: (s) => s === 502 || s === 503 || s === 504,
  },
}

function limpiar() {
  fs.rmSync(userData, { recursive: true, force: true })
}

module.exports = { userData, red, limpiar }
