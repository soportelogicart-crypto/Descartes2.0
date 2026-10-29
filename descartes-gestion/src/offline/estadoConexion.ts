import { computed, reactive } from 'vue'
import {
  getDescartesBridge,
  type DescartesOfflineBridge,
  type OfflineEstado,
  type OfflineEvento,
} from '@/bridge/electron'

/** Estado de la conexión que publica Electron (monitor + cola de ventas). */
const estado = reactive<OfflineEstado>({
  disponible: false,
  estado: 'online',
  pendientes: 0,
  errores: 0,
})

const avisos = reactive({
  /** Modal de "se ha perdido la conexión": una vez por corte. */
  modalCorte: false,
  /** Mensaje temporal (conexión recuperada / sincronización terminada). */
  toast: null as null | { texto: string; tipo: 'ok' | 'aviso' },
})

let iniciado = false
let timerToast: number | null = null

export function puenteOffline(): DescartesOfflineBridge | null {
  return getDescartesBridge()?.offline ?? null
}

export function aplicarEstado(e: OfflineEstado) {
  Object.assign(estado, e)
}

function mostrarToast(texto: string, tipo: 'ok' | 'aviso' = 'ok', ms = 8000) {
  avisos.toast = { texto, tipo }
  if (timerToast) window.clearTimeout(timerToast)
  timerToast = window.setTimeout(() => {
    avisos.toast = null
  }, ms)
}

function onEvento(ev: OfflineEvento) {
  if (ev.tipo === 'perdido') {
    avisos.modalCorte = true
    return
  }
  if (ev.tipo === 'recuperado') {
    avisos.modalCorte = false
    mostrarToast(
      ev.pendientes > 0
        ? `Conexión recuperada. Enviando ${ev.pendientes} ${ev.pendientes === 1 ? 'venta' : 'ventas'}…`
        : 'Conexión recuperada.'
    )
    return
  }
  if (ev.tipo === 'sincronizado') {
    const partes: string[] = []
    if (ev.enviadas > 0) {
      const tickets = [...ev.tickets].sort((a, b) => a - b)
      const rango =
        tickets.length > 1
          ? ` Tickets ${tickets[0]} a ${tickets[tickets.length - 1]}.`
          : tickets.length === 1
            ? ` Ticket ${tickets[0]}.`
            : ''
      partes.push(
        `Sincronización completada: ${ev.enviadas} ${ev.enviadas === 1 ? 'venta enviada' : 'ventas enviadas'}.${rango}`
      )
    }
    if (ev.incidencias.length > 0) {
      partes.push(`${ev.incidencias.length} con incidencias (precio o cliente cambiados).`)
    }
    if (ev.errores > 0) {
      partes.push(`${ev.errores} no se han podido enviar: revise Ventas pendientes.`)
    }
    mostrarToast(partes.join(' '), ev.errores > 0 ? 'aviso' : 'ok', 12000)
  }
}

/** Una vez al arrancar la app. Sin Electron (navegador) no hace nada. */
export function iniciarEstadoConexion() {
  if (iniciado) return
  iniciado = true
  const p = puenteOffline()
  if (!p) return
  void p.estado().then(aplicarEstado).catch(() => {})
  p.onEstado(aplicarEstado)
  p.onEvento(onEvento)
}

/**
 * Las peticiones van al servidor local mientras no hay conexión y mientras se
 * envían las ventas pendientes (así no se mezclan números de ticket).
 */
export function modoLocal(): boolean {
  return estado.disponible && (estado.estado === 'offline' || estado.estado === 'sincronizando')
}

export function useEstadoConexion() {
  return {
    estado,
    avisos,
    sinConexion: computed(() => estado.disponible && estado.estado === 'offline'),
    modoLocal: computed(() => modoLocal()),
    cerrarModalCorte: () => {
      avisos.modalCorte = false
    },
    cerrarToast: () => {
      avisos.toast = null
    },
  }
}
