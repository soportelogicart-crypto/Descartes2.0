/**
 * Envío de las ventas hechas sin conexión, en orden y de una en una.
 * Solo se marca "enviada" cuando el servidor responde con el ticket: si la
 * respuesta se pierde, el reintento con el mismo UUID devuelve el mismo ticket.
 */
const { EventEmitter } = require('events')
const db = require('./db')
const { peticionJson, esFalloDeRed } = require('./http')

const ESPERAS_MS = [5000, 15000, 60000, 300000]
const DIAS_CONSERVAR_ENVIADAS = 7

class Cola extends EventEmitter {
  constructor() {
    super()
    this.apiBase = ''
    this.vinculo = null
    this.enviando = false
    this.requiereLogin = false
    this.timer = null
    this.lote = { enviadas: 0, tickets: [], incidencias: [] }
  }

  configurar({ apiBase, vinculo }) {
    this.apiBase = apiBase
    this.vinculo = vinculo
  }

  resumen() {
    const r = db.fila(
      `SELECT
         SUM(CASE WHEN estado = 'pendiente' THEN 1 ELSE 0 END) AS pendientes,
         SUM(CASE WHEN estado = 'error' THEN 1 ELSE 0 END) AS errores
       FROM ventas_pendientes`
    )
    return {
      pendientes: Number(r?.pendientes) || 0,
      errores: Number(r?.errores) || 0,
      enviando: this.enviando,
      requiereLogin: this.requiereLogin,
    }
  }

  listar() {
    return db
      .filas(
        `SELECT uuid, tipo_op, numero_offline, creado, estado, intentos, error, enviado, resultado, importe
         FROM ventas_pendientes ORDER BY CASE estado WHEN 'enviada' THEN 1 ELSE 0 END, orden DESC, creado DESC LIMIT 300`
      )
      .map((f) => ({
        uuid: f.uuid,
        tipo: f.tipo_op,
        numeroOffline: f.numero_offline,
        creado: f.creado,
        estado: f.estado,
        intentos: Number(f.intentos) || 0,
        error: f.error,
        enviado: f.enviado,
        resultado: f.resultado ? JSON.parse(f.resultado) : null,
        importe: Number(f.importe) || 0,
      }))
  }

  /** Exportación para soporte (ventas pendientes y con error). */
  exportar() {
    return db.filas(
      "SELECT uuid, tipo_op, numero_offline, creado, estado, intentos, error, payload FROM ventas_pendientes WHERE estado <> 'enviada' ORDER BY orden"
    )
  }

  reintentar(uuid) {
    const params = []
    let where = "estado = 'error'"
    if (uuid) {
      where += ' AND uuid = ?'
      params.push(uuid)
    }
    db.ejecutar(`UPDATE ventas_pendientes SET estado = 'pendiente', proximo_intento = NULL, error = NULL WHERE ${where}`, params)
    db.guardar({ inmediato: true })
    this.emit('cambio')
  }

  sesionRecuperada() {
    if (!this.requiereLogin) return
    this.requiereLogin = false
    this.emit('cambio')
  }

  purgar() {
    const limite = new Date(Date.now() - DIAS_CONSERVAR_ENVIADAS * 86400000).toISOString()
    db.ejecutar("DELETE FROM ventas_pendientes WHERE estado = 'enviada' AND enviado < ?", [limite])
    db.guardar()
  }

  programar(ms) {
    if (this.timer) clearTimeout(this.timer)
    this.timer = setTimeout(() => {
      this.timer = null
      this.emit('despertar')
    }, ms)
  }

  /**
   * Envía todo lo que toque. `hayConexion()` lo consulta el monitor: si se
   * corta a mitad, se para y se sigue al volver.
   * @returns {Promise<{ terminado: boolean }>}
   */
  async procesar(hayConexion) {
    if (this.enviando || this.requiereLogin) return { terminado: false }
    this.enviando = true
    this.emit('cambio')
    try {
      for (;;) {
        if (!hayConexion()) return { terminado: false }
        const ahora = new Date().toISOString()
        const item = db.fila(
          `SELECT * FROM ventas_pendientes
           WHERE estado = 'pendiente' AND (proximo_intento IS NULL OR proximo_intento <= ?)
           ORDER BY orden, creado LIMIT 1`,
          [ahora]
        )
        if (!item) {
          const espera = db.fila(
            "SELECT MIN(proximo_intento) AS t FROM ventas_pendientes WHERE estado = 'pendiente'"
          )
          if (espera?.t) {
            this.programar(Math.max(1000, Date.parse(espera.t) - Date.now()))
            return { terminado: false }
          }
          return { terminado: true }
        }
        const r = await this.enviar(item)
        if (r === 'red') return { terminado: false }
        if (r === 'login') return { terminado: false }
        this.emit('cambio')
      }
    } finally {
      this.enviando = false
      this.emit('cambio')
    }
  }

