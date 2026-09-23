<script setup lang="ts">
import { nextTick, onUnmounted, ref, watch } from 'vue'
import { buscarClientesTpv } from '@/api/tpv'
import { extractApiError } from '@/composables/extractApiError'
import type { TpvCliente } from '@/types/tpv'
import { CLIENTE_RAPIDO_TPV } from '@/types/tpv'
import TpvTecladoAlfanumerico from '@/components/tpv/TpvTecladoAlfanumerico.vue'

const props = defineProps<{
  open: boolean
  /** Cliente ya asignado al ticket, si hay alguno. */
  actual?: TpvCliente | null
}>()

const emit = defineEmits<{
  seleccionar: [TpvCliente]
  quitar: []
  cancelar: []
}>()

const consulta = ref('')
const resultados = ref<TpvCliente[]>([])
const buscando = ref(false)
const error = ref<string | null>(null)
const buscado = ref(false)
const input = ref<HTMLInputElement | null>(null)
// No se reinicia al abrir: en una caja sin teclado físico se deja puesto.
const tecladoVisible = ref(false)

/** Espera entre pulsaciones antes de consultar, para no lanzar una peticion por tecla. */
const RETARDO_TECLEO = 250
/** Con una sola letra la lista seria medio fichero de clientes. */
const MINIMO_TECLEADO = 2
let temporizador: ReturnType<typeof setTimeout> | null = null

function cancelarBusquedaPendiente() {
  if (temporizador === null) return
  clearTimeout(temporizador)
  temporizador = null
}

watch(
  () => props.open,
  async (abierto) => {
    cancelarBusquedaPendiente()
    if (!abierto) return
    consulta.value = ''
    resultados.value = []
    error.value = null
    buscado.value = false
    await nextTick()
    input.value?.focus()
  },
  { immediate: true }
)

async function foco() {
  await nextTick()
  input.value?.focus()
}

function teclear(t: string) {
  consulta.value += t
  void foco()
}

function borrarUno() {
  consulta.value = consulta.value.slice(0, -1)
  void foco()
}

function limpiar() {
  consulta.value = ''
  void foco()
}

/** Descarta respuestas de consultas que ya han quedado atras al seguir tecleando. */
let ultimaPeticion = 0

async function buscar() {
  const q = consulta.value.trim()
  if (!q) return
  const peticion = ++ultimaPeticion
  buscando.value = true
  error.value = null
  try {
    const encontrados = await buscarClientesTpv(q)
    if (peticion !== ultimaPeticion) return
    resultados.value = encontrados
    buscado.value = true
  } catch (e: unknown) {
    if (peticion !== ultimaPeticion) return
    resultados.value = []
    error.value = extractApiError(e, 'No se pudo buscar el cliente')
  } finally {
    if (peticion === ultimaPeticion) buscando.value = false
  }
}

// Busca mientras se escribe; el boton BUSCAR queda como atajo.
watch(consulta, (texto) => {
  cancelarBusquedaPendiente()
  if (texto.trim().length < MINIMO_TECLEADO) {
    ultimaPeticion++
    resultados.value = []
    buscado.value = false
    buscando.value = false
    return
  }
  temporizador = setTimeout(() => {
    temporizador = null
    void buscar()
  }, RETARDO_TECLEO)
})

onUnmounted(cancelarBusquedaPendiente)

function etiqueta(c: TpvCliente): string {
  const partes = [c.poblacion, c.nif, c.email].filter((p) => String(p).trim() !== '')
  return partes.join(' · ')
}
</script>

