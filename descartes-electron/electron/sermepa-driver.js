const { spawn, spawnSync } = require('child_process')
const fs = require('fs')
const os = require('os')
const path = require('path')

const procesos = new Map()

function text(value) {
  return String(value ?? '').trim()
}

function xmlValue(xml, names) {
  for (const name of names) {
    const match = String(xml || '').match(
      new RegExp(`<${name}(?:\\s[^>]*)?>(?:<!\\[CDATA\\[)?([\\s\\S]*?)(?:\\]\\]>)?</${name}>`, 'i')
    )
    if (match) return match[1].replace(/<[^>]+>/g, '').trim()
  }
  return ''
}

/** El TPV antiguo configura el puerto como "COM9:,19200,N,8,1". */
function normalizarPuerto(value) {
  const raw = text(value).toUpperCase()
  const com = raw.match(/^COM\s*(\d+)$/)
  return com ? `COM${com[1]}:,19200,N,8,1` : raw
}

function configuracion(payload) {
  const cfg = payload.configuracion || payload
  return {
    comercio: text(cfg.comercio),
    terminal: text(cfg.terminal),
    clave: text(cfg.clave),
    version: text(cfg.version) || '8.1',
    puerto: normalizarPuerto(cfg.puerto),
    demo: !!cfg.demo,
  }
}

function validar(cfg) {
  if (!cfg.comercio) throw new Error('Indique el número de comercio de Redsys')
  if (!cfg.terminal) throw new Error('Indique el terminal de Redsys')
  if (!cfg.clave) throw new Error('Indique la clave del TPV de Redsys')
  if (!cfg.puerto) throw new Error('Indique el puerto del pinpad')
}

function directorioAyudante() {
  try {
    return path.join(require('electron').app.getPath('userData'), 'redsys')
  } catch {
    return path.join(os.tmpdir(), 'descartes-redsys')
  }
}

/**
 * La librería de Redsys es COM de 32 bits y solo responde dentro de un proceso
 * x86 en STA con bombeo de mensajes, así que Electron delega en este ayudante.
 * Se compila una vez con el csc del .NET Framework que trae Windows.
 */
function ayudante() {
  // csc.exe no puede leer dentro de app.asar: el fuente se empaqueta en app.asar.unpacked.
  const fuente = path
    .join(__dirname, 'redsys', 'RedsysTpvpc.cs')
    .replace(`app.asar${path.sep}`, `app.asar.unpacked${path.sep}`)
  const destino = path.join(directorioAyudante(), 'RedsysTpvpc.exe')
  const compilado = fs.existsSync(destino) && fs.statSync(destino).mtimeMs >= fs.statSync(fuente).mtimeMs
  if (compilado) return destino

  const csc = path.join(
    process.env.WINDIR || 'C:\\Windows',
    'Microsoft.NET',
    'Framework',
    'v4.0.30319',
    'csc.exe'
  )
  if (!fs.existsSync(csc)) {
    throw new Error('Falta .NET Framework 4 en el equipo: no se puede usar el datáfono Redsys')
  }
  fs.mkdirSync(path.dirname(destino), { recursive: true })
  const res = spawnSync(
    csc,
    [
      '/nologo',
      '/platform:x86',
      '/target:exe',
      `/out:${destino}`,
      '/r:System.Windows.Forms.dll',
      fuente,
    ],
    { encoding: 'utf8', windowsHide: true }
  )
  if (res.status !== 0 || !fs.existsSync(destino)) {
    throw new Error(`No se pudo preparar el conector Redsys: ${text(res.stderr) || text(res.stdout)}`)
  }
  return destino
}

function ejecutar(entrada, operationId, signal) {
  const exe = ayudante()
  return new Promise((resolve, reject) => {
    const child = spawn(exe, [], { windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] })
    if (operationId) procesos.set(operationId, child)
    let stdout = ''
    let stderr = ''
    const abort = () => {
      child.kill()
      reject(new Error('Operación cancelada'))
    }
    if (signal) {
      if (signal.aborted) return abort()
      signal.addEventListener('abort', abort, { once: true })
    }
    child.stdout.setEncoding('utf8')
    child.stderr.setEncoding('utf8')
    child.stdout.on('data', (chunk) => {
      stdout += chunk
    })
    child.stderr.on('data', (chunk) => {
      stderr += chunk
    })
    child.on('error', reject)
    child.on('close', (code) => {
      if (signal) signal.removeEventListener('abort', abort)
      if (operationId) procesos.delete(operationId)
      if (signal?.aborted) return
      const line = stdout.trim().split(/\r?\n/).filter(Boolean).at(-1)
      if (!line) {
        reject(new Error(text(stderr) || `El conector Redsys terminó con código ${code}`))
        return
      }
      try {
        resolve(JSON.parse(line))
      } catch {
        reject(new Error(`Respuesta no válida del conector Redsys: ${line}`))
      }
    })
    const lineas = Object.entries(entrada).map(([clave, valor]) => `${clave}=${text(valor)}`)
    child.stdin.end(`${lineas.join('\n')}\n`, 'utf8')
  })
}

/** Redsys solo devuelve el motivo del rechazo en el XML: sin esto no hay forma de verlo. */
function registrarOperacion(entrada, raw) {
  try {
    const destino = path.join(directorioAyudante(), 'ultima-operacion.json')
    const { clave, ...datos } = entrada
    fs.writeFileSync(
      destino,
      JSON.stringify({ fecha: new Date().toISOString(), enviado: datos, recibido: raw }, null, 2),
      'utf8'
    )
  } catch {
    // el diagnóstico nunca debe tumbar un cobro
  }
}

