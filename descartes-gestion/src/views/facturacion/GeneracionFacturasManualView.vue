<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  generarAlbaranesPeriodicos,
  generarFacturasManual,
  listarFacturasManualPendientes,
  traspasoFacturasManual,
} from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaManualPendiente, FacturaManualGenerada } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

type Opt = { value: string; label: string }

const puestoContexto = usePuestoContextoStore()
const { puede } = usePermisos()

const loading = ref(false)
const loadingOpts = ref(false)
const generating = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<FacturaManualPendiente[]>([])
const selected = ref<Record<string, boolean>>({})
const tiendas = ref<Opt[]>([])
const formasPago = ref<Opt[]>([])
const generadas = ref<FacturaManualGenerada[]>([])
const modalPeriodicos = ref(false)
const periodoDesde = ref('')
const periodoHasta = ref('')
const busyExtra = ref(false)
const buscarClienteOpen = ref(false)
const buscarClienteInicial = ref('')

function hoyIso() {
  return new Date().toISOString().slice(0, 10)
}

function rowKey(r: FacturaManualPendiente) {
  return `${r.empresa}|${r.tipo}|${r.albaran}`
}

const form = ref({
  empresa: '',
  fechaFacturacion: hoyIso(),
  agrupacion: 'separar' as 'separar' | 'agrupar',
  tipo: 'facturas' as 'facturas' | 'prefacturas',
  /** Por defecto todos: un cliente CR no lleva necesariamente FacturacionManual. */
  tipoCliente: 'todos' as 'normales' | 'manuales' | 'todos',
  seleccion: 'todos' as 'todos' | 'con_prefactura' | 'sin_prefactura',
  formaPago: '',
  numFactura: '' as string | number,
  fechaDesde: '',
  fechaHasta: '',
  cliente: '',
  albaranDesde: '' as string | number,
  albaranHasta: '' as string | number,
})

const seleccionados = computed(() => items.value.filter((r) => selected.value[rowKey(r)]))
const totalSel = computed(() => seleccionados.value.length)
const importeSel = computed(() =>
  Math.round(seleccionados.value.reduce((s, r) => s + r.importe, 0) * 100) / 100
)

const todosMarcados = computed(
  () => items.value.length > 0 && items.value.every((r) => selected.value[rowKey(r)])
)

function toggleTodos(v: boolean) {
  const next: Record<string, boolean> = { ...selected.value }
  for (const r of items.value) {
    next[rowKey(r)] = v
  }
  selected.value = next
}

function abrirBuscarCliente() {
  buscarClienteInicial.value = form.value.cliente.trim()
  buscarClienteOpen.value = true
}

function onClienteSeleccionado(sel: EntidadBuscarResultado) {
  buscarClienteOpen.value = false
  form.value.cliente = sel.codigo
}

function paramsConsulta(): Record<string, string | number | undefined> {
  const f = form.value
  const out: Record<string, string | number | undefined> = {
    seleccion: f.seleccion,
    tipoCliente: f.tipoCliente,
  }
  const put = (k: string, v: string | number) => {
    const s = String(v ?? '').trim()
    if (s !== '') out[k] = s
  }
  put('empresa', f.empresa)
  put('fechaDesde', f.fechaDesde)
  put('fechaHasta', f.fechaHasta)
  put('cliente', f.cliente)
  const ad = Number(f.albaranDesde)
  if (Number.isFinite(ad) && ad > 0) out.albaranDesde = ad
  const ah = Number(f.albaranHasta)
  if (Number.isFinite(ah) && ah > 0) out.albaranHasta = ah
  return out
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const [tiendasRes, fpRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }),
      api.get('/api/mantenimiento/formas-pago', { params: { pageSize: 500 } }),
    ])
    tiendas.value = (tiendasRes.data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: `${codigo} — ${t.nombre ?? ''}` }
    })
    formasPago.value = (fpRes.data.items ?? []).map(
      (f: { codigo: string; descripcion?: string }) => {
        const codigo = String(f.codigo ?? '').trim()
        return { value: codigo, label: `${codigo} — ${f.descripcion ?? ''}` }
      }
    )
    const emp = String(puestoContexto.empresaCodigo ?? '').trim()
    if (emp) form.value.empresa = emp
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar opciones')
  } finally {
    loadingOpts.value = false
  }
}

