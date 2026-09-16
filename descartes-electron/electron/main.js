const { app, BrowserWindow, ipcMain, shell } = require('electron')
const path = require('path')
const fs = require('fs')
const localConfig = require('./local-config')
const logos = require('./logos')
const peripherals = require('./peripherals')
const dispositivoAgente = require('./dispositivo-agente')
const { bindWindowMenu, clearMenu } = require('./app-menu')
const { startViteDevServer, stopViteDevServer, waitForUrl } = require('./dev-server')

// Solo --dev arranca Vite. Sin ese flag se usa config.json (hosting / producción),
// también al ejecutar `electron .` sin empaquetar.
const isDev = process.argv.includes('--dev')
const openDevTools = process.argv.includes('--devtools')

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

async function resolveAppUrl(cfg) {
  if (isDev) {
    const devUrl = 'http://127.0.0.1:5173'
    try {
      await waitForUrl(devUrl, { timeoutMs: 1500 })
      console.log('[descartes-electron] UI ya estaba en marcha')
    } catch {
      await startViteDevServer()
    }
    return devUrl
  }
  return cfg.appUrl || 'http://127.0.0.1:5173'
}

async function createWindow() {
  const cfg = loadShellConfig()
  const winCfg = cfg.window || {}
  const url = await resolveAppUrl(cfg)
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

  if (!isDev) {
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
  ipcMain.handle('equipo:clear', () => localConfig.clearEquipo())
  ipcMain.handle('logos:empresa', (_event, codigo) => logos.resolveEmpresa(codigo))

  ipcMain.handle('peripheral:listPrinters', () => peripherals.listPrinters())
  ipcMain.handle('peripheral:printTicket', (_event, payload) => peripherals.printTicket(payload))
  ipcMain.handle('peripheral:printHtml', (_event, payload) => peripherals.printHtml(payload))
  ipcMain.handle('peripheral:printLabel', (_event, payload) => peripherals.printLabel(payload))
  ipcMain.handle('peripheral:openCashDrawer', () => peripherals.openCashDrawer())
  ipcMain.handle('peripheral:readCashDrawer', (_event, payload) => peripherals.readCashDrawer(payload || {}))
  ipcMain.handle('peripheral:readScale', () => peripherals.readScale())
  ipcMain.handle('peripheral:displayPrice', (_event, payload) => peripherals.displayPrice(payload))
}

app.whenReady().then(async () => {
  // Quitar el menu por defecto de Electron cuanto antes.
  clearMenu()
  registerIpc()
  dispositivoAgente.start()
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
