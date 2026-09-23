<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import {
  descargarAlbaranPendientePdf,
  descargarAlbaranesPendientesPdf,
  listarAlbaranesPendientesFacturar,
  obtenerAlbaranPendiente,
} from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaManualPendiente } from '@/types/facturacion'
import type { VentaDetalle } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { useGridRenderLimit } from '@/composables/useGridRenderLimit'
import { abrirVentanaPreview, escribirVentanaPdf } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

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
const detalleOpen = ref(false)
const detalle = ref<VentaDetalle | null>(null)
const detalleRef = ref<FacturaManualPendiente | null>(null)
const detalleLoading = ref(false)
const detalleError = ref<string | null>(null)
const imprimiendo = ref(false)

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

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'albaran', label: 'Albarán', clase: 'col-alb' },
  { key: 'puesto', label: 'Pto', clase: 'col-pto' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'razonSocial', label: 'Razón social', clase: 'col-razon' },
  { key: 'nif', label: 'NIF', clase: 'col-nif' },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
  { key: 'prefactura', label: 'Pref.', clase: 'col-pref' },
] as const

type ColumnaKey = (typeof COLUMNAS)[number]['key']

const textoColumna: Record<ColumnaKey, (r: FacturaManualPendiente) => string> = {
  fecha: (r) => String(r.fecha ?? ''),
  albaran: (r) => String(r.albaran ?? ''),
  puesto: (r) => String(r.puesto ?? ''),
  cliente: (r) => String(r.cliente ?? ''),
  razonSocial: (r) => String(r.razonSocial ?? ''),
  nif: (r) => String(r.nif ?? ''),
  importe: (r) => Number(r.importe ?? 0).toFixed(2),
  prefactura: (r) => (r.prefactura ? 'Sí' : ''),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    fecha: '',
    albaran: '',
    puesto: '',
    cliente: '',
    razonSocial: '',
    nif: '',
    importe: '',
    prefactura: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>(filtrosColumnaVacios())

function normalizar(texto: string) {
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
      : items.value.filter((r) =>
          activos.every((f) => normalizar(textoColumna[f.key](r)).includes(f.valor))
        )
  return ordenarFilas(base, (row, key) => textoColumna[key as ColumnaKey](row), ['fecha'])
})

const { gridEl, visibles, onScrollGrid } = useGridRenderLimit(itemsFiltrados)

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

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
    items.value = data.items ?? []
    mensaje.value = `${data.totales.albaranes} albaranes · ${data.totales.importe.toFixed(2)} €`
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar albaranes pendientes')
    items.value = []
  } finally {
    loading.value = false
  }
}

/** Valida que el blob sea un PDF real; si no, extrae el error JSON de la API. */
async function asegurarPdf(blob: Blob, fallback: string) {
  const head = await blob.slice(0, 5).text()
  if (head.startsWith('%PDF')) return
  let msg = fallback
  try {
    const j = JSON.parse(await blob.text()) as { error?: string }
    if (j.error) msg = j.error
  } catch {
    /* ignore */
  }
  throw new Error(msg)
}

async function abrirDetalle(r: FacturaManualPendiente) {
  detalleRef.value = r
  detalle.value = null
  detalleError.value = null
  detalleOpen.value = true
  detalleLoading.value = true
  try {
    detalle.value = await obtenerAlbaranPendiente(r.empresa, r.tipo, r.albaran)
  } catch (e: unknown) {
    detalleError.value = extractApiError(e, 'No se pudo cargar el albarán')
  } finally {
    detalleLoading.value = false
  }
}

function cerrarDetalle() {
  detalleOpen.value = false
  detalle.value = null
  detalleRef.value = null
  detalleError.value = null
}

async function imprimirAlbaran() {
  const r = detalleRef.value
  if (!r) return
  imprimiendo.value = true
  detalleError.value = null
  try {
    const ventana = abrirVentanaPreview(`Albarán ${r.tipo}/${r.albaran}`)
    if (!ventana) throw new Error('Permita las ventanas emergentes para previsualizar el PDF')
    try {
      const blob = await descargarAlbaranPendientePdf(r.empresa, r.tipo, r.albaran)
      await asegurarPdf(blob, 'Error al generar el PDF del albarán')
      escribirVentanaPdf(ventana, blob, `Albarán ${r.tipo}/${r.albaran}`)
    } catch (e) {
      if (!ventana.closed) ventana.close()
      throw e
    }
  } catch (e: unknown) {
    detalleError.value = extractApiError(e, 'No se pudo generar el PDF del albarán')
  } finally {
    imprimiendo.value = false
  }
}

