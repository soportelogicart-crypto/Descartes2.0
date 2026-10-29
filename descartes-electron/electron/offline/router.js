/**
 * "Servidor local": responde sin conexión a las mismas URLs que usa la UI.
 * Solo cubre consultar clientes y artículos y vender en el TPV; lo demás
 * contesta 503 OFFLINE ("No disponible sin conexión").
 */
const db = require('./db')
const sesionLocal = require('./sesion-local')
const ventas = require('./ventas-local')
const { CLIENTE_RAPIDO, normalizar, descuentoLinea, claveCache, leerJson } = require('./calculo')

const NO_DISPONIBLE = 'No disponible sin conexión. Sin conexión solo se pueden consultar clientes y artículos y vender en el TPV.'

function ok(data, status = 200) {
  return { status, data }
}

function error(status, mensaje, codigo = 'OFFLINE') {
  return { status, data: { error: mensaje, codigo } }
}

function tarifaValida(t) {
  const n = Number(t) || 0
  return Math.min(9, Math.max(1, n > 0 ? n : 1))
}

function articuloPrecio(fila, tarifa) {
  return {
    codigo: fila.codigo,
    descripcion: fila.descripcion,
    precio: Number(fila[`p${tarifaValida(tarifa)}`]) || 0,
    iva: Number(fila.iva) > 0 ? Number(fila.iva) : 21,
    bloqueado: false,
  }
}

function resolverArticulo(q, tarifa) {
  const texto = String(q ?? '').trim()
  if (!texto) return error(400, 'Código o EAN obligatorio', 'VALIDACION')
  let fila = db.fila('SELECT * FROM articulos WHERE codigo = ?', [texto])
  let matchPor = 'codigo'
  let unidades = 1
  if (!fila) {
    fila = db.fila("SELECT * FROM articulos WHERE alternativo = ? AND alternativo <> ''", [texto])
    matchPor = 'alternativo'
  }
  if (!fila) {
    let ean = texto.replace(/^(\d+)\.0+$/, '$1')
    if (/^\d{4,18}$/.test(ean)) {
      ean = ean.replace(/^0+/, '')
      const barra = db.fila('SELECT * FROM barras WHERE ean = ?', [ean])
      if (barra) {
        fila = db.fila('SELECT * FROM articulos WHERE codigo = ?', [barra.codigo])
        matchPor = 'ean'
        unidades = Number(barra.unidades) > 0 ? Number(barra.unidades) : 1
      }
    }
  }
  if (!fila) return error(404, 'Artículo no encontrado', 'NO_ENCONTRADO')
  return ok({ ...articuloPrecio(fila, tarifa), matchPor, unidadesPaquete: unidades })
}

function buscarArticulos(q, tarifa, limite, ambito) {
  const texto = String(q ?? '').trim()
  if (!texto) return error(400, 'Texto de búsqueda obligatorio', 'VALIDACION')
  const amb = String(ambito || 'todos').toLowerCase()
  const n = Math.min(100, Math.max(1, Number(limite) || 30))
  const norm = `%${normalizar(texto)}%`
  const like = `%${texto}%`
  const conds = []
  const params = []
  if (amb === 'todos' || amb === 'articulo') {
    conds.push('a.codigo = ?', 'a.codigo LIKE ?', 'a.busqueda LIKE ?')
    params.push(texto, `${texto}%`, norm)
  }
  if (amb === 'todos' || amb === 'macrofamilia') {
    conds.push('m.codigo LIKE ?', 'm.descripcion LIKE ?')
    params.push(`${texto}%`, like)
  }
  if (amb === 'todos' || amb === 'familia') {
    conds.push('f.codigo LIKE ?', 'f.descripcion LIKE ?')
    params.push(`${texto}%`, like)
  }
  if (amb === 'todos' || amb === 'subfamilia') {
    conds.push('s.subfamilia LIKE ?', 's.descripcion LIKE ?')
    params.push(`${texto}%`, like)
  }
  if (conds.length === 0) return ok({ items: [] })
  const filas = db.filas(
    `SELECT a.*, f.descripcion AS familia_desc, m.descripcion AS macro_desc, s.descripcion AS sub_desc
     FROM articulos a
     LEFT JOIN familias f ON f.codigo = a.familia
     LEFT JOIN macrofamilias m ON m.codigo = a.macrofamilia
     LEFT JOIN subfamilias s ON s.familia = a.familia AND s.subfamilia = a.subfamilia
     WHERE ${conds.join(' OR ')}
     ORDER BY CASE WHEN a.codigo = ? THEN 0 ELSE 1 END, a.descripcion
     LIMIT ${n}`,
    [...params, texto]
  )
  return ok({
    items: filas.map((f) => ({
      ...articuloPrecio(f, tarifa),
      familiaCodigo: f.familia || '',
      familiaDescripcion: f.familia_desc || '',
      subfamiliaCodigo: f.subfamilia || '',
      subfamiliaDescripcion: f.sub_desc || '',
      macroFamiliaCodigo: f.macrofamilia || '',
      macroFamiliaDescripcion: f.macro_desc || '',
      agrupacionCodigo: '',
      agrupacionDescripcion: '',
    })),
  })
}

