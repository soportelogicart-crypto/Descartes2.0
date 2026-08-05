const { app, BrowserWindow, ipcMain, shell, dialog } = require('electron')
const path = require('path')
const fs = require('fs')
const { pathToFileURL } = require('url')
const { loadConfig, saveConfig, getPublicConfig } = require('./config')
const { getPool, closePool, query, resetPool } = require('./db')
const { generar } = require('./services/abcVentasService')
const { login } = require('./services/authService')
const { buscar: buscarLookup } = require('./services/lookupService')
const { startViteDevServer, stopViteDevServer, waitForUrl } = require('./dev-server')
const { bindWindowMenu } = require('./app-menu')

const isDev = process.argv.includes('--dev') || !app.isPackaged
const openDevTools = process.argv.includes('--devtools')

function appIconPath() {
  return path.join(__dirname, '..', 'icono', 'icono.ico')
}

/** @type {{ codigo: string, nombre: string, usuario: string } | null} */
let sessionUser = null

function requireSession() {
  if (!sessionUser) {
    const err = new Error('Sesión no iniciada')
    err.code = 'AUTH'
    throw err
  }
  return sessionUser
}

async function resolveAppUrl() {
  if (isDev) {
    const devUrl = 'http://127.0.0.1:5180'
    try {
      await waitForUrl(devUrl, { timeoutMs: 1500 })
      console.log('[abc-ventas] UI ya estaba en marcha')
    } catch {
      await startViteDevServer()
    }
    return `${devUrl}/login`
  }

  const indexHtml = path.join(__dirname, '..', 'frontend', 'dist', 'index.html')
  return pathToFileURL(indexHtml).href
}

async function createWindow() {
  const cfg = loadConfig()
  const winCfg = cfg.window || {}
  const url = await resolveAppUrl()
  const icon = appIconPath()

  const win = new BrowserWindow({
    width: winCfg.width || 1400,
    height: winCfg.height || 900,
    fullscreen: !!winCfg.fullscreen,
    show: false,
    title: 'ABC Ventas',
    icon: fs.existsSync(icon) ? icon : undefined,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: false,
    },
  })

  bindWindowMenu(win)
  win.once('ready-to-show', () => win.show())

  win.webContents.setWindowOpenHandler(({ url: target }) => {
    shell.openExternal(target)
    return { action: 'deny' }
  })

  console.log(`[abc-ventas] Cargando UI: ${url}`)
  await win.loadURL(url)

  if (openDevTools) {
    win.webContents.openDevTools({ mode: 'detach' })
  }
}

function registerIpc() {
  ipcMain.handle('auth:login', async (_event, payload) => {
    const user = await login(payload?.usuario, payload?.password)
    sessionUser = user
    return user
  })

  ipcMain.handle('auth:logout', async () => {
    sessionUser = null
    return { ok: true }
  })

  ipcMain.handle('auth:me', async () => sessionUser)

  ipcMain.handle('config:get', async () => getPublicConfig())

  ipcMain.handle('config:save', async (_event, payload) => {
    const saved = saveConfig(payload || {})
    try {
      await resetPool()
    } catch (err) {
      console.error('[abc-ventas] Reconexión SQL tras guardar config:', err.message)
      throw new Error(`Config guardada, pero no se pudo conectar: ${err.message}`)
    }
    return saved
  })

  ipcMain.handle('export:save', async (_event, payload) => {
    const { canceled, filePath } = await dialog.showSaveDialog({
      defaultPath: payload?.defaultPath || 'export',
      filters: payload?.filters || [{ name: 'All', extensions: ['*'] }],
    })
    if (canceled || !filePath) {
      return { ok: false }
    }
    const bytes = Buffer.from(payload?.data || [])
    fs.writeFileSync(filePath, bytes)
    return { ok: true, filePath }
  })

  ipcMain.handle('abc:obtener', async (_event, filtros) => {
    const user = requireSession()
    try {
      // Cada usuario solo puede ver su propio ABC (codigo = Vendedores.Codigo)
      const safe = { ...(filtros || {}) }
      safe.vendedorDesde = user.codigo
      safe.vendedorHasta = user.codigo
      return await generar(safe)
    } catch (err) {
      console.error('[abc-ventas] Error informe:', err)
      const message = err.message || String(err)
      const wrapped = new Error(message)
      wrapped.code = err.code || 'ERROR'
      throw wrapped
    }
  })

  ipcMain.handle('lookup:buscar', async (_event, payload) => {
    requireSession()
    try {
      return await buscarLookup(payload?.entidad, payload?.q, { limit: payload?.limit })
    } catch (err) {
      console.error('[abc-ventas] Error lookup:', err)
      const message = err.message || String(err)
      const wrapped = new Error(message)
      wrapped.code = err.code || 'ERROR'
      throw wrapped
    }
  })

  ipcMain.handle('abc:testDb', async () => {
    try {
      await getPool()
      const result = await query('SELECT 1 AS ok')
      return { ok: true, result: result.recordset }
    } catch (err) {
      return { ok: false, error: err.message || String(err) }
    }
  })
}

app.whenReady().then(async () => {
  if (process.platform === 'win32') {
    app.setAppUserModelId('com.descartes.abcventas')
  }
  registerIpc()
  try {
    getPool().catch((err) => {
      console.error('[abc-ventas] No se pudo conectar a SQL Server:', err.message)
    })
    await createWindow()
  } catch (err) {
    console.error('[abc-ventas] No se pudo iniciar:', err)
    app.quit()
    return
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) {
      createWindow()
    }
  })
})

app.on('before-quit', async () => {
  stopViteDevServer()
  await closePool()
})

app.on('window-all-closed', () => {
  stopViteDevServer()
  if (process.platform !== 'darwin') {
    app.quit()
  }
})
