const test = require('node:test')
const assert = require('node:assert/strict')
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')
const { userData, red, limpiar } = require('./simulador')

const db = require('../../electron/offline/db')
const snapshot = require('../../electron/offline/snapshot')
const router = require('../../electron/offline/router')
const sesionLocal = require('../../electron/offline/sesion-local')
const cola = require('../../electron/offline/cola')

const CLAVE = crypto.randomBytes(32).toString('hex')
const EQUIPO = { empresaCodigo: '1', puestoCodigo: '01' }
const CONTEXTO = {
  empresa: '1',
  puesto: '01',
  sesion: 5,
  tecladoCodigo: 1,
  tecladoGeneral: '001',
  tarifa: 1,
  vendedor: 'V1',
  clienteRapido: 'ZZZZZZZZZ',
  formasPago: [
    { codigo: 'EU', descripcion: 'EFECTIVO', datafono: false, chipAcumuladoMenu: false, vales: false },
    { codigo: 'TJ', descripcion: 'TARJETA', datafono: true, chipAcumuladoMenu: false, vales: false },
    { codigo: 'XP', descripcion: 'DATAFONO', datafono: true, chipAcumuladoMenu: true, vales: false },
  ],
}

function fotoServidor() {
  return {
    status: 200,
    datos: {
      claveOffline: CLAVE,
      bloques: {
        empresa: { hash: 'e1', datos: { codigo: '1', preciosIvaIncluido: true, ticketBai: false, almacen: 1 } },
        articulos: {
          hash: 'a1',
          datos: [
            ['A1', 'Camiseta azul', 'ALT1', 'F1', 'S1', '', '', 'M1', 'P1', 21, [12.1, 11, 0, 0, 0, 0, 0, 0, 0]],
            ['B2', 'Pan de molde', '', 'F2', '', '', '', 'M2', 'P2', 4, [2.08, 2, 0, 0, 0, 0, 0, 0, 0]],
          ],
        },
        barras: { hash: 'b1', datos: [['8400000000017', 'A1', 1]] },
        familias: { hash: 'f1', datos: [['F1', 'Ropa', 'M1'], ['F2', 'Alimentación', 'M2']] },
        macrofamilias: { hash: 'm1', datos: [['M1', 'Textil'], ['M2', 'Comida']] },
        subfamilias: { hash: 's1', datos: [['F1', 'S1', 'Camisetas']] },
        clientes: {
          hash: 'c1',
          datos: [
            ['C001', 'Lucía Pérez', '', '12345678Z', 'Calle Mayor 1', 'Bilbao', '48001', 'Bizkaia', 'ES', '600000000', 'l@x.es', 'EU', 1, 'T1', 0],
            ['CR1', 'Taller Norte', '', 'B11223344', '', '', '', '', 'ES', '', '', 'CR', 1, '', 0],
          ],
        },
        tiposDescuento: { hash: 't1', datos: [] },
        cache: { hash: 'k1', datos: [{ path: '/api/tpv/contexto', params: { empresa: '1', puesto: '01' }, datos: CONTEXTO }] },
      },
    },
  }
}

function pedir(method, url, data, params) {
  return router.manejar({ method, url, data: data === undefined ? undefined : JSON.stringify(data), params })
}

test.before(async () => {
  await db.abrir()
  red.responder = () => fotoServidor()
  const r = await snapshot.refrescar({ apiBase: 'http://srv', equipo: EQUIPO, vinculo: null })
  assert.equal(r.ok, true)
  cola.configurar({ apiBase: 'http://srv', vinculo: null })
})

test.after(() => {
  if (cola.timer) clearTimeout(cola.timer)
  db.cerrar()
  limpiar()
})

test('foto incremental: solo pide los bloques cambiados', async () => {
  red.peticiones = []
  red.responder = () => ({ status: 200, datos: { bloques: { articulos: { hash: 'a1', sinCambios: true } } } })
  const r = await snapshot.refrescar({ apiBase: 'http://srv', equipo: EQUIPO, vinculo: null })
  assert.equal(r.ok, true)
  assert.deepEqual(r.cambiados, [])
  assert.match(red.peticiones[0].url, /\/api\/offline\/cambios\?.*hashes=/)
  assert.equal(db.fila('SELECT COUNT(*) AS n FROM articulos').n, 2)
})

