<script setup lang="ts">
import ToolIcon from '@/components/common/ToolIcon.vue'

defineProps<{
  label: string
  modelValue: string
  placeholder?: string
  maxlength?: number | string
  title?: string
}>()

defineEmits<{
  'update:modelValue': [value: string]
  buscar: []
}>()
</script>

<template>
  <div class="filtro-lupa-field" :title="title">
    <span class="filtro-lupa-label">{{ label }}</span>
    <div class="filtro-lupa">
      <input
        :value="modelValue"
        :placeholder="placeholder"
        :maxlength="maxlength != null ? Number(maxlength) : undefined"
        @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
        @keydown.enter.prevent="$emit('buscar')"
        @keydown.f4.prevent="$emit('buscar')"
      />
      <button
        type="button"
        class="btn-lupa"
        title="Buscar"
        @click.stop.prevent="$emit('buscar')"
      >
        <ToolIcon name="buscar" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.filtro-lupa-field {
  display: flex;
  flex-direction: column;
  font-size: 0.75rem;
  gap: 0.2rem;
  color: #475569;
}
.filtro-lupa-label {
  line-height: 1.2;
}
.filtro-lupa {
  display: flex;
  gap: 0.25rem;
  align-items: stretch;
  min-width: 0;
}
.filtro-lupa input {
  flex: 1;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.35rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}
.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  flex-shrink: 0;
  border: 1px solid #64748b;
  border-radius: 4px;
  background: #fff;
  color: #334155;
  cursor: pointer;
  padding: 0;
}
.btn-lupa:hover {
  background: #eff6ff;
  border-color: #2563eb;
  color: #1d4ed8;
}
.btn-lupa :deep(.tool-icon) {
  width: 1rem;
  height: 1rem;
}
</style>
