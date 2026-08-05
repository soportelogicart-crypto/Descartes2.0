const { Menu, app, BrowserWindow } = require('electron')

function focusedWin(focusedWindow) {
  return focusedWindow || BrowserWindow.getFocusedWindow()
}

function buildAppMenu() {
  return Menu.buildFromTemplate([
    {
      label: 'Salir',
      accelerator: process.platform === 'darwin' ? 'Cmd+Q' : 'Alt+F4',
      click: () => {
        app.quit()
      },
    },
    {
      label: 'Configuración',
      submenu: [
        {
          label: 'Conexión SQL…',
          click: (_item, focusedWindow) => {
            focusedWin(focusedWindow)?.webContents.send('menu:configuracion')
          },
        },
        { type: 'separator' },
        {
          label: 'Ver consola',
          accelerator: process.platform === 'darwin' ? 'Alt+Command+I' : 'Ctrl+Shift+I',
          click: (_item, focusedWindow) => {
            const win = focusedWin(focusedWindow)
            win?.webContents.toggleDevTools()
          },
        },
      ],
    },
  ])
}

function setAppMenu() {
  Menu.setApplicationMenu(buildAppMenu())
}

function clearMenu() {
  Menu.setApplicationMenu(null)
}

/**
 * Menú nativo: Salir | Configuración (Conexión SQL, Ver consola).
 */
function bindWindowMenu(win) {
  setAppMenu()
  win.on('focus', setAppMenu)
}

module.exports = {
  bindWindowMenu,
  setAppMenu,
  clearMenu,
}
