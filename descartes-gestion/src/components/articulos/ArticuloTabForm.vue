<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { ArticuloField, ArticuloSection } from '@/config/articulos-tabs'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { entidadDesdeOptionsSource } from '@/config/entidad-lookup'

const props = defineProps<{
  sections: ArticuloSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  /** Keys de campos a marcar como error de validacion. */
  invalidKeys?: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

type FamiliaOpt = { value: string; macroFamiliaCodigo: string }

const familiasAll = ref<FamiliaOpt[]>([])

const allFields = computed(() => props.sections.flatMap((s) => s.fields))

onMounted(async () => {
  const needsFamilias = allFields.value.some(
    (f) => f.optionsSource === 'familias' || f.optionsSource === 'macrofamilias'
  )
  if (!needsFamilias) return
  try {
    const { data } = await api.get('/api/mantenimiento/familias', { params: { pageSize: 500 } })
    familiasAll.value = (data.items ?? []).map(
      (f: { codigo: string; macroFamiliaCodigo?: string }) => ({
        value: String(f.codigo).trim(),
        macroFamiliaCodigo: String(f.macroFamiliaCodigo ?? '').trim(),
      })
    )
  } catch {
    familiasAll.value = []
  }
})

function lookupEntidadField(field: ArticuloField) {
  if (!field.lookup) return null
  return entidadDesdeOptionsSource(field.optionsSource)
}

function updateField(key: string, value: unknown) {
  const next = { ...props.modelValue, [key]: value }
  if (key === 'precioVen1') next.precioVenta = value
  if (key === 'macroFamilia') {
    const macro = String(value ?? '').trim()
    const fam = String(next.familia ?? '').trim()
    if (fam) {
      const hit = familiasAll.value.find((f) => f.value === fam)
      if (hit && hit.macroFamiliaCodigo !== macro) next.familia = null
    }
  }
  if (key === 'familia') {
    const fam = String(value ?? '').trim()
    const hit = familiasAll.value.find((f) => f.value === fam)
    if (hit?.macroFamiliaCodigo) next.macroFamilia = hit.macroFamiliaCodigo
  }
  emit('update:modelValue', next)
}
function isInvalid(field: ArticuloField) {
  return (props.invalidKeys ?? []).includes(field.key)
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

function gridClass(section: ArticuloSection) {
  return [sectionClass(section), section.flow === 'column' ? 'flow-column' : '']
}

function anchoInput(field: ArticuloField, section: ArticuloSection) {
  if (fieldLayout(field) !== 'inline') return undefined
  return field.inputWidth ?? section.inputWidth
}

/**
 * El ancho se aplica como pista de la rejilla del campo, no como max-width del
 * control: asi todos los campos de la seccion arrancan y terminan igual aunque
 * la etiqueta sea mas larga. '100%' significa ocupar la celda entera.
 */
function fieldStyle(field: ArticuloField, section: ArticuloSection) {
  const ancho = anchoInput(field, section)
  if (!ancho) return undefined
  return { '--input-col': ancho === '100%' ? '1fr' : `minmax(0, ${ancho})` }
}

function gridStyle(section: ArticuloSection) {
  const estilo: Record<string, string> = {}
  if (section.flow === 'column') {
    estilo.gridTemplateRows = `repeat(${section.rows ?? section.fields.length}, auto)`
  }
  if (section.labelWidth) {
    estilo['--label-col'] = `minmax(0, ${section.labelWidth})`
  }
  return Object.keys(estilo).length > 0 ? estilo : undefined
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
    <fieldset
      v-for="section in sections"
      :key="section.title"
      class="form-section"
      :class="[sectionZoneClass(section), section.title === 'Clasificacion' ? 'section-clasificacion' : '']"
    >
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
      <div class="section-grid" :class="gridClass(section)" :style="gridStyle(section)">
        <div
          v-for="field in section.fields"
          :key="field.key"
          class="field"
          :class="[
            `span-${field.span ?? 1}`,
            `field-${fieldLayout(field)}`,
            field.type === 'number' ? 'field-number' : '',
            section.hideFieldLabels ? 'field-no-label' : '',
            isInvalid(field) ? 'field-invalid' : '',
            lookupEntidadField(field) ? 'field-lookup' : '',
            anchoInput(field, section) ? 'field-ancho-fijo' : '',
          ]"
          :style="fieldStyle(field, section)"
        >
          <span v-if="!section.hideFieldLabels" class="field-label">
            {{ field.label }}<em v-if="field.required"> *</em>
          </span>

          <textarea
            v-if="field.type === 'textarea'"
            :data-field-key="field.key"
            :value="String(modelValue[field.key] ?? '')"
            :readonly="isReadOnly(field)"
            rows="5"
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

          <EntidadLookupField
            v-else-if="lookupEntidadField(field)"
            variant="combo"
            :model-value="(modelValue[field.key] as string | number | null) ?? null"
            :entidad="lookupEntidadField(field)!"
            :readonly="isReadOnly(field)"
            :max-length="field.maxLength"
            :field-key="field.key"
            empty-as-null
            @update:model-value="updateField(field.key, $event)"
          />

          <input
            v-else-if="field.type === 'date'"
            type="date"
            :data-field-key="field.key"
            :value="displayDate(field.key)"
            :readonly="isReadOnly(field)"
            @input="updateField(field.key, ($event.target as HTMLInputElement).value || null)"
          />

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

/* Rellena cada columna de arriba abajo (grid-template-rows va en el estilo inline). */
.section-grid.flow-column {
  grid-auto-flow: column;
}

.field {
  min-width: 0;
}

.field-invalid .field-label {
  color: #b91c1c;
  font-weight: 600;
}

.field-invalid input,
.field-invalid select,
.field-invalid textarea,
.field-invalid :deep(.lookup-combo) {
  border-color: #dc2626 !important;
  background: #fef2f2 !important;
  box-shadow: 0 0 0 1px #fecaca;
}

.field-invalid :deep(.lookup-combo .lookup-codigo),
.field-invalid :deep(.lookup-combo .lookup-nombre-inner) {
  background: transparent !important;
  box-shadow: none;
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
  /* --label-col y --input-col fijan las pistas para que todos los campos de la seccion coincidan. */
  grid-template-columns: var(--label-col, minmax(5.2rem, auto)) var(--input-col, 1fr);
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

/* Con ancho de pista manda la rejilla, no el tope por tipo de campo. */
.field.field-ancho-fijo input:not(.lookup-codigo),
.field.field-ancho-fijo select {
  max-width: none;
  width: 100%;
}

/* El selector de fecha necesita hueco para dd/mm/aaaa mas el icono del calendario. */
.field input[type='date'] {
  max-width: 11rem;
  padding-right: 0.2rem;
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

input:read-only:not(.lookup-codigo),
textarea:read-only,
select:disabled {
  background: #e8edf2;
  color: #475569;
}

.field-lookup.field-inline {
  grid-template-columns: var(--label-col, minmax(5.2rem, auto)) minmax(0, 1fr);
}

.section-clasificacion .field-inline {
  grid-template-columns: var(--label-col, minmax(6.25rem, auto)) minmax(0, 1fr);
}

.section-clasificacion :deep(.lookup-combo) {
  --lookup-codigo-w: 3.35rem;
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
