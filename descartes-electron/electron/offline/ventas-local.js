/**
 * Tickets del TPV sin conexión. Mientras se vende, el ticket es un borrador
 * local (tipo "L"); al cobrar recibe el número de la serie del puesto
 * (OFF-<puesto>-000123), se firma y entra en la cola de envío.
 */
const crypto = require('crypto')
const db = require('./db')
const localConfig = require('../local-config')
const { empresaFoto } = require('./snapshot')
const { hmac } = require('./cifrado')
const { CLIENTE_RAPIDO, calcularTotales, importeLinea, numeroOffline, claveCache, leerJson, r2 } = require('./calculo')

/** Fuera del rango de UltAlbaranVen: un borrador local no se confunde con un albarán del servidor. */
const BASE_BORRADOR = 900000000
const MAX_PENDIENTES = 2000
const MAX_HORAS_OFFLINE = 72

class ErrorLocal extends Error {
  constructor(status, mensaje, codigo = 'OFFLINE') {
    super(mensaje)
    this.status = status
    this.codigo = codigo
  }
}

function ahoraLocal() {
  const d = new Date()
  const p = (n) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}

function contextoCache(empresa, puesto) {
  const r = db.fila('SELECT datos FROM cache WHERE clave = ?', [claveCache('/api/tpv/contexto', { empresa, puesto })])
  if (!r) return null
  const datos = leerJson(r.datos)
  return datos && typeof datos === 'object' ? datos : null
}

function clienteLocal(codigo) {
  const c = String(codigo ?? '').trim()
  if (!c || c.toUpperCase() === CLIENTE_RAPIDO) return null
  return db.fila('SELECT * FROM clientes WHERE codigo = ?', [c])
}

function claveBorrador(empresa, id) {
  return `${String(empresa).trim()}|L|${id}`
}

function leerBorrador(empresa, id) {
  const r = db.fila('SELECT datos FROM borradores WHERE clave = ?', [claveBorrador(empresa, id)])
  return r ? JSON.parse(r.datos) : null
}

function guardarBorrador(b) {
  db.ejecutar('INSERT OR REPLACE INTO borradores (clave, datos, actualizado) VALUES (?, ?, ?)', [
    claveBorrador(b.empresa, b.id),
    JSON.stringify(b),
    new Date().toISOString(),
  ])
  db.guardar()
}

function siguienteBorrador() {
  const n = (Number(db.meta('contadorBorrador')) || 0) + 1
  db.meta('contadorBorrador', String(n))
  return BASE_BORRADOR + n
}

/** Límites del modo local: pasado esto hay que conectar antes de seguir vendiendo. */
function comprobarLimites() {
  const pendientes = db.fila(
    "SELECT COUNT(*) AS n, MIN(creado) AS primero FROM ventas_pendientes WHERE estado IN ('pendiente', 'error')"
  )
  if ((pendientes?.n ?? 0) >= MAX_PENDIENTES) {
    throw new ErrorLocal(
      409,
      `Hay ${pendientes.n} ventas sin enviar. Hace falta conectar con el servidor para seguir vendiendo.`,
      'LIMITE_OFFLINE'
    )
  }
  if (pendientes?.primero) {
    const horas = (Date.now() - Date.parse(pendientes.primero)) / 3600000
    if (horas > MAX_HORAS_OFFLINE) {
      throw new ErrorLocal(
        409,
        `Hay ventas sin enviar desde hace más de ${MAX_HORAS_OFFLINE} horas. Hace falta conectar para seguir vendiendo.`,
        'LIMITE_OFFLINE'
      )
    }
  }
  const empresa = empresaFoto()
  if (!empresa || !db.meta('ultimaFoto')) {
    throw new ErrorLocal(
      409,
      'Este puesto no tiene datos guardados para vender sin conexión. Abra el TPV con conexión al menos una vez.'
    )
  }
  if (empresa.ticketBai) {
    throw new ErrorLocal(
      409,
      'Esta tienda emite TicketBAI: cada ticket se firma con el servidor, así que sin conexión no se puede cobrar.'
    )
  }
}

function lineaLocal(l) {
  const cantidad = Number(l.cantidad) || 0
  const precio = Number(l.precio) || 0
  const pjeDto = Number(l.pjeDto) || 0
  return {
    articulo: String(l.articulo ?? '').trim(),
    descripcion: String(l.descripcion ?? ''),
    cantidad,
    precio,
    pjeDto,
    importe: importeLinea(cantidad, precio, pjeDto),
    pjeIva: Number(l.pjeIva) || 0,
    ...(l.regalo ? { regalo: true } : {}),
  }
}

