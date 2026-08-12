<script setup lang="ts">
import { onMounted, ref } from 'vue'
import {
  descargarDesgloseArqueoPdf,
  imprimirTermicaDispositivo,
  obtenerDesgloseArqueoTextoTermico,
  obtenerDesgloseArqueoVentas,
} from '@/api/ventas'
import type { DesgloseArqueoVentasResponse } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePdfPreview } from '@/composables/usePdfPreview'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { api } from '@/api/client'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

type Opt = { value: string; label: string }

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loading = ref(false)
const loadingOpts = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const data = ref<DesgloseArqueoVentasResponse | null>(null)
const tiendas = ref<Opt[]>([])
const puestos = ref<Opt[]>([])
const { pdfOpen, pdfUrl, pdfTitulo, cerrarPdf, abrirPdf } = usePdfPreview('Desglose de arqueo')

function hoyIso() {
  return new Date().toISOString().slice(0, 10)
}

const form = ref({
  idioma: 'castellano',
  divisa: 'EU',
  formato: 'desglosado',
  subtotal: 'F',
  reservas: 'excluidas',
  albaranes: 'todos',
  empresaDesde: '',
  empresaHasta: '',
  puestoDesde: '',
  puestoHasta: '',
  sesionDesde: '' as string | number,
  sesionHasta: '' as string | number,
  fechaDesde: hoyIso(),
  fechaHasta: hoyIso(),
  vendedorDesde: '',
  vendedorHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  albaranDesde: '' as string | number,
  albaranHasta: '' as string | number,
  fpagoDesde: '',
  fpagoHasta: '',
})

function paramsConsulta(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    formato: f.formato,
    subtotal: f.subtotal,
    reservas: f.reservas,
    albaranes: f.albaranes,
  }
  const putStr = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  const putNum = (k: string, v: string | number) => {
    const n = Number(v)
    if (Number.isFinite(n) && n > 0) out[k] = n
  }
  putStr('empresaDesde', f.empresaDesde)
  putStr('empresaHasta', f.empresaHasta || f.empresaDesde)
  putStr('puestoDesde', f.puestoDesde)
  putStr('puestoHasta', f.puestoHasta || f.puestoDesde)
  putNum('sesionDesde', f.sesionDesde)
  putNum('sesionHasta', f.sesionHasta || f.sesionDesde)
  putStr('fechaDesde', f.fechaDesde)
  putStr('fechaHasta', f.fechaHasta)
  putStr('vendedorDesde', f.vendedorDesde)
  putStr('vendedorHasta', f.vendedorHasta || f.vendedorDesde)
  putStr('clienteDesde', f.clienteDesde)
  putStr('clienteHasta', f.clienteHasta || f.clienteDesde)
  putNum('albaranDesde', f.albaranDesde)
  putNum('albaranHasta', f.albaranHasta || f.albaranDesde)
  putStr('fpagoDesde', f.fpagoDesde)
  putStr('fpagoHasta', f.fpagoHasta || f.fpagoDesde)
  return out
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const [tiendasRes, puestosRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }),
      api.get('/api/mantenimiento/puestos-trabajo', { params: { pageSize: 500 } }),
    ])
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
    puestos.value = (puestosRes.data.items ?? [])
      .map((p: { codigo?: string; descripcion?: string }) => {
        const codigo = String(p.codigo ?? '').trim()
        return { value: codigo, label: `${codigo} — ${p.descripcion ?? ''}` }
      })
      .sort((a: Opt, b: Opt) => a.value.localeCompare(b.value, undefined, { numeric: true }))

    const puestoPc = String(puestoContexto.puestoCodigo ?? '').trim()
    if (puestoPc) {
      form.value.puestoDesde = puestoPc
      form.value.puestoHasta = puestoPc
    }
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

async function consultar() {
  if (!puede('ventas-arqueo-desglose', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    data.value = await obtenerDesgloseArqueoVentas(paramsConsulta())
    mensaje.value = `${data.value.totales.documentos} documentos`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el desglose')
    data.value = null
  } finally {
    loading.value = false
  }
}

function descargarBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
}

