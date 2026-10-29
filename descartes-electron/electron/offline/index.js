/**
 * Modo sin conexión del TPV: une base local, monitor, foto de datos, servidor
 * local y cola de envío, y avisa a la ventana de cada cambio de estado.
 */
const { BrowserWindow, dialog, ipcMain } = require('electron')
const fs = require('fs')
const localConfig = require('../local-config')
const db = require('./db')
const monitor = require('./monitor')
const cola = require('./cola')
const snapshot = require('./snapshot')
const router = require('./router')
const sesionLocal = require('./sesion-local')
const { claveProtegida } = require('./cifrado')
const uiLocal = require('./ui-local')

const REFRESCO_MS = 15 * 60 * 1000
const PURGA_MS = 6 * 60 * 60 * 1000

let apiBase = ''
let listo = false
let errorInicio = null
let ventana = null
let ultimoEstado = null
let refrescando = null
let timers = []

function vinculo() {
  return localConfig.readVinculo()
}

function estado() {
  if (!listo) {
    return { disponible: false, estado: 'online', error: errorInicio, pendientes: 0, errores: 0 }
  }
  const c = cola.resumen()
  let e = 'online'
  if (!monitor.conectado) e = 'offline'
  else if (monitor.inestable) e = 'inestable'
  else if (!c.requiereLogin && (c.enviando || c.pendientes > 0)) e = 'sincronizando'
  const empresa = snapshot.empresaFoto()
  return {
    disponible: true,
    estado: e,
    pendientes: c.pendientes,
    errores: c.errores,
    requiereLogin: c.requiereLogin,
    ultimaFoto: db.meta('ultimaFoto'),
    fotoEmpresa: db.meta('fotoEmpresa'),
    fotoPuesto: db.meta('fotoPuesto'),
    ticketBai: !!empresa?.ticketBai,
    puedeVender: !!db.meta('ultimaFoto') && !!db.meta('claveOffline') && !empresa?.ticketBai,
    desfaseRelojMs: Math.round(monitor.desfaseRelojMs || 0),
    datosCifrados: claveProtegida(),
    uiLocal: uiLocal.leerActual()?.version ?? null,
    ultimoPing: monitor.ultimoPing,
  }
}

function enviar(canal, datos) {
  for (const w of BrowserWindow.getAllWindows()) {
    if (!w.isDestroyed()) w.webContents.send(canal, datos)
  }
}

function publicarEstado() {
  const e = estado()
  const clave = JSON.stringify(e)
  if (clave === ultimoEstado) return
  ultimoEstado = clave
  enviar('offline:estado', e)
}

async function refrescar(completa = false) {
  if (!listo || !monitor.conectado) return { ok: false, motivo: 'Sin conexión' }
  if (refrescando) return refrescando
  refrescando = (async () => {
    try {
      const r = await snapshot.refrescar({ apiBase, equipo: localConfig.readEquipo(), vinculo: vinculo(), completa })
      if (!r.ok && r.status !== 401) {
        console.log(`[offline] Foto de datos no actualizada: ${r.motivo}`)
      }
      return r
    } catch (e) {
      console.error('[offline] Error al actualizar la foto de datos:', e.message)
      return { ok: false, motivo: e.message }
    } finally {
      refrescando = null
      publicarEstado()
    }
  })()
  return refrescando
}

async function sincronizar() {
  if (!listo || !monitor.conectado) return
  const r = await cola.procesar(() => monitor.conectado)
  publicarEstado()
  if (r.terminado) {
    const lote = cola.tomarLote()
    const c = cola.resumen()
    if (lote.enviadas > 0 || c.errores > 0) {
      enviar('offline:evento', { tipo: 'sincronizado', ...lote, errores: c.errores })
      void refrescar(false)
    }
  }
}

