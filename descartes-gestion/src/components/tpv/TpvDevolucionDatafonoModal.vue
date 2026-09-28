<script setup lang="ts">
import { ref, watch } from 'vue'
import { buscarAutorizacionTarjetaPorAut, obtenerAutorizacionTarjetaAlbaran } from '@/api/ventas'
import type { DevolucionDatafonoContexto } from '@/composables/cobroDatafono'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  open: boolean
  empresa: string
  albaranOrigen: number
  importeDevolucion?: number
}>()

const emit = defineEmits<{
  confirmar: [DevolucionDatafonoContexto]
  cancelar: []
}>()

const autorizacion = ref('')
const clr = ref('')
const pedidoRedsys = ref('')
const rts = ref('')
const sinOriginal = ref(false)
const devolucionPinpad = ref(false)
const mostrarAvanzado = ref(false)
const loading = ref(false)
const procesando = ref(false)
const error = ref<string | null>(null)
const info = ref<string | null>(null)

async function cargarDesdeOrigen() {
  if (!props.albaranOrigen) return null
  return obtenerAutorizacionTarjetaAlbaran(props.empresa, props.albaranOrigen)
}

async function resolverLegacy(aut: string, dig: string) {
  return buscarAutorizacionTarjetaPorAut(
    props.empresa,
    aut,
    dig,
    props.importeDevolucion,
    props.albaranOrigen
  )
}

function aplicarItem(item: {
  pedidoRedsys?: string
  identificadorRts?: string
  autorizacion?: string
  clr?: string
}) {
  if (item.autorizacion && !autorizacion.value.trim()) {
    autorizacion.value = String(item.autorizacion).trim()
  }
  if (item.clr && !clr.value.trim()) clr.value = String(item.clr).trim()
  if (item.pedidoRedsys) pedidoRedsys.value = String(item.pedidoRedsys).trim()
  if (item.identificadorRts) rts.value = String(item.identificadorRts).trim()
}

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) {
      procesando.value = false
      return
    }
    autorizacion.value = ''
    clr.value = ''
    pedidoRedsys.value = ''
    rts.value = ''
    sinOriginal.value = false
    devolucionPinpad.value = false
    mostrarAvanzado.value = false
    error.value = null
    info.value =
      'Como en legacy: AUT y CLR. Si Autorizaciones está vacía, buscamos el cobro en LOG_TAR de Desora (identificador RTS).'
    if (!props.albaranOrigen) {
      sinOriginal.value = true
      return
    }
    loading.value = true
    try {
      const item = await cargarDesdeOrigen()
      if (item) {
        aplicarItem(item)
        info.value = 'Autorización del ticket origen cargada desde base de datos.'
      }
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo consultar la autorización del ticket origen')
    } finally {
      loading.value = false
    }
  }
)

let buscarAutTimer: ReturnType<typeof setTimeout> | null = null

watch([autorizacion, clr], () => {
  if (buscarAutTimer) clearTimeout(buscarAutTimer)
  const aut = autorizacion.value.trim()
  const dig = clr.value.trim()
  if (aut.length < 4 || dig.length < 4 || !props.empresa) return
  buscarAutTimer = setTimeout(async () => {
    try {
      const item = await resolverLegacy(aut, dig)
      if (!item?.pedidoRedsys) return
      aplicarItem(item)
        info.value = item.identificadorRts
          ? 'Cobro localizado (RTS listo para devolución como en VentaGen).'
          : 'Cobro localizado. Pulse continuar.'
    } catch {
      // Sin fila aún; al confirmar se vuelve a intentar.
    }
  }, 400)
})

