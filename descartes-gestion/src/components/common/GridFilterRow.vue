<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  FILTRO_OPERADORES,
  operadorNecesitaValor,
  operadorNecesitaValor2,
  type ColumnFilter,
  type FilterOperador,
} from '@/composables/useGridColumnFilters'

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

const menuAbierto = ref<string | null>(null)
const filterInputRefs = ref<Record<string, HTMLInputElement | null>>({})
const filterKeySet = computed(() => new Set(props.filterableKeys))
const dateKeySet = computed(() => new Set(props.dateKeys ?? []))

watch(menuAbierto, (v) => emit('menu-open', !!v))

function setFilterInputRef(key: string, el: unknown) {
  filterInputRefs.value[key] = (el as HTMLInputElement | null) ?? null
}

async function enfocarInputFiltro(key: string) {
  await nextTick()
  await nextTick()
  const input = filterInputRefs.value[key]
  if (!input || input.disabled) return
  input.focus()
  if (input.type !== 'date') {
    const len = input.value.length
    input.setSelectionRange(len, len)
  }
}

function esFecha(key: string) {
  return dateKeySet.value.has(key)
}

function filtroDe(key: string): ColumnFilter {
  return props.filters?.[key] ?? { operador: 'sin_filtro', valor: '', valor2: '' }
}

function patchFiltro(key: string, patch: Partial<ColumnFilter>) {
  emit('update:filters', { ...props.filters, [key]: { ...filtroDe(key), ...patch } })
}

function onFiltroValor(key: string, valor: string) {
  const actual = filtroDe(key)
  const patch: Partial<ColumnFilter> = { valor }
  if (valor.trim() !== '' && actual.operador === 'sin_filtro') {
    patch.operador = esFecha(key) ? 'igual' : 'contiene'
  }
  patchFiltro(key, patch)
}

function elegirOperador(key: string, operador: FilterOperador) {
  const patch: Partial<ColumnFilter> = { operador }
  if (!operadorNecesitaValor(operador)) {
    patch.valor = ''
    patch.valor2 = ''
  } else if (!operadorNecesitaValor2(operador)) {
    patch.valor2 = ''
  }
  patchFiltro(key, patch)
  menuAbierto.value = null
  if (operadorNecesitaValor(operador)) void enfocarInputFiltro(key)
}

function toggleMenu(key: string, event: MouseEvent) {
  event.stopPropagation()
  menuAbierto.value = menuAbierto.value === key ? null : key
}

function onDocClick() {
  menuAbierto.value = null
}

onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))
</script>

<template>
  <tr class="filter-row">
    <th class="col-ind"></th>
    <th v-for="col in columns" :key="`f-${col.key}`">
      <div
        v-if="filterKeySet.has(col.key)"
        class="filter-cell"
        :class="{ abierta: menuAbierto === col.key }"
        @click.stop
      >
        <div class="filter-inputs">
          <input
            :type="esFecha(col.key) ? 'date' : 'text'"
            class="filter-input"
            :ref="(el) => setFilterInputRef(col.key, el)"
            :value="filtroDe(col.key).valor"
            :disabled="!operadorNecesitaValor(filtroDe(col.key).operador)"
            :placeholder="operadorNecesitaValor2(filtroDe(col.key).operador) ? 'Desde' : ''"
            @input="onFiltroValor(col.key, ($event.target as HTMLInputElement).value)"
            @keydown.enter.prevent="emit('search')"
          />
          <input
            v-if="operadorNecesitaValor2(filtroDe(col.key).operador)"
            :type="esFecha(col.key) ? 'date' : 'text'"
            class="filter-input"
            :value="filtroDe(col.key).valor2"
            placeholder="Hasta"
            @input="patchFiltro(col.key, { valor2: ($event.target as HTMLInputElement).value })"
            @keydown.enter.prevent="emit('search')"
          />
        </div>
        <button
          type="button"
          class="filter-btn"
          :class="{ active: filtroDe(col.key).operador !== 'sin_filtro' }"
          title="Operador de filtro"
          @click="toggleMenu(col.key, $event)"
        >
          <svg viewBox="0 0 16 16" width="12" height="12" aria-hidden="true">
            <path fill="currentColor" d="M1.5 2h13l-4.5 5.2V13l-4-2.2V7.2L1.5 2z" />
          </svg>
        </button>
        <div v-if="menuAbierto === col.key" class="filter-menu">
          <button
            v-for="op in FILTRO_OPERADORES"
            :key="op.value"
            type="button"
            class="filter-menu-item"
            :class="{ selected: filtroDe(col.key).operador === op.value }"
            @click="elegirOperador(col.key, op.value)"
          >
            {{ op.label }}
          </button>
        </div>
      </div>
    </th>
  </tr>
</template>

<style scoped src="../../assets/grid-mantenimiento.css"></style>

<style scoped>
.filter-row th {
  vertical-align: middle;
}
</style>
