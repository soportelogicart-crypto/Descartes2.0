/**
 * Copia local de la UI de gestión (dist del hosting) servida con descartes://app/.
 * Así la ventana abre sin conexión. /api/* se reenvía al hosting con las cookies
 * de la sesión de Electron.
 */
const { app, protocol } = require('electron')
const fs = require('fs')
const path = require('path')
const { peticion } = require('./http')

const ESQUEMA = 'descartes'
const URL_LOCAL = `${ESQUEMA}://app/`

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.wasm': 'application/wasm',
  '.txt': 'text/plain; charset=utf-8',
}

let apiBase = ''

/** Debe llamarse antes de app.whenReady(). */
function registrarEsquema() {
  protocol.registerSchemesAsPrivileged([
    {
      scheme: ESQUEMA,
      privileges: {
        standard: true,
        secure: true,
        supportFetchAPI: true,
        corsEnabled: true,
        stream: true,
      },
    },
  ])
}

function dirUi() {
  return path.join(app.getPath('userData'), 'ui')
}

function leerActual() {
  try {
    const data = JSON.parse(fs.readFileSync(path.join(dirUi(), 'actual.json'), 'utf8'))
    const dir = path.join(dirUi(), String(data.version || ''))
    if (data.version && fs.existsSync(path.join(dir, 'index.html'))) {
      return { version: String(data.version), base: String(data.base || '/'), dir }
    }
  } catch {
    // sin copia local
  }
  return null
}

function disponible() {
  return leerActual() !== null
}

function urlArchivo(appUrl, nombre) {
  const base = appUrl.endsWith('/') ? appUrl : `${appUrl}/`
  return new URL(nombre.split('/').map(encodeURIComponent).join('/'), base).toString()
}

/**
 * Descarga la versión publicada si cambió. No lanza: sin conexión o sin
 * manifiesto se sigue con la copia que haya.
 * @returns {Promise<{ actualizado: boolean, version: string|null, motivo?: string }>}
 */
async function actualizar(appUrl, { timeoutMs = 8000 } = {}) {
  const actual = leerActual()
  let manifiesto
  try {
    const res = await peticion({
      url: `${urlArchivo(appUrl, 'ui-manifest.json')}?t=${Date.now()}`,
      timeoutMs,
    })
    if (res.status !== 200) {
      return { actualizado: false, version: actual?.version ?? null, motivo: `manifiesto HTTP ${res.status}` }
    }
    manifiesto = JSON.parse(res.body.toString('utf8'))
  } catch (e) {
    return { actualizado: false, version: actual?.version ?? null, motivo: e.message }
  }
  const version = String(manifiesto?.version || '').replace(/[^a-zA-Z0-9_-]/g, '')
  const archivos = Array.isArray(manifiesto?.archivos) ? manifiesto.archivos : []
  if (!version || !archivos.includes('index.html')) {
    return { actualizado: false, version: actual?.version ?? null, motivo: 'manifiesto no válido' }
  }
  if (actual && actual.version === version) {
    return { actualizado: false, version }
  }

  const destino = path.join(dirUi(), version)
  const temporal = `${destino}.descarga`
  fs.rmSync(temporal, { recursive: true, force: true })
  try {
    for (const nombre of archivos) {
      const limpio = path.normalize(String(nombre)).replace(/^([.][.][\\/])+/, '')
      if (limpio.startsWith('..') || path.isAbsolute(limpio)) continue
      const res = await peticion({ url: urlArchivo(appUrl, nombre), timeoutMs: 60000 })
      if (res.status !== 200) {
        throw new Error(`${nombre}: HTTP ${res.status}`)
      }
      const archivo = path.join(temporal, limpio)
      fs.mkdirSync(path.dirname(archivo), { recursive: true })
      fs.writeFileSync(archivo, res.body)
    }
    fs.rmSync(destino, { recursive: true, force: true })
    fs.renameSync(temporal, destino)
    fs.writeFileSync(
      path.join(dirUi(), 'actual.json'),
      JSON.stringify({ version, base: manifiesto.base || '/', descargado: new Date().toISOString() }, null, 2)
    )
  } catch (e) {
    fs.rmSync(temporal, { recursive: true, force: true })
    return { actualizado: false, version: actual?.version ?? null, motivo: e.message }
  }

  // Se guardan la actual y la anterior; el resto sobra.
  for (const entrada of fs.readdirSync(dirUi(), { withFileTypes: true })) {
    if (!entrada.isDirectory()) continue
    if (entrada.name === version || entrada.name === actual?.version) continue
    fs.rmSync(path.join(dirUi(), entrada.name), { recursive: true, force: true })
  }
  return { actualizado: true, version }
}

function esRutaApi(pathname) {
  return /(^|\/)api(\/|$)/.test(pathname)
}

async function reenviarApi(request, url) {
  const apiPath = url.pathname.slice(url.pathname.indexOf('/api'))
  const destino = `${apiBase}${apiPath}${url.search}`
  const headers = {}
  request.headers.forEach((v, k) => {
    headers[k] = v
  })
  const body =
    request.method === 'GET' || request.method === 'HEAD'
      ? null
      : Buffer.from(await request.arrayBuffer())
  try {
    const res = await peticion({
      method: request.method,
      url: destino,
      headers,
      body,
      timeoutMs: 120000,
    })
    return new Response(res.body, { status: res.status, headers: res.headers })
  } catch (e) {
    // 502 = no se llegó al servidor; el renderer lo trata como corte de red.
    return new Response(
      JSON.stringify({ error: `Sin conexión con el servidor: ${e.message}`, codigo: 'SIN_CONEXION' }),
      { status: 502, headers: { 'content-type': 'application/json; charset=utf-8' } }
    )
  }
}

function servirArchivo(url) {
  const actual = leerActual()
  if (!actual) {
    return new Response('Sin copia local de la aplicación', { status: 404 })
  }
  let ruta = decodeURIComponent(url.pathname)
  const base = actual.base.endsWith('/') ? actual.base : `${actual.base}/`
  if (base !== '/' && ruta.startsWith(base)) {
    ruta = `/${ruta.slice(base.length)}`
  }
  let archivo = path.normalize(path.join(actual.dir, ruta))
  if (!archivo.startsWith(actual.dir)) {
    return new Response('Ruta no válida', { status: 403 })
  }
  if (!fs.existsSync(archivo) || fs.statSync(archivo).isDirectory()) {
    // Rutas del router de Vue (history): siempre index.html.
    archivo = path.join(actual.dir, 'index.html')
  }
  const tipo = MIME[path.extname(archivo).toLowerCase()] || 'application/octet-stream'
  return new Response(fs.readFileSync(archivo), {
    status: 200,
    headers: { 'content-type': tipo, 'cache-control': 'no-cache' },
  })
}

/** Tras app.whenReady(). `base` = origen del hosting que recibe /api. */
function registrarManejador(base) {
  apiBase = String(base || '').replace(/\/+$/, '')
  protocol.handle(ESQUEMA, async (request) => {
    const url = new URL(request.url)
    if (esRutaApi(url.pathname)) {
      return reenviarApi(request, url)
    }
    return servirArchivo(url)
  })
}

module.exports = {
  URL_LOCAL,
  registrarEsquema,
  registrarManejador,
  actualizar,
  disponible,
  leerActual,
}
