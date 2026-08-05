<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  descargarAlbaranesPendientesPdf,
  listarAlbaranesPendientesFacturar,
} from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaManualPendiente } from '@/types/facturacion'
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
const items = ref<FacturaManualPendiente[]>([])
const tiendas = ref<Opt[]>([])
const buscarClienteOpen = ref(false)
const buscarClienteCampo = ref<CampoCliente>('desde')
const buscarClienteInicial = ref('')
const { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf } = usePdfPreview(
  'Albaranes pendientes de facturar'
)

const form = ref({
  empresaDesde: '',
  empresaHasta: '',
  fechaDesde: '',
  fechaHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  albaranDesde: '' as string | number,
  albaranHasta: '' as string | number,
  seleccion: 'todos' as 'todos' | 'con_prefactura' | 'sin_prefactura',
})

function paramsConsulta(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    seleccion: f.seleccion,
  }
  const put = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  put('empresaDesde', f.empresaDesde)
  put('empresaHasta', f.empresaHasta || f.empresaDesde)
  put('fechaDesde', f.fechaDesde)
  put('fechaHasta', f.fechaHasta)
  put('clienteDesde', f.clienteDesde)
  put('clienteHasta', f.clienteHasta || f.clienteDesde)
  const ad = Number(f.albaranDesde)
  if (Number.isFinite(ad) && ad > 0) out.albaranDesde = ad
  const ah = Number(f.albaranHasta)
  if (Number.isFinite(ah) && ah > 0) out.albaranHasta = ah
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
  if (!puede('facturacion-albaranes-pendientes', 'ver')) {
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
    const data = await listarAlbaranesPendientesFacturar(paramsConsulta())
    items.value = data.items
    mensaje.value = `${data.totales.albaranes} albaranes · ${data.totales.importe.toFixed(2)} €`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar albaranes pendientes')
    items.value = []
  } finally {
    loading.value = false
  }
}

async function pdf() {
  if (!puede('facturacion-albaranes-pendientes', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresaDesde.trim()) {
    error.value = 'Indique la tienda'
    return
  }
  if (!items.value.length) {
    error.value = 'Busque primero'
    return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarAlbaranesPendientesPdf(paramsConsulta())
    const head = await blob.slice(0, 5).text()
    if (!head.startsWith('%PDF')) {
      let msg = 'Error al generar PDF'
      try {
        const j = JSON.parse(await blob.text()) as { error?: string }
        if (j.error) msg = j.error
      } catch {
        /* ignore */
      }
      throw new Error(msg)
    }
    abrirPdf(blob, 'Albaranes pendientes de facturar')
    mensaje.value = 'PDF listo para previsualizar'
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
        <h2>Albaranes pendientes de facturar</h2>
        <p class="hint">Informe de crédito pendiente (solo consulta).</p>
      </div>
      <div class="actions">
        <button type="button" class="btn primary" :disabled="loading || loadingOpts" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button type="button" class="btn" :disabled="saving || !items.length" @click="pdf">
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
            <span class="rango-label">Albarán</span>
            <input v-model="form.albaranDesde" type="number" min="0" />
            <input v-model="form.albaranHasta" type="number" min="0" />
          </div>
        </fieldset>

        <fieldset class="opciones">
          <legend>Opciones</legend>
          <label>
            <span>Prefactura</span>
            <select v-model="form.seleccion">
              <option value="todos">Todos</option>
              <option value="con_prefactura">Con Prefactura</option>
              <option value="sin_prefactura">Sin Prefactura</option>
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
                <th>Albarán</th>
                <th>Pto</th>
                <th>Cliente</th>
                <th>Razón social</th>
                <th>NIF</th>
                <th class="num">Importe</th>
                <th>Pref.</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in items" :key="`${r.empresa}|${r.tipo}|${r.albaran}`">
                <td>{{ r.fecha }}</td>
                <td>{{ r.empresa }}</td>
                <td>{{ r.albaran }}</td>
                <td>{{ r.puesto }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td>{{ r.prefactura ? 'Sí' : '' }}</td>
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
}
.actions {
  display: flex;
  gap: 0.35rem;
  flex-wrap: wrap;
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
