<script setup lang="ts">
import { ref } from 'vue'
import type { InteresComercialFila } from '@/config/intereses-comerciales-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const FILTER_COLUMNS = [{ key: 'codigo' }, { key: 'descripcion' }]

const props = defineProps<{
  filas: InteresComercialFila[]
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

function indicadorFila(index: number, fila: InteresComercialFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
}

function onRowDblClick(index: number, fila: InteresComercialFila) {
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
    <table v-else class="intereses-grid">
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
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  background: #fff;
}

.grid-wrap.filter-menu-open {
  overflow: visible;
}

.loading {
  margin: 0;
  padding: 0.75rem;
  color: #64748b;
}

.intereses-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.intereses-grid th,
.intereses-grid td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.28rem 0.4rem;
  text-align: left;
}

.intereses-grid th {
  background: #f1f5f9;
  font-weight: 600;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
}

.col-codigo {
  width: 4rem;
}

.intereses-grid tbody tr {
  cursor: pointer;
}

.intereses-grid tbody tr.selected {
  background: #dbeafe;
}

.intereses-grid tbody tr.nuevo {
  background: #f8fafc;
}

.celda-vacia {
  color: transparent;
}
</style>
