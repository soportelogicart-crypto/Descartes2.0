/**
 * Drivers / stubs de periféricos.
 * ESC-POS tickets: escpos-encode + raw-print-win (cola Windows RAW).
 */

const { BrowserWindow } = require('electron')
const { buildTicketBuffer } = require('./escpos-encode')
const { isWindows, printRawWindows } = require('./raw-print-win')

/**
 * Impresoras del sistema Windows/macOS/Linux detectadas por Electron.
 * Indices 1-based como en legacy (npresor).
 */
async function listPrinters() {
  let win = BrowserWindow.getFocusedWindow() || BrowserWindow.getAllWindows()[0]
  let tempWin = null

  // El agente HTTP puede llamarse sin ventana enfocada: crear una oculta solo para enumerar.
  if (!win) {
    try {
      tempWin = new BrowserWindow({
        show: false,
        width: 100,
        height: 100,
        webPreferences: { sandbox: true },
      })
      win = tempWin
    } catch (err) {
      return {
        ok: false,
        stub: false,
        printers: [],
        message: 'No hay ventana Electron para enumerar impresoras',
      }
    }
  }

  try {
    const raw = await win.webContents.getPrintersAsync()
    const printers = (Array.isArray(raw) ? raw : [])
      .map((p, i) => {
        const name = String((p && (p.name || p.displayName)) || '').trim()
        return {
          id: i + 1,
          name,
          displayName: String((p && (p.displayName || p.name)) || name).trim(),
          description: String((p && p.description) || '').trim(),
          isDefault: !!(p && p.isDefault),
          status: p && p.status != null ? p.status : null,
        }
      })
      .filter((p) => p.name !== '')

    return {
      ok: true,
      stub: false,
      printers,
      message: printers.length ? `${printers.length} impresora(s) detectada(s)` : 'No se detectaron impresoras',
    }
  } catch (err) {
    console.error('[peripherals] listPrinters', err)
    return {
      ok: false,
      stub: false,
      printers: [],
      message: String(err && err.message ? err.message : err),
    }
  } finally {
    if (tempWin && !tempWin.isDestroyed()) {
      try {
        tempWin.destroy()
      } catch {
        /* ignore */
      }
    }
  }
}

/**
 * @param {object} payload
 * @param {Array<{id:number,name:string,displayName?:string,isDefault?:boolean}>} printers
 * @returns {{ name: string } | { error: string }}
 */
function resolvePrinterName(payload, printers) {
  const byName = String(
    (payload && (payload.impresora || payload.printerName || payload.deviceName)) || ''
  ).trim()

  const matchName = (wanted) => {
    const w = wanted.toLowerCase()
    const exact = printers.find(
      (p) => p.name.toLowerCase() === w || String(p.displayName || '').toLowerCase() === w
    )
    if (exact) return exact.name
    // Prefijo / contiene (nombres cortados en BD legacy nvarchar(8))
    const partial = printers.find((p) => {
      const n = p.name.toLowerCase()
      const d = String(p.displayName || '').toLowerCase()
      return n.includes(w) || d.includes(w) || (w.length >= 3 && (n.startsWith(w) || w.startsWith(n.slice(0, 8))))
    })
    return partial ? partial.name : null
  }

  if (byName) {
    const matched = matchName(byName)
    if (matched) return { name: matched }
    const disponibles = printers
      .slice(0, 8)
      .map((p) => p.displayName || p.name)
      .join(', ')
    return {
      error:
        `La impresora «${byName}» no existe en Windows (error típico si el puesto tiene el nombre lógico legacy «TICKETS»). ` +
        `En Puestos → Datos Generales → Tickets (impresora Windows) elija una impresora real (p. ej. TICKETW / TICKETU). Disponibles: ${disponibles || '(ninguna)'}`,
    }
  }

  const byId = payload && payload.impresoraId != null ? Number(payload.impresoraId) : NaN
  if (Number.isFinite(byId) && byId > 0) {
    const found = printers.find((p) => p.id === byId)
    if (found) return { name: found.name }
  }

  const def = printers.find((p) => p.isDefault)
  if (def) return { name: def.name }
  if (printers[0]) return { name: printers[0].name }
  return { error: 'No hay impresoras detectadas' }
}

/**
 * Imprime ticket térmico ESC/POS (RAW) en Windows.
 * payload: { texto, impresora?, impresoraId?, cortar?, abrirCajon?, ancho?, feed? }
 */
