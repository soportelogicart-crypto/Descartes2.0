/**
 * Estado de la conexión con el servidor. Ping cada 10 s a /api/offline/estado
 * (4 s de espera). Pasa a sin conexión tras 3 fallos seguidos y vuelve tras 2
 * éxitos seguidos, para no ir y venir con una línea inestable.
 * navigator.onLine no sirve: dice "conectado" con wifi sin salida a internet.
 */
const { EventEmitter } = require('events')
const { peticionJson, esFalloDeRed } = require('./http')

const INTERVALO_MS = 10000
const TIMEOUT_MS = 4000
const FALLOS_PARA_OFFLINE = 3
const EXITOS_PARA_ONLINE = 2

class Monitor extends EventEmitter {
  constructor() {
    super()
    this.apiBase = ''
    this.vinculo = null
    this.conectado = true
    this.inestable = false
    this.fallos = 0
    this.exitos = 0
    this.timer = null
    this.enCurso = null
    this.versionCatalogo = null
    this.desfaseRelojMs = 0
    this.ultimoPing = null
    /** La ventana ya ha visto un fallo: si el ping lo confirma, se pasa a local ya. */
    this.confirmadoPorUi = false
  }

  iniciar({ apiBase, vinculo }) {
    this.apiBase = apiBase
    this.vinculo = vinculo
    this.programar(0)
  }

  detener() {
    if (this.timer) clearTimeout(this.timer)
    this.timer = null
  }

  programar(ms) {
    if (this.timer) clearTimeout(this.timer)
    this.timer = setTimeout(() => {
      this.timer = null
      this.comprobar().finally(() => {
        if (!this.timer) this.programar(INTERVALO_MS)
      })
    }, ms)
  }

  /**
   * La ventana o la cola han visto un corte. El ping lo confirma y, si también
   * falla, se pasa a local sin esperar a las 3 comprobaciones de fondo.
   */
  avisarFallo() {
    this.confirmadoPorUi = true
    if (this.enCurso) return
    void this.comprobar()
  }

  async comprobar() {
    if (this.enCurso) return this.enCurso
    this.enCurso = (async () => {
      const inicio = Date.now()
      let ok = false
      try {
        const vinculo = typeof this.vinculo === 'function' ? this.vinculo() : this.vinculo
        const res = await peticionJson({
          url: `${this.apiBase}/api/offline/estado`,
          vinculo,
          timeoutMs: TIMEOUT_MS,
        })
        // Un 500 con JSON es el PHP. Un 500 sin JSON es el proxy (Apache parado).
        ok = alcanzaServidor(res.status, res.datos)
        if (ok && res.datos?.hora) {
          const servidor = Date.parse(res.datos.hora)
          if (Number.isFinite(servidor)) {
            this.desfaseRelojMs = Date.now() - (Date.now() - inicio) / 2 - servidor
          }
        }
        if (ok && res.datos?.versionCatalogo && res.datos.versionCatalogo !== this.versionCatalogo) {
          const anterior = this.versionCatalogo
          this.versionCatalogo = res.datos.versionCatalogo
          if (anterior !== null) this.emit('catalogo')
        }
      } catch {
        ok = false
      }
      this.ultimoPing = new Date().toISOString()
      this.registrar(ok)
    })()
    try {
      await this.enCurso
    } finally {
      this.enCurso = null
    }
  }

  registrar(ok) {
    if (ok) {
      this.fallos = 0
      this.confirmadoPorUi = false
      this.exitos += 1
      if (this.inestable) {
        this.inestable = false
        this.emit('cambio')
      }
      if (!this.conectado && this.exitos >= EXITOS_PARA_ONLINE) {
        this.conectado = true
        this.emit('cambio')
        this.emit('recuperado')
      }
      return
    }
    this.exitos = 0
    this.fallos += 1
    const confirmado = this.confirmadoPorUi
    this.confirmadoPorUi = false
    if (this.conectado && (confirmado || this.fallos >= FALLOS_PARA_OFFLINE)) {
      this.conectado = false
      this.inestable = false
      this.emit('cambio')
      this.emit('perdido')
      return
    }
    if (this.conectado && !this.inestable) {
      this.inestable = true
      this.emit('cambio')
      // Reintento rápido para confirmar el corte en unos segundos.
      this.programar(3000)
    }
  }
}

/**
 * Hay proceso al otro lado. 502/503/504 y un 500 sin JSON (proxy de Vite o de
 * Apache parado) no cuentan: la API no ha contestado.
 */
function alcanzaServidor(status, datos) {
  if (esFalloDeRed(status)) return false
  if (status === 500) return !!datos && typeof datos === 'object'
  return status > 0
}

module.exports = new Monitor()
module.exports.alcanzaServidor = alcanzaServidor