async function exportarPdf() {
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarDesgloseArqueoPdf(paramsConsulta())
    if (blob.type && blob.type.includes('json')) {
      throw new Error('Error al generar PDF')
    }
    abrirPdf(blob, 'Desglose de arqueo')
    mensaje.value = 'PDF listo para previsualizar'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    saving.value = false
  }
}

function exportarExcel() {
  if (!data.value) return
  const esc = (v: string | number) => {
    const s = String(v ?? '')
    if (/[;"\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`
    return s
  }
  const num = (n: number) => (Math.round(n * 100) / 100).toFixed(2).replace('.', ',')
  const lines: string[] = [
    ['Grupo', 'Albaran', 'Fecha', 'Tipo', 'Puesto', 'Sesion', 'FP1', 'Contado', 'Credito', 'Total']
      .map(esc)
      .join(';'),
  ]
  for (const g of data.value.grupos) {
    if (data.value.formato === 'desglosado') {
      for (const l of g.lineas) {
        lines.push(
          [
            g.etiqueta,
            l.albaran,
            l.fechaCorta,
            l.docTipo,
            l.puesto,
            l.sesion,
            l.fpago1 || '',
            num(l.importeContado),
            num(l.importeCredito),
            num(l.importe),
          ]
            .map(esc)
            .join(';')
        )
      }
    }
    lines.push(
      [g.etiqueta, '', '', 'SUBTOTAL', '', '', '', num(g.contado), num(g.credito), num(g.total)]
        .map(esc)
        .join(';')
    )
  }
  const t = data.value.totales
  lines.push(['TOTAL', '', '', '', '', '', '', num(t.contado), num(t.credito), num(t.total)].map(esc).join(';'))
  descargarBlob(
    new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }),
    'desglose_arqueo.csv'
  )
  mensaje.value = 'Excel (CSV) descargado'
}

