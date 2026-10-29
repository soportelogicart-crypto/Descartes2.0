/**
 * Base local SQLite (sql.js, sin binarios nativos) guardada cifrada en
 * %APPDATA%/descartes-electron/offline/datos.bin.
 */
const fs = require('fs')
const path = require('path')
const initSqlJs = require('sql.js')
const { dirOffline, escribirCifrado, leerCifrado } = require('./cifrado')

const ESQUEMA = `
CREATE TABLE IF NOT EXISTS meta (clave TEXT PRIMARY KEY, valor TEXT);
CREATE TABLE IF NOT EXISTS articulos (
  codigo TEXT PRIMARY KEY, descripcion TEXT, alternativo TEXT, familia TEXT, subfamilia TEXT,
  seccion TEXT, subseccion TEXT, macrofamilia TEXT, proveedor TEXT, iva REAL,
  p1 REAL, p2 REAL, p3 REAL, p4 REAL, p5 REAL, p6 REAL, p7 REAL, p8 REAL, p9 REAL,
  baja INTEGER, busqueda TEXT, impuesto TEXT, agrupacion TEXT, ficha TEXT
);
CREATE INDEX IF NOT EXISTS ix_articulos_alternativo ON articulos(alternativo);
CREATE TABLE IF NOT EXISTS barras (ean TEXT PRIMARY KEY, codigo TEXT, unidades REAL);
CREATE TABLE IF NOT EXISTS familias (codigo TEXT PRIMARY KEY, descripcion TEXT, macrofamilia TEXT);
CREATE TABLE IF NOT EXISTS macrofamilias (codigo TEXT PRIMARY KEY, descripcion TEXT);
CREATE TABLE IF NOT EXISTS subfamilias (familia TEXT, subfamilia TEXT, descripcion TEXT, PRIMARY KEY (familia, subfamilia));
CREATE TABLE IF NOT EXISTS impuestos (codigo TEXT PRIMARY KEY, descripcion TEXT);
CREATE TABLE IF NOT EXISTS agrupaciones (codigo TEXT PRIMARY KEY, descripcion TEXT);
CREATE TABLE IF NOT EXISTS secciones (codigo TEXT PRIMARY KEY, descripcion TEXT);
CREATE TABLE IF NOT EXISTS subsecciones (codigo TEXT PRIMARY KEY, descripcion TEXT);
CREATE TABLE IF NOT EXISTS proveedores (codigo TEXT PRIMARY KEY, nombre TEXT);
CREATE TABLE IF NOT EXISTS clientes (
  codigo TEXT PRIMARY KEY, razon_social TEXT, razon_social2 TEXT, nif TEXT, direccion TEXT,
  poblacion TEXT, codigo_postal TEXT, provincia TEXT, pais TEXT, telefono TEXT, email TEXT,
  forma_pago TEXT, tarifa INTEGER, tipo_descuento TEXT, dto1 REAL, busqueda TEXT
);
CREATE TABLE IF NOT EXISTS tipos_descuento (
  tipo_descuento TEXT, tipo TEXT, codigo TEXT, fecha_inicio TEXT, fecha_fin TEXT,
  descuento REAL, bloqueos TEXT
);
CREATE INDEX IF NOT EXISTS ix_tipos_descuento ON tipos_descuento(tipo_descuento);
CREATE TABLE IF NOT EXISTS cache (clave TEXT PRIMARY KEY, datos TEXT, guardado TEXT);
CREATE TABLE IF NOT EXISTS usuarios (
  codigo TEXT PRIMARY KEY, sal TEXT, hash TEXT, usuario TEXT, permisos TEXT, ultimo_login TEXT
);
CREATE TABLE IF NOT EXISTS borradores (clave TEXT PRIMARY KEY, datos TEXT, actualizado TEXT);
CREATE TABLE IF NOT EXISTS ventas_pendientes (
  uuid TEXT PRIMARY KEY, orden INTEGER, tipo_op TEXT, numero_offline TEXT, empresa TEXT,
  puesto TEXT, creado TEXT, payload TEXT, hmac TEXT, estado TEXT, intentos INTEGER,
  error TEXT, proximo_intento TEXT, enviado TEXT, resultado TEXT, importe REAL, venta TEXT
);
CREATE INDEX IF NOT EXISTS ix_ventas_pendientes_estado ON ventas_pendientes(estado, orden);
`

