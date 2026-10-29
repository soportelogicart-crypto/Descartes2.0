const { app, BrowserWindow, ipcMain, shell } = require('electron')
const path = require('path')
const fs = require('fs')
const localConfig = require('./local-config')
const logos = require('./logos')
const tecladoImagenes = require('./teclado-imagenes')
const peripherals = require('./peripherals')
const dispositivoAgente = require('./dispositivo-agente')
const { bindWindowMenu, clearMenu } = require('./app-menu')
const { startViteDevServer, stopViteDevServer, waitForUrl } = require('./dev-server')
const uiLocal = require('./offline/ui-local')
const offline = require('./offline')

// Solo --dev arranca Vite. Sin ese flag se usa config.json (hosting / producción),
// también al ejecutar `electron .` sin empaquetar.
const isDev = process.argv.includes('--dev')
const openDevTools = process.argv.includes('--devtools')
const DEV_URL = 'http://127.0.0.1:5173'
// El renderer usa el proxy de Vite, pero el monitor no debe hacerlo: al parar
// Apache Vite escribe un error ruidoso en CMD por cada ping.
const DEV_API_BASE = 'http://127.0.0.1/descartes-api-dev/public'

uiLocal.registrarEsquema()

function appIconPath() {
  const candidates = [
    path.join(process.resourcesPath || '', 'icono', 'icon.png'),
    path.join(__dirname, '..', 'icono', 'icon.png'),
  ]
  return candidates.find((file) => fs.existsSync(file))
}

function parseWindowFeatures(features) {
  const out = { width: 940, height: 800 }
  if (!features || typeof features !== 'string') return out
  for (const part of features.split(',')) {
    const [key, raw] = part.split('=').map((s) => String(s || '').trim())
    const n = Number(raw)
    if (key === 'width' && n > 200) out.width = Math.round(n)
    if (key === 'height' && n > 200) out.height = Math.round(n)
  }
  return out
}

function loadShellConfig() {
  const candidates = [
    path.join(process.resourcesPath || '', 'config.json'),
    path.join(app.getAppPath(), 'config.json'),
    path.join(__dirname, '..', 'config.json'),
  ]
  for (const file of candidates) {
    try {
      if (fs.existsSync(file)) {
        console.log(`[descartes-electron] Config UI: ${file}`)
        return JSON.parse(fs.readFileSync(file, 'utf8'))
      }
    } catch {
      // ignore
    }
  }
  return {
    appUrl: 'http://127.0.0.1:5173',
    window: { width: 1280, height: 800, maximized: true, fullscreen: false },
  }
}

/** Base real a la que van las llamadas /api del proceso principal. */
function apiBaseDe(cfg) {
  if (isDev) return DEV_API_BASE
  try {
    return new URL(cfg.appUrl || DEV_URL).origin
  } catch {
    return DEV_URL
  }
}

async function resolveAppUrl(cfg) {
  if (isDev) {
    try {
      await waitForUrl(DEV_URL, { timeoutMs: 1500 })
      console.log('[descartes-electron] UI ya estaba en marcha')
    } catch {
      await startViteDevServer()
    }
    return { url: DEV_URL, local: false }
  }
  const appUrl = cfg.appUrl || DEV_URL
  if (cfg.uiLocal === false) {
    return { url: appUrl, local: false }
  }
  const res = await uiLocal.actualizar(appUrl)
  if (res.actualizado) {
    console.log(`[descartes-electron] UI local actualizada a ${res.version}`)
  } else if (res.motivo) {
    console.log(`[descartes-electron] UI local sin actualizar: ${res.motivo}`)
  }
  if (uiLocal.disponible()) {
    return { url: uiLocal.URL_LOCAL, local: true }
  }
  // Sin copia local todavía (hosting sin ui-manifest.json): como antes.
  return { url: appUrl, local: false }
}

async function createWindow() {
  const cfg = loadShellConfig()
  const winCfg = cfg.window || {}
  const { url, local } = await resolveAppUrl(cfg)
  const fullscreen = !!winCfg.fullscreen
  // Por defecto ventana maximizada (completa). Solo se desactiva si maximized: false.
  const maximized = winCfg.maximized !== false && !fullscreen

  const icon = appIconPath()
  const win = new BrowserWindow({
    width: winCfg.width || 1280,
    height: winCfg.height || 800,
    fullscreen,
    show: false,
    title: 'Descartes 2.0',
    icon,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
    },
  })

  win.once('ready-to-show', () => {
    if (maximized) win.maximize()
    win.show()
  })
  bindWindowMenu(win)

  win.webContents.setWindowOpenHandler(({ url: target, features }) => {
    // window.open('about:blank') → ventana propia (previsualización).
    // Sin parent la ventana nueva queda detrás de la principal maximizada.
    const esBlank = !target || target === 'about:blank' || String(target).startsWith('about:blank')
    if (esBlank) {
      const parsed = parseWindowFeatures(features)
      return {
        action: 'allow',
        overrideBrowserWindowOptions: {
          parent: win,
          modal: false,
          show: true,
          autoHideMenuBar: true,
          title: 'Descartes 2.0',
          width: parsed.width,
          height: parsed.height,
        },
      }
    }
    shell.openExternal(target)
    return { action: 'deny' }
  })

  console.log(`[descartes-electron] Cargando UI en la ventana Electron: ${url}`)
  console.log(`[descartes-electron] Config equipo: ${localConfig.equipoPath()}`)

  offline.adjuntarVentana(win)

  if (!isDev && !local) {
    // Sin esto Chromium reutiliza un index.html viejo y no ve el JS nuevo del hosting.
    try {
      await win.webContents.session.clearCache()
    } catch {
      // ignore
    }
    await win.loadURL(url, {
      extraHeaders: 'Cache-Control: no-cache\nPragma: no-cache\n',
    })
  } else {
    await win.loadURL(url)
  }

  if (openDevTools) {
    win.webContents.openDevTools({ mode: 'detach' })
  }
}

