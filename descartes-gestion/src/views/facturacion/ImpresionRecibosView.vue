<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { descargarRecibosPdf, listarRecibosImpresion } from '@/api/facturacion'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import { usePdfPreview } from '@/composables/usePdfPreview'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import type { ReciboImpresionItem } from '@/types/facturacion'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const puesto = usePuestoContextoStore()
const loading = ref(false)
const printing = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<ReciboImpresionItem[]>([])
const selected = ref<Record<string, boolean>>({})
const tiendas = ref<Array<{ value: string; label: string }>>([])
const { pdfOpen, pdfUrl, pdfTitulo, abrirPdf, cerrarPdf } = usePdfPreview('Impresión de recibos')
const buscarClienteOpen = ref(false)

const hoy = new Date().toISOString().slice(0, 10)
const form = ref({
  empresa: '',
  estado: 'pendientes',
  vencimientoDesde: '',
  vencimientoHasta: hoy,
  cliente: '',
  factura: '' as string | number,
})

function key(r: ReciboImpresionItem) {
  return `${r.empresa}|${r.facturaTipo}|${r.factura}|${r.recibo}`
}

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'vencimiento', label: 'Vencimiento', clase: 'col-venc' },
  { key: 'factura', label: 'Factura', clase: 'col-factura' },
  { key: 'recibo', label: 'Recibo', clase: 'col-recibo' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'razonSocial', label: 'Razón social', clase: 'col-razon' },
  { key: 'formaPago', label: 'Forma de pago', clase: 'col-fpago' },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
  { key: 'estado', label: 'Estado', clase: 'col-estado' },
] as const

type ColumnaKey = (typeof COLUMNAS)[number]['key']

function textoEstado(r: ReciboImpresionItem) {
  return r.liquidado ? 'Liquidado' : r.remesado ? 'Remesado' : 'Pendiente'
}

const textoColumna: Record<ColumnaKey, (r: ReciboImpresionItem) => string> = {
  vencimiento: (r) => String(r.vencimiento ?? ''),
  factura: (r) => `${r.facturaTipo}-${r.factura}`,
  recibo: (r) => String(r.recibo ?? ''),
  cliente: (r) => String(r.cliente ?? ''),
  razonSocial: (r) => String(r.razonSocial ?? ''),
  formaPago: (r) => `${r.formaPago ?? ''} ${r.formaPagoDescripcion ?? ''}`.trim(),
  importe: (r) => Number(r.importe ?? 0).toFixed(2),
  estado: (r) => textoEstado(r),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    vencimiento: '',
    factura: '',
    recibo: '',
    cliente: '',
    razonSocial: '',
    formaPago: '',
    importe: '',
    estado: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>(filtrosColumnaVacios())

/** Compara sin acentos ni mayúsculas: "MARIA" encuentra "María". */
function normalizar(texto: string) {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

const filtrosColumnaActivos = computed(() =>
  (Object.entries(filtrosColumna.value) as [ColumnaKey, string][])
    .map(([k, valor]) => ({ key: k, valor: normalizar(valor.trim()) }))
    .filter((f) => f.valor !== '')
)

const hayFiltroColumna = computed(() => filtrosColumnaActivos.value.length > 0)
const { orden, clicarColumna, ordenarFilas } = useOrdenLista()

const itemsFiltrados = computed(() => {
  const activos = filtrosColumnaActivos.value
  const base =
    activos.length === 0
      ? items.value
      : items.value.filter((r) =>
          activos.every((f) => normalizar(textoColumna[f.key](r)).includes(f.valor))
        )
  return ordenarFilas(base, (row, key) => textoColumna[key as ColumnaKey](row), ['fecha'])
})

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

const seleccionados = computed(() => itemsFiltrados.value.filter((r) => selected.value[key(r)]))
const todos = computed(
  () => itemsFiltrados.value.length > 0 && itemsFiltrados.value.every((r) => selected.value[key(r)])
)

function toggleTodos(valor: boolean) {
  const next: Record<string, boolean> = { ...selected.value }
  for (const r of itemsFiltrados.value) next[key(r)] = valor
  selected.value = next
}

function abrirBuscarCliente() {
  buscarClienteOpen.value = true
}

function onClienteSeleccionado(sel: EntidadBuscarResultado) {
  buscarClienteOpen.value = false
  form.value.cliente = sel.codigo
}

async function cargarOpciones() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
    form.value.empresa = String(puesto.empresaCodigo ?? '').trim()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las tiendas')
  }
}

