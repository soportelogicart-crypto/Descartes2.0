<script setup lang="ts">
import { nextTick, onUnmounted, ref, watch } from 'vue'
import { buscarArticulosTpv } from '@/api/tpv'
import TpvTecladoAlfanumerico from '@/components/tpv/TpvTecladoAlfanumerico.vue'
import { extractApiError } from '@/composables/extractApiError'
import type { TpvArticuloPrecio } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  tarifa: number
  /** Texto ya tecleado en la caja, para no obligar a escribirlo otra vez. */
  inicial?: string
}>()

const emit = defineEmits<{
  seleccionar: [TpvArticuloPrecio]
  cancelar: []
}>()

const consulta = ref('')
const resultados = ref<TpvArticuloPrecio[]>([])
const buscando = ref(false)
const error = ref<string | null>(null)
const buscado = ref(false)
const tecladoVisible = ref(true)
const input = ref<HTMLInputElement | null>(null)

const MINIMO = 2
let temporizador: ReturnType<typeof setTimeout> | null = null
let ultimaPeticion = 0

function cancelarPendiente() {
  if (temporizador === null) return
  clearTimeout(temporizador)
  temporizador = null
}

watch(
  () => props.open,
  async (abierto) => {
    cancelarPendiente()
    if (!abierto) return
    consulta.value = String(props.inicial ?? '').trim()
    resultados.value = []
    error.value = null
    buscado.value = false
    await nextTick()
    input.value?.focus()
    if (consulta.value.length >= MINIMO) void buscar()
  }
)

watch(consulta, (texto) => {
  cancelarPendiente()
  if (texto.trim().length < MINIMO) {
    ultimaPeticion++
    resultados.value = []
    buscado.value = false
    return
  }
  temporizador = setTimeout(() => {
    temporizador = null
    void buscar()
  }, 250)
})

onUnmounted(cancelarPendiente)

async function buscar() {
  const q = consulta.value.trim()
  if (q.length < MINIMO) return
  const peticion = ++ultimaPeticion
  buscando.value = true
  error.value = null
  try {
    const items = await buscarArticulosTpv(q, {
      tarifa: props.tarifa,
      limite: 30,
      ambito: 'articulo',
    })
    if (peticion !== ultimaPeticion) return
    resultados.value = items
    buscado.value = true
  } catch (e: unknown) {
    if (peticion !== ultimaPeticion) return
    error.value = extractApiError(e, 'No se pudo buscar el artículo')
  } finally {
    if (peticion === ultimaPeticion) buscando.value = false
  }
}

function euros(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}
</script>

<template>
  <div v-if="open" class="overlay">
    <section class="ventana" :class="{ ancha: tecladoVisible }" role="dialog" aria-modal="true">
      <header class="barra">BUSCAR ARTÍCULO</header>
      <div class="cuerpo">
        <div class="buscador">
          <input
            ref="input"
            v-model="consulta"
            type="text"
            autocomplete="off"
            spellcheck="false"
            placeholder="Descripción o código"
            @keydown.enter.prevent="buscar"
          />
          <button
            type="button"
            class="teclado-btn"
            :class="{ activo: tecladoVisible }"
            @click="tecladoVisible = !tecladoVisible"
          >
            TECLADO
          </button>
        </div>

        <TpvTecladoAlfanumerico
          v-if="tecladoVisible"
          @tecla="consulta += $event"
          @borrar="consulta = consulta.slice(0, -1)"
          @limpiar="consulta = ''"
        />

        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="buscando" class="estado">Buscando…</p>

        <ul v-if="resultados.length" class="lista">
          <li v-for="a in resultados" :key="a.codigo">
            <button type="button" class="fila" @click="emit('seleccionar', a)">
              <span class="nombre">{{ a.descripcion || a.codigo }}</span>
              <span class="cod">{{ a.codigo }}</span>
              <span class="precio">{{ euros(a.precio) }}</span>
            </button>
          </li>
        </ul>
        <p v-else-if="buscado && !buscando" class="estado">Ningún artículo coincide</p>
        <p v-else-if="consulta.trim().length < MINIMO" class="estado">
          Escriba al menos {{ MINIMO }} letras
        </p>
      </div>
      <footer class="pie">
        <button type="button" class="cerrar" @click="emit('cancelar')">CANCELAR</button>
      </footer>
    </section>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 55%);
}

.ventana {
  display: flex;
  flex-direction: column;
  width: min(34rem, 96vw);
  max-height: 92vh;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.ventana.ancha {
  width: min(52rem, 96vw);
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  min-height: 0;
  padding: 0.75rem;
  overflow: auto;
}

.buscador {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 0.4rem;
}

input,
button {
  min-height: 2.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font: inherit;
}

input {
  padding: 0.35rem 0.6rem;
}

input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgb(37 99 235 / 18%);
}

.teclado-btn {
  padding: 0.3rem 0.7rem;
  background: #fff;
  font-weight: 600;
  cursor: pointer;
}

.teclado-btn.activo {
  background: #1e293b;
  border-color: #1e293b;
  color: #fff;
}

.lista {
  display: grid;
  gap: 4px;
  margin: 0.6rem 0 0;
  padding: 0;
  list-style: none;
}

.fila {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  gap: 0.6rem;
  align-items: center;
  width: 100%;
  padding: 0.45rem 0.6rem;
  background: #fff;
  text-align: left;
  cursor: pointer;
}

.fila:hover {
  background: #f8fafc;
  border-color: #94a3b8;
}

.nombre {
  overflow: hidden;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cod,
.precio {
  color: #64748b;
  font-size: 0.85rem;
}

.precio {
  font-variant-numeric: tabular-nums;
}

.estado,
.error {
  margin: 0.55rem 0 0;
  font-size: 0.85rem;
}

.error {
  color: #be123c;
}

.estado {
  color: #64748b;
}

.pie {
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.cerrar {
  width: 100%;
  background: #e2e8f0;
  font-weight: 600;
  cursor: pointer;
}
</style>
