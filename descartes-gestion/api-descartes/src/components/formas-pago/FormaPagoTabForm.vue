<script setup lang="ts">
import { computed } from 'vue'
import type { FormaPagoField, FormaPagoFieldOption, FormaPagoSection } from '@/config/formas-pago-tabs'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { entidadDesdeOptionsSource } from '@/config/entidad-lookup'

const props = defineProps<{
  sections: FormaPagoSection[]
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

/** Agrupa secciones consecutivas con el mismo `row` para mostrarlas en paralelo. */
const filasSecciones = computed(() => {
  const rows: { key: string; sections: FormaPagoSection[] }[] = []
  const list = seccionesVisibles.value
  let i = 0
  while (i < list.length) {
    const current = list[i]
    if (current.row && list[i + 1]?.row === current.row) {
      rows.push({ key: `${current.row}-${i}`, sections: [current, list[i + 1]] })
      i += 2
    } else {
      rows.push({ key: `solo-${i}-${current.title}`, sections: [current] })
      i += 1
    }
  }
  return rows
})

function updateField(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function isReadOnly(field: FormaPagoField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function displayNumber(key: string) {
  const value = props.modelValue[key]
  return value == null || value === '' ? '' : value
}

function selectValue(field: FormaPagoField) {
  const value = props.modelValue[field.key]
  if (value == null) return ''
  return String(value).trim()
}

function optionsFor(field: FormaPagoField): FormaPagoFieldOption[] {
  const base = field.options ?? []
  const current = selectValue(field)
  if (!current) return base
  if (base.some((o) => String(o.value) === current)) return base
  return [...base, { value: current, label: current }]
}

function onSelectChange(field: FormaPagoField, raw: string) {
  if (field.key === 'agrupacion') {
    updateField(field.key, raw === '' ? 0 : Number(raw))
    return
  }
  updateField(field.key, raw)
}

function sectionClass(section: FormaPagoSection) {
  return `cols-${section.columns ?? 4}`
}
</script>

<template>
  <div class="tab-form">
    <div
      v-for="fila in filasSecciones"
      :key="fila.key"
      class="section-row"
      :class="{ paired: fila.sections.length > 1 }"
    >
      <fieldset v-for="section in fila.sections" :key="section.title" class="form-section">
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
            <template v-if="field.type === 'checkbox' || field.layout === 'checkbox'">
              <input
                type="checkbox"
                :checked="Boolean(modelValue[field.key])"
                :disabled="isReadOnly(field)"
                @change="updateField(field.key, ($event.target as HTMLInputElement).checked)"
              />
              <span class="field-label">{{ field.label }}<em v-if="field.required"> *</em></span>
            </template>

            <template v-else>
              <span class="field-label">{{ field.label }}<em v-if="field.required"> *</em></span>

              <EntidadLookupField
                v-if="entidadDesdeOptionsSource(field.optionsSource)"
                :model-value="(modelValue[field.key] as string | number | null) ?? null"
                :entidad="entidadDesdeOptionsSource(field.optionsSource)!"
                :readonly="isReadOnly(field)"
                :max-length="field.maxLength"
                :field-key="field.key"
                @update:model-value="
                  updateField(field.key, typeof $event === 'number' ? $event : $event ?? 0)
                "
              />

              <select
                v-else-if="field.type === 'select'"
                :value="selectValue(field)"
                :disabled="isReadOnly(field)"
                @change="onSelectChange(field, ($event.target as HTMLSelectElement).value)"
              >
                <option v-for="opt in optionsFor(field)" :key="String(opt.value)" :value="String(opt.value)">
                  {{ opt.label }}
                </option>
              </select>

              <DecimalInput
                v-else-if="field.type === 'number'"
                :model-value="(modelValue[field.key] as number | null) ?? null"
                :empty-as-null="true"
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
            </template>
          </div>
        </div>
      </fieldset>
    </div>
  </div>
</template>

<style scoped>
.tab-form {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  padding: 0.45rem 0.55rem 0.55rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 8px;
  max-width: 1100px;
}

.section-row.paired {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.4rem;
  align-items: stretch;
}

.form-section {
  margin: 0;
  padding: 0.3rem 0.45rem 0.4rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
  height: 100%;
}

.form-section legend {
  padding: 0 0.3rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #334155;
}

.section-grid {
  display: grid;
  gap: 0.15rem 0.45rem;
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
  gap: 0.1rem;
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
  grid-template-columns: 8rem minmax(0, 1fr);
  align-items: center;
  gap: 0.35rem;
}

.field-checkbox {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
}

.field-checkbox .field-label {
  width: auto;
  max-width: none;
  overflow: visible;
  text-overflow: unset;
  white-space: normal;
  line-height: 1.2;
}

.field-label {
  font-size: 0.7rem;
  color: #475569;
  white-space: nowrap;
}

.field-inline .field-label {
  width: 8rem;
  max-width: 8rem;
  flex-shrink: 0;
  overflow: hidden;
  text-overflow: ellipsis;
}

.field-label em {
  color: #b91c1c;
  font-style: normal;
}

.field input[type='text'],
.field select {
  width: 100%;
  min-width: 0;
  padding: 0.15rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.78rem;
  background: #fff;
}

.field input[type='checkbox'] {
  width: 0.95rem;
  height: 0.95rem;
}

.field input:read-only,
.field input:disabled,
.field select:disabled {
  background: #f1f5f9;
  color: #64748b;
}

@media (max-width: 800px) {
  .section-row.paired {
    grid-template-columns: 1fr;
  }

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
