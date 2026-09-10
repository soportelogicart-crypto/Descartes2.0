<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { generarFacturasAutomatico, previewGeneracionFacturas } from '@/api/facturacion'
import { api } from '@/api/client'
import type {
  FacturaGeneracionGrupoPreview,
  FacturaManualGenerada,
  FacturasGeneracionPreviewResponse,
  FacturasGeneracionResponse,
} from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

type Opt = { value: string; label: string }
type EntidadLupa = 'trabajadores' | 'clientes' | 'formas-pago'
type CampoLupa =
  | 'vendedorDesde'
  | 'vendedorHasta'
  | 'clienteDesde'
  | 'clienteHasta'
  | 'fpagoDesde'
  | 'fpagoHasta'

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

function fechaCorta(valor?: string | null) {
  const s = String(valor ?? '').trim()
  if (!s) return '—'
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
    const [y, m, d] = s.slice(0, 10).split('-')
    return `${d}/${m}/${y}`
  }
  return s
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

const buscarOpen = ref(false)
const buscarEntidad = ref<EntidadLupa>('clientes')
const buscarCampo = ref<CampoLupa>('clienteDesde')
const buscarInicial = ref('')

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'factura', label: 'Factura', clase: 'col-factura' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'nombre', label: 'Nombre', clase: 'col-nombre' },
  { key: 'albaran', label: 'Albarán', clase: 'col-alb' },
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
] as const

type ColumnaKey = (typeof COLUMNAS)[number]['key']

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return { factura: '', cliente: '', nombre: '', albaran: '', fecha: '', importe: '' }
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

function coincideFiltros(textos: Record<ColumnaKey, string>) {
  return filtrosColumnaActivos.value.every((f) =>
    normalizar(textos[f.key] ?? '').includes(f.valor)
  )
}

function textosGrupo(g: FacturaGeneracionGrupoPreview): Record<ColumnaKey, string> {
  return {
    factura: `Prevista ${g.indice}`,
    cliente: String(g.cliente ?? ''),
    nombre: String(g.razonSocial ?? ''),
    albaran: g.albaranes.map((a) => String(a.albaran)).join(' '),
    fecha: g.albaranes.map((a) => fechaCorta(a.fecha)).join(' '),
    importe: Number(g.importe ?? 0).toFixed(2),
  }
}

function textosGenerada(f: FacturaManualGenerada): Record<ColumnaKey, string> {
  return {
    factura: `${f.facturaTipo}/${f.factura}`,
    cliente: String(f.cliente ?? ''),
    nombre: String(f.razonSocial ?? ''),
    albaran: (f.albaranes ?? []).map((a) => String(a.albaran)).join(' '),
    fecha: (f.albaranes ?? []).map((a) => fechaCorta(a.fecha)).join(' '),
    importe: Number(f.importe ?? 0).toFixed(2),
  }
}

const gruposFiltrados = computed(() => {
  const grupos = preview.value?.grupos ?? []
  if (!hayFiltroColumna.value) return grupos
  return grupos.filter((g) => coincideFiltros(textosGrupo(g)))
})

const generadasFiltradas = computed(() => {
  if (!hayFiltroColumna.value) return generadas.value
  return generadas.value.filter((f) => coincideFiltros(textosGenerada(f)))
})

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

function emailDe(f: FacturaManualGenerada) {
  return (
    emails.value?.detalles.find(
      (d) =>
        d.factura === f.factura &&
        d.facturaTipo === f.facturaTipo &&
        d.empresa === f.empresa
    ) ?? null
  )
}

const TITULOS_LUPA: Record<CampoLupa, string> = {
  vendedorDesde: 'Buscar vendedor desde',
  vendedorHasta: 'Buscar vendedor hasta',
  clienteDesde: 'Buscar cliente desde',
  clienteHasta: 'Buscar cliente hasta',
  fpagoDesde: 'Buscar forma de pago desde',
  fpagoHasta: 'Buscar forma de pago hasta',
}

