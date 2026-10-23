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

function fmtNum(n: number) {
  return Number(n).toLocaleString('es-ES', { maximumFractionDigits: 4 })
}
</script>

<template>
  <aside class="side-panels">
    <section class="panel">
      <header>Stock por almacen</header>
      <div class="table-wrap">
        <table class="tbl-stock">
          <colgroup>
            <col class="col-cod" />
            <col class="col-nombre" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num-wide" />
          </colgroup>
          <thead>
            <tr>
              <th>Codigo</th>
              <th>Almacen</th>
              <th class="num">Stock</th>
              <th class="num">Pendiente</th>
              <th class="num">Minimo</th>
              <th class="num">Optimo</th>
              <th class="num">Estadistica</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in stockRows" :key="String(row.almacenCodigo)">
              <td>{{ row.almacenCodigo }}</td>
              <td class="col-texto" :title="row.almacenDescripcion">{{ row.almacenDescripcion }}</td>
              <td class="num">{{ fmtNum(row.cantidad) }}</td>
              <td class="num">{{ fmtNum(0) }}</td>
              <td class="num">{{ fmtNum(0) }}</td>
              <td class="num">{{ fmtNum(0) }}</td>
              <td class="num">{{ fmtNum(0) }}</td>
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
        <table class="tbl-tarifas">
          <colgroup>
            <col class="col-cod" />
            <col class="col-tipo" />
            <col class="col-nombre" />
            <col class="col-oferta" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num" />
          </colgroup>
          <thead>
            <tr>
              <th>Tarifa</th>
              <th>Tipo</th>
              <th>Tarifa</th>
              <th>Oferta</th>
              <th class="num">Precio</th>
              <th class="num">Dto</th>
              <th class="num">Neto</th>
              <th class="num">Sin Iva</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in tarifasRows" :key="row.tarifa">
              <td>{{ row.tarifa }}</td>
              <td>{{ row.tipo }}</td>
              <td class="col-texto">{{ row.nombre }}</td>
              <td>{{ row.oferta }}</td>
              <td class="num">{{ fmtNum(row.precio) }}</td>
              <td class="num">{{ fmtNum(row.dto) }}</td>
              <td class="num">{{ fmtNum(row.neto) }}</td>
              <td class="num">{{ fmtNum(row.sinIva) }}</td>
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
        <table class="tbl-prov">
          <colgroup>
            <col class="col-cod" />
            <col class="col-nombre" />
            <col class="col-fecha" />
            <col class="col-num" />
            <col class="col-num" />
            <col class="col-num" />
          </colgroup>
          <thead>
            <tr>
              <th>Proveedor</th>
              <th>Nombre</th>
              <th>Fecha</th>
              <th class="num">Precio ST</th>
              <th class="num">Precio CT</th>
              <th class="num">Transporte</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="ficha.proveedorHabitual">
              <td>{{ ficha.proveedorHabitual }}</td>
              <td class="col-texto">—</td>
              <td>{{ ficha.fechaUltCompra ? String(ficha.fechaUltCompra).slice(0, 10) : '' }}</td>
              <td class="num">{{ fmtNum(Number(ficha.precioUltimoST ?? 0)) }}</td>
              <td class="num">{{ fmtNum(Number(ficha.precioUltimo ?? 0)) }}</td>
              <td class="num">{{ fmtNum(0) }}</td>
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
  min-width: 0;
  padding: 0.5rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-left: none;
  border-radius: 0 0 8px 8px;
}

.panel {
  display: flex;
  flex-direction: column;
  min-height: 5.5rem;
  min-width: 0;
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
  flex-shrink: 0;
}

.table-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
  max-width: 100%;
}

table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.75rem;
}

.tbl-stock,
.tbl-tarifas,
.tbl-prov {
  width: 100%;
  min-width: 36rem;
}

/* Stock: Almacén con tope; el resto se reparte entre numéricas (evita el hueco central). */
.tbl-stock .col-cod {
  width: 8%;
}
.tbl-stock .col-nombre {
  width: 22%;
}
.tbl-stock .col-num {
  width: 13%;
}
.tbl-stock .col-num-wide {
  width: 18%;
}

.tbl-tarifas .col-cod {
  width: 8%;
}
.tbl-tarifas .col-tipo {
  width: 7%;
}
.tbl-tarifas .col-nombre {
  width: 18%;
}
.tbl-tarifas .col-oferta {
  width: 11%;
}
.tbl-tarifas .col-num {
  width: 14%;
}

.tbl-prov .col-cod {
  width: 12%;
}
.tbl-prov .col-nombre {
  width: 22%;
}
.tbl-prov .col-fecha {
  width: 18%;
}
.tbl-prov .col-num {
  width: 16%;
}

th,
td {
  padding: 0.28rem 0.45rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

th {
  background: #f8fafc;
  position: sticky;
  top: 0;
  z-index: 1;
  font-weight: 600;
}

th.num {
  padding-left: 0.55rem;
  padding-right: 0.55rem;
}

.col-texto {
  overflow: hidden;
  text-overflow: ellipsis;
}

.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  padding-left: 0.55rem;
  padding-right: 0.55rem;
}

.empty {
  text-align: center;
  color: #94a3b8;
  font-style: italic;
}

@media (max-width: 1100px) {
  .side-panels {
    grid-template-rows: auto auto auto;
    border-left: 1px solid #c5cdd8;
    border-radius: 0 0 8px 8px;
  }

  .panel {
    min-height: 7rem;
    max-height: 12rem;
  }
}
</style>
