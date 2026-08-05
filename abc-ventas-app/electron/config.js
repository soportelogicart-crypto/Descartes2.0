const path = require('path')
const fs = require('fs')

function isPackaged() {
  try {
    const { app } = require('electron')
    return !!(app && app.isPackaged)
  } catch {
    return false
  }
}

/** Ruta escribible de config.json (userData en instalable). */
function configFilePath() {
  try {
    const { app } = require('electron')
    if (app && app.isPackaged) {
      return path.join(app.getPath('userData'), 'config.json')
    }
  } catch {
    // Fuera de Electron
  }
  return path.join(__dirname, '..', 'config.json')
}

function exampleConfigPath() {
  const candidates = [
    path.join(__dirname, '..', 'config.example.json'),
  ]
  try {
    const { app } = require('electron')
    if (app) {
      candidates.unshift(path.join(app.getAppPath(), 'config.example.json'))
    }
    if (process.resourcesPath) {
      candidates.unshift(path.join(process.resourcesPath, 'config.example.json'))
    }
  } catch {
    // ignore
  }
  for (const file of candidates) {
    if (fs.existsSync(file)) return file
  }
  return candidates[candidates.length - 1]
}

function configCandidates() {
  const files = [configFilePath()]

  try {
    const { app } = require('electron')
    if (app && typeof app.getAppPath === 'function' && !app.isPackaged) {
      files.push(path.join(app.getAppPath(), 'config.json'))
    }
  } catch {
    // Fuera de Electron
  }

  return files
}

function defaultConfig() {
  return {
    database: {
      server: 'localhost',
      database: 'larasa',
      user: 'sa',
      password: '',
      port: 1433,
      options: { encrypt: false, trustServerCertificate: true },
    },
    window: { width: 1400, height: 900, fullscreen: false },
  }
}

function ensureUserConfig() {
  const dest = configFilePath()
  if (fs.existsSync(dest)) return
  try {
    const dir = path.dirname(dest)
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true })
    const example = exampleConfigPath()
    if (example && fs.existsSync(example)) {
      fs.copyFileSync(example, dest)
    } else {
      fs.writeFileSync(dest, JSON.stringify(defaultConfig(), null, 2), 'utf8')
    }
  } catch (err) {
    console.error('[abc-ventas] No se pudo crear config inicial:', err.message)
  }
}

function loadConfig() {
  if (isPackaged()) {
    ensureUserConfig()
  }

  for (const file of configCandidates()) {
    try {
      if (fs.existsSync(file)) {
        return JSON.parse(fs.readFileSync(file, 'utf8'))
      }
    } catch {
      // ignore
    }
  }

  const example = exampleConfigPath()
  if (example && fs.existsSync(example)) {
    try {
      return JSON.parse(fs.readFileSync(example, 'utf8'))
    } catch {
      // ignore
    }
  }

  return defaultConfig()
}

/**
 * Guarda config.json (userData si esta empaquetada).
 * @param {Record<string, unknown>} next
 */
function saveConfig(next) {
  const file = configFilePath()
  const dir = path.dirname(file)
  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true })

  const current = loadConfig()
  const merged = {
    ...current,
    ...next,
    database: {
      ...(current.database || {}),
      ...(next.database || {}),
      options: {
        ...(current.database?.options || {}),
        ...(next.database?.options || {}),
      },
    },
    window: {
      ...(current.window || {}),
      ...(next.window || {}),
    },
  }
  fs.writeFileSync(file, JSON.stringify(merged, null, 2), 'utf8')
  return merged
}

/**
 * Config pública para la UI (sin ocultar password de SQL: es config local de la app).
 */
function getPublicConfig() {
  return loadConfig()
}

module.exports = {
  loadConfig,
  saveConfig,
  getPublicConfig,
  configFilePath,
}