async function imprimirTermica() {
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const { texto } = await obtenerDesgloseArqueoTextoTermico(paramsConsulta())
    const puesto =
      String(form.value.puestoDesde || puestoContexto.puestoCodigo || '').trim() || '00'
    const res = await imprimirTermicaDispositivo(puesto, {
      texto,
      tipo: 'desglose-arqueo',
    })
    if (!res.agenteOnline) {
      error.value = res.message || 'Agente local no disponible (Descartes Electron)'
      return
    }
    mensaje.value = res.stub
      ? `Térmica (stub / Registradora): ${res.message || ''}`
      : res.message || 'Enviado a térmica'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir en térmica')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="desglose-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Desglose de arqueo</h2>
        <p class="hint">
          Misma estructura que legacy. <strong>Imprimir térmica</strong> = Registradora.
        </p>
      </div>
      <div class="toolbar-actions">
        <button
          type="button"
          class="btn primary"
          :disabled="loading || loadingOpts"
          @click="consultar"
        >
          {{ loading ? 'Consultando…' : 'Consultar' }}
        </button>
        <button
          type="button"
          class="btn"
          :disabled="saving || loading || !data?.totales.documentos"
          title="Desglose de Arqueo Registradora"
          @click="imprimirTermica"
        >
          Imprimir
        </button>
        <button
          type="button"
          class="btn"
          :disabled="saving || loading || !data"
          @click="exportarPdf"
        >
          PDF
        </button>
        <button
          type="button"
          class="btn"
          :disabled="loading || !data?.totales.documentos"
          @click="exportarExcel"
        >
          Excel
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel" @submit.prevent="consultar">
          <fieldset class="opciones">
            <legend>Opciones</legend>
            <label>
              Idioma
              <select v-model="form.idioma">
                <option value="castellano">Castellano</option>
              </select>
            </label>
            <label>
              Divisa
              <select v-model="form.divisa">
                <option value="EU">EU</option>
              </select>
            </label>
            <label>
              Formato
              <select v-model="form.formato">
                <option value="desglosado">Desglosado</option>
                <option value="resumido">Resumido</option>
              </select>
            </label>
            <label>
              Subtotal
              <select v-model="form.subtotal">
                <option value="F">Fecha</option>
                <option value="P">Puesto</option>
                <option value="S">Sesión</option>
                <option value="V">Vendedor</option>
              </select>
            </label>
            <label>
              Reservas
              <select v-model="form.reservas">
                <option value="excluidas">Reservas excluidas</option>
                <option value="incluidas">Reservas incluidas</option>
              </select>
            </label>
            <label>
              Albaranes
              <select v-model="form.albaranes">
                <option value="todos">Todos</option>
                <option value="excluir_facturados">Excluir facturados</option>
                <option value="solo_tickets_facturas">Tickets + facturas</option>
                <option value="facturas_contado">Facturas contado</option>
              </select>
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
              <span class="rango-label">Puesto</span>
              <select v-model="form.puestoDesde" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="p in puestos" :key="'pd-' + p.value" :value="p.value">{{ p.value }}</option>
              </select>
              <select v-model="form.puestoHasta" :disabled="loadingOpts">
                <option value="">—</option>
                <option v-for="p in puestos" :key="'ph-' + p.value" :value="p.value">{{ p.value }}</option>
              </select>
            </div>
            <div class="rango-row">
              <span class="rango-label">Sesión</span>
              <DecimalInput
                :model-value="form.sesionDesde === '' || form.sesionDesde == null ? null : Number(form.sesionDesde)"
                :empty-as-null="true"
                :integer="true"
                @update:model-value="form.sesionDesde = $event ?? ''"
              />
              <DecimalInput
                :model-value="form.sesionHasta === '' || form.sesionHasta == null ? null : Number(form.sesionHasta)"
                :empty-as-null="true"
                :integer="true"
                @update:model-value="form.sesionHasta = $event ?? ''"
              />
            </div>
            <div class="rango-row">
              <span class="rango-label">Fecha</span>
              <input v-model="form.fechaDesde" type="date" />
              <input v-model="form.fechaHasta" type="date" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Vendedor</span>
              <input v-model="form.vendedorDesde" type="text" />
              <input v-model="form.vendedorHasta" type="text" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Cliente</span>
              <input v-model="form.clienteDesde" type="text" />
              <input v-model="form.clienteHasta" type="text" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Albarán</span>
              <DecimalInput
                :model-value="form.albaranDesde === '' || form.albaranDesde == null ? null : Number(form.albaranDesde)"
                :empty-as-null="true"
                :integer="true"
                @update:model-value="form.albaranDesde = $event ?? ''"
              />
              <DecimalInput
                :model-value="form.albaranHasta === '' || form.albaranHasta == null ? null : Number(form.albaranHasta)"
                :empty-as-null="true"
                :integer="true"
                @update:model-value="form.albaranHasta = $event ?? ''"
              />
            </div>
            <div class="rango-row">
              <span class="rango-label">F.Pago</span>
              <input v-model="form.fpagoDesde" type="text" maxlength="4" />
              <input v-model="form.fpagoHasta" type="text" maxlength="4" />
            </div>
          </fieldset>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Generando informe…</p>
        <p v-if="!data && !loading && !error" class="empty">
          Configure opciones e intervalos a la izquierda y pulse <strong>Consultar</strong>.
        </p>

        <template v-if="data">
          <header class="informe-cab">
            <div>
              <strong>DESGLOSE DE ARQUEO</strong>
              <span>{{ form.divisa }} · {{ data.formato }} · Subtotal {{ data.subtotal }}</span>
            </div>
            <div>
              Docs {{ data.totales.documentos }} · Contado {{ data.totales.contado.toFixed(2) }} ·
              Crédito {{ data.totales.credito.toFixed(2) }} · Total
              {{ data.totales.total.toFixed(2) }}
            </div>
            <div class="agrup">
              Efectivo {{ data.porAgrupacion.efectivo.toFixed(2) }} · Cheques
              {{ data.porAgrupacion.cheques.toFixed(2) }} · Tarjetas
              {{ data.porAgrupacion.tarjetas.toFixed(2) }} · Crédito
              {{ data.porAgrupacion.credito.toFixed(2) }} · Vales
              {{ data.porAgrupacion.vales.toFixed(2) }} · Otros
              {{ data.porAgrupacion.otros.toFixed(2) }}
            </div>
          </header>

          <div v-for="g in data.grupos" :key="g.clave" class="grupo">
            <h3>{{ g.etiqueta }}</h3>
            <div v-if="data.formato === 'desglosado' && g.lineas.length" class="grid-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Albarán</th>
                    <th>Fecha</th>
                    <th>Tipo</th>
                    <th>Puesto</th>
                    <th>Sesión</th>
                    <th>FP</th>
                    <th class="num">Contado</th>
                    <th class="num">Crédito</th>
                    <th class="num">Total</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in g.lineas" :key="l.empresa + l.tipo + l.albaran">
                    <td>{{ l.albaran }}</td>
                    <td>{{ l.fechaCorta }}</td>
                    <td>{{ l.docTipo }}</td>
                    <td>{{ l.puesto }}</td>
                    <td>{{ l.sesion }}</td>
                    <td>{{ l.fpago1 || '—' }}</td>
                    <td class="num">{{ l.importeContado.toFixed(2) }}</td>
                    <td class="num">{{ l.importeCredito.toFixed(2) }}</td>
                    <td class="num">{{ l.importe.toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p class="subtot">
              Subtotal — Contado {{ g.contado.toFixed(2) }} · Crédito {{ g.credito.toFixed(2) }} ·
              Total {{ g.total.toFixed(2) }}
            </p>
          </div>
        </template>
      </div>
    </div>
  </section>

  <PdfPreviewModal
    :open="pdfOpen"
    :url="pdfUrl"
    :titulo="pdfTitulo"
    @cerrar="cerrarPdf"
  />
</template>

<style scoped>
.desglose-page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}
.toolbar h2 {
  margin: 0;
  font-size: 1.15rem;
}
.hint {
  color: #64748b;
  font-size: 0.82rem;
  margin: 0.25rem 0 0;
}
.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}
.btn {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font: inherit;
}
.btn.primary {
  background: #0f172a;
  border-color: #0f172a;
  color: #fff;
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.layout {
  display: grid;
  grid-template-columns: 16.5rem minmax(0, 1fr);
  gap: 0.85rem;
  align-items: start;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
}

.sidebar {
  width: 16.5rem;
  max-width: 100%;
}
.sidebar .panel {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.65rem;
  background: #f8fafc;
  width: 100%;
  box-sizing: border-box;
}
fieldset {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  margin: 0;
  padding: 0.55rem 0.55rem 0.65rem;
  background: #fff;
  width: 100%;
  box-sizing: border-box;
}
legend {
  padding: 0 0.3rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}

.opciones {
  display: grid;
  gap: 0.4rem;
}
.opciones label {
  display: grid;
  grid-template-columns: 4.8rem minmax(0, 1fr);
  align-items: center;
  gap: 0.3rem;
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
  box-sizing: border-box;
}

.rangos {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.rango-head,
.rango-row {
  display: grid;
  grid-template-columns: 4rem minmax(0, 1fr) minmax(0, 1fr);
  gap: 0.25rem;
  align-items: center;
}
.rango-head {
  font-size: 0.68rem;
  color: #64748b;
  text-align: center;
  padding: 0 0 0.05rem;
}
.rango-head span:first-child {
  text-align: left;
}
.rango-label {
  font-size: 0.75rem;
  color: #334155;
  white-space: nowrap;
}
.rangos input[type='date'],
.rangos input[type='text'],
.rangos select {
  min-width: 0;
}
.rangos input[type='date'] {
  font-size: 0.68rem;
  padding-inline: 0.15rem;
}
.opciones,
.rangos {
  overflow: hidden;
}

.resultado {
  min-width: 0;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 0.75rem;
  background: #fff;
  min-height: 18rem;
}
.empty {
  color: #64748b;
  font-size: 0.9rem;
  margin: 2rem 0;
  text-align: center;
}
.informe-cab {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-bottom: 0.85rem;
  padding-bottom: 0.65rem;
  border-bottom: 1px solid #e2e8f0;
  font-size: 0.85rem;
}
.informe-cab strong {
  margin-right: 0.5rem;
}
.agrup {
  font-size: 0.78rem;
  color: #475569;
}
.grupo {
  margin-bottom: 1rem;
}
.grupo h3 {
  margin: 0 0 0.35rem;
  font-size: 0.92rem;
  color: #0f172a;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.28rem 0.4rem;
}
th {
  background: #f1f5f9;
  text-align: left;
}
.num {
  text-align: right;
}
.subtot {
  font-size: 0.82rem;
  font-weight: 600;
  margin: 0.35rem 0 0;
  color: #1e293b;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #166534;
}
</style>
