<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { listarPedidosProveedor } from '@/api/compras'
import type { PedidoProveedorResumen, PedidoSituacionLabel } from '@/types/compras'
import { extractApiError } from '@/composables/extractApiError'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

const router = useRouter()
const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()
const puedeCrear = computed(() => puede('compras', 'crear'))

const BLOQUE_CARGA = GRID_LIMITE_INICIAL
const RENDER_INICIAL = 300
const RENDER_PASO = 300

const anioVigor = new Date().getFullYear()
const inicioAnio = `${anioVigor}-01-01`
const finAnio = `${anioVigor}-12-31`

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<PedidoProveedorResumen[]>([])
const total = ref(0)
const gridEl = ref<HTMLDivElement | null>(null)
const renderLimite = ref(RENDER_INICIAL)
const tiendas = ref<{ value: string; label: string; corto: string }[]>([])
const almacenes = ref<{ value: string; label: string }[]>([])
const buscarOpen = ref(false)

function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

const filtros = ref({
  empresa: normalizarEmpresaCodigo(puestoContexto.empresaCodigo || ''),
  fechaDesde: inicioAnio,
  fechaHasta: finAnio,
  proveedor: '',
  almacen: '' as string,
  pedido: '' as string,
  situacion: '' as string,
})

const tiendaLabel = computed(() => {
  const codigo = filtros.value.empresa
  if (!codigo) return 'Todas'
  const t = tiendas.value.find((x) => x.value === codigo)
  return t?.corto ?? codigo
})

type ColumnaKey = 'tienda' | 'fecha' | 'pedido' | 'proveedor' | 'almacen' | 'importe' | 'situacion'

const COLUMNAS: { key: ColumnaKey; label: string; clase: string }[] = [
  { key: 'tienda', label: 'Tienda', clase: 'col-tienda' },
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'pedido', label: 'Pedido', clase: 'col-ped' },
  { key: 'proveedor', label: 'Proveedor', clase: 'col-prov' },
  { key: 'almacen', label: 'Almacén', clase: 'col-corto' },
  { key: 'importe', label: 'Importe', clase: 'col-imp' },
  { key: 'situacion', label: 'Situación', clase: 'col-sit' },
]

