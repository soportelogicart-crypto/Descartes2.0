<script setup lang="ts">
import { computed } from 'vue'
import type { AlmacenField, AlmacenSection } from '@/config/almacenes-tabs'
import DecimalInput from '@/components/common/DecimalInput.vue'

const props = defineProps<{
  sections: AlmacenSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections
    .map((section) => ({
      ...section,
      fields: section.fields.filter((f) => f.key !== 'codigo' && f.key !== 'descripcion'),
    }))
    .filter((section) => section.fields.length > 0)
})

function updateField(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function isReadOnly(field: AlmacenField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function displayNumber(key: string) {
  const value = props.modelValue[key]
  return value == null || value === '' ? '' : value
}

function sectionClass(section: AlmacenSection) {
  return `cols-${section.columns ?? 4}`
}
</script>

<template>
  <div class="tab-form">
    <fieldset v-for="section in seccionesVisibles" :key="section.title" class="form-section">
      <legend>{{ section.title }}</legend>
      <div class="section-grid" :class="sectionClass(section)">
        <div
          v-for="field in section.fields"
          :key="field.key"
          class="field"
          :class="[
            `span-${field.span ?? 1}`,
            field.layout === 'inline' ? 'field-inline' : '',
            field.layout === 'checkbox' ? 'field-checkbox' : '',
            field.type === 'number' ? 'field-number' : '',
          ]"
        >
          <span class="field-label">{{ field.label }}<em v-if="field.required"> *</em></span>

          <input
            v-if="field.type === 'checkbox'"
            type="checkbox"
            :checked="Boolean(modelValue[field.key])"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLInputElement).checked)"
          />

          <DecimalInput
            v-else-if="field.type === 'number'"
            :model-value="(modelValue[field.key] as number | null) ?? null"
            :empty-as-null="true"
            :integer="true"
            :readonly="isReadOnly(field)"
            @update:model-value="updateField(field.key, $event)"
          />

          <input
            v-else
            type="text"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            :maxlength="field.maxLength"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>
    </fieldset>

    <p
      v-if="Array.isArray(modelValue.tiendasVinculadas) && modelValue.tiendasVinculadas.length"
      class="tiendas-vinculadas"
    >
      Tiendas vinculadas: {{ (modelValue.tiendasVinculadas as string[]).join(', ') }}
    </p>
  </div>
</template>

<style scoped>
.tab-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.5rem 0.65rem 0.65rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 8px;
  max-width: 720px;
}

.form-section {
  margin: 0;
  padding: 0.35rem 0.5rem 0.45rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.section-grid {
  display: grid;
  gap: 0.2rem 0.5rem;
  align-items: center;
}

.section-grid.cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.section-grid.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.section-grid.cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
}

.field.span-2 {
  grid-column: span 2;
}

.field.span-3 {
  grid-column: span 3;
}

.field.span-4 {
  grid-column: span 4;
}

.field-inline {
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.35rem;
}

.field-checkbox {
  grid-template-columns: auto auto;
  justify-content: start;
  align-items: center;
  gap: 0.35rem;
}

.field-label {
  font-size: 0.72rem;
  color: #475569;
  white-space: nowrap;
}

.field-label em {
  color: #b91c1c;
  font-style: normal;
}

.field input[type='text'] {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
}

.field input[type='checkbox'] {
  width: 1rem;
  height: 1rem;
}

.field input:read-only,
.field input:disabled {
  background: #f1f5f9;
  color: #64748b;
}

.tiendas-vinculadas {
  margin: 0;
  padding: 0.4rem 0.5rem;
  font-size: 0.8rem;
  color: #334155;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
}

@media (max-width: 720px) {
  .section-grid.cols-2,
  .section-grid.cols-3,
  .section-grid.cols-4 {
    grid-template-columns: 1fr;
  }

  .field.span-2,
  .field.span-3,
  .field.span-4 {
    grid-column: span 1;
  }

  .field-inline {
    grid-template-columns: 1fr;
  }
}
</style>
