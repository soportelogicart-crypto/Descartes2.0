<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  buscarAutorizacionTarjetaPorAut,
  obtenerAutorizacionTarjetaAlbaran,
  type AutorizacionTarjetaItem,
} from '@/api/ventas'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'
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
/** En caja táctil no hay teclado físico: el pad escribe en el campo marcado. */
const campo = ref<'aut' | 'clr'>('aut')
const pedidoRedsys = ref('')
const rts = ref('')
const sinOriginal = ref(false)
const devolucionPinpad = ref(true)
const mostrarAvanzado = ref(false)
const loading = ref(false)
const procesando = ref(false)
const error = ref<string | null>(null)
/** Cobro original de la BD. No se muestra: el cajero debe teclear AUT y CLR del ticket. */
const original = ref<AutorizacionTarjetaItem | null>(null)
/** Segundo paso: datos comprobados, falta que el cajero confirme el importe. */
const verificado = ref<DevolucionDatafonoContexto | null>(null)

const puedeContinuar = computed(
  () => autorizacion.value.trim().length > 0 && clr.value.trim().length === 4
)

const importeTexto = computed(() =>
  Math.abs(Number(props.importeDevolucion ?? 0)).toLocaleString('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
)

function pulsar(tecla: string) {
  if (campo.value === 'clr') {
    if (clr.value.length >= 4) return
    clr.value += tecla
    return
  }
  if (autorizacion.value.length >= 15) return
  autorizacion.value += tecla
}

function borrarDigito() {
  if (campo.value === 'clr') {
    clr.value = clr.value.slice(0, -1)
    return
  }
  autorizacion.value = autorizacion.value.slice(0, -1)
}

function limpiarCampo() {
  if (campo.value === 'clr') clr.value = ''
  else autorizacion.value = ''
}

function normalizarAut(valor: string | undefined): string {
  const v = String(valor ?? '').trim().toUpperCase()
  return /^\d+$/.test(v) ? v.replace(/^0+(?=\d)/, '') : v
}

function ultimos4(valor: string | undefined): string {
  return (String(valor ?? '').replace(/\D/g, '') || '').slice(-4)
}

/** Cobros antiguos guardaron el código de respuesta Redsys ("00") en lugar del AUT. */
function autGuardado(valor: string | undefined): string {
  const v = normalizarAut(valor)
  return v.length <= 2 ? '' : v
}

function coincide(item: AutorizacionTarjetaItem): boolean {
  const autBd = autGuardado(item.autorizacion)
  const clrBd = ultimos4(item.clr || item.tarjeta)
  if (!autBd && !clrBd) return false
  if (autBd && autBd !== normalizarAut(autorizacion.value)) return false
  if (clrBd && clrBd !== ultimos4(clr.value)) return false
  return true
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
    campo.value = 'aut'
    pedidoRedsys.value = ''
    rts.value = ''
    sinOriginal.value = !props.albaranOrigen
    devolucionPinpad.value = true
    mostrarAvanzado.value = false
    error.value = null
    original.value = null
    verificado.value = null
    if (!props.albaranOrigen) return
    loading.value = true
    try {
      original.value = await obtenerAutorizacionTarjetaAlbaran(props.empresa, props.albaranOrigen)
    } catch (e: unknown) {
      error.value = extractApiError(e, 'No se pudo consultar el cobro del ticket original')
    } finally {
      loading.value = false
    }
  }
)

/**
 * La devolución solo sale si AUT y CLR coinciden con el cobro original:
 * sin esta comprobación cualquier número tecleado devolvería el dinero.
 */
