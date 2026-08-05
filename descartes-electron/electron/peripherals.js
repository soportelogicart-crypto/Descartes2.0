/**
 * Stubs de perifericos. Aqui se conectaran drivers reales (ESC-POS, Cashlogy, PayDesk, OPOS…).
 * La UI/API hablan con estas funciones via el agente local (HTTP) o el puente IPC.
 */

async function printTicket(payload) {
  console.log('[peripherals] printTicket', payload)
  const texto = payload && typeof payload.texto === 'string' ? payload.texto : ''
  return {
    ok: true,
    stub: true,
    message:
      texto.length > 0
        ? `Impresion termica stub (${texto.length} caracteres). Conecte driver ESC-POS.`
        : 'Impresion de ticket no implementada (stub)',
  }
}

async function printLabel(payload) {
  console.log('[peripherals] printLabel', payload)
  return {
    ok: true,
    stub: true,
    message: 'Impresion de etiqueta no implementada (stub)',
  }
}

/** Apertura mecanica (kick) — no es lectura de inventario. */
async function openCashDrawer() {
  console.log('[peripherals] openCashDrawer')
  return {
    ok: true,
    stub: true,
    message: 'Apertura de cajon no implementada (stub)',
  }
}

/**
 * Lectura de inventario / efectivo del reciclador (Cashlogy, PayDesk, OPOS CashCounts).
 * Devuelve importe total y vector monedas[0..19] = importes Moneda01..20.
 */
async function readCashDrawer(payload = {}) {
  console.log('[peripherals] readCashDrawer', payload)
  const formaPago = (payload && payload.formaPago) || 'EU'
  return {
    ok: true,
    stub: true,
    formaPago,
    importe: 0,
    monedas: Array(20).fill(0),
    message:
      'Lectura de cajon no implementada (stub). Conecte driver Cashlogy/PayDesk/OPOS.',
  }
}

async function readScale() {
  console.log('[peripherals] readScale')
  return {
    ok: true,
    stub: true,
    weight: null,
    unit: 'kg',
    message: 'Lectura de balanza no implementada (stub)',
  }
}

async function displayPrice(payload) {
  console.log('[peripherals] displayPrice', payload)
  return {
    ok: true,
    stub: true,
    message: 'Visor de cliente no implementado (stub)',
  }
}

module.exports = {
  printTicket,
  printLabel,
  openCashDrawer,
  readCashDrawer,
  readScale,
  displayPrice,
}
