<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  type ProveedorField,
  type ProveedorSection,
} from '@/config/proveedores-tabs'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const props = defineProps<{
  sections: ProveedorSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
  camposInvalidos?: string[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
}>()

const formasPagoOptions = ref<{ value: string; label: string }[]>([])
const formasPagoCargadas = ref(false)
const buscarFormaPagoOpen = ref(false)

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections
    .map((section) => ({
      ...section,
      fields: section.fields.filter((f) => f.key !== 'codigo' && f.key !== 'nombre'),
    }))
    .filter((section) => section.fields.length > 0)
})

const filasSecciones = computed(() => {
  const rows: { key: string; sections: ProveedorSection[] }[] = []
  const list = seccionesVisibles.value
  let i = 0
  while (i < list.length) {
    const current = list[i]
    if (current.pair && list[i + 1]?.pair === current.pair) {
      rows.push({ key: `${current.pair}-${i}`, sections: [current, list[i + 1]] })
      i += 2
    } else {
      rows.push({ key: `solo-${i}-${current.title}`, sections: [current] })
      i += 1
    }
  }
  return rows
})

const allFields = computed(() => props.sections.flatMap((s) => s.fields))

function codigoBancoDisplay(): string {
  const v = props.modelValue.cuentaBanco
  if (v == null || v === '') return ''
  const s = String(v).trim()
  if (s === '0' || s === '0.0') return ''
  if (/^\d+\.0+$/.test(s)) return s.replace(/\.0+$/, '')
  return s
}

async function cargarFormasPago() {
  if (formasPagoCargadas.value) return
  if (!allFields.value.some((f) => f.optionsSource === 'formas-pago')) return
  try {
    const { data } = await api.get('/api/mantenimiento/formas-pago', {
      params: { activo: true, pageSize: 500 },
    })
    formasPagoOptions.value = (data.items ?? []).map((f: { codigo: string; descripcion: string }) => {
      const codigo = String(f.codigo).trim()
      return { value: codigo, label: codigo }
    })
    formasPagoCargadas.value = true
  } catch {
    formasPagoOptions.value = []
  }
}

onMounted(() => {
  void cargarFormasPago()
})

watch(
  () => props.sections,
  () => {
    void cargarFormasPago()
  },
  { deep: true }
)

function optionsFor(field: ProveedorField) {
  if (field.options) return field.options
  if (field.optionsSource === 'formas-pago') return formasPagoOptions.value
  return []
}

function selectValue(field: ProveedorField): string {
  const raw = String(props.modelValue[field.key] ?? '').trim()
  if (raw) return raw
  if (field.key === 'tratamientoFiscal') return 'N'
  return ''
}

function isReadOnly(field: ProveedorField) {
  if (props.readonly || field.readOnly) return true
  if (field.key === 'codigo' && props.codigoReadOnly) return true
  return false
}

function isInvalid(field: ProveedorField) {
  return (props.camposInvalidos ?? []).includes(field.key)
}

function displayValue(field: ProveedorField) {
  const value = props.modelValue[field.key]
  if (field.type === 'checkbox') return Boolean(value)
  if (field.type === 'number') return value == null || value === '' ? '' : value
  return value ?? ''
}

function controlStyle(field: ProveedorField): Record<string, string> | undefined {
  if (!field.inputWidth) return undefined
  return { width: field.inputWidth, maxWidth: '100%' }
}

const FISCAL_TO_ALMACEN: Record<string, string> = {
  direccion: 'direccionEnvio',
  codigoPostal: 'codigoPostalEnvio',
  poblacion: 'poblacionEnvio',
  provincia: 'provinciaEnvio',
  pais: 'paisEnvio',
}

function setValue(field: ProveedorField, value: unknown) {
  const next: Record<string, unknown> = { ...props.modelValue, [field.key]: value }
  const dest = FISCAL_TO_ALMACEN[field.key]
  if (dest && !props.readonly) next[dest] = value
  emit('update:modelValue', next)
}

function onTextInput(field: ProveedorField, raw: string) {
  if (field.key === 'codigoPostal' || field.key === 'codigoPostalEnvio') {
    void onCodigoPostalInput(field, raw)
    return
  }
  setValue(field, raw)
}

function onTextBlur(field: ProveedorField, raw: string) {
  if (field.key === 'codigoPostal' || field.key === 'codigoPostalEnvio') {
    void onCodigoPostalInput(field, raw)
  }
}

const CP_FIELD_MAP: Record<string, { poblacion: string; provincia: string }> = {
  codigoPostal: { poblacion: 'poblacion', provincia: 'provincia' },
  codigoPostalEnvio: { poblacion: 'poblacionEnvio', provincia: 'provinciaEnvio' },
}

let cpLookupSeq = 0