async function confirmar() {
  if (procesando.value) return
  error.value = null
  if (!autorizacion.value.trim()) {
    error.value = 'Indique el código de autorización (AUT) del ticket original.'
    return
  }
  if (!clr.value.trim()) {
    error.value = 'Indique el CLR (4 últimos dígitos de la tarjeta).'
    return
  }
  procesando.value = true
  try {
    let pedido = pedidoRedsys.value.trim()
    let rtsVal = rts.value.trim()
    if (!sinOriginal.value) {
      const item = await resolverLegacy(autorizacion.value.trim(), clr.value.trim())
      if (item?.pedidoRedsys) {
        pedido = String(item.pedidoRedsys).trim()
        if (item.identificadorRts) rtsVal = String(item.identificadorRts).trim()
        pedidoRedsys.value = pedido
        rts.value = rtsVal
      }
      if (!pedido && !rtsVal) {
        error.value =
          'No se encontró el cobro (Autorizaciones vacía y sin coincidencia en LOG_TAR). ' +
          'Compruebe AUT/CLR, pegue el RTS en opciones avanzadas o haga el abono el mismo día del cobro en legacy.'
        return
      }
    }
    emit('confirmar', {
      pedidoOriginal: pedido || undefined,
      rtsOriginal: rtsVal || undefined,
      codigoAutorizacion: autorizacion.value.trim(),
      clr: clr.value.trim(),
      devolucionSinOriginal: sinOriginal.value,
      devolucionPinpad: devolucionPinpad.value,
      modoLegacyRts: Boolean(rtsVal) && !pedido,
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo resolver la devolución')
  } finally {
    procesando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @mousedown.self.prevent>
      <section class="ventana">
        <header class="barra">DEVOLUCIÓN EN DATÁFONO</header>
        <div class="cuerpo">
          <p class="hint">
            Igual que legacy: <strong>AUT</strong> y <strong>CLR</strong>. En Larasa la devolución usa el
            <strong>RTS</strong> del cobro (se guarda en <strong>LOG_TAR</strong>, no en Autorizaciones).
            En el ticket, AUT suele ser el <strong>conttrans</strong> (no el OPE).
          </p>
          <p v-if="albaranOrigen" class="hint">
            Ticket origen: albarán interno <strong>{{ albaranOrigen }}</strong>
          </p>
          <p v-if="loading" class="estado">Cargando autorización…</p>
          <p v-if="info" class="info">{{ info }}</p>
          <p v-if="error" class="error">{{ error }}</p>
          <p v-if="procesando" class="estado strong">Enviando devolución al datáfono…</p>

          <label>
            Código autorización (AUT)
            <input v-model="autorizacion" type="text" autocomplete="off" maxlength="15" />
          </label>
          <label>
            CLR (4 últimos dígitos tarjeta)
            <input v-model="clr" type="text" inputmode="numeric" maxlength="4" autocomplete="off" />
          </label>

          <button type="button" class="link-avanzado" @click="mostrarAvanzado = !mostrarAvanzado">
            {{ mostrarAvanzado ? 'Ocultar opciones avanzadas' : 'Opciones avanzadas (pedido / pinpad)' }}
          </button>

          <template v-if="mostrarAvanzado">
            <label>
              Pedido Redsys (solo si no está en Autorizaciones)
              <input v-model="pedidoRedsys" type="text" inputmode="numeric" autocomplete="off" />
            </label>
            <label>
              Identificador RTS (opcional)
              <input v-model="rts" type="text" autocomplete="off" />
            </label>
            <label class="check">
              <input v-model="devolucionPinpad" type="checkbox" />
              Forzar devolución leyendo tarjeta en pinpad (legacy no suele usarla)
            </label>
            <label class="check">
              <input v-model="sinOriginal" type="checkbox" />
              Devolución sin operación original (solo si Redsys lo tiene activo)
            </label>
          </template>
        </div>
        <footer class="pie">
          <button type="button" @click="emit('cancelar')">CANCELAR</button>
          <button type="button" class="aceptar" :disabled="loading || procesando" @click="confirmar">
            DEVOLVER EN DATÁFONO
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 6000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 55%);
}

.ventana {
  width: min(28rem, 96vw);
  background: #fff;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 16px 40px rgb(15 23 42 / 35%);
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.barra {
  padding: 0.55rem 0.75rem;
  background: #1e293b;
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  padding: 0.75rem;
  display: grid;
  gap: 0.55rem;
}

.hint {
  margin: 0;
  font-size: 0.78rem;
  color: #64748b;
  line-height: 1.35;
}

label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.78rem;
  color: #334155;
}

input[type='text'] {
  min-height: 2.4rem;
  padding: 0.35rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font: inherit;
}

.link-avanzado {
  justify-self: start;
  padding: 0;
  border: none;
  background: none;
  color: #2563eb;
  font: inherit;
  font-size: 0.78rem;
  cursor: pointer;
  text-decoration: underline;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.info {
  margin: 0;
  padding: 0.45rem 0.55rem;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  color: #92400e;
  font-size: 0.78rem;
}

.strong {
  font-weight: 700;
}

.error {
  margin: 0;
  padding: 0.45rem 0.55rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 8px;
  color: #be123c;
  font-size: 0.78rem;
}

.estado {
  margin: 0;
  font-size: 0.78rem;
  color: #64748b;
}

.pie {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.65rem 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.pie button {
  min-height: 2.4rem;
  padding: 0.35rem 0.75rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  font: inherit;
  cursor: pointer;
}

.pie .aceptar {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}
</style>