function precioArticulo(codigo, tarifa) {
  const fila = db.fila('SELECT * FROM articulos WHERE codigo = ?', [String(codigo ?? '').trim()])
  if (!fila) return error(404, 'Artículo no encontrado', 'NO_ENCONTRADO')
  return ok(articuloPrecio(fila, tarifa))
}

function clienteTpv(c) {
  return {
    codigo: c.codigo,
    razonSocial: c.razon_social || '',
    razonSocial2: c.razon_social2 || '',
    nif: c.nif || '',
    direccion: c.direccion || '',
    poblacion: c.poblacion || '',
    codigoPostal: c.codigo_postal || '',
    provincia: c.provincia || '',
    pais: c.pais || '',
    telefono: c.telefono || '',
    email: c.email || '',
    formaPago: c.forma_pago || '',
    tarifa: Number(c.tarifa) || 0,
  }
}

function pagina(query) {
  return Math.min(500, Math.max(1, Math.floor(Number(query.pageSize) || 200)))
}

function filtro(query) {
  const q = String(query.q ?? '').trim()
  return q ? `%${normalizar(q)}%` : ''
}

function mantenimientoLocal(path, query) {
  try {
    return mantenimientoLocalInterno(path, query)
  } catch {
    return error(503, 'No se ha podido leer la copia local.')
  }
}

function mantenimientoLocalInterno(path, query) {
  const articulo = path.match(/^\/api\/mantenimiento\/articulos\/([^/]+)$/)
  if (articulo) return fichaArticulo(decodeURIComponent(articulo[1]))
  const cliente = path.match(/^\/api\/mantenimiento\/clientes\/([^/]+)$/)
  if (cliente && !['check-nif', 'siguiente-codigo'].includes(cliente[1])) {
    return fichaCliente(decodeURIComponent(cliente[1]))
  }
  const catalogo = path.match(/^\/api\/mantenimiento\/(familias|macrofamilias|impuestos|agrupaciones|secciones|subsecciones)\/([^/]+)$/)
  const proveedor = path.match(/^\/api\/mantenimiento\/proveedores\/([^/]+)$/)
  if (proveedor && proveedor[1] !== 'siguiente-codigo') return fichaProveedor(decodeURIComponent(proveedor[1]))
  if (catalogo) return fichaCatalogo(catalogo[1], decodeURIComponent(catalogo[2]))
  const subfamilia = path.match(/^\/api\/mantenimiento\/subfamilias\/([^/]+)$/)
  if (subfamilia) return fichaSubfamilia(decodeURIComponent(subfamilia[1]))
  const formaPago = path.match(/^\/api\/mantenimiento\/formas-pago\/([^/]+)$/)
  if (formaPago) return fichaFormaPago(decodeURIComponent(formaPago[1]))
  if (path === '/api/mantenimiento/articulos') return listarArticulos(query)
  if (path === '/api/mantenimiento/clientes') return listarClientes(query)
  if (path === '/api/mantenimiento/familias') return listarSimple('familias', query)
  if (path === '/api/mantenimiento/macrofamilias') return listarSimple('macrofamilias', query)
  if (path === '/api/mantenimiento/subfamilias') return listarSimple('subfamilias', query)
  if (path === '/api/mantenimiento/impuestos') return listarSimple('impuestos', query)
  if (path === '/api/mantenimiento/agrupaciones') return listarSimple('agrupaciones', query)
  if (path === '/api/mantenimiento/secciones') return listarSimple('secciones', query)
  if (path === '/api/mantenimiento/subsecciones') return listarSimple('subsecciones', query)
  if (path === '/api/mantenimiento/proveedores') return listarProveedores(query)
  if (path === '/api/mantenimiento/formas-pago') {
    const formas = listaFormasPagoCache()
    if (formas) return formas
  }
  return null
}

