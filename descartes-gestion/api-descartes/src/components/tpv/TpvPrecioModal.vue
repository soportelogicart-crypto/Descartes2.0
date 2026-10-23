<script setup lang="ts">
import { computed, ref, watch } from 'vue'

const props = defineProps<{
  open: boolean
  titulo: string
  articulo: string
  descripcion: string
  cantidad: number
  precioInicial?: number
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

const visor = computed(() => entrada.value || '0')
const importe = computed(() => (valor.value * props.cantidad).toFixed(2))
const valido = computed(() => valor.value > 0)

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    const ini = props.precioInicial ?? 0
    entrada.value = ini > 0 ? String(ini).replace('.', ',') : ''
  },
  { immediate: true }
)

function pulsar(tecla: string) {
  if (tecla === ',') {
    if (!entrada.value.includes(',')) {
      entrada.value = (entrada.value || '0') + ','
    }
    return
  }
  const [, decimales] = entrada.value.split(',')
  if (decimales !== undefined && decimales.length >= 2) return
  if (entrada.value.replace(',', '').length >= 8) return
  entrada.value = (entrada.value + tecla).replace(/^0+(?=\d)/, '')
}

function borrarUno() {
  entrada.value = entrada.value.slice(0, -1)
}

function limpiar() {
  entrada.value = ''
}

function confirmar() {
  if (!valido.value) return
  emit('confirmar', valor.value)
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="emit('cancelar')">
    <div class="ventana" role="dialog" aria-modal="true">
      <header class="barra">{{ titulo }}</header>

      <div class="cuerpo">
        <dl class="datos">
          <dt>Articulo</dt>
          <dd>{{ articulo }}</dd>
          <dt>Descripcion</dt>
          <dd>{{ descripcion || '—' }}</dd>
          <dt>Cantidad</dt>
          <dd>{{ cantidad }}</dd>
        </dl>

        <div class="visor">
          <span class="visor-lbl">PRECIO €</span>
          <span class="visor-val">{{ visor }}</span>
        </div>
        <p class="importe">Importe linea: <strong>{{ importe }} €</strong></p>

        <div class="pad">
          <button
            v-for="t in ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0']"
            :key="t"
            type="button"
            class="btn-legacy num"
            @click="pulsar(t)"
          >
            {{ t }}
          </button>
          <button type="button" class="btn-legacy num" @click="pulsar(',')">,</button>
          <button type="button" class="btn-legacy num aux" @click="borrarUno">←</button>
          <button type="button" class="btn-legacy num aux" @click="limpiar">C</button>
        </div>
      </div>

      <footer class="pie">
        <button type="button" class="btn-legacy aceptar" :disabled="!valido" @click="confirmar">
          ACEPTAR
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
  width: min(26rem, 94vw);
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: #0f172a;
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

.datos {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.1rem 0.6rem;
  margin: 0 0 0.6rem;
  font-size: 0.82rem;
}

.datos dt {
  color: #64748b;
}

.datos dd {
  margin: 0;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.45rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font-family: 'Cascadia Mono', Consolas, monospace;
}

.visor-lbl {
  font-size: 0.7rem;
  color: #64748b;
}

.visor-val {
  margin-left: auto;
  font-size: 1.8rem;
  font-weight: 600;
}

.importe {
  margin: 0.35rem 0 0.6rem;
  font-size: 0.8rem;
  text-align: right;
  color: #475569;
}

.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
}

.btn-legacy {
  min-height: 3.4rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-size: 0.85rem;
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

.num {
  font-size: 1.35rem;
  font-weight: 500;
}

.aux {
  background: #f1f5f9;
  color: #475569;
  font-size: 1.1rem;
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