async function continuar() {
  if (procesando.value || !puedeContinuar.value) return
  error.value = null
  procesando.value = true
  try {
    let pedido = pedidoRedsys.value.trim()
    let rtsVal = rts.value.trim()
    if (!sinOriginal.value) {
      let item = original.value
      if (!item || !coincide(item)) {
        // Sin cobro guardado en el ticket se busca por AUT + CLR, sin atajo por albarán.
        const encontrado = await buscarAutorizacionTarjetaPorAut(
          props.empresa,
          autorizacion.value.trim(),
          clr.value.trim(),
          props.importeDevolucion
        )
        item = encontrado && coincide(encontrado) ? encontrado : null
      }
      if (!item) {
        error.value =
          'El AUT o el CLR no coinciden con el cobro original. Revise el ticket del cliente y la tarjeta.'
        return
      }
      pedido = String(item.pedidoRedsys ?? '').trim() || pedido
      rtsVal = String(item.identificadorRts ?? '').trim() || rtsVal
      if (!pedido && !rtsVal) {
        error.value =
          'No se encontró la operación Redsys del cobro original. Use las opciones avanzadas o haga la devolución desde el TPV antiguo.'
        return
      }
    }
    verificado.value = {
      pedidoOriginal: pedido || undefined,
      rtsOriginal: rtsVal || undefined,
      codigoAutorizacion: autorizacion.value.trim(),
      clr: clr.value.trim(),
      devolucionSinOriginal: sinOriginal.value,
      devolucionPinpad: devolucionPinpad.value && Boolean(pedido),
      modoLegacyRts: Boolean(rtsVal) && !pedido,
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo comprobar el cobro original')
  } finally {
    procesando.value = false
  }
}

function volver() {
  verificado.value = null
}

function confirmar() {
  if (!verificado.value) return
  emit('confirmar', verificado.value)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @mousedown.self.prevent>
      <section class="ventana">
        <header class="barra">DEVOLUCIÓN EN DATÁFONO</header>

        <div v-if="verificado" class="cuerpo">
          <div class="resumen">
            <span>Importe a devolver</span>
            <strong>{{ importeTexto }} €</strong>
            <span>Tarjeta terminada en <strong>{{ verificado.clr }}</strong></span>
            <span v-if="albaranOrigen">Ticket origen: albarán {{ albaranOrigen }}</span>
          </div>
          <p class="aviso">
            <template v-if="verificado.devolucionPinpad">
              Al confirmar, pida al cliente que <strong>pase la tarjeta</strong> por el datáfono.
            </template>
            <template v-else>
              Al confirmar, la devolución se enviará directamente a Redsys.
            </template>
          </p>
        </div>

        <div v-else class="cuerpo">
          <p class="hint">
            Teclee el <strong>AUT</strong> del ticket del cliente (no el OPE) y los
            <strong>4 últimos dígitos</strong> de su tarjeta. Deben coincidir con el cobro original.
          </p>
          <p v-if="albaranOrigen" class="hint">
            Ticket origen: albarán interno <strong>{{ albaranOrigen }}</strong>
          </p>
          <p v-if="loading" class="estado">Cargando cobro original…</p>
          <p v-if="error" class="error">{{ error }}</p>
          <p v-if="procesando" class="estado strong">Comprobando…</p>

          <label :class="{ activo: campo === 'aut' }" @pointerdown="campo = 'aut'">
            Código autorización (AUT)
            <input
              v-model="autorizacion"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              maxlength="15"
              @focus="campo = 'aut'"
            />
          </label>
          <label :class="{ activo: campo === 'clr' }" @pointerdown="campo = 'clr'">
            CLR (4 últimos dígitos tarjeta)
            <input
              v-model="clr"
              type="text"
              inputmode="numeric"
              maxlength="4"
              autocomplete="off"
              @focus="campo = 'clr'"
            />
          </label>

          <TpvTecladoNumerico
            class="pad"
            @tecla="pulsar"
            @borrar="borrarDigito"
            @limpiar="limpiarCampo"
          />

          <button type="button" class="link-avanzado" @click="mostrarAvanzado = !mostrarAvanzado">
            {{ mostrarAvanzado ? 'Ocultar opciones avanzadas' : 'Opciones avanzadas' }}
          </button>

          <template v-if="mostrarAvanzado">
            <label>
              Pedido Redsys (solo si no está guardado en el ticket)
              <input v-model="pedidoRedsys" type="text" inputmode="numeric" autocomplete="off" />
            </label>
            <label>
              Identificador RTS (opcional)
              <input v-model="rts" type="text" autocomplete="off" />
            </label>
            <label class="check">
              <input v-model="devolucionPinpad" type="checkbox" />
              Pedir la tarjeta en el datáfono
            </label>
            <label class="check">
              <input v-model="sinOriginal" type="checkbox" />
              Devolución sin operación original (solo si Redsys lo tiene activo)
            </label>
          </template>
        </div>

        <footer class="pie">
          <template v-if="verificado">
            <button type="button" @click="volver">VOLVER</button>
            <button type="button" class="aceptar" @click="confirmar">CONFIRMAR DEVOLUCIÓN</button>
          </template>
          <template v-else>
            <button type="button" @click="emit('cancelar')">CANCELAR</button>
            <button
              type="button"
              class="aceptar"
              :disabled="loading || procesando || !puedeContinuar"
              @click="continuar"
            >
              CONTINUAR
            </button>
          </template>
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
  width: min(32rem, 96vw);
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

label.activo input {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgb(37 99 235 / 18%);
}

.pad {
  width: min(16rem, 100%);
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

.resumen {
  display: grid;
  gap: 0.25rem;
  padding: 0.75rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  font-size: 0.9rem;
}

.resumen > strong {
  font-size: 1.6rem;
  color: #be123c;
}

.aviso {
  margin: 0;
  padding: 0.55rem 0.65rem;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  color: #1e3a8a;
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
