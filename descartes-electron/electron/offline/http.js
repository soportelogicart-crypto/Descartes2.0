/**
 * Peticiones HTTP desde el proceso principal con las cookies de la sesión de la
 * ventana: el proxy de la UI local y la cola de ventas usan el mismo login.
 */
const { net, session } = require('electron')

/** Cabeceras que el renderer puede reenviar a la API. */
const CABECERAS_REENVIO = [
  'accept',
  'content-type',
  'x-cliente-id',
  'x-cliente-clave',
  'x-requested-with',
]

/** Cabeceras de respuesta que no se devuelven: Chromium ya descomprime el cuerpo. */
const CABECERAS_OMITIR = new Set(['content-encoding', 'content-length', 'transfer-encoding', 'set-cookie'])

function parseSetCookie(texto, url) {
  const partes = String(texto).split(';').map((p) => p.trim())
  const [par, ...atributos] = partes
  const igual = par.indexOf('=')
  if (igual <= 0) return null
  const cookie = {
    url,
    name: par.slice(0, igual),
    value: par.slice(igual + 1),
  }
  for (const atributo of atributos) {
    const [k, ...v] = atributo.split('=')
    const clave = k.trim().toLowerCase()
    const valor = v.join('=').trim()
    if (clave === 'path') cookie.path = valor
    else if (clave === 'domain') cookie.domain = valor
    else if (clave === 'secure') cookie.secure = true
    else if (clave === 'httponly') cookie.httpOnly = true
    else if (clave === 'samesite') {
      const s = valor.toLowerCase()
      cookie.sameSite = s === 'strict' ? 'strict' : s === 'none' ? 'no_restriction' : 'lax'
    } else if (clave === 'max-age') {
      const n = Number(valor)
      if (Number.isFinite(n)) cookie.expirationDate = Math.floor(Date.now() / 1000) + n
    } else if (clave === 'expires') {
      const t = Date.parse(valor)
      if (Number.isFinite(t)) cookie.expirationDate = Math.floor(t / 1000)
    }
  }
  return cookie
}

async function guardarCookies(url, setCookie) {
  const lista = Array.isArray(setCookie) ? setCookie : setCookie ? [setCookie] : []
  for (const texto of lista) {
    const cookie = parseSetCookie(texto, url)
    if (!cookie) continue
    try {
      await session.defaultSession.cookies.set(cookie)
    } catch {
      // Una cookie rechazada no debe romper la petición.
    }
  }
}

/**
 * @param {{ method?: string, url: string, headers?: Record<string, string>, body?: Buffer|string|null, timeoutMs?: number }} opciones
 * @returns {Promise<{ status: number, headers: Record<string, string>, body: Buffer }>}
 */
function peticion({ method = 'GET', url, headers = {}, body = null, timeoutMs = 30000 }) {
  return new Promise((resolve, reject) => {
    let terminado = false
    const req = net.request({
      method,
      url,
      session: session.defaultSession,
      useSessionCookies: true,
      redirect: 'follow',
    })
    for (const [k, v] of Object.entries(headers)) {
      if (v === undefined || v === null || v === '') continue
      if (!CABECERAS_REENVIO.includes(k.toLowerCase())) continue
      try {
        req.setHeader(k, String(v))
      } catch {
        // Cabecera no permitida por Chromium.
      }
    }
    const timer = setTimeout(() => {
      if (terminado) return
      terminado = true
      try {
        req.abort()
      } catch {
        // ignore
      }
      const err = new Error(`Tiempo de espera agotado (${Math.round(timeoutMs / 1000)} s)`)
      err.code = 'TIMEOUT'
      reject(err)
    }, timeoutMs)

    req.on('response', (res) => {
      const trozos = []
      res.on('data', (c) => trozos.push(Buffer.from(c)))
      res.on('end', async () => {
        if (terminado) return
        terminado = true
        clearTimeout(timer)
        const cabeceras = {}
        for (const [k, v] of Object.entries(res.headers || {})) {
          if (CABECERAS_OMITIR.has(k.toLowerCase())) continue
          cabeceras[k] = Array.isArray(v) ? v.join(', ') : String(v)
        }
        await guardarCookies(url, res.headers?.['set-cookie'])
        resolve({ status: res.statusCode, headers: cabeceras, body: Buffer.concat(trozos) })
      })
      res.on('error', (e) => {
        if (terminado) return
        terminado = true
        clearTimeout(timer)
        reject(e)
      })
    })
    req.on('error', (e) => {
      if (terminado) return
      terminado = true
      clearTimeout(timer)
      e.code = e.code || 'RED'
      reject(e)
    })
    if (body !== null && body !== undefined && method !== 'GET' && method !== 'HEAD') {
      req.write(body)
    }
    req.end()
  })
}

/** JSON con la cabecera de vínculo de instalación del equipo. */
async function peticionJson({ method = 'GET', url, datos, vinculo, timeoutMs = 30000 }) {
  const headers = { accept: 'application/json', 'content-type': 'application/json' }
  if (vinculo && vinculo.id) {
    headers['x-cliente-id'] = vinculo.id
    headers['x-cliente-clave'] = vinculo.clave
  }
  const res = await peticion({
    method,
    url,
    headers,
    body: datos === undefined ? null : JSON.stringify(datos),
    timeoutMs,
  })
  let json = null
  try {
    json = res.body.length ? JSON.parse(res.body.toString('utf8')) : null
  } catch {
    json = null
  }
  return { status: res.status, datos: json }
}

/** 502/503/504: el proxy o el hosting no llegan al servidor; cuenta como sin conexión. */
function esFalloDeRed(status) {
  return status === 502 || status === 503 || status === 504
}

module.exports = { peticion, peticionJson, esFalloDeRed }
