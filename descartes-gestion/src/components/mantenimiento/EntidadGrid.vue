<script setup lang="ts">
import { computed } from 'vue'
import { type GridColumn, type GridFila } from '@/config/entidad-grid-columns'
import { type ColumnFilter } from '@/composables/useGridColumnFilters'
import DecimalInput from '@/components/common/DecimalInput.vue'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

export type GridOptionsMap = Record<string, { value: string; label: string }[]>

const props = defineProps<{
  columns: GridColumn[]
  filas: GridFila[]
  indiceSeleccionado: number
  optionsMap?: GridOptionsMap
  readonly?: boolean
  loading?: boolean
  /** Claves de columna con fila de filtro (escribir para filtrar). */
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

const muestraFiltros = computed(() => (props.filterableKeys?.length ?? 0) > 0)

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

</script>

<template>
  <div class="grid-wrap">
    <p v-if="loading" class="loading-banner">Cargando...</p>
    <table class="entidad-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th v-for="col in columns" :key="col.key" :style="col.width ? { minWidth: col.width, width: col.width } : undefined">
            {{ col.label }}
          </th>
        </tr>
        <GridFilterRow
          v-if="muestraFiltros"
          :columns="columns"
          :filterable-keys="filterableKeys ?? []"
          :filters="filters ?? {}"
          :date-keys="dateKeys"
          @update:filters="emit('update:filters', $event)"
          @search="emit('search')"
        />
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
