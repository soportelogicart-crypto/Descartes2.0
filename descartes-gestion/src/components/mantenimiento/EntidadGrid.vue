<script setup lang="ts">
import { computed } from 'vue'
import { type GridColumn, type GridFila } from '@/config/entidad-grid-columns'
import { type ColumnFilter } from '@/composables/useGridColumnFilters'
import { useOrdenCabeceraGrid } from '@/composables/useOrdenCabeceraGrid'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
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
  /** Total en servidor (si hay más que las filas cargadas). */
  totalServidor?: number
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
const { orden, clicar, filasOrdenadas, indiceOriginal, esSeleccionada, indicador } =
  useOrdenCabeceraGrid(
    () => props.filas,
    () => props.indiceSeleccionado,
    { dateKeys: () => props.dateKeys }
  )
const avisoLimite = computed(() => {
  const total = Number(props.totalServidor ?? 0)
  const datos = props.filas.filter((f) => !f._nuevo).length
  if (total > datos && datos > 0) {
    return `Mostrando ${datos} de ${total}. Escriba en el filtro para acotar.`
  }
  if (!total && datos >= GRID_LIMITE_INICIAL) {
    return `Mostrando los ${GRID_LIMITE_INICIAL} primeros. Escriba en el filtro para acotar.`
  }
  return ''
})

function onCellChange(indexMostrado: number, key: string, value: unknown) {
  const fila = filasOrdenadas.value[indexMostrado]
  if (!fila) return
  const index = indiceOriginal(fila)
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

function optionsFor(col: GridColumn) {
  if (!col.optionsSource) return []
  return props.optionsMap?.[col.optionsSource] ?? []
}

function onSeleccionar(fila: GridFila) {
  emit('seleccionar', indiceOriginal(fila))
}

function onRowDblClick(fila: GridFila) {
  const index = indiceOriginal(fila)
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
    <p v-else-if="avisoLimite" class="loading-banner">{{ avisoLimite }}</p>
    <table class="entidad-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th
            v-for="col in columns"
            :key="col.key"
            class="ordenable"
            :style="col.width ? { minWidth: col.width, width: col.width } : undefined"
            :title="`Ordenar por ${col.label}`"
            @click="clicar(col.key)"
          >
            {{ col.label }}
            <span v-if="orden?.key === col.key" class="marca-orden">{{
              orden.dir === 'asc' ? '▲' : '▼'
            }}</span>
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
          v-for="(fila, index) in filasOrdenadas"
          :key="fila._nuevo ? 'nuevo' : String(fila.codigo)"
          :class="{ selected: esSeleccionada(fila), nuevo: fila._nuevo }"
          @click="onSeleccionar(fila)"
          @dblclick="onRowDblClick(fila)"
        >
          <td class="col-ind">{{ indicador(fila) }}</td>

          <template v-if="fila._nuevo">
            <td v-for="col in columns" :key="col.key" class="celda-vacia">&nbsp;</td>
          </template>

          <template v-else>
            <td v-for="col in columns" :key="col.key" @click.stop="readonly ? onSeleccionar(fila) : undefined">
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
