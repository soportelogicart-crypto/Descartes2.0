<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  descargarInformeIvaPdf,
  generarInformeIva,
  type InformeIvaFila,
  type InformeIvaParams,
  type InformeIvaResult,
  type InformeIvaResumenFila,
} from '@/api/listados'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import {
  INFORME_IVA_ESTADOS,
  INFORME_IVA_FORMATOS,
  type InformeIvaEstado,
  type InformeIvaFormato,
} from '@/config/informe-iva-opciones'
import { abrirVentanaPreview, escribirVentanaPdf } from '@/composables/previewDocumentoVentana'
import { extractApiError } from '@/composables/useMantenimiento'
import { inicioMesIso, hoyIso } from '@/composables/useAtajosFecha'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

type CampoEntidad = 'clienteDesde' | 'clienteHasta' | 'puestoDesde' | 'puestoHasta'
type EntidadModal = 'clientes' | 'puestos-trabajo'
type Opt = { value: string; label: string }

function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

const auth = useAuthStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const generandoPdf = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<InformeIvaResult | null>(null)
const tiendas = ref<Opt[]>([])

const form = ref({
  divisa: 'EU',
  estado: 'todos' as InformeIvaEstado,
  formato: 'normal' as InformeIvaFormato,
  fechaDesde: inicioMesIso(),
  fechaHasta: hoyIso(),
  empresaDesde: '',
  empresaHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  origenDesde: '',
  origenHasta: '',
  cierreSesionDesde: '' as string | number,
  cierreSesionHasta: '' as string | number,
  puestoDesde: '',
  puestoHasta: '',
  sesionDesde: '' as string | number,
  sesionHasta: '' as string | number,
  facturaDesde: '' as string | number,
  facturaHasta: '' as string | number,
})

/** Legacy: al indicar cliente «desde», «hasta» toma el mismo código. */
watch(
  () => form.value.clienteDesde,
  (codigo) => {
    form.value.clienteHasta = codigo
  },
)

const buscarOpen = ref(false)
const buscarEntidad = ref<EntidadModal>('clientes')
const buscarCampo = ref<CampoEntidad>('clienteDesde')
const buscarInicial = ref('')

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)
const tiposIva = computed((): InformeIvaResumenFila[] => resultado.value?.resumenPorIva ?? [])

function pjeKey(p: number): string {
  return p.toFixed(2)
}

function etiquetaPje(p: number): string {
  return `${numCsv(p, 2).replace(/,00$/, '')}%`
}

function buscarDesglose(row: InformeIvaFila, pje: number) {
  return row.desgloseIva?.find((d) => pjeKey(d.pjeIva) === pjeKey(pje))
}

function celdaDesglose(row: InformeIvaFila, pje: number, campo: 'baseImponible' | 'cuotaIva'): string {
  const d = buscarDesglose(row, pje)
  if (!d || d[campo] === 0) return ''
  return numCsv(d[campo])
}

function etiquetaDocumento(row: { numeroTicket: number | null; facturaTipo?: string }): string {
  const n = row.numeroTicket
  if (n != null && n > 0) {
    const t = (row.facturaTipo ?? 'F').trim().toUpperCase() || 'F'
    return `${t}-${n}`
  }
  return '—'
}

function parseIntOpt(v: string | number): number | undefined {
  if (v === '' || v == null) return undefined
  const n = typeof v === 'number' ? v : Number.parseInt(String(v).trim(), 10)
  return Number.isFinite(n) && n > 0 ? n : undefined
}

function paramsConsulta(): InformeIvaParams {
  return {
    fechaDesde: form.value.fechaDesde,
    fechaHasta: form.value.fechaHasta,
    estado: form.value.estado,
    formato: form.value.formato,
    empresaDesde: form.value.empresaDesde.trim() || undefined,
    empresaHasta: form.value.empresaHasta.trim() || undefined,
    clienteDesde: form.value.clienteDesde.trim() || undefined,
    clienteHasta: form.value.clienteHasta.trim() || undefined,
    origenDesde: form.value.origenDesde.trim() || undefined,
    origenHasta: form.value.origenHasta.trim() || undefined,
    cierreSesionDesde: parseIntOpt(form.value.cierreSesionDesde),
    cierreSesionHasta: parseIntOpt(form.value.cierreSesionHasta),
    puestoDesde: form.value.puestoDesde.trim() || undefined,
    puestoHasta: form.value.puestoHasta.trim() || undefined,
    sesionDesde: parseIntOpt(form.value.sesionDesde),
    sesionHasta: parseIntOpt(form.value.sesionHasta),
    facturaDesde: parseIntOpt(form.value.facturaDesde),
    facturaHasta: parseIntOpt(form.value.facturaHasta),
  }
}

