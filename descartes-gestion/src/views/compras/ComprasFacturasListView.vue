<script setup lang="ts">
import { onActivated, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { listarFacturasCompra } from '@/api/compras'
import type { FacturaCompraResumen } from '@/types/compras'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/extractApiError'
import ListPagination from '@/components/common/ListPagination.vue'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

const router = useRouter()

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<FacturaCompraResumen[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize())
const buscarOpen = ref(false)

const hoy = new Date().toISOString().slice(0, 10)
const filtros = ref({
  fechaDesde: hoy,
  fechaHasta: hoy,
  proveedor: '',
  factura: '' as string,
  suFactura: '',
  estado: '',
})

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const facturaNum = filtros.value.factura.trim()
      ? Number(filtros.value.factura)
      : undefined
    const data = await listarFacturasCompra({
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      proveedor: filtros.value.proveedor || undefined,
      factura: Number.isFinite(facturaNum) ? facturaNum : undefined,
      suFactura: filtros.value.suFactura || undefined,
      estado: filtros.value.estado || undefined,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    page.value = data.page
    pageSize.value = data.pageSize
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las facturas de proveedor')
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

function abrir(f: FacturaCompraResumen) {
  router.push({
    name: 'compras-factura-detalle',
    params: { factura: String(f.factura) },
  })
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function baseTotal(f: FacturaCompraResumen) {
  return Number(f.baseImp1 ?? 0) + Number(f.baseImp2 ?? 0) + Number(f.baseImp3 ?? 0)
}

function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  filtros.value.proveedor = r.codigo
  buscarOpen.value = false
}

const omitirProximoActivated = ref(true)

onMounted(() => {
  void cargar()
})

onActivated(() => {
  if (omitirProximoActivated.value) {
    omitirProximoActivated.value = false
    return
  }
  void cargar()
})
</script>

<template>
  <section class="compras-facturas-view">
    <div class="head">
      <div>
        <h2>Facturas de proveedor</h2>
        <p class="hint">Consulta solo lectura — doble clic o el nº de factura para abrir el detalle</p>
      </div>
    </div>

    <div class="layout-busqueda">
      <form class="panel-filtros" @submit.prevent="buscar">
        <h3>Búsqueda</h3>

        <div class="fechas-row">
          <label>
            Desde
            <input v-model="filtros.fechaDesde" type="date" />
          </label>
          <label>
            Hasta
            <input v-model="filtros.fechaHasta" type="date" />
          </label>
        </div>

        <FiltroLupaField
          v-model="filtros.proveedor"
          label="Proveedor"
          placeholder="Código / nombre"
          @buscar="buscarOpen = true"
        />

        <label>
          Factura
          <input v-model="filtros.factura" type="text" inputmode="numeric" placeholder="Nº" />
        </label>

        <label>
          Su factura
          <input v-model="filtros.suFactura" type="text" placeholder="Nº proveedor" />
        </label>

        <label>
          Estado
          <input v-model="filtros.estado" type="text" maxlength="4" placeholder="Estado" />
        </label>

        <button type="submit" class="btn-buscar" :disabled="loading">Buscar</button>
      </form>

      <div class="panel-listado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="loading" class="msg">Cargando...</p>

        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-fecha">Fecha</th>
                <th class="col-fac">Factura</th>
                <th class="col-su">Su factura</th>
                <th>Proveedor</th>
                <th class="col-corto">Estado</th>
                <th class="col-corto">F.Pago</th>
                <th class="num col-imp">Base</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="f in items"
                :key="f.factura"
                @dblclick="abrir(f)"
              >
                <td class="col-fecha">{{ fmtFecha(f.fecha) }}</td>
                <td class="col-fac">
                  <button type="button" class="linkish" @click="abrir(f)">
                    {{ f.factura }}
                  </button>
                </td>
                <td class="col-su">{{ f.suFactura || '—' }}</td>
                <td class="col-prov">
                  <span class="prov-cod">{{ f.proveedor }}</span>
                  <span v-if="f.razonSocial" class="prov-nom">{{ f.razonSocial }}</span>
                </td>
                <td class="col-corto">{{ f.estado || '—' }}</td>
                <td class="col-corto">{{ f.fpago || '—' }}</td>
                <td class="num col-imp">{{ baseTotal(f).toFixed(2) }}</td>
              </tr>
              <tr v-if="!loading && items.length === 0">
                <td colspan="7">Sin resultados</td>
              </tr>
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
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      entidad="proveedores"
      titulo="Buscar proveedor"
      :busqueda-inicial="filtros.proveedor"
      @seleccionar="onProveedorSeleccionado"
      @cerrar="buscarOpen = false"
    />
  </section>
</template>

<style scoped>
.compras-facturas-view h2 {
  margin: 0 0 0.35rem;
}
.head {
  margin-bottom: 0.65rem;
}
.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0;
}

.layout-busqueda {
  display: grid;
  grid-template-columns: 16.5rem minmax(0, 1fr);
  gap: 0.85rem;
  align-items: start;
}

.panel-filtros {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #f8fafc;
  min-width: 0;
  overflow: hidden;
}
.panel-filtros h3 {
  margin: 0 0 0.15rem;
  font-size: 0.9rem;
  color: #0f172a;
}
.panel-filtros label {
  display: flex;
  flex-direction: column;
  font-size: 0.75rem;
  gap: 0.2rem;
  color: #475569;
}
.panel-filtros input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.35rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}
.fechas-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
  width: 100%;
  min-width: 0;
}
.fechas-row label {
  min-width: 0;
  max-width: 100%;
}
.fechas-row input[type='date'] {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  box-sizing: border-box;
  font-size: 0.72rem;
  padding: 0.28rem 0.2rem;
}
.btn-buscar {
  margin-top: 0.35rem;
  padding: 0.45rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
  font-weight: 600;
}
.btn-buscar:disabled {
  opacity: 0.6;
}

.panel-listado {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  max-height: calc(100vh - 14rem);
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  table-layout: fixed;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.45rem;
  text-align: left;
  vertical-align: middle;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
th {
  background: #f1f5f9;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
}
.col-fecha {
  width: 6.5rem;
}
.col-fac {
  width: 5.5rem;
}
.col-su {
  width: 7rem;
}
.col-corto {
  width: 4.5rem;
}
.col-imp {
  width: 6rem;
}
th.col-corto,
td.col-corto {
  text-align: center;
}
th.col-imp,
td.col-imp,
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-prov {
  white-space: nowrap;
}
.prov-cod {
  font-weight: 600;
  margin-right: 0.35rem;
}
.prov-nom {
  color: #475569;
}
.linkish {
  background: none;
  border: none;
  color: #0369a1;
  cursor: pointer;
  text-decoration: underline;
  padding: 0;
  font: inherit;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.msg {
  color: #475569;
  margin: 0;
}
tbody tr {
  cursor: pointer;
}
tbody tr:hover {
  background: #f8fafc;
}

@media (max-width: 900px) {
  .layout-busqueda {
    grid-template-columns: 1fr;
  }
  .grid-wrap {
    max-height: none;
  }
}
</style>
