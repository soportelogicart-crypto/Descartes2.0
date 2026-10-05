<script setup lang="ts">
import ToolIcon from '@/components/common/ToolIcon.vue'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'

export type DatosFacturaTicket = {
  cliente: string
  razonSocial: string
  nif: string
  direccion: string
  codigoPostal: string
  poblacion: string
  provincia: string
}

const props = defineProps<{
  modelValue: DatosFacturaTicket
  /** Caja táctil: campos más altos. */
  tactil?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [DatosFacturaTicket]
  'buscar-cliente': []
}>()

function patch(campo: keyof DatosFacturaTicket, valor: string) {
  emit('update:modelValue', { ...props.modelValue, [campo]: valor })
}

let cpSeq = 0

async function onCodigoPostal(raw: string) {
  patch('codigoPostal', raw)
  const cp = raw.trim()
  if (cp.replace(/\D/g, '').length < 4) return
  const seq = ++cpSeq
  try {
    const data = await lookupCodigoPostal(cp)
    if (seq !== cpSeq || !data || (!data.poblacion && !data.provincia)) return
    emit('update:modelValue', {
      ...props.modelValue,
      codigoPostal: raw,
      ...(data.poblacion ? { poblacion: data.poblacion } : {}),
      ...(data.provincia ? { provincia: data.provincia } : {}),
    })
  } catch {
    // CP desconocido: población y provincia se escriben a mano.
  }
}

function nifInvalido(): boolean {
  const n = props.modelValue.nif.toUpperCase().replace(/[\s.\-]/g, '')
  return n.length < 7 || /^[0X]+$/.test(n)
}
</script>

<template>
  <section class="datos-factura" :class="{ tactil }">
    <h3>Datos de facturación</h3>
    <div class="fields">
      <label class="field span-2">
        <span class="label">Cliente</span>
        <span class="lookup-row">
          <input
            class="lookup-codigo"
            :value="modelValue.cliente"
            type="text"
            maxlength="18"
            autocomplete="off"
            :disabled="disabled"
            @input="patch('cliente', ($event.target as HTMLInputElement).value)"
            @keydown.f4.prevent="emit('buscar-cliente')"
          />
          <button
            type="button"
            class="btn-lupa"
            title="Buscar cliente (F4)"
            :disabled="disabled"
            @click="emit('buscar-cliente')"
          >
            <ToolIcon name="buscar" />
          </button>
        </span>
      </label>
      <label class="field span-4" :class="{ 'campo-invalido': !modelValue.razonSocial.trim() }">
        <span class="label">Razón social *</span>
        <input
          :value="modelValue.razonSocial"
          type="text"
          maxlength="100"
          :disabled="disabled"
          @input="patch('razonSocial', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="field span-2" :class="{ 'campo-invalido': nifInvalido() }">
        <span class="label">NIF *</span>
        <input
          :value="modelValue.nif"
          type="text"
          maxlength="32"
          autocomplete="off"
          :disabled="disabled"
          @input="patch('nif', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="field span-4">
        <span class="label">Dirección</span>
        <input
          :value="modelValue.direccion"
          type="text"
          maxlength="100"
          :disabled="disabled"
          @input="patch('direccion', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="field">
        <span class="label">C.P.</span>
        <input
          :value="modelValue.codigoPostal"
          type="text"
          inputmode="numeric"
          maxlength="16"
          autocomplete="off"
          :disabled="disabled"
          @input="onCodigoPostal(($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="field span-3">
        <span class="label">Población</span>
        <input
          :value="modelValue.poblacion"
          type="text"
          maxlength="100"
          :disabled="disabled"
          @input="patch('poblacion', ($event.target as HTMLInputElement).value)"
        />
      </label>
      <label class="field span-2">
        <span class="label">Provincia</span>
        <input
          :value="modelValue.provincia"
          type="text"
          maxlength="100"
          :disabled="disabled"
          @input="patch('provincia', ($event.target as HTMLInputElement).value)"
        />
      </label>
    </div>
  </section>
</template>

<style scoped>
.datos-factura {
  padding: 0.45rem 0.55rem 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  text-align: left;
}

h3 {
  margin: 0 0 0.4rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid #e2e8f0;
  color: #334155;
  font-size: 0.78rem;
  font-weight: 700;
}

.fields {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 0.35rem 0.55rem;
}

.field {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
  font-size: 0.78rem;
}

.label {
  color: #334155;
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

input {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.35rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
}

input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.18);
}

input:disabled {
  background: #f1f5f9;
  color: #334155;
}

.campo-invalido .label {
  color: #b91c1c;
  font-weight: 600;
}

.campo-invalido input {
  border-color: #f87171;
}

.lookup-row {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  min-width: 0;
}

.lookup-row input {
  flex: 1 1 auto;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  width: 1.7rem;
  height: 1.55rem;
  padding: 0;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 3px;
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

/* En caja táctil los campos tienen que poder pulsarse con el dedo. */
.tactil .field {
  font-size: 0.85rem;
}

.tactil input {
  min-height: 2.3rem;
  padding: 0.3rem 0.5rem;
  font-size: 0.95rem;
}

.tactil .btn-lupa {
  width: 2.5rem;
  height: 2.3rem;
}

.tactil .btn-lupa :deep(.tool-icon) {
  width: 1.2rem;
  height: 1.2rem;
}
</style>
