<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

/** La columna Descripcion de la línea de albarán es nvarchar(50). */
const MAXIMO = 50

const props = defineProps<{
  open: boolean
  articulo: string
  descripcion: string
}>()

const emit = defineEmits<{
  confirmar: [string]
  cancelar: []
}>()

const entrada = ref('')
const mayusculas = ref(true)
const input = ref<HTMLInputElement | null>(null)

const filas = ['1234567890', 'qwertyuiop', 'asdfghjklñ', 'zxcvbnm']
const teclas = computed(() =>
  filas.map((fila) => [...fila].map((t) => (mayusculas.value ? t.toUpperCase() : t)))
)

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    entrada.value = props.descripcion.slice(0, MAXIMO)
    mayusculas.value = true
    await nextTick()
    input.value?.focus()
    input.value?.select()
  },
  { immediate: true }
)

function escribir(tecla: string) {
  if (entrada.value.length >= MAXIMO) return
  entrada.value += tecla
}

function confirmar() {
  emit('confirmar', entrada.value.trim().slice(0, MAXIMO))
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @mousedown.self.prevent>
      <section class="ventana">
        <header class="barra">DESCRIPCIÓN DE LÍNEA</header>
        <div class="cuerpo">
          <p class="articulo">Artículo {{ articulo }}</p>
          <input
            ref="input"
            v-model="entrada"
            type="text"
            :maxlength="MAXIMO"
            autocomplete="off"
            spellcheck="false"
          />
          <p class="cuenta">{{ entrada.length }}/{{ MAXIMO }}</p>

          <div class="teclado" @mousedown.prevent>
            <div v-for="(fila, i) in teclas" :key="i" class="fila">
              <button
                v-for="t in fila"
                :key="t"
                type="button"
                class="tecla"
                @click="escribir(t)"
              >
                {{ t }}
              </button>
            </div>
            <div class="fila">
              <button
                type="button"
                class="tecla ancha"
                :class="{ activa: mayusculas }"
                @click="mayusculas = !mayusculas"
              >
                MAYÚS
              </button>
              <button type="button" class="tecla espacio" @click="escribir(' ')">espacio</button>
              <button type="button" class="tecla ancha" @click="entrada = entrada.slice(0, -1)">
                ←
              </button>
            </div>
          </div>
        </div>
        <footer class="pie">
          <button type="button" @click="emit('cancelar')">CANCELAR</button>
          <button type="button" class="aceptar" @click="confirmar">ACEPTAR</button>
        </footer>
      </section>
    </div>
  </Teleport>
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
  width: min(40rem, 96vw);
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  padding: 0.75rem;
}

.articulo {
  margin: 0 0 0.4rem;
  color: #64748b;
  font-size: 0.8rem;
}

input {
  box-sizing: border-box;
  width: 100%;
  min-height: 2.8rem;
  padding: 0.4rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font: inherit;
  font-size: 1.05rem;
}

input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgb(37 99 235 / 18%);
}

.cuenta {
  margin: 0.25rem 0 0.55rem;
  color: #64748b;
  font-size: 0.75rem;
  text-align: right;
}

.fila {
  display: flex;
  justify-content: center;
  gap: 4px;
  margin-bottom: 4px;
}

.tecla {
  flex: 1;
  min-width: 0;
  min-height: 2.7rem;
  padding: 0.2rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  color: #1e293b;
  font: inherit;
  font-size: 1rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}

.tecla:active {
  background: #e2e8f0;
}

.ancha {
  flex: 1.6;
  background: #f1f5f9;
  font-size: 0.78rem;
}

.ancha.activa {
  background: #1e293b;
  border-color: #1e293b;
  color: #fff;
}

.espacio {
  flex: 4;
  font-size: 0.78rem;
  color: #475569;
}

.pie {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.pie button {
  min-height: 2.6rem;
  padding: 0.4rem 0.9rem;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  background: #fff;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}

.pie .aceptar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}
</style>
