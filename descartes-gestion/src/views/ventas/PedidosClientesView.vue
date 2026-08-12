<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { listarPedidos } from '@/api/ventas'
import type { PedidoResumen } from '@/types/ventas'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { usePedidosBusquedaStore } from '@/stores/pedidosBusqueda'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import ListPagination from '@/components/common/ListPagination.vue'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

type BuscarEntidad = 'clientes' | 'trabajadores' | 'puestos-trabajo'

const SITUACIONES = [
  { value: '', label: 'Todas' },
  { value: 'abierto', label: 'Abierto' },
  { value: 'cerrado', label: 'Cerrado' },
] as const

const router = useRouter()
const { puede } = usePermisos()
const puedeCrear = computed(() => puede('ventas-pedidos', 'crear'))
const puestoContexto = usePuestoContextoStore()
const busqueda = usePedidosBusquedaStore()

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<PedidoResumen[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize())
const tiendas = ref<{ value: string; label: string; corto: string }[]>([])
const buscarOpen = ref(false)
const buscarEntidad = ref<BuscarEntidad>('clientes')
const buscarCampo = ref<'cliente' | 'vendedor' | 'puesto'>('cliente')
const buscarTitulo = ref('Buscar')
const buscarInicial = ref('')

const hoy = new Date().toISOString().slice(0, 10)
const filtros = ref({
  empresa: busqueda.filtros?.empresa || puestoContexto.empresaCodigo || '',
  fechaDesde: busqueda.filtros?.fechaDesde || hoy,
  fechaHasta: busqueda.filtros?.fechaHasta || hoy,
  puesto: busqueda.filtros?.puesto || '',
  vendedor: busqueda.filtros?.vendedor || '',
  cliente: busqueda.filtros?.cliente || '',
  situacion: busqueda.filtros?.situacion || '',
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

/** Refresca la tabla local con lo último del store (p. ej. vendedor cambiado en ficha). */
function sincronizarDesdeStore() {
  if (!busqueda.items.length) return
  const porClave = new Map(busqueda.items.map((p) => [`${p.empresa}|${p.pedido}`, p]))
  items.value = items.value.map((p) => {
    const act = porClave.get(`${p.empresa}|${p.pedido}`)
    return act ? { ...act } : p
  })
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarPedidos({
      empresa: filtros.value.empresa || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      puesto: filtros.value.puesto || undefined,
      vendedor: filtros.value.vendedor || undefined,
      cliente: filtros.value.cliente || undefined,
      situacion: filtros.value.situacion || undefined,
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
    error.value = extractApiError(e, 'No se pudieron cargar los pedidos')
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

function abrir(p: PedidoResumen) {
  router.push(`/ventas/pedidos/${encodeURIComponent(p.empresa)}/${p.pedido}`)
}

function nuevo() {
  router.push('/ventas/pedidos/nuevo')
}

function nombreTienda(codigo: string) {
  const t = tiendas.value.find((x) => x.value === codigo)
  return t ? t.corto : codigo
}

function labelSituacion(p: PedidoResumen) {
  return p.situacionLabel || (p.actualizado ? 'CERRADO' : 'ABIERTO')
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
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

watch(
  () => busqueda.items,
  () => sincronizarDesdeStore(),
  { deep: true }
)

onMounted(async () => {
  await cargarTiendas()
  await cargar()
})

onActivated(() => {
  sincronizarDesdeStore()
})
</script>

<template>
  <section class="pedidos-view">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :loading="loading"
      :indice="-1"
      :total="0"
      @nuevo="nuevo"
      @buscar="buscar"
    />

    <div class="head">
      <div>
        <h2>Pedidos</h2>
        <p class="hint">
          Tienda: <strong class="tienda-activa">{{ tiendaLabel }}</strong>
          — Doble clic para abrir
        </p>
      </div>
      <button v-if="puedeCrear" type="button" class="btn-nuevo" @click="nuevo">Nuevo pedido</button>
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
          placeholder="Código / nombre / NIF"
          @buscar="abrirBuscar('cliente')"
        />
        <label>
          Situación
          <select v-model="filtros.situacion" title="Situación del pedido">
            <option v-for="s in SITUACIONES" :key="s.value || 'todas'" :value="s.value">
              {{ s.label }}
            </option>
          </select>
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
                <th class="col-pedido">Pedido</th>
                <th>Cliente</th>
                <th class="col-corto">Puesto</th>
                <th class="col-corto">Vendedor</th>
                <th class="col-sit">Situación</th>
                <th class="num col-imp">Importe</th>
                <th class="col-su">Su pedido</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in items"
                :key="`${p.empresa}-${p.pedido}`"
                @dblclick="abrir(p)"
              >
                <td class="tienda">{{ nombreTienda(p.empresa) }}</td>
                <td class="col-fecha">{{ fmtFecha(p.fecha) }}</td>
                <td class="col-pedido">
                  <button type="button" class="linkish" @click="abrir(p)">
                    {{ p.pedido }}
                  </button>
                </td>
                <td class="col-cli">
                  <span class="cli-cod">{{ p.cliente }}</span>
                  <span v-if="p.razonSocial" class="cli-nom">{{ p.razonSocial }}</span>
                </td>
                <td class="col-corto">{{ p.puesto || '—' }}</td>
                <td class="col-corto">{{ p.vendedor || '—' }}</td>
                <td class="col-sit">
                  <span :class="p.actualizado ? 'tag cerrado' : 'tag abierto'">
                    {{ labelSituacion(p) }}
                  </span>
                </td>
                <td class="num col-imp">{{ Number(p.importe ?? 0).toFixed(2) }}</td>
                <td class="col-su">{{ p.suPedido || '—' }}</td>
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
.pedidos-view h2 {
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
.col-pedido {
  width: 6.5rem;
}
.col-corto {
  width: 4.5rem;
}
th.col-corto,
td.col-corto {
  text-align: center;
}
.col-sit {
  width: 5.5rem;
}
.col-imp {
  width: 6rem;
}
.col-su {
  width: 7rem;
}
th.col-imp,
td.col-imp,
th.num,
td.num {
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
.tag {
  display: inline-block;
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
  font-size: 0.72rem;
  font-weight: 600;
}
.tag.abierto {
  background: #dcfce7;
  color: #166534;
}
.tag.cerrado {
  background: #fee2e2;
  color: #991b1b;
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
