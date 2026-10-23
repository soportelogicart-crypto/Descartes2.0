<script setup lang="ts">
import { ref, watch } from 'vue'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'

const props = defineProps<{
  open: boolean
  inicial?: string
}>()

const emit = defineEmits<{
  confirmar: [string]
  cancelar: []
}>()

const entrada = ref('')

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    entrada.value = String(props.inicial ?? '').trim()
  },
  { immediate: true }
)

function pulsar(tecla: string) {
  if (entrada.value.length >= 20) return
  entrada.value += tecla
}

function confirmar() {
  const codigo = entrada.value.trim()
  if (codigo) emit('confirmar', codigo)
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.prevent>
      <section class="ventana" role="dialog" aria-modal="true">
        <header class="barra">CODIGO DE ARTICULO</header>

        <div class="cuerpo">
          <div class="visor">
            <span>CODIGO</span>
            <strong>{{ entrada || '—' }}</strong>
          </div>

          <TpvTecladoNumerico
            @tecla="pulsar"
            @borrar="entrada = entrada.slice(0, -1)"
            @limpiar="entrada = ''"
          />
        </div>

        <footer class="pie">
          <button type="button" class="btn aceptar" :disabled="!entrada.trim()" @click="confirmar">
            AÑADIR
          </button>
          <button type="button" class="btn" @click="emit('cancelar')">CANCELAR</button>
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
  background: rgb(0 0 0 / 45%);
}

.ventana {
  width: min(22rem, 94vw);
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

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin-bottom: 0.6rem;
  padding: 0.45rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font-family: 'Cascadia Mono', Consolas, monospace;
}

.visor span {
  font-size: 0.7rem;
  color: #64748b;
}

.visor strong {
  margin-left: auto;
  font-size: 1.5rem;
  font-variant-numeric: tabular-nums;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pie {
  display: flex;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.btn {
  flex: 1;
  min-height: 2.9rem;
  background: #e2e8f0;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #334155;
  font-family: inherit;
  font-size: 0.95rem;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.btn:active {
  transform: translateY(1px);
}

.btn:disabled {
  opacity: 0.45;
  cursor: default;
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
</style>
