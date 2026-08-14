<script setup lang="ts">
/**
 * Alta rápida de artículo (legacy «Generación Artículos») desde compras u otros módulos.
 * Misma ficha y obligatorios que Mantenimiento → Artículos.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { api } from '@/api/client'
import { crearArticulo } from '@/api/articulos'
import { extractApiError } from '@/composables/extractApiError'
import {
  ARTICULO_CAMPOS_OBLIGATORIOS,
  articuloTabs,
  articuloVacio,
  camposArticuloObligatoriosVacios,
} from '@/config/articulos-tabs'
import ArticuloTabForm from '@/components/articulos/ArticuloTabForm.vue'
import ArticuloSidePanels from '@/components/articulos/ArticuloSidePanels.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'

const props = defineProps<{
  open: boolean
  /** Texto tecleado en la línea (legacy: Alternativo). */
  queryInicial?: string
  proveedorHabitual?: string
  empresa?: string
}>()

const emit = defineEmits<{
  cerrar: []
  creado: [articulo: Record<string, unknown>]
}>()

const MODAL_TAB_IDS = ['general', 'tarifas', 'parametros'] as const

const modalTabs = computed(() => articuloTabs.filter((t) => MODAL_TAB_IDS.includes(t.id as (typeof MODAL_TAB_IDS)[number])))

const saving = ref(false)
const error = ref<string | null>(null)
const ficha = ref<Record<string, unknown>>({})
const tabActiva = ref('general')
const codigoAutomatico = ref(false)
const camposInvalidos = ref<string[]>([])
const avisoOpen = ref(false)
const avisoMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const codigoInput = ref<HTMLInputElement | null>(null)
const descripcionInput = ref<HTMLInputElement | null>(null)

const tabSeleccionada = computed(() => modalTabs.value.find((t) => t.id === tabActiva.value) ?? modalTabs.value[0])

function esCampoInvalido(key: string) {
  return camposInvalidos.value.includes(key)
}

function limpiarCampoInvalido(key: string) {
  camposInvalidos.value = camposInvalidos.value.filter((k) => k !== key)
}

function onFichaUpdate(next: Record<string, unknown>) {
  ficha.value = next
  if (camposInvalidos.value.length === 0) return
  camposInvalidos.value = camposInvalidos.value.filter((k) => !String(next[k] ?? '').trim())
}

function mostrarAvisoCampo(key: string, message: string) {
  campoAvisoActual.value = key
  camposInvalidos.value = [key]
  avisoMensaje.value = message
  avisoOpen.value = true
}

async function cerrarAviso() {
  const key = campoAvisoActual.value
  avisoOpen.value = false
  avisoMensaje.value = ''
  campoAvisoActual.value = null
  if (!key) return
  tabActiva.value = key === 'codigo' || key === 'descripcion' ? 'general' : 'general'
  await nextTick()
  await nextTick()
  if (key === 'codigo') {
    codigoInput.value?.focus()
    return
  }
  if (key === 'descripcion') {
    descripcionInput.value?.focus()
    return
  }
  document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)?.focus()
}

async function initFicha() {
  error.value = null
  saving.value = false
  camposInvalidos.value = []
  tabActiva.value = 'general'
  codigoAutomatico.value = false

  const vacio = articuloVacio()
  const q = String(props.queryInicial ?? '').trim()
  if (q) vacio.alternativo = q.toUpperCase()
  const prov = String(props.proveedorHabitual ?? '').trim()
  if (prov) vacio.proveedorHabitual = prov

  try {
    const { data } = await api.get('/api/mantenimiento/articulos/siguiente-codigo', {
      params: { empresa: props.empresa || undefined },
    })
    if (data.automatico && data.codigo) {
      vacio.codigo = String(data.codigo)
      codigoAutomatico.value = true
    }
  } catch {
    /* código manual */
  }

  ficha.value = vacio
  await nextTick()
  descripcionInput.value?.focus()
}

watch(
  () => props.open,
  (abierto) => {
    if (abierto) void initFicha()
  }
)

async function onGuardar() {
  const vacios = camposArticuloObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = ARTICULO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    mostrarAvisoCampo(key, `El campo "${label}" es obligatorio.`)
    return
  }

  saving.value = true
  error.value = null
  try {
    const payload = { ...ficha.value }
    if (payload.precioVen1 != null) payload.precioVenta = payload.precioVen1
    const creado = await crearArticulo(payload)
    emit('creado', creado)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el artículo')
  } finally {
    saving.value = false
  }
}