function listarArticulos(query) {
  const like = filtro(query)
  const limite = pagina(query)
  const where = like ? 'WHERE busqueda LIKE ?' : ''
  const params = like ? [like] : []
  const total = Number(db.fila(`SELECT COUNT(*) AS n FROM articulos ${where}`, params)?.n ?? 0)
  const rows = db.filas(
    `SELECT codigo, descripcion, familia, proveedor, p1, baja FROM articulos ${where} ORDER BY descripcion LIMIT ${limite}`,
    params,
  )
  return ok({
    items: rows.map((f) => ({
      codigo: f.codigo,
      descripcion: f.descripcion,
      familia: f.familia || '',
      impuestoCodigo: '',
      proveedorHabitual: f.proveedor || '',
      precioVen1: Number(f.p1) || 0,
      precioVenta: Number(f.p1) || 0,
      activo: !f.baja,
    })),
    total,
  })
}

function fichaArticulo(codigo) {
  const f = db.fila('SELECT * FROM articulos WHERE codigo = ?', [codigo])
  if (!f) return error(404, 'Artículo no encontrado.', 'NO_ENCONTRADO')
  const guardada = f.ficha ? leerJson(f.ficha) : null
  if (guardada && typeof guardada === 'object') {
    guardada.codigo = f.codigo
    for (const campo of ['fechaAlta', 'fechaUltCompra', 'fechaBaja']) {
      if (typeof guardada[campo] === 'string' && guardada[campo].length >= 10) {
        guardada[campo] = guardada[campo].slice(0, 10)
      }
    }
    return ok(guardada)
  }
  const ficha = {
    codigo: f.codigo,
    descripcion: f.descripcion || '',
    alternativo: f.alternativo || '',
    macroFamilia: f.macrofamilia || '',
    familia: f.familia || '',
    subfamilia: f.subfamilia || '',
    seccion: f.seccion || '',
    subSeccion: f.subseccion || '',
    proveedorHabitual: f.proveedor || '',
    impuestoCodigo: f.impuesto || '',
    agrupacion: f.agrupacion || '',
    precioVenta: Number(f.p1) || 0,
    precioVen1: Number(f.p1) || 0,
    precioVen2: Number(f.p2) || 0,
    precioVen3: Number(f.p3) || 0,
    precioVen4: Number(f.p4) || 0,
    precioVen5: Number(f.p5) || 0,
    precioVen6: Number(f.p6) || 0,
    precioVen7: Number(f.p7) || 0,
    precioVen8: Number(f.p8) || 0,
    precioVen9: Number(f.p9) || 0,
    activo: !f.baja,
  }
  return ok(ficha)
}

function listarClientes(query) {
  const like = filtro(query)
  const limite = pagina(query)
  const where = like ? 'WHERE busqueda LIKE ?' : ''
  const params = like ? [like] : []
  const total = Number(db.fila(`SELECT COUNT(*) AS n FROM clientes ${where}`, params)?.n ?? 0)
  const rows = db.filas(
    `SELECT codigo, razon_social, nif, telefono FROM clientes ${where} ORDER BY razon_social LIMIT ${limite}`,
    params,
  )
  return ok({
    items: rows.map((f) => ({
      codigo: f.codigo,
      nombre: f.razon_social || '',
      nif: f.nif || '',
      telefono1: f.telefono || '',
      activo: true,
    })),
    total,
  })
}

function fichaCliente(codigo) {
  const f = db.fila('SELECT * FROM clientes WHERE codigo = ?', [codigo])
  if (!f) return error(404, 'Cliente no encontrado.', 'NO_ENCONTRADO')
  const ficha = {
    codigo: f.codigo,
    nombre: f.razon_social || '',
    razonSocial2: f.razon_social2 || '',
    nif: f.nif || '',
    direccion: f.direccion || '',
    poblacion: f.poblacion || '',
    provincia: f.provincia || '',
    pais: f.pais || '',
    codigoPostal: f.codigo_postal || '',
    telefono1: f.telefono || '',
    email: f.email || '',
    formaPago: f.forma_pago || '',
    tarifa: Number(f.tarifa) || 0,
    tipoDescuento: f.tipo_descuento || '',
    dto1: Number(f.dto1) || 0,
    activo: true,
  }
  return ok(ficha)
}

