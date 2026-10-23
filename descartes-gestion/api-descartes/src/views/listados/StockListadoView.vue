<script setup lang="ts">
import { computed, onActivated, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  generarListadoStock,
  type StockAgruparPor,
  type StockListadoFila,
  type StockListadoParams,
  type StockListadoResult,
} from '@/api/listados'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import type { EntidadLookupId } from '@/config/entidad-lookup'
import {
  STOCK_ALMACENES_MODO,
  STOCK_DIVISAS,
  STOCK_FILTRO_EXISTENCIAS,
  STOCK_FORMATO_CLIENTE_AGRUPADO,
  STOCK_FORMATO_CLIENTE_ARTICULOS,
  STOCK_FORMATOS_AGRUPADO,
  STOCK_FORMATOS_ARTICULOS,
  STOCK_SI_NO,
  STOCK_TARIFAS,
  type StockFiltroExistencias,
} from '@/config/stock-listado-opciones'
import {
  stockListadoPorAgrupar,
  type StockIntervalDef,
  type StockListadoDef,
} from '@/config/stock-listado-config'
import {
  agruparPorDesdePathStock,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'
import { extractApiError } from '@/composables/useMantenimiento'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { useStockListadoSesionStore } from '@/stores/stockListadoSesion'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { useGridRenderLimit } from '@/composables/useGridRenderLimit'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { registrarReciente } = useListadosRecientes()
const sesionStock = useStockListadoSesionStore()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()

const generando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<StockListadoResult | null>(null)

const def = computed((): StockListadoDef | undefined => {
  const agrupar = esEstaInstanciaActiva()
    ? String(route.params.agruparPor ?? '')
    : agruparPorDesdePathStock(pathInstancia)
  return stockListadoPorAgrupar(agrupar)
})

const opcionesFormato = computed(() =>
  def.value?.variant === 'articulos' ? STOCK_FORMATOS_ARTICULOS : STOCK_FORMATOS_AGRUPADO,
)

const opcionesFormatoCliente = computed(() =>
  def.value?.variant === 'articulos' ? STOCK_FORMATO_CLIENTE_ARTICULOS : STOCK_FORMATO_CLIENTE_AGRUPADO,
)

const form = ref({
  divisa: 'EU',
  tipoBusqueda: 'todos' as StockFiltroExistencias,
  imArticulos: 'si',
  imprimirServicio: 'si',
  stockFiltro: 'todos' as StockFiltroExistencias,
  tarifa1: 'sin_valorar',
  tarifa2: 'sin_valorar',
  precio1: 'sin_valorar',
  precio2: 'sin_valorar',
  almacenesModo: 'desglosado',
  formato: 'normal',
  formatoCliente: 'si',
  /** Legacy: año/mes vacíos = no acotar periodo de [Stock] (no pre-rellenar el año en curso). */
  anoDesde: '',
  anoHasta: '',
  mesDesde: '',
  mesHasta: '',
  almacenDesde: '',
  almacenHasta: '',
  macrofamiliaDesde: '',
  macrofamiliaHasta: '',
  familiaDesde: '',
  familiaHasta: '',
  subfamiliaDesde: '',
  subfamiliaHasta: '',
  agrupacionDesde: '',
  agrupacionHasta: '',
  articuloDesde: '',
  articuloHasta: '',
  proveedorDesde: '',
  proveedorHasta: '',
  seccionDesde: '',
  seccionHasta: '',
  subseccionDesde: '',
  subseccionHasta: '',
  ultimaVentaDesde: '',
  ultimaVentaHasta: '',
  fechaAltaDesde: '',
  fechaAltaHasta: '',
  ultCompraDesde: '',
  ultCompraHasta: '',
  ubicacionDesde: '',
  ubicacionHasta: '',
})

const agruparAnterior = ref<string | null>(null)

function restaurarSesion(agruparPor: string): boolean {
  const cached = sesionStock.obtener(agruparPor)
  if (!cached) return false
  form.value = { ...cached.form }
  resultado.value = cached.resultado
  mensaje.value = cached.mensaje
  error.value = cached.error
  return true
}

function persistirSesion() {
  const d = def.value
  if (!d || !esEstaInstanciaActiva()) return
  sesionStock.guardar(d.agruparPor, {
    form: { ...form.value },
    resultado: resultado.value,
    mensaje: mensaje.value,
    error: error.value,
  })
}

watch(
  () =>
    esEstaInstanciaActiva()
      ? route.params.agruparPor
      : agruparPorDesdePathStock(pathInstancia),
  (agrupar) => {
    if (!esEstaInstanciaActiva()) return
    const key = String(agrupar ?? '')
    const d = stockListadoPorAgrupar(key)
    if (!d) {
      void router.replace({ path: '/listados/stock' })
      return
    }
    const cambioAgrupacion = agruparAnterior.value !== null && agruparAnterior.value !== key
    agruparAnterior.value = key
    const restaurado = restaurarSesion(d.agruparPor)
    if (!restaurado && cambioAgrupacion) {
      resultado.value = null
      mensaje.value = null
      error.value = null
    }
    registrarReciente('stock')
    if (!restaurado) {
      form.value.formatoCliente = d.variant === 'articulos' ? 'si' : 'no'
    }
  },
  { immediate: true },
)

onActivated(() => {
  if (!esEstaInstanciaActiva()) return
  const d = def.value
  if (d) restaurarSesion(d.agruparPor)
})

watch(
  form,
  () => {
    persistirSesion()
  },
  { deep: true },
)

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)
const filasListado = computed(() => resultado.value?.items ?? [])
const { gridEl, visibles, onScrollGrid } = useGridRenderLimit(filasListado)