  /** @returns {Promise<'ok'|'error'|'red'|'login'|'reintento'>} */
  async enviar(item) {
    const vinculo = typeof this.vinculo === 'function' ? this.vinculo() : this.vinculo
    let res
    try {
      if (item.tipo_op === 'anular') {
        const s = JSON.parse(item.payload)
        res = await peticionJson({
          method: 'DELETE',
          url: `${this.apiBase}/api/tpv/ventas/${encodeURIComponent(s.empresa)}/${encodeURIComponent(s.tipo)}/${Number(s.albaran)}`,
          vinculo,
          timeoutMs: 30000,
        })
        // Ya no existe o ya se cobró en el servidor: no hay nada que anular.
        if (res.status === 404 || res.status === 409) res = { status: 200, datos: { ok: true } }
      } else {
        res = await peticionJson({
          method: 'POST',
          url: `${this.apiBase}/api/offline/ventas`,
          datos: { payload: item.payload, hmac: item.hmac },
          vinculo,
          timeoutMs: 60000,
        })
      }
    } catch (e) {
      this.reprogramar(item, `Sin respuesta del servidor: ${e.message}`)
      this.emit('fallo-red')
      return 'red'
    }

    if (res.status === 200) {
      db.ejecutar(
        "UPDATE ventas_pendientes SET estado = 'enviada', enviado = ?, error = NULL, resultado = ? WHERE uuid = ?",
        [new Date().toISOString(), JSON.stringify(res.datos ?? {}), item.uuid]
      )
      db.guardar({ inmediato: true })
      if (item.tipo_op !== 'anular') {
        this.lote.enviadas += 1
        if (res.datos?.ticket) this.lote.tickets.push(Number(res.datos.ticket))
        for (const inc of res.datos?.incidencias ?? []) {
          this.lote.incidencias.push(`${item.numero_offline}: ${inc}`)
        }
      }
      return 'ok'
    }
    if (res.status === 401) {
      this.requiereLogin = true
      this.reprogramar(item, 'Inicie sesión para enviar las ventas pendientes', false)
      return 'login'
    }
    if (esFalloDeRed(res.status)) {
      this.reprogramar(item, `El servidor no responde (HTTP ${res.status})`)
      this.emit('fallo-red')
      return 'red'
    }
    if (res.status === 409 && res.datos?.codigo === 'EN_PROCESO') {
      this.reprogramar(item, res.datos?.error || 'Venta en proceso en el servidor')
      return 'reintento'
    }
    if (res.status >= 500) {
      this.reprogramar(item, res.datos?.error || `Error del servidor (HTTP ${res.status})`)
      return 'reintento'
    }
    // Error de negocio (artículo inexistente, firma...): lo revisa un responsable.
    db.ejecutar(
      "UPDATE ventas_pendientes SET estado = 'error', intentos = intentos + 1, error = ? WHERE uuid = ?",
      [res.datos?.error || `HTTP ${res.status}`, item.uuid]
    )
    db.guardar({ inmediato: true })
    return 'error'
  }

  reprogramar(item, mensaje, contar = true) {
    const intentos = (Number(item.intentos) || 0) + (contar ? 1 : 0)
    const espera = ESPERAS_MS[Math.min(ESPERAS_MS.length - 1, Math.max(0, intentos - 1))]
    db.ejecutar('UPDATE ventas_pendientes SET intentos = ?, error = ?, proximo_intento = ? WHERE uuid = ?', [
      intentos,
      mensaje,
      new Date(Date.now() + (contar ? espera : 0)).toISOString(),
      item.uuid,
    ])
    db.guardar()
  }

  /** Resumen de lo enviado desde la última vez (para el aviso de fin). */
  tomarLote() {
    const l = this.lote
    this.lote = { enviadas: 0, tickets: [], incidencias: [] }
    return l
  }
}

module.exports = new Cola()