let SQL = null
let db = null
let pendienteGuardar = null
let ultimaCopia = 0

function archivoDatos() {
  return path.join(dirOffline(), 'datos.bin')
}

function dirCopias() {
  const dir = path.join(dirOffline(), 'copias')
  fs.mkdirSync(dir, { recursive: true })
  return dir
}

function cargarDesde(archivo) {
  const buf = leerCifrado(archivo)
  if (!buf) return null
  const base = new SQL.Database(new Uint8Array(buf))
  base.exec('SELECT count(*) FROM sqlite_master')
  return base
}

async function abrir() {
  if (db) return db
  SQL = await initSqlJs({
    locateFile: (f) => path.join(path.dirname(require.resolve('sql.js')), f),
  })
  try {
    db = cargarDesde(archivoDatos())
  } catch (e) {
    console.error('[offline] Base local dañada, se restaura la última copia:', e.message)
    db = null
    const copias = fs.readdirSync(dirCopias()).filter((f) => f.endsWith('.bin')).sort().reverse()
    for (const copia of copias) {
      try {
        db = cargarDesde(path.join(dirCopias(), copia))
        if (db) break
      } catch {
        // probar la anterior
      }
    }
  }
  if (!db) db = new SQL.Database()
  db.exec(ESQUEMA)
  const columnas = new Set(filas('PRAGMA table_info(articulos)').map((c) => c.name))
  if (!columnas.has('impuesto')) ejecutar('ALTER TABLE articulos ADD COLUMN impuesto TEXT')
  if (!columnas.has('agrupacion')) ejecutar('ALTER TABLE articulos ADD COLUMN agrupacion TEXT')
  if (!columnas.has('ficha')) ejecutar('ALTER TABLE articulos ADD COLUMN ficha TEXT')
  return db
}

function filas(sql, params = []) {
  const st = db.prepare(sql)
  try {
    st.bind(params)
    const out = []
    while (st.step()) out.push(st.getAsObject())
    return out
  } finally {
    st.free()
  }
}

function fila(sql, params = []) {
  return filas(sql, params)[0] ?? null
}

function ejecutar(sql, params = []) {
  db.run(sql, params)
}

function transaccion(fn) {
  db.exec('BEGIN')
  try {
    const r = fn()
    db.exec('COMMIT')
    return r
  } catch (e) {
    db.exec('ROLLBACK')
    throw e
  }
}

function escribirAhora() {
  if (!db) return
  if (pendienteGuardar) {
    clearTimeout(pendienteGuardar)
    pendienteGuardar = null
  }
  const datos = Buffer.from(db.export())
  escribirCifrado(archivoDatos(), datos)
  const ahora = Date.now()
  if (ahora - ultimaCopia > 60 * 60 * 1000) {
    ultimaCopia = ahora
    const nombre = new Date(ahora).toISOString().replace(/[:.]/g, '-')
    escribirCifrado(path.join(dirCopias(), `datos-${nombre}.bin`), datos)
    const copias = fs.readdirSync(dirCopias()).filter((f) => f.endsWith('.bin')).sort()
    for (const sobra of copias.slice(0, Math.max(0, copias.length - 24))) {
      fs.rmSync(path.join(dirCopias(), sobra), { force: true })
    }
  }
}

/** `inmediato` para ventas y cola: no se puede perder una venta cobrada. */
function guardar({ inmediato = false } = {}) {
  if (inmediato) {
    escribirAhora()
    return
  }
  if (pendienteGuardar) return
  pendienteGuardar = setTimeout(() => {
    pendienteGuardar = null
    try {
      escribirAhora()
    } catch (e) {
      console.error('[offline] No se pudo guardar la base local:', e.message)
    }
  }, 2000)
}

function meta(clave, valor) {
  if (valor === undefined) {
    const r = fila('SELECT valor FROM meta WHERE clave = ?', [clave])
    return r ? r.valor : null
  }
  ejecutar('INSERT OR REPLACE INTO meta (clave, valor) VALUES (?, ?)', [clave, valor === null ? null : String(valor)])
  return valor
}

function cerrar() {
  try {
    escribirAhora()
  } catch (e) {
    console.error('[offline] No se pudo guardar al cerrar:', e.message)
  }
}

module.exports = { abrir, filas, fila, ejecutar, transaccion, guardar, meta, cerrar }
