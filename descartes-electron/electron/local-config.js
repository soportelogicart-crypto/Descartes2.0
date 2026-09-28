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

const DATAFONO_DRIVERS = {
  0: 'BS',
  1: '4B',
  2: 'BSEMV',
  3: 'DRS',
  4: 'CLEARONE',
  6: 'SERMEPA',
}

function normalizeDatafono(value, previous = {}) {
  const data = value && typeof value === 'object' ? value : {}
  const prev = previous && typeof previous === 'object' ? previous : {}
  const centroRaw = Number(data.centro)
  const centro = [0, 1, 2, 3, 4, 6].includes(centroRaw) ? centroRaw : 6
  const tipo = centro === 6 ? 'webservice' : 'dll'
  const claveNueva = String(data.clave || '').trim()
  const clave = claveNueva || String(prev.clave || '').trim()
  // El conector lo manda el protocolo: si viniera uno antiguo del formulario,
  // el cobro iría al driver equivocado.
  const driverAuto = DATAFONO_DRIVERS[centro] || ''
  return {
    activo: !!data.activo,
    proveedor: String(data.proveedor || '').trim().slice(0, 100),
    marca: String(data.marca || '').trim().slice(0, 100),
    modelo: String(data.modelo || '').trim().slice(0, 100),
    driver: String(driverAuto || data.driver || '').trim().toUpperCase().slice(0, 50),
    tipo,
    centro,
    demo: !!data.demo,
    comercio: String(data.comercio || '').trim().slice(0, 50),
    clave: clave.slice(0, 200),
    version: String(data.version || '').trim().slice(0, 20),
    terminal: String(data.terminal || '').trim().slice(0, 100),
    puerto: String(data.puerto || '').trim().slice(0, 120),
    dllPath: String(data.dllPath || '').trim().slice(0, 500),
    timeoutMs: Math.min(Math.max(Number(data.timeoutMs) || 120000, 5000), 300000),
  }
}

function publicarDatafono(data) {
  const n = normalizeDatafono(data)
  return {
    ...n,
    clave: '',
    claveConfigurada: n.clave !== '',
  }
}

function equipoVacio() {
  return {
    equipoId: defaultEquipoId(),
    empresaCodigo: null,
    puestoCodigo: null,
    datafono: normalizeDatafono(),
    configurado: false,
  }
}

function publicarEquipo(raw) {
  return {
    ...raw,
    datafono: publicarDatafono(raw.datafono),
  }
}

function readEquipoInterno() {
  const file = equipoPath()
  if (!fs.existsSync(file)) {
    return equipoVacio()
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
      datafono: normalizeDatafono(data.datafono),
      configurado: !!(empresaCodigo && puestoCodigo),
      actualizado: data.actualizado || null,
    }
  } catch {
    return equipoVacio()
  }
}

function readEquipo() {
  return publicarEquipo(readEquipoInterno())
}

function writeEquipo({ empresaCodigo, puestoCodigo, equipoId, datafono }) {
  const dir = configDir()
  if (!fs.existsSync(dir)) {
    fs.mkdirSync(dir, { recursive: true })
  }
  const current = readEquipoInterno()
  const payload = {
    equipoId: String(equipoId || defaultEquipoId()).trim().toUpperCase().slice(0, 50),
    empresaCodigo: String(empresaCodigo || '').trim(),
    puestoCodigo: String(puestoCodigo || '').trim(),
    datafono: normalizeDatafono(
      datafono === undefined ? current.datafono : datafono,
      current.datafono
    ),
    actualizado: new Date().toISOString(),
  }
  if (!payload.empresaCodigo || !payload.puestoCodigo) {
    throw new Error('empresaCodigo y puestoCodigo son obligatorios')
  }
  fs.writeFileSync(equipoPath(), JSON.stringify(payload, null, 2), 'utf8')
  return publicarEquipo({
    ...payload,
    configurado: true,
  })
}

function writeDatafono(datafono) {
  const current = readEquipoInterno()
  if (!current.configurado || !current.empresaCodigo || !current.puestoCodigo) {
    throw new Error('Configure primero la empresa y el puesto de este equipo')
  }
  return writeEquipo({ ...current, datafono })
}

function readDatafonoInterno() {
  return readEquipoInterno().datafono
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
  writeDatafono,
  readDatafonoInterno,
  clearEquipo,
  equipoPath,
}
