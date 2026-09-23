<script setup lang="ts">
withDefaults(
  defineProps<{
    /** Añade la tecla de decimales (importes, descuentos). */
    conComa?: boolean
    /** Etiqueta de la tecla de borrado total. */
    etiquetaLimpiar?: string
  }>(),
  {
    conComa: false,
    etiquetaLimpiar: 'C',
  }
)

const emit = defineEmits<{
  tecla: [string]
  borrar: []
  limpiar: []
}>()
</script>

<template>
  <div class="pad" @mousedown.prevent>
    <button
      v-for="d in ['7', '8', '9', '4', '5', '6', '1', '2', '3']"
      :key="d"
      type="button"
      class="tecla"
      @click="emit('tecla', d)"
    >
      {{ d }}
    </button>
    <button type="button" class="tecla aux" @click="emit('limpiar')">
      {{ etiquetaLimpiar }}
    </button>
    <button type="button" class="tecla" @click="emit('tecla', '0')">0</button>
    <button v-if="conComa" type="button" class="tecla" @click="emit('tecla', ',')">,</button>
    <button type="button" class="tecla aux" @click="emit('borrar')">←</button>
  </div>
</template>

<style scoped>
.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 4px;
}

.tecla {
  min-height: 3.2rem;
  padding: 0.3rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  font-size: 1.25rem;
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

.tecla.aux {
  background: #f1f5f9;
  color: #475569;
  font-size: 1.05rem;
}
</style>
