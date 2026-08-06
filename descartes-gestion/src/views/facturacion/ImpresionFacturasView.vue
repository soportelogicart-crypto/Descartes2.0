<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  descargarFacturasPdf,
  listarFacturasImpresion,
  marcarFacturasImpresas,
} from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaImpresionItem } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePdfPreview } from '@/composables/usePdfPreview'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

type Opt = { value: string; label: string }
type CampoCliente = 'desde' | 'hasta'

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loading = ref(false)
const loadingOpts = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<FacturaImpresionItem[]>([])
const selected = ref<Record<string, boolean>>({})
const tiendas = ref<Opt[]>([])
const actividades = ref<Opt[]>([])
const { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf } = usePdfPreview('Impresión de facturas')
const buscarClienteOpen = ref(false)
const buscarClienteCampo = ref<CampoCliente>('desde')
const buscarClienteInicial = ref('')

function hoyIso() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function rowKey(r: FacturaImpresionItem) {
  return `${r.empresa}|${r.facturaTipo}|${r.factura}`
}

const form = ref({
  /** Todas: tras generar, a menudo ya están marcadas si se previsualizó el PDF. */
  estadoImpresion: 'todas',
  /** Crédito/generación = diferidas (Estado G). */
  tipoCobro: 'diferidas',
  facturaTipo: '',
  facturacion: 'normal',
  canalImpresion: 'impresora',
  clientesModo: 'todos',
  /** Solo con botón / checkbox explícito; el PDF no marca por defecto. */
  marcarImpresa: false,
  empresaDesde: '',
  empresaHasta: '',
  fechaDesde: '',
  fechaHasta: hoyIso(),
  clienteDesde: '',
  clienteHasta: '',
  facturaDesde: '' as string | number,
  facturaHasta: '' as string | number,
  actividadDesde: '',
  actividadHasta: '',
})

const seleccionados = computed(() => items.value.filter((r) => selected.value[rowKey(r)]))
const todosMarcados = computed(
  () => items.value.length > 0 && items.value.every((r) => selected.value[rowKey(r)])
)

function toggleTodos(v: boolean) {
  const next: Record<string, boolean> = { ...selected.value }
  for (const r of items.value) next[rowKey(r)] = v
  selected.value = next
}

function abrirBuscarCliente(campo: CampoCliente) {
  buscarClienteCampo.value = campo
  buscarClienteInicial.value =
    campo === 'desde' ? form.value.clienteDesde.trim() : form.value.clienteHasta.trim()
  buscarClienteOpen.value = true
}

function onClienteSeleccionado(sel: EntidadBuscarResultado) {
  buscarClienteOpen.value = false
  if (buscarClienteCampo.value === 'desde') {
    form.value.clienteDesde = sel.codigo
    if (!form.value.clienteHasta.trim()) form.value.clienteHasta = sel.codigo
  } else {
    form.value.clienteHasta = sel.codigo
  }
}

function paramsConsulta(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    estadoImpresion: f.estadoImpresion,
    tipoCobro: f.tipoCobro,
    canalImpresion: f.canalImpresion,
    facturacion: f.facturacion,
  }
  const put = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  put('empresaDesde', f.empresaDesde)
  put('empresaHasta', f.empresaHasta || f.empresaDesde)
  // Compat API antigua
  if (f.empresaDesde) out.empresa = f.empresaDesde
  put('fechaDesde', f.fechaDesde)
  put('fechaHasta', f.fechaHasta)
  put('clienteDesde', f.clienteDesde)
  put('clienteHasta', f.clienteHasta || f.clienteDesde)
  put('actividadDesde', f.actividadDesde)
  put('actividadHasta', f.actividadHasta || f.actividadDesde)
  put('facturaTipo', f.facturaTipo)
  const fd = Number(f.facturaDesde)
  if (Number.isFinite(fd) && fd > 0) out.facturaDesde = fd
  const fh = Number(f.facturaHasta)
  if (Number.isFinite(fh) && fh > 0) out.facturaHasta = fh
  return out
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const [tiendasRes, actRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }),
      api.get('/api/mantenimiento/actividades', { params: { pageSize: 500 } }).catch(() => ({
        data: { items: [] },
      })),
    ])
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
    actividades.value = (actRes.data.items ?? []).map(
      (a: { codigo?: string | number; descripcion?: string }) => {
        const codigo = String(a.codigo ?? '').trim()
        return { value: codigo, label: `${codigo} — ${a.descripcion ?? ''}` }
      }
    )
    const emp = String(puestoContexto.empresaCodigo ?? '').trim()
    if (emp) {
      form.value.empresaDesde = emp
      form.value.empresaHasta = emp
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar opciones')
  } finally {
    loadingOpts.value = false
  }
}