function aplicarPayload(b, payload) {
  const campos = ['cliente', 'razonSocial', 'razonSocial2', 'nif', 'telefono', 'email', 'vendedor', 'tarifa']
  for (const c of campos) {
    if (payload[c] !== undefined) b[c] = payload[c]
  }
  if (payload.almacen !== undefined && payload.almacen !== null) b.almacen = payload.almacen
  if (Array.isArray(payload.lineas)) b.lineas = payload.lineas.map(lineaLocal)
  return b
}

function detalle(b, extra = {}) {
  const empresa = empresaFoto()
  const cli = clienteLocal(b.cliente)
  const tot = calcularTotales(b.lineas || [], {
    ivaIncluido: !!empresa?.preciosIvaIncluido,
    dto1: cli ? Number(cli.dto1) || 0 : 0,
  })
  const ctx = contextoCache(b.empresa, b.puesto)
  return {
    empresa: b.empresa,
    tipo: 'L',
    albaran: b.id,
    fecha: b.fecha,
    cliente: b.cliente || CLIENTE_RAPIDO,
    razonSocial: b.razonSocial || '',
    razonSocial2: b.razonSocial2 || '',
    nif: b.nif || '',
    telefono: b.telefono || '',
    email: b.email || '',
    puesto: b.puesto,
    vendedor: b.vendedor || null,
    almacen: b.almacen ?? empresa?.almacen ?? null,
    tarifa: b.tarifa ?? ctx?.tarifa ?? 1,
    estado: 'B',
    importe: tot.importe,
    bruto: tot.bruto,
    descuento: tot.descuento,
    pjeDto: tot.pjeDto,
    iva: tot.iva,
    pjeIva1: tot.importesIva[0]?.pjeIva ?? 21,
    importesIva: tot.importesIva,
    lineas: (b.lineas || []).map((l, i) => ({ nroLin: i + 1, ...l })),
    formasPago: b.fpago1 ? [{ codigo: b.fpago1, importe: tot.importe }] : [],
    facturada: false,
    factura: 0,
    facturaTipo: 'R',
    sesion: ctx?.sesion ?? null,
    offline: true,
    ...extra,
  }
}

function reservar({ empresa, puesto }) {
  comprobarLimites()
  const e = String(empresa || '').trim()
  const p = String(puesto || '').trim()
  const ctx = contextoCache(e, p)
  const id = siguienteBorrador()
  db.meta(`reservaLocal:${e}:${id}`, '1')
  db.guardar()
  return {
    empresa: e,
    tipo: 'L',
    albaran: id,
    puesto: p || null,
    vendedor: ctx?.vendedor ?? null,
    almacen: empresaFoto()?.almacen ?? null,
  }
}

function crear(payload) {
  comprobarLimites()
  const empresa = String(payload.empresa || '').trim()
  const n = Number(payload.albaran) || 0
  // Reserva hecha con conexión y cabecera aún no grabada: el número del servidor queda libre.
  const id = n > 0 && db.meta(`reservaLocal:${empresa}:${n}`) ? n : siguienteBorrador()
  db.meta(`reservaLocal:${empresa}:${n}`, null)
  const b = aplicarPayload(
    {
      id,
      empresa,
      puesto: String(payload.puesto || '').trim(),
      fecha: ahoraLocal(),
      lineas: [],
      anularServidor: null,
    },
    payload
  )
  guardarBorrador(b)
  return detalle(b)
}

/** Busca el borrador; si la venta empezó en el servidor antes del corte, la pasa a local. */
function resolver(empresa, tipo, albaran, payload = null) {
  const e = String(empresa).trim()
  const n = Number(albaran)
  if (String(tipo).toUpperCase() === 'L') {
    const b = leerBorrador(e, n)
    if (b) return b
    throw new ErrorLocal(404, 'Ticket local no encontrado', 'NO_ENCONTRADO')
  }
  const adoptado = db.meta(`adoptado:${e}|${tipo}|${n}`)
  if (adoptado) {
    const b = leerBorrador(e, Number(adoptado))
    if (b) return b
  }
  if (!payload) {
    throw new ErrorLocal(503, 'Este documento está en el servidor y no hay conexión')
  }
  const id = siguienteBorrador()
  const b = {
    id,
    empresa: e,
    puesto: String(payload.puesto || '').trim(),
    fecha: ahoraLocal(),
    lineas: [],
    anularServidor: { empresa: e, tipo: String(tipo), albaran: n },
  }
  db.meta(`adoptado:${e}|${tipo}|${n}`, String(id))
  return b
}