function num(valor: number | null | undefined): string {
  return Number(valor ?? 0).toFixed(2)
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
  const ventana = abrirVentanaPreview('Albaranes pendientes de facturar')
  if (!ventana) {
    error.value = 'Permita las ventanas emergentes para previsualizar el PDF'
    return
  }
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const blob = await descargarAlbaranesPendientesPdf(paramsConsulta())
    await asegurarPdf(blob, 'Error al generar PDF')
    escribirVentanaPdf(ventana, blob, 'Albaranes pendientes de facturar')
    mensaje.value = 'PDF listo para previsualizar'
  } catch (e: unknown) {
    if (!ventana.closed) ventana.close()
    error.value = extractApiError(e, 'No se pudo generar el PDF')
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
  await buscar()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Albaranes pendientes de facturar</h2>
        <p class="hint">
          Informe de crédito pendiente (solo consulta). Pulse una línea para ver el albarán.
        </p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="loading || loadingOpts" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button type="button" class="btn primary" :disabled="saving || !items.length" @click="pdf">
          {{ saving ? 'PDF…' : 'Previsualizar PDF' }}
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
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

        <div class="resumen">
          <div>
            <span>Listadas</span>
            <strong>{{ itemsFiltrados.length }}</strong>
          </div>
          <div>
            <span>Total</span>
            <strong>{{ items.length }}</strong>
          </div>
        </div>
      </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>
        <div v-if="items.length" ref="gridEl" class="grid-wrap" @scroll.passive="onScrollGrid">
          <table>
            <thead>
              <tr>
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
                v-for="r in visibles"
                :key="`${r.empresa}|${r.tipo}|${r.albaran}`"
                class="fila"
                title="Ver albarán"
                @click="abrirDetalle(r)"
              >
                <td>{{ r.fecha }}</td>
                <td>{{ r.albaran }}</td>
                <td>{{ r.puesto }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td>{{ r.prefactura ? 'Sí' : '' }}</td>
              </tr>
              <tr v-if="!itemsFiltrados.length">
                <td :colspan="COLUMNAS.length" class="empty">Ningún albarán con esos filtros</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!loading && !error" class="empty">
          No hay albaranes pendientes con estas opciones e intervalos.
        </p>
        <div v-if="hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ itemsFiltrados.length }} de {{ items.length }}</span>
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="detalleOpen" class="alb-overlay" @click.self="cerrarDetalle">
        <div class="alb-modal" role="dialog" aria-modal="true" aria-label="Albarán">
          <header class="alb-head">
            <h3>
              Albarán {{ detalleRef?.tipo }}/{{ detalleRef?.albaran }}
              <span class="alb-sub">Tienda {{ detalleRef?.empresa }}</span>
            </h3>
            <div class="alb-acciones">
              <button
                type="button"
                class="btn primary"
                :disabled="imprimiendo || detalleLoading || !detalle"
                @click="imprimirAlbaran"
              >
                {{ imprimiendo ? 'Imprimiendo…' : 'Imprimir' }}
              </button>
              <button type="button" class="btn" @click="cerrarDetalle">Cerrar</button>
            </div>
          </header>

          <div class="alb-body">
            <p v-if="detalleError" class="error">{{ detalleError }}</p>
            <p v-if="detalleLoading" class="empty">Cargando albarán…</p>

            <template v-if="detalle">
              <dl class="alb-datos">
                <div><dt>Fecha</dt><dd>{{ detalle.fecha }}</dd></div>
                <div><dt>Cliente</dt><dd>{{ detalle.cliente }}</dd></div>
                <div class="ancho"><dt>Razón social</dt><dd>{{ detalle.razonSocial }}</dd></div>
                <div><dt>NIF</dt><dd>{{ detalle.nif }}</dd></div>
                <div><dt>Puesto</dt><dd>{{ detalle.puesto }}</dd></div>
                <div><dt>Vendedor</dt><dd>{{ detalle.vendedor }}</dd></div>
                <div><dt>Estado</dt><dd>{{ detalle.estado }}</dd></div>
                <div class="ancho"><dt>Dirección envío</dt><dd>{{ detalle.direccionEnvio }}</dd></div>
                <div>
                  <dt>Población</dt>
                  <dd>{{ detalle.codigoPostalEnvio }} {{ detalle.poblacionEnvio }}</dd>
                </div>
                <div><dt>Prefactura</dt><dd>{{ detalleRef?.prefactura ? 'Sí' : 'No' }}</dd></div>
              </dl>

              <div class="alb-lineas">
                <table>
                  <thead>
                    <tr>
                      <th>Artículo</th>
                      <th>Descripción</th>
                      <th class="num">Cantidad</th>
                      <th class="num">Precio</th>
                      <th class="num">% Dto</th>
                      <th class="num">Importe</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(l, i) in detalle.lineas" :key="l.nroLin ?? i">
                      <td>{{ l.articulo }}</td>
                      <td class="clip">{{ l.descripcion }}</td>
                      <td class="num">{{ num(l.cantidad) }}</td>
                      <td class="num">{{ num(l.precio) }}</td>
                      <td class="num">{{ num(l.pjeDto) }}</td>
                      <td class="num">{{ num(l.importe) }}</td>
                    </tr>
                    <tr v-if="!detalle.lineas.length">
                      <td colspan="6" class="empty">El albarán no tiene líneas.</td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <div class="alb-totales">
                <span v-for="(iva, i) in detalle.importesIva" :key="i" class="tag">
                  Base {{ num(iva.base) }} · IVA {{ num(iva.pjeIva) }}% · {{ num(iva.iva) }}
                </span>
                <strong>Total {{ num(detalle.importe) }} €</strong>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

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
.page {
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
  grid-template-columns: 21rem minmax(0, 1fr);
  gap: 0.85rem;
  align-items: stretch;
  min-height: 0;
  flex: 1;
}
.sidebar {
  width: 21rem;
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
.panel fieldset {
  margin: 0;
  padding: 0.55rem 0.55rem 0.65rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
.panel legend {
  padding: 0 0.3rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}
.rango-head,
.rango-row {
  display: grid;
  grid-template-columns: 4.4rem minmax(0, 1fr) minmax(0, 1fr);
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
.rango-row input,
.rango-row select,
.opciones select {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  height: 1.65rem;
  font: inherit;
  font-size: 0.78rem;
  background: #fff;
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
.opciones label {
  display: grid;
  grid-template-columns: 6.2rem minmax(0, 1fr);
  align-items: center;
  gap: 0.3rem;
  font-size: 0.75rem;
  color: #334155;
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
  margin-top: auto;
}
.resumen strong {
  display: block;
  color: #94a3b8;
  font-size: 1rem;
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
  vertical-align: top;
}
.th-titulo {
  display: block;
  padding: 0 0.1rem 0.15rem;
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
  font-weight: 400;
  height: auto;
}
.filtro-col:focus {
  outline: 2px solid #2563eb;
  outline-offset: -1px;
}
.col-fecha {
  width: 6.5rem;
}
.col-alb {
  width: 6rem;
}
.col-pto,
.col-pref {
  width: 4rem;
}
.col-cliente,
.col-nif {
  width: 7rem;
}
.col-importe {
  width: 6.5rem;
}
.pie-grid {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}
.listadas {
  font-size: 0.78rem;
  color: #64748b;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.fila {
  cursor: pointer;
}
.fila:hover td {
  background: #e0f2fe;
}
.alb-overlay {
  position: fixed;
  inset: 0;
  z-index: 1100;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.55);
}
.alb-modal {
  display: flex;
  flex-direction: column;
  width: min(1000px, 96vw);
  max-height: 90vh;
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
}
.alb-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}
.alb-head h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}
.alb-sub {
  margin-left: 0.5rem;
  font-size: 0.8rem;
  font-weight: 400;
  color: #64748b;
}
.alb-acciones {
  display: flex;
  gap: 0.35rem;
}
.alb-body {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  padding: 0.75rem 0.85rem;
  overflow: auto;
}
.alb-datos {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(11rem, 1fr));
  gap: 0.4rem 0.75rem;
  margin: 0;
}
.alb-datos .ancho {
  grid-column: span 2;
}
.alb-datos dt {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
  color: #64748b;
}
.alb-datos dd {
  margin: 0;
  font-size: 0.85rem;
  color: #0f172a;
}
.alb-lineas {
  overflow: auto;
  max-height: 45vh;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}
.alb-totales {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  justify-content: flex-end;
}
.alb-totales .tag {
  padding: 0.15rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #f8fafc;
  font-size: 0.75rem;
  color: #334155;
}
.clip {
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
</style>
