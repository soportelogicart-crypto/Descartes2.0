<script setup lang="ts">
import { computed } from 'vue'
import { almacenColumns, type AlmacenColumn, type AlmacenFila } from '@/config/almacenes-columns'
import { type ColumnFilter } from '@/composables/useGridColumnFilters'
import { useOrdenCabeceraGrid } from '@/composables/useOrdenCabeceraGrid'
import DecimalInput from '@/components/common/DecimalInput.vue'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const props = defineProps<{
  filas: AlmacenFila[]
  indiceSeleccionado: number
  readonly?: boolean
  loading?: boolean
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  actualizar: [index: number, fila: AlmacenFila]
  abrir: [index: number]
  nuevo: []
  'update:filters': [filters: Record<string, ColumnFilter>]
  search: []
}>()

function onRowDblClick(fila: AlmacenFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', indiceOriginal(fila))
}

const muestraFiltros = computed(() => (props.filterableKeys?.length ?? 0) > 0)
const { orden, clicar, filasOrdenadas, indiceOriginal, esSeleccionada, indicador } =
  useOrdenCabeceraGrid(
    () => props.filas,
    () => props.indiceSeleccionado
  )

function onSeleccionar(fila: AlmacenFila) {
  emit('seleccionar', indiceOriginal(fila))
}

function onCellChange(indexMostrado: number, key: string, value: unknown) {
  const mostrada = filasOrdenadas.value[indexMostrado]
  if (!mostrada) return
  const index = indiceOriginal(mostrada)
  const fila = { ...props.filas[index], [key]: value, _dirty: true }
  emit('actualizar', index, fila)
}

function cellValue(fila: AlmacenFila, col: AlmacenColumn) {
  const value = fila[col.key]
  if (col.type === 'checkbox') return Boolean(value)
  if (col.type === 'number') return value == null || value === '' ? '' : value
  return value ?? ''
}

</script>

<template>
  <div class="grid-wrap">
    <p v-if="loading" class="loading">Cargando...</p>
    <table v-else class="almacenes-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th
            v-for="col in almacenColumns"
            :key="col.key"
            class="ordenable"
            :style="{ minWidth: col.width }"
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
          :columns="almacenColumns"
          :filterable-keys="filterableKeys ?? []"
          :filters="filters ?? {}"
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
          <td v-for="col in almacenColumns" :key="col.key" @click.stop>
            <input
              v-if="col.type === 'checkbox'"
              type="checkbox"
              :checked="Boolean(cellValue(fila, col))"
              :disabled="readonly || (col.key === 'codigo' && !fila._nuevo)"
              @change="onCellChange(index, col.key, ($event.target as HTMLInputElement).checked)"
            />
            <DecimalInput
              v-else-if="col.type === 'number'"
              class="cell-input"
              :model-value="(cellValue(fila, col) as number | null) ?? null"
              :empty-as-null="true"
              :integer="true"
              :readonly="readonly || !fila._nuevo"
              @update:model-value="onCellChange(index, col.key, $event)"
            />
            <input
              v-else
              type="text"
              class="cell-input"
              :value="String(cellValue(fila, col))"
              :readonly="readonly"
              :maxlength="col.key === 'centroCoste' ? 10 : 40"
              @input="onCellChange(index, col.key, ($event.target as HTMLInputElement).value)"
            />
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped src="../../assets/grid-mantenimiento.css"></style>