test('sin sesión local solo se puede entrar; login offline con permisos de TPV', () => {
  assert.equal(pedir('GET', '/api/tpv/articulos?q=cam').status, 401)
  assert.equal(pedir('GET', '/api/instalacion/estado').data.requiereAccion, false)

  const r = sesionLocal.recordarUsuario({
    codigo: 'ana',
    password: 'secreta',
    usuario: { codigo: 'ANA', nombre: 'Ana' },
    permisos: { tpv: { ver: true, editar: true }, clientes: { ver: true } },
  })
  assert.equal(r.recordado, true)
  assert.equal(pedir('POST', '/api/auth/login', { usuario: 'ana', password: 'mala' }).status, 401)
  const ok = pedir('POST', '/api/auth/login', { usuario: 'ANA', password: 'secreta' })
  assert.equal(ok.status, 200)
  assert.deepEqual(Object.keys(ok.data.permisos), ['tpv', 'clientes'])
  assert.equal(ok.data.permisos.clientes.ver, true)
  assert.equal(ok.data.permisos.clientes.editar, false)
  assert.equal(pedir('GET', '/api/auth/me').data.usuario.codigo, 'ANA')
})

test('un usuario sin permiso de TPV no queda recordado', () => {
  const r = sesionLocal.recordarUsuario({ codigo: 'conta', password: 'x', usuario: {}, permisos: { facturacion: { ver: true } } })
  assert.equal(r.recordado, false)
  assert.equal(sesionLocal.login('conta', 'x').ok, false)
})

test('consulta de artículos y clientes en la base local', () => {
  const porEan = pedir('GET', '/api/tpv/articulos/resolver', undefined, { q: '8400000000017', tarifa: 1 })
  assert.equal(porEan.status, 200)
  assert.equal(porEan.data.codigo, 'A1')
  assert.equal(porEan.data.precio, 12.1)

  const busqueda = pedir('GET', '/api/tpv/articulos', undefined, { q: 'CAMISETA', tarifa: 2 })
  assert.equal(busqueda.data.items[0].precio, 11)

  const clientes = pedir('GET', '/api/tpv/clientes', undefined, { q: 'lucia' })
  assert.equal(clientes.data.items[0].codigo, 'C001')

  assert.equal(pedir('GET', '/api/tpv/contexto', undefined, { empresa: '1', puesto: '01' }).data.vendedor, 'V1')
  assert.equal(pedir('GET', '/api/compras/albaranes').status, 503)
})

let numeroPrimera = ''

test('venta en efectivo: borrador local, cobro con serie del puesto y firma', () => {
  const reserva = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  assert.equal(reserva.status, 200)
  assert.equal(reserva.data.tipo, 'L')

  const creada = pedir('POST', '/api/ventas/albaranes', {
    empresa: '1',
    albaran: reserva.data.albaran,
    puesto: '01',
    vendedor: reserva.data.vendedor,
    cliente: 'ZZZZZZZZZ',
    lineas: [
      { articulo: 'A1', descripcion: 'Camiseta azul', cantidad: 2, precio: 12.1, pjeIva: 21 },
      { articulo: 'B2', descripcion: 'Pan de molde', cantidad: 1, precio: 2.08, pjeIva: 4 },
    ],
  })
  assert.equal(creada.status, 200)
  assert.equal(creada.data.albaran, reserva.data.albaran)
  assert.equal(creada.data.importe, 26.28)

  const url = `/api/ventas/albaranes/1/L/${creada.data.albaran}/finalizar`
  const integrado = pedir('POST', url, { tipo: 'T', fpago1: 'XP' })
  assert.equal(integrado.status, 409)
  assert.match(integrado.data.error, /datáfono integrado/)
  assert.equal(pedir('POST', url, { tipo: 'F', fpago1: 'EU' }).status, 409)
  assert.equal(pedir('POST', url, { tipo: 'T', fpago1: 'EU', aplicarPuntosFidelizacion: true }).status, 409)

  const cobrada = pedir('POST', url, { tipo: 'T', fpago1: 'EU' })
  assert.equal(cobrada.status, 200)
  assert.equal(cobrada.data.numeroOffline, 'OFF-01-000001')
  assert.equal(cobrada.data.facturaTipo, 'T')
  numeroPrimera = cobrada.data.numeroOffline

  const fila = db.fila('SELECT * FROM ventas_pendientes WHERE numero_offline = ?', [numeroPrimera])
  const firma = crypto.createHmac('sha256', Buffer.from(CLAVE, 'hex')).update(fila.payload).digest('hex')
  assert.equal(fila.hmac, firma)
  const payload = JSON.parse(fila.payload)
  assert.equal(payload.importe, 26.28)
  assert.equal(payload.vendedor, 'V1')
  assert.equal(payload.anularServidor, null)

  // Reimprimir el ticket ya cobrado: sigue disponible aunque el borrador se haya borrado.
  const otraVez = pedir('GET', `/api/ventas/albaranes/1/L/${creada.data.albaran}`)
  assert.equal(otraVez.data.numeroOffline, numeroPrimera)
})

