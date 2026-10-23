<script setup lang="ts">
import { ref } from 'vue'
import {
  familiaColumns,
  nombreMacrofamilia,
  type FamiliaColumn,
  type FamiliaFila,
} from '@/config/familias-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import { useOrdenCabeceraGrid } from '@/composables/useOrdenCabeceraGrid'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const props = defineProps<{
  filas: FamiliaFila[]
  indiceSeleccionado: number
  macroOpciones: { value: string; label: string }[]
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

function cellValue(fila: FamiliaFila, col: FamiliaColumn) {
  if (col.key === 'macroFamiliaNombre') {
    return fila.macroFamiliaNombre || nombreMacrofamilia(fila.macroFamiliaCodigo ?? '', props.macroOpciones)
  }
  const value = fila[col.key as keyof FamiliaFila]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  return value ?? ''
}

function onSeleccionar(fila: FamiliaFila) {
  emit('seleccionar', indiceOriginal(fila))
}

function onRowDblClick(fila: FamiliaFila) {
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
    <table v-else class="familias-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th
            v-for="col in familiaColumns"
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
          :columns="familiaColumns"
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
            <td v-for="col in familiaColumns" :key="col.key" class="celda-vacia">&nbsp;</td>
          </template>
          <template v-else>
            <td
              v-for="col in familiaColumns"
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