const etiquetaGrupo = computed(() => {
  const t = def.value?.titulo ?? 'Stock'
  const m = t.match(/\(([^)]+)\)/)
  return m?.[1] ?? 'Grupo'
})

const TITULO_GRUPO: Record<string, string> = {
  familia: 'FAMILIA',
  subfamilia: 'SUBFAMILIA',
  macrofamilia: 'MACROFAMILIA',
  agrupacion: 'AGRUPACIÓN',
  proveedor: 'PROVEEDOR',
}

const tituloGrupo = computed(() => TITULO_GRUPO[def.value?.agruparPor ?? ''] ?? 'GRUPO')
const esDetalle = computed(() => resultado.value?.detalle === true)
const mostrarGrupos = computed(() => esDetalle.value && def.value?.agruparPor !== 'articulo')

type FilaDetalle =
  | { tipo: 'grupo'; clave: string; texto: string }
  | { tipo: 'articulo'; clave: string; row: StockListadoFila }
  | { tipo: 'total'; clave: string; etiqueta: string; unidades: number }

const filasDetalle = computed((): FilaDetalle[] => {
  const data = resultado.value
  if (!data?.detalle) return []
  const items = data.items
  if (!mostrarGrupos.value) {
    return items.map((row, i) => ({
      tipo: 'articulo' as const,
      clave: `a-${row.articulo ?? ''}-${i}`,
      row,
    }))
  }
  const out: FilaDetalle[] = []
  let i = 0
  while (i < items.length) {
    const codigo = items[i].grupoCodigo
    const nombre = items[i].grupoNombre
    const titulo = tituloGrupo.value
    out.push({
      tipo: 'grupo',
      clave: `g-${codigo}-${i}`,
      texto: `${titulo} ${codigo} ${nombre}`.replace(/\s+/g, ' ').trim(),
    })
    let suma = 0
    const inicio = i
    while (i < items.length && items[i].grupoCodigo === codigo) {
      const row = items[i]
      out.push({ tipo: 'articulo', clave: `a-${codigo}-${row.articulo ?? ''}-${i}`, row })
      suma += row.unidades
      i++
    }
    out.push({
      tipo: 'total',
      clave: `t-${codigo}-${inicio}`,
      etiqueta: `TOTAL ${titulo}`,
      unidades: suma,
    })
  }
  return out
})

const { gridEl: gridDetalleEl, visibles: visiblesDetalle, onScrollGrid: onScrollDetalle } =
  useGridRenderLimit(filasDetalle)

type CampoRango = `${string}Desde` | `${string}Hasta`

const buscarOpen = ref(false)
const buscarEntidad = ref<EntidadLookupId>('articulos')
const buscarCampo = ref<CampoRango>('articuloDesde')
const buscarInicial = ref('')

function valorCampo(campo: CampoRango): string {
  return (form.value as Record<string, string>)[campo] ?? ''
}

function asignarCampo(campo: CampoRango, v: string) {
  ;(form.value as Record<string, string>)[campo] = v
}

