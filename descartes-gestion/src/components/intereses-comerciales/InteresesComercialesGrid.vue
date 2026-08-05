<script setup lang="ts">
import { ref } from 'vue'
import type { InteresComercialFila } from '@/config/intereses-comerciales-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const FILTER_COLUMNS = [{ key: 'codigo' }, { key: 'descripcion' }]

const props = defineProps<{
  filas: InteresComercialFila[]
  indiceSeleccionado: number
  readonly?: boolean
  loading?: boolean
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  actualizar: [index: number, fila: InteresComercialFila]
  'update:filters': [filters: Record<string, ColumnFilter>]
}>()

const filterMenuOpen = ref(false)

function onCellChange(index: number, key: 'codigo' | 'descripcion', value: string) {
  const fila = { ...props.filas[index], [key]: value, _dirty: true }
  emit('actualizar', index, fila)
}

function indicadorFila(index: number, fila: InteresComercialFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
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
        >
          <td class="col-ind">{{ indicadorFila(index, fila) }}</td>
          <td @click.stop>
            <input
              type="text"
              class="cell-input"
              :value="fila.codigo ?? ''"
              :readonly="readonly || !fila._nuevo"
              maxlength="2"
              @input="onCellChange(index, 'codigo', ($event.target as HTMLInputElement).value)"
            />
          </td>
          <td @click.stop>
            <input
              type="text"
              class="cell-input"
              :value="fila.descripcion ?? ''"
              :readonly="readonly"
              maxlength="50"
              @input="onCellChange(index, 'descripcion', ($event.target as HTMLInputElement).value)"
            />
          </td>
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

.intereses-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

.intereses-grid th,
.intereses-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.15rem 0.25rem;
  vertical-align: middle;
}

.intereses-grid th {
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
  width: 5rem;
}

.col-descripcion {
  min-width: 18rem;
}

.intereses-grid tbody tr {
  cursor: pointer;
}

.intereses-grid tbody tr.selected {
  background: #dbeafe;
}

.intereses-grid tbody tr.nuevo {
  background: #fefce8;
}

.cell-input {
  width: 100%;
  border: none;
  background: transparent;
  padding: 0.2rem 0.35rem;
  font: inherit;
}

.cell-input:focus {
  outline: 2px solid #2563eb;
  background: #fff;
}
</style>
