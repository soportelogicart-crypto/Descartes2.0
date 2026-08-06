<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { descargarDiarioPdf, listarDiarioFacturacion } from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaDiarioItem } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import { usePdfPreview } from '@/composables/usePdfPreview'

type Opt = { value: string; label: string }
type CampoCliente = 'desde' | 'hasta'

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loading = ref(false)
const loadingOpts = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<FacturaDiarioItem[]>([])
const tiendas = ref<Opt[]>([])
const buscarClienteOpen = ref(false)
const buscarClienteCampo = ref<CampoCliente>('desde')
const buscarClienteInicial = ref('')
const { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf } = usePdfPreview('Diario de facturación')

function hoyIso() {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

const form = ref({
  empresaDesde: '',
  empresaHasta: '',
  fechaDesde: '',
  fechaHasta: hoyIso(),
  clienteDesde: '',
  clienteHasta: '',
  facturaDesde: '' as string | number,
  facturaHasta: '' as string | number,
  facturaTipo: '',
  estado: '',
})

function paramsConsulta(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    estadoImpresion: 'todas',
    tipoCobro: 'todas',
  }
  const put = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  put('empresaDesde', f.empresaDesde)
  put('empresaHasta', f.empresaHasta || f.empresaDesde)
  if (f.empresaDesde) out.empresa = f.empresaDesde
  put('fechaDesde', f.fechaDesde)
  put('fechaHasta', f.fechaHasta)
  put('clienteDesde', f.clienteDesde)
  put('clienteHasta', f.clienteHasta || f.clienteDesde)
  put('facturaTipo', f.facturaTipo)
  put('estado', f.estado)
  const fd = Number(f.facturaDesde)
  if (Number.isFinite(fd) && fd > 0) out.facturaDesde = fd
  const fh = Number(f.facturaHasta)
  if (Number.isFinite(fh) && fh > 0) out.facturaHasta = fh
  return out
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

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const tiendasRes = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
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
  if (!puede('facturacion-diario', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresaDesde.trim()) {
    error.value = 'Indique la tienda'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarDiarioFacturacion(paramsConsulta())
    items.value = data.items
    const trunc =
      data.items.length >= 500 ? ' (máx. 500; afine filtros si faltan)' : ''
    mensaje.value = `${data.totales.facturas} facturas · ${data.totales.importe.toFixed(2)} €${trunc}`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el diario')
    items.value = []
  } finally {
    loading.value = false
  }
}

async function pdf() {
  if (!puede('facturacion-diario', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresaDesde.trim()) {
    error.value = 'Indique la tienda'
    return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarDiarioPdf({
      ...paramsConsulta(),
    })
    if (blob.type && blob.type.includes('json')) {
      throw new Error('Error al generar PDF')
    }
    abrirPdf(blob, 'Diario de facturación')
    mensaje.value =
      items.value.length >= 500
        ? 'PDF del diario (máx. 500 filas) listo'
        : 'PDF del diario listo para previsualizar'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div>
        <h2>Diario de facturación</h2>
        <p class="hint">Listado de facturas emitidas (auditoría). El PDF es el diario tabular, no reimpresión.</p>
      </div>
      <div class="actions">
        <button type="button" class="btn" :disabled="loading || loadingOpts" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button type="button" class="btn primary" :disabled="saving || loadingOpts" @click="pdf">
          {{ saving ? 'PDF…' : 'Previsualizar PDF' }}
        </button>
      </div>
    </div>

    <div class="layout">
      <form class="panel" @submit.prevent="buscar">
        <fieldset class="intervalos">
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
              <option v-for="t in tiendas" :key="`d-${t.value}`" :value="t.value">{{ t.value }}</option>
            </select>
            <select v-model="form.empresaHasta" :disabled="loadingOpts">
              <option value="">—</option>
              <option v-for="t in tiendas" :key="`h-${t.value}`" :value="t.value">{{ t.value }}</option>
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
              <input v-model="form.clienteDesde" maxlength="12" />
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
              <input v-model="form.clienteHasta" maxlength="12" />
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
        </fieldset>

        <fieldset class="opciones">
          <legend>Opciones</legend>
          <label>
            <span>Tipo</span>
            <select v-model="form.facturaTipo">
              <option value="">Todas</option>
              <option value="F">Facturas (F)</option>
              <option value="A">Abonos (A)</option>
            </select>
          </label>
          <label>
            <span>Estado</span>
            <select v-model="form.estado">
              <option value="">Todos</option>
              <option value="G">G — Diferida (crédito)</option>
              <option value="F">F — Contado</option>
            </select>
          </label>
        </fieldset>
      </form>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <div v-if="items.length" class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tie.</th>
                <th>Tipo</th>
                <th>Factura</th>
                <th>Cliente</th>
                <th>Razón social</th>
                <th>NIF</th>
                <th class="num">Importe</th>
                <th>Cobro</th>
                <th>F.P.</th>
                <th>Imp.</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in items" :key="`${r.empresa}|${r.facturaTipo}|${r.factura}`">
                <td>{{ r.fecha }}</td>
                <td>{{ r.empresa }}</td>
                <td>{{ r.facturaTipo }}</td>
                <td>{{ r.factura }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td>{{ r.tipoCobro === 'diferida' ? 'Dif.' : 'Con.' }}</td>
                <td>{{ r.fpago || '—' }}</td>
                <td>{{ r.impresa ? 'Sí' : '' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!loading" class="empty">Configure filtros y pulse Buscar.</p>
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
.page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  height: 100%;
  min-height: 0;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: flex-start;
}
.toolbar h2 {
  margin: 0;
}
.hint {
  margin: 0.15rem 0 0;
  color: #64748b;
  font-size: 0.85rem;
}
.actions {
  display: flex;
  gap: 0.35rem;
}
.btn {
  padding: 0.4rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}
.btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.layout {
  display: grid;
  grid-template-columns: minmax(16rem, 20rem) 1fr;
  gap: 0.75rem;
  min-height: 0;
  flex: 1;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding: 0.65rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  align-self: start;
}
.panel fieldset {
  margin: 0;
  padding: 0.45rem 0.5rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
}
.panel legend {
  padding: 0 0.3rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}
.rango-head,
.rango-row {
  display: grid;
  grid-template-columns: 4.2rem 1fr 1fr;
  gap: 0.3rem;
  align-items: center;
}
.rango-head {
  margin-bottom: 0.2rem;
  font-size: 0.72rem;
  color: #64748b;
  text-align: center;
}
.rango-head span:first-child {
  text-align: left;
}
.rango-label {
  font-size: 0.75rem;
  color: #475569;
}
.rango-row input,
.rango-row select,
.opciones select {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.3rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  font-size: 0.8rem;
}
.con-lupa {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  min-width: 0;
}
.con-lupa input {
  flex: 1;
}
.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.7rem;
  height: 1.7rem;
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
.opciones {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}
.opciones label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}
.resultado {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  flex: 1;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
th,
td {
  border: 1px solid #e2e8f0;
  padding: 0.25rem 0.35rem;
}
th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.clip {
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #047857;
}
.empty {
  color: #64748b;
  font-size: 0.9rem;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
</style>