function abrirBuscar(entidad: EntidadLupa, campo: CampoLupa) {
  buscarEntidad.value = entidad
  buscarCampo.value = campo
  buscarInicial.value = String(form.value[campo] ?? '').trim()
  buscarOpen.value = true
}

function onLupaSeleccionado(sel: EntidadBuscarResultado) {
  buscarOpen.value = false
  const campo = buscarCampo.value
  form.value[campo] = sel.codigo
  // Rango con un solo valor: replicar en "Hasta" para no facturar de más.
  if (campo.endsWith('Desde')) {
    const hasta = campo.replace('Desde', 'Hasta') as CampoLupa
    if (!String(form.value[hasta] ?? '').trim()) form.value[hasta] = sel.codigo
  }
}

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
    filtrosColumna.value = filtrosColumnaVacios()
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
              <div class="con-lupa">
                <input v-model="form.vendedorDesde" type="text" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar vendedor desde"
                  @click="abrirBuscar('trabajadores', 'vendedorDesde')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <div class="con-lupa">
                <input v-model="form.vendedorHasta" type="text" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar vendedor hasta"
                  @click="abrirBuscar('trabajadores', 'vendedorHasta')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
            </div>
            <div class="rango-row">
              <span class="rango-label">Cliente</span>
              <div class="con-lupa">
                <input v-model="form.clienteDesde" type="text" maxlength="12" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar cliente desde"
                  @click="abrirBuscar('clientes', 'clienteDesde')"
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
                  @click="abrirBuscar('clientes', 'clienteHasta')"
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
            <div class="rango-row">
              <span class="rango-label">F.Pago</span>
              <div class="con-lupa">
                <input v-model="form.fpagoDesde" type="text" maxlength="4" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar forma de pago desde"
                  @click="abrirBuscar('formas-pago', 'fpagoDesde')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <div class="con-lupa">
                <input v-model="form.fpagoHasta" type="text" maxlength="4" />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar forma de pago hasta"
                  @click="abrirBuscar('formas-pago', 'fpagoHasta')"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
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

        <div v-if="preview && preview.grupos?.length" class="grid-wrap">
          <table class="resumen-grid">
            <thead>
              <tr>
                <th class="col-ind"></th>
                <th v-for="c in COLUMNAS" :key="c.key" :class="c.clase">
                  <span class="th-titulo" :class="{ num: c.num }">{{ c.label }}</span>
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
              <template v-for="g in gruposFiltrados" :key="'p-' + g.indice">
                <tr class="fila-factura">
                  <td class="col-ind">{{ g.indice }}</td>
                  <td>Prevista {{ g.indice }}</td>
                  <td>{{ g.cliente }}</td>
                  <td>
                    {{ g.razonSocial || '—' }}
                    <span v-if="g.sujetoPasivo" class="tag">SP</span>
                  </td>
                  <td>{{ g.albaranes.length }} alb.</td>
                  <td></td>
                  <td class="num">{{ g.importe.toFixed(2) }}</td>
                </tr>
                <tr v-for="a in g.albaranes" :key="'p-' + g.indice + '-' + a.empresa + '-' + a.albaran" class="fila-alb">
                  <td></td>
                  <td></td>
                  <td></td>
                  <td></td>
                  <td>{{ a.albaran }}</td>
                  <td>{{ fechaCorta(a.fecha) }}</td>
                  <td class="num">{{ a.importe.toFixed(2) }}</td>
                </tr>
              </template>
              <tr v-if="!gruposFiltrados.length">
                <td :colspan="COLUMNAS.length + 1" class="empty-filtro">
                  Ningún grupo con esos filtros
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="preview && hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ gruposFiltrados.length }} de {{ preview.grupos.length }}</span>
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>

        <div v-if="generadas.length" class="grid-wrap">
          <h3>Facturas generadas</h3>
          <table class="resumen-grid">
            <thead>
              <tr>
                <th class="col-ind"></th>
                <th v-for="c in COLUMNAS" :key="'g-' + c.key" :class="c.clase">
                  <span class="th-titulo" :class="{ num: c.num }">{{ c.label }}</span>
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
              <template
                v-for="(f, i) in generadasFiltradas"
                :key="'g-' + f.facturaTipo + '-' + f.factura"
              >
                <tr class="fila-factura">
                  <td class="col-ind">{{ i + 1 }}</td>
                  <td>{{ f.facturaTipo }}/{{ f.factura }}</td>
                  <td>{{ f.cliente }}</td>
                  <td>{{ f.razonSocial || '—' }}</td>
                  <td>{{ f.albaranes.length }} alb.</td>
                  <td>
                    <span v-if="emailDe(f)" class="email">
                      {{ emailDe(f)?.estado }}
                      <template v-if="emailDe(f)?.destinatario">
                        ({{ emailDe(f)?.destinatario }})
                      </template>
                    </span>
                  </td>
                  <td class="num">{{ f.importe.toFixed(2) }}</td>
                </tr>
                <tr
                  v-for="a in f.albaranes"
                  :key="'g-' + f.factura + '-' + a.empresa + '-' + a.albaran"
                  class="fila-alb"
                >
                  <td></td>
                  <td></td>
                  <td></td>
                  <td></td>
                  <td>{{ a.albaran }}</td>
                  <td>{{ fechaCorta(a.fecha) }}</td>
                  <td class="num">{{ a.importe != null ? a.importe.toFixed(2) : '' }}</td>
                </tr>
              </template>
              <tr v-if="!generadasFiltradas.length">
                <td :colspan="COLUMNAS.length + 1" class="empty-filtro">
                  Ninguna factura con esos filtros
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="generadas.length && hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ generadasFiltradas.length }} de {{ generadas.length }}</span>
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      :entidad="buscarEntidad"
      :titulo="TITULOS_LUPA[buscarCampo]"
      :busqueda-inicial="buscarInicial"
      :codigo-actual="String(form[buscarCampo] ?? '')"
      @seleccionar="onLupaSeleccionado"
      @cerrar="buscarOpen = false"
    />
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
.opciones label {
  display: grid;
  grid-template-columns: 6.2rem minmax(0, 1fr);
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
.btn-lupa :deep(.tool-icon) {
  width: 0.95rem;
  height: 0.95rem;
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
.grid-wrap {
  overflow: auto;
  min-height: 0;
  flex: 1;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}
.grid-wrap h3 {
  margin: 0;
  padding: 0.45rem 0.55rem;
  font-size: 0.9rem;
  background: #ecfdf5;
  border-bottom: 1px solid #d1fae5;
}
.resumen-grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
.resumen-grid th,
.resumen-grid td {
  border: 1px solid #e2e8f0;
  padding: 0.22rem 0.4rem;
  text-align: left;
  white-space: nowrap;
}
.resumen-grid th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  font-weight: 600;
  vertical-align: top;
}
.th-titulo {
  display: block;
  padding: 0 0.1rem 0.15rem;
  overflow: hidden;
  text-overflow: ellipsis;
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
.col-factura,
.col-alb,
.col-fecha {
  width: 6.5rem;
}
.col-cliente {
  width: 7rem;
}
.col-importe {
  width: 6rem;
}
.empty-filtro {
  text-align: center;
  color: #64748b;
  padding: 0.8rem;
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
.col-ind {
  width: 2rem;
  text-align: center;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.fila-factura {
  background: #eff6ff;
  font-weight: 600;
}
.fila-alb td {
  color: #475569;
  background: #fff;
}
.tag {
  margin-left: 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  color: #1d4ed8;
}
.email {
  font-weight: 400;
  color: #047857;
  font-size: 0.72rem;
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
</style>
