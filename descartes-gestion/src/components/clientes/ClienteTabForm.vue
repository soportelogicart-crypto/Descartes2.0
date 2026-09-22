<script setup lang="ts">
import { computed } from 'vue'
import { api } from '@/api/client'
import {
  fechaParaInput,
  riesgoPendienteCliente,
  type ClienteField,
  type ClienteSection,
} from '@/config/clientes-tabs'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { entidadDesdeOptionsSource } from '@/config/entidad-lookup'

const props = defineProps<{
  sections: ClienteSection[]
  modelValue: Record<string, unknown>
  readonly?: boolean
  codigoReadOnly?: boolean
  ocultarCabecera?: boolean
  camposInvalidos?: string[]
  motorFidelizacion?: 'NINGUNO' | 'EUROS' | 'PUNTOS'
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  'blur-field': [key: string, value: string]
}>()

const seccionesVisibles = computed(() => {
  if (!props.ocultarCabecera) return props.sections
  return props.sections
    .map((section) => ({
      ...section,
          fields: section.fields.filter(
            (f) => f.key !== 'codigo' && f.key !== 'nombre' && f.key !== 'nif' && f.key !== 'formaPago'
          ),
    }))
    .filter((section) => section.fields.length > 0)
})

/** Agrupa secciones consecutivas con el mismo `row` para mostrarlas en paralelo. */
const filasSecciones = computed(() => {
  const rows: { key: string; sections: ClienteSection[] }[] = []
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

function optionsFor(field: ClienteField) {
  return field.options ?? []
}

function isReadOnly(field: ClienteField) {
  if (props.readonly || field.readOnly) return true
  if (field.key === 'codigo' && props.codigoReadOnly) return true
  return false
}

function fieldVisible(field: ClienteField) {
  const motor = props.motorFidelizacion ?? 'NINGUNO'
  if (field.key === 'pjeFidelizacion' || field.key === 'acumuladoFidelizacion') {
    return motor === 'EUROS'
  }
  if (field.key === 'acumuladoPuntos') {
    return motor === 'PUNTOS'
  }
  return true
}

function etiquetaMotorFidelizacion() {
  const motor = props.motorFidelizacion ?? 'NINGUNO'
  if (motor === 'EUROS') return 'Método activo: saldo en euros'
  if (motor === 'PUNTOS') return 'Método activo: puntos'
  return 'La tienda no tiene fidelización activa'
}

function displayValue(field: ClienteField) {
  if (field.key === 'riesgoPendiente') {
    const pendiente = riesgoPendienteCliente(props.modelValue)
    return pendiente === null ? '' : pendiente
  }
  const value = props.modelValue[field.key]
  if (field.type === 'date') return fechaParaInput(value)
  if (field.type === 'checkbox') return Boolean(value)
  if (field.type === 'number') return value == null || value === '' ? '' : value
  return value ?? ''
}

function numberModel(field: ClienteField): number | null {
  if (field.key === 'riesgoPendiente') return riesgoPendienteCliente(props.modelValue)
  const value = props.modelValue[field.key]
  if (value == null || value === '') return null
  return Number(value)
}

/** Direccion fiscal → envio (mismo criterio que legacy PreAlta/copia). */
const FISCAL_A_ENVIO: Record<string, string> = {
  direccion: 'direccionEnvio',
  codigoPostal: 'codigoPostalEnvio',
  poblacion: 'poblacionEnvio',
  provincia: 'provinciaEnvio',
  pais: 'paisEnvio',
}

function setValue(field: ClienteField, value: unknown) {
  const next: Record<string, unknown> = { ...props.modelValue, [field.key]: value }
  const envioKey = FISCAL_A_ENVIO[field.key]
  if (envioKey) {
    next[envioKey] = value
  }
  emit('update:modelValue', next)
}

const CP_FIELD_MAP: Record<string, { poblacion: string; provincia: string }> = {
  codigoPostal: { poblacion: 'poblacion', provincia: 'provincia' },
  codigoPostalEnvio: { poblacion: 'poblacionEnvio', provincia: 'provinciaEnvio' },
}

let cpLookupSeq = 0

function onFieldBlur(field: ClienteField, raw: string) {
  if (field.key === 'codigoPostal' || field.key === 'codigoPostalEnvio') {
    void onCodigoPostalInput(field, raw)
    return
  }
  if (field.key === 'nif') {
    emit('blur-field', 'nif', raw)
  }
}

async function onCodigoPostalInput(field: ClienteField, raw: string) {
  setValue(field, raw)
  const map = CP_FIELD_MAP[field.key]
  if (!map || props.readonly || field.readOnly) return
  const cp = raw.trim()
  if (cp.length < 4) return
  const seq = ++cpLookupSeq
  try {
    const data = await lookupCodigoPostal(cp)
    if (seq !== cpLookupSeq || !data) return
    const next: Record<string, unknown> = { ...props.modelValue, [field.key]: raw }
    if (data.poblacion) next[map.poblacion] = data.poblacion
    if (data.provincia) next[map.provincia] = data.provincia
    // Si es CP fiscal, reflejar tambien en envio.
    if (field.key === 'codigoPostal') {
      next.codigoPostalEnvio = raw
      if (data.poblacion) next.poblacionEnvio = data.poblacion
      if (data.provincia) next.provinciaEnvio = data.provincia
    }
    emit('update:modelValue', next)
  } catch {
    // Silencioso
  }
}

function colsClass(section: ClienteSection) {
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
      <section v-for="section in fila.sections" :key="section.title" class="section">
        <h3>{{ section.title }}</h3>
        <p v-if="section.title === 'Fidelizacion'" class="fidelizacion-motor">
          {{ etiquetaMotorFidelizacion() }}
        </p>
        <div class="fields" :class="colsClass(section)">
          <template v-for="field in section.fields" :key="field.key">
            <label
              v-if="fieldVisible(field)"
              class="field"
              :class="[
                `span-${field.span ?? 1}`,
                field.layout ?? 'inline',
                camposInvalidos?.includes(field.key) ? 'campo-invalido' : '',
              ]"
            >
            <template v-if="field.layout === 'checkbox'">
              <input
                type="checkbox"
                :checked="Boolean(displayValue(field))"
                :disabled="isReadOnly(field)"
                @change="setValue(field, ($event.target as HTMLInputElement).checked)"
              />
              <span>{{ field.label }}</span>
            </template>

            <template v-else>
              <span class="label">
                {{ field.label }}
                <span v-if="field.required" class="req">*</span>
              </span>

              <EntidadLookupField
                v-if="entidadDesdeOptionsSource(field.optionsSource)"
                :model-value="(modelValue[field.key] as string | number | null) ?? null"
                :entidad="entidadDesdeOptionsSource(field.optionsSource)!"
                :readonly="isReadOnly(field)"
                :max-length="field.maxLength"
                :field-key="field.key"
                empty-as-null
                @update:model-value="setValue(field, $event)"
              />

              <select
                v-else-if="field.type === 'select'"
                :data-field-key="field.key"
                :value="String(modelValue[field.key] ?? '')"
                :disabled="isReadOnly(field)"
                @change="setValue(field, ($event.target as HTMLSelectElement).value)"
              >
                <option value="">--</option>
                <option v-for="opt in optionsFor(field)" :key="String(opt.value)" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>

              <textarea
                v-else-if="field.layout === 'textarea' || field.type === 'textarea'"
                :data-field-key="field.key"
                :value="String(displayValue(field))"
                :readonly="isReadOnly(field)"
                rows="4"
                @input="setValue(field, ($event.target as HTMLTextAreaElement).value)"
              />

              <input
                v-else-if="field.type === 'checkbox'"
                type="checkbox"
                :data-field-key="field.key"
                :checked="Boolean(displayValue(field))"
                :disabled="isReadOnly(field)"
                @change="setValue(field, ($event.target as HTMLInputElement).checked)"
              />

              <DecimalInput
                v-else-if="field.type === 'number'"
                :field-key="field.key"
                :model-value="numberModel(field)"
                :empty-as-null="true"
                :readonly="isReadOnly(field)"
                @update:model-value="setValue(field, $event)"
              />

              <input
                v-else
                :data-field-key="field.key"
                :type="field.type === 'date' ? 'date' : field.type === 'email' ? 'email' : 'text'"
                :value="displayValue(field) as string | number"
                :readonly="isReadOnly(field)"
                :maxlength="field.maxLength"
                @input="
                  field.key === 'codigoPostal' || field.key === 'codigoPostalEnvio'
                    ? onCodigoPostalInput(field, ($event.target as HTMLInputElement).value)
                    : setValue(field, ($event.target as HTMLInputElement).value)
                "
                @blur="onFieldBlur(field, ($event.target as HTMLInputElement).value)"
              />
            </template>
            </label>
          </template>
        </div>
      </section>
    </div>
  </div>

</template>

<style scoped>
.tab-form {
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  border-top: none;
  padding: 0.65rem;
  max-width: 1100px;
}

.section-row {
  margin-bottom: 0.65rem;
}

.fidelizacion-motor {
  margin: -0.2rem 0 0.5rem;
  color: #475569;
  font-size: 0.78rem;
}

.section-row.paired {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
  align-items: stretch;
}

.section-row.paired .section {
  margin-bottom: 0;
  height: 100%;
}

.section {
  margin-bottom: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.section-row:not(.paired) .section {
  margin-bottom: 0;
}

.section h3 {
  margin: 0 0 0.4rem;
  font-size: 0.78rem;
  font-weight: 700;
  color: #334155;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 0.25rem;
}

.fields {
  display: grid;
  gap: 0.35rem 0.55rem;
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
.cols-5 {
  grid-template-columns: repeat(5, minmax(0, 1fr));
}

.field {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
  min-width: 0;
}

.campo-invalido .label {
  color: #b91c1c;
  font-weight: 600;
}

.campo-invalido input,
.campo-invalido select,
.campo-invalido textarea {
  border-color: #ef4444;
  background: #fef2f2;
}

.field.checkbox {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding-top: 1.1rem;
}

.span-2 {
  grid-column: span 2;
}
.span-3 {
  grid-column: span 3;
}
.span-4 {
  grid-column: span 4;
}

.label {
  color: #475569;
}

.req {
  color: #b91c1c;
}

input,
select,
textarea {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  background: #fff;
}

input[type='checkbox'] {
  width: auto;
}

input:read-only,
textarea:read-only,
select:disabled {
  background: #f1f5f9;
  color: #334155;
}

.lookup-row {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
  /* Si el contenido desborda la celda, la etiqueta del campo vecino no debe
     quedar por encima de la lupa: interceptaria los clics. */
  position: relative;
  z-index: 1;
}

.lookup-row input.lookup-codigo {
  width: 7.5rem;
  flex: 0 1 auto;
}

.lookup-nombre {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: #334155;
  font-size: 0.75rem;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.7rem;
  height: 1.55rem;
  flex-shrink: 0;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
}

.btn-lupa :deep(.tool-icon) {
  width: 0.95rem;
  height: 0.95rem;
}

.btn-lupa:hover:not(:disabled) {
  background: #e0f2fe;
  border-color: #38bdf8;
}

.btn-lupa:disabled {
  background: #f1f5f9;
  color: #94a3b8;
  cursor: default;
}

@media (max-width: 900px) {
  .section-row.paired {
    grid-template-columns: 1fr;
  }

  .cols-3,
  .cols-4,
  .cols-5 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .span-3,
  .span-4 {
    grid-column: span 2;
  }
}
</style>