function metaImpresion(): string[] {
  const est = INFORME_IVA_ESTADOS.find((e) => e.value === form.value.estado)?.label ?? 'Todos'
  const fmt = INFORME_IVA_FORMATOS.find((f) => f.value === form.value.formato)?.label ?? 'Normal'
  const lines = [
    `Periodo: ${form.value.fechaDesde} — ${form.value.fechaHasta}`,
    `Divisa: EU · Estado: ${est} · Formato: ${fmt}`,
  ]
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

async function cargarTiendas() {
  loadingOpts.value = true
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre?: string }) => ({
      value: normalizarEmpresaCodigo(String(t.codigo ?? '')),
      label: `${t.codigo}${t.nombre ? ` — ${t.nombre}` : ''}`,
    }))
  } catch {
    tiendas.value = []
  } finally {
    loadingOpts.value = false
  }
}

function abrirBuscar(campo: CampoEntidad) {
  buscarCampo.value = campo
  if (campo.startsWith('cliente')) {
    buscarEntidad.value = 'clientes'
    buscarInicial.value = campo === 'clienteDesde' ? form.value.clienteDesde : form.value.clienteHasta
  } else {
    buscarEntidad.value = 'puestos-trabajo'
    buscarInicial.value = campo === 'puestoDesde' ? form.value.puestoDesde : form.value.puestoHasta
  }
  buscarOpen.value = true
}

function focusablesPanel(form: HTMLElement): HTMLElement[] {
  return Array.from(form.querySelectorAll<HTMLElement>('input:not([disabled]), select:not([disabled])')).filter(
    (el) => el.getClientRects().length > 0,
  )
}

/** Intro: siguiente campo del panel; en el último, Generar (como legacy). */
function onPanelEnterNav(e: KeyboardEvent) {
  if (e.key !== 'Enter' && e.key !== 'NumpadEnter') return
  if (e.isComposing || buscarOpen.value) return

  const target = e.target
  if (!(target instanceof HTMLElement)) return
  const form = e.currentTarget
  if (!(form instanceof HTMLFormElement)) return

  const list = focusablesPanel(form)
  const idx = list.indexOf(target)
  if (idx === -1) return

  e.preventDefault()

  if (idx < list.length - 1) {
    const next = list[idx + 1]
    next.focus()
    if (next instanceof HTMLInputElement && next.type !== 'date') {
      next.select()
    }
    return
  }

  form.requestSubmit()
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  const codigo = r.codigo.trim()
  switch (buscarCampo.value) {
    case 'clienteDesde':
      form.value.clienteDesde = codigo
      break
    case 'clienteHasta':
      form.value.clienteHasta = codigo
      break
    case 'puestoDesde':
      form.value.puestoDesde = codigo
      break
    case 'puestoHasta':
      form.value.puestoHasta = codigo
      break
  }
  buscarOpen.value = false
}

let seq = 0
async function generar() {
  if (form.value.fechaDesde > form.value.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarInformeIva(paramsConsulta())
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Sin documentos en el periodo seleccionado.'
    } else {
      const t = data.totales
      mensaje.value = `${t.tickets} documento(s). Total: ${numCsv(t.importeTotal)} € (base ${numCsv(t.baseImponible)} · IVA ${numCsv(t.cuotaIva)})`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el informe')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

async function vistaPreviaPdf() {
  if (form.value.fechaDesde > form.value.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  const ventana = abrirVentanaPreview('Informe de IVA')
  if (!ventana) {
    error.value = 'Permita las ventanas emergentes para previsualizar el PDF'
    return
  }
  generandoPdf.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarInformeIvaPdf(paramsConsulta())
    if (blob.type && blob.type.includes('json')) {
      throw new Error('Error al generar PDF')
    }
    escribirVentanaPdf(ventana, blob, 'Informe de IVA')
    mensaje.value = 'PDF del informe listo para previsualizar'
    if (!resultado.value) {
      await generar()
    }
  } catch (e: unknown) {
    if (!ventana.closed) ventana.close()
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    generandoPdf.value = false
  }
}

function cabecerasDesglose(): string[] {
  const cab: string[] = []
  for (const t of tiposIva.value) {
    const l = etiquetaPje(t.pjeIva)
    cab.push(`Base ${l}`, `Cuota ${l}`)
  }
  return cab
}

function filaDesgloseValores(r: InformeIvaFila): string[] {
  const vals: string[] = []
  for (const t of tiposIva.value) {
    vals.push(celdaDesglose(r, t.pjeIva, 'baseImponible') || '')
    vals.push(celdaDesglose(r, t.pjeIva, 'cuotaIva') || '')
  }
  return vals
}

function exportarExcel() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const cab = ['Fecha', 'Documento', 'Tienda', 'Cliente', ...cabecerasDesglose(), 'Total']
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [
        r.fecha,
        etiquetaDocumento(r),
        r.empresa,
        r.razonSocial || r.cliente,
        ...filaDesgloseValores(r),
        numCsv(r.importeTotal),
      ]
        .map(escCsv)
        .join(';')
    )
  }
  const t = resultado.value!.totales
  const totDesglose: string[] = []
  for (const r of tiposIva.value) {
    totDesglose.push(numCsv(r.baseImponible), numCsv(r.cuotaIva))
  }
  lines.push('')
  lines.push(['TOTAL', '', '', '', ...totDesglose, numCsv(t.importeTotal)].map(escCsv).join(';'))
  descargarCsv('informe-iva.csv', lines)
  mensaje.value = 'Excel (CSV) exportado'
}

