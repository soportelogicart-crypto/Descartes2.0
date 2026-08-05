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

<style scoped>
.col-ind {
  width: 1.5rem;
}

.filter-row th {
  background: #e8eef5;
  padding: 0.15rem 0.2rem;
  font-weight: 400;
  overflow: visible;
  vertical-align: middle;
}

.filter-cell {
  position: relative;
  display: flex;
  align-items: center;
  gap: 0.15rem;
  overflow: visible;
}

.filter-cell.abierta {
  z-index: 20;
}

.filter-inputs {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.filter-input {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  background: #fff;
  padding: 0.12rem 0.25rem;
  font: inherit;
  font-size: 0.78rem;
  min-height: 1.45rem;
}

.filter-input:disabled {
  background: #e2e8f0;
  color: #94a3b8;
}

.filter-btn {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.45rem;
  height: 1.45rem;
  padding: 0;
  border: 1px solid #ca8a04;
  border-radius: 2px;
  background: linear-gradient(180deg, #fde047 0%, #eab308 100%);
  color: #713f12;
  cursor: pointer;
}

.filter-btn.active {
  box-shadow: inset 0 0 0 1px #a16207;
}

.filter-menu {
  position: absolute;
  top: calc(100% + 2px);
  left: 0;
  z-index: 30;
  min-width: 11rem;
  max-height: 16rem;
  overflow: auto;
  background: #fff;
  border: 1px solid #64748b;
  box-shadow: 2px 2px 6px rgba(15, 23, 42, 0.18);
}

.filter-menu-item {
  display: block;
  width: 100%;
  text-align: left;
  border: 0;
  background: transparent;
  padding: 0.28rem 0.55rem;
  font-size: 0.78rem;
  cursor: pointer;
  color: #0f172a;
}

.filter-menu-item:hover {
  background: #e0f2fe;
}

.filter-menu-item.selected {
  background: #fde047;
  font-weight: 600;
}
</style>
