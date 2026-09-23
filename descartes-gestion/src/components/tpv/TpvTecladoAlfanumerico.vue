<script setup lang="ts">
/**
 * Teclado en pantalla para los campos de texto del TPV (búsqueda de cliente,
 * observaciones). Complementa a `TpvTecladoNumerico`, que solo da dígitos.
 *
 * Emite en mayúsculas porque es como se teclea en caja y las búsquedas del
 * servidor no distinguen mayúsculas.
 */

const emit = defineEmits<{
  tecla: [string]
  borrar: []
  limpiar: []
}>()

const FILAS: string[][] = [
  ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'],
  ['Q', 'W', 'E', 'R', 'T', 'Y', 'U', 'I', 'O', 'P'],
  ['A', 'S', 'D', 'F', 'G', 'H', 'J', 'K', 'L', 'Ñ'],
  ['Z', 'X', 'C', 'V', 'B', 'N', 'M', '.', '-', '@'],
]
</script>

<template>
  <!-- mousedown.prevent: pulsar una tecla no debe robar el foco del input. -->
  <div class="pad" @mousedown.prevent>
    <div v-for="(fila, i) in FILAS" :key="i" class="fila">
      <button
        v-for="t in fila"
        :key="t"
        type="button"
        class="tecla"
        @click="emit('tecla', t)"
      >
        {{ t }}
      </button>
    </div>

    <div class="fila">
      <button type="button" class="tecla espacio" @click="emit('tecla', ' ')">ESPACIO</button>
      <button type="button" class="tecla aux" @click="emit('borrar')">←</button>
      <button type="button" class="tecla aux" @click="emit('limpiar')">BORRAR TODO</button>
    </div>
  </div>
</template>

<style scoped>
.pad {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
}

.fila {
  display: flex;
  gap: 5px;
}

.tecla {
  flex: 1 1 0;
  min-width: 0;
  min-height: 2.6rem;
  padding: 0.2rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  color: #1e293b;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  font-size: 1.05rem;
  font-weight: 500;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.tecla:hover {
  background: #f1f5f9;
  border-color: #94a3b8;
}

.tecla:active {
  transform: translateY(1px);
}

.tecla.espacio {
  flex: 4 1 0;
  font-size: 0.8rem;
}

.tecla.aux {
  flex: 2 1 0;
  background: #f1f5f9;
  color: #475569;
  font-size: 0.8rem;
}
</style>