test('ticket del servidor tocado sin conexión: se adopta y se anula el original al enviar', () => {
  const adoptada = pedir('PUT', '/api/ventas/albaranes/1/A/555', {
    puesto: '01',
    vendedor: 'V1',
    lineas: [{ articulo: 'A1', descripcion: 'Camiseta azul', cantidad: 1, precio: 12.1, pjeIva: 21 }],
  })
  assert.equal(adoptada.status, 200)
  assert.equal(adoptada.data.tipo, 'L')
  const otraVez = pedir('PUT', '/api/ventas/albaranes/1/A/555', { lineas: adoptada.data.lineas })
  assert.equal(otraVez.data.albaran, adoptada.data.albaran)

  const cobrada = pedir('POST', `/api/ventas/albaranes/1/L/${adoptada.data.albaran}/finalizar`, { tipo: 'T', fpago1: 'EU' })
  assert.equal(cobrada.data.numeroOffline, 'OFF-01-000002')
  const payload = JSON.parse(db.fila('SELECT payload FROM ventas_pendientes WHERE numero_offline = ?', ['OFF-01-000002']).payload)
  assert.deepEqual(payload.anularServidor, { empresa: '1', tipo: 'A', albaran: 555 })
})

test('la base local en disco va cifrada', () => {
  db.guardar({ inmediato: true })
  const dir = path.join(userData, 'offline')
  const archivos = fs.readdirSync(dir).filter((f) => f.endsWith('.db') || f.endsWith('.bin') || f.endsWith('.dat'))
  assert.ok(archivos.length > 0, `sin archivos en ${dir}: ${fs.readdirSync(dir).join(', ')}`)
  for (const f of archivos) {
    const contenido = fs.readFileSync(path.join(dir, f)).toString('latin1')
    assert.ok(!contenido.includes('OFF-01-000001'), `${f} contiene datos en claro`)
    assert.ok(!contenido.includes('SQLite format'), `${f} es una base SQLite sin cifrar`)
  }
})

test('cola: corte de red a mitad, reintento con el mismo UUID y aviso final', async () => {
  let llamadas = 0
  red.responder = () => {
    llamadas += 1
    throw new Error('ECONNRESET')
  }
  const r1 = await cola.procesar(() => true)
  assert.equal(r1.terminado, false)
  assert.equal(llamadas, 1)
  const tras = db.fila('SELECT intentos, proximo_intento FROM ventas_pendientes WHERE numero_offline = ?', [numeroPrimera])
  assert.equal(tras.intentos, 1)
  assert.ok(Date.parse(tras.proximo_intento) > Date.now())

  db.ejecutar('UPDATE ventas_pendientes SET proximo_intento = NULL')
  const uuids = []
  let ticket = 700
  red.responder = (req) => {
    uuids.push(JSON.parse(req.datos.payload).uuid)
    ticket += 1
    return { status: 200, datos: { ticket, albaran: ticket, incidencias: ticket === 702 ? ['Artículo A1: precio cambiado'] : [] } }
  }
  const r2 = await cola.procesar(() => true)
  assert.equal(r2.terminado, true)
  assert.equal(uuids.length, 2)
  assert.equal(cola.resumen().pendientes, 0)
  const lote = cola.tomarLote()
  assert.equal(lote.enviadas, 2)
  assert.deepEqual(lote.tickets, [701, 702])
  assert.equal(lote.incidencias.length, 1)
})

test('cola: error de negocio queda para revisar y la sesión caducada para el envío', async () => {
  const reserva = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  pedir('POST', '/api/ventas/albaranes', {
    empresa: '1',
    albaran: reserva.data.albaran,
    puesto: '01',
    vendedor: 'V1',
    lineas: [{ articulo: 'B2', cantidad: 1, precio: 2.08, pjeIva: 4 }],
  })
  pedir('POST', `/api/ventas/albaranes/1/L/${reserva.data.albaran}/finalizar`, { tipo: 'T', fpago1: 'EU' })

  red.responder = () => ({ status: 422, datos: { error: 'Artículos que ya no existen en el servidor: B2' } })
  await cola.procesar(() => true)
  assert.equal(cola.resumen().errores, 1)

  cola.reintentar()
  red.responder = () => ({ status: 401, datos: { error: 'No autenticado' } })
  const r = await cola.procesar(() => true)
  assert.equal(r.terminado, false)
  assert.equal(cola.resumen().requiereLogin, true)
  assert.equal(cola.resumen().pendientes, 1)

  cola.sesionRecuperada()
  red.responder = () => ({ status: 200, datos: { ticket: 703, incidencias: [] } })
  assert.equal((await cola.procesar(() => true)).terminado, true)
  assert.equal(cola.resumen().pendientes, 0)
})

