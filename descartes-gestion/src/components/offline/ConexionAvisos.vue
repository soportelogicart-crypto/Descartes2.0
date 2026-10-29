<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { puenteOffline, useEstadoConexion } from '@/offline/estadoConexion'
import type { OfflineVentaPendiente } from '@/bridge/electron'

const router = useRouter()
const { estado, avisos, cerrarModalCorte, cerrarToast } = useEstadoConexion()

const panelAbierto = ref(false)
const pendientes = ref<OfflineVentaPendiente[]>([])
const cargando = ref(false)
const mensajePanel = ref('')

const banda = computed(() => {
  if (!estado.disponible) return null
  const n = estado.pendientes
  const ventas = `${n} ${n === 1 ? 'venta pendiente' : 'ventas pendientes'} de enviar`
  if (estado.estado === 'offline') {
    return {
      clase: 'banda--offline',
      texto: `SIN CONEXIÓN — Trabajando en modo local. Solo TPV en efectivo y consultas.${n > 0 ? ` ${ventas}.` : ''}`,
    }
  }
  if (estado.estado === 'sincronizando') {
    return { clase: 'banda--sync', texto: `Conexión recuperada. Enviando ventas: quedan ${n}.` }
  }
  if (estado.requiereLogin && n > 0) {
    return { clase: 'banda--aviso', texto: `Hay ${ventas}. Vuelva a iniciar sesión para enviarlas.` }
  }
  if (estado.errores > 0) {
    return {
      clase: 'banda--aviso',
      texto: `${estado.errores} ${estado.errores === 1 ? 'venta no se ha podido enviar' : 'ventas no se han podido enviar'}. Revise Ventas pendientes.`,
    }
  }
  if (estado.estado === 'inestable') {
    return { clase: 'banda--inestable', texto: 'Conexión inestable con el servidor.' }
  }
  return null
})

watch(
  () => !!banda.value,
  (visible) => {
    document.documentElement.style.setProperty('--alto-banda', visible ? '2rem' : '0px')
  },
  { immediate: true }
)

const fotoTexto = computed(() => {
  if (!estado.ultimaFoto) return 'Este puesto no tiene datos locales: no se puede vender sin conexión.'
  const d = new Date(estado.ultimaFoto)
  return `Datos locales del ${d.toLocaleDateString()} a las ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.`
})

const avisoReloj = computed(() => Math.abs(estado.desfaseRelojMs ?? 0) > 5 * 60 * 1000)

async function abrirPanel() {
  panelAbierto.value = true
  await cargarPendientes()
}

async function cargarPendientes() {
  const p = puenteOffline()
  if (!p) return
  cargando.value = true
  try {
    pendientes.value = await p.pendientes()
  } finally {
    cargando.value = false
  }
}

async function reintentar(uuid?: string) {
  await puenteOffline()?.reintentar(uuid ?? null)
  mensajePanel.value = 'Reintento programado.'
  window.setTimeout(() => void cargarPendientes(), 1500)
}

async function exportar() {
  const r = await puenteOffline()?.exportar()
  mensajePanel.value = r?.exportado ? `Exportado a ${r.ruta}` : ''
}

function irAlTpv() {
  cerrarModalCorte()
  void router.push({ name: 'tpv' })
}

function textoEstado(v: OfflineVentaPendiente) {
  if (v.estado === 'enviada') {
    const t = v.resultado?.ticket
    if (t) return `Enviada (ticket ${t})`
    const a = v.resultado?.albaran
    if (a) return `Enviada (albarán ${a})`
    return 'Enviada'
  }
  if (v.estado === 'error') return `Error: ${v.error ?? ''}`
  return v.intentos > 0 ? `Pendiente (${v.intentos} intentos)` : 'Pendiente'
}