async function onCodigoPostalInput(field: ProveedorField, raw: string) {
  const base: Record<string, unknown> = { ...props.modelValue, [field.key]: raw }
  if (field.key === 'codigoPostal' && !props.readonly) {
    base.codigoPostalEnvio = raw
  }
  emit('update:modelValue', base)

  const map = CP_FIELD_MAP[field.key]
  if (!map || props.readonly || field.readOnly) return

  const cp = raw.trim().replace(/\s+/g, '')
  // CP español: esperar 5 digitos para resolver con fiabilidad
  if (cp.length < 5) return

  const seq = ++cpLookupSeq
  try {
    const data = await lookupCodigoPostal(cp)
    if (seq !== cpLookupSeq || !data) return

    const updated: Record<string, unknown> = { ...props.modelValue, ...base }
    if (data.poblacion) {
      updated[map.poblacion] = data.poblacion
      if (field.key === 'codigoPostal') updated.poblacionEnvio = data.poblacion
    }
    if (data.provincia) {
      updated[map.provincia] = data.provincia
      if (field.key === 'codigoPostal') updated.provinciaEnvio = data.provincia
    }
    emit('update:modelValue', updated)
  } catch {
    // Silencioso si el CP no existe en maestro
  }
}

function abrirBuscarFormaPago() {
  if (props.readonly) return
  buscarFormaPagoOpen.value = true
}

function onFormaPagoSeleccionada(resultado: EntidadBuscarResultado) {
  emit('update:modelValue', { ...props.modelValue, formaPago: resultado.codigo })
  buscarFormaPagoOpen.value = false
}

function onFormaPagoSelect(value: string) {
  emit('update:modelValue', { ...props.modelValue, formaPago: value })
}

function onBancoInput(raw: string) {
  emit('update:modelValue', { ...props.modelValue, cuentaBanco: raw })
}

function setDiaPago(key: 'diaPago1' | 'diaPago2', value: number | null) {
  emit('update:modelValue', { ...props.modelValue, [key]: value })
}

function colsClass(section: ProveedorSection) {
  return `cols-${section.columns ?? 2}`
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
        <div class="section-grid" :class="colsClass(section)">
          <div
            v-for="field in section.fields"
            :key="field.key"
            class="field"
            :class="[
              `span-${field.span ?? 1}`,
              `layout-${field.layout ?? 'inline'}`,
              { invalid: isInvalid(field) },
            ]"
          >
            <template v-if="field.layout === 'checkbox'">
              <label class="check-label">
                <input
                  type="checkbox"
                  :checked="Boolean(displayValue(field))"
                  :disabled="isReadOnly(field)"
                  :data-field-key="field.key"
                  @change="setValue(field, ($event.target as HTMLInputElement).checked)"
                />
                <span>{{ field.label }}</span>
              </label>
            </template>

            <template v-else>
              <span class="field-label">
                {{ field.label.trim() || '\u00a0'
                }}<em v-if="field.required"> *</em>
              </span>

              <div
                v-if="field.layout === 'dias-pago'"
                class="dias-pago-pair"
              >
                <DecimalInput
                  :model-value="(modelValue.diaPago1 as number | null) ?? null"
                  :empty-as-null="true"
                  :integer="true"
                  :readonly="isReadOnly(field)"
                  field-key="diaPago1"
                  :style="controlStyle(field)"
                  @update:model-value="setDiaPago('diaPago1', $event)"
                />
                <DecimalInput
                  :model-value="(modelValue.diaPago2 as number | null) ?? null"
                  :empty-as-null="true"
                  :integer="true"
                  :readonly="isReadOnly(field)"
                  field-key="diaPago2"
                  :style="controlStyle(field)"
                  @update:model-value="setDiaPago('diaPago2', $event)"
                />
              </div>

              <div
                v-else-if="field.type === 'select' && field.lookup && field.optionsSource === 'formas-pago'"
                class="lookup-row"
              >
                <select
                  :value="String(modelValue[field.key] ?? '')"
                  :disabled="isReadOnly(field)"
                  :data-field-key="field.key"
                  :style="controlStyle(field)"
                  class="lookup-select"
                  @change="onFormaPagoSelect(($event.target as HTMLSelectElement).value)"
                >
                  <option value="">--</option>
                  <option v-for="opt in optionsFor(field)" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
                <button
                  v-if="!isReadOnly(field)"
                  type="button"
                  class="btn-lupa"
                  title="Buscar forma de pago"
                  @click="abrirBuscarFormaPago"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>

              <div
                v-else-if="field.key === 'cuentaBanco'"
                class="lookup-row"
              >
                <input
                  type="text"
                  :value="codigoBancoDisplay()"
                  :readonly="isReadOnly(field)"
                  :maxlength="field.maxLength ?? 10"
                  data-field-key="cuentaBanco"
                  :style="controlStyle(field)"
                  class="lookup-select input-banco"
                  @input="onBancoInput(($event.target as HTMLInputElement).value)"
                />
                <button
                  v-if="!isReadOnly(field)"
                  type="button"
                  class="btn-lupa"
                  title="Buscar"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>

              <select
                v-else-if="field.type === 'select'"
                :value="selectValue(field)"
                :disabled="isReadOnly(field)"
                :data-field-key="field.key"
                :style="controlStyle(field)"
                @change="setValue(field, ($event.target as HTMLSelectElement).value)"
              >
                <option v-if="!field.required" value="">--</option>
                <option v-for="opt in optionsFor(field)" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>

              <textarea
                v-else-if="field.layout === 'textarea' || field.type === 'textarea'"
                :value="String(displayValue(field))"
                :readonly="isReadOnly(field)"
                :data-field-key="field.key"
                rows="9"
                @input="setValue(field, ($event.target as HTMLTextAreaElement).value)"
              />

              <DecimalInput
                v-else-if="field.type === 'number' && field.key !== 'cuentaBanco'"
                :model-value="(modelValue[field.key] as number | null) ?? null"
                :empty-as-null="true"
                :readonly="isReadOnly(field)"
                :field-key="field.key"
                :style="controlStyle(field)"
                @update:model-value="setValue(field, $event)"
              />

              <input
                v-else
                :type="field.type === 'email' ? 'email' : 'text'"
                :value="displayValue(field) as string | number"
                :readonly="isReadOnly(field)"
                :maxlength="field.maxLength"
                :data-field-key="field.key"
                :style="controlStyle(field)"
                @input="onTextInput(field, ($event.target as HTMLInputElement).value)"
                @blur="onTextBlur(field, ($event.target as HTMLInputElement).value)"
              />
            </template>
          </div>
        </div>
      </fieldset>
    </div>

    <EntidadBuscarModal
      :open="buscarFormaPagoOpen"
      entidad="formas-pago"
      titulo="Buscar forma de pago"
      @seleccionar="onFormaPagoSeleccionada"
      @cerrar="buscarFormaPagoOpen = false"
    />
  </div>
