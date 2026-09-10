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
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  box-shadow: 3px 3px 10px rgba(0, 0, 0, 0.5);
  font-family: 'Segoe UI', Tahoma, sans-serif;
  color: #000;
}

.ventana.ancha {
  width: min(46rem, 96vw);
}

.barra {
  padding: 0.35rem 0.6rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
  font-size: 0.88rem;
  font-weight: 700;
}

.cuerpo {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-height: 0;
  padding: 0.6rem;
}

.actual {
  margin: 0;
  padding: 0.3rem 0.5rem;
  background: #e0e8f8;
  border: 1px solid #8090b0;
  font-size: 0.82rem;
}

.buscador {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 5px;
}

.teclado-btn.activo {
  background: #ffd36a;
  border-style: inset;
}

.buscador input {
  min-width: 0;
  padding: 0.45rem 0.5rem;
  background: #fff;
  border: 2px inset #f0f0f0;
  font-family: Consolas, monospace;
  font-size: 1.05rem;
  color: #000;
}

.error {
  margin: 0;
  padding: 0.35rem 0.5rem;
  background: #ffd0cc;
  border: 1px solid #a00000;
  font-size: 0.8rem;
  font-weight: 700;
}

.vacio {
  margin: 0;
  padding: 0.5rem;
  font-size: 0.82rem;
  color: #4b4b4b;
}

.lista {
  margin: 0;
  padding: 0;
  overflow: auto;
  min-height: 0;
  background: #fff;
  border: 2px inset #f0f0f0;
  list-style: none;
}

.fila {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 0 0.5rem;
  width: 100%;
  padding: 0.45rem 0.6rem;
  background: transparent;
  border: 0;
  border-bottom: 1px solid #d0d0d0;
  font-family: inherit;
  text-align: left;
  cursor: pointer;
}

.fila:hover,
.fila:focus-visible {
  background: #cfe0ff;
}

.nombre {
  font-size: 0.92rem;
  font-weight: 700;
}

.datos {
  grid-column: 1;
  font-size: 0.75rem;
  color: #505050;
}

.cod {
  grid-row: 1 / span 2;
  align-self: center;
  font-family: Consolas, monospace;
  font-size: 0.8rem;
  color: #303030;
}

.btn-legacy {
  min-height: 2.8rem;
  padding: 0.3rem 0.7rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: inherit;
  font-size: 0.82rem;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
}

.btn-legacy:active:not(:disabled) {
  border-style: inset;
}

.btn-legacy:disabled {
  color: #8a8a8a;
  text-shadow: 1px 1px 0 #fff;
  cursor: not-allowed;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 5px;
  padding: 0 0.6rem 0.6rem;
}

.quitar {
  background: #e8e0d0;
}

.cancelar {
  background: #e0c8c8;
}
</style>
