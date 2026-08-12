const { app, BrowserWindow, ipcMain, shell } = require('electron')
const path = require('path')
const fs = require('fs')
const localConfig = require('./local-config')
const peripherals = require('./peripherals')
const dispositivoAgente = require('./dispositivo-agente')
const { bindWindowMenu, clearMenu } = require('./app-menu')
const { startViteDevServer, stopViteDevServer, waitForUrl } = require('./dev-server')

const isDev = process.argv.includes('--dev') || !app.isPackaged
const openDevTools = process.argv.includes('--devtools')

function loadShellConfig() {
  const candidates = [
    path.join(process.resourcesPath || '', 'config.json'),
    path.join(app.getAppPath(), 'config.json'),
    path.join(__dirname, '..', 'config.json'),
  ]
  for (const file of candidates) {
    try {
      if (fs.existsSync(file)) {
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

  const win = new BrowserWindow({
    width: winCfg.width || 1280,
    height: winCfg.height || 800,
    fullscreen,
    show: false,
    title: 'Descartes 2.0',
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

  win.webContents.setWindowOpenHandler(({ url: target }) => {
    shell.openExternal(target)
    return { action: 'deny' }
  })

  console.log(`[descartes-electron] Cargando UI en la ventana Electron: ${url}`)
  console.log(`[descartes-electron] Config equipo: ${localConfig.equipoPath()}`)
  await win.loadURL(url)

  if (openDevTools) {
    win.webContents.openDevTools({ mode: 'detach' })
  }
}

function registerIpc() {
  ipcMain.handle('equipo:get', () => localConfig.readEquipo())
  ipcMain.handle('equipo:hostname', () => localConfig.defaultEquipoId())
  ipcMain.handle('equipo:set', (_event, payload) => localConfig.writeEquipo(payload || {}))
  ipcMain.handle('equipo:clear', () => localConfig.clearEquipo())

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
