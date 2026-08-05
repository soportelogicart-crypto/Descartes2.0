const { Menu, app, BrowserWindow } = require('electron')

function isLoginUrl(url) {
  try {
    const { pathname } = new URL(url)
    return pathname === '/login' || pathname.endsWith('/login')
  } catch {
    return /\/login(\?|#|$)/.test(String(url || ''))
  }
}

function buildLoginMenu() {
  return Menu.buildFromTemplate([
    {
      label: 'Descartes',
      submenu: [
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
            const win = focusedWindow || BrowserWindow.getFocusedWindow()
            win?.webContents.toggleDevTools()
          },
        },
      ],
    },
  ])
}

function setLoginMenu() {
  Menu.setApplicationMenu(buildLoginMenu())
}

function clearMenu() {
  Menu.setApplicationMenu(null)
}

function applyMenuForUrl(url) {
  if (isLoginUrl(url)) {
    setLoginMenu()
  } else {
    clearMenu()
  }
}

/**
 * Sincroniza el menu nativo con la ruta Vue: login = Descartes > Salir/Consola; resto = sin menu.
 */
function bindWindowMenu(win) {
  clearMenu()

  const sync = () => {
    applyMenuForUrl(win.webContents.getURL())
  }

  win.webContents.on('did-navigate', sync)
  win.webContents.on('did-navigate-in-page', sync)
  win.webContents.on('did-finish-load', sync)
}

module.exports = {
  bindWindowMenu,
  applyMenuForUrl,
  setLoginMenu,
  clearMenu,
}
