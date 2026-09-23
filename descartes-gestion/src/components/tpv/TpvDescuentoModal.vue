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
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}

.cuerpo {
  padding: 0.75rem;
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
  padding: 0.45rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font-family: 'Cascadia Mono', Consolas, monospace;
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
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.btn:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

.btn:active:not(:disabled) {
  transform: translateY(1px);
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.num {
  font-size: 1.3rem;
  font-weight: 500;
}

.limpiar {
  background: #f1f5f9;
  color: #475569;
  font-size: 0.75rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.aceptar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.aceptar:hover:not(:disabled) {
  background: #047857;
  border-color: #047857;
}

.cancelar {
  background: #e2e8f0;
  border-color: #cbd5e1;
  color: #334155;
}
</style>
