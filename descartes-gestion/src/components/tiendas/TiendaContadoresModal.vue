<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { contadoresFieldsDer, contadoresFieldsIzq } from '@/config/tiendas-tabs'

const props = defineProps<{
  open: boolean
  modelValue: Record<string, unknown>
  readonly?: boolean
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: Record<string, unknown>]
  guardar: [value: Record<string, unknown>]
  cerrar: []
}>()

const draft = ref<Record<string, unknown>>({})

watch(
  () => [props.open, props.modelValue] as const,
  ([open]) => {
    if (open) draft.value = { ...props.modelValue }
  },
  { immediate: true, deep: true }
)

const titulo = computed(() => {
  const codigo = draft.value.codigo ?? ''
  const nombre = draft.value.nombre ?? ''
  return `Contadores — Tienda ${codigo} ${nombre}`.trim()
})

function num(key: string) {
  const v = draft.value[key]
  if (v == null || v === '') return 0
  return Number(v)
}

function setNum(key: string, raw: string) {
  const n = raw === '' ? 0 : Number(raw)
  draft.value = { ...draft.value, [key]: Number.isFinite(n) ? n : 0 }
}

function onGuardar() {
  const next = { ...draft.value }
  emit('update:modelValue', next)
  emit('guardar', next)
}

function onCerrar() {
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="onCerrar">
    <div class="modal">
      <header class="modal-header">
        <h3>{{ titulo }}</h3>
        <button type="button" class="close" @click="onCerrar">×</button>
      </header>

      <p class="hint">
        Contadores de numeracion (tabla Empresas). Se usan al empezar el año o corregir series.
        {{ readonly ? 'Sin permiso de edicion.' : 'Edite y pulse Guardar.' }}
      </p>

      <div class="box">
        <label v-for="f in contadoresFieldsIzq" :key="f.key" class="row">
          <span class="lbl">{{ f.label }}</span>
          <input
            type="number"
            step="1"
            :value="num(f.key)"
            :readonly="readonly"
            @input="setNum(f.key, ($event.target as HTMLInputElement).value)"
          />
        </label>

        <div class="sep" aria-hidden="true" />

        <label v-for="f in contadoresFieldsDer" :key="f.key" class="row">
          <span class="lbl">{{ f.label }}</span>
          <input
            type="number"
            step="1"
            :value="num(f.key)"
            :readonly="readonly"
            @input="setNum(f.key, ($event.target as HTMLInputElement).value)"
          />
        </label>
      </div>

      <footer class="modal-footer">
        <button v-if="!readonly" type="button" class="primary" :disabled="saving" @click="onGuardar">
          Guardar
        </button>
        <button type="button" @click="onCerrar">Cerrar</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 50;
  padding: 1rem;
}

.modal {
  width: min(420px, 100%);
  max-height: 92vh;
  overflow: auto;
  background: #e8edf2;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.65rem 0.85rem;
  background: #f8fafc;
  border-bottom: 1px solid #cbd5e1;
}

.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
}

.close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
}

.hint {
  margin: 0;
  padding: 0.45rem 0.85rem 0.25rem;
  font-size: 0.78rem;
  color: #64748b;
}

.box {
  display: flex;
  flex-direction: column;
  gap: 0.22rem;
  margin: 0.5rem 0.85rem 0.75rem;
  padding: 0.65rem 0.85rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.sep {
  height: 0.65rem;
}

.row {
  display: grid;
  grid-template-columns: 12.5rem 8.25rem;
  gap: 0.5rem;
  align-items: center;
  font-size: 0.78rem;
  color: #334155;
  margin: 0;
}

.row .lbl {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.row input {
  width: 8.25rem;
  box-sizing: border-box;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  text-align: right;
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  appearance: textfield;
  -moz-appearance: textfield;
}

.row input::-webkit-outer-spin-button,
.row input::-webkit-inner-spin-button {
  -webkit-appearance: none;
  margin: 0;
}

.row input:read-only {
  background: #eef2f6;
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem;
  border-top: 1px solid #cbd5e1;
  background: #f8fafc;
}

.modal-footer button {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
}

.modal-footer button.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.modal-footer button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

@media (max-width: 420px) {
  .row {
    grid-template-columns: 1fr 7.5rem;
  }

  .row input {
    width: 7.5rem;
  }
}
</style>
