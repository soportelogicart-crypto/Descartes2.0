<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { listarFacturasImpresion, marcarFacturasImpresas, enviarFacturasImpresionEmail } from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaImpresionItem } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import {
  imprimirFacturasPreparadas,
  prepararImpresionFacturas,
  type PrepImpresionFacturas,
} from '@/composables/useImpresionFacturaDocumento'
import { useVentanaPreviewDocumento } from '@/composables/previewDocumentoVentana'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import FacturasImpresionA4Modal from '@/components/facturacion/FacturasImpresionA4Modal.vue'
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
const items = ref<FacturaImpresionItem[]>([])
const selected = ref<Record<string, boolean>>({})
const tiendas = ref<Opt[]>([])
const actividades = ref<Opt[]>([])
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionFacturas | null>(null)
const preguntaEmailOpen = ref(false)
const emailOpen = ref(false)
const emailDestino = ref('')
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const buscarClienteOpen = ref(false)
const buscarClienteCampo = ref<CampoCliente>('desde')
const buscarClienteInicial = ref('')
/** Ventana aparte con la previsualización A4 (sustituye al modal). */
const preview = useVentanaPreviewDocumento({ imprimir: () => imprimirDocumentos() })

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

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'factura', label: 'Factura', clase: 'col-factura' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'razonSocial', label: 'Razón social', clase: 'col-razon' },
  { key: 'nif', label: 'NIF', clase: 'col-nif' },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
  { key: 'cobro', label: 'Cobro', clase: 'col-cobro' },
  { key: 'impresa', label: 'Imp.', clase: 'col-imp' },
] as const

type ColumnaKey = (typeof COLUMNAS)[number]['key']