function abrirBuscar(interval: StockIntervalDef, lado: 'Desde' | 'Hasta') {
  if (!interval.entidad) return
  const campo = `${interval.key}${lado}` as CampoRango
  buscarCampo.value = campo
  buscarEntidad.value = interval.entidad
  buscarInicial.value = valorCampo(campo)
  buscarOpen.value = true
}

function espejarDesdeEnHasta(campoDesde: CampoRango, codigo: string) {
  asignarCampo(campoDesde, codigo)
  if (campoDesde.endsWith('Desde')) {
    const hasta = `${campoDesde.slice(0, -5)}Hasta` as CampoRango
    asignarCampo(hasta, codigo)
  }
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  espejarDesdeEnHasta(buscarCampo.value, r.codigo.trim())
  buscarOpen.value = false
}

/** Legacy: al indicar «desde» (lupa o texto), «hasta» toma el mismo valor. */
watch(
  () => {
    const d = def.value
    if (!d) return ''
    return d.intervalos
      .filter((i) => i.entidad)
      .map((i) => (form.value as Record<string, string>)[`${i.key}Desde`] ?? '')
      .join('\0')
  },
  () => {
    for (const i of def.value?.intervalos ?? []) {
      if (!i.entidad) continue
      const desde = (form.value as Record<string, string>)[`${i.key}Desde`] ?? ''
      ;(form.value as Record<string, string>)[`${i.key}Hasta`] = desde
    }
  },
)

function parseIntOpt(v: string): number | undefined {
  const t = v.trim()
  if (!t) return undefined
  const n = Number.parseInt(t, 10)
  return Number.isFinite(n) && n > 0 ? n : undefined
}

function trimOpt(v: string): string | undefined {
  const t = v.trim()
  return t || undefined
}

function filtroExistenciasActivo(): StockFiltroExistencias {
  return def.value?.variant === 'articulos' ? form.value.tipoBusqueda : form.value.stockFiltro
}

function paramsConsulta(): StockListadoParams {
  const agruparPor = def.value?.agruparPor ?? ('articulo' as StockAgruparPor)
  const stockFiltro = filtroExistenciasActivo()

  return {
    agruparPor,
    ocultarCero: stockFiltro === 'superior_0',
    stockFiltro,
    anoDesde: parseIntOpt(form.value.anoDesde),
    mesDesde: parseIntOpt(form.value.mesDesde),
    almacenDesde: parseIntOpt(form.value.almacenDesde),
    almacenHasta: parseIntOpt(form.value.almacenHasta),
    macrofamiliaDesde: trimOpt(form.value.macrofamiliaDesde),
    macrofamiliaHasta: trimOpt(form.value.macrofamiliaHasta),
    familiaDesde: trimOpt(form.value.familiaDesde),
    familiaHasta: trimOpt(form.value.familiaHasta),
    subfamiliaDesde: trimOpt(form.value.subfamiliaDesde),
    subfamiliaHasta: trimOpt(form.value.subfamiliaHasta),
    agrupacionDesde: trimOpt(form.value.agrupacionDesde),
    agrupacionHasta: trimOpt(form.value.agrupacionHasta),
    articuloDesde: trimOpt(form.value.articuloDesde),
    articuloHasta: trimOpt(form.value.articuloHasta),
    proveedorDesde: trimOpt(form.value.proveedorDesde),
    proveedorHasta: trimOpt(form.value.proveedorHasta),
    seccionDesde: trimOpt(form.value.seccionDesde),
    seccionHasta: trimOpt(form.value.seccionHasta),
    subseccionDesde: trimOpt(form.value.subseccionDesde),
    subseccionHasta: trimOpt(form.value.subseccionHasta),
    ultimaVentaDesde: trimOpt(form.value.ultimaVentaDesde),
    ultimaVentaHasta: trimOpt(form.value.ultimaVentaHasta),
    fechaAltaDesde: trimOpt(form.value.fechaAltaDesde),
    fechaAltaHasta: trimOpt(form.value.fechaAltaHasta),
    ultCompraDesde: trimOpt(form.value.ultCompraDesde),
    ultCompraHasta: trimOpt(form.value.ultCompraHasta),
    ubicacionDesde: trimOpt(form.value.ubicacionDesde),
    ubicacionHasta: trimOpt(form.value.ubicacionHasta),
    imArticulos: def.value?.variant === 'articulos' ? 'si' : form.value.imArticulos,
  }
}

