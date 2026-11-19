<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue'
import CarpetaCompartidaExplorador from '@/components/carpeta/CarpetaCompartidaExplorador.vue'

const props = defineProps<{
  open: boolean
  ruta: string
}>()

const emit = defineEmits<{
  cerrar: []
}>()

function onTecla(e: KeyboardEvent) {
  if (e.key === 'Escape') emit('cerrar')
}

watch(
  () => props.open,
  (abierto) => {
    if (abierto) window.addEventListener('keydown', onTecla)
    else window.removeEventListener('keydown', onTecla)
  },
)

onBeforeUnmount(() => window.removeEventListener('keydown', onTecla))
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" aria-label="Carpeta compartida" @click.self="emit('cerrar')">
      <div class="dialogo">
        <header class="cabecera">
          <h2>Carpeta compartida</h2>
          <button type="button" class="cerrar" aria-label="Cerrar" @click="emit('cerrar')">×</button>
        </header>
        <div class="cuerpo">
          <CarpetaCompartidaExplorador :ruta="ruta" solo-archivos />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 4500;
  display: grid;
  place-items: center;
  padding: 1.5rem;
  background: rgba(15, 23, 42, 0.55);
}

.dialogo {
  display: flex;
  flex-direction: column;
  width: min(56rem, 100%);
  height: min(36rem, calc(100vh - 3rem));
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 18px 50px rgba(15, 23, 42, 0.35);
  overflow: hidden;
}

.cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.7rem 0.9rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
}

.cabecera h2 {
  margin: 0;
  font-size: 1rem;
}

.cerrar {
  width: 1.8rem;
  height: 1.8rem;
  padding: 0;
  border: 1px solid #7a8ec0;
  border-radius: 6px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  font-size: 1.3rem;
  line-height: 1;
  cursor: pointer;
}

.cuerpo {
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: 0.9rem 1rem 1rem;
}
</style>
