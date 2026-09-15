<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import {
  enviarFacturasManualEmail,
  generarAlbaranesPeriodicos,
  generarFacturasManual,
  listarFacturasManualPendientes,
  traspasoFacturasManual,
} from '@/api/facturacion'
import { api } from '@/api/client'
import type { FacturaManualPendiente, FacturaManualGenerada, FacturasManualPeriodicosResponse } from '@/types/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { useOrdenLista } from '@/composables/useOrdenCabeceraGrid'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import {
  imprimirFacturasPreparadas,
  prepararImpresionFacturas,
  type PrepImpresionFacturas,
} from '@/composables/useImpresionFacturaDocumento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import FacturasImpresionA4Modal from '@/components/facturacion/FacturasImpresionA4Modal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

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
const periodicosGenerados = ref<FacturasManualPeriodicosResponse['generados']>([])
const periodicosOmitidos = ref<NonNullable<FacturasManualPeriodicosResponse['omisiones']>>([])
const modalPeriodicos = ref(false)
const periodoDesde = ref('')
const periodoHasta = ref('')
const busyExtra = ref(false)
const buscarClienteOpen = ref(false)
const buscarClienteInicial = ref('')
const accionFactura = ref(false)
const a4Open = ref(false)
const a4Oculto = ref(false)
const a4Cargando = ref(false)
const a4Error = ref<string | null>(null)
const a4Prep = ref<PrepImpresionFacturas | null>(null)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const emailOpen = ref(false)
const emailDestino = ref('')
const emailFacturas = ref<FacturaManualGenerada[]>([])

function claveFactura(f: FacturaManualGenerada) {
  return { empresa: f.empresa, facturaTipo: f.facturaTipo, factura: f.factura }
}

const generadasImprimibles = computed(() => generadas.value.filter((f) => !f.prefactura))

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

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'albaran', label: 'Albarán', clase: 'col-alb' },
  { key: 'puesto', label: 'Pto', clase: 'col-pto' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'razonSocial', label: 'Razón social', clase: 'col-razon' },
  { key: 'nif', label: 'NIF', clase: 'col-nif' },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
  { key: 'pagoACuenta', label: 'A cuenta', clase: 'col-acuento', num: true },
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
  pagoACuenta: (r) => Number(r.pagoACuenta ?? 0).toFixed(2),
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
    pagoACuenta: '',
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

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

const seleccionados = computed(() => itemsFiltrados.value.filter((r) => selected.value[rowKey(r)]))
const totalSel = computed(() => seleccionados.value.length)
const importeSel = computed(
  () => Math.round(seleccionados.value.reduce((s, r) => s + r.importe, 0) * 100) / 100
)

const todosMarcados = computed(
  () =>
    itemsFiltrados.value.length > 0 && itemsFiltrados.value.every((r) => selected.value[rowKey(r)])
)

