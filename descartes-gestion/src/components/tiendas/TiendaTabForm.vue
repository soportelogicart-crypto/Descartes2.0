<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { TiendaField, TiendaSection } from '@/config/tiendas-tabs'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'

const props = defineProps<{
  sections: TiendaSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
  camposInvalidos?: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const almacenesOptions = ref<{ value: string; label: string }[]>([])
const impuestosOptions = ref<{ value: string; label: string }[]>([])
const formasPagoOptions = ref<{ value: string; label: string }[]>([])
const almacenesCargados = ref(false)
const impuestosCargados = ref(false)
const formasPagoCargadas = ref(false)

const invalidSet = computed(() => new Set(props.camposInvalidos ?? []))

function esInvalido(key: string) {
  return invalidSet.value.has(key)
}

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections
    .map((section) => ({
      ...section,
      fields: section.fields.filter((f) => f.key !== 'codigo' && f.key !== 'nombre'),
    }))
    .filter((section) => section.fields.length > 0)
})

const allFields = computed(() => props.sections.flatMap((s) => s.fields))

async function cargarAlmacenes() {
  if (almacenesCargados.value) return
  if (!allFields.value.some((f) => f.optionsSource === 'almacenes')) return
  try {
    const { data } = await api.get('/api/mantenimiento/almacenes', {
      params: { activo: true, pageSize: 200 },
    })
    almacenesOptions.value = (data.items ?? []).map((a: { codigo: number; descripcion: string }) => ({
      value: String(a.codigo),
      label: `${a.codigo} - ${a.descripcion}`,
    }))
    almacenesCargados.value = true
  } catch {
    almacenesOptions.value = []
  }
}

async function cargarImpuestos() {
  if (impuestosCargados.value) return
  if (!allFields.value.some((f) => f.type === 'impuesto')) return
  try {
    const { data } = await api.get('/api/mantenimiento/impuestos', {
      params: { activo: true, pageSize: 100 },
    })
    impuestosOptions.value = (data.items ?? []).map((i: { codigo: string; descripcion: string }) => ({
      value: String(i.codigo).trim(),
      label: `${String(i.codigo).trim()} - ${i.descripcion}`,
    }))
    impuestosCargados.value = true
  } catch {
    impuestosOptions.value = []
  }
}

async function cargarFormasPago() {
  if (formasPagoCargadas.value) return
  if (!allFields.value.some((f) => f.optionsSource === 'formas-pago')) return
  try {
    const { data } = await api.get('/api/mantenimiento/formas-pago', {
      params: { activo: true, pageSize: 500 },
    })
    formasPagoOptions.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({
      value: String(f.codigo).trim(),
      label: `${String(f.codigo).trim()} - ${f.descripcion}`,
    }))
    formasPagoCargadas.value = true
  } catch {
    formasPagoOptions.value = []
  }
}

async function cargarOpcionesTab() {
  await Promise.all([cargarAlmacenes(), cargarImpuestos(), cargarFormasPago()])
}

onMounted(() => {
  void cargarOpcionesTab()
})

watch(
  () => props.sections,
  () => {
    void cargarOpcionesTab()
  },
  { deep: true }
)

function optionsFor(field: TiendaField) {
  if (field.optionsSource === 'almacenes') return almacenesOptions.value
  if (field.optionsSource === 'formas-pago') return formasPagoOptions.value
  return []
}

function updateField(key: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

let cpLookupSeq = 0

async function onCodigoPostalInput(raw: string) {
  updateField('codigoPostal', raw)
  if (props.readonly) return
  const cp = raw.trim()
  if (cp.length < 4) return
  const seq = ++cpLookupSeq
  try {
    const data = await lookupCodigoPostal(cp)
    if (seq !== cpLookupSeq || !data) return
    const next = { ...props.modelValue, codigoPostal: raw }
    if (data.poblacion) next.poblacion = data.poblacion
    if (data.provincia) next.provincia = data.provincia
    emit('update:modelValue', next)
  } catch {
    // Silencioso: el usuario puede rellenar a mano
  }
}

function isReadOnly(field: TiendaField) {
  return props.readonly || field.readOnly || (field.key === 'codigo' && props.codigoReadOnly)
}

function displayNumber(key: string) {
  const value = props.modelValue[key]
  return value == null || value === '' ? 0 : value
}

function sectionClass(section: TiendaSection) {
  return `cols-${section.columns ?? 4}`
}

function fieldLayout(field: TiendaField) {
  if (field.layout) return field.layout
  if (field.type === 'checkbox') return 'checkbox'
  if (field.type === 'textarea') return 'textarea'
  return 'inline'
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
            `field-${fieldLayout(field)}`,
            field.type === 'number' ? 'field-number' : '',
            esInvalido(field.key) ? 'field-invalid' : '',
          ]"
        >
          <span class="field-label">{{ field.label }}<em v-if="field.required"> *</em></span>

          <textarea
            v-if="field.type === 'textarea'"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            rows="2"
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

          <select
            v-else-if="field.type === 'impuesto'"
            :value="modelValue[field.key] != null ? String(modelValue[field.key]).trim() : ''"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLSelectElement).value || null)"
          >
            <option value="">--</option>
            <option v-for="opt in impuestosOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
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
            @input="
              field.key === 'codigoPostal'
                ? onCodigoPostalInput(($event.target as HTMLInputElement).value)
                : updateField(field.key, ($event.target as HTMLInputElement).value)
            "
            @blur="
              field.key === 'codigoPostal'
                ? onCodigoPostalInput(($event.target as HTMLInputElement).value)
                : undefined
            "
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

.section-grid.cols-5 {
  grid-template-columns: repeat(5, minmax(0, 1fr));
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

.field-textarea {
  display: grid;
  gap: 0.15rem;
}

.field-textarea .field-label {
  font-size: 0.78rem;
  color: #475569;
}

.field-number input {
  max-width: 5rem;
}

.field.required .field-label em {
  color: #b91c1c;
  font-style: normal;
}

.field-invalid .field-label {
  color: #b91c1c;
  font-weight: 700;
}

.field-invalid input,
.field-invalid select,
.field-invalid textarea {
  border-color: #dc2626 !important;
  background: #fef2f2 !important;
  box-shadow: 0 0 0 1px #fca5a5;
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
  min-height: 2.5rem;
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

@media (max-width: 800px) {
  .section-grid.cols-4,
  .section-grid.cols-3,
  .section-grid.cols-5 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field.span-3,
  .field.span-4 {
    grid-column: span 2;
  }
}
</style>