async function buscar() {
  if (!puede('facturacion-manual', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  generadas.value = []
  try {
    const data = await listarFacturasManualPendientes(paramsConsulta())
    items.value = data.items
    selected.value = {}
    mensaje.value = `${data.totales.albaranes} albaranes · ${data.totales.importe.toFixed(2)} €`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar albaranes pendientes')
    items.value = []
  } finally {
    loading.value = false
  }
}

async function ejecutar() {
  if (!puede('facturacion-manual', 'crear')) {
    error.value = 'Sin permiso para generar facturas'
    return
  }
  if (!form.value.empresa.trim()) {
    error.value = 'Indique la empresa de facturación'
    return
  }
  if (!form.value.fechaFacturacion) {
    error.value = 'Indique la fecha de facturación'
    return
  }
  if (seleccionados.value.length === 0) {
    error.value = 'Seleccione al menos un albarán'
    return
  }
  if (
    !window.confirm(
      form.value.tipo === 'prefacturas'
        ? `¿Generar pre-facturas de ${totalSel.value} albarán(es) por ${importeSel.value.toFixed(2)} €?`
        : `¿Generar facturas de ${totalSel.value} albarán(es) por ${importeSel.value.toFixed(2)} €?`
    )
  ) {
    return
  }

  generating.value = true
  error.value = null
  mensaje.value = null
  try {
    const num = Number(form.value.numFactura)
    const result = await generarFacturasManual({
      empresa: form.value.empresa.trim(),
      fechaFacturacion: form.value.fechaFacturacion,
      agrupacion: form.value.agrupacion,
      tipoFacturacion: form.value.tipo,
      formaPago: form.value.formaPago.trim() || undefined,
      numFactura: Number.isFinite(num) && num > 0 ? num : undefined,
      albaranes: seleccionados.value.map((r) => ({
        empresa: r.empresa,
        tipo: r.tipo,
        albaran: r.albaran,
      })),
    })
    generadas.value = result.facturas
    const label = form.value.tipo === 'prefacturas' ? 'pre-factura(s)' : 'factura(s)'
    mensaje.value = `Generadas ${result.totales.facturas} ${label} · ${result.totales.albaranes} albaranes · ${result.totales.importe.toFixed(2)} €`
    await buscar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar las facturas')
  } finally {
    generating.value = false
  }
}

async function onTraspaso() {
  if (!puede('facturacion-manual', 'crear')) {
    error.value = 'Sin permiso'
    return
  }
  if (!form.value.empresa.trim()) {
    error.value = 'Indique la empresa'
    return
  }
  if (seleccionados.value.length === 0) {
    error.value = 'Seleccione al menos un albarán'
    return
  }
  if (
    !window.confirm(
      '¿Está seguro de transformar los albaranes a traspasos entre almacenes?'
    )
  ) {
    return
  }

  busyExtra.value = true
  error.value = null
  mensaje.value = null
  generadas.value = []
  try {
    const result = await traspasoFacturasManual({
      empresa: form.value.empresa.trim(),
      albaranes: seleccionados.value.map((r) => ({
        empresa: r.empresa,
        tipo: r.tipo,
        albaran: r.albaran,
      })),
    })
    mensaje.value = `Creados ${result.totales.traspasos} traspaso(s) comercial(es). Los albaranes salen de facturación.`
    await buscar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar los traspasos')
  } finally {
    busyExtra.value = false
  }
}

function abrirGenAlb() {
  if (!form.value.empresa.trim()) {
    error.value = 'Indique la empresa'
    return
  }
  const hoy = hoyIso()
  periodoDesde.value = hoy
  periodoHasta.value = hoy
  modalPeriodicos.value = true
  error.value = null
}

async function confirmarGenAlb() {
  if (!puede('facturacion-manual', 'crear')) {
    error.value = 'Sin permiso'
    return
  }
  if (!periodoDesde.value || !periodoHasta.value) {
    error.value = 'Indique el rango de fechas'
    return
  }

  busyExtra.value = true
  error.value = null
  mensaje.value = null
  try {
    const result = await generarAlbaranesPeriodicos({
      empresa: form.value.empresa.trim(),
      fechaDesde: periodoDesde.value,
      fechaHasta: periodoHasta.value,
    })
    modalPeriodicos.value = false
    mensaje.value =
      result.totales.generados > 0
        ? `Generados ${result.totales.generados} albarán(es) periódico(s)` +
          (result.totales.omitidos ? ` · ${result.totales.omitidos} omitido(s)` : '')
        : 'No había albaranes periódicos pendientes en ese rango'
    await buscar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar los albaranes periódicos')
  } finally {
    busyExtra.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
})
</script>

<template>
  <section class="manual-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Generador de facturas Manual</h2>
        <p class="hint">
          Albaranes pendientes (sin facturar) → seleccionar → facturar. Si no salen, revise Tipo
          cliente y que el documento esté finalizado como albarán.
        </p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="loading || loadingOpts || busyExtra" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button
          type="button"
          class="btn"
          title="Generar albaranes periódicos"
          :disabled="generating || busyExtra || loadingOpts"
          @click="abrirGenAlb"
        >
          Gen.Alb
        </button>
        <button
          type="button"
          class="btn"
          title="Generar traspaso comercial"
          :disabled="generating || busyExtra || loading || totalSel === 0"
          @click="onTraspaso"
        >
          {{ busyExtra ? '…' : 'Traspaso' }}
        </button>
        <button
          type="button"
          class="btn primary"
          :disabled="generating || busyExtra || loading || totalSel === 0"
          @click="ejecutar"
        >
          {{ generating ? 'Generando…' : 'Ejecutar' }}
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel" @submit.prevent="buscar">
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
              <span>Tipo</span>
              <select v-model="form.tipo">
                <option value="facturas">Facturas</option>
                <option value="prefacturas">Pre-Facturas</option>
              </select>
            </label>
            <label>
              <span>Tipo cliente</span>
              <select v-model="form.tipoCliente" title="Flag Facturación manual del cliente">
                <option value="todos">Todos</option>
                <option value="normales">Normales</option>
                <option value="manuales">Solo facturación manual</option>
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
              <span>Factura</span>
              <input
                v-model="form.numFactura"
                type="number"
                min="0"
                placeholder="Auto"
                :disabled="form.tipo === 'prefacturas'"
                :title="form.tipo === 'prefacturas' ? 'No disponible en pre-facturas' : ''"
              />
            </label>
            <label>
              <span>F. cobro</span>
              <select v-model="form.formaPago" :disabled="loadingOpts">
                <option value="">(cliente)</option>
                <option v-for="f in formasPago" :key="f.value" :value="f.value">{{ f.value }}</option>
              </select>
            </label>
          </fieldset>

          <fieldset class="filtros">
            <legend>Filtros búsqueda</legend>
            <div class="rango-head">
              <span></span>
              <span>Desde</span>
              <span>Hasta</span>
            </div>
            <div class="rango-row">
              <span class="rango-label">Fecha</span>
              <input v-model="form.fechaDesde" type="date" />
              <input v-model="form.fechaHasta" type="date" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Albarán</span>
              <input v-model="form.albaranDesde" type="number" min="0" />
              <input v-model="form.albaranHasta" type="number" min="0" />
            </div>
            <div class="rango-row">
              <span class="rango-label">Cliente</span>
              <div class="con-lupa span-2">
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
            </div>
          </fieldset>

          <div class="resumen">
            <div>
              <span>Albaranes</span>
              <strong :class="{ sel: totalSel > 0 }">{{ totalSel }}</strong>
            </div>
            <div>
              <span>Importe</span>
              <strong :class="{ sel: totalSel > 0 }">{{ importeSel.toFixed(2) }} €</strong>
            </div>
          </div>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando pendientes…</p>
        <p v-if="!items.length && !loading && !error" class="empty">
          Configure filtros y pulse <strong>Buscar</strong>.
        </p>

        <div v-if="items.length" class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="sel">
                  <input
                    type="checkbox"
                    :checked="todosMarcados"
                    title="Todos"
                    @change="toggleTodos(($event.target as HTMLInputElement).checked)"
                  />
                </th>
                <th>Fecha</th>
                <th>Tie.</th>
                <th>Albarán</th>
                <th>Pto</th>
                <th>Cliente</th>
                <th>Razón social</th>
                <th>NIF</th>
                <th class="num">Importe</th>
                <th class="num">A cuenta</th>
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
                <td>{{ r.albaran }}</td>
                <td>{{ r.puesto }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td class="num">{{ r.pagoACuenta.toFixed(2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="generadas.length" class="generadas">
          <h3>Facturas generadas</h3>
          <ul>
            <li v-for="(f, i) in generadas" :key="i">
              {{ f.facturaTipo }}/{{ f.factura }} · cliente {{ f.cliente }} ·
              {{ f.importe.toFixed(2) }} € · {{ f.albaranes.length }} alb.
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div
      v-if="modalPeriodicos"
      class="modal-backdrop"
      role="dialog"
      aria-modal="true"
      aria-label="Generación de albaranes periódicos"
      @click.self="modalPeriodicos = false"
    >
      <div class="modal">
        <h3>Generación de albaranes periódicos</h3>
        <p class="hint">Se copiarán las plantillas de <code>AlbaranesPeriodicos</code> cuya próxima fecha caiga en el rango.</p>
        <label>
          <span>Fecha inicial</span>
          <input v-model="periodoDesde" type="date" />
        </label>
        <label>
          <span>Fecha final</span>
          <input v-model="periodoHasta" type="date" />
        </label>
        <div class="modal-actions">
          <button type="button" class="btn" :disabled="busyExtra" @click="modalPeriodicos = false">
            Cancelar
          </button>
          <button type="button" class="btn primary" :disabled="busyExtra" @click="confirmarGenAlb">
            {{ busyExtra ? 'Generando…' : 'Generar' }}
          </button>
        </div>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarClienteOpen"
      entidad="clientes"
      titulo="Buscar cliente"
      :busqueda-inicial="buscarClienteInicial"
      @seleccionar="onClienteSeleccionado"
      @cerrar="buscarClienteOpen = false"
    />
  </section>
</template>

<style scoped>
.manual-page {
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
  align-self: stretch;
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

.opciones label,
.campo-simple {
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
.filtros input,
.campo-simple input {
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
.rango-row .span-2 {
  grid-column: 2 / -1;
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
  width: 100%;
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
  background: #fff;
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
.generadas {
  border: 1px solid #d1fae5;
  background: #ecfdf5;
  border-radius: 4px;
  padding: 0.6rem 0.75rem;
  flex-shrink: 0;
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
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.35);
  padding: 1rem;
}
.modal {
  width: min(24rem, 100%);
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  padding: 1rem 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
}
.modal h3 {
  margin: 0;
  font-size: 1rem;
}
.modal label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.8rem;
}
.modal input[type='date'] {
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
  margin-top: 0.25rem;
}
</style>
