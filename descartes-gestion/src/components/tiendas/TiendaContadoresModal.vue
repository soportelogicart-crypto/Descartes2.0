<script setup lang="ts">
import { computed } from 'vue'
import { contadoresSections } from '@/config/tiendas-tabs'
import TiendaTabForm from './TiendaTabForm.vue'

const props = defineProps<{
  open: boolean
  modelValue: Record<string, unknown>
  readonly?: boolean
}>()

defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  cerrar: []
}>()

const titulo = computed(() => {
  const codigo = props.modelValue.codigo ?? ''
  const nombre = props.modelValue.nombre ?? ''
  return `Contadores — Tienda ${codigo} ${nombre}`.trim()
})

const contadoresSoloLectura = computed(() => props.readonly !== false)
</script>

<template>
  <div v-if="open" class="overlay" @click.self="$emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>{{ titulo }}</h3>
        <button type="button" class="close" @click="$emit('cerrar')">×</button>
      </header>
      <p class="hint">Valores actuales en la tabla Empresas (solo lectura).</p>
      <TiendaTabForm
        :sections="contadoresSections"
        :model-value="modelValue"
        :readonly="contadoresSoloLectura"
        @update:model-value="$emit('update:modelValue', $event)"
      />
      <footer class="modal-footer">
        <button type="button" @click="$emit('cerrar')">Cerrar</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 50;
  padding: 1rem;
}

.modal {
  width: min(720px, 100%);
  max-height: 90vh;
  overflow: auto;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.hint {
  margin: 0;
  padding: 0.5rem 1rem 0;
  font-size: 0.85rem;
  color: #64748b;
}

.close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid #e2e8f0;
}

button {
  padding: 0.45rem 0.9rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
  cursor: pointer;
}
</style>
