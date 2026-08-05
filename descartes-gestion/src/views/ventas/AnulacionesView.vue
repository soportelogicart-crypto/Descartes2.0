<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { listarAnulaciones } from '@/api/ventas'
import type { Anulacion } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import ListPagination from '@/components/common/ListPagination.vue'

const hoy = new Date().toISOString().slice(0, 10)
const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<Anulacion[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(50)
const filtros = ref({ fechaDesde: hoy, fechaHasta: hoy, cajero: '', motivo: '' })

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarAnulaciones({
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      cajero: filtros.value.cajero || undefined,
      motivo: filtros.value.motivo || undefined,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    page.value = data.page
    pageSize.value = data.pageSize
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el diario')
  } finally {
    loading.value = false
  }
}

function buscar() {
  page.value = 1
  return cargar()
}

function onPage(p: number) {
  page.value = p
  void cargar()
}

function onPageSize(n: number) {
  pageSize.value = n
  page.value = 1
  void cargar()
}

onMounted(cargar)
</script>

<template>
  <section>
    <h2>Diario de anulaciones</h2>
    <p class="hint">Solo consulta. Las anulaciones se originan en el TPV.</p>
    <form class="filtros" @submit.prevent="buscar">
      <label>Desde <input v-model="filtros.fechaDesde" type="date" /></label>
      <label>Hasta <input v-model="filtros.fechaHasta" type="date" /></label>
      <label>Cajero <input v-model="filtros.cajero" maxlength="4" /></label>
      <label>Motivo <input v-model="filtros.motivo" /></label>
      <button type="submit" :disabled="loading">Buscar</button>
    </form>
    <p v-if="error" class="error">{{ error }}</p>
    <div class="grid-wrap">
      <table>
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Articulo</th>
            <th>Cant.</th>
            <th>Importe</th>
            <th>Motivo</th>
            <th>Cajero</th>
            <th>Puesto</th>
            <th>Mesa</th>
            <th>Sesion</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(a, i) in items" :key="i">
            <td>{{ a.fecha?.slice(0, 19)?.replace('T', ' ') }}</td>
            <td>{{ a.articulo }}</td>
            <td class="num">{{ a.cantidad }}</td>
            <td class="num">{{ a.importeLin.toFixed(2) }}</td>
            <td>{{ a.motivo ?? 'Sin motivo' }}</td>
            <td>{{ a.cajero }}</td>
            <td>{{ a.puesto }}</td>
            <td>{{ a.mesa ?? '—' }}</td>
            <td>{{ a.sesion ?? '—' }}</td>
          </tr>
          <tr v-if="!loading && !items.length"><td colspan="9">Sin anulaciones</td></tr>
        </tbody>
      </table>
    </div>
    <ListPagination
      :page="page"
      :page-size="pageSize"
      :total="total"
      :loading="loading"
      @update:page="onPage"
      @update:page-size="onPageSize"
    />
  </section>
</template>

<style scoped>
.hint { color: #64748b; font-size: 0.85rem; }
.filtros { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; margin: 0.75rem 0; }
.filtros label { display: flex; flex-direction: column; font-size: 0.75rem; gap: 0.15rem; }
.filtros input { padding: 0.3rem 0.4rem; border: 1px solid #94a3b8; border-radius: 4px; }
.filtros button { padding: 0.4rem 0.85rem; cursor: pointer; }
.grid-wrap { overflow: auto; border: 1px solid #94a3b8; border-radius: 4px; }
table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
th, td { border-bottom: 1px solid #e2e8f0; padding: 0.3rem 0.45rem; }
th { background: #f1f5f9; }
.num { text-align: right; }
.error { color: #b91c1c; }
</style>
