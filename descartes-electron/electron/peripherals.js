/**
 * Drivers / stubs de periféricos.
 * Tickets: ESC/POS RAW (Epson TM TICKETU/TICKETW, datatype RAW).
 * A4/etiquetas: GDI vía webContents.print.
 */

const { BrowserWindow } = require('electron')
const { execFile } = require('child_process')
const { promisify } = require('util')
const { buildTicketBuffer } = require('./escpos-encode')
const { isWindows, printRawWindows } = require('./raw-print-win')

const execFileAsync = promisify(execFile)

function psQuote(value) {
  return String(value || '').replace(/'/g, "''")
}

async function runPs(command, timeout = 15000) {
  const { stdout, stderr } = await execFileAsync(
    'powershell.exe',
    ['-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', command],
    { windowsHide: true, timeout, maxBuffer: 1024 * 1024 }
  )
  return { stdout: String(stdout || ''), stderr: String(stderr || '') }
}

/** El monitor Epson ESDPRT retiene trabajos si BIDI espera estado y la cola está NotAvailable. */
async function disablePrinterBidi(printerName) {
  try {
    await execFileAsync(
      'rundll32.exe',
      [`printui.dll,PrintUIEntry /Xs /n "${printerName}" attributes -enablebidi`],
      { windowsHide: true, timeout: 10000 }
    )
  } catch {
    /* ignore */
  }
}

/** Evita que el spooler deje el ticket en «Printing, Retained» sin enviarlo al USB. */
async function enableDirectPrint(printerName) {
  try {
    await execFileAsync(
      'rundll32.exe',
      [`printui.dll,PrintUIEntry /Xs /n "${printerName}" attributes +direct`],
      { windowsHide: true, timeout: 10000 }
    )
  } catch {
    /* ignore */
  }
}

async function purgeRetainedJobs(printerName) {
  const n = psQuote(printerName)
  try {
    await runPs(
      `Get-PrintJob -PrinterName '${n}' -ErrorAction SilentlyContinue | ForEach-Object {` +
        ` $st = [string]$_.JobStatus;` +
        ` if ($st -match 'Retain|Error|Offline|Paused') {` +
        `   Remove-PrintJob -PrinterName '${n}' -ID $_.Id -ErrorAction SilentlyContinue` +
        ` }` +
        `}`
    )
  } catch {
    /* ignore */
  }
}

async function printerStatusName(printerName) {
  const n = psQuote(printerName)
  try {
    const { stdout } = await runPs(
      `$p = Get-Printer -Name '${n}' -ErrorAction SilentlyContinue; if ($p) { $p.PrinterStatus.ToString() }`
    )
    return stdout.trim()
  } catch {
    return ''
  }
}

async function hasStuckJob(printerName) {
  const n = psQuote(printerName)
  try {
    const { stdout } = await runPs(
      `$j = Get-PrintJob -PrinterName '${n}' -ErrorAction SilentlyContinue |` +
        ` Where-Object { $_.PagesPrinted -eq 0 -and ([string]$_.JobStatus) -match 'Print|Retain|Error' };` +
        ` if ($j) { 'YES' } else { 'NO' }`
    )
    return stdout.trim() === 'YES'
  } catch {
    return false
  }
}

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
 * Ticket ESC/POS RAW. TICKETU/TICKETW (Epson TM Receipt) tienen datatype RAW;
 * un HTML/GDI (EMF) se queda detrás o retenido en ESDPRT.
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

  await disablePrinterBidi(printerName)
  await enableDirectPrint(printerName)
  await purgeRetainedJobs(printerName)

  const buffer = buildTicketBuffer({
    texto,
    cortar: payload && payload.cortar !== false,
    abrirCajon: !!(payload && payload.abrirCajon),
    ancho: payload && payload.ancho != null ? Number(payload.ancho) : 42,
    feed: payload && payload.feed != null ? Number(payload.feed) : 4,
  })

  console.log('[peripherals] printTicket RAW', {
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
        'En el puesto, campo Tickets, elija el nombre exacto de Windows (TICKETU / TICKETW).',
    }
  }
  if (!result.ok) {
    return { ok: false, stub: false, impresora: printerName, message: result.message }
  }

  await new Promise((r) => setTimeout(r, 8000))
  if (await hasStuckJob(printerName)) {
    const status = await printerStatusName(printerName)
    return {
      ok: false,
      stub: false,
      impresora: printerName,
      message:
        `El ticket sigue en la cola de «${printerName}» sin imprimirse (Windows: ${status || '?'}). ` +
        'Abra esa impresora en Windows, cancele todos los documentos, apague y encienda la TM-T88IV ' +
        'y vuelva a probar. El reinicio de la cola ya no hace falta.',
    }
  }

  return {
    ok: true,
    stub: false,
    impresora: printerName,
    message: result.message,
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
  return printHtmlSized(payload || {}, {
    widthMm: 210,
    heightMm: 297,
    landscape: false,
    copies: 1,
    tmpPrefix: 'descartes-a4',
    errorLabel: 'A4',
  })
}

/**
 * Imprime etiqueta HTML con tamaño de página en mm (misma ruta que printHtml).
 * payload: {
 *   html,
 *   impresora?, impresoraId?, silent?,
 *   pageWidthMm|widthMm?, pageHeightMm|heightMm?,
 *   copies?, landscape?
 * }
 */
async function printLabel(payload) {
  const p = payload && typeof payload === 'object' ? payload : {}
  const widthMm = Number(p.pageWidthMm ?? p.widthMm)
  const heightMm = Number(p.pageHeightMm ?? p.heightMm)
  if (!Number.isFinite(widthMm) || widthMm < 5 || !Number.isFinite(heightMm) || heightMm < 5) {
    return {
      ok: false,
      stub: false,
      message: 'pageWidthMm y pageHeightMm son obligatorios (mm, ≥ 5)',
    }
  }
  let copies = Math.trunc(Number(p.copies ?? 1))
  if (!Number.isFinite(copies) || copies < 1) copies = 1
  if (copies > 500) copies = 500

  return printHtmlSized(p, {
    widthMm,
    heightMm,
    landscape: Boolean(p.landscape),
    copies,
    tmpPrefix: 'descartes-label',
    errorLabel: 'etiqueta',
  })
}

/**
 * Núcleo compartido: HTML → BrowserWindow → webContents.print con pageSize en micrones.
 * @param {object} payload
 * @param {{ widthMm: number, heightMm: number, landscape: boolean, copies: number, tmpPrefix: string, errorLabel: string }} opts
 */
async function printHtmlSized(payload, opts) {
  const fs = require('fs')
  const os = require('os')
  const path = require('path')
  const { BrowserWindow: BW } = require('electron')

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

  // Electron pageSize: micrones (1 mm = 1000 micrones).
  const pageSize = {
    width: Math.round(opts.widthMm * 1000),
    height: Math.round(opts.heightMm * 1000),
  }

  const pxPerMm = 96 / 25.4
  const winW = Math.max(80, Math.round(opts.widthMm * pxPerMm))
  const winH = Math.max(80, Math.round(opts.heightMm * pxPerMm))

  const tmpPath = path.join(
    os.tmpdir(),
    `${opts.tmpPrefix}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}.html`
  )
  fs.writeFileSync(tmpPath, html, 'utf8')

  const printOnce = (win, useSilent) =>
    new Promise((resolvePrint) => {
      win.webContents.print(
        {
          silent: useSilent,
          printBackground: true,
          deviceName: resolved.name,
          pageSize,
          landscape: opts.landscape,
          copies: opts.copies,
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
    const win = new BW({
      show: false,
      width: winW,
      height: winH,
      webPreferences: { sandbox: true, offscreen: false },
    })

    try {
      await win.loadFile(tmpPath)
      await win.webContents.executeJavaScript(
        `Promise.resolve(document.fonts ? document.fonts.ready : null).then(function () {
          var b = document.body;
          return b ? { w: b.scrollWidth, h: b.scrollHeight } : { w: 0, h: 0 };
        })`
      )
      await new Promise((r) => setTimeout(r, 350))

      let result = await printOnce(win, silent)
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
          pageWidthMm: opts.widthMm,
          pageHeightMm: opts.heightMm,
          copies: opts.copies,
          message: result.failureReason || `Error al imprimir ${opts.errorLabel}`,
        }
      }
      return {
        ok: true,
        stub: false,
        impresora: resolved.name,
        pageWidthMm: opts.widthMm,
        pageHeightMm: opts.heightMm,
        copies: opts.copies,
        message:
          opts.copies > 1
            ? `Enviado a ${resolved.name} (${opts.copies} copias, ${opts.widthMm}×${opts.heightMm} mm)`
            : `Enviado a ${resolved.name} (${opts.widthMm}×${opts.heightMm} mm)`,
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
