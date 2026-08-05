/**
 * Config local del PC (equivalente al INI legacy).
 * Ruta: %APPDATA%/descartes-electron/equipo.json  (Windows)
 */
const fs = require('fs')
const path = require('path')
const os = require('os')
const { app } = require('electron')

function configDir() {
  return path.join(app.getPath('userData'), 'config')
}

function equipoPath() {
  return path.join(configDir(), 'equipo.json')
}

function defaultEquipoId() {
  return (os.hostname() || 'EQUIPO').toUpperCase().replace(/[^A-Z0-9._-]/g, '-').slice(0, 50)
}

function readEquipo() {
  const file = equipoPath()
  if (!fs.existsSync(file)) {
    return {
      equipoId: defaultEquipoId(),
      empresaCodigo: null,
      puestoCodigo: null,
      configurado: false,
    }
  }
  try {
    const data = JSON.parse(fs.readFileSync(file, 'utf8'))
    const equipoId = String(data.equipoId || defaultEquipoId()).trim().toUpperCase()
    const empresaCodigo = data.empresaCodigo ? String(data.empresaCodigo).trim() : null
    const puestoCodigo = data.puestoCodigo ? String(data.puestoCodigo).trim() : null
    return {
      equipoId,
      empresaCodigo,
      puestoCodigo,
      configurado: !!(empresaCodigo && puestoCodigo),
      actualizado: data.actualizado || null,
    }
  } catch {
    return {
      equipoId: defaultEquipoId(),
      empresaCodigo: null,
      puestoCodigo: null,
      configurado: false,
    }
  }
}

function writeEquipo({ empresaCodigo, puestoCodigo, equipoId }) {
  const dir = configDir()
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true })
  }
  const payload = {
    equipoId: String(equipoId || defaultEquipoId()).trim().toUpperCase().slice(0, 50),
    empresaCodigo: String(empresaCodigo || '').trim(),
    puestoCodigo: String(puestoCodigo || '').trim(),
    actualizado: new Date().toISOString(),
  }
  if (!payload.empresaCodigo || !payload.puestoCodigo) {
    throw new Error('empresaCodigo y puestoCodigo son obligatorios')
  }
  fs.writeFileSync(equipoPath(), JSON.stringify(payload, null, 2), 'utf8')
  return {
    ...payload,
    configurado: true,
  }
}

function clearEquipo() {
  const file = equipoPath()
  if (fs.existsSync(file)) {
    fs.unlinkSync(file)
  }
  return readEquipo()
}

module.exports = {
  defaultEquipoId,
  readEquipo,
  writeEquipo,
  clearEquipo,
  equipoPath,
}