function fichaProveedor(codigo) {
  const f = db.fila('SELECT codigo, nombre FROM proveedores WHERE codigo = ?', [codigo])
  if (!f) return error(404, 'No encontrado.', 'NO_ENCONTRADO')
  return ok({ codigo: f.codigo, nombre: f.nombre || '', activo: true })
}

function listarProveedores(query) {
  const like = filtro(query)
  const params = like ? [like, like] : []
  const where = like ? 'WHERE codigo LIKE ? OR nombre LIKE ?' : ''
  const rows = db.filas(`SELECT codigo, nombre FROM proveedores ${where} ORDER BY nombre`, params)
  return ok({
    items: rows.map((f) => ({ codigo: f.codigo, nombre: f.nombre || '', activo: true })),
    total: rows.length,
  })
}

function listaFormasPagoCache() {
  const filas = db.filas("SELECT datos FROM cache WHERE clave LIKE '%/api/mantenimiento/formas-pago?%'")
  for (const fila of filas) {
    const datos = leerJson(fila.datos)
    const items = Array.isArray(datos?.items) ? datos.items : []
    if (items.length) {
      return ok({ items, total: datos.total ?? items.length })
    }
  }
  return null
}

function fichaCatalogo(tabla, codigo) {
  const f = db.fila(`SELECT codigo, descripcion FROM ${tabla} WHERE codigo = ?`, [codigo])
  if (!f) return error(404, 'No encontrado.', 'NO_ENCONTRADO')
  return ok({ codigo: f.codigo, descripcion: f.descripcion || '', activo: true })
}

function fichaSubfamilia(codigo) {
  const f = db.fila(
    'SELECT subfamilia AS codigo, descripcion, familia FROM subfamilias WHERE subfamilia = ?',
    [codigo],
  )
  if (!f) return error(404, 'No encontrado.', 'NO_ENCONTRADO')
  return ok({ codigo: f.codigo, descripcion: f.descripcion || '', familia: f.familia || '', activo: true })
}

function fichaFormaPago(codigo) {
  const filas = db.filas("SELECT datos FROM cache WHERE clave LIKE '%formas-pago%'")
  for (const fila of filas) {
    const datos = leerJson(fila.datos)
    const items = Array.isArray(datos?.items) ? datos.items : []
    const f = items.find((i) => String(i.codigo ?? '').trim() === codigo)
    if (f) return ok({ codigo: f.codigo, descripcion: f.descripcion || '' })
  }
  return error(404, 'No encontrado.', 'NO_ENCONTRADO')
}

function listarSimple(tabla, query) {
  const like = filtro(query)
  const params = like ? [like, like] : []
  const where = like ? 'WHERE codigo LIKE ? OR descripcion LIKE ?' : ''
  const sql = tabla === 'subfamilias'
    ? `SELECT subfamilia AS codigo, descripcion, familia FROM subfamilias ${like ? 'WHERE subfamilia LIKE ? OR descripcion LIKE ?' : ''} ORDER BY descripcion`
    : `SELECT codigo, descripcion FROM ${tabla} ${where} ORDER BY descripcion`
  const rows = db.filas(sql, params)
  return ok({
    items: rows.map((f) => ({ codigo: f.codigo, descripcion: f.descripcion || '', activo: true })),
    total: rows.length,
  })
}

function buscarClientes(q, limite) {
  const texto = String(q ?? '').trim()
  if (!texto) return error(400, 'Indique código, NIF, nombre o teléfono', 'VALIDACION')
  const n = Math.min(100, Math.max(1, Number(limite) || 30))
  const filas = db.filas(
    `SELECT * FROM clientes WHERE codigo = ? OR busqueda LIKE ?
     ORDER BY CASE WHEN codigo = ? THEN 0 ELSE 1 END, razon_social LIMIT ${n}`,
    [texto, `%${normalizar(texto)}%`, texto]
  )
  return ok({ items: filas.map(clienteTpv) })
}

