<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  type EntidadLookupId,
  etiquetaDesdeDetalle,
  maxLengthLookup,
} from '@/config/entidad-lookup'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null | undefined
    entidad: EntidadLookupId
    readonly?: boolean
    maxLength?: number
    /** Ficha articulos clasificacion: codigo estrecho + nombre amplio. */
    variant?: 'default' | 'combo'
    compact?: boolean
    emptyAsNull?: boolean
    fieldKey?: string
    tituloModal?: string
  }>(),
  {
    readonly: false,
    variant: 'default',
    compact: false,
    emptyAsNull: false,
  }
)

const emit = defineEmits<{
  'update:modelValue': [value: string | number | null]
}>()

const etiqueta = ref('')
const modalOpen = ref(false)
const busquedaInicial = ref('')

const maxLen = computed(() => maxLengthLookup(props.entidad, props.maxLength))
const codigo = computed(() => {
  const v = props.modelValue
  if (v == null || v === '') return ''
  const s = String(v).trim()
  if (/^\d+\.0+$/.test(s)) return s.replace(/\.0+$/, '')
  return s
})

function parseCodigo(raw: string): string {
  const s = raw.trim()
  if (!s) return ''
  const sep = s.indexOf(' - ')
  return sep >= 0 ? s.slice(0, sep).trim() : s
}

function emitCodigo(raw: string) {
  const c = parseCodigo(raw)
  if (!c) {
    emit('update:modelValue', props.emptyAsNull ? null : '')
    return
  }
  if (props.entidad === 'almacenes' && /^\d+$/.test(c)) {
    emit('update:modelValue', Number(c))
    return
  }
  emit('update:modelValue', c)
}

async function resolverEtiqueta(codigoRaw: string) {
  const c = codigoRaw.trim()
  if (!c) {
    etiqueta.value = ''
    return
  }
  try {
    const { data } = await api.get(
      `/api/mantenimiento/${props.entidad}/${encodeURIComponent(c)}`
    )
    etiqueta.value = etiquetaDesdeDetalle(props.entidad, (data ?? {}) as Record<string, unknown>)
  } catch {
    etiqueta.value = ''
  }
}

watch(
  () => `${props.entidad}\u0000${codigo.value}`,
  () => {
    void resolverEtiqueta(codigo.value)
  },
  { immediate: true }
)

function abrirModal() {
  if (props.readonly) return
  busquedaInicial.value = codigo.value
  modalOpen.value = true
}

function onSeleccion(sel: EntidadBuscarResultado) {
  modalOpen.value = false
  emitCodigo(sel.codigo)
  etiqueta.value = sel.etiqueta
}

function onInput(raw: string) {
  emitCodigo(raw)
}

function onBlur(raw: string) {
  emitCodigo(raw)
  void resolverEtiqueta(parseCodigo(raw))
}

const tituloBuscar = computed(() => props.tituloModal ?? `Buscar ${props.entidad.replace(/-/g, ' ')}`)
</script>

<template>
  <div class="lookup-row" :class="{ compact, 'variant-combo': variant === 'combo' }">
    <div
      v-if="variant === 'combo'"
      class="lookup-combo"
      :class="{ 'lookup-combo-readonly': readonly }"
      :title="etiqueta || undefined"
    >
      <input
        type="text"
        class="lookup-codigo"
        :data-field-key="fieldKey"
        :value="codigo"
        :readonly="readonly"
        :maxlength="maxLen"
        @input="onInput(($event.target as HTMLInputElement).value)"
        @blur="onBlur(($event.target as HTMLInputElement).value)"
      />
      <span class="lookup-nombre-inner">{{ etiqueta }}</span>
    </div>
    <template v-else>
      <div
        class="lookup-combo lookup-combo-inline"
        :class="{ 'lookup-combo-readonly': readonly }"
        :title="etiqueta || undefined"
      >
        <input
          type="text"
          class="lookup-codigo"
          :data-field-key="fieldKey"
          :value="codigo"
          :readonly="readonly"
          :maxlength="maxLen"
          @input="onInput(($event.target as HTMLInputElement).value)"
          @blur="onBlur(($event.target as HTMLInputElement).value)"
        />
        <span class="lookup-nombre-inner">{{ etiqueta }}</span>
      </div>
    </template>
    <button
      type="button"
      class="btn-lupa"
      :title="tituloBuscar"
      :disabled="readonly"
      @click="abrirModal"
    >
      <ToolIcon name="buscar" />
    </button>
    <EntidadBuscarModal
      :open="modalOpen"
      :entidad="entidad"
      :titulo="tituloBuscar"
      :busqueda-inicial="busquedaInicial"
      @seleccionar="onSeleccion"
      @cerrar="modalOpen = false"
    />
  </div>
</template>

<style scoped>
.lookup-row {
  display: flex;
  align-items: stretch;
  gap: 0.25rem;
  min-width: 0;
  width: 100%;
  position: relative;
  z-index: 1;
}

.lookup-row.compact {
  gap: 0.15rem;
}

.lookup-combo {
  flex: 1;
  min-width: 0;
  display: grid;
  grid-template-columns: var(--lookup-codigo-w, 3.35rem) minmax(0, 1fr);
  align-items: center;
  min-height: 1.625rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  overflow: hidden;
  box-sizing: border-box;
}

.lookup-row.compact .lookup-combo {
  min-height: 1.45rem;
  grid-template-columns: var(--lookup-codigo-w, 2.75rem) minmax(0, 1fr);
}

.lookup-combo-readonly {
  background: #e8edf2;
}

.lookup-combo .lookup-codigo {
  width: 100% !important;
  max-width: none !important;
  min-width: 0;
  height: 100%;
  min-height: inherit;
  padding: 0.15rem 0.25rem;
  border: none !important;
  border-right: 1px solid #cbd5e1 !important;
  border-radius: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
  font-size: 0.75rem;
  line-height: 1.25;
  box-sizing: border-box;
  text-align: center;
}

.lookup-row:not(.variant-combo) .lookup-combo-inline {
  grid-template-columns: var(--lookup-codigo-w, 4rem) minmax(0, 1fr);
}

.lookup-row:not(.variant-combo) .lookup-combo .lookup-codigo {
  text-align: left;
  padding: 0.2rem 0.35rem;
  font-size: 0.8rem;
}

.lookup-nombre-inner {
  min-width: 0;
  min-height: inherit;
  display: block;
  padding: 0.2rem 0.4rem;
  font-size: 0.8125rem;
  line-height: 1.3;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  pointer-events: none;
  user-select: none;
}

.lookup-row.compact .lookup-nombre-inner {
  font-size: 0.75rem;
  padding: 0.15rem 0.3rem;
}

.lookup-combo-readonly .lookup-codigo,
.lookup-combo-readonly .lookup-nombre-inner {
  color: #475569;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.7rem;
  min-height: 1.625rem;
  flex-shrink: 0;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
  box-sizing: border-box;
}

.lookup-row.compact .btn-lupa {
  width: 1.45rem;
  min-height: 1.45rem;
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
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
