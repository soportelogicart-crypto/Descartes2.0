<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { listarDiarioFacturacion } from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaDiarioItem } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { useGridRenderLimit } from '@/composables/useGridRenderLimit'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { ESTILOS_LISTADO_A4 } from '@/composables/listadoPrintStyles'
import { LEGACY_PRINT_CSS } from '@/composables/abcVentasInformeHtml'

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
  fechaDesde: hoyIso(),
  fechaHasta: hoyIso(),
  clienteDesde: '',
  clienteHasta: '',
  facturaDesde: '' as string | number,
  facturaHasta: '' as string | number,
  facturaTipo: '',
  estado: '',
})

function textoCobro(r: FacturaDiarioItem) {
  return r.tipoCobro === 'diferida' ? 'Dif.' : 'Con.'
}

type FilaImpuesto = {
  clave: string
  primera: boolean
  factura: FacturaDiarioItem
  base: number
  pjeIva: number
  cuotaIva: number
  pjeRec: number
  cuotaRec: number
}

const filasImpuesto = computed<FilaImpuesto[]>(() => {
  const out: FilaImpuesto[] = []
  for (const factura of items.value) {
    const lineas = factura.desgloseIva?.length
      ? factura.desgloseIva
      : [{ pjeIva: 0, baseImponible: 0, cuotaIva: 0, pjeRec: 0, cuotaRec: 0 }]
    lineas.forEach((d, i) => {
      out.push({
        clave: `${factura.empresa}|${factura.facturaTipo}|${factura.factura}|${i}`,
        primera: i === 0,
        factura,
        base: d.baseImponible,
        pjeIva: d.pjeIva,
        cuotaIva: d.cuotaIva,
        pjeRec: d.pjeRec ?? 0,
        cuotaRec: d.cuotaRec ?? 0,
      })
    })
  }
  return out
})

function fmtImporte(n: number) {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtFechaCab(iso: string) {
  const s = iso.trim()
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s)
  if (m) return `${m[3]}/${m[2]}/${m[1]}`
  return s || '…'
}

const fechaImpresionAhora = ref('')

function marcarFechaImpresion() {
  fechaImpresionAhora.value = new Date().toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

function sumaFacturas(campo: 'importe' | 'irpf') {
  return items.value.reduce(
    (acc, fila) => acc + Number(campo === 'importe' ? fila.importe : (fila.cuotaIrpf ?? 0)),
    0
  )
}

function sumaLineas(campo: 'base' | 'iva' | 'recargo') {
  return filasImpuesto.value.reduce((acc, fila) => {
    if (campo === 'base') return acc + fila.base
    if (campo === 'iva') return acc + fila.cuotaIva
    return acc + fila.cuotaRec
  }, 0)
}

const { gridEl, visibles, onScrollGrid } = useGridRenderLimit(filasImpuesto)

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
    items.value = data.items ?? []
    marcarFechaImpresion()
    const base = Number(data.totales.baseImponible ?? 0).toFixed(2)
    const cuota = Number(data.totales.cuotaIva ?? 0).toFixed(2)
    mensaje.value = `${data.totales.facturas} facturas · base ${base} € · IVA ${cuota} € · total ${data.totales.importe.toFixed(2)} €`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el diario')
    items.value = []
  } finally {
    loading.value = false
  }
}

