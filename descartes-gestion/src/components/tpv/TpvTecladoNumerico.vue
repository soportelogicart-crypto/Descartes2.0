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
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  border-radius: 0;
  color: #000;
  font-family: 'Segoe UI', Tahoma, sans-serif;
  font-size: 1.25rem;
  font-weight: 700;
  cursor: pointer;
}

.tecla:active {
  border-style: inset;
}

.tecla.aux {
  background: #c8ccd4;
  font-size: 1.05rem;
}
</style>
