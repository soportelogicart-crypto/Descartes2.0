<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { buscarLookup, type LookupEntidad, type LookupItem } from '@/api/lookup'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  open: boolean
  entidad: LookupEntidad | null
  titulo: string
  labelHeader?: string
}>()

const emit = defineEmits<{
  seleccionar: [resultado: LookupItem]
  cerrar: []
}>()

const q = ref('')
const loading = ref(false)
const items = ref<LookupItem[]>([])
const indice = ref(0)
const error = ref<string | null>(null)
const headerLabel = ref('Descripcion')
const inputRef = ref<HTMLInputElement | null>(null)

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto || !props.entidad) return
    q.value = ''
    indice.value = 0
    error.value = null
    items.value = []
    await buscar()
    await nextTick()
    inputRef.value?.focus()
  },
)

async function buscar() {
  if (!props.entidad) return
  loading.value = true
  error.value = null
  try {
    const data = await buscarLookup(props.entidad, q.value)
    items.value = data.items ?? []
    headerLabel.value = props.labelHeader || data.labelHeader || 'Descripcion'
    indice.value = Math.min(indice.value, Math.max(0, items.value.length - 1))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el listado')
    items.value = []
  } finally {
    loading.value = false
  }
}

function seleccionarIndice(i: number) {
  indice.value = i
}

function aceptar(i?: number) {
  const fila = items.value[i ?? indice.value]
  if (!fila) return
  emit('seleccionar', fila)
  emit('cerrar')
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    emit('cerrar')
    return
  }
  if (e.key === 'ArrowDown') {
    e.preventDefault()
    if (!items.value.length) return
    indice.value = Math.min(indice.value + 1, items.value.length - 1)
    return
  }
  if (e.key === 'ArrowUp') {
    e.preventDefault()
    if (!items.value.length) return
    indice.value = Math.max(indice.value - 1, 0)
    return
  }
  if (e.key === 'Enter' && !loading.value) {
    e.preventDefault()
    if (document.activeElement === inputRef.value && q.value !== '') {
      void buscar()
      return
    }
    aceptar()
  }
}
</script>

<template>
  <div
    v-if="open"
    class="overlay"
    role="dialog"
    aria-modal="true"
    @click.self="emit('cerrar')"
    @keydown="onKeydown"
  >
    <div class="modal">
      <header class="modal-header">
        <h3>{{ titulo }}</h3>
        <button type="button" class="btn ghost" @click="emit('cerrar')">Cerrar</button>
      </header>

      <div class="buscar-bar">
        <input
          ref="inputRef"
          v-model="q"
          type="search"
          placeholder="Codigo o texto..."
          autocomplete="off"
          @keyup.enter.prevent="buscar"
        />
        <button type="button" class="btn-lupa" title="Buscar" @click="buscar">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true">
            <circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2" />
            <path d="M15.5 15.5L21 21" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
          </svg>
        </button>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="loading" class="hint">Cargando...</p>

      <div v-else class="grid-wrap">
        <table class="lookup-grid">
          <thead>
            <tr>
              <th class="col-ind"></th>
              <th>Codigo</th>
              <th>{{ headerLabel }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="(item, i) in items"
              :key="`${item.codigo}-${i}`"
              :class="{ selected: i === indice }"
              @click="seleccionarIndice(i)"
              @dblclick="aceptar(i)"
            >
              <td class="col-ind">{{ i === indice ? '>' : '' }}</td>
              <td class="col-codigo">{{ item.codigo }}</td>
              <td>{{ item.etiqueta }}</td>
            </tr>
            <tr v-if="items.length === 0">
              <td colspan="3">Sin resultados</td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="modal-footer">
        <button type="button" class="btn primary" :disabled="!items.length" @click="aceptar()">
          Seleccionar
        </button>
        <button type="button" class="btn" @click="emit('cerrar')">Cancelar</button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 36, 48, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 1rem;
}

.modal {
  width: min(640px, 100%);
  max-height: min(80vh, 640px);
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  background: var(--surface-raised);
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  padding: 0.85rem;
  box-shadow: var(--shadow);
}

.modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.modal-header h3 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.05rem;
}

.buscar-bar {
  display: flex;
  gap: 0.35rem;
}

.buscar-bar input {
  flex: 1;
  padding: 0.4rem 0.55rem;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  font-size: 0.85rem;
  background: var(--surface-raised);
}

.buscar-bar input:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(15, 110, 102, 0.16);
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.2rem;
  border: 1px solid var(--line-strong);
  border-radius: var(--radius);
  background: #f3f0ea;
  color: var(--ink-soft);
  cursor: pointer;
}

.btn-lupa:hover {
  border-color: var(--accent);
  color: var(--accent);
}

.grid-wrap {
  overflow: auto;
  flex: 1;
  min-height: 12rem;
  border: 1px solid var(--line);
}

.lookup-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.lookup-grid th,
.lookup-grid td {
  border-bottom: 1px solid #ebe6dc;
  padding: 0.28rem 0.4rem;
  text-align: left;
}

.lookup-grid th {
  background: #e8e3d8;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
  color: var(--accent);
  font-weight: 700;
}

.col-codigo {
  width: 7rem;
  font-family: var(--font-mono);
  white-space: nowrap;
}

.lookup-grid tbody tr {
  cursor: pointer;
}

.lookup-grid tbody tr:hover {
  background: var(--accent-soft);
}

.lookup-grid tbody tr.selected {
  background: var(--accent-soft);
}

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
}

.hint,
.error {
  margin: 0;
  font-size: 0.85rem;
}

.error {
  color: var(--danger);
}

.hint {
  color: var(--muted);
}
</style>