function escHtml(v: string | number) {
  return String(v ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function celdasImpuesto(fila: FilaImpuesto): string {
  const irpf = fila.primera
    ? `<td class="col-num">${fmtImporte(fila.factura.pjeIrpf ?? 0)}</td><td class="col-num">${fmtImporte(fila.factura.cuotaIrpf ?? 0)}</td>`
    : '<td class="col-num"></td><td class="col-num"></td>'
  return `<td class="col-num">${fmtImporte(fila.base)}</td><td class="col-num">${fmtImporte(fila.pjeIva)}</td><td class="col-num">${fmtImporte(fila.cuotaIva)}</td><td class="col-num">${fmtImporte(fila.pjeRec)}</td><td class="col-num">${fmtImporte(fila.cuotaRec)}</td>${irpf}`
}

function construirHtmlDiario(): string {
  const thead = `<thead><tr><th>Fecha</th><th>Tie.</th><th>Factura</th><th>Cliente</th><th>Razón social</th><th class="col-nif">NIF</th><th class="col-num">Base</th><th class="col-num">% Iva</th><th class="col-num">Iva</th><th class="col-num">% Rec</th><th class="col-num">Recargo</th><th class="col-num">% IRPF</th><th class="col-num">IRPF</th><th class="col-num">Importe</th><th>Cobro</th><th>F.P.</th></tr></thead>`
  const filas = filasImpuesto.value
    .map((fila) => {
      const r = fila.factura
      const identidad = fila.primera
        ? `<td>${escHtml(fmtFechaCab(r.fecha))}</td><td>${escHtml(r.empresa)}</td><td>${escHtml(r.factura)}</td><td>${escHtml(r.cliente)}</td><td>${escHtml(r.razonSocial ?? '')}</td><td class="col-nif">${escHtml(r.nif ?? '')}</td>`
        : '<td></td><td></td><td></td><td></td><td></td><td></td>'
      const cierre = fila.primera
        ? `<td class="col-num">${fmtImporte(r.importe)}</td><td>${escHtml(textoCobro(r))}</td><td>${escHtml(r.fpago || '—')}</td>`
        : '<td></td><td></td><td></td>'
      return `<tr>${identidad}${celdasImpuesto(fila)}${cierre}</tr>`
    })
    .join('')
  const total = `<tr class="total-general"><td colspan="6" class="lab-tot">TOTAL GENERAL</td><td class="col-num">${fmtImporte(sumaLineas('base'))}</td><td></td><td class="col-num">${fmtImporte(sumaLineas('iva'))}</td><td></td><td class="col-num">${fmtImporte(sumaLineas('recargo'))}</td><td></td><td class="col-num">${fmtImporte(sumaFacturas('irpf'))}</td><td class="col-num">${fmtImporte(sumaFacturas('importe'))}</td><td colspan="2"></td></tr>`

  const css = `
.abc-legacy .col-nif { white-space: nowrap; }
.abc-legacy td, .abc-legacy th { white-space: nowrap; }
`
  return `<!doctype html><html><head><meta charset="utf-8"><title>Diario de facturación</title>
<style>${ESTILOS_LISTADO_A4}${LEGACY_PRINT_CSS}${css}</style></head><body><main class="folio">
<div class="legacy-top">
  <div><h1 class="legacy-tit">DIARIO DE FACTURACIÓN</h1><hr class="legacy-tit-line" /></div>
  <div class="legacy-fecha">
    <span class="legacy-fecha-lab">Fecha</span>
    <span class="legacy-fecha-val">${escHtml(fmtFechaCab(form.value.fechaDesde))} 00:00</span>
    <span class="legacy-fecha-val">${escHtml(fmtFechaCab(form.value.fechaHasta))} 23:59</span>
  </div>
</div>
<p class="legacy-impresion">Fecha de Impresión: ${escHtml(fechaImpresionAhora.value)}</p>
<hr class="legacy-sep" />
<table class="abc-table abc-legacy">${thead}<tbody>${filas}${total}</tbody></table>
</main></body></html>`
}

async function previsualizar() {
  if (!puede('facturacion-diario', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!items.value.length) {
    error.value = 'Pulse Buscar antes de previsualizar'
    return
  }
  saving.value = true
  try {
    const res = await imprimirListadoHtml({
      titulo: 'Diario de facturación',
      html: construirHtmlDiario(),
      filenameFallback: 'diario-facturacion.html',
    })
    mensaje.value = res.message
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo preparar la previsualización')
  } finally {
    saving.value = false
  }
}

function exportarExcel() {
  const filas = items.value
  if (!filas.length) {
    error.value = 'No hay filas para exportar'
    return
  }
  const esc = (v: string | number) => {
    const s = String(v ?? '')
    if (/[;"\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`
    return s
  }
  const num = (n: number) => (Math.round(n * 100) / 100).toFixed(2).replace('.', ',')
  const cabecera = [
    'Fecha',
    'Tienda',
    'Tipo',
    'Factura',
    'Cliente',
    'Razón social',
    'NIF',
    'Base',
    '% Iva',
    'Iva',
    '% Rec',
    'Recargo',
    '% IRPF',
    'IRPF',
    'Importe',
    'Cobro',
    'Forma de pago',
    'Impresa',
  ]
  const lines = [cabecera.map(esc).join(';')]
  for (const fila of filasImpuesto.value) {
    const r = fila.factura
    lines.push(
      [
        fila.primera ? r.fecha : '',
        fila.primera ? r.empresa : '',
        fila.primera ? r.facturaTipo : '',
        fila.primera ? r.factura : '',
        fila.primera ? r.cliente : '',
        fila.primera ? r.razonSocial : '',
        fila.primera ? r.nif : '',
        num(fila.base),
        num(fila.pjeIva),
        num(fila.cuotaIva),
        num(fila.pjeRec),
        num(fila.cuotaRec),
        fila.primera ? num(r.pjeIrpf ?? 0) : '',
        fila.primera ? num(r.cuotaIrpf ?? 0) : '',
        fila.primera ? num(r.importe) : '',
        fila.primera ? (r.tipoCobro === 'diferida' ? 'Diferida' : 'Contado') : '',
        fila.primera ? r.fpago || '' : '',
        fila.primera ? (r.impresa ? 'Sí' : 'No') : '',
      ]
        .map(esc)
        .join(';')
    )
  }
  const blob = new Blob(['\uFEFF' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'diario-facturacion.csv'
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
  mensaje.value = `Excel (CSV) de ${filas.length} factura(s)`
}

onMounted(async () => {
  await cargarOpciones()
  await buscar()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div>
        <h2>Diario de facturación</h2>
        <p class="hint">Listado de facturas emitidas (auditoría). La previsualización es el diario tabular, no reimpresión.</p>
      </div>
      <div class="actions">
        <button
          type="button"
          class="btn primary"
          :disabled="saving || loading || loadingOpts || !items.length"
          @click="previsualizar"
        >
          {{ saving ? 'Preparando…' : 'Previsualizar' }}
        </button>
        <button
          type="button"
          class="btn"
          :disabled="loading || !items.length"
          @click="exportarExcel"
        >
          Exportar Excel
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
            <DecimalInput
              :model-value="form.facturaDesde === '' || form.facturaDesde == null ? null : Number(form.facturaDesde)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.facturaDesde = $event ?? ''"
            />
            <DecimalInput
              :model-value="form.facturaHasta === '' || form.facturaHasta == null ? null : Number(form.facturaHasta)"
              :empty-as-null="true"
              :integer="true"
              @update:model-value="form.facturaHasta = $event ?? ''"
            />
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
        <button type="submit" class="btn primary btn-buscar" :disabled="loading || loadingOpts">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
      </form>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>
        <div v-if="items.length" ref="gridEl" class="informe-wrap" @scroll.passive="onScrollGrid">
          <div class="informe abc-legacy-informe">
            <header class="informe-cab legacy-cab">
              <div class="legacy-top">
                <div>
                  <h1 class="legacy-tit">DIARIO DE FACTURACIÓN</h1>
                  <hr class="legacy-tit-line" />
                </div>
                <div class="legacy-fecha">
                  <span class="legacy-fecha-lab">Fecha</span>
                  <span class="legacy-fecha-val">{{ fmtFechaCab(form.fechaDesde) }} 00:00</span>
                  <span class="legacy-fecha-val">{{ fmtFechaCab(form.fechaHasta) }} 23:59</span>
                </div>
              </div>
              <p class="legacy-impresion">Fecha de Impresión: {{ fechaImpresionAhora }}</p>
              <hr class="legacy-sep" />
            </header>
            <div class="table-scroll">
              <table class="abc-table abc-legacy">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Tie.</th>
                    <th>Factura</th>
                    <th>Cliente</th>
                    <th class="col-articulo">Razón social</th>
                    <th class="col-nif">NIF</th>
                    <th class="col-num">Base</th>
                    <th class="col-num">% Iva</th>
                    <th class="col-num">Iva</th>
                    <th class="col-num">% Rec</th>
                    <th class="col-num">Recargo</th>
                    <th class="col-num">% IRPF</th>
                    <th class="col-num">IRPF</th>
                    <th class="col-num">Importe</th>
                    <th>Cobro</th>
                    <th>F.P.</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="fila in visibles" :key="fila.clave">
                    <td>{{ fila.primera ? fmtFechaCab(fila.factura.fecha) : '' }}</td>
                    <td>{{ fila.primera ? fila.factura.empresa : '' }}</td>
                    <td>{{ fila.primera ? fila.factura.factura : '' }}</td>
                    <td class="col-codigo">{{ fila.primera ? fila.factura.cliente : '' }}</td>
                    <td class="col-articulo" :title="fila.primera ? fila.factura.razonSocial : ''">
                      {{ fila.primera ? fila.factura.razonSocial : '' }}
                    </td>
                    <td class="col-nif">{{ fila.primera ? fila.factura.nif : '' }}</td>
                    <td class="col-num">{{ fmtImporte(fila.base) }}</td>
                    <td class="col-num">{{ fmtImporte(fila.pjeIva) }}</td>
                    <td class="col-num">{{ fmtImporte(fila.cuotaIva) }}</td>
                    <td class="col-num">{{ fmtImporte(fila.pjeRec) }}</td>
                    <td class="col-num">{{ fmtImporte(fila.cuotaRec) }}</td>
                    <td class="col-num">{{ fila.primera ? fmtImporte(fila.factura.pjeIrpf ?? 0) : '' }}</td>
                    <td class="col-num">{{ fila.primera ? fmtImporte(fila.factura.cuotaIrpf ?? 0) : '' }}</td>
                    <td class="col-num">{{ fila.primera ? fmtImporte(fila.factura.importe) : '' }}</td>
                    <td>{{ fila.primera ? textoCobro(fila.factura) : '' }}</td>
                    <td>{{ fila.primera ? fila.factura.fpago || '—' : '' }}</td>
                  </tr>
                  <tr class="total-row total-general">
                    <td colspan="6" class="lab-tot"><strong>TOTAL GENERAL</strong></td>
                    <td class="col-num">{{ fmtImporte(sumaLineas('base')) }}</td>
                    <td></td>
                    <td class="col-num">{{ fmtImporte(sumaLineas('iva')) }}</td>
                    <td></td>
                    <td class="col-num">{{ fmtImporte(sumaLineas('recargo')) }}</td>
                    <td></td>
                    <td class="col-num">{{ fmtImporte(sumaFacturas('irpf')) }}</td>
                    <td class="col-num">{{ fmtImporte(sumaFacturas('importe')) }}</td>
                    <td colspan="2"></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <p v-else-if="!loading && !error" class="empty">
          No hay facturas con estas opciones e intervalos.
        </p>
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
  </section>
</template>

<style scoped>
@import '../listados/abc-form-view.css';

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
.btn-buscar {
  width: 100%;
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
.informe-wrap {
  flex: 1;
  overflow: auto;
}
.informe-wrap .abc-table.abc-legacy {
  table-layout: auto;
  width: max-content;
  max-width: none;
  min-width: 100%;
}
.informe-wrap .table-scroll {
  overflow-x: auto;
  max-width: 100%;
}
.abc-table.abc-legacy .col-nif {
  min-width: 7rem;
  white-space: nowrap;
}
.abc-legacy-informe .legacy-top {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 4px;
}
.abc-legacy-informe .legacy-tit {
  font-size: 1.35rem;
  font-weight: 700;
  font-style: italic;
  color: #006f6f;
  margin: 0;
}
.abc-legacy-informe .legacy-tit-line {
  border: none;
  border-top: 2px solid #006f6f;
  margin: 2px 0 0;
}
.abc-legacy-informe .legacy-fecha {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  font-size: 0.72rem;
}
.abc-legacy-informe .legacy-fecha-lab {
  background: #006f6f;
  color: #fff;
  padding: 2px 6px;
  font-weight: 700;
}
.abc-legacy-informe .legacy-fecha-val {
  background: #004d4d;
  color: #fff;
  padding: 2px 6px;
}
.abc-legacy-informe .legacy-impresion {
  font-size: 0.72rem;
  margin: 0.35rem 0;
}
.abc-legacy-informe .legacy-sep {
  border: none;
  border-top: 3px solid #6b2d2d;
  margin: 0 0 0.5rem;
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
