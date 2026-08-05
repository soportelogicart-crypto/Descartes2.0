<script setup lang="ts">
import { ref } from 'vue'
import { articuloColumns, type ArticuloColumn, type ArticuloFila } from '@/config/articulos-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const props = defineProps<{
  filas: ArticuloFila[]
  indiceSeleccionado: number
  familiaOpciones: { value: string; label: string }[]
  impuestoOpciones: { value: string; label: string }[]
  proveedorOpciones: { value: string; label: string }[]
  readonly?: boolean
  loading?: boolean
  filterableKeys?: string[]
  filters?: Record<string, ColumnFilter>
}>()

const emit = defineEmits<{
  seleccionar: [index: number]
  actualizar: [index: number, fila: ArticuloFila]
  abrir: [index: number]
  nuevo: []
  'update:filters': [filters: Record<string, ColumnFilter>]
  search: []
}>()

const filterMenuOpen = ref(false)

function onCellChange(index: number, key: string, value: unknown) {
  const fila: ArticuloFila = { ...props.filas[index], [key]: value, _dirty: true }
  if (key === 'precioVen1') fila.precioVenta = value as number
  emit('actualizar', index, fila)
}

function cellValue(fila: ArticuloFila, col: ArticuloColumn) {
  const value = fila[col.key as keyof ArticuloFila]
  if (col.type === 'number') return value == null || value === '' ? 0 : value
  if (col.type === 'checkbox') return Boolean(value)
  return value ?? ''
}

function formatCell(fila: ArticuloFila, col: ArticuloColumn) {
  const value = cellValue(fila, col)
  if (col.type === 'number') {
    const n = Number(value)
    return Number.isFinite(n) ? String(n) : ''
  }
  return String(value ?? '')
}

function labelSelect(col: ArticuloColumn, fila: ArticuloFila) {
  const raw = String(fila[col.key as keyof ArticuloFila] ?? '').trim()
  if (!raw) return ''
  const opt = optionsFor(col).find((o) => o.value === raw)
  return opt?.label ?? raw
}

function isReadOnly(col: ArticuloColumn, fila: ArticuloFila) {
  if (props.readonly || col.readOnly || fila._nuevo) return true
  if (col.key === 'codigo' && !fila._nuevo) return true
  return false
}

function indicadorFila(index: number, fila: ArticuloFila) {
  if (fila._nuevo) return '*'
  if (index === props.indiceSeleccionado) return '>'
  return ''
}

function optionsFor(col: ArticuloColumn) {
  if (col.optionsSource === 'familias') return props.familiaOpciones
  if (col.optionsSource === 'impuestos') return props.impuestoOpciones
  if (col.optionsSource === 'proveedores') return props.proveedorOpciones
  return []
}

function onRowClick(index: number) {
  emit('seleccionar', index)
}

function onRowDblClick(index: number, fila: ArticuloFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', index)
}
</script>

<template>
  <div class="grid-wrap" :class="{ 'filter-menu-open': filterMenuOpen }">
    <p v-if="loading" class="loading-banner">Cargando...</p>
    <table class="articulos-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th v-for="col in articuloColumns" :key="col.key" :style="{ minWidth: col.width }">
            {{ col.label }}
          </th>
        </tr>
        <GridFilterRow
          v-if="filterableKeys?.length"
          :columns="articuloColumns"
          :filterable-keys="filterableKeys"
          :filters="filters ?? {}"
          @update:filters="emit('update:filters', $event)"
          @menu-open="filterMenuOpen = $event"
          @search="emit('search')"
        />
      </thead>
      <tbody>
        <tr
          v-for="(fila, index) in filas"
          :key="fila._nuevo ? 'nuevo' : String(fila.codigo)"
          :class="{ selected: index === indiceSeleccionado, nuevo: fila._nuevo }"
          @click="onRowClick(index)"
          @dblclick="onRowDblClick(index, fila)"
        >
          <td class="col-ind">{{ indicadorFila(index, fila) }}</td>

          <!-- Fila * sin inputs: el alta se hace en ficha -->
          <template v-if="fila._nuevo">
            <td v-for="col in articuloColumns" :key="col.key" class="celda-vacia">&nbsp;</td>
          </template>

          <template v-else>
            <td v-for="col in articuloColumns" :key="col.key">
              <!-- Grid siempre en solo lectura: la edición se hace en ficha -->
              <template v-if="readonly || isReadOnly(col, fila)">
                <input
                  v-if="col.type === 'checkbox'"
                  type="checkbox"
                  :checked="Boolean(cellValue(fila, col))"
                  disabled
                />
                <span v-else-if="col.type === 'select'" class="celda-texto">
                  {{ labelSelect(col, fila) }}
                </span>
                <span v-else class="celda-texto">{{ formatCell(fila, col) }}</span>
              </template>

              <template v-else>
                <select
                  v-if="col.type === 'select'"
                  :value="String(fila[col.key as keyof ArticuloFila] ?? '')"
                  @click.stop
                  @change="onCellChange(index, col.key, ($event.target as HTMLSelectElement).value || '')"
                >
                  <option value="">--</option>
                  <option v-for="opt in optionsFor(col)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>

                <input
                  v-else-if="col.type === 'checkbox'"
                  type="checkbox"
                  :checked="Boolean(cellValue(fila, col))"
                  @click.stop
                  @change="onCellChange(index, col.key, ($event.target as HTMLInputElement).checked)"
                />

                <input
                  v-else-if="col.type === 'number'"
                  type="number"
                  class="cell-input"
                  :value="cellValue(fila, col) as number"
                  step="any"
                  @click.stop
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
                  :maxlength="col.maxLength"
                  @click.stop
                  @input="onCellChange(index, col.key, ($event.target as HTMLInputElement).value)"
                />
              </template>
            </td>
          </template>
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

.loading-banner {
  margin: 0;
  padding: 0.35rem 0.6rem;
  font-size: 0.8rem;
  color: #334155;
  background: #f1f5f9;
  border-bottom: 1px solid #cbd5e1;
}

.articulos-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.articulos-grid th,
.articulos-grid td {
  border: 1px solid #cbd5e1;
  padding: 0.12rem 0.2rem;
  vertical-align: middle;
}

.articulos-grid th {
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

.articulos-grid tbody tr {
  cursor: pointer;
}

.articulos-grid tbody tr.selected {
  background: #dbeafe;
}

.articulos-grid tbody tr.nuevo {
  background: #fefce8;
}

.celda-vacia {
  height: 1.6rem;
}

.celda-texto {
  display: block;
  padding: 0.15rem 0.3rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
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

input[type='checkbox'] {
  width: 0.95rem;
  height: 0.95rem;
  display: block;
  margin: 0 auto;
}

.cell-input:focus,
select:focus {
  outline: 2px solid #2563eb;
  background: #fff;
}
</style>