async function printTicket(payload) {
  const texto = payload && typeof payload.texto === 'string' ? payload.texto : ''
  if (!texto.trim()) {
    return { ok: false, stub: false, message: 'texto es obligatorio' }
  }

  if (!isWindows()) {
    return {
      ok: false,
      stub: false,
      message: 'Impresión RAW ESC/POS solo implementada en Windows por ahora',
    }
  }

  const listed = await listPrinters()
  const printers = listed.printers || []
  if (!printers.length) {
    return {
      ok: false,
      stub: false,
      message: listed.message || 'No hay impresoras detectadas en este equipo',
    }
  }

  const resolved = resolvePrinterName(payload || {}, printers)
  if (resolved.error) {
    return { ok: false, stub: false, message: resolved.error }
  }
  const printerName = resolved.name

  const buffer = buildTicketBuffer({
    texto,
    cortar: payload && payload.cortar !== false,
    abrirCajon: !!(payload && payload.abrirCajon),
    ancho: payload && payload.ancho != null ? Number(payload.ancho) : 42,
    feed: payload && payload.feed != null ? Number(payload.feed) : 4,
  })

  console.log('[peripherals] printTicket', {
    printerName,
    bytes: buffer.length,
    tipo: payload && payload.tipo,
  })

  const result = await printRawWindows(printerName, buffer)
  if (!result.ok && /1801/.test(result.message || '')) {
    return {
      ok: false,
      stub: false,
      impresora: printerName,
      message:
        `OpenPrinter 1801: nombre de impresora no válido («${printerName}»). ` +
        'En el puesto, campo Tickets, elija el nombre exacto de Windows (no «TICKETS»).',
    }
  }
  return {
    ok: !!result.ok,
    stub: false,
    impresora: printerName,
    message: result.message,
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

async function openCashDrawer() {
  console.log('[peripherals] openCashDrawer')
  return {
    ok: true,
    stub: true,
    message: 'Apertura de cajon no implementada (stub)',
  }
}

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

/**
 * Imprime HTML A4 en una impresora concreta (silent si hay deviceName).
 * payload: { html, impresora?, impresoraId?, silent? }
 */
async function printHtml(payload) {
  const fs = require('fs')
  const os = require('os')
  const path = require('path')
  const { BrowserWindow } = require('electron')

  const html = payload && typeof payload.html === 'string' ? payload.html : ''
  if (!html.trim()) {
    return { ok: false, stub: false, message: 'html es obligatorio' }
  }

  const listed = await listPrinters()
  const printers = listed.printers || []
  const resolved = resolvePrinterName(payload || {}, printers)
  if (resolved.error) {
    return { ok: false, stub: false, message: resolved.error }
  }

  const silent = payload && Object.prototype.hasOwnProperty.call(payload, 'silent')
    ? Boolean(payload.silent)
    : true

  // A4 en micrones (API Electron print).
  const pageSizeA4 = { width: 210000, height: 297000 }

  const tmpPath = path.join(
    os.tmpdir(),
    `descartes-a4-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.html`
  )
  fs.writeFileSync(tmpPath, html, 'utf8')

  const printOnce = (win, useSilent) =>
    new Promise((resolvePrint) => {
      win.webContents.print(
        {
          silent: useSilent,
          printBackground: true,
          deviceName: resolved.name,
          pageSize: pageSizeA4,
          landscape: false,
          scaleFactor: 100,
          margins: {
            marginType: 'custom',
            top: 0,
            bottom: 0,
            left: 0,
            right: 0,
          },
        },
        (success, failureReason) => {
          resolvePrint({ success: !!success, failureReason: failureReason || '' })
        }
      )
    })

  try {
    const win = new BrowserWindow({
      show: false,
      width: 794,
      height: 1123,
      webPreferences: { sandbox: true, offscreen: false },
    })

    try {
      await win.loadFile(tmpPath)
      // Esperar layout real (evita "page size is empty").
      await win.webContents.executeJavaScript(
        `Promise.resolve(document.fonts ? document.fonts.ready : null).then(function () {
          var b = document.body;
          return b ? { w: b.scrollWidth, h: b.scrollHeight } : { w: 0, h: 0 };
        })`
      )
      await new Promise((r) => setTimeout(r, 350))

      let result = await printOnce(win, silent)
      // Si el driver rechaza silent (página vacía), reintentar con diálogo.
      if (
        !result.success &&
        silent &&
        /empty|invalid|page size/i.test(String(result.failureReason || ''))
      ) {
        result = await printOnce(win, false)
      }

      if (!result.success) {
        return {
          ok: false,
          stub: false,
          impresora: resolved.name,
          message: result.failureReason || 'Error al imprimir A4',
        }
      }
      return {
        ok: true,
        stub: false,
        impresora: resolved.name,
        message: `Enviado a ${resolved.name}`,
      }
    } finally {
      try {
        if (!win.isDestroyed()) win.destroy()
      } catch {
        /* ignore */
      }
    }
  } catch (err) {
    return {
      ok: false,
      stub: false,
      message: String(err && err.message ? err.message : err),
    }
  } finally {
    try {
      fs.unlinkSync(tmpPath)
    } catch {
      /* ignore */
    }
  }
}

module.exports = {
  listPrinters,
  printTicket,
  printHtml,
  printLabel,
  openCashDrawer,
  readCashDrawer,
  readScale,
  displayPrice,
}
