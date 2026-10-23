<script setup lang="ts">
import { ref } from 'vue'
import {
  nombreFamilia,
  subfamiliaColumns,
  type SubfamiliaColumn,
  type SubfamiliaFila,
} from '@/config/subfamilias-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import { useOrdenCabeceraGrid } from '@/composables/useOrdenCabeceraGrid'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const props = defineProps<{
  filas: SubfamiliaFila[]
  indiceSeleccionado: number
  familiaOpciones: { value: string; label: string }[]
  loading?: boolean
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  abrir: [index: number]
  nuevo: []
  'update:filters': [filters: Record<string, ColumnFilter>]
  search: []
}>()

const filterMenuOpen = ref(false)
const { orden, clicar, filasOrdenadas, indiceOriginal, esSeleccionada, indicador } =
  useOrdenCabeceraGrid(
    () => props.filas,
    () => props.indiceSeleccionado
  )

function cellValue(fila: SubfamiliaFila, col: SubfamiliaColumn) {
  if (col.key === 'familiaNombre') {
    return fila.familiaNombre || nombreFamilia(fila.familiaCodigo ?? '', props.familiaOpciones)
  }
  const value = fila[col.key as keyof SubfamiliaFila]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  return value ?? ''
}

function onSeleccionar(fila: SubfamiliaFila) {
  emit('seleccionar', indiceOriginal(fila))
}

function onRowDblClick(fila: SubfamiliaFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', indiceOriginal(fila))
}
</script>

<template>
  <div class="grid-wrap" :class="{ 'filter-menu-open': filterMenuOpen }">
    <p v-if="loading" class="loading">Cargando...</p>
    <table v-else class="subfamilias-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th
            v-for="col in subfamiliaColumns"
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
          v-if="filterableKeys?.length"
          :columns="subfamiliaColumns"
          :filterable-keys="filterableKeys"
          :filters="filters ?? {}"
          @update:filters="emit('update:filters', $event)"
          @search="emit('search')"
          @menu-open="filterMenuOpen = $event"
        />
      </thead>
      <tbody>
        <tr
          v-for="fila in filasOrdenadas"
          :key="fila._nuevo ? 'nuevo' : fila.codigo"
          :class="{ selected: esSeleccionada(fila), nuevo: fila._nuevo }"
          @click="onSeleccionar(fila)"
          @dblclick="onRowDblClick(fila)"
        >
          <td class="col-ind">{{ indicador(fila) }}</td>
          <template v-if="fila._nuevo">
            <td v-for="col in subfamiliaColumns" :key="col.key" class="celda-vacia">&nbsp;</td>
          </template>
          <template v-else>
            <td
              v-for="col in subfamiliaColumns"
              :key="col.key"
              :class="{ 'col-num': col.type === 'number' }"
            >
              {{ cellValue(fila, col) }}
            </td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped src="../../assets/grid-mantenimiento.css"></style>