function hoy() {
  const d = new Date()
  const p = (x) => String(x).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

function descuentoOferta(query) {
  const cliente = String(query.cliente ?? '').trim()
  const empresa = String(query.empresa ?? '').trim()
  const fecha = String(query.fecha ?? '').slice(0, 10) || hoy()
  const codigos = [
    ...new Set(
      String(query.articulos ?? '')
        .split(',')
        .map((c) => c.trim())
        .filter((c) => c && c.toUpperCase() !== 'NO')
    ),
  ]
  const ceros = codigos.map((articulo) => ({ articulo, pjeDto: 0, regalo: null }))
  if (!cliente || cliente.toUpperCase() === CLIENTE_RAPIDO) return ok({ items: ceros })
  const cli = db.fila('SELECT tipo_descuento FROM clientes WHERE codigo = ?', [cliente])
  const tipo = String(cli?.tipo_descuento ?? '').trim()
  if (!tipo) return ok({ items: ceros })
  const ofertas = db.filas('SELECT * FROM tipos_descuento WHERE tipo_descuento = ?', [tipo])
  const items = codigos.map((articulo) => {
    const a = db.fila('SELECT * FROM articulos WHERE codigo = ?', [articulo])
    const clase = a
      ? {
          codigo: a.codigo,
          familia: a.familia,
          subfamilia: a.subfamilia,
          seccion: a.seccion,
          subseccion: a.subseccion,
          macrofamilia: a.macrofamilia,
          proveedor: a.proveedor,
        }
      : null
    // Los artículos regalo dependen del stock del servidor: sin conexión no se dan.
    return { articulo, pjeDto: descuentoLinea({ ofertas, clase, cliente, empresa, fecha }), regalo: null }
  })
  return ok({ items })
}

function leerCache(url, params) {
  const r = db.fila('SELECT datos FROM cache WHERE clave = ?', [claveCache(url, params)])
  if (!r) return null
  const datos = leerJson(r.datos)
  return datos && typeof datos === 'object' ? datos : null
}

function authMe() {
  const s = sesionLocal.actual()
  if (s) return ok({ usuario: s.usuario, permisos: s.permisos, offline: true })
  return error(401, 'Sesión no iniciada', 'NO_AUTENTICADO')
}

const RE_VENTA = /^\/api\/(?:ventas\/albaranes|tpv\/ventas)\/([^/]+)\/([^/]+)\/(-?\d+)(\/[a-z-]+)?$/

/**
 * @param {{ method: string, url: string, params?: object, data?: any }} req
 * @returns {{ status: number, data: any }}
 */
function manejar(req) {
  const method = String(req.method || 'get').toUpperCase()
  const u = new URL(String(req.url || ''), 'http://local')
  const path = decodeURIComponent(u.pathname).replace(/\/+$/, '')
  const query = { ...Object.fromEntries(u.searchParams.entries()), ...(req.params || {}) }
  let body = req.data
  if (typeof body === 'string') {
    try {
      body = JSON.parse(body)
    } catch {
      body = {}
    }
  }
  body = body && typeof body === 'object' ? body : {}

  try {
    if (path === '/api/instalacion/estado' && method === 'GET') {
      return ok({
        conexionOk: true,
        errorConexion: null,
        migraciones: { aplicadas: [], pendientes: [], total: 0 },
        adminListo: true,
        requiereAccion: false,
        offline: true,
        acceso: { usuario: '', password: '' },
      })
    }
    if (path.startsWith('/api/instalacion/')) {
      return error(503, 'La configuración de la instalación no está disponible sin conexión.', 'OFFLINE')
    }
    if (path === '/api/auth/me' && method === 'GET') return authMe()
    if (path === '/api/auth/login' && method === 'POST') {
      const r = sesionLocal.login(body.usuario, body.password)
      return r.ok ? ok({ usuario: r.usuario, permisos: r.permisos, offline: true }) : error(401, r.error, 'CREDENCIALES')
    }
    if (path === '/api/auth/logout' && method === 'POST') {
      sesionLocal.cerrar()
      return ok({ ok: true })
    }
    if (path.startsWith('/api/') && path !== '/api/auth/me' && !sesionLocal.actual() && !path.startsWith('/api/instalacion')) {
      // Sin sesión local solo se puede entrar (login).
      const me = authMe()
      if (me.status !== 200) return me
    }
    if ((path === '/api/tpv/ping' || path === '/api/offline/estado') && method === 'GET') {
      return ok({ ok: true, modulo: 'tpv', local: true })
    }
    if (path === '/api/logs') return ok({ ok: true })
    if (path === '/api/tpv/articulos/resolver' && method === 'GET') return resolverArticulo(query.q, query.tarifa)
    if (path === '/api/tpv/articulos' && method === 'GET') {
      return buscarArticulos(query.q, query.tarifa, query.limite, query.ambito)
    }
    const precio = path.match(/^\/api\/tpv\/articulos\/(.+)\/precio$/)
    if (precio && method === 'GET') return precioArticulo(precio[1], query.tarifa)
    if (path === '/api/tpv/clientes' && method === 'GET') return buscarClientes(query.q, query.limite)
    if (method === 'GET' && path.startsWith('/api/mantenimiento/')) {
      const local = mantenimientoLocal(path, query)
      if (local) return local
    }
    if (path === '/api/ventas/descuento-oferta' && method === 'GET') return descuentoOferta(query)
    if (path === '/api/tpv/tickets-en-espera' && method === 'GET') return ok({ items: [] })
    if (path === '/api/tpv/ventas' && method === 'GET') return ok({ items: [], total: 0 })
    if (path === '/api/ventas/albaranes/reservar' && method === 'POST') return ok(ventas.reservar(body))
    if (path === '/api/ventas/albaranes' && method === 'POST') return ok(ventas.crear(body))

    const venta = path.match(RE_VENTA)
    if (venta) {
      const [, empresa, tipo, albaran, accion] = venta
      if (!accion && method === 'GET') return ok(ventas.obtener(empresa, tipo, albaran))
      if (!accion && method === 'PUT') return ok(ventas.actualizar(empresa, tipo, albaran, body))
      if (!accion && method === 'DELETE') {
        ventas.anular(empresa, tipo, albaran)
        return ok({ ok: true })
      }
      if (accion === '/finalizar' && method === 'POST') return ok(ventas.finalizar(empresa, tipo, albaran, body))
      if (accion === '/impreso' && method === 'POST') return ok(ventas.obtener(empresa, tipo, albaran))
      if (accion === '/en-espera' || accion === '/recuperar') {
        return error(503, 'Los tickets en espera no están disponibles sin conexión.')
      }
      return error(503, NO_DISPONIBLE)
    }

    if (method === 'GET') {
      const cache = leerCache(path, query)
      if (cache !== null) return ok(cache)
    }
    return error(503, NO_DISPONIBLE)
  } catch (e) {
    if (e instanceof ventas.ErrorLocal) return error(e.status, e.message, e.codigo)
    console.error('[offline] Error en el servidor local:', e)
    return error(500, `Error en el modo sin conexión: ${e.message}`, 'ERROR')
  }
}

/** GET con respuesta correcta del servidor que conviene tener sin conexión. */
const PREFIJOS_CACHE = [
  '/api/tpv/contexto',
  '/api/tpv/teclados/',
  '/api/mantenimiento/puestos-trabajo/',
  '/api/mantenimiento/tiendas/',
  '/api/mantenimiento/trabajadores/',
  '/api/mantenimiento/documento-plantillas',
  '/api/mantenimiento/formas-pago',
  '/api/config-equipo',
]
const MAX_BYTES_CACHE = 512 * 1024

function guardarCache(req) {
  const u = new URL(String(req.url || ''), 'http://local')
  const path = decodeURIComponent(u.pathname)
  if (path === '/api/auth/me') {
    // Solo en memoria: tras reiniciar sin conexión hay que volver a entrar con contraseña.
    if (req.data?.usuario) sesionLocal.adoptar(req.data.usuario, req.data.permisos)
    return false
  }
  if (!PREFIJOS_CACHE.some((p) => path.startsWith(p))) return false
  let data = req.data ?? null
  if (typeof data === 'string') {
    const leido = leerJson(data)
    if (leido && typeof leido === 'object') data = leido
  }
  const texto = JSON.stringify(data)
  if (texto.length > MAX_BYTES_CACHE) return false
  db.ejecutar('INSERT OR REPLACE INTO cache (clave, datos, guardado) VALUES (?, ?, ?)', [
    claveCache(path + u.search, req.params || {}),
    texto,
    new Date().toISOString(),
  ])
  db.guardar()
  return true
}

module.exports = { manejar, guardarCache }
