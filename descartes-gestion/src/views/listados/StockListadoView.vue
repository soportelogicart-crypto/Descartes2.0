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

const etiquetaGrupo = computed(() => {
  const t = def.value?.titulo ?? 'Stock'
  const m = t.match(/\(([^)]+)\)/)
  return m?.[1] ?? 'Grupo'
})

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
  }
}

function metaImpresion(): string[] {
  const lines: string[] = [def.value?.titulo ?? 'Stock']
  if (form.value.anoDesde.trim()) lines.push(`Año: ${form.value.anoDesde}`)
  if (form.value.mesDesde.trim()) lines.push(`Mes: ${form.value.mesDesde}`)
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

function formatoUnidades(n: number): string {
  const r = Math.round(n * 10000) / 10000
  return r.toLocaleString('es-ES', { maximumFractionDigits: 4 })
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
  if (!filas.length) return
  const cab = ['Código', etiquetaGrupo.value, 'Unidades', 'N.º artículos']
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [r.grupoCodigo, r.grupoNombre, numCsv(r.unidades, 4), r.numArticulos].map(escCsv).join(';'),
    )
  }
  if (resultado.value) {
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
    const res = await imprimirListadoHtml({
      titulo: def.value?.titulo ?? 'Stock',
      metaLineas: metaImpresion(),
      thead: ['Código', 'Descripción / nombre', 'Unidades', 'Artículos'],
      filas: filas.map((r) => [r.grupoCodigo, r.grupoNombre, formatoUnidades(r.unidades), r.numArticulos]),
      pie: resultado.value
        ? [`Total unidades: ${formatoUnidades(resultado.value.totales.unidades)}`, `${filas.length} filas`]
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

        <div v-if="tieneDatos" class="grid-wrap">
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
              <tr v-for="(row, i) in resultado!.items" :key="`${row.grupoCodigo}-${i}`">
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
