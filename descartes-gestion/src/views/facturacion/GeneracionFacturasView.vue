<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { generarFacturasAutomatico, previewGeneracionFacturas } from '@/api/facturacion'
import { api } from '@/api/client'
import type {
  FacturaManualGenerada,
  FacturasGeneracionPreviewResponse,
  FacturasGeneracionResponse,
} from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import DecimalInput from '@/components/common/DecimalInput.vue'

type Opt = { value: string; label: string }

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loading = ref(false)
const loadingOpts = ref(false)
const generating = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const preview = ref<FacturasGeneracionPreviewResponse | null>(null)
const generadas = ref<FacturaManualGenerada[]>([])
const emails = ref<FacturasGeneracionResponse['emails'] | null>(null)
const tiendas = ref<Opt[]>([])
const puestos = ref<Opt[]>([])

function hoyIso() {
  return new Date().toISOString().slice(0, 10)
}

const form = ref({
  empresa: '',
  fechaFacturacion: hoyIso(),
  agrupacion: 'separar' as 'separar' | 'agrupar',
  tipoFacturacion: 'facturas' as 'facturas' | 'prefacturas',
  tipoCliente: 'normales' as 'normales' | 'manuales' | 'todos',
  seleccion: 'todos' as 'todos' | 'con_prefactura' | 'sin_prefactura',
  importeMinimo: '' as string | number,
  empresaDesde: '',
  empresaHasta: '',
  puestoDesde: '',
  puestoHasta: '',
  fechaDesde: '',
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

function params(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    agrupacion: f.agrupacion,
    tipoCliente: f.tipoCliente,
    tipoFacturacion: f.tipoFacturacion,
    seleccion: f.seleccion,
  }
  const put = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  put('empresa', f.empresa)
  put('fechaFacturacion', f.fechaFacturacion)
  put('empresaDesde', f.empresaDesde || f.empresa)
  put('empresaHasta', f.empresaHasta || f.empresaDesde || f.empresa)
  put('puestoDesde', f.puestoDesde)
  put('puestoHasta', f.puestoHasta || f.puestoDesde)
  put('fechaDesde', f.fechaDesde)
  put('fechaHasta', f.fechaHasta || f.fechaFacturacion)
  put('vendedorDesde', f.vendedorDesde)
  put('vendedorHasta', f.vendedorHasta || f.vendedorDesde)
  put('clienteDesde', f.clienteDesde)
  put('clienteHasta', f.clienteHasta || f.clienteDesde)
  put('fpagoDesde', f.fpagoDesde)
  put('fpagoHasta', f.fpagoHasta || f.fpagoDesde)
  const ad = Number(f.albaranDesde)
  if (Number.isFinite(ad) && ad > 0) out.albaranDesde = ad
  const ah = Number(f.albaranHasta)
  if (Number.isFinite(ah) && ah > 0) out.albaranHasta = ah
  const im = Number(f.importeMinimo)
  if (Number.isFinite(im) && im !== 0) out.importeMinimo = im
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

    const emp = String(puestoContexto.empresaCodigo ?? '').trim()
    if (emp) {
      form.value.empresa = emp
      form.value.empresaDesde = emp
      form.value.empresaHasta = emp
    }
    const puestoPc = String(puestoContexto.puestoCodigo ?? '').trim()
    if (puestoPc) {
      form.value.puestoDesde = puestoPc
      form.value.puestoHasta = puestoPc
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar opciones')
  } finally {
    loadingOpts.value = false
  }
}

async function consultar() {
  if (!puede('facturacion-generacion', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresa.trim()) {
    error.value = 'Indique la empresa de facturación'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  generadas.value = []
  emails.value = null
  try {
    preview.value = await previewGeneracionFacturas(params())
    const t = preview.value.totales
    mensaje.value = `${t.albaranes} albaranes · ~${t.gruposEstimados} facturas · ${t.importe.toFixed(2)} €`
    if (preview.value.omitidosImporteMinimo > 0) {
      mensaje.value += ` (${preview.value.omitidosImporteMinimo} grupos bajo mínimo)`
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo consultar pendientes')
    preview.value = null
  } finally {
    loading.value = false
  }
}

async function ejecutar() {
  if (!puede('facturacion-generacion', 'crear')) {
    error.value = 'Sin permiso para generar facturas'
    return
  }
  if (!form.value.empresa.trim() || !form.value.fechaFacturacion) {
    error.value = 'Empresa y fecha de facturación obligatorias'
    return
  }
  if (!preview.value?.totales.gruposEstimados) {
    error.value = 'Consulte primero; no hay grupos a facturar'
    return
  }
  const t = preview.value.totales
  const docLabel = form.value.tipoFacturacion === 'prefacturas' ? 'pre-factura(s)' : 'factura(s)'
  if (
    !window.confirm(
      `¿Generar ~${t.gruposEstimados} ${docLabel} de ${t.albaranes} albarán(es) por ${t.importe.toFixed(2)} €?`
    )
  ) {
    return
  }

  generating.value = true
  error.value = null
  mensaje.value = null
  try {
    const p = params()
    const result = await generarFacturasAutomatico({
      empresa: String(p.empresa ?? ''),
      fechaFacturacion: String(p.fechaFacturacion ?? ''),
      agrupacion: form.value.agrupacion,
      tipoFacturacion: form.value.tipoFacturacion,
      tipoCliente: form.value.tipoCliente,
      seleccion: form.value.seleccion,
      importeMinimo: p.importeMinimo !== undefined ? Number(p.importeMinimo) : undefined,
      empresaDesde: p.empresaDesde !== undefined ? String(p.empresaDesde) : undefined,
      empresaHasta: p.empresaHasta !== undefined ? String(p.empresaHasta) : undefined,
      fechaDesde: p.fechaDesde !== undefined ? String(p.fechaDesde) : undefined,
      fechaHasta: p.fechaHasta !== undefined ? String(p.fechaHasta) : undefined,
      clienteDesde: p.clienteDesde !== undefined ? String(p.clienteDesde) : undefined,
      clienteHasta: p.clienteHasta !== undefined ? String(p.clienteHasta) : undefined,
      albaranDesde: p.albaranDesde !== undefined ? Number(p.albaranDesde) : undefined,
      albaranHasta: p.albaranHasta !== undefined ? Number(p.albaranHasta) : undefined,
      puestoDesde: p.puestoDesde !== undefined ? String(p.puestoDesde) : undefined,
      puestoHasta: p.puestoHasta !== undefined ? String(p.puestoHasta) : undefined,
      vendedorDesde: p.vendedorDesde !== undefined ? String(p.vendedorDesde) : undefined,
      vendedorHasta: p.vendedorHasta !== undefined ? String(p.vendedorHasta) : undefined,
      fpagoDesde: p.fpagoDesde !== undefined ? String(p.fpagoDesde) : undefined,
      fpagoHasta: p.fpagoHasta !== undefined ? String(p.fpagoHasta) : undefined,
    })
    generadas.value = result.facturas
    emails.value = result.emails
    mensaje.value = `Generadas ${result.totales.facturas} ${docLabel} · ${result.totales.albaranes} albaranes · ${result.totales.importe.toFixed(2)} €`
    if (result.omitidosImporteMinimo > 0) {
      mensaje.value += ` · omitidos ${result.omitidosImporteMinimo} por importe mínimo`
    }
    if (form.value.tipoFacturacion === 'facturas') {
      mensaje.value += ` · emails: ${result.emails.enviadas} enviados, ${result.emails.omitidas} omitidos`
      if (result.emails.errores > 0) {
        mensaje.value += `, ${result.emails.errores} con error`
      }
    }
    preview.value = null
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar las facturas')
  } finally {
    generating.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="gen-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Generación de facturas</h2>
        <p class="hint">
          Factura automáticamente los albaranes de crédito pendientes y envía el PDF a los
          clientes configurados para recibir facturas por email.
        </p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="loading || loadingOpts" @click="consultar">
          {{ loading ? 'Consultando…' : 'Consultar' }}
        </button>
        <button
          type="button"
          class="btn primary"
          :disabled="generating || loading || !preview?.totales.gruposEstimados"
          @click="ejecutar"
        >
          {{ generating ? 'Generando…' : 'Ejecutar' }}
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel" @submit.prevent="consultar">
          <fieldset class="opciones">
            <legend>Facturación</legend>
            <label>
              <span>Empresa</span>
              <select v-model="form.empresa" :disabled="loadingOpts" required>
                <option value="">—</option>
                <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.value }}</option>
              </select>
            </label>
            <label>
              <span>F. factur.</span>
              <input v-model="form.fechaFacturacion" type="date" required />
            </label>
            <label>
              <span>Agrupación</span>
              <select v-model="form.agrupacion">
                <option value="separar">Separar fact./abonos</option>
                <option value="agrupar">Agrupar fact. y abonos</option>
              </select>
            </label>
            <label>
              <span>Tipo fact.</span>
              <select v-model="form.tipoFacturacion">
                <option value="facturas">Facturas</option>
                <option value="prefacturas">Pre-Facturas</option>
              </select>
            </label>
            <label>
              <span>Tipo cliente</span>
              <select v-model="form.tipoCliente">
                <option value="normales">Normales</option>
                <option value="manuales">Facturación manual</option>
                <option value="todos">Todos</option>
              </select>
            </label>
            <label>
              <span>Selección</span>
              <select v-model="form.seleccion">
                <option value="todos">Todos</option>
                <option value="con_prefactura">Con Prefactura</option>
                <option value="sin_prefactura">Sin Prefactura</option>
              </select>
            </label>
            <label>
              <span>Imp. mínimo</span>
              <DecimalInput
                :model-value="form.importeMinimo === '' || form.importeMinimo == null ? null : Number(form.importeMinimo)"
                :empty-as-null="true"
                placeholder="0"
                @update:model-value="form.importeMinimo = $event ?? ''"
              />
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

          <div v-if="preview" class="resumen">
            <div>
              <span>Albaranes</span>
              <strong class="sel">{{ preview.totales.albaranes }}</strong>
            </div>
            <div>
              <span>Facturas</span>
              <strong class="sel">{{ preview.totales.gruposEstimados }}</strong>
            </div>
            <div class="full">
              <span>Importe</span>
              <strong class="sel">{{ preview.totales.importe.toFixed(2) }} €</strong>
            </div>
          </div>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="!preview && !generadas.length && !loading && !error" class="empty">
          Configure opciones e intervalos y pulse <strong>Consultar</strong>. Luego
          <strong>Ejecutar</strong> para generar.
        </p>
        <p v-if="loading" class="hint">Calculando pendientes…</p>

        <div v-if="generadas.length" class="generadas">
          <h3>Facturas generadas</h3>
          <ul>
            <li v-for="(f, i) in generadas" :key="i">
              {{ f.facturaTipo }}/{{ f.factura }} · cliente {{ f.cliente }} ·
              {{ f.importe.toFixed(2) }} € · {{ f.albaranes.length }} alb.
              <template v-if="emails?.detalles[i]">
                · email {{ emails.detalles[i].estado }}
                <span v-if="emails.detalles[i].destinatario">
                  ({{ emails.detalles[i].destinatario }})
                </span>
                <span v-if="emails.detalles[i].motivo">
                  — {{ emails.detalles[i].motivo }}
                </span>
              </template>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.gen-page {
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
.opciones label {
  display: grid;
  grid-template-columns: 4.6rem minmax(0, 1fr);
  align-items: center;
  gap: 0.3rem;
  font-size: 0.75rem;
  color: #334155;
  min-width: 0;
}
.opciones select,
.opciones input,
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
.rango-head span:first-child {
  text-align: left;
}
.rango-label {
  font-size: 0.75rem;
  color: #334155;
  white-space: nowrap;
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
  width: 100%;
  margin-top: auto;
}
.resumen .full {
  grid-column: 1 / -1;
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
.generadas {
  border: 1px solid #d1fae5;
  background: #ecfdf5;
  border-radius: 4px;
  padding: 0.6rem 0.75rem;
}
.generadas h3 {
  margin: 0 0 0.35rem;
  font-size: 0.9rem;
}
.generadas ul {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}
</style>
