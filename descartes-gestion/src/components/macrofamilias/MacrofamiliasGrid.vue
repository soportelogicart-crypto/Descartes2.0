<script setup lang="ts">
import { ref } from 'vue'
import type { MacrofamiliaFila } from '@/config/macrofamilias-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const FILTER_COLUMNS = [{ key: 'codigo' }, { key: 'descripcion' }]

const props = defineProps<{
  filas: MacrofamiliaFila[]
  indiceSeleccionado: number
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

function indicadorFila(index: number, fila: MacrofamiliaFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
}

function onRowDblClick(index: number, fila: MacrofamiliaFila) {
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
    <table v-else class="macro-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th class="col-codigo">Codigo</th>
          <th class="col-descripcion">Descripcion</th>
        </tr>
        <GridFilterRow
          v-if="filterableKeys?.length"
          :columns="FILTER_COLUMNS"
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
            <td class="celda-vacia">&nbsp;</td>
            <td class="celda-vacia">&nbsp;</td>
          </template>
          <template v-else>
            <td>{{ fila.codigo }}</td>
            <td>{{ fila.descripcion }}</td>
          </template>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped src="../../assets/grid-mantenimiento.css"></style>

<style scoped>
.col-codigo {
  width: 6rem;
}

.col-descripcion {
  min-width: 18rem;
}
</style>