function importe(n: number) {
  return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div v-if="banda" class="banda" :class="banda.clase" role="status">
    <span class="banda__texto">{{ banda.texto }}</span>
    <button v-if="estado.pendientes > 0 || estado.errores > 0" type="button" class="banda__btn" @click="abrirPanel">
      Ventas pendientes
    </button>
  </div>

  <div v-if="avisos.modalCorte" class="modal-fondo" @click.self="cerrarModalCorte">
    <div class="modal" role="alertdialog" aria-labelledby="corte-titulo">
      <h2 id="corte-titulo">Se ha perdido la conexión con el servidor</h2>
      <p>La aplicación sigue funcionando en <strong>modo local</strong>:</p>
      <ul>
        <li>Puede vender en el TPV cobrando en <strong>efectivo</strong>.</li>
        <li>Puede consultar clientes y artículos.</li>
        <li>Los tickets llevan un número provisional y se envían solos cuando vuelva la conexión.</li>
        <li>Un cliente de crédito se puede cerrar como albarán. El papel lleva un número provisional; el oficial se asigna al enviarse.</li>
        <li>Artículos y clientes se consultan en Mantenimiento, solo lectura. Proveedores y puestos de trabajo no están en la copia local.</li>
        <li>No están disponibles la tarjeta del propio equipo, vales, fidelización, tickets en espera ni el resto de menús.</li>
      </ul>
      <p class="modal__nota">{{ fotoTexto }}</p>
      <p v-if="estado.ticketBai" class="modal__error">
        Esta tienda usa TicketBAI: no se puede vender sin conexión.
      </p>
      <p v-if="estado.datosCifrados === false" class="modal__nota">
        Windows no permite proteger la clave de la base local en este equipo: los datos se cifran, pero la clave
        queda en el disco.
      </p>
      <p v-if="avisoReloj" class="modal__error">
        El reloj de este equipo no coincide con el del servidor. Revise la fecha y la hora.
      </p>
      <div class="modal__acciones">
        <button type="button" class="btn" @click="cerrarModalCorte">Entendido</button>
        <button type="button" class="btn btn--primario" @click="irAlTpv">Ir al TPV</button>
      </div>
    </div>
  </div>

  <div v-if="panelAbierto" class="modal-fondo" @click.self="panelAbierto = false">
    <div class="modal modal--ancho" role="dialog" aria-labelledby="pend-titulo">
      <h2 id="pend-titulo">Ventas pendientes de enviar</h2>
      <p v-if="cargando">Cargando…</p>
      <p v-else-if="pendientes.length === 0">No hay ventas sin conexión.</p>
      <table v-else class="tabla">
        <thead>
          <tr>
            <th>Número</th>
            <th>Fecha</th>
            <th class="num">Importe</th>
            <th>Estado</th>
            <th />
          </tr>
        </thead>
        <tbody>
          <tr v-for="v in pendientes" :key="v.uuid" :class="{ 'fila--error': v.estado === 'error' }">
            <td>{{ v.tipo === 'anular' ? 'Anulación' : v.numeroOffline }}</td>
            <td>{{ new Date(v.creado).toLocaleString() }}</td>
            <td class="num">{{ importe(v.importe) }}</td>
            <td>
              {{ textoEstado(v) }}
              <span v-if="v.resultado?.incidencias?.length" class="incidencia">
                {{ v.resultado.incidencias.join(' · ') }}
              </span>
            </td>
            <td>
              <button v-if="v.estado !== 'enviada'" type="button" class="btn btn--peque" @click="reintentar(v.uuid)">
                Reintentar
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="mensajePanel" class="modal__nota">{{ mensajePanel }}</p>
      <div class="modal__acciones">
        <button type="button" class="btn" @click="exportar">Exportar</button>
        <button type="button" class="btn" @click="reintentar()">Reintentar todas</button>
        <button type="button" class="btn btn--primario" @click="panelAbierto = false">Cerrar</button>
      </div>
    </div>
  </div>

  <div v-if="avisos.toast" class="toast" :class="`toast--${avisos.toast.tipo}`" role="status" @click="cerrarToast">
    {{ avisos.toast.texto }}
  </div>
</template>

<style scoped>
.banda {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 2rem;
  box-sizing: border-box;
  overflow: hidden;
  white-space: nowrap;
  padding: 0 14px;
  font-weight: 600;
  font-size: 0.9rem;
  color: #fff;
}
.banda__texto {
  flex: 1;
}
.banda--offline {
  background: #c62828;
}
.banda--sync {
  background: #1565c0;
}
.banda--aviso {
  background: #ef6c00;
}
.banda--inestable {
  background: #f9a825;
  color: #222;
}
.banda__btn {
  border: 1px solid currentColor;
  background: transparent;
  color: inherit;
  border-radius: 4px;
  padding: 2px 10px;
  cursor: pointer;
  font-weight: 600;
}
.modal-fondo {
  position: fixed;
  inset: 0;
  background: rgb(0 0 0 / 45%);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 3000;
}
.modal {
  background: #fff;
  color: #222;
  border-radius: 8px;
  padding: 20px 24px;
  max-width: 520px;
  width: calc(100% - 32px);
  box-shadow: 0 10px 30px rgb(0 0 0 / 30%);
}
.modal--ancho {
  max-width: 860px;
  max-height: 85vh;
  overflow: auto;
}
.modal h2 {
  margin: 0 0 10px;
  font-size: 1.15rem;
}
.modal ul {
  margin: 6px 0 10px;
  padding-left: 20px;
}
.modal__nota {
  color: #555;
  font-size: 0.9rem;
}
.modal__error {
  color: #c62828;
  font-weight: 600;
}
.modal__acciones {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 14px;
}
.btn {
  border: 1px solid #bbb;
  background: #f5f5f5;
  border-radius: 4px;
  padding: 6px 14px;
  cursor: pointer;
}
.btn--primario {
  background: #1565c0;
  border-color: #1565c0;
  color: #fff;
}
.btn--peque {
  padding: 2px 8px;
  font-size: 0.85rem;
}
.tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.tabla th,
.tabla td {
  border-bottom: 1px solid #e0e0e0;
  padding: 4px 6px;
  text-align: left;
  vertical-align: top;
}
.tabla .num {
  text-align: right;
}
.fila--error {
  background: #fdecea;
}
.incidencia {
  display: block;
  color: #ef6c00;
  font-size: 0.8rem;
}
.toast {
  position: fixed;
  right: 16px;
  bottom: 16px;
  max-width: 420px;
  padding: 10px 14px;
  border-radius: 6px;
  color: #fff;
  z-index: 3100;
  box-shadow: 0 6px 18px rgb(0 0 0 / 25%);
  cursor: pointer;
}
.toast--ok {
  background: #2e7d32;
}
.toast--aviso {
  background: #ef6c00;
}
</style>
