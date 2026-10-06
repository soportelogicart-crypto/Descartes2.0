const { Menu, app, BrowserWindow } = require('electron')

function rutaConexion() {
  // Siempre la pantalla pública. La de dentro del menú exige sesión y, si
  // esa comprobación falla, devuelve al login y Conexión no llega a abrirse.
  return '/instalacion'
}

function navegarEnVentana(win, path) {
  if (!win || win.isDestroyed()) return
  win.webContents.send('app:navigate', path)
}

function buildDescartesMenu(getFocusedWindow) {
  return Menu.buildFromTemplate([
    {
      label: 'Descartes',
      submenu: [
        {
          label: 'Conexion',
          accelerator: 'Ctrl+Shift+C',
          click: (_item, focusedWindow) => {
            const win = focusedWindow || getFocusedWindow()
            if (!win) return
            navegarEnVentana(win, rutaConexion())
          },
        },
        { type: 'separator' },
        {
          label: 'Salir',
          accelerator: process.platform === 'darwin' ? 'Cmd+Q' : 'Alt+F4',
          click: () => {
            app.quit()
          },
        },
        {
          label: 'Consola',
          accelerator: process.platform === 'darwin' ? 'Alt+Cmd+I' : 'Ctrl+Shift+I',
          click: (_item, focusedWindow) => {
            const win = focusedWindow || getFocusedWindow()
            win?.webContents.toggleDevTools()
          },
        },
      ],
    },
  ])
}

function setDescartesMenu(getFocusedWindow) {
  Menu.setApplicationMenu(buildDescartesMenu(getFocusedWindow))
}

function clearMenu() {
  Menu.setApplicationMenu(null)
}

/**
 * En Electron el menu Descartes permanece visible (Conexion, Salir, Consola).
 */
function bindWindowMenu(win) {
  const getFocusedWindow = () => win
  setDescartesMenu(getFocusedWindow)
}

module.exports = {
  bindWindowMenu,
  setDescartesMenu,
  clearMenu,
  rutaConexion,
}