async function imprimir() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const t = resultado.value!.totales
  const thead = ['Fecha', 'Documento', 'Cliente', ...cabecerasDesglose(), 'Total']
  const body = filas.map((r) => [
    r.fecha,
    etiquetaDocumento(r),
    r.razonSocial || r.cliente,
    ...filaDesgloseValores(r),
    numCsv(r.importeTotal),
  ])
  const res = await imprimirListadoHtml({
    titulo: 'Informe de IVA',
    metaLineas: metaImpresion(),
    thead,
    filas: body,
    pie: [
      `${t.tickets} documentos · Base: ${numCsv(t.baseImponible)} € · Cuota: ${numCsv(t.cuotaIva)} € · Total: ${numCsv(t.importeTotal)} €`,
    ],
    filenameFallback: 'informe-iva.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('informe-iva')
  void cargarTiendas()
})
</script>

<template>
  <section class="ventas-view">
    <div class="head">
      <div>
        <h2>Informe de IVA</h2>
        <p class="hint">Misma disposición que el listado de Ventas.</p>
      </div>
      <div class="head-actions">
        <button type="button" class="btn-accion" :disabled="generandoPdf" @click="vistaPreviaPdf">
          {{ generandoPdf ? 'PDF…' : 'Vista previa' }}
        </button>
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="exportarExcel">Excel</button>
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="imprimir">Imprimir</button>
      </div>
    </div>

    <div class="layout-busqueda">
      <form class="panel-filtros" @submit.prevent="generar" @keydown="onPanelEnterNav">
        <fieldset class="bloque-opciones">
          <legend>Opciones</legend>
          <label>
            <span>Divisa</span>
            <select v-model="form.divisa" disabled>
              <option value="EU">EU</option>
            </select>
          </label>
          <label>
            <span>Estado</span>
            <select v-model="form.estado">
              <option v-for="o in INFORME_IVA_ESTADOS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label>
            <span>Formato</span>
            <select v-model="form.formato">
              <option v-for="o in INFORME_IVA_FORMATOS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
        </fieldset>

        <fieldset class="bloque-intervalos">
          <legend>Intervalos</legend>
          <div class="rango-head">
            <span />
            <span>Desde</span>
            <span>Hasta</span>
          </div>
          <div class="rango-row">
            <span class="rango-label">Tienda</span>
            <select v-model="form.empresaDesde" :disabled="loadingOpts">
              <option value="">—</option>
              <option v-for="t in tiendas" :key="`ed-${t.value}`" :value="t.value">{{ t.value }}</option>
            </select>
            <select v-model="form.empresaHasta" :disabled="loadingOpts">
              <option value="">—</option>
              <option v-for="t in tiendas" :key="`eh-${t.value}`" :value="t.value">{{ t.value }}</option>
            </select>
          </div>
          <div class="rango-row">
            <span class="rango-label">Fecha</span>
            <input v-model="form.fechaDesde" type="date" required />
            <input v-model="form.fechaHasta" type="date" required />
          </div>
          <div class="rango-row">
            <span class="rango-label">Cliente</span>
            <div class="con-lupa">
              <input v-model="form.clienteDesde" maxlength="12" />
              <button type="button" class="btn-lupa" title="Buscar cliente desde" @click="abrirBuscar('clienteDesde')">
                <ToolIcon name="buscar" />
              </button>
            </div>
            <div class="con-lupa">
              <input v-model="form.clienteHasta" maxlength="12" />
              <button type="button" class="btn-lupa" title="Buscar cliente hasta" @click="abrirBuscar('clienteHasta')">
                <ToolIcon name="buscar" />
              </button>
            </div>
          </div>
          <div class="rango-row">
            <span class="rango-label">Origen</span>
            <select v-model="form.origenDesde" :disabled="loadingOpts" title="Empresa origen">
              <option value="">—</option>
              <option v-for="t in tiendas" :key="`od-${t.value}`" :value="t.value">{{ t.value }}</option>
            </select>
            <select v-model="form.origenHasta" :disabled="loadingOpts" title="Empresa origen">
              <option value="">—</option>
              <option v-for="t in tiendas" :key="`oh-${t.value}`" :value="t.value">{{ t.value }}</option>
            </select>
          </div>
          <div class="rango-row">
            <span class="rango-label">Cierre ses.</span>
            <DecimalInput
              :model-value="form.cierreSesionDesde === '' ? null : Number(form.cierreSesionDesde)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.cierreSesionDesde = $event ?? ''"
            />
            <DecimalInput
              :model-value="form.cierreSesionHasta === '' ? null : Number(form.cierreSesionHasta)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.cierreSesionHasta = $event ?? ''"
            />
          </div>
          <div class="rango-row">
            <span class="rango-label">Puesto</span>
            <div class="con-lupa">
              <input v-model="form.puestoDesde" maxlength="4" />
              <button type="button" class="btn-lupa" title="Buscar puesto desde" @click="abrirBuscar('puestoDesde')">
                <ToolIcon name="buscar" />
              </button>
            </div>
            <div class="con-lupa">
              <input v-model="form.puestoHasta" maxlength="4" />
              <button type="button" class="btn-lupa" title="Buscar puesto hasta" @click="abrirBuscar('puestoHasta')">
                <ToolIcon name="buscar" />
              </button>
            </div>
          </div>
          <div class="rango-row">
            <span class="rango-label">Sesión</span>
            <DecimalInput
              :model-value="form.sesionDesde === '' ? null : Number(form.sesionDesde)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.sesionDesde = $event ?? ''"
            />
            <DecimalInput
              :model-value="form.sesionHasta === '' ? null : Number(form.sesionHasta)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.sesionHasta = $event ?? ''"
            />
          </div>
          <div class="rango-row">
            <span class="rango-label">Nº factura</span>
            <DecimalInput
              :model-value="form.facturaDesde === '' ? null : Number(form.facturaDesde)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.facturaDesde = $event ?? ''"
            />
            <DecimalInput
              :model-value="form.facturaHasta === '' ? null : Number(form.facturaHasta)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.facturaHasta = $event ?? ''"
            />
          </div>
        </fieldset>

        <button type="submit" class="btn-buscar" :disabled="generando || loadingOpts">
          {{ generando ? 'Generando…' : 'Generar' }}
        </button>
      </form>

      <div class="panel-listado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <p v-if="generando" class="msg">Generando informe…</p>
        <p v-if="resultado?.truncado" class="msg">
          Se muestran como máximo {{ resultado.limite }} documentos; acote fechas o tienda.
        </p>

        <div v-if="tieneDatos" class="grid-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Documento</th>
                <th>Tienda</th>
                <th>Cliente</th>
                <template v-for="t in tiposIva" :key="`h-${t.pjeIva}`">
                  <th class="num col-iva">Base {{ etiquetaPje(t.pjeIva) }}</th>
                  <th class="num col-iva">Cuota {{ etiquetaPje(t.pjeIva) }}</th>
                </template>
                <th class="num">Total</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in resultado!.items" :key="`${row.empresa}-${row.numeroTicket}-${i}`">
                <td>{{ row.fecha }}</td>
                <td>{{ etiquetaDocumento(row) }}</td>
                <td>{{ row.empresa }}</td>
                <td class="clip">{{ row.razonSocial || row.cliente || '—' }}</td>
                <template v-for="t in tiposIva" :key="`${row.numeroTicket}-${t.pjeIva}`">
                  <td class="num col-iva">{{ celdaDesglose(row, t.pjeIva, 'baseImponible') }}</td>
                  <td class="num col-iva">{{ celdaDesglose(row, t.pjeIva, 'cuotaIva') }}</td>
                </template>
                <td class="num">{{ numCsv(row.importeTotal) }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="4"><strong>Totales</strong></td>
                <template v-for="t in tiposIva" :key="`f-${t.pjeIva}`">
                  <td class="num"><strong>{{ numCsv(t.baseImponible) }}</strong></td>
                  <td class="num"><strong>{{ numCsv(t.cuotaIva) }}</strong></td>
                </template>
                <td class="num">
                  <strong>{{ numCsv(resultado!.totales.importeTotal) }}</strong>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-else-if="resultado && !generando" class="empty">No hay filas que mostrar.</p>
        <p v-else-if="!generando && !error && !resultado" class="empty">Pulse Generar para cargar el informe.</p>

        <section v-if="resultado?.resumenPorIva.length" class="resumen-iva">
          <h3>Resumen por % IVA</h3>
          <table class="grid resumen-grid">
            <thead>
              <tr>
                <th class="num">% IVA</th>
                <th class="num">Base imponible</th>
                <th class="num">Cuota IVA</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(r, j) in resultado!.resumenPorIva" :key="`${r.pjeIva}-${j}`">
                <td class="num">{{ numCsv(r.pjeIva, 2) }}</td>
                <td class="num">{{ numCsv(r.baseImponible) }}</td>
                <td class="num">{{ numCsv(r.cuotaIva) }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      :entidad="buscarEntidad"
      :titulo="
        buscarCampo === 'clienteDesde'
          ? 'Buscar cliente desde'
          : buscarCampo === 'clienteHasta'
            ? 'Buscar cliente hasta'
            : buscarCampo === 'puestoDesde'
              ? 'Buscar puesto desde'
              : 'Buscar puesto hasta'
      "
      :busqueda-inicial="buscarInicial"
      @seleccionar="onEntidadSeleccionada"
      @cerrar="buscarOpen = false"
    />
  </section>
</template>

<style scoped>
@import './listado-grid.css';

.ventas-view {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.65rem;
  flex-shrink: 0;
}

.head h2 {
  margin: 0;
}

.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0;
}

.head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.btn-accion {
  padding: 0.45rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  color: #1e293b;
  cursor: pointer;
  font: inherit;
  font-size: 0.88rem;
}

.btn-accion:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

/* Panel lateral ancho cómodo para inputs desde/hasta (como diario de facturación). */
.layout-busqueda {
  display: grid;
  grid-template-columns: minmax(16rem, 20rem) minmax(0, 1fr);
  gap: 0.75rem;
  flex: 1;
  min-height: 0;
}

.panel-filtros {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  padding: 0.65rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #f8fafc;
  min-width: 0;
  align-self: start;
  max-height: 100%;
  overflow-y: auto;
}

.panel-filtros fieldset {
  margin: 0;
  padding: 0.45rem 0.5rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  min-width: 0;
}

.panel-filtros legend {
  padding: 0 0.3rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.bloque-opciones {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.bloque-opciones label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: #475569;
}

.bloque-opciones select {
  width: 100%;
  box-sizing: border-box;
  padding: 0.35rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}

.bloque-intervalos {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.rango-head,
.rango-row {
  display: grid;
  grid-template-columns: 4.2rem 1fr 1fr;
  gap: 0.3rem;
  align-items: center;
}

.rango-head {
  margin-bottom: 0;
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
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  font-size: 0.78rem;
  line-height: 1.25;
  background: #fff;
}

.rango-row input[type='date'] {
  font-size: 0.75rem;
  padding: 0.18rem 0.3rem;
}

.rango-row :deep(input) {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.2rem 0.35rem;
  font-size: 0.78rem;
  line-height: 1.25;
}

.con-lupa {
  display: flex;
  align-items: center;
  gap: 0.2rem;
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
  width: 1.55rem;
  height: 1.55rem;
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

.btn-buscar {
  margin-top: 0.25rem;
  padding: 0.45rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  cursor: pointer;
  font-weight: 600;
  font: inherit;
}

.btn-buscar:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.panel-listado {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.error {
  margin: 0;
  color: #b91c1c;
  font-size: 0.88rem;
}

.ok {
  margin: 0;
  color: #15803d;
  font-size: 0.88rem;
}

.msg,
.empty {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}

.grid-wrap {
  flex: 1;
  min-height: 8rem;
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}

.grid-wrap table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.grid-wrap th,
.grid-wrap td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.45rem;
}

.grid-wrap th {
  background: #f1f5f9;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
}

.clip {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 12rem;
}

.resumen-iva {
  flex-shrink: 0;
}

.resumen-iva h3 {
  margin: 0 0 0.35rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: #334155;
}

.resumen-grid {
  max-width: 28rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.col-iva {
  white-space: nowrap;
  font-size: 0.8rem;
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
