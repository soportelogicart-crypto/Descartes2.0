<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { PuestoField, PuestoSection } from '@/config/puestos-tabs'

const props = defineProps<{
  sections: PuestoSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const trabajadoresOptions = ref<{ value: string; label: string }[]>([])
const usuariosOptions = ref<{ value: string; label: string }[]>([])
const tiendasOptions = ref<{ value: string; label: string }[]>([])

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections.map((section) => ({
    ...section,
    fields: section.fields.filter((f) => f.key !== 'codigo' && f.key !== 'descripcion'),
  })).filter((section) => section.fields.length > 0)
})

const allFields = computed(() => props.sections.flatMap((s) => s.fields))

onMounted(async () => {
  const needsTrabajadores = allFields.value.some((f) => f.optionsSource === 'trabajadores')
  const needsUsuarios = allFields.value.some((f) => f.optionsSource === 'usuarios')
  const needsTiendas = allFields.value.some((f) => f.optionsSource === 'tiendas')

  const requests: Promise<void>[] = []

  if (needsTrabajadores) {
    requests.push(
      api.get('/api/mantenimiento/trabajadores', { params: { activo: true, pageSize: 500 } }).then(({ data }) => {
        trabajadoresOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
          value: String(t.codigo).trim(),
          label: `${String(t.codigo).trim()} - ${t.nombre}`,
        }))
      })
    )
  }

  if (needsUsuarios) {
    requests.push(
      api.get('/api/mantenimiento/usuarios', { params: { activo: true, pageSize: 500 } }).then(({ data }) => {
        usuariosOptions.value = (data.items ?? []).map((u: { codigo: string; nombre: string }) => ({
          value: String(u.codigo).trim(),
          label: `${String(u.codigo).trim()} - ${u.nombre}`,
        }))
      })
    )
  }

  if (needsTiendas) {
    requests.push(
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }).then(({ data }) => {
        tiendasOptions.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
          value: String(t.codigo).trim(),
          label: `${String(t.codigo).trim()} - ${t.nombre}`,
        }))
      })
    )
  }

  await Promise.all(requests)
})

function optionsFor(field: PuestoField) {
  if (field.optionsSource === 'trabajadores') return trabajadoresOptions.value
  if (field.optionsSource === 'usuarios') return usuariosOptions.value
  if (field.optionsSource === 'tiendas') return tiendasOptions.value
  return []
}

function updateField(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function isReadOnly(field: PuestoField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function displayNumber(key: string) {
  const value = props.modelValue[key]
  return value == null || value === '' ? 0 : value
}

function sectionClass(section: PuestoSection) {
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
  padding: 0.5rem 0.65rem 0.65rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 8px;
  max-width: 920px;
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
  grid-template-columns: minmax(5.5rem, auto) 1fr;
  gap: 0.35rem;
  align-items: center;
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

.field-number input {
  max-width: 5rem;
}

.field.required .field-label em {
  color: #b91c1c;
  font-style: normal;
}

input,
select {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font-size: 0.8rem;
  line-height: 1.25;
}

input[type='checkbox'] {
  width: 0.9rem;
  height: 0.9rem;
}

input:read-only,
select:disabled {
  background: #e8edf2;
  color: #475569;
}

@media (max-width: 800px) {
  .section-grid.cols-4,
  .section-grid.cols-3 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field.span-3,
  .field.span-4 {
    grid-column: span 2;
  }
}
</style>
