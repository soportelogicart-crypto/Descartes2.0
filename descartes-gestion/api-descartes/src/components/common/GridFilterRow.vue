<script setup lang="ts">
import { computed } from 'vue'
import { type ColumnFilter } from '@/composables/useGridColumnFilters'

const props = defineProps<{
  columns: { key: string }[]
  filterableKeys: string[]
  filters: Record<string, ColumnFilter>
  /** Claves que usan input type=date y comparacion solo por fecha. */
  dateKeys?: string[]
}>()

const emit = defineEmits<{
  'update:filters': [filters: Record<string, ColumnFilter>]
  'menu-open': [open: boolean]
  search: []
}>()

const filterKeySet = computed(() => new Set(props.filterableKeys))
const dateKeySet = computed(() => new Set(props.dateKeys ?? []))

function esFecha(key: string) {
  return dateKeySet.value.has(key)
}

function filtroDe(key: string): ColumnFilter {
  return props.filters?.[key] ?? { operador: 'sin_filtro', valor: '', valor2: '' }
}

function onFiltroValor(key: string, valor: string) {
  const vacio = valor.trim() === ''
  emit('update:filters', {
    ...props.filters,
    [key]: {
      operador: vacio ? 'sin_filtro' : esFecha(key) ? 'igual' : 'contiene',
      valor,
      valor2: '',
    },
  })
}
</script>

<template>
  <tr class="filter-row">
    <th class="col-ind"></th>
    <th v-for="col in columns" :key="`f-${col.key}`">
      <input
        v-if="filterKeySet.has(col.key)"
        :type="esFecha(col.key) ? 'date' : 'search'"
        class="filter-input"
        :value="filtroDe(col.key).valor"
        :aria-label="`Filtrar por ${col.key}`"
        @input="onFiltroValor(col.key, ($event.target as HTMLInputElement).value)"
        @keydown.enter.prevent="emit('search')"
      />
    </th>
  </tr>
</template>

<style scoped src="../../assets/grid-mantenimiento.css"></style>

<style scoped>
.filter-row th {
  vertical-align: middle;
}
</style>