/** Redsys numera las operaciones; de la referencia interna solo sirven los dígitos. */
function pedido(payload) {
  const digitos = text(payload.reference || payload.operationId).replace(/\D/g, '')
  if (digitos) return digitos.slice(-12)
  const ahora = new Date()
  return `${ahora.getHours()}${ahora.getMinutes()}${ahora.getSeconds()}`.padStart(6, '0')
}

async function status(payload) {
  const cfg = configuracion(payload)
  if (cfg.demo) {
    return { ok: true, available: true, code: 'DEMO', message: 'Redsys configurado en modo demo' }
  }
  validar(cfg)
  const raw = await ejecutar({ action: 'status', ...cfg })
  if (!raw.ok) {
    return {
      ok: false,
      available: false,
      code: 'SERMEPA_NO_DISPONIBLE',
      message: raw.message || 'Redsys no pudo iniciar el datáfono',
    }
  }
  const serie = text(raw.serie)
  return {
    ok: true,
    available: true,
    code: 'OK',
    message: serie ? `Datáfono Redsys conectado (serie ${serie})` : 'Datáfono Redsys conectado',
  }
}

async function charge(payload, signal) {
  const cfg = configuracion(payload)
  if (cfg.demo) {
    return {
      ok: true,
      approved: true,
      code: 'DEMO',
      authorization: 'DEMO',
      reference: text(payload.reference || payload.operationId),
      message: 'Cobro simulado: modo demo',
    }
  }
  validar(cfg)
  const cents = Math.abs(Number(payload.amountCents || 0))
  let tipoOp = text(payload.tipoOperacion).toUpperCase()
  if (!tipoOp) tipoOp = Number(payload.amountCents || 0) < 0 ? 'DEVOLUCION' : 'PAGO'
  const esDevolucion = tipoOp === 'DEVOLUCION'
  const pedidoOrig = text(payload.pedidoOriginal)
  const codigoAut = text(payload.codigoAutorizacion)
  const entrada = {
    action: 'charge',
    ...cfg,
    importe: (cents / 100).toFixed(2),
    moneda: text(payload.currency) === 'EUR' ? '978' : text(payload.currency) || '978',
    pedido: esDevolucion && pedidoOrig ? pedidoOrig : pedido(payload),
    pedidoOriginal: pedidoOrig,
    rtsOriginal: text(payload.rtsOriginal),
    codigoAutorizacion: codigoAut,
    clr: text(payload.clr),
    factura: text(
      (esDevolucion && codigoAut) || payload.factura || payload.reference || payload.operationId
    ).slice(0, 40),
    devolucionSinOriginal: payload.devolucionSinOriginal ? '1' : '0',
    devolucionPinpad: payload.devolucionPinpad ? '1' : '0',
    modoLegacyRts:
      payload.modoLegacyRts ||
      (esDevolucion && text(payload.rtsOriginal) && !pedidoOrig)
        ? '1'
        : '0',
    tipoOperacion: tipoOp,
  }
  const raw = await ejecutar(entrada, text(payload.operationId), signal)
  registrarOperacion(entrada, raw)
  if (!raw.ok) {
    const detalle = text(raw.debug)
    return {
      ok: false,
      approved: false,
      code: 'SERMEPA_ERROR',
      message: [raw.message || 'Error de Redsys', detalle].filter(Boolean).join(' — '),
    }
  }

  const xml = text(raw.xml)
  const error = xml.match(/<error(?:\s[^>]*)?>([\s\S]*?)<\/error>/i)
  const errCode = xmlValue(xml, ['codigo'])
  const errMsg = error
    ? error[1].replace(/<[^>]+>/g, '').trim()
    : xmlValue(xml, ['mensaje', 'descripcion'])
  const hayError =
    /<Error>/i.test(xml) ||
    (errCode && /TPV|SOAP|AX-/i.test(errCode)) ||
    (!!error && errMsg !== '')
  const resultado = xmlValue(xml, ['resultado'])
  const codigo = xmlValue(xml, ['codigoRespuesta', 'codigo'])
  const numero = /^\d+$/.test(codigo) ? Number(codigo) : Number.NaN
  const approved =
    !hayError && (/autorizad[ao]/i.test(resultado) || (Number.isFinite(numero) && numero >= 0 && numero <= 99))
  return {
    ok: true,
    approved,
    code: codigo || (approved ? 'APROBADA' : 'RECHAZADA'),
    authorization: xmlValue(xml, ['autorizacion', 'codigoAutorizacion']),
    reference: xmlValue(xml, ['pedido', 'pedidoBase']) || text(payload.reference),
    // Sin el código de Redsys delante, un rechazo no se puede diagnosticar.
    message: [
      approved || !codigo ? '' : codigo,
      errMsg ||
        xmlValue(xml, ['mensaje', 'descripcion']) ||
        resultado ||
        (approved ? 'Cobro aprobado' : 'Cobro rechazado'),
    ]
      .filter(Boolean)
      .join(': '),
    receipt: xml,
  }
}

async function cancel(payload) {
  const child = procesos.get(text(payload.operationId))
  if (!child) return
  child.kill()
}

module.exports = { status, charge, cancel }
