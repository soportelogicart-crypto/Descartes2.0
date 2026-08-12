/**
 * Mini agente HTTP local (127.0.0.1) para que descartes-api pueda proxyar
 * leer-cajon / imprimir sin que el navegador hable con el hardware.
 *
 * Puerto por defecto: 17321 (DISPOSITIVO_AGENTE_PORT).
 */

const http = require('http')
const peripherals = require('./peripherals')

let server = null

function port() {
  const p = Number(process.env.DISPOSITIVO_AGENTE_PORT || 17321)
  return Number.isFinite(p) && p > 0 ? p : 17321
}

function readJson(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    req.on('data', (c) => chunks.push(c))
    req.on('end', () => {
      if (!chunks.length) {
        resolve({})
        return
      }
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8')))
      } catch (e) {
        reject(e)
      }
    })
    req.on('error', reject)
  })
}

function sendJson(res, status, body) {
  const raw = JSON.stringify(body)
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(raw),
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Accept',
  })
  res.end(raw)
}

async function handle(req, res) {
  const url = String(req.url || '').split('?')[0]

  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Accept',
    })
    res.end()
    return
  }

  if (req.method === 'GET' && (url === '/health' || url === '/')) {
    sendJson(res, 200, { ok: true, service: 'descartes-dispositivo-agente', stub: false })
    return
  }

  if (req.method === 'GET' && url === '/impresoras') {
    const result = await peripherals.listPrinters()
    sendJson(res, result.ok ? 200 : 503, {
      ok: !!result.ok,
      stub: !!result.stub,
      agenteOnline: true,
      printers: result.printers || [],
      message: result.message || '',
    })
    return
  }

  if (req.method === 'POST' && url === '/leer-cajon') {
    const body = await readJson(req)
    const result = await peripherals.readCashDrawer(body)
    sendJson(res, 200, result)
    return
  }

  if (req.method === 'POST' && url === '/imprimir') {
    const body = await readJson(req)
    const result = await peripherals.printTicket(body)
    sendJson(res, 200, {
      ok: !!result.ok,
      stub: !!result.stub,
      message: result.message || '',
      impresora: result.impresora || null,
    })
    return
  }

  sendJson(res, 404, { ok: false, message: 'Ruta no encontrada' })
}

function start() {
  if (server) return server
  const p = port()
  server = http.createServer((req, res) => {
    handle(req, res).catch((err) => {
      console.error('[dispositivo-agente]', err)
      sendJson(res, 500, { ok: false, message: String(err && err.message ? err.message : err) })
    })
  })
  server.on('error', (err) => {
    console.warn('[dispositivo-agente] no se pudo iniciar:', err.message)
  })
  server.listen(p, '127.0.0.1', () => {
    console.log(`[dispositivo-agente] escuchando http://127.0.0.1:${p}`)
  })
  return server
}

function stop() {
  if (!server) return
  try {
    server.close()
  } catch (_) {
    /* ignore */
  }
  server = null
}

module.exports = { start, stop, port }
