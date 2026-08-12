<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { esGridEstrecho, type GridColumn, type GridFila } from '@/config/entidad-grid-columns'
import {
  FILTRO_OPERADORES,
  operadorNecesitaValor,
  operadorNecesitaValor2,
  type ColumnFilter,
  type FilterOperador,
} from '@/composables/useGridColumnFilters'
import DecimalInput from '@/components/common/DecimalInput.vue'

export type GridOptionsMap = Record<string, { value: string; label: string }[]>

const props = defineProps<{
  columns: GridColumn[]
  filas: GridFila[]
  indiceSeleccionado: number
  optionsMap?: GridOptionsMap
  readonly?: boolean
  loading?: boolean
  /** Claves de columna con fila de filtro (input + embudo). */
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
  /** Claves filtradas como fecha (input date + comparacion YYYY-MM-DD). */
  dateKeys?: string[]
  /** Si se indica, solo esas columnas son editables (el resto quedan bloqueadas). */
  editableKeys?: string[]
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  actualizar: [index: number, fila: GridFila]
  abrir: [index: number]
  nuevo: []
  'update:filters': [filters: Record<string, ColumnFilter>]
  search: []
}>()

const menuAbierto = ref<string | null>(null)
const filterInputRefs = ref<Record<string, HTMLInputElement | null>>({})

const filterKeySet = computed(() => new Set(props.filterableKeys ?? []))
const dateKeySet = computed(() => new Set(props.dateKeys ?? []))
const muestraFiltros = computed(() => (props.filterableKeys?.length ?? 0) > 0)
const esEstrecho = computed(() => esGridEstrecho(props.columns))

function setFilterInputRef(key: string, el: unknown) {
  filterInputRefs.value[key] = (el as HTMLInputElement | null) ?? null
}

async function enfocarInputFiltro(key: string) {
  await nextTick()
  await nextTick()
  const input = filterInputRefs.value[key]
  if (!input || input.disabled) return
  input.focus()
  if (input.type !== 'date') {
    const len = input.value.length
    input.setSelectionRange(len, len)
  }
}

function esFecha(key: string) {
  return dateKeySet.value.has(key)
}

function onCellChange(index: number, key: string, value: unknown) {
  const actual = props.filas[index]
  if (!actual) return
  emit('seleccionar', index)
  emit('actualizar', index, { ...actual, [key]: value, _dirty: true })
}

function cellValue(fila: GridFila, col: GridColumn) {
  const value = fila[col.key]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  if (col.type === 'checkbox') return Boolean(value)
  return value ?? ''
}

function isReadOnly(col: GridColumn, fila: GridFila) {
  if (props.readonly || col.readOnly || fila._nuevo) return true
  if (col.key === 'codigo') return true
  if (props.editableKeys && props.editableKeys.length > 0 && !props.editableKeys.includes(col.key)) {
    return true
  }
  return false
}

function indicadorFila(index: number, fila: GridFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
}

function optionsFor(col: GridColumn) {
  if (!col.optionsSource) return []
  return props.optionsMap?.[col.optionsSource] ?? []
}

function onRowDblClick(index: number, fila: GridFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', index)
}

function filtroDe(key: string): ColumnFilter {
  return props.filters?.[key] ?? { operador: 'sin_filtro', valor: '', valor2: '' }
}

function patchFiltro(key: string, patch: Partial<ColumnFilter>) {
  const actual = { ...filtroDe(key), ...patch }
  emit('update:filters', { ...(props.filters ?? {}), [key]: actual })
}

function onFiltroValor(key: string, valor: string) {
  const actual = filtroDe(key)
  const patch: Partial<ColumnFilter> = { valor }
  if (valor.trim() !== '' && actual.operador === 'sin_filtro') {
    patch.operador = esFecha(key) ? 'igual' : 'contiene'
  }
  patchFiltro(key, patch)
}

function elegirOperador(key: string, operador: FilterOperador) {
  const patch: Partial<ColumnFilter> = { operador }
  if (!operadorNecesitaValor(operador)) {
    patch.valor = ''
    patch.valor2 = ''
  } else if (!operadorNecesitaValor2(operador)) {
    patch.valor2 = ''
  }
  patchFiltro(key, patch)
  menuAbierto.value = null
  if (operadorNecesitaValor(operador)) {
    void enfocarInputFiltro(key)
  }
}