function onCancelar() {
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="onCancelar">
    <div class="modal" role="dialog" aria-labelledby="articulo-alta-title">
      <header class="modal-head">
        <h3 id="articulo-alta-title">Generación artículos</h3>
        <button type="button" class="btn-close" title="Cerrar" @click="onCancelar">×</button>
      </header>

      <div class="modal-toolbar">
        <button type="button" class="tool primary" :disabled="saving" @click="onGuardar">
          {{ saving ? 'Guardando…' : 'Guardar' }}
        </button>
        <button type="button" class="tool" :disabled="saving" @click="onCancelar">Cancelar</button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>

      <div class="ficha-header">
        <label :class="{ 'campo-invalido': esCampoInvalido('codigo') }">
          Código *
          <input
            ref="codigoInput"
            v-model="ficha.codigo"
            data-field-key="codigo"
            :readonly="codigoAutomatico"
            maxlength="18"
            class="codigo-input"
            @input="limpiarCampoInvalido('codigo')"
          />
        </label>
        <label class="nombre-input" :class="{ 'campo-invalido': esCampoInvalido('descripcion') }">
          Descripción *
          <input
            ref="descripcionInput"
            v-model="ficha.descripcion"
            data-field-key="descripcion"
            maxlength="50"
            @input="limpiarCampoInvalido('descripcion')"
          />
        </label>
      </div>

      <div class="tabs">
        <button
          v-for="tab in modalTabs"
          :key="tab.id"
          type="button"
          class="tab"
          :class="{ active: tabActiva === tab.id }"
          @click="tabActiva = tab.id"
        >
          {{ tab.label }}
        </button>
      </div>

      <div class="ficha-body">
        <ArticuloTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :codigo-read-only="codigoAutomatico"
          :invalid-keys="camposInvalidos"
          @update:model-value="onFichaUpdate"
        />
        <ArticuloSidePanels :ficha="ficha" />
      </div>
    </div>

    <ConfirmDialog
      :open="avisoOpen"
      title="Campo obligatorio"
      :message="avisoMensaje"
      confirm-label="Aceptar"
      :danger="false"
      hide-cancel
      @confirm="cerrarAviso"
      @cancel="cerrarAviso"
    />
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 1200;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 1rem;
  overflow: auto;
  background: rgb(15 23 42 / 45%);
}

.modal {
  width: min(1180px, 96vw);
  max-height: calc(100vh - 2rem);
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border: 1px solid #94a3b8;
  border-radius: 10px;
  box-shadow: 0 12px 40px rgb(15 23 42 / 25%);
}

.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.55rem 0.75rem;
  background: linear-gradient(180deg, #e8eef5 0%, #d7e0ea 100%);
  border-bottom: 1px solid #94a3b8;
  border-radius: 10px 10px 0 0;
}

.modal-head h3 {
  margin: 0;
  font-size: 1rem;
}

.btn-close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  line-height: 1;
  cursor: pointer;
  color: #475569;
}

.modal-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  padding: 0.45rem 0.65rem;
  background: #fff;
  border-bottom: 1px solid #cbd5e1;
}

.tool {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}

.tool.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.error {
  margin: 0.35rem 0.65rem 0;
  color: #b91c1c;
  font-size: 0.85rem;
}

.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
  padding: 0.45rem 0.65rem;
  background: #fff;
  border-bottom: 1px solid #c5cdd8;
}

.ficha-header label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}

.codigo-input {
  width: 9rem;
}

.nombre-input {
  flex: 1;
  min-width: 220px;
}

.ficha-header input {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
}

.ficha-header label.campo-invalido {
  color: #b91c1c;
  font-weight: 600;
}

.ficha-header label.campo-invalido input {
  border-color: #dc2626;
  background: #fef2f2;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.25rem 0.35rem 0;
  background: #fff;
  border-bottom: 1px solid #c5cdd8;
}

.tab {
  border: 1px solid #94a3b8;
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  background: #e8edf2;
  padding: 0.3rem 0.6rem;
  font-size: 0.78rem;
  cursor: pointer;
}

.tab.active {
  background: #f8fafc;
  font-weight: 600;
}

.ficha-body {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(260px, 0.85fr);
  align-items: stretch;
  overflow: auto;
  flex: 1;
  min-height: 0;
}

@media (max-width: 960px) {
  .ficha-body {
    grid-template-columns: 1fr;
  }
}
</style>
