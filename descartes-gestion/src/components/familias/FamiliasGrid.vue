<script setup lang="ts">
import { ref } from 'vue'
import {
  familiaColumns,
  nombreMacrofamilia,
  type FamiliaColumn,
  type FamiliaFila,
} from '@/config/familias-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const props = defineProps<{
  filas: FamiliaFila[]
  indiceSeleccionado: number
  macroOpciones: { value: string; label: string }[]
  readonly?: boolean
  loading?: boolean
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  actualizar: [index: number, fila: FamiliaFila]
  'update:filters': [filters: Record<string, ColumnFilter>]
}>()

const filterMenuOpen = ref(false)

function onCellChange(index: number, key: string, value: unknown) {
  const fila: FamiliaFila = { ...props.filas[index], [key]: value, _dirty: true }
  if (key === 'macroFamiliaCodigo') {
    fila.macroFamiliaNombre = nombreMacrofamilia(String(value ?? ''), props.macroOpciones)
  }
  emit('actualizar', index, fila)
}

function cellValue(fila: FamiliaFila, col: FamiliaColumn) {
  const value = fila[col.key as keyof FamiliaFila]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  if (col.key === 'macroFamiliaNombre') {
    return fila.macroFamiliaNombre || nombreMacrofamilia(fila.macroFamiliaCodigo ?? '', props.macroOpciones)
  }
  return value ?? ''
}

function isReadOnly(col: FamiliaColumn, fila: FamiliaFila) {
  if (props.readonly || col.readOnly) return true
  if (col.key === 'codigo' && !fila._nuevo) return true
  return false
}

function indicadorFila(index: number, fila: FamiliaFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
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
            :style="{ minWidth: col.width }"
          >
            {{ col.label }}
          </th>
        </tr>
        <GridFilterRow
          v-if="filterableKeys?.length"
          :columns="familiaColumns"
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
          <td v-for="col in familiaColumns" :key="col.key" @click.stop>
            <select
              v-if="col.type === 'select-macro'"
              :value="String(fila.macroFamiliaCodigo ?? '')"
              :disabled="isReadOnly(col, fila)"
              @change="onCellChange(index, 'macroFamiliaCodigo', ($event.target as HTMLSelectElement).value)"
            >
              <option value="">--</option>
              <option v-for="opt in macroOpciones" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </select>

            <input
              v-else-if="col.type === 'number'"
              type="number"
              class="cell-input"
              :value="cellValue(fila, col) as number"
              :readonly="isReadOnly(col, fila)"
              step="any"
              @input="
                onCellChange(
                  index,
                  col.key,
                  ($event.target as HTMLInputElement).value === ''
                    ? 0
                    : Number(($event.target as HTMLInputElement).value)
                )
              "
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
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.grid-wrap {
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

.familias-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.familias-grid th,
.familias-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.12rem 0.2rem;
  vertical-align: middle;
}

.familias-grid th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  font-weight: 600;
  text-align: center;
  white-space: nowrap;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
  color: #1e40af;
  font-weight: 700;
  background: #f8fafc;
}

.familias-grid tbody tr {
  cursor: pointer;
}

.familias-grid tbody tr.selected {
  background: #dbeafe;
}

.familias-grid tbody tr.nuevo {
  background: #fefce8;
}

.cell-input,
select {
  width: 100%;
  border: none;
  background: transparent;
  padding: 0.15rem 0.3rem;
  font: inherit;
  min-width: 0;
}

.cell-input:focus,
select:focus {
  outline: 2px solid #2563eb;
  background: #fff;
}
</style>