async function buscar() {
  if (!form.value.empresa) {
    error.value = 'Indique la tienda'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarRecibosImpresion({
      empresa: form.value.empresa,
      estado: form.value.estado,
      vencimientoDesde: form.value.vencimientoDesde || undefined,
      vencimientoHasta: form.value.vencimientoHasta || undefined,
      cliente: form.value.cliente || undefined,
      factura: Number(form.value.factura) || undefined,
    })
    const todos = data.items ?? []
    items.value = todos.slice(0, GRID_LIMITE_INICIAL)
    selected.value = {}
    mensaje.value = `${data.totales.recibos} recibos · ${data.totales.importe.toFixed(2)} €`
    if (todos.length > GRID_LIMITE_INICIAL) {
      mensaje.value += ` · mostrando ${GRID_LIMITE_INICIAL}`
    }
  } catch (e: unknown) {
    items.value = []
    error.value = extractApiError(e, 'No se pudieron cargar los recibos')
  } finally {
    loading.value = false
  }
}

async function imprimir() {
  if (!seleccionados.value.length) {
    error.value = 'Seleccione al menos un recibo'
    return
  }
  printing.value = true
  error.value = null
  try {
    const blob = await descargarRecibosPdf({
      recibos: seleccionados.value.map((r) => ({
        empresa: r.empresa,
        facturaTipo: r.facturaTipo,
        factura: r.factura,
        recibo: r.recibo,
      })),
    })
    const head = await blob.slice(0, 5).text()
    if (!head.startsWith('%PDF')) throw new Error('La API no devolvió un PDF válido')
    abrirPdf(blob, `Recibos (${seleccionados.value.length})`)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron imprimir los recibos')
  } finally {
    printing.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
  await buscar()
})
</script>

<template>
  <section class="page">
    <header>
      <div>
        <h2>Impresión de recibos</h2>
        <p>Recibos generados por las facturas a crédito.</p>
      </div>
      <div class="actions">
        <button type="button" :disabled="loading" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button
          type="button"
          class="primary"
          :disabled="printing || !seleccionados.length"
          @click="imprimir"
        >
          {{ printing ? 'Generando…' : 'Imprimir' }}
        </button>
      </div>
    </header>

    <div class="layout">
      <form @submit.prevent="buscar">
        <label>
          Tienda
          <select v-model="form.empresa">
            <option value="">—</option>
            <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </label>
        <label>
          Estado
          <select v-model="form.estado">
            <option value="pendientes">Pendientes</option>
            <option value="liquidados">Liquidados</option>
            <option value="todos">Todos</option>
          </select>
        </label>
        <label>Vencimiento desde <input v-model="form.vencimientoDesde" type="date" /></label>
        <label>Vencimiento hasta <input v-model="form.vencimientoHasta" type="date" /></label>
        <label>
          Cliente
          <div class="con-lupa">
            <input v-model="form.cliente" type="text" maxlength="12" />
            <button
              type="button"
              class="btn-lupa"
              title="Buscar cliente"
              @click="abrirBuscarCliente"
            >
              <ToolIcon name="buscar" />
            </button>
          </div>
        </label>
        <label>Factura <input v-model="form.factura" type="number" min="1" /></label>
      </form>

      <main>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>
        <div v-if="items.length" class="grid">
          <table>
            <thead>
              <tr>
                <th class="sel">
                  <input
                    type="checkbox"
                    :checked="todos"
                    @change="toggleTodos(($event.target as HTMLInputElement).checked)"
                  />
                </th>
                <th v-for="c in COLUMNAS" :key="c.key" :class="c.clase">
                  <span
                    class="th-titulo"
                    :class="{ num: c.num }"
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
                v-for="r in itemsFiltrados"
                :key="key(r)"
                :class="{ checked: selected[key(r)] }"
                @click="selected[key(r)] = !selected[key(r)]"
              >
                <td class="sel" @click.stop>
                  <input v-model="selected[key(r)]" type="checkbox" />
                </td>
                <td>{{ r.vencimiento }}</td>
                <td>{{ r.facturaTipo }}-{{ r.factura }}</td>
                <td>{{ r.recibo }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.formaPago }} {{ r.formaPagoDescripcion }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td>{{ textoEstado(r) }}</td>
              </tr>
              <tr v-if="!itemsFiltrados.length">
                <td :colspan="COLUMNAS.length + 1" class="empty">Ningún recibo con esos filtros</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!loading && !error" class="empty">
          No hay recibos con estas opciones e intervalos.
        </p>
        <div v-if="hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ itemsFiltrados.length }} de {{ items.length }}</span>
          <button type="button" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>
      </main>
    </div>

    <EntidadBuscarModal
      :open="buscarClienteOpen"
      entidad="clientes"
      titulo="Buscar cliente"
      :busqueda-inicial="form.cliente"
      @seleccionar="onClienteSeleccionado"
      @cerrar="buscarClienteOpen = false"
    />

    <PdfPreviewModal
      :open="pdfOpen"
      :url="pdfUrl"
      :titulo="pdfTitulo"
      @cerrar="cerrarPdf"
    />
  </section>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: .75rem; height: 100%; min-height: 0; }
header { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start; }
h2 { margin: 0; }
header p { margin: .15rem 0 0; color: #64748b; font-size: .85rem; }
.actions { display: flex; gap: .4rem; }
button { padding: .4rem .8rem; border: 1px solid #94a3b8; border-radius: 5px; background: #fff; cursor: pointer; }
button.primary { color: #fff; background: #0f172a; border-color: #0f172a; }
button:disabled { opacity: .5; cursor: not-allowed; }
.layout { display: grid; grid-template-columns: 21rem minmax(0, 1fr); gap: .75rem; flex: 1; min-height: 0; }
form { display: flex; flex-direction: column; gap: .5rem; padding: .65rem; border: 1px solid #cbd5e1; border-radius: 5px; background: #f8fafc; width: 21rem; max-width: 100%; box-sizing: border-box; align-self: start; }
label { display: grid; gap: .15rem; font-size: .78rem; color: #334155; }
form input,
form select {
  box-sizing: border-box;
  width: 100%;
  height: 1.65rem;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font: inherit;
  font-size: 0.78rem;
}
.con-lupa {
  display: flex;
  align-items: center;
  gap: 0.15rem;
  min-width: 0;
}
.con-lupa input {
  flex: 1;
  min-width: 0;
}
.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.65rem;
  height: 1.65rem;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
  color: #334155;
  flex-shrink: 0;
}
.btn-lupa:hover {
  background: #e0f2fe;
  border-color: #38bdf8;
}
main { min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: .5rem; }
.hint { margin: 0; color: #64748b; font-size: .82rem; }
.grid { overflow: auto; flex: 1; border: 1px solid #cbd5e1; border-radius: 4px; min-height: 0; }
table { width: 100%; border-collapse: collapse; font-size: .8rem; }
th, td { padding: .32rem .4rem; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
th { position: sticky; top: 0; background: #f1f5f9; z-index: 1; vertical-align: top; font-weight: 600; }
th.sel,
td.sel {
  width: 2rem;
  text-align: center;
}
th.sel input[type='checkbox'],
td.sel input[type='checkbox'] {
  width: auto;
  height: auto;
  margin: 0;
  padding: 0;
  vertical-align: middle;
}
.th-titulo {
  display: block;
  padding: 0 0.1rem 0.15rem;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
  user-select: none;
}
.th-titulo.num { text-align: right; }
.th-titulo:hover { color: #1d4ed8; }
.marca-orden { font-size: 0.65rem; margin-left: 0.15rem; color: #1d4ed8; }
.filtro-col {
  width: 100%;
  box-sizing: border-box;
  padding: 0.15rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font: inherit;
  font-size: 0.76rem;
  font-weight: 400;
  height: auto;
}
.filtro-col:focus {
  outline: 2px solid #2563eb;
  outline-offset: -1px;
}
.col-venc { width: 7rem; }
.col-factura { width: 6.5rem; }
.col-recibo { width: 5rem; }
.col-cliente { width: 7rem; }
.col-importe { width: 6rem; }
.col-estado { width: 6.5rem; }
tbody tr { cursor: pointer; }
tbody tr:hover { background: #f8fafc; }
tbody tr.checked { background: #eff6ff; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
td.clip { max-width: 16rem; overflow: hidden; text-overflow: ellipsis; }
.error { color: #b91c1c; margin: 0; }
.ok { color: #047857; margin: 0; }
.empty { text-align: center; color: #64748b; padding: 1rem; }
.pie-grid { display: flex; justify-content: flex-end; align-items: center; gap: .75rem; flex-shrink: 0; }
.listadas { font-size: .78rem; color: #64748b; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } form { width: 100%; } }
</style>