function actualizar(empresa, tipo, albaran, payload) {
  const b = aplicarPayload(resolver(empresa, tipo, albaran, payload), payload)
  guardarBorrador(b)
  return detalle(b)
}

function obtener(empresa, tipo, albaran) {
  if (String(tipo).toUpperCase() === 'L') {
    const enviada = db.fila('SELECT venta FROM ventas_pendientes WHERE empresa = ? AND venta LIKE ?', [
      String(empresa).trim(),
      `%"albaran":${Number(albaran)},%`,
    ])
    if (enviada) return JSON.parse(enviada.venta)
  }
  return detalle(resolver(empresa, tipo, albaran))
}

/** Indicadores de la foto antigua pueden venir como 0/"0" en vez de booleanos. */
function marcado(valor) {
  if (valor === true || valor === 1) return true
  return ['1', 'true', 'si', 'sí', 's', 'x'].includes(String(valor ?? '').trim().toLowerCase())
}

/** Cliente real cuya forma de pago no está entre las de caja (CobroDeArqueo). */
function clienteEsCredito(b) {
  const codigo = String(b.cliente || '').trim().toUpperCase()
  if (!codigo || codigo === CLIENTE_RAPIDO) return false
  const cli = clienteLocal(codigo)
  if (!cli) return false
  const forma = String(cli.forma_pago || '').trim().toUpperCase()
  const ctx = contextoCache(b.empresa, b.puesto)
  const deCaja = (ctx?.formasPago || []).some(
    (f) => String(f.codigo).trim().toUpperCase() === forma && forma !== ''
  )
  return !deCaja
}

/**
 * Sin conexión: efectivo y datáfono externo (solo el indicador Datafono).
 * El terminal integrado (Datafono + ChipAcumuladoMenu) y los vales no.
 */
function formaPermitida(ctx, codigo) {
  const buscado = String(codigo).trim().toUpperCase()
  const forma = (ctx?.formasPago || []).find((f) => String(f.codigo).trim().toUpperCase() === buscado)
  if (!forma) return { ok: false, motivo: `La forma de pago «${buscado}» no está en los datos locales del puesto.` }
  if (marcado(forma.datafono) && marcado(forma.chipAcumuladoMenu)) {
    return { ok: false, motivo: 'El datáfono integrado necesita conexión con el servidor.' }
  }
  if (marcado(forma.vales)) return { ok: false, motivo: 'La forma seleccionada es un vale.' }
  return { ok: true }
}