async function buscar() {
  if (!puede('facturacion-impresion', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresaDesde.trim()) {
    error.value = 'Indique la tienda'
    return
  }
  if (
    form.value.clientesModo === 'seleccionados' &&
    !form.value.clienteDesde.trim() &&
    !form.value.clienteHasta.trim()
  ) {
    error.value = 'Con «Clientes seleccionados» indique Cliente Desde/Hasta'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarFacturasImpresion(paramsConsulta())
    items.value = data.items
    selected.value = {}
    const trunc =
      data.items.length >= 500 ? ' (máx. 500; afine filtros si faltan)' : ''
    if (data.items.length === 0 && form.value.estadoImpresion === 'pendientes') {
      mensaje.value =
        '0 facturas pendientes de imprimir. Pruebe Estado = Todas o Impresas (pueden haberse marcado al previsualizar el PDF).'
    } else {
      mensaje.value = `${data.totales.facturas} facturas · ${data.totales.importe.toFixed(2)} €${trunc}`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar facturas')
    items.value = []
  } finally {
    loading.value = false
  }
}

async function imprimirPdf() {
  if (!puede('facturacion-impresion', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  const sel = seleccionados.value
  if (sel.length === 0) {
    error.value = 'Seleccione al menos una factura'
    return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarFacturasPdf({
      facturas: sel.map((r) => ({
        empresa: r.empresa,
        facturaTipo: r.facturaTipo,
        factura: r.factura,
      })),
      marcarImpresa: form.value.marcarImpresa,
    })
    if (blob.type && blob.type.includes('json')) {
      throw new Error('Error al generar PDF')
    }
    abrirPdf(blob, `Facturas (${sel.length})`)
    mensaje.value = `PDF de ${sel.length} factura(s) listo para previsualizar`
    if (form.value.marcarImpresa) await buscar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    saving.value = false
  }
}

async function marcarSolo() {
  if (!puede('facturacion-impresion', 'crear')) {
    error.value = 'Sin permiso'
    return
  }
  const sel = seleccionados.value
  if (sel.length === 0) {
    error.value = 'Seleccione al menos una factura'
    return
  }
  saving.value = true
  error.value = null
  try {
    const r = await marcarFacturasImpresas({
      facturas: sel.map((x) => ({
        empresa: x.empresa,
        facturaTipo: x.facturaTipo,
        factura: x.factura,
      })),
    })
    mensaje.value = `Marcadas ${r.marcadas} factura(s) como impresas`
    await buscar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo marcar')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="imp-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Impresión de facturas</h2>
        <p class="hint">
          Diferidas = crédito (Estado G). Si no salen, revise Estado impresión (Todas / Impresas):
          previsualizar el PDF pudo marcarlas.
        </p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="loading || loadingOpts" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button
          type="button"
          class="btn primary"
          :disabled="saving || loading || seleccionados.length === 0"
          @click="imprimirPdf"
        >
          {{ saving ? 'Generando…' : 'Imprimir' }}
        </button>
        <button
          type="button"
          class="btn"
          :disabled="saving || loading || seleccionados.length === 0"
          title="Marcar Impresa sin PDF"
          @click="marcarSolo"
        >
          Marcar impresa
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel" @submit.prevent="buscar">
          <fieldset class="opciones">
            <legend>Opciones</legend>
            <label>
              <span>Estado</span>
              <select v-model="form.estadoImpresion">
                <option value="pendientes">Pendientes de Imprimir</option>
                <option value="impresas">Impresas</option>
                <option value="todas">Todas</option>
              </select>
            </label>
            <label>
              <span>Tipo cobro</span>
              <select
                v-model="form.tipoCobro"
                title="Diferidas = Estado G (crédito). Contado = Estado F. Contado diferido TPV también es diferida."
              >
                <option value="todas">Todas</option>
                <option value="diferidas">Diferidas (crédito / Est. G)</option>
                <option value="contado">Contado (Est. F)</option>
              </select>
            </label>
            <label>
              <span>Tipo Factura</span>
              <select v-model="form.facturaTipo">
                <option value="">Todas</option>
                <option value="F">Facturas (F)</option>
                <option value="A">Abonos (A)</option>
              </select>
            </label>
            <label>
              <span>Facturación</span>
              <select v-model="form.facturacion">
                <option value="normal">Normal</option>
                <option value="sujeto_pasivo">Sujeto pasivo</option>
              </select>
            </label>
            <label>
              <span>Canal</span>
              <select v-model="form.canalImpresion">
                <option value="impresora">Impresora</option>
                <option value="email">Pendientes email</option>
              </select>
            </label>
            <label>
              <span>Clientes</span>
              <select v-model="form.clientesModo">
                <option value="todos">Todos</option>
                <option value="seleccionados">Clientes seleccionados</option>
              </select>
            </label>
            <label class="check">
              <input v-model="form.marcarImpresa" type="checkbox" />
              <span>Marcar al imprimir</span>
            </label>
          </fieldset>

          <fieldset class="rangos">
            <legend>Intervalos</legend>
            <div class="rango-head">
              <span></span>
              <span>Desde</span>
              <span>Hasta</span>
            </div>
            <div class="rango-row">
              <span class="rango-label">Tienda</span>
              <select v-model="form.empresaDesde" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="t in tiendas" :key="'ed-' + t.value" :value="t.value">{{ t.value }}</option>
              </select>
              <select v-model="form.empresaHasta" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="t in tiendas" :key="'eh-' + t.value" :value="t.value">{{ t.value }}</option>
              </select>
            </div>
            <div class="rango-row">
              <span class="rango-label">Fecha</span>
              <input v-model="form.fechaDesde" type="date" />
              <input v-model="form.fechaHasta" type="date" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Cliente</span>
              <div class="con-lupa">
                <input v-model="form.clienteDesde" type="text" maxlength="12" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar cliente desde"
                  @click="abrirBuscarCliente('desde')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <div class="con-lupa">
                <input v-model="form.clienteHasta" type="text" maxlength="12" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar cliente hasta"
                  @click="abrirBuscarCliente('hasta')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
            </div>
            <div class="rango-row">
              <span class="rango-label">Factura</span>
              <input v-model="form.facturaDesde" type="number" min="0" />
              <input v-model="form.facturaHasta" type="number" min="0" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Actividad</span>
              <select v-model="form.actividadDesde" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="a in actividades" :key="'ad-' + a.value" :value="a.value">{{ a.value }}</option>
              </select>
              <select v-model="form.actividadHasta" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="a in actividades" :key="'ah-' + a.value" :value="a.value">{{ a.value }}</option>
              </select>
            </div>
          </fieldset>

          <div class="resumen">
            <div>
              <span>Sel.</span>
              <strong :class="{ sel: seleccionados.length > 0 }">{{ seleccionados.length }}</strong>
            </div>
            <div>
              <span>Listadas</span>
              <strong>{{ items.length }}</strong>
            </div>
          </div>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>
        <p v-if="!items.length && !loading && !error" class="empty">
          Configure opciones e intervalos y pulse <strong>Buscar</strong>.
        </p>

        <div v-if="items.length" class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="sel">
                  <input
                    type="checkbox"
                    :checked="todosMarcados"
                    @change="toggleTodos(($event.target as HTMLInputElement).checked)"
                  />
                </th>
                <th>Fecha</th>
                <th>Tie.</th>
                <th>Tipo</th>
                <th>Factura</th>
                <th>Cliente</th>
                <th>Razón social</th>
                <th>NIF</th>
                <th class="num">Importe</th>
                <th>Est.</th>
                <th>Cobro</th>
                <th>Imp.</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in items"
                :key="rowKey(r)"
                :class="{ checked: selected[rowKey(r)] }"
                @click="selected[rowKey(r)] = !selected[rowKey(r)]"
              >
                <td class="sel" @click.stop>
                  <input v-model="selected[rowKey(r)]" type="checkbox" />
                </td>
                <td>{{ r.fecha }}</td>
                <td>{{ r.empresa }}</td>
                <td>{{ r.facturaTipo }}</td>
                <td>{{ r.factura }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td :title="r.facturaContadoDiferida ? 'Contado diferido TPV' : ''">{{ r.estado || '—' }}</td>
                <td>{{ r.tipoCobro === 'diferida' ? 'Diferida' : 'Contado' }}</td>
                <td>{{ r.impresa ? 'Sí' : 'No' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarClienteOpen"
      entidad="clientes"
      :titulo="buscarClienteCampo === 'desde' ? 'Buscar cliente desde' : 'Buscar cliente hasta'"
      :busqueda-inicial="buscarClienteInicial"
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
.imp-page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
  height: 100%;
  box-sizing: border-box;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  flex-shrink: 0;
}
.toolbar-title h2 {
  margin: 0;
  font-size: 1.15rem;
}
.hint {
  margin: 0.2rem 0 0;
  color: #64748b;
  font-size: 0.82rem;
}
.toolbar-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.btn {
  border: 1px solid #94a3b8;
  background: #fff;
  border-radius: 4px;
  padding: 0.4rem 0.85rem;
  font: inherit;
  font-size: 0.875rem;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn.primary {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}
.layout {
  display: grid;
  grid-template-columns: 16.5rem minmax(0, 1fr);
  gap: 0.85rem;
  align-items: stretch;
  min-height: 0;
  flex: 1;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.sidebar {
  width: 16.5rem;
  max-width: 100%;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.65rem;
  background: #f8fafc;
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  overflow: auto;
  min-height: 0;
}
fieldset {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  margin: 0;
  padding: 0.55rem 0.55rem 0.65rem;
  background: #fff;
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  overflow: hidden;
}
legend {
  padding: 0 0.3rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}
.opciones label:not(.check) {
  display: grid;
  grid-template-columns: 5rem minmax(0, 1fr);
  align-items: center;
  gap: 0.3rem;
  font-size: 0.75rem;
  color: #334155;
  min-width: 0;
}
.opciones label.check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.75rem;
  color: #334155;
}
.opciones select,
.rangos input,
.rangos select {
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  height: 1.65rem;
  font: inherit;
  font-size: 0.78rem;
  background: #fff;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  box-sizing: border-box;
}
.rango-head,
.rango-row {
  display: grid;
  grid-template-columns: 3.6rem minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.25rem;
  align-items: center;
  min-width: 0;
}
.rango-head {
  font-size: 0.68rem;
  color: #64748b;
  text-align: center;
}
.rango-label {
  font-size: 0.75rem;
  color: #334155;
  white-space: nowrap;
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
.resumen {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.4rem;
  padding: 0.45rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  font-size: 0.78rem;
  box-sizing: border-box;
  margin-top: auto;
}
.resumen strong {
  display: block;
  color: #94a3b8;
  font-size: 1rem;
}
.resumen strong.sel {
  color: #b91c1c;
}
.resultado {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 0.65rem;
  background: #fff;
  box-sizing: border-box;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.ok {
  color: #047857;
  margin: 0;
}
.empty {
  color: #64748b;
  margin: 1rem 0;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  min-height: 0;
  flex: 1;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  padding: 0.3rem 0.4rem;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
  white-space: nowrap;
}
th {
  background: #f1f5f9;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
}
th.sel,
td.sel {
  width: 2rem;
  text-align: center;
}
td.num,
th.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
td.clip {
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
tbody tr {
  cursor: pointer;
}
tbody tr:hover {
  background: #f8fafc;
}
tbody tr.checked {
  background: #eff6ff;
}
</style>
