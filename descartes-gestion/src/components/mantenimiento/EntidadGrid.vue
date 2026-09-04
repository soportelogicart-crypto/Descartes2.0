<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { type GridColumn, type GridFila } from '@/config/entidad-grid-columns'
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
    :class="{ 'filter-menu-open': !!menuAbierto }"
  >
    <p v-if="loading" class="loading-banner">Cargando...</p>
    <table class="entidad-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th v-for="col in columns" :key="col.key" :style="col.width ? { minWidth: col.width, width: col.width } : undefined">
            {{ col.label }}
          </th>
        </tr>
        <tr v-if="muestraFiltros" class="filter-row">
          <th class="col-ind"></th>
          <th
            v-for="col in columns"
            :key="`f-${col.key}`"
            :style="col.width ? { minWidth: col.width, width: col.width } : undefined"
          >
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

<style scoped src="../../assets/grid-mantenimiento.css"></style>

<style scoped>
.grid-wrap .entidad-grid td {
  overflow: hidden;
  text-overflow: ellipsis;
}

.grid-wrap input[type='checkbox']:disabled {
  opacity: 0.85;
  cursor: default;
}

.grid-wrap td select:disabled {
  cursor: default;
}
</style>