function toggleMenu(key: string, event: MouseEvent) {
  event.stopPropagation()
  menuAbierto.value = menuAbierto.value === key ? null : key
}

function onDocClick() {
  menuAbierto.value = null
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocClick)
})
</script>

<template>
  <div
    class="grid-wrap"
    :class="{ 'filter-menu-open': !!menuAbierto, 'grid-wrap--half': esEstrecho }"
  >
    <p v-if="loading" class="loading-banner">Cargando...</p>
    <table class="entidad-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th v-for="col in columns" :key="col.key" :style="{ minWidth: col.width }">
            {{ col.label }}
          </th>
        </tr>
        <tr v-if="muestraFiltros" class="filter-row">
          <th class="col-ind"></th>
          <th v-for="col in columns" :key="`f-${col.key}`">
            <div
              v-if="filterKeySet.has(col.key)"
              class="filter-cell"
              :class="{ abierta: menuAbierto === col.key }"
              @click.stop
            >
              <div class="filter-inputs">
                <input
                  :type="esFecha(col.key) ? 'date' : 'text'"
                  class="filter-input"
                  :ref="(el) => setFilterInputRef(col.key, el)"
                  :value="filtroDe(col.key).valor"
                  :disabled="!operadorNecesitaValor(filtroDe(col.key).operador)"
                  :placeholder="operadorNecesitaValor2(filtroDe(col.key).operador) ? 'Desde' : ''"
                  @input="onFiltroValor(col.key, ($event.target as HTMLInputElement).value)"
                  @keydown.enter.prevent="emit('search')"
                />
                <input
                  v-if="operadorNecesitaValor2(filtroDe(col.key).operador)"
                  :type="esFecha(col.key) ? 'date' : 'text'"
                  class="filter-input"
                  :value="filtroDe(col.key).valor2"
                  placeholder="Hasta"
                  @input="patchFiltro(col.key, { valor2: ($event.target as HTMLInputElement).value })"
                  @keydown.enter.prevent="emit('search')"
                />
              </div>
              <button
                type="button"
                class="filter-btn"
                :class="{ active: filtroDe(col.key).operador !== 'sin_filtro' }"
                title="Operador de filtro"
                @click="toggleMenu(col.key, $event)"
              >
                <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
                  <path
                    fill="currentColor"
                    d="M1.5 2h13l-4.5 5.2V13l-4-2.2V7.2L1.5 2z"
                  />
                </svg>
              </button>
              <div v-if="menuAbierto === col.key" class="filter-menu">
                <button
                  v-for="op in FILTRO_OPERADORES"
                  :key="op.value"
                  type="button"
                  class="filter-menu-item"
                  :class="{ selected: filtroDe(col.key).operador === op.value }"
                  @click="elegirOperador(col.key, op.value)"
                >
                  {{ op.label }}
                </button>
              </div>
            </div>
          </th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(fila, index) in filas"
          :key="fila._nuevo ? 'nuevo' : String(fila.codigo)"
          :class="{ selected: index === indiceSeleccionado, nuevo: fila._nuevo }"
          @click="emit('seleccionar', index)"
          @dblclick="onRowDblClick(index, fila)"
        >
          <td class="col-ind">{{ indicadorFila(index, fila) }}</td>

          <template v-if="fila._nuevo">
            <td v-for="col in columns" :key="col.key" class="celda-vacia">&nbsp;</td>
          </template>

          <template v-else>
            <td v-for="col in columns" :key="col.key" @click.stop="readonly ? emit('seleccionar', index) : undefined">
              <select
                v-if="col.type === 'select'"
                :value="String(fila[col.key] ?? '')"
                :disabled="isReadOnly(col, fila)"
                @change="onCellChange(index, col.key, ($event.target as HTMLSelectElement).value || '')"
              >
                <option value="">--</option>
                <option v-for="opt in optionsFor(col)" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>

              <input
                v-else-if="col.type === 'checkbox'"
                type="checkbox"
                :checked="Boolean(cellValue(fila, col))"
                :disabled="isReadOnly(col, fila)"
                @change="onCellChange(index, col.key, ($event.target as HTMLInputElement).checked)"
              />

              <DecimalInput
                v-else-if="col.type === 'number'"
                class="cell-input"
                :model-value="(cellValue(fila, col) as number | null) ?? null"
                :empty-as-null="false"
                :readonly="isReadOnly(col, fila)"
                @update:model-value="onCellChange(index, col.key, $event ?? 0)"
              />

              <input
                v-else
                type="text"
                class="cell-input"
                :value="String(cellValue(fila, col))"
                :readonly="isReadOnly(col, fila)"
                :maxlength="col.maxLength"
                @input="onCellChange(index, col.key, ($event.target as HTMLInputElement).value)"
              />
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}

