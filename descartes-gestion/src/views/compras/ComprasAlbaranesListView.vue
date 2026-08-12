<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { listarAlbaranesCompra } from '@/api/compras'
import type { AlbaranCompraResumen } from '@/types/compras'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/extractApiError'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import ListPagination from '@/components/common/ListPagination.vue'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

const router = useRouter()
const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()
const puedeCrear = computed(() => puede('compras', 'crear'))

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<AlbaranCompraResumen[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize())
const tiendas = ref<{ value: string; label: string; corto: string }[]>([])
const almacenes = ref<{ value: string; label: string }[]>([])
const buscarOpen = ref(false)

const hoy = new Date().toISOString().slice(0, 10)
const filtros = ref({
  empresa: puestoContexto.empresaCodigo || '',
  fechaDesde: hoy,
  fechaHasta: hoy,
  proveedor: '',
  almacen: '' as string,
  albaran: '' as string,
  suAlbaran: '',
})

const tiendaLabel = computed(() => {
  const codigo = filtros.value.empresa
  if (!codigo) return 'Todas'
  const t = tiendas.value.find((x) => x.value === codigo)
  return t?.corto ?? codigo
})

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', {
      params: { activo: true, pageSize: 200 },
    })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo).trim()
      return {
        value: codigo,
        label: `${codigo} - ${t.nombre}`,
        corto: codigo,
      }
    })
    if (!filtros.value.empresa && puestoContexto.empresaCodigo) {
      filtros.value.empresa = puestoContexto.empresaCodigo
    }
  } catch {
    tiendas.value = []
  }
}

async function cargarAlmacenes() {
  try {
    const { data } = await api.get('/api/mantenimiento/almacenes', {
      params: { activo: true, pageSize: 200 },
    })
    almacenes.value = (data.items ?? []).map(
      (a: { codigo: string | number; nombre?: string }) => {
        const codigo = String(a.codigo).trim()
        return {
          value: codigo,
          label: a.nombre ? `${codigo} - ${a.nombre}` : codigo,
        }
      }
    )
  } catch {
    almacenes.value = []
  }
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const albaranNum = filtros.value.albaran.trim()
      ? Number(filtros.value.albaran)
      : undefined
    const almacenNum = filtros.value.almacen.trim()
      ? Number(filtros.value.almacen)
      : undefined
    const data = await listarAlbaranesCompra({
      empresa: filtros.value.empresa || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      proveedor: filtros.value.proveedor || undefined,
      almacen: Number.isFinite(almacenNum) ? almacenNum : undefined,
      albaran: Number.isFinite(albaranNum) ? albaranNum : undefined,
      suAlbaran: filtros.value.suAlbaran || undefined,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    page.value = data.page
    pageSize.value = data.pageSize
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar los albaranes de compra')
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

function abrir(a: AlbaranCompraResumen) {
  router.push({
    name: 'compras-albaran-detalle',
    params: { empresa: a.empresa, albaran: String(a.albaran) },
  })
}

function nuevo() {
  router.push({ name: 'compras-albaran-nuevo' })
}

function nombreTienda(codigo: string) {
  const t = tiendas.value.find((x) => x.value === codigo)
  return t ? t.corto : codigo
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function flags(a: AlbaranCompraResumen) {
  const bits: string[] = []
  if (a.albaranDevolucion) bits.push('Dev')
  if (a.actualizado) bits.push('Stock')
  if (a.trasCtb) bits.push('Ctb')
  return bits.length ? bits.join(' · ') : '—'
}

function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  filtros.value.proveedor = r.codigo
  buscarOpen.value = false
}

const omitirProximoActivated = ref(true)

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarAlmacenes()])
  await cargar()
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
  <section class="compras-albaranes-view">
    <div class="head">
      <div>
        <h2>Albaranes de compra</h2>
        <p class="hint">
          Tienda: <strong class="tienda-activa">{{ tiendaLabel }}</strong>
          — Doble clic o el nº de albarán para abrir el detalle
        </p>
      </div>
      <button v-if="puedeCrear" type="button" class="btn-nuevo" @click="nuevo">
        Nuevo albarán
      </button>
    </div>

    <div class="layout-busqueda">
      <form class="panel-filtros" @submit.prevent="buscar">
        <h3>Búsqueda</h3>

        <label class="filtro-tienda">
          Tienda
          <select v-model="filtros.empresa" title="Tienda">
            <option value="">Todas</option>
            <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </label>

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
          Almacén
          <select v-model="filtros.almacen" title="Almacén">
            <option value="">Todos</option>
            <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
          </select>
        </label>

        <label>
          Albarán
          <input v-model="filtros.albaran" type="text" inputmode="numeric" placeholder="Nº" />
        </label>

        <label>
          Su albarán
          <input v-model="filtros.suAlbaran" type="text" placeholder="Nº proveedor" />
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
                <th class="col-tienda">Tienda</th>
                <th class="col-fecha">Fecha</th>
                <th class="col-alb">Albarán</th>
                <th class="col-su">Su alb.</th>
                <th>Proveedor</th>
                <th class="col-corto">Almacén</th>
                <th class="num col-imp">Importe</th>
                <th class="col-flags">Flags</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="a in items"
                :key="`${a.empresa}-${a.albaran}`"
                @dblclick="abrir(a)"
              >
                <td class="tienda">{{ nombreTienda(a.empresa) }}</td>
                <td class="col-fecha">{{ fmtFecha(a.fechaAlbaran) }}</td>
                <td class="col-alb">
                  <button type="button" class="linkish" @click="abrir(a)">
                    {{ a.albaran }}
                  </button>
                </td>
                <td class="col-su">{{ a.suAlbaran || '—' }}</td>
                <td class="col-prov">
                  <span class="prov-cod">{{ a.proveedor }}</span>
                  <span v-if="a.razonSocial" class="prov-nom">{{ a.razonSocial }}</span>
                </td>
                <td class="col-corto">{{ a.almacen ?? '—' }}</td>
                <td class="num col-imp">{{ Number(a.importeAlb ?? 0).toFixed(2) }}</td>
                <td class="col-flags">{{ flags(a) }}</td>
              </tr>
              <tr v-if="!loading && items.length === 0">
                <td colspan="8">Sin resultados</td>
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
.compras-albaranes-view h2 {
  margin: 0 0 0.35rem;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.65rem;
}
.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0;
}
.tienda-activa {
  color: #1d4ed8;
  background: #dbeafe;
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
}
.btn-nuevo {
  padding: 0.45rem 0.9rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
  white-space: nowrap;
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
.panel-filtros input,
.panel-filtros select {
  width: 100%;
  box-sizing: border-box;
  padding: 0.35rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}
.filtro-tienda select {
  border: 2px solid #2563eb;
  background: #dbeafe;
  font-weight: 700;
  color: #1e3a8a;
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
.fechas-row input[type='date']::-webkit-calendar-picker-indicator {
  margin-left: 0;
  padding: 0;
  width: 0.9rem;
  height: 0.9rem;
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
.col-tienda {
  width: 4.5rem;
}
.col-fecha {
  width: 6.5rem;
}
.col-alb {
  width: 5.5rem;
}
.col-su {
  width: 6rem;
}
.col-corto {
  width: 4.5rem;
}
.col-flags {
  width: 7rem;
}
th.col-corto,
td.col-corto {
  text-align: center;
}
.col-imp {
  width: 6rem;
}
th.col-imp,
td.col-imp,
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
th:first-child,
td.tienda {
  background: #eff6ff;
  font-weight: 700;
  color: #1e40af;
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
