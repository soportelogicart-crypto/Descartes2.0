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
    // La UI vive en /descartes/ y el proxy de /api cuelga de esa carpeta.
    // El origen solo (sin la ruta) apunta a la raíz, que no es la API.
    const u = new URL(cfg.appUrl || DEV_URL)
    return u.origin + u.pathname.replace(/\/+$/, '')
  } catch {
    return DEV_URL
  }
}

function crearProgreso() {
  let win = null
  let listo = false
  let ultimo = null

  const pintar = () => {
    if (!listo || !ultimo || !win || win.isDestroyed()) return
    const hecho = Number(ultimo.hecho) || 0
    const total = Number(ultimo.total) || 0
    const nombre = JSON.stringify(String(ultimo.nombre || ''))
    win.webContents.executeJavaScript(`window.ponerProgreso(${hecho}, ${total}, ${nombre})`).catch(() => {})
  }

  return {
    actualizar(info) {
      ultimo = info
      if (!win) {
        const icon = appIconPath()
        win = new BrowserWindow({
          width: 460,
          height: 196,
          resizable: false,
          minimizable: false,
          maximizable: false,
          fullscreenable: false,
          autoHideMenuBar: true,
          center: true,
          show: false,
          alwaysOnTop: true,
          title: 'Descartes 2.0',
          backgroundColor: '#f7f5f2',
          ...(icon ? { icon } : {}),
          webPreferences: {
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
          },
        })
        win.once('ready-to-show', () => {
          if (win && !win.isDestroyed()) win.show()
        })
        win.webContents.once('did-finish-load', () => {
          listo = true
          pintar()
        })
        win.loadFile(path.join(__dirname, 'progreso-ui.html'))
      }
      pintar()
    },
    cerrar() {
      if (win && !win.isDestroyed()) win.destroy()
      win = null
      listo = false
    },
  }
}

async function resolveAppUrl(cfg, onProgreso) {
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
  const res = await uiLocal.actualizar(appUrl, { onProgreso })
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
  const progreso = crearProgreso()
  let url
  let local
  try {
    ;({ url, local } = await resolveAppUrl(cfg, (info) => progreso.actualizar(info)))
  } catch (err) {
    progreso.cerrar()
    throw err
  }
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
    progreso.cerrar()
  })
  win.webContents.on('did-fail-load', (_e, _code, _desc, _validatedURL, isMainFrame) => {
    if (isMainFrame !== false) progreso.cerrar()
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
  // #region agent log
  fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'0d3608'},body:JSON.stringify({sessionId:'0d3608',location:'main.js:createWindow',message:'url de arranque',data:{url,local,apiBase:apiBaseDe(cfg)},timestamp:Date.now(),hypothesisId:'A'})}).catch(()=>{});
  const anotarNavegacion = (navUrl) => {
    const u = String(navUrl || '')
    if (!/mantenimiento|login|instalacion/.test(u)) return
    fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'0d3608'},body:JSON.stringify({sessionId:'0d3608',location:'main.js:navegacion',message:'cambio de pantalla',data:{url:u.slice(0,220)},timestamp:Date.now(),hypothesisId:'D'})}).catch(()=>{});
  }
  win.webContents.on('did-navigate-in-page', (_e, navUrl) => anotarNavegacion(navUrl))
  win.webContents.on('did-fail-load', (_e, code, desc, validatedURL) => {
    fetch('http://127.0.0.1:7584/ingest/0f1f3667-dd3e-47f6-820d-e79b40345fcc',{method:'POST',headers:{'Content-Type':'application/json','X-Debug-Session-Id':'0d3608'},body:JSON.stringify({sessionId:'0d3608',location:'main.js:did-fail-load',message:'fallo de carga',data:{code,desc:String(desc||'').slice(0,160),url:String(validatedURL||'').slice(0,220)},timestamp:Date.now(),hypothesisId:'H'})}).catch(()=>{});
  })
  // #endregion
  console.log(`[descartes-electron] Config equipo: ${localConfig.equipoPath()}`)

  offline.adjuntarVentana(win)

  try {
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
  } catch (err) {
    progreso.cerrar()
    throw err
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
