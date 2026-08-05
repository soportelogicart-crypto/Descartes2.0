<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  ficha: Record<string, unknown>
}>()

type StockRow = {
  almacenCodigo: number | string
  almacenDescripcion: string
  cantidad: number
}

const stockRows = computed(() => (Array.isArray(props.ficha.stock) ? (props.ficha.stock as StockRow[]) : []))

const tarifasRows = computed(() => {
  const rows = []
  for (let i = 1; i <= 9; i++) {
    const precio = props.ficha[`precioVen${i}`]
    if (precio == null || precio === '' || Number(precio) === 0) continue
    rows.push({
      tarifa: i,
      tipo: 'V',
      nombre: `Tarifa ${i}`,
      oferta: '',
      precio: Number(precio),
      dto: 0,
      neto: Number(precio),
      sinIva: Number(precio),
    })
  }
  return rows
})
</script>

<template>
  <aside class="side-panels">
    <section class="panel">
      <header>Stock por almacen</header>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Almacen</th>
              <th>Stock</th>
              <th>Pendiente</th>
              <th>Minimo</th>
              <th>Optimo</th>
              <th>Estadistica</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in stockRows" :key="String(row.almacenCodigo)">
              <td>{{ row.almacenCodigo }}</td>
              <td>{{ row.almacenDescripcion }}</td>
              <td class="num">{{ row.cantidad }}</td>
              <td class="num">0</td>
              <td class="num">0</td>
              <td class="num">0</td>
              <td class="num">0</td>
            </tr>
            <tr v-if="stockRows.length === 0">
              <td colspan="7" class="empty">Sin stock</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <header>Tarifas</header>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Tarifa</th>
              <th>Tipo</th>
              <th>Tarifa</th>
              <th>Oferta</th>
              <th>Precio</th>
              <th>Dto</th>
              <th>Neto</th>
              <th>Sin Iva</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in tarifasRows" :key="row.tarifa">
              <td>{{ row.tarifa }}</td>
              <td>{{ row.tipo }}</td>
              <td>{{ row.nombre }}</td>
              <td>{{ row.oferta }}</td>
              <td class="num">{{ row.precio }}</td>
              <td class="num">{{ row.dto }}</td>
              <td class="num">{{ row.neto }}</td>
              <td class="num">{{ row.sinIva }}</td>
            </tr>
            <tr v-if="tarifasRows.length === 0">
              <td colspan="8" class="empty">Sin tarifas</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="panel">
      <header>Proveedores</header>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Proveedor</th>
              <th>Nombre</th>
              <th>Fecha</th>
              <th>Precio ST</th>
              <th>Precio CT</th>
              <th>Transporte</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="ficha.proveedorHabitual">
              <td>{{ ficha.proveedorHabitual }}</td>
              <td>—</td>
              <td>{{ ficha.fechaUltCompra ? String(ficha.fechaUltCompra).slice(0, 10) : '' }}</td>
              <td class="num">{{ ficha.precioUltimoST ?? 0 }}</td>
              <td class="num">{{ ficha.precioUltimo ?? 0 }}</td>
              <td class="num">0</td>
            </tr>
            <tr v-else>
              <td colspan="6" class="empty">Sin proveedor habitual</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </aside>
</template>

<style scoped>
.side-panels {
  display: grid;
  grid-template-rows: 1fr 1fr 1fr;
  gap: 0.4rem;
  min-height: 320px;
  padding: 0.5rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-left: none;
  border-radius: 0 0 8px 8px;
}

.panel {
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  overflow: hidden;
}

.panel header {
  padding: 0.25rem 0.45rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
  background: #e8edf2;
  border-bottom: 1px solid #c5cdd8;
}

.table-wrap {
  flex: 1;
  overflow: auto;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.72rem;
}

th,
td {
  padding: 0.2rem 0.35rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  white-space: nowrap;
}

th {
  background: #f8fafc;
  position: sticky;
  top: 0;
  z-index: 1;
}

.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.empty {
  text-align: center;
  color: #94a3b8;
  font-style: italic;
}
</style>
