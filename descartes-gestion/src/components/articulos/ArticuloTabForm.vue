<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { ArticuloField, ArticuloSection } from '@/config/articulos-tabs'

const props = defineProps<{
  sections: ArticuloSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const familiasOptions = ref<{ value: string; label: string }[]>([])
const subfamiliasOptions = ref<{ value: string; label: string }[]>([])
const agrupacionesOptions = ref<{ value: string; label: string }[]>([])
const impuestosOptions = ref<{ value: string; label: string }[]>([])
const proveedoresOptions = ref<{ value: string; label: string }[]>([])

const allFields = computed(() => props.sections.flatMap((s) => s.fields))

onMounted(async () => {
  const needs = {
    familias: allFields.value.some((f) => f.optionsSource === 'familias'),
    subfamilias: allFields.value.some((f) => f.optionsSource === 'subfamilias'),
    agrupaciones: allFields.value.some((f) => f.optionsSource === 'agrupaciones'),
    impuestos: allFields.value.some((f) => f.optionsSource === 'impuestos'),
    proveedores: allFields.value.some((f) => f.optionsSource === 'proveedores'),
  }

  const requests: Promise<void>[] = []

  if (needs.familias) {
    requests.push(
      api.get('/api/mantenimiento/familias', { params: { pageSize: 500 } }).then(({ data }) => {
        familiasOptions.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({
          value: String(f.codigo).trim(),
          label: `${String(f.codigo).trim()} - ${f.descripcion}`,
        }))
      })
    )
  }
  if (needs.subfamilias) {
    requests.push(
      api.get('/api/mantenimiento/subfamilias', { params: { pageSize: 500 } }).then(({ data }) => {
        subfamiliasOptions.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({
          value: String(f.codigo).trim(),
          label: `${String(f.codigo).trim()} - ${f.descripcion}`,
        }))
      })
    )
  }
  if (needs.agrupaciones) {
    requests.push(
      api.get('/api/mantenimiento/agrupaciones', { params: { pageSize: 500 } }).then(({ data }) => {
        agrupacionesOptions.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({
          value: String(f.codigo).trim(),
          label: `${String(f.codigo).trim()} - ${f.descripcion}`,
        }))
      })
    )
  }
  if (needs.impuestos) {
    requests.push(
      api.get('/api/mantenimiento/impuestos', { params: { activo: true, pageSize: 100 } }).then(({ data }) => {
        impuestosOptions.value = (data.items ?? []).map((i: { codigo: string; descripcion: string }) => ({
          value: String(i.codigo).trim(),
          label: `${String(i.codigo).trim()} - ${i.descripcion}`,
        }))
      })
    )
  }
  if (needs.proveedores) {
    requests.push(
      api.get('/api/mantenimiento/proveedores', { params: { activo: true, pageSize: 500 } }).then(({ data }) => {
        proveedoresOptions.value = (data.items ?? []).map((p: { codigo: string; nombre: string }) => ({
          value: String(p.codigo).trim(),
          label: `${String(p.codigo).trim()} - ${p.nombre}`,
        }))
      })
    )
  }

  await Promise.all(requests)
})

function optionsFor(field: ArticuloField) {
  if (field.optionsSource === 'familias') return familiasOptions.value
  if (field.optionsSource === 'subfamilias') return subfamiliasOptions.value
  if (field.optionsSource === 'agrupaciones') return agrupacionesOptions.value
  if (field.optionsSource === 'impuestos') return impuestosOptions.value
  if (field.optionsSource === 'proveedores') return proveedoresOptions.value
  return []
}

function updateField(key: string, value: unknown) {
  const next = { ...props.modelValue, [key]: value }
  if (key === 'precioVen1') next.precioVenta = value
  emit('update:modelValue', next)
}

