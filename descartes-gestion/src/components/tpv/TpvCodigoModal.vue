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
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
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

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  margin-bottom: 0.6rem;
  padding: 0.4rem 0.6rem;
  background: #000;
  border: 2px inset #808080;
  color: #30ff30;
}

.visor span {
  font-size: 0.72rem;
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
  gap: 4px;
  padding: 0 0.6rem 0.6rem;
}

.btn {
  flex: 1;
  min-height: 2.9rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  border-radius: 0;
  font-family: inherit;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
}

.btn:active {
  border-style: inset;
}

.btn:disabled {
  color: #777;
  cursor: default;
}

.aceptar {
  background: #c8e0c8;
}
</style>
