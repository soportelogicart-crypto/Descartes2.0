<script setup lang="ts">
import { ref } from 'vue'
import type { InteresComercialFila } from '@/config/intereses-comerciales-columns'
import type { ColumnFilter } from '@/composables/useGridColumnFilters'
import { useOrdenCabeceraGrid } from '@/composables/useOrdenCabeceraGrid'
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
  search: []
}>()

const filterMenuOpen = ref(false)
const { orden, clicar, filasOrdenadas, indiceOriginal, esSeleccionada, indicador } =
  useOrdenCabeceraGrid(
    () => props.filas,
    () => props.indiceSeleccionado
  )

function onSeleccionar(fila: InteresComercialFila) {
  emit('seleccionar', indiceOriginal(fila))
}

function onRowDblClick(fila: InteresComercialFila) {
  if (fila._nuevo) {
    emit('nuevo')
    return
  }
  emit('abrir', indiceOriginal(fila))
}
</script>

<template>
  <div class="grid-wrap" :class="{ 'filter-menu-open': filterMenuOpen }">
    <p v-if="loading" class="loading">Cargando...</p>
    <table v-else class="intereses-grid">
      <thead>
        <tr>
          <th class="col-ind"></th>
          <th class="col-codigo ordenable" title="Ordenar por codigo" @click="clicar('codigo')">
            Codigo
            <span v-if="orden?.key === 'codigo'" class="marca-orden">{{
              orden.dir === 'asc' ? '▲' : '▼'
            }}</span>
          </th>
          <th class="col-descripcion ordenable" title="Ordenar por descripcion" @click="clicar('descripcion')">
            Descripcion
            <span v-if="orden?.key === 'descripcion'" class="marca-orden">{{
              orden.dir === 'asc' ? '▲' : '▼'
            }}</span>
          </th>
        </tr>
        <GridFilterRow
          v-if="filterableKeys?.length"
          :columns="FILTER_COLUMNS"
          :filterable-keys="filterableKeys"
          :filters="filters ?? {}"
          @update:filters="emit('update:filters', $event)"
          @search="emit('search')"
          @menu-open="filterMenuOpen = $event"
        />
      </thead>
      <tbody>
        <tr
          v-for="fila in filasOrdenadas"
          :key="fila._nuevo ? 'nuevo' : fila.codigo"
          :class="{ selected: esSeleccionada(fila), nuevo: fila._nuevo }"
          @click="onSeleccionar(fila)"
          @dblclick="onRowDblClick(fila)"
        >
          <td class="col-ind">{{ indicador(fila) }}</td>
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
  width: 4rem;
}
</style>