function isReadOnly(field: ArticuloField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function displayNumber(key: string) {
  const value = props.modelValue[key]
  return value == null || value === '' ? 0 : value
}

function displayDate(key: string) {
  const value = props.modelValue[key]
  if (!value) return ''
  const s = String(value)
  return s.length >= 10 ? s.slice(0, 10) : s
}

function sectionClass(section: ArticuloSection) {
  return `cols-${section.columns ?? 4}`
}

function fieldLayout(field: ArticuloField) {
  if (field.layout) return field.layout
  if (field.type === 'checkbox') return 'checkbox'
  if (field.type === 'textarea') return 'textarea'
  return 'inline'
}

const isTarifasLayout = computed(() =>
  props.sections.some((s) => s.title.startsWith('Unidades') || s.title === 'Precios' || s.title === 'Costes')
)

function sectionZoneClass(section: ArticuloSection) {
  if (!isTarifasLayout.value) return ''
  if (section.title.startsWith('Unidades')) return 'zone-especiales'
  if (section.title === 'Precios') return 'zone-precios'
  if (section.title === 'Costes') return 'zone-costes'
  return ''
}
</script>

<template>
  <div class="tab-form" :class="{ 'tab-form-tarifas': isTarifasLayout }">
    <fieldset v-for="section in sections" :key="section.title" class="form-section" :class="sectionZoneClass(section)">
      <legend>{{ section.title }}</legend>
      <div
        v-if="section.columnHeaders?.length"
        class="section-headers"
        :class="sectionClass(section)"
      >
        <span v-for="(header, idx) in section.columnHeaders" :key="`${section.title}-h-${idx}`" class="col-header">
          {{ header }}
        </span>
      </div>
      <div class="section-grid" :class="sectionClass(section)">
        <div
          v-for="field in section.fields"
          :key="field.key"
          class="field"
          :class="[
            `span-${field.span ?? 1}`,
            `field-${fieldLayout(field)}`,
            field.type === 'number' ? 'field-number' : '',
            section.hideFieldLabels ? 'field-no-label' : '',
          ]"
        >
          <span v-if="!section.hideFieldLabels" class="field-label">
            {{ field.label }}<em v-if="field.required"> *</em>
          </span>

          <textarea
            v-if="field.type === 'textarea'"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            rows="5"
            @input="updateField(field.key, ($event.target as HTMLTextAreaElement).value)"
          />

          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            :checked="Boolean(modelValue[field.key])"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLInputElement).checked)"
          />

          <select
            v-else-if="field.type === 'select'"
            :value="modelValue[field.key] != null ? String(modelValue[field.key]).trim() : ''"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLSelectElement).value || null)"
          >
            <option value="">--</option>
            <option v-for="opt in optionsFor(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <input
            v-else-if="field.type === 'date'"
            type="date"
            :value="displayDate(field.key)"
            :readonly="isReadOnly(field)"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value || null)"
          />

          <input
            v-else-if="field.type === 'number'"
            type="number"
            :value="displayNumber(field.key) as number"
            :readonly="isReadOnly(field)"
            step="any"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value))"
          />

          <input
            v-else
            type="text"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value)"
          />
        </div>
      </div>
    </fieldset>
  </div>
</template>

<style scoped>
.tab-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.5rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 0;
  min-height: 320px;
  max-width: 1100px;
  width: 100%;
  box-sizing: border-box;
}

.tab-form-tarifas {
  display: grid;
  grid-template-columns: minmax(150px, 0.85fr) minmax(200px, 1.15fr);
  grid-template-areas:
    'especiales precios'
    'especiales costes';
  gap: 0.45rem;
  align-items: start;
}

.tab-form-tarifas .zone-especiales {
  grid-area: especiales;
}

.tab-form-tarifas .zone-precios {
  grid-area: precios;
}

.tab-form-tarifas .zone-costes {
  grid-area: costes;
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

.section-headers {
  display: grid;
  gap: 0.2rem 0.5rem;
  margin-bottom: 0.15rem;
}

.section-headers .col-header {
  font-size: 0.72rem;
  font-weight: 600;
  color: #475569;
  text-align: center;
}

.section-grid {
  display: grid;
  gap: 0.2rem 0.5rem;
  align-items: center;
}

.section-grid.cols-1,
.section-headers.cols-1 {
  grid-template-columns: minmax(0, 1fr);
}

.section-grid.cols-2,
.section-headers.cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.section-grid.cols-3,
.section-headers.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.section-grid.cols-4,
.section-headers.cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.field {
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
  display: grid;
  grid-template-columns: minmax(5.2rem, auto) 1fr;
  gap: 0.35rem;
  align-items: center;
}

.field-inline.field-no-label {
  grid-template-columns: 1fr;
}

.field-inline .field-label {
  font-size: 0.78rem;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.field-checkbox {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.1rem 0;
}

.field-checkbox .field-label {
  order: 2;
  font-size: 0.78rem;
  color: #475569;
  line-height: 1.2;
}

.field-checkbox input[type='checkbox'] {
  order: 1;
  margin: 0;
  flex-shrink: 0;
}

.field-textarea {
  display: grid;
  gap: 0.15rem;
}

.field-textarea .field-label {
  font-size: 0.78rem;
  color: #475569;
}

.field-number input {
  max-width: 7rem;
}

.field-no-label.field-number input {
  max-width: none;
  width: 100%;
  text-align: right;
}

.field.required .field-label em {
  color: #b91c1c;
  font-style: normal;
}

input,
select,
textarea {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font-size: 0.8rem;
  line-height: 1.25;
}

textarea {
  resize: vertical;
  min-height: 4rem;
}

input[type='checkbox'] {
  width: 0.9rem;
  height: 0.9rem;
}

input:read-only,
textarea:read-only,
select:disabled {
  background: #e8edf2;
  color: #475569;
}

@media (max-width: 720px) {
  .tab-form-tarifas {
    grid-template-columns: 1fr;
    grid-template-areas:
      'especiales'
      'precios'
      'costes';
  }
}
</style>
