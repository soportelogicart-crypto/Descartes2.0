<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  articulo: string
  descripcion: string
  descuentoInicial: number
}>()

const emit = defineEmits<{
  confirmar: [number]
  cancelar: []
}>()

const entrada = ref('')

const valor = computed(() => {
  const n = Number(entrada.value.replace(',', '.'))
  return Number.isFinite(n) ? n : 0
})

const valido = computed(() => valor.value >= 0 && valor.value <= 100)

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    entrada.value = props.descuentoInicial
      ? String(props.descuentoInicial).replace('.', ',')
      : ''
  },
  { immediate: true }
)

function pulsar(tecla: string) {
  if (tecla === ',') {
    if (!entrada.value.includes(',')) entrada.value = (entrada.value || '0') + ','
    return
  }
  const [, decimales] = entrada.value.split(',')
  if (decimales !== undefined && decimales.length >= 2) return
  if (entrada.value.replace(',', '').length >= 5) return
  entrada.value = (entrada.value + tecla).replace(/^0+(?=\d)/, '')
}

function confirmar() {
  if (valido.value) emit('confirmar', valor.value)
}
</script>

<template>
  <div v-if="open" class="overlay" @mousedown.prevent>
    <div class="ventana" role="dialog" aria-modal="true">
      <header class="barra">DESCUENTO DE LINEA</header>
      <div class="cuerpo">
        <p class="articulo">{{ articulo }} · {{ descripcion }}</p>
        <div class="visor">
          <span>DTO.</span>
          <strong>{{ entrada || '0' }} %</strong>
        </div>
        <div class="pad">
          <button
            v-for="t in ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0']"
            :key="t"
            type="button"
            class="btn num"
            @click="pulsar(t)"
          >
            {{ t }}
          </button>
          <button type="button" class="btn num" @click="pulsar(',')">,</button>
          <button type="button" class="btn num" @click="entrada = entrada.slice(0, -1)">←</button>
          <button type="button" class="btn limpiar" @click="entrada = ''">SIN DTO.</button>
        </div>
      </div>
      <footer class="pie">
        <button type="button" class="btn aceptar" :disabled="!valido" @click="confirmar">
          ACEPTAR
        </button>
        <button type="button" class="btn cancelar" @click="emit('cancelar')">CANCELAR</button>
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
  width: min(25rem, 94vw);
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  box-shadow: 3px 3px 10px rgba(0, 0, 0, 0.5);
  color: #000;
  font-family: 'Segoe UI', Tahoma, sans-serif;
}

.barra {
  padding: 0.35rem 0.6rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
  font-size: 0.88rem;
  font-weight: 700;
}

.cuerpo {
  padding: 0.6rem;
}

.articulo {
  margin: 0 0 0.5rem;
  overflow: hidden;
  font-size: 0.82rem;
  font-weight: 600;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.visor {
  display: flex;
  align-items: baseline;
  padding: 0.35rem 0.6rem;
  background: #000;
  border: 2px inset #808080;
  color: #4ade80;
  font-family: Consolas, monospace;
}

.visor strong {
  margin-left: auto;
  font-size: 1.8rem;
}

.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
  margin-top: 0.6rem;
}

.btn {
  min-height: 3.2rem;
  padding: 0.3rem 0.5rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: inherit;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
}

.btn:active:not(:disabled) {
  border-style: inset;
}

.btn:disabled {
  color: #8a8a8a;
  cursor: not-allowed;
}

.num {
  font-size: 1.3rem;
}

.limpiar {
  background: #e8e0d0;
  font-size: 0.75rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 5px;
  padding: 0 0.6rem 0.6rem;
}

.aceptar {
  background: #c8e0c8;
}

.cancelar {
  background: #e0c8c8;
}
</style>
