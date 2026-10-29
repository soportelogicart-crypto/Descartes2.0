/**
 * Cálculos sin dependencias de Electron (se prueban con node --test):
 * importes y totales como VentaEscrituraService::calcularTotales y descuento de
 * línea como TipoDescuentoService::porcentajesLinea.
 */

const PRIORIDAD_TIPO = { A: 1, S: 2, F: 3, I: 4, T: 5, M: 6, P: 7, C: 8, '.': 9 }
const CLIENTE_RAPIDO = 'ZZZZZZZZZ'

function r2(n) {
  return Math.round((Number(n) + Number.EPSILON) * 100) / 100
}

/** Texto de búsqueda: minúsculas y sin acentos. */
function normalizar(texto) {
  return String(texto ?? '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim()
}

function importeLinea(cantidad, precio, pjeDto = 0) {
  const bruto = Number(cantidad) * Number(precio)
  return r2(bruto - bruto * (Number(pjeDto) / 100))
}

/**
 * @param {Array<{articulo: string, cantidad: number, precio: number, pjeDto?: number, pjeIva?: number}>} lineas
 * @param {{ ivaIncluido: boolean, dto1?: number }} opciones
 */
function calcularTotales(lineas, { ivaIncluido, dto1 = 0 }) {
  let bruto = 0
  let lineasConDescuentos = false
  const acum = new Map()
  for (const lin of lineas) {
    const art = String(lin.articulo ?? '').trim()
    if (!art || art.toUpperCase() === 'NO') continue
    const cant = Number(lin.cantidad) || 0
    const precio = Number(lin.precio) || 0
    const pjeDto = Number(lin.pjeDto) || 0
    if (pjeDto !== 0) lineasConDescuentos = true
    const pjeIva = Number(lin.pjeIva) > 0 ? Number(lin.pjeIva) : 21
    const lineaBruto = cant * precio
    const neto = r2(lineaBruto - lineaBruto * (pjeDto / 100))
    bruto += lineaBruto
    acum.set(pjeIva, (acum.get(pjeIva) ?? 0) + neto)
  }
  const tipos = [...acum.keys()].sort((a, b) => a - b).slice(0, 4)
  let bases = []
  let ivas = []
  let importe = 0
  for (const rate of tipos) {
    const total = r2(acum.get(rate))
    if (ivaIncluido) {
      const base = rate > 0 ? r2(total / (1 + rate / 100)) : total
      bases.push(base)
      ivas.push(r2(total - base))
      importe += total
    } else {
      bases.push(total)
      const iva = r2(total * (rate / 100))
      ivas.push(iva)
      importe += total + iva
    }
  }
  let descuento = 0
  if (Number(dto1) !== 0) {
    bases = bases.map((b) => {
      const d = r2(b * (Number(dto1) / 100))
      descuento += d
      return r2(b - d)
    })
    ivas = bases.map((b, i) => r2(b * (tipos[i] / 100)))
    importe = r2(bases.reduce((s, b) => s + b, 0) + ivas.reduce((s, v) => s + v, 0))
  }
  return {
    bruto: r2(bruto),
    descuento: r2(descuento),
    iva: r2(ivas.reduce((s, v) => s + v, 0)),
    importe: r2(importe),
    pjeDto: Number(dto1) || 0,
    lineasConDescuentos,
    importesIva: tipos.map((pjeIva, i) => ({ pjeIva, base: bases[i], iva: ivas[i] })),
  }
}

function fechaEnPeriodo(oferta, fecha) {
  if (String(oferta.fecha_inicio ?? '') > fecha) return false
  const fin = oferta.fecha_fin
  return fin === null || fin === undefined || fin === '' || String(fin) >= fecha
}

function tiendaExcluida(oferta, empresa) {
  if (!empresa) return false
  let bloqueos = oferta.bloqueos
  if (typeof bloqueos === 'string') {
    try {
      bloqueos = JSON.parse(bloqueos)
    } catch {
      bloqueos = []
    }
  }
  return (bloqueos || []).some((b) => String(b).trim().toLowerCase() === String(empresa).trim().toLowerCase())
}

function ofertaCoincide(oferta, clase, cliente) {
  const tipo = oferta.tipo === '.' ? '.' : String(oferta.tipo ?? '').toUpperCase()
  const codigo = String(oferta.codigo ?? '').trim().toLowerCase()
  if (tipo === '.') return true
  if (tipo === 'C') return codigo !== '' && codigo === String(cliente).trim().toLowerCase()
  const valor = {
    A: clase.codigo,
    F: clase.familia,
    S: clase.subfamilia,
    T: clase.seccion,
    I: clase.subseccion,
    M: clase.macrofamilia,
    P: clase.proveedor,
  }[tipo]
  const v = String(valor ?? '').trim().toLowerCase()
  return codigo !== '' && v !== '' && codigo === v
}

/**
 * Porcentaje de la oferta del cliente para un artículo. Gana la más concreta y,
 * con la misma prioridad, la que empezó más tarde.
 */
function descuentoLinea({ ofertas, clase, cliente, empresa, fecha }) {
  const cli = String(cliente ?? '').trim()
  if (!clase || !cli || cli.toUpperCase() === CLIENTE_RAPIDO) return 0
  let mejor = null
  let mejorPrioridad = 99
  for (const oferta of ofertas) {
    if (!fechaEnPeriodo(oferta, fecha) || tiendaExcluida(oferta, empresa)) continue
    if (!ofertaCoincide(oferta, clase, cli)) continue
    const prioridad = PRIORIDAD_TIPO[oferta.tipo === '.' ? '.' : String(oferta.tipo).toUpperCase()] ?? 99
    if (
      mejor === null ||
      prioridad < mejorPrioridad ||
      (prioridad === mejorPrioridad && String(oferta.fecha_inicio) > String(mejor.fecha_inicio))
    ) {
      mejor = oferta
      mejorPrioridad = prioridad
    }
  }
  return mejor ? Number(mejor.descuento) || 0 : 0
}

/** La foto a veces deja el JSON entre comillas una vez de más. */
function leerJson(texto) {
  let valor = texto
  for (let i = 0; i < 2; i += 1) {
    if (typeof valor !== 'string') break
    try {
      valor = JSON.parse(valor)
    } catch {
      return null
    }
  }
  return valor
}

/** Clave de la caché de respuestas GET: ruta + parámetros ordenados. */
function claveCache(url, params = {}) {
  const u = new URL(String(url || ''), 'http://local')
  const todos = new Map()
  u.searchParams.forEach((v, k) => todos.set(k, v))
  for (const [k, v] of Object.entries(params || {})) {
    if (v === undefined || v === null) continue
    todos.set(k, String(v))
  }
  const query = [...todos.entries()]
    .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&')
  const ruta = decodeURIComponent(u.pathname)
  return query ? `${ruta}?${query}` : ruta
}

/** OFF-<puesto>-000123 */
function numeroOffline(puesto, contador) {
  const p = String(puesto ?? '').trim().toUpperCase().replace(/[^A-Z0-9]/g, '') || 'P'
  return `OFF-${p}-${String(contador).padStart(6, '0')}`
}

module.exports = {
  CLIENTE_RAPIDO,
  r2,
  normalizar,
  importeLinea,
  calcularTotales,
  descuentoLinea,
  leerJson,
  claveCache,
  numeroOffline,
}
