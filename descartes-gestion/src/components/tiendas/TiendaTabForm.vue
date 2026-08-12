<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { TiendaField, TiendaSection } from '@/config/tiendas-tabs'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'
import DecimalInput from '@/components/common/DecimalInput.vue'

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
  if (value == null || value === '') return 0
  const n = Number(value)
  if (!Number.isFinite(n)) return 0
  // Copias y cols: mostrar enteros aunque la API/BD traiga 2.0
  if (
    key.startsWith('copias') ||
    key.startsWith('colAtri') ||
    key === 'decimalesPrecio' ||
    key === 'literalInvitacion'
  ) {
    return Math.trunc(n)
  }
  return n
}

function sectionClass(section: TiendaSection) {
  if (section.variant === 'atributos') return 'atributos-grid'
  if (section.variant === 'copias') return 'copias-grid'
  return `cols-${section.columns ?? 4}`
}

function fieldLayout(field: TiendaField) {
  if (field.layout) return field.layout
  if (field.type === 'checkbox') return 'checkbox'
  if (field.type === 'textarea') return 'textarea'
  return 'inline'
}

function atributosPairs(section: TiendaSection) {
  const nombres = section.fields.filter((f) => /^atri\d+$/.test(f.key))
  const cols = section.fields.filter((f) => /^colAtri\d+$/.test(f.key))
  const appWeb = section.fields.find((f) => f.key === 'appWeb')
  const pairs = nombres.map((atri, i) => ({
    atri,
    col: cols[i] ?? null,
  }))
  return { pairs, appWeb }
}
</script>

<template>
  <div class="tab-form">
    <fieldset v-for="section in seccionesVisibles" :key="section.title" class="form-section">
      <legend>{{ section.title }}</legend>

      <!-- Atributos / Col: filas compactas como legacy -->
      <div v-if="section.variant === 'atributos'" class="atributos-wrap">
        <div class="atributos-head">
          <span>Atributos</span>
          <span>Col</span>
        </div>
        <div
          v-for="pair in atributosPairs(section).pairs"
          :key="pair.atri.key"
          class="atributos-row"
        >
          <input
            type="text"
            maxlength="15"
            :value="String(modelValue[pair.atri.key] ?? '')"
            :readonly="isReadOnly(pair.atri)"
            @input="updateField(pair.atri.key, ($event.target as HTMLInputElement).value)"
          />
          <DecimalInput
            v-if="pair.col"
            :integer="true"
            :model-value="(modelValue[pair.col.key] as number | null) ?? null"
            :empty-as-null="false"
            :readonly="isReadOnly(pair.col)"
            @update:model-value="updateField(pair.col.key, $event ?? 0)"
          />
        </div>
        <label
          v-if="atributosPairs(section).appWeb"
          class="app-web-row"
          :class="{ 'field-invalid': esInvalido('appWeb') }"
        >
          <input
            type="checkbox"
            :checked="Boolean(modelValue.appWeb)"
            :disabled="readonly"
            @change="updateField('appWeb', ($event.target as HTMLInputElement).checked)"
          />
          App Web
        </label>
      </div>

      <!-- Copias: lista densa etiqueta + input corto -->
      <div v-else-if="section.variant === 'copias'" class="copias-wrap">
        <label
          v-for="field in section.fields"
          :key="field.key"
          class="copias-row"
          :class="{ 'field-invalid': esInvalido(field.key) }"
        >
          <span>{{ field.label }}</span>
          <DecimalInput
            :integer="true"
            :model-value="(modelValue[field.key] as number | null) ?? null"
            :empty-as-null="true"
            :readonly="isReadOnly(field)"
            @update:model-value="updateField(field.key, $event)"
          />
        </label>
      </div>

      <div v-else class="section-grid" :class="sectionClass(section)">
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
            :data-field-key="field.key"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            rows="2"
            @input="updateField(field.key, ($event.target as HTMLTextAreaElement).value)"
          />

          <input
            v-else-if="field.type === 'checkbox'"
            type="checkbox"
            :data-field-key="field.key"
            :checked="Boolean(modelValue[field.key])"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLInputElement).checked)"
          />

          <select
            v-else-if="field.type === 'select'"
            :data-field-key="field.key"
            :value="modelValue[field.key] != null ? String(modelValue[field.key]).trim() : ''"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLSelectElement).value || null)"
          >
            <option value="">--</option>
            <option v-for="opt in optionsFor(field)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <select
            v-else-if="field.type === 'impuesto'"
            :data-field-key="field.key"
            :value="modelValue[field.key] != null ? String(modelValue[field.key]).trim() : ''"
            :disabled="isReadOnly(field)"
            @change="updateField(field.key, ($event.target as HTMLSelectElement).value || null)"
          >
            <option value="">--</option>
            <option v-for="opt in impuestosOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
          </select>

          <DecimalInput
            v-else-if="field.type === 'number'"
            :field-key="field.key"
            :model-value="(modelValue[field.key] as number | null) ?? null"
            :empty-as-null="true"
            :readonly="isReadOnly(field)"
            @update:model-value="updateField(field.key, $event)"
          />

          <input
            v-else
            type="text"
            :data-field-key="field.key"
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
  grid-template-columns: 7.75rem minmax(0, 1fr);
  gap: 0.35rem;
  align-items: center;
}

.field-inline .field-label {
  font-size: 0.78rem;
  color: #475569;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: left;
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

.atributos-wrap {
  width: fit-content;
  max-width: 100%;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
}

.atributos-head {
  display: grid;
  grid-template-columns: 9.5rem 2.75rem;
  gap: 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #475569;
  padding: 0 0.1rem;
}

.atributos-row {
  display: grid;
  grid-template-columns: 9.5rem 2.75rem;
  gap: 0.35rem;
  align-items: center;
}

.atributos-row input[type='text'] {
  width: 100%;
  box-sizing: border-box;
  padding: 0.12rem 0.3rem;
  font-size: 0.78rem;
}

.atributos-row :deep(input[inputmode='numeric']),
.atributos-row :deep(input[inputmode='decimal']) {
  width: 100%;
  box-sizing: border-box;
  padding: 0.12rem 0.2rem;
  font-size: 0.78rem;
  text-align: right;
}

.app-web-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-top: 0.35rem;
  font-size: 0.78rem;
  color: #475569;
}

.app-web-row input {
  width: auto;
  margin: 0;
}

.copias-wrap {
  display: flex;
  flex-direction: column;
  gap: 0.18rem;
  width: min(22rem, 100%);
}

.copias-row {
  display: grid;
  grid-template-columns: 1fr 3.25rem;
  gap: 0.45rem;
  align-items: center;
  margin: 0;
  font-size: 0.78rem;
  color: #334155;
}

.copias-row input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.15rem 0.3rem;
  text-align: right;
  font-size: 0.8rem;
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