.grid-wrap--half {
  width: 50%;
}

/* Al abrir el embudo, el menu puede sobresalir del grid */
.grid-wrap.filter-menu-open {
  overflow: visible;
}

.loading-banner {
  margin: 0;
  padding: 0.35rem 0.6rem;
  font-size: 0.8rem;
  color: #334155;
  background: #f1f5f9;
  border-bottom: 1px solid #cbd5e1;
}

.entidad-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.entidad-grid th,
.entidad-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.12rem 0.2rem;
  vertical-align: middle;
}

.entidad-grid th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  font-weight: 600;
  text-align: center;
  white-space: nowrap;
}

.filter-row th {
  background: #e8eef5;
  padding: 0.15rem 0.2rem;
  font-weight: 400;
  overflow: visible;
}

.filter-cell {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.15rem;
  overflow: visible;
}

.filter-cell.abierta {
  z-index: 20;
}

.filter-inputs {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.filter-input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  background: #fff;
  padding: 0.12rem 0.25rem;
  font: inherit;
  min-height: 1.45rem;
}

.filter-input:disabled {
  background: #e2e8f0;
  color: #94a3b8;
}

.filter-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.45rem;
  height: 1.45rem;
  padding: 0;
  border: 1px solid #ca8a04;
  border-radius: 2px;
  background: linear-gradient(180deg, #fde047 0%, #eab308 100%);
  color: #713f12;
  cursor: pointer;
}

.filter-btn.active {
  box-shadow: inset 0 0 0 1px #a16207;
}

.filter-btn:hover {
  filter: brightness(1.05);
}

.filter-menu {
  position: absolute;
  top: calc(100% + 2px);
  left: 0;
  z-index: 30;
  min-width: 11rem;
  max-height: 16rem;
  overflow: auto;
  background: #fff;
  border: 1px solid #64748b;
  box-shadow: 2px 2px 6px rgba(15, 23, 42, 0.18);
}

.filter-menu-item {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0.28rem 0.55rem;
  font-size: 0.78rem;
  cursor: pointer;
  color: #0f172a;
}

.filter-menu-item:hover {
  background: #e0f2fe;
}

.filter-menu-item.selected {
  background: #fde047;
  font-weight: 600;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
  color: #1e40af;
  font-weight: 700;
  background: #f8fafc;
}

.entidad-grid tbody tr {
  cursor: pointer;
}

.entidad-grid tbody tr.selected {
  background: #dbeafe;
}

.entidad-grid tbody tr.nuevo {
  background: #fefce8;
}

.entidad-grid tbody tr:nth-child(even):not(.selected):not(.nuevo) {
  background: #f8fafc;
}

.celda-vacia {
  height: 1.6rem;
}

.cell-input,
select {
  width: 100%;
  border: none;
  background: transparent;
  padding: 0.15rem 0.3rem;
  font: inherit;
  min-width: 0;
}

input[type='checkbox'] {
  width: 0.95rem;
  height: 0.95rem;
  display: block;
  margin: 0 auto;
}

input[type='checkbox']:disabled {
  opacity: 0.85;
  cursor: default;
}

.cell-input:focus,
select:focus {
  outline: 2px solid #2563eb;
  background: #fff;
}

.cell-input:read-only,
select:disabled {
  cursor: default;
}
</style>
