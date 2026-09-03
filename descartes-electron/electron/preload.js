const { contextBridge, ipcRenderer } = require('electron')

/**
 * API segura expuesta a la UI Vue (window.descartes).
 * No se expone Node completo: solo estas funciones.
 */
contextBridge.exposeInMainWorld('descartes', {
  isElectron: true,
  platform: process.platform,

  getEquipoConfig: () => ipcRenderer.invoke('equipo:get'),
  setEquipoConfig: (payload) => ipcRenderer.invoke('equipo:set', payload),
  clearEquipoConfig: () => ipcRenderer.invoke('equipo:clear'),
  getHostname: () => ipcRenderer.invoke('equipo:hostname'),

  listPrinters: () => ipcRenderer.invoke('peripheral:listPrinters'),
  printTicket: (payload) => ipcRenderer.invoke('peripheral:printTicket', payload),
  printHtml: (payload) => ipcRenderer.invoke('peripheral:printHtml', payload),
  printLabel: (payload) => ipcRenderer.invoke('peripheral:printLabel', payload),
  openCashDrawer: () => ipcRenderer.invoke('peripheral:openCashDrawer'),
  readCashDrawer: (payload) => ipcRenderer.invoke('peripheral:readCashDrawer', payload || {}),
  readScale: () => ipcRenderer.invoke('peripheral:readScale'),
  displayPrice: (payload) => ipcRenderer.invoke('peripheral:displayPrice', payload),
})

ipcRenderer.on('app:navigate', (_event, path) => {
  if (typeof path !== 'string' || path === '') return
  window.dispatchEvent(new CustomEvent('descartes:navigate', { detail: path }))
})