</template>

<style scoped>
.tab-form {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  padding: 0.55rem 0.65rem 0.7rem;
  background: #eef2f6;
  border: 1px solid #c5cdd8;
  border-top: none;
  border-radius: 0 0 8px 8px;
}

.section-row {
  display: block;
}

.section-row.paired {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
  align-items: stretch;
}

.form-section {
  margin: 0;
  padding: 0.4rem 0.55rem 0.5rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #fff;
  box-shadow: 0 1px 0 rgba(15, 23, 42, 0.04);
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: #475569;
}

.section-grid {
  display: grid;
  gap: 0.28rem 0.55rem;
  align-items: center;
}

.cols-1 {
  grid-template-columns: minmax(0, 1fr);
}
.cols-2 {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.cols-4 {
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

.layout-inline,
.layout-dias-pago {
  display: grid;
  grid-template-columns: 6.75rem minmax(0, 1fr);
  gap: 0.3rem 0.4rem;
  align-items: center;
}

.layout-textarea {
  display: grid;
  grid-template-columns: 6.75rem minmax(0, 1fr);
  gap: 0.3rem 0.4rem;
  align-items: start;
}

.layout-checkbox {
  display: flex;
  align-items: center;
  min-height: 1.6rem;
}

.field-label {
  font-size: 0.76rem;
  color: #475569;
  text-align: right;
  line-height: 1.2;
}

.field-label em {
  color: #b91c1c;
  font-style: normal;
  font-weight: 700;
}

.check-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.76rem;
  color: #334155;
  cursor: pointer;
}

.invalid .field-label,
.invalid .check-label {
  color: #b91c1c;
  font-weight: 600;
}

.invalid input,
.invalid select,
.invalid textarea {
  border-color: #ef4444 !important;
  background: #fef2f2 !important;
}

.control-lupa {
  display: flex;
  gap: 0.2rem;
  align-items: stretch;
  min-width: 0;
}

.control-lupa select {
  flex: 1;
  min-width: 0;
}

.lookup-row {
  display: inline-flex;
  align-items: stretch;
  gap: 0.25rem;
  min-width: 0;
  width: fit-content;
  max-width: 100%;
}

.lookup-select {
  flex: 0 0 auto;
  width: auto;
  min-width: 0;
  max-width: 100%;
  box-sizing: border-box;
}

.lookup-desc {
  font-size: 0.8rem;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 14rem;
  align-self: center;
}

.dias-pago-pair {
  display: inline-flex;
  align-items: stretch;
  gap: 0.2rem;
}

.dias-pago-pair :deep(input) {
  margin: 0;
}

.input-banco {
  font-variant-numeric: tabular-nums;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.7rem;
  align-self: stretch;
  flex-shrink: 0;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
  box-sizing: border-box;
}

.btn-lupa :deep(.tool-icon) {
  width: 0.95rem;
  height: 0.95rem;
}

.btn-lupa:hover {
  background: #e0f2fe;
  border-color: #38bdf8;
}

input,
select,
textarea {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  box-sizing: border-box;
  padding: 0.22rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  font-size: 0.8rem;
  background: #fff;
  color: #0f172a;
}

textarea {
  min-height: 11rem;
  resize: vertical;
}

input[type='checkbox'] {
  width: auto;
  margin: 0;
}

input:read-only,
textarea:read-only,
select:disabled {
  background: #f1f5f9;
  color: #334155;
}

input:focus,
select:focus,
textarea:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}

@media (max-width: 860px) {
  .tab-form {
    width: 100%;
  }

  .section-row.paired {
    grid-template-columns: 1fr;
  }

  .cols-3,
  .cols-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .field.span-3,
  .field.span-4 {
    grid-column: span 2;
  }
}
</style>
