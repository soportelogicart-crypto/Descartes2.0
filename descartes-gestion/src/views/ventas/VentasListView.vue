<script setup lang="ts">
import { computed, onActivated, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { listarVentas } from '@/api/ventas'
import type { VentaResumen } from '@/types/ventas'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useVentasBusquedaStore } from '@/stores/ventasBusqueda'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import ListPagination from '@/components/common/ListPagination.vue'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

type BuscarEntidad = 'clientes' | 'trabajadores' | 'puestos-trabajo'

const ESTADOS_VENTA = [
  { value: '', label: 'Todos' },
  { value: 'B', label: 'B — En edición' },
  { value: 'D', label: 'D' },
  { value: 'F', label: 'F — Facturado' },
  { value: 'G', label: 'G — Contado diferido' },
] as const

const CLASES_DOCUMENTO = [
  { value: '', label: 'Todos' },
  { value: 'albaran', label: 'Albarán' },
  { value: 'presupuesto', label: 'Presupuesto' },
  { value: 'factura', label: 'Factura' },
  { value: 'ticket', label: 'Ticket' },
] as const

const router = useRouter()
const puestoContexto = usePuestoContextoStore()
const busqueda = useVentasBusquedaStore()

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<VentaResumen[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize())
const tiendas = ref<{ value: string; label: string; corto: string }[]>([])
const buscarOpen = ref(false)
const buscarEntidad = ref<BuscarEntidad>('clientes')
const buscarCampo = ref<'cliente' | 'vendedor' | 'puesto'>('cliente')
const buscarTitulo = ref('Buscar')
const buscarInicial = ref('')

/** Legacy: tienda "001" y ventas Empresa "1" deben coincidir. */
function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