function metaImpresion(): string[] {
  const lines: string[] = [def.value?.titulo ?? 'Stock']
  const ano = resultado.value?.ano
  if (ano) lines.push(`Año: ${ano}`)
  else if (form.value.anoDesde.trim()) lines.push(`Año: ${form.value.anoDesde}`)
  if (form.value.mesDesde.trim()) lines.push(`Mes: ${form.value.mesDesde}`)
  const alm = resultado.value?.almacen
  if (alm) lines.push(`Almacén: ${alm} ${resultado.value?.almacenNombre ?? ''}`.trim())
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

function formatoUnidades(n: number): string {
  const r = Math.round(n * 10000) / 10000
  return r.toLocaleString('es-ES', { maximumFractionDigits: 4 })
}

function formatoImporte(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function focusablesPanel(formEl: HTMLElement): HTMLElement[] {
  return Array.from(formEl.querySelectorAll<HTMLElement>('input:not([disabled]), select:not([disabled])')).filter(
    (el) => el.getClientRects().length > 0,
  )
}

function onPanelEnterNav(e: KeyboardEvent) {
  if (e.key !== 'Enter' && e.key !== 'NumpadEnter') return
  if (e.isComposing || buscarOpen.value) return
  const target = e.target
  if (!(target instanceof HTMLElement)) return
  const formEl = e.currentTarget
  if (!(formEl instanceof HTMLFormElement)) return
  const list = focusablesPanel(formEl)
  const idx = list.indexOf(target)
  if (idx === -1) return
  e.preventDefault()
  if (idx < list.length - 1) {
    const next = list[idx + 1]
    next.focus()
    if (next instanceof HTMLInputElement && next.type !== 'date') next.select()
    return
  }
  formEl.requestSubmit()
}

let seq = 0
async function generar() {
  if (!def.value) return
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarListadoStock(paramsConsulta())
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Sin datos con los filtros actuales.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran las primeras ${data.limite} filas. Acote intervalos si necesita el listado completo.`
    } else if (data.detalle) {
      mensaje.value = `${data.items.length} artículo(s). Stock: ${formatoUnidades(data.totales.unidades)}`
    } else {
      mensaje.value = `${data.items.length} fila(s). Total unidades: ${formatoUnidades(data.totales.unidades)}`
    }
    persistirSesion()
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el listado')
    resultado.value = null
    persistirSesion()
  } finally {
    if (id === seq) generando.value = false
  }
}

function filasParaExport(): StockListadoFila[] {
  return resultado.value?.items ?? []
}

function exportarExcel() {
  const filas = filasParaExport()
  if (!filas.length || !resultado.value) return
  const lines: string[] = []
  if (resultado.value.detalle) {
    lines.push(
      ['Artículo', 'Descripción', 'P.Medio', 'Stock', 'Uni', 'Emp. Uni', 'Valor PM', 'Valor PU']
        .map(escCsv)
        .join(';'),
    )
    for (const fila of filasDetalle.value) {
      if (fila.tipo === 'grupo') {
        lines.push([fila.texto, '', '', '', '', '', '', ''].map(escCsv).join(';'))
      } else if (fila.tipo === 'total') {
        lines.push(['', fila.etiqueta, '', numCsv(fila.unidades, 4), '', '', '', ''].map(escCsv).join(';'))
      } else {
        const r = fila.row
        lines.push(
          [
            r.articulo ?? '',
            r.descripcion ?? '',
            numCsv(r.precioMedio ?? 0, 2),
            numCsv(r.unidades, 4),
            r.unidad ?? '',
            r.unidadEmpaquetado ?? '',
            numCsv(r.valorPm ?? 0, 2),
            numCsv(r.valorPu ?? 0, 2),
          ]
            .map(escCsv)
            .join(';'),
        )
      }
    }
    lines.push(
      ['', 'TOTAL ALMACEN', '', numCsv(resultado.value.totales.unidades, 4), '', '', '', '']
        .map(escCsv)
        .join(';'),
    )
  } else {
    const cab = ['Código', etiquetaGrupo.value, 'Unidades', 'N.º artículos']
    lines.push(cab.map(escCsv).join(';'))
    for (const r of filas) {
      lines.push(
        [r.grupoCodigo, r.grupoNombre, numCsv(r.unidades, 4), r.numArticulos ?? 0].map(escCsv).join(';'),
      )
    }
    lines.push('')
    lines.push(['TOTAL', '', numCsv(resultado.value.totales.unidades, 4), ''].map(escCsv).join(';'))
  }
  descargarCsv(`stock-${def.value?.agruparPor ?? 'listado'}.csv`, lines)
  mensaje.value = `Excel (CSV) de ${filas.length} fila(s)`
}

async function imprimir() {
  const filas = filasParaExport()
  if (!filas.length) return
  try {
    const detalle = resultado.value?.detalle === true
    const filasImpresion: (string | number)[][] = detalle
      ? [
          ...filasDetalle.value.map((fila) => {
            if (fila.tipo === 'grupo') return [fila.texto, '', '', '', '', '', '']
            if (fila.tipo === 'total') return [fila.etiqueta, '', formatoUnidades(fila.unidades), '', '', '', '']
            const r = fila.row
            return [
              `${r.articulo ?? ''} ${r.descripcion ?? ''}`.trim(),
              formatoImporte(r.precioMedio ?? 0),
              formatoUnidades(r.unidades),
              r.unidad ?? '',
              r.unidadEmpaquetado ?? '',
              formatoImporte(r.valorPm ?? 0),
              formatoImporte(r.valorPu ?? 0),
            ]
          }),
          ['TOTAL ALMACEN', '', formatoUnidades(resultado.value?.totales.unidades ?? 0), '', '', '', ''],
        ]
      : filas.map((r) => [r.grupoCodigo, r.grupoNombre, formatoUnidades(r.unidades), r.numArticulos ?? 0])
    const res = await imprimirListadoHtml({
      titulo: def.value?.titulo ?? 'Stock',
      metaLineas: metaImpresion(),
      orientacion: detalle ? 'horizontal' : undefined,
      thead: detalle
        ? ['Artículo', 'P.Medio', 'Stock', 'Uni', 'Emp. Uni', 'Valor PM', 'Valor PU']
        : [
            'Código',
            def.value?.agruparPor === 'articulo' ? 'Descripción' : 'Nombre',
            'Unidades',
            'Artículos',
          ],
      columnas: detalle
        ? [
            { ancho: '34%', alineacion: 'left' },
            { ancho: '11%', alineacion: 'right' },
            { ancho: '11%', alineacion: 'right' },
            { ancho: '8%', alineacion: 'left' },
            { ancho: '10%', alineacion: 'left' },
            { ancho: '13%', alineacion: 'right' },
            { ancho: '13%', alineacion: 'right' },
          ]
        : [
            { ancho: '16%', alineacion: 'left' },
            { ancho: '54%', alineacion: 'left' },
            { ancho: '15%', alineacion: 'right' },
            { ancho: '15%', alineacion: 'right' },
          ],
      filas: filasImpresion,
      pie: resultado.value
        ? [`Total stock: ${formatoUnidades(resultado.value.totales.unidades)}`, `${filas.length} filas`]
        : undefined,
      filenameFallback: 'stock.html',
    })
    if (res.ok) {
      error.value = null
      mensaje.value = res.message
    } else {
      mensaje.value = null
      error.value = res.message
    }
    persistirSesion()
  } catch (e: unknown) {
    mensaje.value = null
    error.value = extractApiError(e, 'No se pudo imprimir el listado')
    persistirSesion()
  }
}

function inputType(interval: StockIntervalDef): string {
  if (interval.input === 'date') return 'date'
  if (interval.input === 'number') return 'text'
  return 'text'
}

function volverAlSelector() {
  void router.push({ path: '/listados/stock' })
}

</script>

<template>
  <section v-if="def" class="ventas-view stock-form-view">
    <div class="head head-compact">
      <div>
        <button type="button" class="volver-hub" @click="volverAlSelector">← Listado de stock</button>
        <h2>{{ def.titulo }}</h2>
      </div>
      <div class="head-actions">
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="exportarExcel">Excel</button>
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="imprimir">Imprimir</button>
      </div>
    </div>

    <div class="layout-busqueda layout-stock">
      <form class="panel-filtros panel-filtros-compact" @submit.prevent="generar" @keydown="onPanelEnterNav">
        <fieldset class="bloque-opciones opciones-fila">
          <legend>Opciones</legend>
          <template v-if="def.variant === 'articulos'">
            <label>
              <span>Tipo de búsqueda</span>
              <select v-model="form.tipoBusqueda">
                <option v-for="o in STOCK_FILTRO_EXISTENCIAS" :key="'tb-' + o.value" :value="o.value">
                  {{ o.label }}
                </option>
              </select>
            </label>
            <label>
              <span>Precio</span>
              <select v-model="form.precio1">
                <option v-for="o in STOCK_TARIFAS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Precio</span>
              <select v-model="form.precio2">
                <option v-for="o in STOCK_TARIFAS" :key="'p2-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
          </template>
          <template v-else>
            <label>
              <span>Divisa</span>
              <select v-model="form.divisa" disabled>
                <option v-for="o in STOCK_DIVISAS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Im. artículos</span>
              <select v-model="form.imArticulos">
                <option v-for="o in STOCK_SI_NO" :key="'ia-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Imprimir servicio</span>
              <select v-model="form.imprimirServicio">
                <option v-for="o in STOCK_SI_NO" :key="'is-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Stock</span>
              <select v-model="form.stockFiltro">
                <option v-for="o in STOCK_FILTRO_EXISTENCIAS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Tarifa</span>
              <select v-model="form.tarifa1">
                <option v-for="o in STOCK_TARIFAS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
            <label>
              <span>Tarifa</span>
              <select v-model="form.tarifa2">
                <option v-for="o in STOCK_TARIFAS" :key="'t2-' + o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </label>
          </template>
          <label>
            <span>Almacenes</span>
            <select v-model="form.almacenesModo">
              <option v-for="o in STOCK_ALMACENES_MODO" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label>
            <span>Formato</span>
            <select v-model="form.formato">
              <option v-for="o in opcionesFormato" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label>
            <span>Formato cliente</span>
            <select v-model="form.formatoCliente">
              <option v-for="o in opcionesFormatoCliente" :key="'fc-' + o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
        </fieldset>

        <fieldset class="bloque-intervalos bloque-intervalos-col">
          <legend>Intervalos</legend>
          <div class="intervalos-col">
            <div class="rango-head">
              <span />
              <span>Desde</span>
              <span>Hasta</span>
            </div>
            <div v-for="row in def.intervalos" :key="row.key" class="rango-row">
              <span class="rango-label">{{ row.label }}</span>
              <div
                class="celda-intervalo"
                :class="{ 'celda-intervalo-valor': row.modo === 'valor' }"
              >
                <div v-if="row.entidad" class="con-lupa">
                  <input
                    :value="valorCampo(`${row.key}Desde` as CampoRango)"
                    maxlength="20"
                    @input="asignarCampo(`${row.key}Desde` as CampoRango, ($event.target as HTMLInputElement).value)"
                  />
                  <button type="button" class="btn-lupa" :title="`Buscar ${row.label} desde`" @click="abrirBuscar(row, 'Desde')">
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <DecimalInput
                  v-else-if="row.input === 'number' && row.key === 'mes'"
                  :model-value="form.mesDesde === '' ? null : Number(form.mesDesde)"
                  :empty-as-null="true"
                  :integer="true"
                  @update:model-value="form.mesDesde = $event != null ? String($event) : ''"
                />
                <DecimalInput
                  v-else-if="row.input === 'number' && row.key === 'ano'"
                  :model-value="form.anoDesde === '' ? null : Number(form.anoDesde)"
                  :empty-as-null="true"
                  :integer="true"
                  @update:model-value="form.anoDesde = $event != null ? String($event) : ''"
                />
                <DecimalInput
                  v-else-if="row.input === 'number' && row.key === 'almacen'"
                  :model-value="form.almacenDesde === '' ? null : Number(form.almacenDesde)"
                  :empty-as-null="true"
                  :integer="true"
                  @update:model-value="form.almacenDesde = $event != null ? String($event) : ''"
                />
                <input
                  v-else
                  :type="inputType(row)"
                  :value="valorCampo(`${row.key}Desde` as CampoRango)"
                  @input="asignarCampo(`${row.key}Desde` as CampoRango, ($event.target as HTMLInputElement).value)"
                />
              </div>
              <div v-if="row.modo !== 'valor'" class="celda-intervalo">
                <div v-if="row.entidad" class="con-lupa">
                  <input
                    :value="valorCampo(`${row.key}Hasta` as CampoRango)"
                    maxlength="20"
                    @input="asignarCampo(`${row.key}Hasta` as CampoRango, ($event.target as HTMLInputElement).value)"
                  />
                  <button type="button" class="btn-lupa" :title="`Buscar ${row.label} hasta`" @click="abrirBuscar(row, 'Hasta')">
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <DecimalInput
                  v-else-if="row.input === 'number' && row.key === 'almacen'"
                  :model-value="form.almacenHasta === '' ? null : Number(form.almacenHasta)"
                  :empty-as-null="true"
                  :integer="true"
                  @update:model-value="form.almacenHasta = $event != null ? String($event) : ''"
                />
                <input
                  v-else-if="row.input !== 'number' || (row.key !== 'ano' && row.key !== 'mes')"
                  :type="inputType(row)"
                  :value="valorCampo(`${row.key}Hasta` as CampoRango)"
                  @input="asignarCampo(`${row.key}Hasta` as CampoRango, ($event.target as HTMLInputElement).value)"
                />
              </div>
            </div>
          </div>
        </fieldset>

        <button type="submit" class="btn-buscar btn-buscar-compact" :disabled="generando">
          {{ generando ? 'Generando…' : 'Generar' }}
        </button>
      </form>

      <div class="panel-listado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <p v-if="generando" class="msg">Generando listado…</p>
        <p v-if="resultado?.truncado" class="msg">
          Se muestran como máximo {{ resultado.limite }} filas; acote intervalos.
        </p>

        <p v-if="esDetalle && (resultado?.ano || resultado?.almacen)" class="contexto-stock">
          <span v-if="resultado?.ano">Año {{ resultado.ano }}</span>
          <span v-if="resultado?.almacen">Almacén {{ resultado.almacen }} {{ resultado.almacenNombre }}</span>
        </p>

        <div
          v-if="tieneDatos && esDetalle"
          ref="gridDetalleEl"
          class="grid-wrap"
          @scroll.passive="onScrollDetalle"
        >
          <table class="grid">
            <thead>
              <tr>
                <th>Artículo</th>
                <th class="num">P.Medio</th>
                <th class="num">Stock</th>
                <th>Uni</th>
                <th>Emp. Uni</th>
                <th class="num">Valor PM</th>
                <th class="num">Valor PU</th>
              </tr>
            </thead>
            <tbody>
              <template v-for="fila in visiblesDetalle" :key="fila.clave">
                <tr v-if="fila.tipo === 'grupo'" class="fila-grupo">
                  <td colspan="7">{{ fila.texto }}</td>
                </tr>
                <tr v-else-if="fila.tipo === 'total'" class="fila-total">
                  <td colspan="2">{{ fila.etiqueta }}</td>
                  <td class="num">{{ formatoUnidades(fila.unidades) }}</td>
                  <td colspan="4" />
                </tr>
                <tr v-else-if="fila.tipo === 'articulo'">
                  <td>
                    <span class="cod-art">{{ fila.row.articulo }}</span>
                    {{ fila.row.descripcion }}
                  </td>
                  <td class="num">{{ formatoImporte(fila.row.precioMedio ?? 0) }}</td>
                  <td class="num">{{ formatoUnidades(fila.row.unidades) }}</td>
                  <td>{{ fila.row.unidad }}</td>
                  <td>{{ fila.row.unidadEmpaquetado }}</td>
                  <td class="num">{{ formatoImporte(fila.row.valorPm ?? 0) }}</td>
                  <td class="num">{{ formatoImporte(fila.row.valorPu ?? 0) }}</td>
                </tr>
              </template>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2"><strong>TOTAL ALMACEN</strong></td>
                <td class="num"><strong>{{ formatoUnidades(resultado!.totales.unidades) }}</strong></td>
                <td colspan="4" />
              </tr>
            </tfoot>
          </table>
        </div>

        <div v-else-if="tieneDatos" ref="gridEl" class="grid-wrap" @scroll.passive="onScrollGrid">
          <table class="grid">
            <thead>
              <tr>
                <th>Código</th>
                <th>{{ def.agruparPor === 'articulo' ? 'Descripción' : 'Nombre' }}</th>
                <th class="num">Unidades</th>
                <th class="num">Artículos</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(row, i) in visibles" :key="`${row.grupoCodigo}-${i}`">
                <td>{{ row.grupoCodigo }}</td>
                <td>{{ row.grupoNombre }}</td>
                <td class="num">{{ formatoUnidades(row.unidades) }}</td>
                <td class="num">{{ row.numArticulos }}</td>
              </tr>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="2"><strong>Totales</strong></td>
                <td class="num"><strong>{{ formatoUnidades(resultado!.totales.unidades) }}</strong></td>
                <td class="num"><strong>{{ resultado!.totales.filas }}</strong></td>
              </tr>
            </tfoot>
          </table>
        </div>
        <p v-else-if="resultado && !generando" class="empty">No hay filas que mostrar.</p>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      :entidad="buscarEntidad"
      :busqueda-inicial="buscarInicial"
      @seleccionar="onEntidadSeleccionada"
      @cerrar="buscarOpen = false"
    />
  </section>
</template>

<style scoped>
@import './listado-grid.css';
@import './listado-informe-layout.css';

.stock-form-view .head-compact {
  margin-bottom: 0.35rem;
}

.volver-hub {
  display: inline-block;
  margin-bottom: 0.15rem;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-size: 0.72rem;
  color: #2563eb;
  cursor: pointer;
  text-align: left;
}

.volver-hub:hover {
  text-decoration: underline;
}

.stock-form-view .head-compact h2 {
  font-size: 1.05rem;
}

.stock-form-view {
  --stock-col-etiq: 6.25rem;
}

.contexto-stock {
  display: flex;
  gap: 1.25rem;
  margin: 0 0 0.4rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: #1e293b;
}

.cod-art {
  display: inline-block;
  min-width: 4.5rem;
  margin-right: 0.35rem;
  font-variant-numeric: tabular-nums;
}

.fila-grupo td {
  background: #e2e8f0;
  font-weight: 700;
  color: #0f172a;
}

.fila-total td {
  font-weight: 700;
  border-top: 1px solid #94a3b8;
}

.layout-stock {
  grid-template-columns: minmax(17rem, 22rem) minmax(0, 1fr);
  align-items: start;
}

.panel-filtros-compact {
  max-height: none;
  overflow: visible;
  padding: 0.35rem 0.4rem;
  gap: 0.3rem;
}

.panel-filtros-compact fieldset {
  padding: 0.3rem 0.35rem 0.35rem;
}

.panel-filtros-compact legend {
  font-size: 0.68rem;
}

.opciones-fila {
  display: flex;
  flex-direction: column;
  gap: 0.22rem;
}

.opciones-fila label {
  display: grid !important;
  grid-template-columns: var(--stock-col-etiq) minmax(0, 1fr);
  align-items: center;
  gap: 0.35rem;
  flex-direction: row !important;
  font-size: 0.75rem !important;
  color: #475569 !important;
}

.opciones-fila label > span {
  text-align: right;
  line-height: 1.2;
}

.opciones-fila select {
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.2rem 0.35rem !important;
  font-size: 0.78rem !important;
}

.bloque-intervalos-col {
  padding-left: 0;
  padding-right: 0;
}

/* Una sola rejilla: etiqueta | desde | hasta alineados en todas las filas. */
.intervalos-col {
  display: grid;
  grid-template-columns: var(--stock-col-etiq) minmax(0, 1fr) minmax(0, 1fr);
  column-gap: 0.35rem;
  row-gap: 0.22rem;
  align-items: center;
  width: 100%;
}

.intervalos-col .rango-head,
.intervalos-col .rango-row {
  display: contents;
}

.intervalos-col .rango-head span:nth-child(2),
.intervalos-col .rango-head span:nth-child(3) {
  font-size: 0.72rem;
  color: #64748b;
  text-align: center;
  padding-bottom: 0.05rem;
}

.intervalos-col .rango-label {
  text-align: right;
  font-size: 0.75rem;
  line-height: 1.2;
  color: #475569;
  padding-right: 0.05rem;
}

.celda-intervalo {
  min-width: 0;
  display: flex;
  align-items: center;
}

.celda-intervalo-valor {
  grid-column: 2 / 4;
}

.celda-intervalo input,
.celda-intervalo :deep(input) {
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

.intervalos-col .con-lupa {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  width: 100%;
  min-width: 0;
}

.intervalos-col .con-lupa input {
  flex: 1;
  min-width: 0;
}

.intervalos-col .btn-lupa {
  width: 1.35rem;
  height: 1.35rem;
  flex-shrink: 0;
}

.btn-buscar-compact {
  margin-top: 0.1rem;
  padding: 0.32rem 0.55rem;
  font-size: 0.82rem;
}

.grid-wrap {
  flex: 1;
  min-height: 8rem;
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}

@media (max-width: 1100px) {
  .layout-stock {
    grid-template-columns: minmax(17rem, 22rem) minmax(0, 1fr);
  }
}
</style>
