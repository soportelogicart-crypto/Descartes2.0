<script setup lang="ts">
import { ref } from 'vue'
import { articuloColumns, type ArticuloColumn, type ArticuloFila } from '@/config/articulos-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import GridFilterRow from '@/components/common/GridFilterRow.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

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

                <DecimalInput
                  v-else-if="col.type === 'number'"
                  class="cell-input"
                  :model-value="(cellValue(fila, col) as number | null) ?? null"
                  :empty-as-null="false"
                  @click.stop
                  @update:model-value="onCellChange(index, col.key, $event ?? 0)"
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

<style scoped src="../../assets/grid-mantenimiento.css"></style>

<style scoped>
.celda-texto {
  display: block;
  padding: 0.05rem 0.15rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
