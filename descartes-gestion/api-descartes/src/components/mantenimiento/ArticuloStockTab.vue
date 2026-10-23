<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'

export interface StockAlmacen {
  almacenCodigo: number
  almacenDescripcion: string
  cantidad: number
}

const props = defineProps<{
  articuloCodigo: string
  stock?: StockAlmacen[]
}>()

const items = ref<StockAlmacen[]>(props.stock ?? [])
const loading = ref(false)

async function cargar() {
  if (!props.articuloCodigo) return
  loading.value = true
  try {
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(props.articuloCodigo)}/stock`)
    items.value = data.items ?? []
  } finally {
    loading.value = false
  }
}

watch(
  () => props.stock,
  (value) => {
    if (value) items.value = value
  },
  { deep: true }
)

onMounted(() => {
  if (props.articuloCodigo) cargar()
})
</script>

<template>
  <section class="stock-tab">
    <header>
      <h3>Stock por almacen</h3>
      <button type="button" :disabled="loading" @click="cargar">Actualizar</button>
    </header>
    <p class="hint">Solo lectura. Los movimientos de stock se gestionan en inventario.</p>
    <table v-if="items.length">
      <thead>
        <tr>
          <th>Almacen</th>
          <th>Descripcion</th>
          <th>Cantidad</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row in items" :key="row.almacenCodigo">
          <td>{{ row.almacenCodigo }}</td>
          <td>{{ row.almacenDescripcion }}</td>
          <td>{{ row.cantidad }}</td>
        </tr>
      </tbody>
    </table>
    <p v-else-if="!loading" class="empty">Sin stock registrado en almacenes.</p>
    <p v-if="loading" class="empty">Cargando...</p>
  </section>
</template>

<style scoped>
.stock-tab {
  margin-top: 1rem;
  background: #fff;
  border-radius: 8px;
  padding: 1rem;
}

header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.5rem;
}

h3 {
  margin: 0;
  font-size: 1rem;
}

.hint {
  color: #6b7280;
  font-size: 0.875rem;
  margin: 0 0 0.75rem;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  text-align: left;
  padding: 0.5rem;
  border-bottom: 1px solid #e5e7eb;
}

.empty {
  color: #6b7280;
}
</style>