const textoColumna: Record<ColumnaKey, (r: FacturaImpresionItem) => string> = {
  fecha: (r) => String(r.fecha ?? ''),
  factura: (r) => String(r.factura ?? ''),
  cliente: (r) => String(r.cliente ?? ''),
  razonSocial: (r) => String(r.razonSocial ?? ''),
  nif: (r) => String(r.nif ?? ''),
  importe: (r) => Number(r.importe ?? 0).toFixed(2),
  cobro: (r) => (r.tipoCobro === 'diferida' ? 'Diferida' : 'Contado'),
  impresa: (r) => (r.impresa ? 'Sí' : 'No'),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    fecha: '',
    factura: '',
    cliente: '',
    razonSocial: '',
    nif: '',
    importe: '',
    cobro: '',
    impresa: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>(filtrosColumnaVacios())

/** Compara sin acentos ni mayúsculas: "MARIA" encuentra "María". */
function normalizar(texto: string): string {
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

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

// Solo se imprime lo que se ve: los filtros de columna acotan la selección.
const seleccionados = computed(() => itemsFiltrados.value.filter((r) => selected.value[rowKey(r)]))
const todosMarcados = computed(
  () => itemsFiltrados.value.length > 0 && itemsFiltrados.value.every((r) => selected.value[rowKey(r)])
)

function toggleTodos(v: boolean) {
  const next: Record<string, boolean> = { ...selected.value }
  for (const r of itemsFiltrados.value) next[rowKey(r)] = v
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
    const todos = data.items ?? []
    items.value = todos.slice(0, GRID_LIMITE_INICIAL)
    selected.value = {}
    const trunc =
      todos.length > GRID_LIMITE_INICIAL
        ? ` (mostrando ${GRID_LIMITE_INICIAL} de ${todos.length})`
        : ''
    if (data.items.length === 0 && form.value.estadoImpresion === 'pendientes') {
      mensaje.value =
        '0 facturas pendientes de imprimir. Pruebe Estado = Todas o Impresas.'
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

function onImprimir() {
  if (!puede('facturacion-impresion', 'ver')) {
    error.value = 'Sin permiso'
    return
  }
  if (seleccionados.value.length === 0) {
    error.value = 'Seleccione al menos una factura'
    return
  }
  error.value = null
  mensaje.value = null
  preguntaEmailOpen.value = true
}

function soloImprimir() {
  preguntaEmailOpen.value = false
  void previsualizar()
}

function quererEmail() {
  preguntaEmailOpen.value = false
  const sel = seleccionados.value
  emailDestino.value = ''
  emailOpen.value = true
  error.value = null
  if (sel.length === 0) {
    emailOpen.value = false
    error.value = 'Seleccione al menos una factura'
  }
}

function resumenEmail(r: { enviadas: number; omitidas: number; errores: number }) {
  const partes = [`Enviadas ${r.enviadas}`]
  if (r.omitidas) partes.push(`${r.omitidas} sin email`)
  if (r.errores) partes.push(`${r.errores} error(es)`)
  return partes.join(' · ')
}

async function confirmarEmail() {
  const sel = seleccionados.value
  if (sel.length === 0) return
  const email = emailDestino.value.trim()
  error.value = null
  // Abrir la ventana de preview en este clic (si no, el navegador la bloquea).
  const abierta = preview.abrir(
    sel.length === 1 ? `Factura ${sel[0].facturaTipo}-${sel[0].factura}` : 'Facturas'
  )
  if (!abierta) {
    error.value = 'Permita las ventanas emergentes para previsualizar la factura'
    return
  }
  emailOpen.value = false
  saving.value = true
  mensaje.value = null
  try {
    const result = await enviarFacturasImpresionEmail({
      facturas: sel.map((r) => ({
        empresa: r.empresa,
        facturaTipo: r.facturaTipo,
        factura: r.factura,
        cliente: r.cliente,
      })),
      email: email || undefined,
    })
    mensaje.value = resumenEmail(result)
    if (result.errores > 0) {
      error.value = result.detalles.find((d) => d.estado === 'error')?.motivo ?? 'Error al enviar'
    }
  } catch (e: unknown) {
    preview.cerrar()
    error.value = extractApiError(e, 'No se pudieron enviar las facturas')
    saving.value = false
    return
  }
  await cargarPreview(true)
}

/** Previsualiza con la plantilla del diseñador activa en una ventana aparte. */
async function previsualizar() {
  await cargarPreview(false)
}

async function cargarPreview(ventanaYaAbierta: boolean) {
  if (!puede('facturacion-impresion', 'ver')) {
    error.value = 'Sin permiso'
    if (ventanaYaAbierta) preview.cerrar()
    return
  }
  const sel = seleccionados.value
  if (sel.length === 0) {
    error.value = 'Seleccione al menos una factura'
    if (ventanaYaAbierta) preview.cerrar()
    return
  }
  if (!ventanaYaAbierta) {
    const abierta = preview.abrir(
      sel.length === 1 ? `Factura ${sel[0].facturaTipo}-${sel[0].factura}` : 'Facturas'
    )
    if (!abierta) {
      error.value = 'Permita las ventanas emergentes para previsualizar la factura'
      return
    }
    mensaje.value = null
  }

  saving.value = true
  try {
    a4Prep.value = await prepararImpresionFacturas(
      sel.map((r) => ({
        empresa: r.empresa,
        facturaTipo: r.facturaTipo,
        factura: r.factura,
      })),
      { puestoCodigo: String(puestoContexto.puestoCodigo ?? '') }
    )
    a4Open.value = true
    await nextTick()
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    if (!html) {
      throw new Error('No hay plantilla configurada para estas facturas en el puesto')
    }
    preview.mostrar(html, {
      impresoraNombre: a4Prep.value.impresoraNombre,
      puedeImprimir: puede('facturacion-impresion', 'crear'),
    })
  } catch (e: unknown) {
    preview.cerrar()
    a4Open.value = false
    error.value = extractApiError(e, 'No se pudo preparar la impresión')
  } finally {
    saving.value = false
  }
}

/** Imprime lo que muestra la ventana de previsualización. */
async function imprimirDocumentos() {
  const prep = a4Prep.value
  if (!prep || saving.value) return
  saving.value = true
  error.value = null
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    mensaje.value = await imprimirFacturasPreparadas(prep, html)
    if (form.value.marcarImpresa) {
      await marcarFacturasImpresas({ facturas: prep.documentos.map((d) => d.clave) })
    }
    preview.cerrar()
    a4Open.value = false
    if (form.value.marcarImpresa) await buscar()
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo imprimir')
    error.value = msg
    preview.notificarError(msg)
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
  // Al entrar se listan las facturas con los filtros por defecto, sin pulsar Buscar.
  await buscar()
})
</script>

<template>
  <section class="imp-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Impresión de facturas</h2>
        <p class="hint">
          Diferidas = crédito (Estado G). Imprimir abre la factura A4 (plantilla del
          diseñador del puesto) y pregunta si quiere enviarla por email. Solo se marcan
          como impresas si activa «Marcar al imprimir».
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
          @click="onImprimir"
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
              <strong>{{ itemsFiltrados.length }}</strong>
            </div>
          </div>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>
        <p v-if="!items.length && !loading && !error" class="empty">
          No hay facturas con estas opciones e intervalos.
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
                :key="rowKey(r)"
                :class="{ checked: selected[rowKey(r)] }"
                @click="selected[rowKey(r)] = !selected[rowKey(r)]"
              >
                <td class="sel" @click.stop>
                  <input v-model="selected[rowKey(r)]" type="checkbox" />
                </td>
                <td>{{ r.fecha }}</td>
                <td>{{ r.factura }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td :title="r.facturaContadoDiferida ? 'Contado diferido TPV' : ''">
                  {{ r.tipoCobro === 'diferida' ? 'Diferida' : 'Contado' }}
                </td>
                <td>{{ r.impresa ? 'Sí' : 'No' }}</td>
              </tr>
              <tr v-if="!itemsFiltrados.length">
                <td :colspan="COLUMNAS.length + 1" class="sin-filtro">
                  Ninguna factura con esos filtros
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="hayFiltroColumna" class="pie-grid">
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
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

    <Teleport to="body">
      <div
        v-if="preguntaEmailOpen"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-label="Enviar factura por email"
        @click.self="preguntaEmailOpen = false"
      >
        <div class="modal">
          <h3>Imprimir factura</h3>
          <p class="modal-hint">
            {{
              seleccionados.length === 1
                ? '¿Quiere enviar esta factura por email?'
                : `¿Quiere enviar estas ${seleccionados.length} facturas por email?`
            }}
          </p>
          <div class="modal-actions">
            <button type="button" class="btn" @click="preguntaEmailOpen = false">Cancelar</button>
            <button type="button" class="btn" @click="soloImprimir">No, solo imprimir</button>
            <button type="button" class="btn primary" @click="quererEmail">Sí, enviar</button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="emailOpen"
        class="modal-backdrop"
        role="dialog"
        aria-modal="true"
        aria-label="Enviar facturas por email"
        @click.self="emailOpen = false"
      >
        <div class="modal">
          <h3>Enviar por email</h3>
          <p v-if="seleccionados.length === 1" class="modal-hint">
            Factura {{ seleccionados[0].facturaTipo }}/{{ seleccionados[0].factura }}
            · {{ seleccionados[0].razonSocial || seleccionados[0].cliente }}
          </p>
          <p v-else class="modal-hint">
            {{ seleccionados.length }} facturas. Si deja el email vacío, cada una irá al correo del cliente.
          </p>
          <label>
            <span>{{
              seleccionados.length === 1
                ? 'Dirección de email (vacío = la del cliente)'
                : 'Email (opcional, el mismo para todas)'
            }}</span>
            <input v-model="emailDestino" type="email" placeholder="cliente@ejemplo.com" />
          </label>
          <div class="modal-actions">
            <button type="button" class="btn" :disabled="saving" @click="emailOpen = false">
              Cancelar
            </button>
            <button type="button" class="btn primary" :disabled="saving" @click="confirmarEmail">
              {{ saving ? 'Enviando…' : 'Enviar e imprimir' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <FacturasImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :documentos="a4Prep?.documentos ?? []"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="saving"
      oculto
      @cerrar="a4Open = false"
      @imprimir="imprimirDocumentos"
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
  grid-template-columns: 21rem minmax(0, 1fr);
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
  grid-template-columns: 6.2rem minmax(0, 1fr);
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
  vertical-align: top;
}
th.sel,
td.sel {
  width: 2rem;
  text-align: center;
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
}
.filtro-col:focus {
  outline: 2px solid #2563eb;
  outline-offset: -1px;
}
.col-fecha {
  width: 6.5rem;
}
.col-factura {
  width: 6rem;
}
.col-cliente {
  width: 7rem;
}
.col-nif {
  width: 7rem;
}
.col-importe {
  width: 6rem;
}
.col-cobro {
  width: 6rem;
}
.col-imp {
  width: 4rem;
}
.sin-filtro {
  color: #64748b;
  text-align: center;
  padding: 0.8rem;
}
.pie-grid {
  display: flex;
  justify-content: flex-end;
  flex-shrink: 0;
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
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 1400;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.45);
}
.modal {
  width: min(24rem, 100%);
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding: 1rem 1.1rem;
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.2);
}
.modal h3 {
  margin: 0;
  font-size: 1rem;
  color: #0f172a;
}
.modal-hint {
  margin: 0;
  font-size: 0.88rem;
  color: #334155;
}
.modal label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.8rem;
  color: #334155;
}
.modal input[type='email'] {
  padding: 0.4rem 0.5rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
}
.modal-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.4rem;
}
</style>