function registrarIpc() {
  ipcMain.handle('offline:estado', () => estado())
  ipcMain.handle('offline:peticion', (_e, req) => {
    if (!listo) return { status: 503, data: { error: 'El modo sin conexión no está disponible en este equipo', codigo: 'OFFLINE' } }
    const r = router.manejar(req || {})
    publicarEstado()
    return r
  })
  ipcMain.handle('offline:guardarCache', (_e, req) => (listo ? router.guardarCache(req || {}) : false))
  ipcMain.handle('offline:falloRed', async () => {
    if (!listo) return estado()
    monitor.avisarFallo()
    await monitor.comprobar()
    return estado()
  })
  ipcMain.handle('offline:recordarUsuario', async (_e, payload) => {
    if (!listo) return { recordado: false }
    const r = sesionLocal.recordarUsuario(payload || {})
    sesionLocal.adoptar(payload?.usuario, payload?.permisos)
    cola.sesionRecuperada()
    void refrescar(true)
    void sincronizar()
    return r
  })
  ipcMain.handle('offline:pendientes', () => (listo ? cola.listar() : []))
  ipcMain.handle('offline:reintentar', (_e, uuid) => {
    if (!listo) return false
    cola.reintentar(uuid || null)
    void sincronizar()
    return true
  })
  ipcMain.handle('offline:refrescar', () => refrescar(true))
  ipcMain.handle('offline:comprobar', async () => {
    if (!listo) return estado()
    await monitor.comprobar()
    return estado()
  })
  ipcMain.handle('offline:exportar', async (event) => {
    if (!listo) return { exportado: false }
    const win = BrowserWindow.fromWebContents(event.sender)
    const { canceled, filePath } = await dialog.showSaveDialog(win, {
      title: 'Exportar ventas pendientes',
      defaultPath: `ventas-pendientes-${new Date().toISOString().slice(0, 10)}.json`,
      filters: [{ name: 'JSON', extensions: ['json'] }],
    })
    if (canceled || !filePath) return { exportado: false }
    fs.writeFileSync(filePath, JSON.stringify(cola.exportar(), null, 2), 'utf8')
    return { exportado: true, ruta: filePath }
  })
}

async function iniciar(opciones) {
  apiBase = String(opciones.apiBase || '').replace(/\/+$/, '')
  registrarIpc()
  try {
    await db.abrir()
  } catch (e) {
    errorInicio = e.message
    throw e
  }
  listo = true
  cola.configurar({ apiBase, vinculo })
  cola.purgar()

  monitor.on('cambio', publicarEstado)
  monitor.on('perdido', () => {
    publicarEstado()
    enviar('offline:evento', { tipo: 'perdido' })
  })
  monitor.on('recuperado', () => {
    db.meta('desfaseRelojMs', String(Math.round(monitor.desfaseRelojMs || 0)))
    publicarEstado()
    enviar('offline:evento', { tipo: 'recuperado', pendientes: cola.resumen().pendientes })
    void sincronizar()
  })
  monitor.on('catalogo', () => void refrescar(false))
  cola.on('cambio', publicarEstado)
  cola.on('despertar', () => void sincronizar())
  cola.on('fallo-red', () => monitor.avisarFallo())
  monitor.iniciar({ apiBase, vinculo })

  timers.push(setTimeout(() => void refrescar(false), 5000))
  timers.push(setTimeout(() => void sincronizar(), 8000))
  timers.push(setInterval(() => void refrescar(false), REFRESCO_MS))
  timers.push(
    setInterval(() => {
      cola.purgar()
    }, PURGA_MS)
  )
}

function adjuntarVentana(win) {
  ventana = win
  ventana.webContents.on('did-finish-load', () => {
    ultimoEstado = null
    publicarEstado()
  })
}

function detener() {
  monitor.detener()
  for (const t of timers) {
    clearTimeout(t)
    clearInterval(t)
  }
  timers = []
  if (listo) db.cerrar()
}

module.exports = { iniciar, adjuntarVentana, detener }