const textoColumna: Record<ColumnaKey, (p: PedidoProveedorResumen) => string> = {
  tienda: (p) => `${p.empresa ?? ''} ${nombreTienda(p.empresa)}`,
  fecha: (p) => fmtFecha(p.fechaPedido),
  pedido: (p) => String(p.pedido ?? ''),
  proveedor: (p) => `${p.proveedor ?? ''} ${p.razonSocial ?? ''}`,
  almacen: (p) => String(p.almacen ?? ''),
  importe: (p) => Number(p.importe ?? 0).toFixed(2),
  situacion: (p) => situacionTexto(p),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    tienda: '',
    fecha: '',
    pedido: '',
    proveedor: '',
    almacen: '',
    importe: '',
    situacion: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>(filtrosColumnaVacios())

function normalizar(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

const filtrosColumnaActivos = computed(() =>
  (Object.entries(filtrosColumna.value) as [ColumnaKey, string][])
    .map(([key, valor]) => ({ key, valor: normalizar(valor.trim()) }))
    .filter((f) => f.valor !== '')
)

const hayFiltroColumna = computed(() => filtrosColumnaActivos.value.length > 0)
const { orden, clicarColumna, ordenarFilas } = useOrdenLista()

const itemsFiltrados = computed(() => {
  const activos = filtrosColumnaActivos.value
  const base =
    activos.length === 0
      ? items.value
      : items.value.filter((p) =>
          activos.every((f) => normalizar(textoColumna[f.key](p)).includes(f.valor))
        )
  return ordenarFilas(base, (row, key) => textoColumna[key as ColumnaKey](row), ['fecha'])
})

const visibles = computed(() => itemsFiltrados.value.slice(0, renderLimite.value))

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

function onScrollGrid() {
  const el = gridEl.value
  if (!el) return
  if (el.scrollTop + el.clientHeight >= el.scrollHeight - 250) {
    if (renderLimite.value < itemsFiltrados.value.length) {
      renderLimite.value += RENDER_PASO
    }
  }
}

watch(itemsFiltrados, () => {
  renderLimite.value = RENDER_INICIAL
  if (gridEl.value) gridEl.value.scrollTop = 0
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
    const pedidoNum = filtros.value.pedido.trim() ? Number(filtros.value.pedido) : undefined
    const almacenNum = filtros.value.almacen.trim() ? Number(filtros.value.almacen) : undefined
    const situacionNum =
      filtros.value.situacion !== '' ? Number(filtros.value.situacion) : undefined
    const data = await listarPedidosProveedor({
      empresa: filtros.value.empresa
        ? normalizarEmpresaCodigo(filtros.value.empresa)
        : undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      proveedor: filtros.value.proveedor || undefined,
      almacen: Number.isFinite(almacenNum) ? almacenNum : undefined,
      pedido: Number.isFinite(pedidoNum) ? pedidoNum : undefined,
      situacion: Number.isFinite(situacionNum as number) ? situacionNum : undefined,
      page: 1,
      pageSize: BLOQUE_CARGA,
    })
    items.value = data.items
    total.value = data.total
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar los pedidos a proveedor')
  } finally {
    loading.value = false
  }
}

function buscar() {
  return cargar()
}

function abrir(p: PedidoProveedorResumen) {
  router.push({
    name: 'compras-pedido-detalle',
    params: { empresa: p.empresa, pedido: String(p.pedido) },
  })
}

function nuevo() {
  router.push({ name: 'compras-pedido-nuevo' })
}

function nombreTienda(codigo: string) {
  const t = tiendas.value.find((x) => x.value === codigo)
  return t ? t.corto : codigo
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function situacionTexto(p: PedidoProveedorResumen): string {
  const label = (p.situacionLabel || '') as PedidoSituacionLabel | string
  if (label === 'pendiente' || label === 'parcial' || label === 'servido') {
    return label.charAt(0).toUpperCase() + label.slice(1)
  }
  if (p.situacion === 1) return 'Parcial'
  if (p.situacion === 2) return 'Servido'
  if (p.situacion === 0) return 'Pendiente'
  return '—'
}

function situacionClass(p: PedidoProveedorResumen): string {
  const label = String(p.situacionLabel || '')
  if (label === 'servido' || p.situacion === 2) return 'sit-servido'
  if (label === 'parcial' || p.situacion === 1) return 'sit-parcial'
  return 'sit-pendiente'
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
  <section class="compras-pedidos-view">
    <div class="head">
      <div>
        <h2>Pedidos a proveedor</h2>
        <p class="hint">
          Tienda: <strong class="tienda-activa">{{ tiendaLabel }}</strong>
          — Escriba bajo cada columna para filtrar el listado.
        </p>
      </div>
      <button v-if="puedeCrear" type="button" class="btn-nuevo" @click="nuevo">
        Nuevo pedido
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
          Pedido
          <input v-model="filtros.pedido" type="text" inputmode="numeric" placeholder="Nº" />
        </label>

        <label>
          Situación
          <select v-model="filtros.situacion" title="Situación">
            <option value="">Todas</option>
            <option value="0">Pendiente</option>
            <option value="1">Parcial</option>
            <option value="2">Servido</option>
          </select>
        </label>

        <button type="submit" class="btn-buscar" :disabled="loading">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
      </form>

      <div class="panel-listado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="loading" class="msg">
          Cargando pedidos… {{ items.length }}<template v-if="total"> de {{ total }}</template>
        </p>

        <div ref="gridEl" class="grid-wrap" @scroll.passive="onScrollGrid">
          <table>
            <thead>
              <tr>
                <th v-for="c in COLUMNAS" :key="c.key" :class="c.clase">
                  <span
                    class="th-titulo"
                    :class="{ num: c.key === 'importe' }"
                    :title="`Ordenar por ${c.label}`"
                    @click="clicarColumna(c.key)"
                  >
                    {{ c.label }}
                    <span v-if="orden?.key === c.key" class="marca-orden">{{
                      orden.dir === 'asc' ? '▲' : '▼'
                    }}</span>
                  </span>
                  <input
                    v-model="filtrosColumna[c.key]"
                    type="search"
                    class="filtro-col"
                    :title="`Filtrar por ${c.label}`"
                    :aria-label="`Filtrar por ${c.label}`"
                  />
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="p in visibles"
                :key="`${p.empresa}-${p.pedido}`"
                @dblclick="abrir(p)"
              >
                <td class="tienda col-tienda">{{ nombreTienda(p.empresa) }}</td>
                <td class="col-fecha">{{ fmtFecha(p.fechaPedido) }}</td>
                <td class="col-ped">
                  <button type="button" class="linkish" @click="abrir(p)">
                    {{ p.pedido }}
                  </button>
                </td>
                <td class="col-prov">
                  <span class="prov-cod">{{ p.proveedor }}</span>
                  <span v-if="p.razonSocial" class="prov-nom">{{ p.razonSocial }}</span>
                </td>
                <td class="col-corto">{{ p.almacen ?? '—' }}</td>
                <td class="num col-imp">{{ Number(p.importe ?? 0).toFixed(2) }}</td>
                <td class="col-sit">
                  <span class="badge-sit" :class="situacionClass(p)">{{ situacionTexto(p) }}</span>
                </td>
              </tr>
              <tr v-if="!loading && itemsFiltrados.length === 0">
                <td :colspan="COLUMNAS.length">
                  {{ hayFiltroColumna ? 'Ningún resultado con esos filtros' : 'Sin resultados' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pie-listado">
          <span>
            {{ itemsFiltrados.length }}
            {{ itemsFiltrados.length === 1 ? 'pedido' : 'pedidos' }}
            <template v-if="hayFiltroColumna">de {{ items.length }} cargados</template>
            <template v-else-if="total > items.length">
              (de {{ total }}; mostrando {{ GRID_LIMITE_INICIAL }})
            </template>
          </span>
          <button
            v-if="hayFiltroColumna"
            type="button"
            class="btn-limpiar"
            @click="limpiarFiltrosColumna"
          >
            Limpiar filtros
          </button>
        </div>
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
.compras-pedidos-view h2 {
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

.compras-pedidos-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.layout-busqueda {
  display: grid;
  grid-template-columns: 16.5rem minmax(0, 1fr);
  gap: 0.85rem;
  flex: 1;
  min-height: 0;
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
  align-self: start;
  max-height: 100%;
  overflow-y: auto;
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
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.grid-wrap {
  flex: 1;
  min-height: 8rem;
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
.pie-listado {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  font-size: 0.78rem;
  color: #475569;
}
.btn-limpiar {
  padding: 0.2rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  color: #1e293b;
  cursor: pointer;
  font: inherit;
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
  z-index: 2;
  padding: 0.2rem 0.25rem 0.25rem;
  vertical-align: bottom;
}
.th-titulo {
  display: block;
  padding: 0 0.2rem 0.15rem;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
  user-select: none;
}
.th-titulo:hover {
  color: #1d4ed8;
}
.marca-orden {
  font-size: 0.65rem;
  margin-left: 0.15rem;
  color: #1d4ed8;
}
.th-titulo.num {
  text-align: right;
}
.filtro-col {
  width: 100%;
  box-sizing: border-box;
  padding: 0.15rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font: inherit;
  font-size: 0.76rem;
}
.filtro-col:focus {
  outline: 2px solid #2563eb;
  outline-offset: -1px;
}
.col-tienda {
  width: 4.5rem;
}
.col-fecha {
  width: 6.5rem;
}
.col-ped {
  width: 5.5rem;
}
.col-corto {
  width: 4.5rem;
}
.col-sit {
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
thead tr:first-child th:first-child,
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
.badge-sit {
  display: inline-block;
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 600;
}
.sit-pendiente {
  background: #e2e8f0;
  color: #334155;
}
.sit-parcial {
  background: #fef3c7;
  color: #92400e;
}
.sit-servido {
  background: #dcfce7;
  color: #166534;
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
  .compras-pedidos-view {
    height: auto;
  }
  .layout-busqueda {
    grid-template-columns: 1fr;
  }
  .grid-wrap {
    max-height: none;
  }
}
</style>
