/**
 * Fachada agnóstica para datáfonos.
 *
 * Cada proveedor debe registrar un driver con:
 *   registerDriver('CODIGO', { charge, cancel?, status? })
 * El código se toma de Puestos.Datafono. Hasta instalar un driver real, una
 * forma de pago marcada como datáfono no puede cerrar la venta por error.
 */

const localConfig = require('./local-config')

const drivers = new Map()
const operations = new Map()
const approvedOperations = new Map()

const DEFAULT_TIMEOUT_MS = 120000

function text(value) {
  return String(value ?? '').trim()
}

function normalizeDriver(value) {
  return text(value).toUpperCase()
}

function resultError(code, message, operationId = '') {
  return {
    ok: false,
    approved: false,
    code,
    message,
    operationId: text(operationId) || null,
  }
}

function registerDriver(code, driver) {
  const key = normalizeDriver(code)
  if (!key) throw new Error('El código del driver de datáfono es obligatorio')
  if (!driver || typeof driver.charge !== 'function') {
    throw new Error(`El driver ${key} no implementa charge()`)
  }
  drivers.set(key, driver)
}

function withLocalConfig(payload = {}) {
  let stored = {}
  try {
    stored = localConfig.readDatafonoInterno() || {}
  } catch {
    stored = {}
  }
  return {
    ...stored,
    ...payload,
    driver: payload.driver || stored.driver,
    terminal: payload.terminal || stored.terminal,
    timeoutMs: payload.timeoutMs || stored.timeoutMs,
    configuracion: {
      ...stored,
      ...(payload.configuracion || {}),
      clave: stored.clave,
    },
  }
}

function resolveDriver(payload = {}) {
  const merged = withLocalConfig(payload)
  const code = normalizeDriver(merged.driver || merged.datafono)
  return { code, driver: drivers.get(code) || null, payload: merged }
}

async function status(payload = {}) {
  const { code, driver, payload: merged } = resolveDriver(payload)
  if (!code) {
    return resultError(
      'DATAFONO_NO_CONFIGURADO',
      'El puesto no tiene un datáfono configurado'
    )
  }
  if (!driver) {
    return resultError(
      'DRIVER_NO_DISPONIBLE',
      `No está instalado el driver del datáfono «${code}»`
    )
  }
  if (typeof driver.status !== 'function') {
    return { ok: true, available: true, driver: code, message: 'Driver disponible' }
  }
  try {
    return { driver: code, ...(await driver.status(merged)) }
  } catch (error) {
    return resultError('ESTADO_ERROR', String(error?.message || error))
  }
}

async function charge(payload = {}) {
  const operationId = text(payload.operationId)
  const amountCents = Number(payload.amountCents)
  if (!operationId) {
    return resultError('OPERACION_INVALIDA', 'operationId es obligatorio')
  }
  if (!Number.isInteger(amountCents) || amountCents <= 0) {
    return resultError('IMPORTE_INVALIDO', 'El importe debe indicarse en céntimos')
  }
  if (operations.has(operationId)) {
    return resultError('OPERACION_EN_CURSO', 'La operación ya está en curso', operationId)
  }
  if (approvedOperations.has(operationId)) {
    return approvedOperations.get(operationId)
  }

  const { code, driver, payload: merged } = resolveDriver(payload)
  if (!code) {
    return resultError(
      'DATAFONO_NO_CONFIGURADO',
      'El puesto no tiene un datáfono configurado',
      operationId
    )
  }
  if (!driver) {
    return resultError(
      'DRIVER_NO_DISPONIBLE',
      `No está instalado el driver del datáfono «${code}»`,
      operationId
    )
  }

  const controller = new AbortController()
  const timeoutMs = Math.min(
    Math.max(Number(merged.timeoutMs) || DEFAULT_TIMEOUT_MS, 5000),
    300000
  )
  const timer = setTimeout(() => controller.abort('timeout'), timeoutMs)
  operations.set(operationId, { controller, driver, payload: merged, code })

  try {
    const response = await driver.charge({ ...merged, driver: code }, controller.signal)
    const result = {
      ok: !!response?.ok,
      approved: !!response?.approved,
      operationId,
      driver: code,
      authorization: text(response?.authorization) || null,
      reference: text(response?.reference) || null,
      code: text(response?.code) || (response?.approved ? 'APROBADA' : 'RECHAZADA'),
      message: text(response?.message) || (response?.approved ? 'Cobro aprobado' : 'Cobro rechazado'),
      receipt: text(response?.receipt) || null,
    }
    if (result.ok && result.approved) {
      approvedOperations.set(operationId, result)
      if (approvedOperations.size > 500) {
        approvedOperations.delete(approvedOperations.keys().next().value)
      }
    }
    return result
  } catch (error) {
    const aborted = controller.signal.aborted
    return resultError(
      aborted ? 'OPERACION_CANCELADA' : 'ERROR_DRIVER',
      aborted
        ? controller.signal.reason === 'timeout'
          ? 'El datáfono no respondió dentro del tiempo permitido'
          : 'Cobro cancelado'
        : String(error?.message || error),
      operationId
    )
  } finally {
    clearTimeout(timer)
    operations.delete(operationId)
  }
}

async function cancel(payload = {}) {
  const operationId = text(payload.operationId)
  if (!operationId) {
    return resultError('OPERACION_INVALIDA', 'operationId es obligatorio')
  }
  const active = operations.get(operationId)
  if (!active) {
    return {
      ok: true,
      cancelled: false,
      operationId,
      message: 'La operación ya no está en curso',
    }
  }

  active.controller.abort('cancelled')
  if (typeof active.driver.cancel === 'function') {
    try {
      await active.driver.cancel({ ...active.payload, operationId })
    } catch {
      // La señal local ya está cancelada; el driver informará si procede.
    }
  }
  return { ok: true, cancelled: true, operationId, message: 'Cancelación solicitada' }
}

registerDriver('SERMEPA', require('./sermepa-driver'))

module.exports = { registerDriver, status, charge, cancel }