const filtros = ref({
  empresa: normalizarEmpresaCodigo(
    busqueda.filtros?.empresa || puestoContexto.empresaCodigo || ''
  ),
  fechaDesde: busqueda.filtros?.fechaDesde ?? '',
  fechaHasta: busqueda.filtros?.fechaHasta ?? '',
  puesto: busqueda.filtros?.puesto || '',
  vendedor: busqueda.filtros?.vendedor || '',
  cliente: busqueda.filtros?.cliente || '',
  estado: busqueda.filtros?.estado || '',
  claseDocumento: busqueda.filtros?.claseDocumento || '',
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
      filtros.value.empresa = normalizarEmpresaCodigo(puestoContexto.empresaCodigo)
    }
  } catch {
    tiendas.value = []
  }
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarVentas({
      empresa: filtros.value.empresa ? normalizarEmpresaCodigo(filtros.value.empresa) : undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      puesto: filtros.value.puesto || undefined,
      vendedor: filtros.value.vendedor || undefined,
      cliente: filtros.value.cliente || undefined,
      estado: filtros.value.estado || undefined,
      claseDocumento: filtros.value.claseDocumento || undefined,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    page.value = data.page
    pageSize.value = data.pageSize
    busqueda.setResultado({
      filtros: { ...filtros.value },
      items: data.items,
      total: data.total,
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las ventas')
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

function abrir(v: VentaResumen) {
  router.push(`/ventas/${encodeURIComponent(v.empresa)}/${encodeURIComponent(v.tipo)}/${v.albaran}`)
}

function nueva() {
  router.push({ name: 'ventas-nuevo' })
}

function nombreTienda(codigo: string) {
  const t = tiendas.value.find((x) => x.value === codigo)
  return t ? t.corto : codigo
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

/** Nº documento tipificado: F-1523, T-88, A-12… */
function fmtFactura(v: VentaResumen) {
  const n = Number(v.factura ?? 0)
  if (Number.isFinite(n) && n > 0) {
    const t = String(v.facturaTipo ?? '').trim().toUpperCase() || 'F'
    return `${t}-${n}`
  }
  return '—'
}

function abrirBuscar(campo: 'cliente' | 'vendedor' | 'puesto') {
  const mapa: Record<typeof campo, { entidad: BuscarEntidad; titulo: string; inicial: string }> = {
    cliente: { entidad: 'clientes', titulo: 'Buscar cliente', inicial: filtros.value.cliente },
    vendedor: {
      entidad: 'trabajadores',
      titulo: 'Buscar vendedor',
      inicial: filtros.value.vendedor,
    },
    puesto: {
      entidad: 'puestos-trabajo',
      titulo: 'Buscar puesto (caja/TPV)',
      inicial: filtros.value.puesto,
    },
  }
  const cfg = mapa[campo]
  buscarCampo.value = campo
  buscarEntidad.value = cfg.entidad
  buscarTitulo.value = cfg.titulo
  buscarInicial.value = cfg.inicial
  buscarOpen.value = true
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  filtros.value[buscarCampo.value] = r.codigo
  buscarOpen.value = false
}

function cerrarBuscar() {
  buscarOpen.value = false
}

const omitirProximoActivated = ref(true)

onMounted(async () => {
  await cargarTiendas()
  await cargar()
})

onActivated(() => {
  // El primer activated va junto al mount; no duplicar la carga.
  if (omitirProximoActivated.value) {
    omitirProximoActivated.value = false
    return
  }
  void cargar()
})
</script>

<template>
  <section class="ventas-view">
    <VentaToolbar
      :puede-crear="true"
      :loading="loading"
      :indice="-1"
      :total="0"
      @nuevo="nueva"
      @buscar="buscar"
    />

    <div class="head">
      <div>
        <h2>Ventas</h2>
        <p class="hint">
          Tienda: <strong class="tienda-activa">{{ tiendaLabel }}</strong>
          — Pulse <strong>Nuevo</strong> para iniciar una venta.
          Deje fechas vacías para ver todos los albaranes (paginado).
        </p>
      </div>
      <button type="button" class="btn-nuevo" @click="nueva">Nueva venta</button>
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
          v-model="filtros.puesto"
          label="Puesto (caja/TPV)"
          title="Código del puesto de trabajo (caja / TPV)"
          placeholder="Código"
          maxlength="2"
          @buscar="abrirBuscar('puesto')"
        />
        <FiltroLupaField
          v-model="filtros.vendedor"
          label="Vendedor"
          placeholder="Código"
          maxlength="4"
          @buscar="abrirBuscar('vendedor')"
        />
        <FiltroLupaField
          v-model="filtros.cliente"
          label="Cliente"
          placeholder="Código / nombre"
          @buscar="abrirBuscar('cliente')"
        />
        <label>
          Tipo documento
          <select v-model="filtros.claseDocumento" title="Clase comercial del documento">
            <option v-for="c in CLASES_DOCUMENTO" :key="c.value || 'todos'" :value="c.value">
              {{ c.label }}
            </option>
          </select>
        </label>
        <label>
          Estado
          <select v-model="filtros.estado" title="Estado del albarán">
            <option v-for="e in ESTADOS_VENTA" :key="e.value || 'todos'" :value="e.value">
              {{ e.label }}
            </option>
          </select>
        </label>

        <button type="submit" class="btn-buscar">{{ loading ? 'Buscando…' : 'Buscar' }}</button>
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
                <th>Cliente</th>
                <th class="col-corto">Puesto</th>
                <th class="col-corto">Vendedor</th>
                <th class="col-corto">Estado</th>
                <th class="num col-imp">Importe</th>
                <th class="col-corto">Factura</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="v in items"
                :key="`${v.empresa}-${v.tipo}-${v.albaran}`"
                @dblclick="abrir(v)"
              >
                <td class="tienda">{{ nombreTienda(v.empresa) }}</td>
                <td class="col-fecha">{{ fmtFecha(v.fecha) }}</td>
                <td class="col-alb">
                  <button type="button" class="linkish" @click="abrir(v)">
                    {{ v.tipo }}-{{ v.albaran }}
                  </button>
                </td>
                <td class="col-cli">
                  <span class="cli-cod">{{ v.cliente }}</span>
                  <span v-if="v.razonSocial" class="cli-nom">{{ v.razonSocial }}</span>
                </td>
                <td class="col-corto">{{ v.puesto || '—' }}</td>
                <td class="col-corto">{{ v.vendedor || '—' }}</td>
                <td class="col-corto">{{ v.estado || '—' }}</td>
                <td class="num col-imp">{{ Number(v.importe ?? 0).toFixed(2) }}</td>
                <td class="col-corto">{{ fmtFactura(v) }}</td>
              </tr>
              <tr v-if="!loading && items.length === 0">
                <td colspan="9">Sin resultados</td>
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
      :entidad="buscarEntidad"
      :titulo="buscarTitulo"
      :busqueda-inicial="buscarInicial"
      @seleccionar="onEntidadSeleccionada"
      @cerrar="cerrarBuscar"
    />
  </section>
</template>

<style scoped>
.ventas-view h2 {
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
  width: 7rem;
}
.col-corto {
  width: 4.5rem;
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
.col-cli {
  white-space: nowrap;
}
.cli-cod {
  font-weight: 600;
  margin-right: 0.35rem;
}
.cli-nom {
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
