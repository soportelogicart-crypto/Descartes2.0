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

<style scoped>
.grid-wrap {
  width: 50%;
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}

.grid-wrap.filter-menu-open {
  overflow: visible;
}

.loading {
  padding: 1rem;
  margin: 0;
}

.macro-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

.macro-grid th,
.macro-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.2rem 0.4rem;
  vertical-align: middle;
}

.macro-grid th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  font-weight: 600;
  text-align: center;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
  color: #1e40af;
  font-weight: 700;
  background: #f8fafc;
}

.col-codigo {
  width: 6rem;
}

.col-descripcion {
  min-width: 18rem;
}

.macro-grid tbody tr {
  cursor: pointer;
}

.macro-grid tbody tr.selected {
  background: #dbeafe;
}

.macro-grid tbody tr.nuevo {
  background: #fefce8;
}

.macro-grid tbody tr:nth-child(even):not(.selected):not(.nuevo) {
  background: #f8fafc;
}

.celda-vacia {
  height: 1.7rem;
}
</style>