<template>
  <div v-if="open" class="overlay">
    <div class="ventana" :class="{ ancha: tecladoVisible }" role="dialog" aria-modal="true">
      <header class="barra">ASIGNAR CLIENTE AL TICKET</header>

      <div class="cuerpo">
        <p v-if="actual" class="actual">
          Ticket a nombre de <strong>{{ actual.razonSocial || actual.codigo }}</strong>
        </p>

        <div class="buscador">
          <input
            ref="input"
            v-model="consulta"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="Codigo, NIF, nombre o telefono"
            @keydown.enter.prevent="buscar"
          />
          <button
            type="button"
            class="btn-legacy teclado-btn"
            :class="{ activo: tecladoVisible }"
            title="Teclado en pantalla"
            @click="tecladoVisible = !tecladoVisible"
          >
            TECLADO
          </button>
          <button
            type="button"
            class="btn-legacy"
            :disabled="!consulta.trim() || buscando"
            @click="buscar"
          >
            {{ buscando ? 'BUSCANDO…' : 'BUSCAR' }}
          </button>
        </div>

        <TpvTecladoAlfanumerico
          v-if="tecladoVisible"
          @tecla="teclear"
          @borrar="borrarUno"
          @limpiar="limpiar"
        />

        <p v-if="error" class="error">{{ error }}</p>

        <ul v-if="resultados.length" class="lista">
          <li v-for="c in resultados" :key="c.codigo">
            <button type="button" class="fila" @click="emit('seleccionar', c)">
              <span class="nombre">{{ c.razonSocial || '(sin nombre)' }}</span>
              <span class="datos">{{ etiqueta(c) }}</span>
              <span class="cod">{{ c.codigo }}</span>
            </button>
          </li>
        </ul>
        <p v-else-if="buscado && !buscando" class="vacio">Ningun cliente coincide con la busqueda</p>
      </div>

      <footer class="pie">
        <button type="button" class="btn-legacy quitar" :disabled="!actual" @click="emit('quitar')">
          VENTA SIN CLIENTE ({{ CLIENTE_RAPIDO_TPV }})
        </button>
        <button type="button" class="btn-legacy cancelar" @click="emit('cancelar')">CANCELAR</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
}

.ventana {
  display: flex;
  flex-direction: column;
  width: min(34rem, 96vw);
  max-height: 90vh;
  /* Con el teclado en pantalla hacen falta 10 teclas legibles por fila. */
  transition: width 0.1s linear;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: #0f172a;
}

.ventana.ancha {
  width: min(46rem, 96vw);
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}

.cuerpo {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-height: 0;
  padding: 0.75rem;
}

.actual {
  margin: 0;
  padding: 0.4rem 0.6rem;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
  color: #1d4ed8;
  font-size: 0.82rem;
}

.buscador {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 5px;
}

.teclado-btn.activo {
  background: #4338ca;
  border-color: #4338ca;
  color: #fff;
}

.buscador input {
  min-width: 0;
  padding: 0.5rem 0.6rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font-family: 'Cascadia Mono', Consolas, monospace;
  font-size: 1.05rem;
  color: #0f172a;
}

.buscador input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
}

.error {
  margin: 0;
  padding: 0.45rem 0.6rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 10px;
  color: #be123c;
  font-size: 0.8rem;
  font-weight: 600;
}

.vacio {
  margin: 0;
  padding: 0.5rem;
  font-size: 0.82rem;
  color: #64748b;
}

.lista {
  margin: 0;
  padding: 0;
  overflow: auto;
  min-height: 0;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  list-style: none;
}

.fila {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0 0.5rem;
  width: 100%;
  padding: 0.5rem 0.65rem;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #f1f5f9;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
}

.fila:hover,
.fila:focus-visible {
  background: #f1f5f9;
}

.nombre {
  font-size: 0.92rem;
  font-weight: 600;
}

.datos {
  grid-column: 1;
  font-size: 0.75rem;
  color: #64748b;
}

.cod {
  grid-row: 1 / span 2;
  align-self: center;
  font-family: 'Cascadia Mono', Consolas, monospace;
  font-size: 0.8rem;
  color: #94a3b8;
}

.btn-legacy {
  min-height: 2.8rem;
  padding: 0.3rem 0.7rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.btn-legacy:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

.btn-legacy:active:not(:disabled) {
  transform: translateY(1px);
}

.btn-legacy:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.quitar {
  background: #f1f5f9;
  color: #475569;
}

.cancelar {
  background: #e2e8f0;
  border-color: #cbd5e1;
  color: #334155;
}
</style>
