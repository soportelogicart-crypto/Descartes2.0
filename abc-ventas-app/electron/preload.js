const { contextBridge, ipcRenderer } = require('electron')

/**
 * API segura expuesta a la UI Vue (window.abcVentas).
 */
contextBridge.exposeInMainWorld('abcVentas', {
  isElectron: true,
  platform: process.platform,

  login: (usuario, password) => ipcRenderer.invoke('auth:login', { usuario, password }),
  logout: () => ipcRenderer.invoke('auth:logout'),
  me: () => ipcRenderer.invoke('auth:me'),

  getConfig: () => ipcRenderer.invoke('config:get'),
  saveConfig: (payload) => ipcRenderer.invoke('config:save', payload),

  obtenerAbc: (filtros) => ipcRenderer.invoke('abc:obtener', filtros || {}),
  testDb: () => ipcRenderer.invoke('abc:testDb'),
  buscarLookup: (payload) => ipcRenderer.invoke('lookup:buscar', payload || {}),

  saveExport: (payload) => ipcRenderer.invoke('export:save', payload),

  onMenuConfiguracion: (handler) => {
    const listener = () => handler()
    ipcRenderer.on('menu:configuracion', listener)
    return () => ipcRenderer.removeListener('menu:configuracion', listener)
  },
})