function toggleTodos(v: boolean) {
  const next: Record<string, boolean> = { ...selected.value }
  for (const r of itemsFiltrados.value) next[rowKey(r)] = v
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

function rutaVenta(empresa: string, tipo: string, albaran: number): string {
  return `/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
}

function fmtFechaIso(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = iso.slice(0, 10)
  const [y, m, day] = d.split('-')
  if (!y || !m || !day) return d
  return `${day}/${m}/${y}`
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
  periodicosGenerados.value = []
  periodicosOmitidos.value = []
  try {
    const data = await listarFacturasManualPendientes(paramsConsulta())
    const todos = data.items ?? []
    items.value = todos.slice(0, GRID_LIMITE_INICIAL)
    selected.value = {}
    mensaje.value = `${data.totales.albaranes} albaranes · ${data.totales.importe.toFixed(2)} €`
    if (todos.length > GRID_LIMITE_INICIAL) {
      mensaje.value += ` · mostrando ${GRID_LIMITE_INICIAL}`
    }
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
    const mensajeGeneracion = `Generadas ${result.totales.facturas} ${label} · ${result.totales.albaranes} albaranes · ${result.totales.importe.toFixed(2)} €`
    const facturasAhora = result.facturas
    await buscar()
    generadas.value = facturasAhora
    mensaje.value = mensajeGeneracion
    const imprimibles = facturasAhora.filter((f) => !f.prefactura)
    if (imprimibles.length > 0) {
      await verFacturas(imprimibles)
    }
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
  periodicosGenerados.value = []
  periodicosOmitidos.value = []
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
  generadas.value = []
  periodicosGenerados.value = []
  periodicosOmitidos.value = []
  try {
    const result = await generarAlbaranesPeriodicos({
      empresa: form.value.empresa.trim(),
      fechaDesde: periodoDesde.value,
      fechaHasta: periodoHasta.value,
    })
    modalPeriodicos.value = false
    const generadosAhora = result.generados ?? []
    const omitidosAhora = result.omisiones ?? []
    const mensajeGeneracion =
      result.totales.generados > 0
        ? `Generados ${result.totales.generados} albarán(es) periódico(s)` +
          (result.totales.omitidos ? ` · ${result.totales.omitidos} omitido(s)` : '')
        : `Ninguna de las ${result.totales.bases ?? 0} base(s) periódica(s) vence entre ` +
          `${fmtFechaIso(periodoDesde.value)} y ${fmtFechaIso(periodoHasta.value)}`
    // Refrescar los pendientes borra los resultados y el mensaje. Restaurarlos
    // después para que el usuario vea qué albaranes acaba de crear Gen.Alb.
    await buscar()
    periodicosGenerados.value = generadosAhora
    periodicosOmitidos.value = omitidosAhora
    mensaje.value = mensajeGeneracion
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar los albaranes periódicos')
  } finally {
    busyExtra.value = false
  }
}

function resumenEmail(r: { enviadas: number; omitidas: number; errores: number }) {
  const partes = [`Enviadas ${r.enviadas}`]
  if (r.omitidas) partes.push(`${r.omitidas} sin email`)
  if (r.errores) partes.push(`${r.errores} error(es)`)
  return partes.join(' · ')
}

async function esperarHtmlFolio(): Promise<string> {
  for (let i = 0; i < 40; i++) {
    await nextTick()
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    if (html) return html
    await new Promise((r) => setTimeout(r, 50))
  }
  return ''
}

async function prepararFacturasA4(lista: FacturaManualGenerada[]) {
  return prepararImpresionFacturas(lista.map(claveFactura), {
    puestoCodigo: String(puestoContexto.puestoCodigo ?? ''),
    origenDocumento: 'manual',
  })
}

async function verFacturas(lista: FacturaManualGenerada[]) {
  const sel = lista.filter((f) => !f.prefactura)
  if (sel.length === 0) {
    error.value = 'No hay facturas para previsualizar'
    return
  }
  accionFactura.value = true
  error.value = null
  a4Error.value = null
  a4Oculto.value = false
  a4Cargando.value = true
  a4Open.value = true
  try {
    a4Prep.value = await prepararFacturasA4(sel)
  } catch (e: unknown) {
    a4Error.value = extractApiError(e, 'No se pudo abrir la factura')
    error.value = a4Error.value
  } finally {
    a4Cargando.value = false
    accionFactura.value = false
  }
}

async function imprimirFacturas(lista: FacturaManualGenerada[]) {
  const sel = lista.filter((f) => !f.prefactura)
  if (sel.length === 0) {
    error.value = 'No hay facturas para imprimir'
    return
  }
  accionFactura.value = true
  error.value = null
  a4Error.value = null
  mensaje.value = 'Preparando impresión…'
  try {
    a4Prep.value = await prepararFacturasA4(sel)
    a4Oculto.value = true
    a4Open.value = true
    const html = await esperarHtmlFolio()
    if (!html) {
      throw new Error('No hay plantilla configurada para estas facturas en el puesto')
    }
    mensaje.value = await imprimirFacturasPreparadas(a4Prep.value, html)
    a4Open.value = false
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir la factura')
    mensaje.value = null
  } finally {
    accionFactura.value = false
  }
}

async function imprimirDocumentos() {
  const prep = a4Prep.value
  if (!prep || accionFactura.value) return
  accionFactura.value = true
  error.value = null
  try {
    const html = await esperarHtmlFolio()
    mensaje.value = await imprimirFacturasPreparadas(prep, html)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir')
  } finally {
    accionFactura.value = false
  }
}

function abrirEmail(lista: FacturaManualGenerada[]) {
  const sel = lista.filter((f) => !f.prefactura)
  if (sel.length === 0) {
    error.value = 'No hay facturas para enviar'
    return
  }
  emailFacturas.value = sel
  emailDestino.value = sel.length === 1 ? String(sel[0].email ?? '').trim() : ''
  emailOpen.value = true
  error.value = null
}

async function confirmarEmail() {
  const sel = emailFacturas.value
  if (sel.length === 0) return
  const email = emailDestino.value.trim()
  if (sel.length === 1 && !email) {
    error.value = 'Indique el email de destino'
    return
  }
  accionFactura.value = true
  error.value = null
  try {
    const result = await enviarFacturasManualEmail({
      facturas: sel.map((f) => ({
        empresa: f.empresa,
        facturaTipo: f.facturaTipo,
        factura: f.factura,
        cliente: f.cliente,
      })),
      email: email || undefined,
    })
    emailOpen.value = false
    mensaje.value = resumenEmail(result)
    if (result.errores > 0) {
      error.value = result.detalles.find((d) => d.estado === 'error')?.motivo ?? 'Error al enviar'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron enviar las facturas')
  } finally {
    accionFactura.value = false
  }
}

onMounted(async () => {
  await cargarOpciones()
  await buscar()
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
              <DecimalInput
                :model-value="form.numFactura === '' || form.numFactura == null ? null : Number(form.numFactura)"
                :empty-as-null="true"
                :integer="true"
                placeholder="Auto"
                :disabled="form.tipo === 'prefacturas'"
                @update:model-value="form.numFactura = $event ?? ''"
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
              <span>Listadas</span>
              <strong>{{ itemsFiltrados.length }}</strong>
            </div>
            <div class="importe">
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
          No hay albaranes pendientes con estas opciones e intervalos.
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
                <td>{{ r.albaran }}</td>
                <td>{{ r.puesto }}</td>
                <td>{{ r.cliente }}</td>
                <td class="clip">{{ r.razonSocial }}</td>
                <td>{{ r.nif }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td class="num">{{ r.pagoACuenta.toFixed(2) }}</td>
              </tr>
              <tr v-if="!itemsFiltrados.length">
                <td :colspan="COLUMNAS.length + 1" class="empty">Ningún albarán con esos filtros</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ itemsFiltrados.length }} de {{ items.length }}</span>
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>

        <div v-if="periodicosGenerados.length" class="generadas periodicos">
          <h3>Albaranes periódicos generados</h3>
          <ul>
            <li v-for="(g, i) in periodicosGenerados" :key="i">
              Albarán
              <router-link :to="rutaVenta(g.empresa, g.tipo, g.albaran)" class="link-venta">
                {{ g.tipo }}-{{ g.albaran }}
              </router-link>
              · plantilla {{ g.plantillaTipo || '?' }}-{{ g.plantilla }}
              · periodo {{ fmtFechaIso(g.fechaPeriodo) }}
            </li>
          </ul>
        </div>

        <div v-if="periodicosOmitidos.length" class="generadas omitidos">
          <h3>Bases periódicas que no han generado</h3>
          <p class="nota">
            Una base genera cuando su <strong>próxima generación</strong> cae dentro del rango
            indicado. La próxima fecha es la fecha base más un periodo.
          </p>
          <table class="tabla-omitidos">
            <thead>
              <tr>
                <th>Plantilla</th>
                <th>Cliente</th>
                <th>Próxima</th>
                <th>Motivo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(o, i) in periodicosOmitidos" :key="i">
                <td>{{ o.tipo }}-{{ o.albaran }}</td>
                <td class="clip">{{ o.razonSocial || o.cliente }}</td>
                <td>{{ o.proximaGeneracion ? fmtFechaIso(o.proximaGeneracion) : '—' }}</td>
                <td>{{ o.motivo }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="generadas.length" class="generadas">
          <div class="generadas-head">
            <h3>Facturas generadas</h3>
            <div v-if="generadasImprimibles.length" class="generadas-acciones">
              <button type="button" class="btn" :disabled="accionFactura" @click="verFacturas(generadasImprimibles)">
                {{ a4Open && !a4Oculto && a4Cargando ? 'Abriendo…' : 'Ver' }}
              </button>
              <button type="button" class="btn" :disabled="accionFactura" @click="imprimirFacturas(generadasImprimibles)">
                {{ accionFactura && a4Oculto ? 'Imprimiendo…' : 'Imprimir' }}
              </button>
              <button type="button" class="btn" :disabled="accionFactura" @click="abrirEmail(generadasImprimibles)">
                Enviar por email
              </button>
            </div>
          </div>
          <p v-if="generadasImprimibles.length" class="nota-acciones">
            Puede ver, imprimir o enviar cada factura, o todas a la vez.
          </p>
          <p v-if="error && generadas.length" class="error">{{ error }}</p>
          <table class="tabla-generadas">
            <thead>
              <tr>
                <th>Factura</th>
                <th>Cliente</th>
                <th class="num">Importe</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(f, i) in generadas" :key="i">
                <td>{{ f.facturaTipo }}/{{ f.factura }}</td>
                <td class="clip">{{ f.razonSocial || f.cliente }}</td>
                <td class="num">{{ Number(f.importe ?? 0).toFixed(2) }} €</td>
                <td class="acciones-fila" @click.stop>
                  <template v-if="!f.prefactura">
                    <button type="button" class="btn-mini" :disabled="accionFactura" @click="verFacturas([f])">
                      Ver
                    </button>
                    <button type="button" class="btn-mini" :disabled="accionFactura" @click="imprimirFacturas([f])">
                      Imprimir
                    </button>
                    <button type="button" class="btn-mini" :disabled="accionFactura" @click="abrirEmail([f])">
                      Email
                    </button>
                  </template>
                  <span v-else class="hint">Pre-factura</span>
                </td>
              </tr>
            </tbody>
          </table>
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
        <p class="hint">
          Se copiarán las plantillas de <code>AlbaranesPeriodicos</code> cuya próxima fecha caiga en el
          rango. Soporta plantillas Tipo <strong>P</strong> (presupuesto) y <strong>A</strong> (albarán).
        </p>
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

    <FacturasImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :documentos="a4Prep?.documentos ?? []"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="accionFactura && !a4Cargando"
      :cargando="a4Cargando"
      :error-carga="a4Error"
      :oculto="a4Oculto"
      @cerrar="a4Open = false"
      @imprimir="imprimirDocumentos"
    />

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
        <p v-if="emailFacturas.length === 1" class="hint">
          Factura {{ emailFacturas[0].facturaTipo }}/{{ emailFacturas[0].factura }}
          · {{ emailFacturas[0].razonSocial || emailFacturas[0].cliente }}
        </p>
        <p v-else class="hint">
          {{ emailFacturas.length }} facturas. Si deja el email vacío, cada una irá al correo del cliente.
        </p>
        <label>
          <span>{{ emailFacturas.length === 1 ? 'Dirección de email' : 'Email (opcional, el mismo para todas)' }}</span>
          <input v-model="emailDestino" type="email" placeholder="cliente@ejemplo.com" />
        </label>
        <div class="modal-actions">
          <button type="button" class="btn" :disabled="accionFactura" @click="emailOpen = false">
            Cancelar
          </button>
          <button type="button" class="btn primary" :disabled="accionFactura" @click="confirmarEmail">
            {{ accionFactura ? 'Enviando…' : 'Enviar' }}
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
  grid-template-columns: 6.2rem minmax(0, 1fr);
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
.resumen .importe {
  grid-column: 1 / -1;
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
  vertical-align: top;
}
th.sel,
td.sel {
  width: 2rem;
  text-align: center;
}
th.sel input[type='checkbox'],
td.sel input[type='checkbox'] {
  width: auto;
  height: auto;
  margin: 0;
  padding: 0;
  vertical-align: middle;
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
.col-pto {
  width: 4rem;
}
.col-cliente,
.col-nif {
  width: 7rem;
}
.col-importe,
.col-acuento {
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
  margin: 0;
  font-size: 0.9rem;
}
.generadas-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}
.generadas-acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}
.nota-acciones {
  margin: 0 0 0.4rem;
  font-size: 0.8rem;
  color: #065f46;
}
.tabla-generadas {
  font-size: 0.82rem;
}
.tabla-generadas th {
  background: #d1fae5;
  position: static;
}
.acciones-fila {
  white-space: nowrap;
  text-align: right;
}
.btn-mini {
  border: 1px solid #94a3b8;
  background: #fff;
  border-radius: 3px;
  padding: 0.15rem 0.45rem;
  font: inherit;
  font-size: 0.75rem;
  cursor: pointer;
  margin-left: 0.2rem;
}
.btn-mini:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.generadas ul {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}
.generadas.periodicos {
  border-color: #bfdbfe;
  background: #eff6ff;
}
.generadas.omitidos {
  border-color: #fde68a;
  background: #fffbeb;
}
.generadas .nota {
  margin: 0 0 0.4rem;
  font-size: 0.8rem;
  color: #78350f;
}
.tabla-omitidos {
  font-size: 0.8rem;
}
.tabla-omitidos th {
  background: #fef3c7;
  position: static;
}
.link-venta {
  font-weight: 600;
  color: #1d4ed8;
  text-decoration: none;
}
.link-venta:hover {
  text-decoration: underline;
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