function registerIpc() {
  ipcMain.handle('equipo:get', () => localConfig.readEquipo())
  ipcMain.handle('equipo:hostname', () => localConfig.defaultEquipoId())
  ipcMain.handle('equipo:set', (_event, payload) => localConfig.writeEquipo(payload || {}))
  ipcMain.handle('equipo:setDatafono', (_event, payload) => localConfig.writeDatafono(payload || {}))
  ipcMain.handle('equipo:clear', () => localConfig.clearEquipo())
  ipcMain.handle('instalacion:get', () => localConfig.readVinculo())
  ipcMain.handle('instalacion:set', (_event, payload) => localConfig.writeVinculo(payload || {}))
  ipcMain.handle('instalacion:clear', () => localConfig.clearVinculo())
  ipcMain.handle('logos:empresa', (_event, codigo) => logos.resolveEmpresa(codigo))
  ipcMain.handle('logos:guardar', (event, codigo) => {
    const win = BrowserWindow.fromWebContents(event.sender)
    return logos.saveEmpresaFromDialog(codigo, win)
  })
  ipcMain.handle('logos:abrirCarpeta', () => logos.openLogosFolder())
  ipcMain.handle('logos:directorio', () => logos.getLogosDirectory())
  ipcMain.handle('teclado:imagen', (_event, ruta) => tecladoImagenes.leerImagen(ruta))
  ipcMain.handle('teclado:elegirImagen', (event) => {
    const win = BrowserWindow.fromWebContents(event.sender)
    return tecladoImagenes.elegirImagen(win)
  })
  // En Windows, tras alert/confirm nativo la página no recupera el teclado hasta cambiar de ventana.
  ipcMain.on('ventana:reenfocar', (event) => {
    const win = BrowserWindow.fromWebContents(event.sender)
    if (!win || win.isDestroyed()) return
    win.blur()
    win.focus()
    win.webContents.focus()
  })

  ipcMain.handle('peripheral:listPrinters', () => peripherals.listPrinters())
  ipcMain.handle('peripheral:printTicket', (_event, payload) => peripherals.printTicket(payload))
  ipcMain.handle('peripheral:printHtml', (_event, payload) => peripherals.printHtml(payload))
  ipcMain.handle('peripheral:htmlToPdf', (_event, payload) => peripherals.htmlToPdf(payload || {}))
  ipcMain.handle('peripheral:printLabel', (_event, payload) => peripherals.printLabel(payload))
  ipcMain.handle('peripheral:openCashDrawer', (_event, payload) =>
    peripherals.openCashDrawer(payload || {})
  )
  ipcMain.handle('peripheral:readCashDrawer', (_event, payload) => peripherals.readCashDrawer(payload || {}))
  ipcMain.handle('peripheral:readScale', () => peripherals.readScale())
  ipcMain.handle('peripheral:displayPrice', (_event, payload) => peripherals.displayPrice(payload))
  ipcMain.handle('peripheral:paymentTerminalStatus', (_event, payload) =>
    peripherals.paymentTerminalStatus(payload || {})
  )
  ipcMain.handle('peripheral:paymentTerminalCharge', (_event, payload) =>
    peripherals.paymentTerminalCharge(payload || {})
  )
  ipcMain.handle('peripheral:paymentTerminalCancel', (_event, payload) =>
    peripherals.paymentTerminalCancel(payload || {})
  )
}

app.whenReady().then(async () => {
  // Quitar el menu por defecto de Electron cuanto antes.
  clearMenu()
  logos.getLogosDirectory()
  registerIpc()
  dispositivoAgente.start()
  peripherals.precalentarImpresionTicket()
  const shellCfg = loadShellConfig()
  uiLocal.registrarManejador(apiBaseDe(shellCfg))
  try {
    await offline.iniciar({ apiBase: apiBaseDe(shellCfg) })
  } catch (err) {
    console.error('[descartes-electron] Modo local no disponible:', err)
  }
  try {
    await createWindow()
  } catch (err) {
    console.error('[descartes-electron] No se pudo iniciar la UI:', err)
    app.quit()
    return
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow()
    }
  })
})

app.on('before-quit', () => {
  offline.detener()
  peripherals.cerrarImpresionTicket()
  dispositivoAgente.stop()
  stopViteDevServer()
})

app.on('window-all-closed', () => {
  dispositivoAgente.stop()
  stopViteDevServer()
  if (process.platform !== 'darwin') {
    app.quit()
  }
})
