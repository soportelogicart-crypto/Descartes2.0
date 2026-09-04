<script setup lang="ts">
import { ref } from 'vue'
import {
  nombreFamilia,
  subfamiliaColumns,
  type SubfamiliaColumn,
  type SubfamiliaFila,
} from '@/config/subfamilias-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
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
}>()

const filterMenuOpen = ref(false)

function cellValue(fila: SubfamiliaFila, col: SubfamiliaColumn) {
  if (col.key === 'familiaNombre') {
    return fila.familiaNombre || nombreFamilia(fila.familiaCodigo ?? '', props.familiaOpciones)
  }
  const value = fila[col.key as keyof SubfamiliaFila]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  return value ?? ''
}

function indicadorFila(index: number, fila: SubfamiliaFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
}

function onRowDblClick(index: number, fila: SubfamiliaFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', index)
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
            :style="{ minWidth: col.width }"
          >
            {{ col.label }}
          </th>
        </tr>
        <GridFilterRow
          v-if="filterableKeys?.length"
          :columns="subfamiliaColumns"
          :filterable-keys="filterableKeys"
          :filters="filters ?? {}"
          @update:filters="emit('update:filters', $event)"
          @menu-open="filterMenuOpen = $event"
        />
      </thead>
      <tbody>
        <tr
          v-for="(fila, index) in filas"
          :key="fila._nuevo ? 'nuevo' : fila.codigo"
          :class="{ selected: index === indiceSeleccionado, nuevo: fila._nuevo }"
          @click="emit('seleccionar', index)"
          @dblclick="onRowDblClick(index, fila)"
        >
          <td class="col-ind">{{ indicadorFila(index, fila) }}</td>
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
