/**
 * Foto de datos del servidor (GET /api/offline/snapshot y /cambios) cargada en
 * la base local. Cada bloque se sustituye entero dentro de una transacción: si
 * algo falla se queda la foto anterior.
 */
const db = require('./db')
const { peticionJson } = require('./http')
const { normalizar, claveCache, leerJson } = require('./calculo')

const VERSION_DATOS = 3

function cargarBloque(nombre, datos) {
  switch (nombre) {
    case 'empresa':
      db.meta('empresa', JSON.stringify(datos))
      return
    case 'articulos':
      db.ejecutar('DELETE FROM articulos')
      for (const a of datos) {
        const [codigo, descripcion, alternativo, familia, subfamilia, seccion, subseccion, macro, proveedor, iva, precios, impuesto, agrupacion, ficha] = a
        const p = Array.isArray(precios) ? precios : []
        db.ejecutar(
          `INSERT OR REPLACE INTO articulos (codigo, descripcion, alternativo, familia, subfamilia, seccion,
            subseccion, macrofamilia, proveedor, iva, p1, p2, p3, p4, p5, p6, p7, p8, p9, baja, busqueda,
            impuesto, agrupacion, ficha)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?)`,
          [
            codigo, descripcion, alternativo, familia, subfamilia, seccion, subseccion, macro, proveedor, iva,
            p[0] ?? 0, p[1] ?? 0, p[2] ?? 0, p[3] ?? 0, p[4] ?? 0, p[5] ?? 0, p[6] ?? 0, p[7] ?? 0, p[8] ?? 0,
            normalizar(`${codigo} ${descripcion} ${alternativo}`),
            impuesto || '', agrupacion || '',
            ficha && typeof ficha === 'object' ? JSON.stringify(ficha) : null,
          ]
        )
      }
      return
    case 'barras':
      db.ejecutar('DELETE FROM barras')
      for (const [ean, codigo, unidades] of datos) {
        db.ejecutar('INSERT OR REPLACE INTO barras (ean, codigo, unidades) VALUES (?, ?, ?)', [ean, codigo, unidades])
      }
      return
    case 'familias':
      db.ejecutar('DELETE FROM familias')
      for (const [codigo, descripcion, macro] of datos) {
        db.ejecutar('INSERT OR REPLACE INTO familias (codigo, descripcion, macrofamilia) VALUES (?, ?, ?)', [codigo, descripcion, macro])
      }
      return
    case 'macrofamilias':
      db.ejecutar('DELETE FROM macrofamilias')
      for (const [codigo, descripcion] of datos) {
        db.ejecutar('INSERT OR REPLACE INTO macrofamilias (codigo, descripcion) VALUES (?, ?)', [codigo, descripcion])
      }
      return
    case 'subfamilias':
      db.ejecutar('DELETE FROM subfamilias')
      for (const [familia, subfamilia, descripcion] of datos) {
        db.ejecutar('INSERT OR REPLACE INTO subfamilias (familia, subfamilia, descripcion) VALUES (?, ?, ?)', [familia, subfamilia, descripcion])
      }
      return
    case 'catalogos':
      for (const tabla of ['impuestos', 'agrupaciones', 'secciones', 'subsecciones']) {
        db.ejecutar(`DELETE FROM ${tabla}`)
        for (const [codigo, descripcion] of datos[tabla] || []) {
          db.ejecutar(`INSERT INTO ${tabla} (codigo, descripcion) VALUES (?, ?)`, [codigo, descripcion || ''])
        }
      }
      db.ejecutar('DELETE FROM proveedores')
      for (const [codigo, nombre] of datos.proveedores || []) {
        db.ejecutar('INSERT INTO proveedores (codigo, nombre) VALUES (?, ?)', [codigo, nombre || ''])
      }
      return
    case 'clientes':
      db.ejecutar('DELETE FROM clientes')
      for (const c of datos) {
        const [codigo, razon, razon2, nif, direccion, poblacion, cp, provincia, pais, telefono, email, fpago, tarifa, tipoDto, dto1] = c
        db.ejecutar(
          `INSERT OR REPLACE INTO clientes (codigo, razon_social, razon_social2, nif, direccion, poblacion,
            codigo_postal, provincia, pais, telefono, email, forma_pago, tarifa, tipo_descuento, dto1, busqueda)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
          [
            codigo, razon, razon2, nif, direccion, poblacion, cp, provincia, pais, telefono, email, fpago, tarifa,
            tipoDto, dto1, normalizar(`${codigo} ${razon} ${razon2} ${nif} ${telefono}`),
          ]
        )
      }
      return
    case 'tiposDescuento':
      db.ejecutar('DELETE FROM tipos_descuento')
      for (const [tipoDescuento, tipo, codigo, inicio, fin, descuento, bloqueos] of datos) {
        db.ejecutar(
          `INSERT INTO tipos_descuento (tipo_descuento, tipo, codigo, fecha_inicio, fecha_fin, descuento, bloqueos)
           VALUES (?, ?, ?, ?, ?, ?, ?)`,
          [tipoDescuento, tipo, codigo, inicio, fin, descuento, JSON.stringify(bloqueos || [])]
        )
      }
      return
    case 'cache': {
      const ahora = new Date().toISOString()
      for (const item of datos) {
        let cuerpo = item.datos
        if (typeof cuerpo === 'string') {
          const leido = leerJson(cuerpo)
          if (leido && typeof leido === 'object') cuerpo = leido
        }
        db.ejecutar('INSERT OR REPLACE INTO cache (clave, datos, guardado) VALUES (?, ?, ?)', [
          claveCache(item.path, item.params || {}),
          JSON.stringify(cuerpo),
          ahora,
        ])
      }
      return
    }
    default:
  }
}

/**
 * @param {{ apiBase: string, equipo: { empresaCodigo: string, puestoCodigo: string }, vinculo: object, completa?: boolean }} o
 * @returns {Promise<{ ok: boolean, status?: number, motivo?: string, cambiados?: string[] }>}
 */
async function refrescar({ apiBase, equipo, vinculo, completa = false }) {
  const empresa = String(equipo?.empresaCodigo || '').trim()
  const puesto = String(equipo?.puestoCodigo || '').trim()
  if (!empresa || !puesto) {
    return { ok: false, motivo: 'Equipo sin tienda ni puesto configurados' }
  }
  const mismoPuesto = db.meta('fotoEmpresa') === empresa && db.meta('fotoPuesto') === puesto
  const mismaVersion = Number(db.meta('versionDatos')) === VERSION_DATOS
  let hashes = {}
  if (!completa && mismoPuesto && mismaVersion) {
    try {
      hashes = JSON.parse(db.meta('hashes') || '{}')
    } catch {
      hashes = {}
    }
  }
  const query = new URLSearchParams({
    empresa,
    puesto,
    hashes: Object.entries(hashes)
      .map(([k, v]) => `${k}:${v}`)
      .join(','),
  })
  const ruta = Object.keys(hashes).length ? 'cambios' : 'snapshot'
  const res = await peticionJson({
    url: `${apiBase}/api/offline/${ruta}?${query}`,
    vinculo,
    timeoutMs: 120000,
  })
  if (res.status !== 200 || !res.datos?.bloques) {
    return { ok: false, status: res.status, motivo: res.datos?.error || `HTTP ${res.status}` }
  }

  const cambiados = []
  db.transaccion(() => {
    if (!mismoPuesto) {
      // Otra tienda o puesto: la caché de respuestas ya no vale.
      db.ejecutar('DELETE FROM cache')
    }
    const nuevosHashes = { ...hashes }
    for (const [nombre, bloque] of Object.entries(res.datos.bloques)) {
      nuevosHashes[nombre] = bloque.hash
      if (bloque.sinCambios || !('datos' in bloque)) continue
      cargarBloque(nombre, bloque.datos)
      cambiados.push(nombre)
    }
    db.meta('hashes', JSON.stringify(nuevosHashes))
    db.meta('fotoEmpresa', empresa)
    db.meta('fotoPuesto', puesto)
    db.meta('versionDatos', String(VERSION_DATOS))
    db.meta('ultimaFoto', new Date().toISOString())
    if (res.datos.claveOffline) db.meta('claveOffline', res.datos.claveOffline)
  })
  db.guardar()
  return { ok: true, cambiados }
}

function empresaFoto() {
  try {
    return JSON.parse(db.meta('empresa') || 'null')
  } catch {
    return null
  }
}

module.exports = { refrescar, empresaFoto }
