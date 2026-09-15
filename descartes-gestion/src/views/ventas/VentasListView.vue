<script setup lang="ts">
import { computed, onActivated, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { listarVentas } from '@/api/ventas'
import type { VentaResumen } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useVentasBusquedaStore } from '@/stores/ventasBusqueda'
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

/** Filas por peticion al abrir el listado. */
const BLOQUE_CARGA = GRID_LIMITE_INICIAL
/** Filas montadas en el DOM; crecen al hacer scroll (el listado no tiene paginas). */
const RENDER_INICIAL = 300
const RENDER_PASO = 300

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<VentaResumen[]>([])
const total = ref(0)
const gridEl = ref<HTMLDivElement | null>(null)
const renderLimite = ref(RENDER_INICIAL)
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

/** Trae como máximo los 200 primeros resultados del filtro. */
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
      page: 1,
      pageSize: BLOQUE_CARGA,
    })
    items.value = data.items
    total.value = data.total
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
  return cargar()
}

type ColumnaKey =
  | 'tienda'
  | 'fecha'
  | 'albaran'
  | 'cliente'
  | 'puesto'
  | 'vendedor'
  | 'estado'
  | 'importe'
  | 'factura'

const COLUMNAS: { key: ColumnaKey; label: string; clase: string }[] = [
  { key: 'tienda', label: 'Tienda', clase: 'col-tienda' },
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'albaran', label: 'Albarán', clase: 'col-alb' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cli' },
  { key: 'puesto', label: 'Puesto', clase: 'col-puesto' },
  { key: 'vendedor', label: 'Vendedor', clase: 'col-vend' },
  { key: 'estado', label: 'Estado', clase: 'col-estado' },
  { key: 'importe', label: 'Importe', clase: 'col-imp' },
  { key: 'factura', label: 'Factura', clase: 'col-fact' },
]

/** Texto que ve el usuario en cada columna: es sobre el que se filtra al escribir. */
const textoColumna: Record<ColumnaKey, (v: VentaResumen) => string> = {
  tienda: (v) => `${v.empresa ?? ''} ${nombreTienda(v.empresa)}`,
  fecha: (v) => fmtFecha(v.fecha),
  albaran: (v) => `${v.tipo ?? ''}-${v.albaran} ${v.albaran}`,
  cliente: (v) => `${v.cliente ?? ''} ${v.razonSocial ?? ''}`,
  puesto: (v) => String(v.puesto ?? ''),
  vendedor: (v) => String(v.vendedor ?? ''),
  estado: (v) => String(v.estado ?? ''),
  importe: (v) => Number(v.importe ?? 0).toFixed(2),
  factura: (v) => fmtFactura(v),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    tienda: '',
    fecha: '',
    albaran: '',
    cliente: '',
    puesto: '',
    vendedor: '',
    estado: '',
    importe: '',
    factura: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>({
  ...filtrosColumnaVacios(),
  ...(busqueda.filtrosColumna as Record<ColumnaKey, string>),
})

/** Compara sin acentos ni mayusculas: "MARIA" encuentra "María". */
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
      : items.value.filter((v) =>
          activos.every((f) => normalizar(textoColumna[f.key](v)).includes(f.valor))
        )
  return ordenarFilas(base, (row, key) => textoColumna[key as ColumnaKey](row), ['fecha'])
})

/** Solo se montan las primeras filas; el resto entra al bajar el scroll. */
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

watch(itemsFiltrados, (lista) => {
  renderLimite.value = RENDER_INICIAL
  if (gridEl.value) gridEl.value.scrollTop = 0
  busqueda.setNavegacion(lista)
})

watch(
  filtrosColumna,
  (f) => {
    busqueda.setFiltrosColumna(f)
  },
  { deep: true }
)

function abrir(v: VentaResumen) {
  busqueda.setNavegacion(itemsFiltrados.value)
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

/** ¿La última búsqueda guardada corresponde a los filtros actuales? */
function mismosFiltrosQueElStore(): boolean {
  const guardados = busqueda.filtros
  if (!guardados) return false
  return (Object.keys(filtros.value) as (keyof typeof filtros.value)[]).every(
    (k) => String(guardados[k] ?? '') === String(filtros.value[k] ?? '')
  )
}

onActivated(() => {
  // El primer activated va junto al mount; no duplicar la carga.
  if (omitirProximoActivated.value) {
    omitirProximoActivated.value = false
    return
  }
  // Al volver de una ficha no se recargan miles de filas: el store ya trae los cambios.
  if (busqueda.itemsCargados.length > 0 && mismosFiltrosQueElStore()) {
    items.value = busqueda.itemsCargados.map((i) => ({ ...i }))
    total.value = busqueda.totalCargados
    filtrosColumna.value = {
      ...filtrosColumnaVacios(),
      ...(busqueda.filtrosColumna as Record<ColumnaKey, string>),
    }
    return
  }
  void cargar()
})
</script>

<template>
  <section class="ventas-view">
    <div class="head">
      <div>
        <h2>Ventas</h2>
        <p class="hint">
          Tienda: <strong class="tienda-activa">{{ tiendaLabel }}</strong>
          — Escriba bajo cada columna para filtrar el listado.
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
        <p v-if="loading" class="msg">
          Cargando ventas… {{ items.length }}<template v-if="total"> de {{ total }}</template>
        </p>
        <p v-else-if="total > items.length" class="msg">
          Mostrando {{ items.length }} de {{ total }}. Ajuste el filtro para acotar.
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
                v-for="v in visibles"
                :key="`${v.empresa}-${v.tipo}-${v.albaran}`"
                @dblclick="abrir(v)"
              >
                <td class="tienda col-tienda">{{ nombreTienda(v.empresa) }}</td>
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
                <td class="col-puesto">{{ v.puesto || '—' }}</td>
                <td class="col-vend">{{ v.vendedor || '—' }}</td>
                <td class="col-estado">{{ v.estado || '—' }}</td>
                <td class="num col-imp">{{ Number(v.importe ?? 0).toFixed(2) }}</td>
                <td class="col-fact">{{ fmtFactura(v) }}</td>
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
            {{ itemsFiltrados.length === 1 ? 'venta' : 'ventas' }}
            <template v-if="hayFiltroColumna">de {{ items.length }} cargadas</template>
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

/* La pagina no scrollea: el alto lo reparte el flex y el scroll va dentro del grid. */
.ventas-view {
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
/* Cabecera fija: titulo y, debajo, el input que filtra mientras se escribe. */
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
/* Anchos proporcionales (table-layout: fixed): el sobrante se reparte entre todas. */
.col-tienda {
  width: 5rem;
}
.col-fecha {
  width: 7rem;
}
.col-alb {
  width: 8.5rem;
}
.col-cli {
  width: 17rem;
  white-space: nowrap;
}
.col-puesto {
  width: 5.5rem;
}
.col-vend {
  width: 6rem;
}
.col-estado {
  width: 5.5rem;
}
.col-imp {
  width: 8rem;
}
.col-fact {
  width: 8.5rem;
}
td.col-puesto,
td.col-vend,
td.col-estado,
td.col-fact {
  text-align: center;
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
  .ventas-view {
    height: auto;
  }
  .layout-busqueda {
    grid-template-columns: 1fr;
  }
  .grid-wrap {
    max-height: 70vh;
  }
}
</style>
