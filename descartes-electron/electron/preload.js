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

  printTicket: (payload) => ipcRenderer.invoke('peripheral:printTicket', payload),
  printLabel: (payload) => ipcRenderer.invoke('peripheral:printLabel', payload),
  openCashDrawer: () => ipcRenderer.invoke('peripheral:openCashDrawer'),
  readCashDrawer: (payload) => ipcRenderer.invoke('peripheral:readCashDrawer', payload || {}),
  readScale: () => ipcRenderer.invoke('peripheral:readScale'),
  displayPrice: (payload) => ipcRenderer.invoke('peripheral:displayPrice', payload),
})