function finalizar(empresa, tipo, albaran, body) {
  comprobarLimites()
  const b = resolver(empresa, tipo, albaran)
  const opcion = String(body?.tipo || '').trim().toUpperCase()
  if (opcion !== 'T' && opcion !== 'A') {
    throw new ErrorLocal(409, 'Sin conexión solo se puede cobrar como ticket o, si el cliente es de crédito, como albarán.')
  }
  const esAlbaran = opcion === 'A'
  if (esAlbaran && !clienteEsCredito(b)) {
    throw new ErrorLocal(409, 'Cliente de contado: no se puede finalizar como albarán. Use Ticket.')
  }
  const fidelizacion = [
    'aplicarValeFidelizacion',
    'aplicarPuntosFidelizacion',
    'aplicarClubSaldo',
    'aplicarClubPromo',
    'altaClub',
    'valeCodigo',
    'clubValeId',
  ].some((k) => !!body?.[k])
  if (fidelizacion) {
    throw new ErrorLocal(409, 'Vales, puntos y club de fidelización no están disponibles sin conexión.')
  }
  const ctx = contextoCache(b.empresa, b.puesto)
  const fpago = esAlbaran
    ? String(clienteLocal(b.cliente)?.forma_pago || '').trim()
    : String(body?.fpago1 || '').trim()
  if (!esAlbaran) {
    const forma = formaPermitida(ctx, fpago)
    if (!forma.ok) {
      throw new ErrorLocal(409, `Sin conexión no se puede cerrar el cobro: ${forma.motivo}`)
    }
  }
  const conArticulo = (b.lineas || []).filter((l) => l.articulo && l.articulo.toUpperCase() !== 'NO')
  if (conArticulo.length === 0) {
    throw new ErrorLocal(400, 'El ticket no tiene líneas', 'VALIDACION')
  }
  if (!String(b.vendedor || '').trim()) {
    throw new ErrorLocal(400, 'No se puede cobrar sin vendedor. Asigne el vendedor del puesto.', 'VALIDACION')
  }
  const clave = db.meta('claveOffline')
  if (!clave) {
    throw new ErrorLocal(409, 'Este puesto no tiene la clave para firmar ventas sin conexión. Abra el TPV con conexión.')
  }

  const contador =
    Math.max(Number(db.meta('contadorOffline')) || 0, localConfig.readContadorOffline()) + 1
  const numero = numeroOffline(b.puesto, contador)
  b.fpago1 = fpago
  const vista = detalle(b, {
    numeroOffline: numero,
    facturaTipo: esAlbaran ? null : 'T',
    fechaCobro: ahoraLocal(),
  })
  const uuid = crypto.randomUUID()
  const payload = {
    uuid,
    empresa: b.empresa,
    puesto: b.puesto,
    numeroOffline: numero,
    fechaHora: b.fecha,
    fechaCobro: vista.fechaCobro,
    desfaseRelojMs: Number(db.meta('desfaseRelojMs')) || 0,
    cliente: b.cliente || CLIENTE_RAPIDO,
    razonSocial: b.razonSocial || '',
    razonSocial2: b.razonSocial2 || '',
    nif: b.nif || '',
    telefono: b.telefono || '',
    email: b.email || '',
    vendedor: b.vendedor,
    almacen: vista.almacen,
    tarifa: vista.tarifa,
    documentoTipo: esAlbaran ? 'A' : 'T',
    fpago1: fpago,
    importe: r2(vista.importe),
    lineas: b.lineas,
    anularServidor: b.anularServidor || null,
  }
  const payloadJson = JSON.stringify(payload)
  db.transaccion(() => {
    db.meta('contadorOffline', String(contador))
    db.ejecutar(
      `INSERT INTO ventas_pendientes (uuid, orden, tipo_op, numero_offline, empresa, puesto, creado, payload, hmac,
        estado, intentos, error, proximo_intento, enviado, resultado, importe, venta)
       VALUES (?, ?, 'venta', ?, ?, ?, ?, ?, ?, 'pendiente', 0, NULL, NULL, NULL, NULL, ?, ?)`,
      [uuid, contador, numero, b.empresa, b.puesto, new Date().toISOString(), payloadJson, hmac(clave, payloadJson), payload.importe, JSON.stringify(vista)]
    )
    db.ejecutar('DELETE FROM borradores WHERE clave = ?', [claveBorrador(b.empresa, b.id)])
  })
  // Una venta cobrada no se puede perder: se escribe ya en disco.
  db.guardar({ inmediato: true })
  try {
    localConfig.writeContadorOffline(contador)
  } catch {
    // La base local ya tiene el contador.
  }
  return vista
}

/** Anulación de un documento del servidor hecha sin conexión: se envía al volver. */
function encolarAnulacion(servidor) {
  const uuid = crypto.randomUUID()
  db.ejecutar(
    `INSERT INTO ventas_pendientes (uuid, orden, tipo_op, numero_offline, empresa, puesto, creado, payload, hmac,
      estado, intentos, importe)
     VALUES (?, ?, 'anular', NULL, ?, NULL, ?, ?, NULL, 'pendiente', 0, 0)`,
    [uuid, 0, servidor.empresa, new Date().toISOString(), JSON.stringify(servidor)]
  )
}

function anular(empresa, tipo, albaran) {
  const e = String(empresa).trim()
  const n = Number(albaran)
  let b = null
  try {
    b = resolver(e, tipo, n)
  } catch (err) {
    if (String(tipo).toUpperCase() === 'L') throw err
    // Venta del servidor que no se llegó a tocar sin conexión.
    encolarAnulacion({ empresa: e, tipo: String(tipo), albaran: n })
    db.guardar({ inmediato: true })
    return
  }
  db.transaccion(() => {
    if (b.anularServidor) encolarAnulacion(b.anularServidor)
    db.ejecutar('DELETE FROM borradores WHERE clave = ?', [claveBorrador(b.empresa, b.id)])
  })
  db.guardar({ inmediato: true })
}

module.exports = {
  ErrorLocal,
  reservar,
  crear,
  actualizar,
  obtener,
  finalizar,
  anular,
  contextoCache,
}