test('el contexto guardado dos veces sigue teniendo las formas de pago', () => {
  const clave = '/api/tpv/contexto?empresa=1&puesto=01'
  const fila = db.fila('SELECT datos FROM cache WHERE clave = ?', [clave])
  db.ejecutar('UPDATE cache SET datos = ? WHERE clave = ?', [JSON.stringify(fila.datos), clave])
  const ctx = pedir('GET', '/api/tpv/contexto', undefined, { empresa: '1', puesto: '01' })
  assert.equal(ctx.data.formasPago.find((f) => f.codigo === 'EU').codigo, 'EU')
  db.ejecutar('UPDATE cache SET datos = ? WHERE clave = ?', [fila.datos, clave])
})

test('el datáfono externo se cobra sin conexión', () => {
  const reserva = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  pedir('POST', '/api/ventas/albaranes', {
    empresa: '1',
    albaran: reserva.data.albaran,
    puesto: '01',
    vendedor: reserva.data.vendedor,
    lineas: [{ articulo: 'B2', cantidad: 1, precio: 2.08, pjeIva: 4 }],
  })
  const cobrada = pedir('POST', `/api/ventas/albaranes/1/L/${reserva.data.albaran}/finalizar`, {
    tipo: 'T',
    fpago1: 'TJ',
  })
  assert.equal(cobrada.status, 200)
  const payload = JSON.parse(
    db.fila('SELECT payload FROM ventas_pendientes WHERE numero_offline = ?', [cobrada.data.numeroOffline]).payload
  )
  assert.equal(payload.fpago1, 'TJ')
})

test('cliente de crédito: se cierra como albarán y el de contado no', () => {
  const contado = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  pedir('POST', '/api/ventas/albaranes', {
    empresa: '1',
    albaran: contado.data.albaran,
    puesto: '01',
    vendedor: contado.data.vendedor,
    cliente: 'C001',
    lineas: [{ articulo: 'B2', cantidad: 1, precio: 2.08, pjeIva: 4 }],
  })
  const rechazado = pedir('POST', `/api/ventas/albaranes/1/L/${contado.data.albaran}/finalizar`, { tipo: 'A' })
  assert.equal(rechazado.status, 409)
  assert.match(rechazado.data.error, /contado/)

  const reserva = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  pedir('POST', '/api/ventas/albaranes', {
    empresa: '1',
    albaran: reserva.data.albaran,
    puesto: '01',
    vendedor: reserva.data.vendedor,
    cliente: 'CR1',
    razonSocial: 'Taller Norte',
    lineas: [{ articulo: 'A1', descripcion: 'Camiseta azul', cantidad: 1, precio: 12.1, pjeIva: 21 }],
  })
  const cobrada = pedir('POST', `/api/ventas/albaranes/1/L/${reserva.data.albaran}/finalizar`, { tipo: 'A' })
  assert.equal(cobrada.status, 200)
  assert.equal(cobrada.data.facturaTipo, null)
  assert.match(cobrada.data.numeroOffline, /^OFF-01-/)
  const payload = JSON.parse(
    db.fila('SELECT payload FROM ventas_pendientes WHERE numero_offline = ?', [cobrada.data.numeroOffline]).payload
  )
  assert.equal(payload.documentoTipo, 'A')
  assert.equal(payload.fpago1, 'CR')
  assert.equal(payload.cliente, 'CR1')
})

test('límite: TicketBAI impide cobrar sin conexión', async () => {
  red.responder = () => {
    const f = fotoServidor()
    f.datos.bloques.empresa.datos.ticketBai = true
    return f
  }
  await snapshot.refrescar({ apiBase: 'http://srv', equipo: EQUIPO, vinculo: null, completa: true })
  const r = pedir('POST', '/api/ventas/albaranes/reservar', { empresa: '1', puesto: '01' })
  assert.equal(r.status, 409)
  assert.match(r.data.error, /TicketBAI/)
})
