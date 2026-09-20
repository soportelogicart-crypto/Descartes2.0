<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { obtenerAbcVentas } from '@/api/ventas'
import type {
  AbcVentasBloque,
  AbcVentasFiltros,
  AbcVentasGrupo,
  AbcVentasResponse,
  AbcVentasTotales,
} from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import {
  abcFiltroSoloDigitos,
  ABC_VENTAS_INTERVALOS_AGRUPACIONES,
  ABC_VENTAS_INTERVALOS_ARTICULOS,
  ABC_VENTAS_INTERVALOS_CLIENTES,
  ABC_VENTAS_INTERVALOS_PERFILES,
  ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES,
  ABC_VENTAS_INTERVALOS_DIAS_SEMANA,
  ABC_VENTAS_INTERVALOS_SEMANAL,
  ABC_VENTAS_INTERVALOS_VENDEDORES,
  abcVentasIntervalosPorDimension,
  type AbcFiltroRango,
} from '@/config/abc-ventas-filtros'
import {
  ABC_VENTAS_AGRUPACION_ARTICULOS,
  ABC_VENTAS_AGRUPACION_CLIENTES,
  ABC_VENTAS_DIVISAS,
  ABC_VENTAS_FORMATO,
  ABC_VENTAS_FORMATO_ARTICULOS,
  ABC_VENTAS_AGRUPACION_HORAS,
  ABC_VENTAS_DESGLOSE_SEMANAL,
  ABC_VENTAS_DIA_SEMANA_ABC,
  ABC_VENTAS_GRAFICO_POR_DIAS_SEMANA,
  ABC_VENTAS_FORMATO_JERARQUIA,
  ABC_VENTAS_GRAFICO_POR_HORAS,
  ABC_VENTAS_IM_ARTICULOS,
  ABC_VENTAS_INTERVALO_HORAS,
  ABC_VENTAS_TIPO_GESTION_HORAS,
  ABC_VENTAS_IMPRIMIR_PROVEEDOR,
  ABC_VENTAS_IVA,
  abcVentasFormatoArticulosMuestraComision,
  abcVentasMuestraImprimirProveedor,
  abcVentasEtiquetaUnidadesJerarquia,
  abcVentasMuestraOpcionesArticulos,
  abcVentasMuestraOpcionesClientes,
  abcVentasDimensionGrupoDesdeAgrupacionHoras,
  abcVentasMuestraFormatoFamilias,
  abcVentasMuestraOpcionesHoras,
  abcVentasMuestraOpcionesDiasSemana,
  abcVentasMuestraOpcionesSemanal,
  abcVentasOcultaImArticulos,
  abcVentasOcultaOrdenInforme,
  abcVentasOrdenDefectoPorDimension,
  abcVentasOrdenPorDimension,
  ABC_VENTAS_TIPO_VENTA,
  ABC_VENTAS_VALOR,
} from '@/config/abc-ventas-opciones'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import type { EntidadLookupId } from '@/config/entidad-lookup'
import { computeAbcHorasGrafico } from '@/composables/abcVentasHorasGrafico'
import {
  abcVentasOcultaColumnaMAgr,
  abcVentasPjeSobreTotalGrupo,
  abcVentasUsaInformePlanoHorasAbc,
  abcVentasUsaTablaPlanaLegacy,
  construirHtmlInformeAbcVentas,
} from '@/composables/abcVentasInformeHtml'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import {
  abcVentasListadoPorDimension,
  etiquetaBloqueGrupoAbc,
  etiquetaDimensionAbc,
  etiquetaTotalGrupoAbc,
  type AbcVentasDimensionId,
} from '@/config/abc-ventas-dimensiones'
import {
  dimensionDesdePathAbcVentas,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'

const route = useRoute()
const router = useRouter()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()

const dimensionEfectiva = computed(() => {
  if (esEstaInstanciaActiva()) {
    return String(route.params.dimension ?? '').trim()
  }
  return dimensionDesdePathAbcVentas(pathInstancia)
})

const defListado = computed(() => abcVentasListadoPorDimension(dimensionEfectiva.value))

const loading = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const data = ref<AbcVentasResponse | null>(null)

const form = ref<AbcVentasFiltros>({
  dimension: 'vendedores',
  orden: 'margen',
  divisa: 'EU',
  iva: 'incluido',
  imArticulos: true,
  valor: 'precioMedio',
  tipoVenta: 'todos' as const,
  imprimir: 'codigo',
  agrupacionClientes: 'normal',
  formato: 'abcVentas',
  agrupacionArticulos: 'sinAgrupacion',
  formatoArticulos: 'normal',
  formatoJerarquia: 'normal',
  intervaloHoras: 'hora',
  graficoPor: 'importe',
  tipoGestionHoras: 'abcVentasHoras',
  agrupacionHoras: 'familia',
  diaSemanaAbc: 'todos',
  desgloseSemanal: 'importe',
  fechaDesde: '',
  fechaHasta: '',
  fechaFacturacionDesde: '',
  fechaFacturacionHasta: '',
  macroFamiliaDesde: '',
  macroFamiliaHasta: '',
  familiaDesde: '',
  familiaHasta: '',
  subfamiliaDesde: '',
  subfamiliaHasta: '',
  agrupacionDesde: '',
  agrupacionHasta: '',
  articuloDesde: '',
  articuloHasta: '',
  tiendaDesde: '',
  tiendaHasta: '',
  agenteDesde: '',
  agenteHasta: '',
  representanteDesde: '',
  representanteHasta: '',
  vendedorDesde: '',
  vendedorHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  proveedorDesde: '',
  proveedorHasta: '',
  puestoDesde: '',
  puestoHasta: '',
  sesionDesde: '',
  sesionHasta: '',
  semanaDesde: '',
  semanaHasta: '',
  perfilDesde: '',
  perfilHasta: '',
  seccionDesde: '',
  seccionHasta: '',
  subSeccionDesde: '',
  subSeccionHasta: '',
  actividadDesde: '',
  actividadHasta: '',
  tipoDescuentoDesde: '',
  tipoDescuentoHasta: '',
  tarifaDesde: '',
  tarifaHasta: '',
  importeDesde: '',
  importeHasta: '',
  facturaDesde: '',
  facturaHasta: '',
})

const rangosIntervalos = computed(() =>
  abcVentasIntervalosPorDimension(dimensionEfectiva.value || form.value.dimension),
)

const opcionesOrden = computed(() =>
  abcVentasOrdenPorDimension(dimensionEfectiva.value || form.value.dimension),
)

const tieneDatos = computed(
  () =>
    (data.value?.matrizVentaHoraria?.filas.length ?? 0) > 0 ||
    (data.value?.matrizSemanal?.filas.length ?? 0) > 0 ||
    (data.value?.grupos.length ?? 0) > 0 ||
    (data.value?.bloques?.length ?? 0) > 0,
)

const matrizVentaHoraria = computed(() => data.value?.matrizVentaHoraria ?? null)
const matrizSemanal = computed(() => data.value?.matrizSemanal ?? null)
const matrizCrosstab = computed(() => matrizVentaHoraria.value ?? matrizSemanal.value)

const muestraOpcionesClientes = computed(() =>
  abcVentasMuestraOpcionesClientes(dimensionEfectiva.value || form.value.dimension),
)

const muestraOpcionesArticulos = computed(() =>
  abcVentasMuestraOpcionesArticulos(dimensionEfectiva.value || form.value.dimension),
)

const muestraFormatoFamilias = computed(() =>
  abcVentasMuestraFormatoFamilias(dimensionEfectiva.value || form.value.dimension),
)

const muestraOpcionesHoras = computed(() =>
  abcVentasMuestraOpcionesHoras(dimensionEfectiva.value || form.value.dimension),
)

const muestraOpcionesSemanal = computed(() =>
  abcVentasMuestraOpcionesSemanal(dimensionEfectiva.value || form.value.dimension),
)

const muestraOpcionesDiasSemana = computed(() =>
  abcVentasMuestraOpcionesDiasSemana(dimensionEfectiva.value || form.value.dimension),
)

const ocultaOrdenInforme = computed(() =>
  abcVentasOcultaOrdenInforme(dimensionEfectiva.value || form.value.dimension),
)

const esVentaHorariaHoras = computed(
  () => (data.value?.tipoGestionHoras ?? form.value.tipoGestionHoras) === 'ventaHoraria',
)

const etiquetaColUnidades = computed(() =>
  abcVentasEtiquetaUnidadesJerarquia(
    muestraFormatoFamilias.value
      ? (data.value?.formatoJerarquia ?? form.value.formatoJerarquia)
      : undefined,
  ),
)

const mostrarTotalGrupoInforme = computed(() => data.value?.mostrarTotalGrupo !== false)

const ocultaImArticulos = computed(() =>
  abcVentasOcultaImArticulos(dimensionEfectiva.value || form.value.dimension),
)

const muestraColumnaComision = computed(() =>
  abcVentasFormatoArticulosMuestraComision(data.value?.formatoArticulos),
)

const etiquetaColLineaSecundaria = computed(() =>
  data.value?.formatoArticulos === 'detalleComision' ? 'Vendedor' : 'Articulo',
)

const muestraColumnasExtendido = computed(
  () => data.value?.formatoArticulos === 'extendido',
)

const usaTablaPlanaLegacy = computed(() => {
  const d = data.value
  return d != null && abcVentasUsaTablaPlanaLegacy(d)
})

const usaInformePlanoHorasAbc = computed(() => {
  const d = data.value
  return d != null && abcVentasUsaInformePlanoHorasAbc(d)
})

const graficoHorasAbc = computed(() => {
  const d = data.value
  if (!d || !usaInformePlanoHorasAbc.value) return null
  const por = d.graficoPor === 'unidades' ? 'unidades' : 'importe'
  return computeAbcHorasGrafico(d.grupos, por)
})

const etiquetaGraficoInformePlano = computed(() =>
  data.value?.dimension === 'dias-semana' ? 'Gráfico ventas por día' : 'Gráfico ventas por hora',
)

const ocultaColumnaMAgr = computed(() => {
  const d = data.value
  return d != null && abcVentasOcultaColumnaMAgr(d)
})

const tituloLegacyPlano = computed(() => {
  const div = data.value?.divisa?.trim() || 'EU'
  return `ABC VENTAS ${div}`
})

const fechaImpresionAhora = computed(() =>
  new Date().toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  }),
)

function pjeSobreTotalGrupo(g: AbcVentasGrupo): number {
  return abcVentasPjeSobreTotalGrupo(g)
}

const colspanEtiquetaGrupo = computed(() => 2 + (muestraColumnasExtendido.value ? 2 : 0))

/** Columnas de la tabla continua (artículos). */
const numColspanMarca = computed(() => {
  let n = 10
  if (muestraColumnasExtendido.value) n += 2
  if (muestraColumnaComision.value) n += 1
  return n
})

const ivaLabel = computed(() =>
  data.value?.iva === 'desglosado' || data.value?.iva === 'excluido' ? 'IVA desglosado' : 'IVA incluido',
)

type CampoForm = keyof AbcVentasFiltros

const buscarOpen = ref(false)
const buscarEntidad = ref<EntidadLookupId>('articulos')
const buscarCampo = ref<CampoForm>('articuloDesde')
const buscarInicial = ref('')

function valorFiltro(key: CampoForm): string {
  return String((form.value as Record<string, unknown>)[key] ?? '')
}

function asignarFiltro(key: CampoForm, v: string) {
  ;(form.value as Record<string, unknown>)[key] = v
}

function abrirBuscar(r: AbcFiltroRango, lado: 'desde' | 'hasta') {
  if (!r.entidad) return
  const key = (lado === 'desde' ? r.desde : r.hasta) as CampoForm
  buscarCampo.value = key
  buscarEntidad.value = r.entidad
  buscarInicial.value = valorFiltro(key)
  buscarOpen.value = true
}

function espejarDesdeEnHasta(campoDesde: CampoForm, codigo: string) {
  asignarFiltro(campoDesde, codigo)
  if (String(campoDesde).endsWith('Desde')) {
    const hasta = String(campoDesde).replace(/Desde$/, 'Hasta') as CampoForm
    asignarFiltro(hasta, codigo)
  }
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  espejarDesdeEnHasta(buscarCampo.value, r.codigo.trim())
  buscarOpen.value = false
}

watch(
  () =>
    rangosIntervalos.value
      .filter((r) => r.entidad)
      .map((r) => valorFiltro(r.desde as CampoForm))
      .join('\0'),
  () => {
    for (const r of rangosIntervalos.value) {
      if (!r.entidad) continue
      asignarFiltro(r.hasta as CampoForm, valorFiltro(r.desde as CampoForm))
    }
  },
)

function volverAlSelector() {
  void router.push({ path: '/listados/abc-ventas' })
}

const etiquetaDimension = computed(() =>
  etiquetaDimensionAbc(data.value?.dimension ?? form.value.dimension),
)

const dimensionInforme = computed(
  () => data.value?.dimension ?? form.value.dimension,
)

const dimensionGrupoInforme = computed(() => {
  if (abcVentasMuestraOpcionesHoras(dimensionInforme.value)) {
    if (esVentaHorariaHoras.value) return 'horas'
    return abcVentasDimensionGrupoDesdeAgrupacionHoras(
      data.value?.agrupacionHoras ?? form.value.agrupacionHoras,
    )
  }
  return dimensionInforme.value
})

const etiquetaTotalGrupo = computed(() => etiquetaTotalGrupoAbc(dimensionGrupoInforme.value))

const etiquetaBloqueGrupo = computed(() => etiquetaBloqueGrupoAbc(dimensionGrupoInforme.value))

const muestraImprimirProveedor = computed(() =>
  abcVentasMuestraImprimirProveedor(dimensionEfectiva.value || form.value.dimension),
)

const tituloCabeceraInforme = computed(() => {
  if (usaInformePlanoHorasAbc.value && data.value?.dimension === 'dias-semana') {
    const div = data.value?.divisa?.trim() || 'EU'
    return `ABC VENTAS (DIAS DE LA SEMANA) ${div}`
  }
  if (matrizSemanal.value) {
    const div = data.value?.divisa?.trim() || 'EU'
    return `ABC VENTAS (SEMANAL) ${div}`
  }
  if (matrizVentaHoraria.value) {
    const div = data.value?.divisa?.trim() || 'EU'
    return `VENTAS HORARIA (HORAS) ${div}`
  }
  if (usaInformePlanoHorasAbc.value) {
    const div = data.value?.divisa?.trim() || 'EU'
    return `ABC VENTAS (HORAS) ${div}`
  }
  if (usaTablaPlanaLegacy.value) return tituloLegacyPlano.value
  const dim = etiquetaDimensionAbc(dimensionInforme.value).toUpperCase()
  return `ABC VENTAS (${dim})`
})

function fmtMatrizValor(n: number | undefined) {
  const m = matrizCrosstab.value?.metrica
  if (m === 'unidades') return fmtQty(n)
  return fmt(n)
}

function limpiarIntervalosFormulario() {
  for (const r of [
    ...ABC_VENTAS_INTERVALOS_VENDEDORES,
    ...ABC_VENTAS_INTERVALOS_AGRUPACIONES,
    ...ABC_VENTAS_INTERVALOS_ARTICULOS,
    ...ABC_VENTAS_INTERVALOS_SECCIONES_SUBSECCIONES,
    ...ABC_VENTAS_INTERVALOS_PERFILES,
    ...ABC_VENTAS_INTERVALOS_CLIENTES,
    ...ABC_VENTAS_INTERVALOS_SEMANAL,
    ...ABC_VENTAS_INTERVALOS_DIAS_SEMANA,
  ]) {
    asignarFiltro(r.desde as CampoForm, '')
    asignarFiltro(r.hasta as CampoForm, '')
  }
}

const filtrosIntervaloActivos = computed(() => {
  const parts: string[] = []
  for (const r of rangosIntervalos.value) {
    const d = valorFiltro(r.desde as CampoForm).trim()
    const h = valorFiltro(r.hasta as CampoForm).trim()
    if (!d && !h) continue
    if (d && h && d === h) parts.push(`${r.label}: ${d}`)
    else if (d && h) parts.push(`${r.label}: ${d}–${h}`)
    else parts.push(`${r.label}: ${d || h}`)
  }
  return parts
})

watch(
  dimensionEfectiva,
  (dim, prev) => {
    if (!esEstaInstanciaActiva()) return
    if (!dim) return
    if (!abcVentasListadoPorDimension(dim)) {
      void router.replace({ path: '/listados/abc-ventas' })
      return
    }
    const dimAnterior = String(prev ?? '').trim()
    if (dimAnterior && dimAnterior !== dim) {
      limpiarIntervalosFormulario()
      data.value = null
    }
    form.value.dimension = dim as AbcVentasDimensionId
    const ordenes = abcVentasOrdenPorDimension(dim)
    if (!ordenes.some((o) => o.value === form.value.orden)) {
      form.value.orden = abcVentasOrdenDefectoPorDimension(dim)
    }
  },
  { immediate: true },
)

function fmtFecha(iso: string) {
  const [y, m, d] = iso.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y.slice(2)}`
}

function etiquetaPeriodo(desde: string, hasta: string): string {
  const d = desde.trim()
  const h = hasta.trim()
  if (!d && !h) return 'Fechas: sin límite'
  if (d && h) return `Fecha ${fmtFecha(d)} 00:00 - ${fmtFecha(h)} 23:59`
  if (d) return `Fecha desde ${fmtFecha(d)} 00:00`
  return `Fecha hasta ${fmtFecha(h)} 23:59`
}

function onFiltroInput(r: AbcFiltroRango, lado: 'desde' | 'hasta', raw: string) {
  let v = raw
  if (abcFiltroSoloDigitos(r.formato)) {
    v = v.replace(/\D+/g, '')
  }
  if (r.maxLength > 0 && v.length > r.maxLength) {
    v = v.slice(0, r.maxLength)
  }
  const key = (lado === 'desde' ? r.desde : r.hasta) as CampoForm
  asignarFiltro(key, v)
}

async function generar() {
  loading.value = true
  error.value = null
  try {
    data.value = await obtenerAbcVentas({ ...form.value })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el listado ABC')
    data.value = null
  } finally {
    loading.value = false
  }
}

function etiquetaBloqueGeo(b: AbcVentasBloque): string {
  if (data.value?.dimension === 'horas') {
    return `HORA: ${b.nombre || b.codigo}`
  }
  const agrArt = data.value?.agrupacionArticulos ?? ''
  if (agrArt && agrArt !== 'sinAgrupacion') {
    const pref =
      ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === agrArt)?.label?.toUpperCase() ??
      'GRUPO'
    return `${pref}: ${b.nombre || b.codigo}`
  }
  const agr = data.value?.agrupacionClientes ?? ''
  if (agr === 'provincia') return `PROVINCIA: ${b.nombre || b.codigo}`
  return `C.P.: ${b.nombre || b.codigo}`
}

function etiquetaTotalBloqueGeo(): string {
  if (data.value?.dimension === 'horas') {
    return 'TOTAL HORA'
  }
  const agrArt = data.value?.agrupacionArticulos ?? ''
  if (agrArt && agrArt !== 'sinAgrupacion') {
    const pref =
      ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === agrArt)?.label?.toUpperCase() ??
      'GRUPO'
    return `TOTAL ${pref}`
  }
  const agr = data.value?.agrupacionClientes ?? ''
  return agr === 'provincia' ? 'TOTAL PROVINCIA' : 'TOTAL C.P.'
}

async function imprimir() {
  const d = data.value
  if (!d) return
  const html = construirHtmlInformeAbcVentas(d, {
    tituloCabecera: tituloCabeceraInforme.value,
    periodo: etiquetaPeriodo(d.fechaDesde, d.fechaHasta),
    ivaLabel: ivaLabel.value,
    filtrosLinea: filtrosIntervaloActivos.value.join(' · ') || undefined,
  })
  const res = await imprimirListadoHtml({
    titulo: tituloCabeceraInforme.value,
    html,
    filenameFallback: 'abc-ventas.html',
  })
  mensaje.value = res.message
}

function fmt(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function rowTotales(t: AbcVentasTotales) {
  return {
    unidades: fmtQty(t.unidades),
    dto: fmt(t.dto),
    importe: fmt(t.importe),
    coste: fmt(t.coste),
    margen: fmt(t.margen),
    pjeMargen: fmt(t.pjeMargen),
    pjeSobreTotal: fmt(t.pjeSobreTotal ?? 100),
    comision: t.comision != null ? fmt(t.comision) : '',
  }
}
</script>

<template>
  <section class="ventas-view abc-form-view">
    <div class="head head-compact no-print">
      <div>
        <button type="button" class="volver-hub" @click="volverAlSelector">← ABC de ventas</button>
        <h2>{{ defListado?.titulo ?? 'ABC de ventas' }}</h2>
      </div>
      <div class="head-actions">
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="imprimir">Imprimir</button>
      </div>
    </div>

    <div class="layout-busqueda layout-abc no-print">
      <form class="panel-filtros panel-filtros-compact" @submit.prevent="generar">
        <fieldset class="bloque-opciones opciones-fila">
          <legend>Opciones</legend>
          <label v-if="!ocultaOrdenInforme">
            <span>Orden</span>
            <select v-model="form.orden">
              <option v-for="o in opcionesOrden" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label>
            <span>Divisa</span>
            <select v-model="form.divisa">
              <option v-for="o in ABC_VENTAS_DIVISAS" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label>
            <span>Iva</span>
            <select v-model="form.iva">
              <option v-for="o in ABC_VENTAS_IVA" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label v-if="muestraOpcionesSemanal || muestraOpcionesDiasSemana">
            <span>Día semana</span>
            <select v-model="form.diaSemanaAbc">
              <option v-for="o in ABC_VENTAS_DIA_SEMANA_ABC" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesDiasSemana">
            <span>Gráfico por</span>
            <select v-model="form.graficoPor">
              <option v-for="o in ABC_VENTAS_GRAFICO_POR_DIAS_SEMANA" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesSemanal">
            <span>Desglose</span>
            <select v-model="form.desgloseSemanal">
              <option v-for="o in ABC_VENTAS_DESGLOSE_SEMANAL" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="!ocultaImArticulos">
            <span>Im. artículos</span>
            <select v-model="form.imArticulos">
              <option v-for="o in ABC_VENTAS_IM_ARTICULOS" :key="String(o.value)" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label>
            <span>Valor</span>
            <select v-model="form.valor">
              <option v-for="o in ABC_VENTAS_VALOR" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label v-if="muestraOpcionesHoras">
            <span>Intervalo</span>
            <select v-model="form.intervaloHoras">
              <option v-for="o in ABC_VENTAS_INTERVALO_HORAS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesHoras">
            <span>Gráfico por</span>
            <select v-model="form.graficoPor">
              <option v-for="o in ABC_VENTAS_GRAFICO_POR_HORAS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesHoras">
            <span>Tipo gestión</span>
            <select v-model="form.tipoGestionHoras">
              <option v-for="o in ABC_VENTAS_TIPO_GESTION_HORAS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesHoras && !esVentaHorariaHoras">
            <span>Agrupación</span>
            <select v-model="form.agrupacionHoras">
              <option v-for="o in ABC_VENTAS_AGRUPACION_HORAS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="!muestraOpcionesHoras">
            <span>Tipo venta</span>
            <select v-model="form.tipoVenta">
              <option v-for="o in ABC_VENTAS_TIPO_VENTA" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label v-if="muestraOpcionesClientes">
            <span>Agrupación</span>
            <select v-model="form.agrupacionClientes">
              <option v-for="o in ABC_VENTAS_AGRUPACION_CLIENTES" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesClientes">
            <span>Formato</span>
            <select v-model="form.formato">
              <option v-for="o in ABC_VENTAS_FORMATO" :key="o.value" :value="o.value">{{ o.label }}</option>
            </select>
          </label>
          <label v-if="muestraOpcionesArticulos">
            <span>Agrupación</span>
            <select v-model="form.agrupacionArticulos">
              <option v-for="o in ABC_VENTAS_AGRUPACION_ARTICULOS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraOpcionesArticulos">
            <span>Formato</span>
            <select v-model="form.formatoArticulos">
              <option v-for="o in ABC_VENTAS_FORMATO_ARTICULOS" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraFormatoFamilias">
            <span>Formato</span>
            <select v-model="form.formatoJerarquia">
              <option v-for="o in ABC_VENTAS_FORMATO_JERARQUIA" :key="o.value" :value="o.value">
                {{ o.label }}
              </option>
            </select>
          </label>
          <label v-if="muestraImprimirProveedor">
            <span>Imprimir</span>
            <select v-model="form.imprimir">
              <option v-for="o in ABC_VENTAS_IMPRIMIR_PROVEEDOR" :key="o.value" :value="o.value">
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
            <div class="rango-row fila-fecha">
              <span class="rango-label">Fecha</span>
              <div class="celda-intervalo celda-fecha">
                <input v-model="form.fechaDesde" type="date" />
              </div>
              <div class="celda-intervalo celda-fecha">
                <input v-model="form.fechaHasta" type="date" />
              </div>
            </div>
            <div
              v-for="r in rangosIntervalos"
              :key="r.label"
              class="rango-row"
              :class="{ 'fila-fecha': r.modo === 1 }"
            >
              <span class="rango-label">{{ r.label }}</span>
              <div class="celda-intervalo" :class="{ 'celda-fecha': r.modo === 1 }">
                <input
                  v-if="r.modo === 1"
                  type="date"
                  :value="valorFiltro(r.desde as CampoForm)"
                  @input="asignarFiltro(r.desde as CampoForm, ($event.target as HTMLInputElement).value)"
                />
                <div v-else-if="r.entidad" class="con-lupa">
                  <input
                    :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                    type="text"
                    :value="valorFiltro(r.desde as CampoForm)"
                    :maxlength="r.maxLength"
                    :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                    autocomplete="off"
                    spellcheck="false"
                    @input="onFiltroInput(r, 'desde', ($event.target as HTMLInputElement).value)"
                  />
                  <button
                    type="button"
                    class="btn-lupa"
                    :title="`Buscar ${r.label} desde`"
                    @click="abrirBuscar(r, 'desde')"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <input
                  v-else
                  :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                  type="text"
                  :value="valorFiltro(r.desde as CampoForm)"
                  :maxlength="r.maxLength"
                  :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                  autocomplete="off"
                  spellcheck="false"
                  @input="onFiltroInput(r, 'desde', ($event.target as HTMLInputElement).value)"
                />
              </div>
              <div class="celda-intervalo" :class="{ 'celda-fecha': r.modo === 1 }">
                <input
                  v-if="r.modo === 1"
                  type="date"
                  :value="valorFiltro(r.hasta as CampoForm)"
                  @input="asignarFiltro(r.hasta as CampoForm, ($event.target as HTMLInputElement).value)"
                />
                <div v-else-if="r.entidad" class="con-lupa">
                  <input
                    :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                    type="text"
                    :value="valorFiltro(r.hasta as CampoForm)"
                    :maxlength="r.maxLength"
                    :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                    autocomplete="off"
                    spellcheck="false"
                    @input="onFiltroInput(r, 'hasta', ($event.target as HTMLInputElement).value)"
                  />
                  <button
                    type="button"
                    class="btn-lupa"
                    :title="`Buscar ${r.label} hasta`"
                    @click="abrirBuscar(r, 'hasta')"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <input
                  v-else
                  :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                  type="text"
                  :value="valorFiltro(r.hasta as CampoForm)"
                  :maxlength="r.maxLength"
                  :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                  autocomplete="off"
                  spellcheck="false"
                  @input="onFiltroInput(r, 'hasta', ($event.target as HTMLInputElement).value)"
                />
              </div>
            </div>
          </div>
        </fieldset>

        <button type="submit" class="btn-buscar btn-buscar-compact" :disabled="loading">
          {{ loading ? 'Generando…' : 'Generar' }}
        </button>
      </form>

      <div class="panel-listado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="msg">Generando listado…</p>
        <p v-else-if="!data" class="hint">Pulse Generar (fechas vacías = sin límite).</p>

        <div v-if="data" class="informe-wrap">
      <div
        class="informe"
        :class="{ 'abc-legacy-informe': usaTablaPlanaLegacy || usaInformePlanoHorasAbc }"
      >
        <header
          v-if="usaTablaPlanaLegacy || usaInformePlanoHorasAbc"
          class="informe-cab legacy-cab"
        >
          <div class="legacy-top">
            <div>
              <h1 class="legacy-tit">
                {{ usaInformePlanoHorasAbc ? tituloCabeceraInforme : tituloLegacyPlano }}
              </h1>
              <hr class="legacy-tit-line" />
            </div>
            <div class="legacy-fecha">
              <span class="legacy-fecha-lab">Fecha</span>
              <span class="legacy-fecha-val">{{ fmtFecha(data.fechaDesde) || '…' }} 00:00</span>
              <span class="legacy-fecha-val">{{ fmtFecha(data.fechaHasta) || '…' }} 23:59</span>
            </div>
          </div>
          <p class="legacy-impresion">Fecha de Impresión: {{ fechaImpresionAhora }}</p>
          <hr class="legacy-sep" />
        </header>
        <header v-else class="informe-cab">
          <div class="cab-row">
            <strong>{{ tituloCabeceraInforme }}</strong>
            <span>{{ data.divisa || 'EU' }}</span>
            <span>{{ etiquetaPeriodo(data.fechaDesde, data.fechaHasta) }}</span>
          </div>
          <div class="cab-row">
            <span>{{ etiquetaDimension }}</span>
            <span>{{ ivaLabel }}</span>
            <span>Orden: {{ data.orden }} · Valor: {{ data.valor }}</span>
          </div>
          <div v-if="data.agrupacionClientes" class="cab-row">
            <span>Agrupación: {{ data.agrupacionClientes }}</span>
            <span v-if="data.formato">Formato: {{ data.formato }}</span>
          </div>
          <div v-if="data.agrupacionArticulos" class="cab-row">
            <span>
              Agrupación:
              {{
                ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === data.agrupacionArticulos)
                  ?.label ?? data.agrupacionArticulos
              }}
            </span>
            <span v-if="data.formatoArticulos">
              Formato:
              {{
                ABC_VENTAS_FORMATO_ARTICULOS.find((o) => o.value === data.formatoArticulos)
                  ?.label ?? data.formatoArticulos
              }}
            </span>
          </div>
          <div v-if="muestraFormatoFamilias && data.formatoJerarquia" class="cab-row">
            <span>
              Formato:
              {{
                ABC_VENTAS_FORMATO_JERARQUIA.find((o) => o.value === data.formatoJerarquia)
                  ?.label ?? data.formatoJerarquia
              }}
            </span>
          </div>
          <div v-if="data.dimension === 'dias-semana'" class="cab-row">
            <span v-if="data.graficoPor">
              Gráfico por:
              {{
                ABC_VENTAS_GRAFICO_POR_DIAS_SEMANA.find((o) => o.value === data.graficoPor)
                  ?.label ?? data.graficoPor
              }}
            </span>
            <span v-if="data.diaSemanaAbc">
              Día semana:
              {{
                ABC_VENTAS_DIA_SEMANA_ABC.find((o) => o.value === data.diaSemanaAbc)?.label ??
                data.diaSemanaAbc
              }}
            </span>
          </div>
          <div v-if="data.dimension === 'horas'" class="cab-row">
            <span v-if="data.intervaloHoras">
              Intervalo:
              {{
                ABC_VENTAS_INTERVALO_HORAS.find((o) => o.value === data.intervaloHoras)?.label ??
                data.intervaloHoras
              }}
            </span>
            <span v-if="data.graficoPor">
              Gráfico por:
              {{
                ABC_VENTAS_GRAFICO_POR_HORAS.find((o) => o.value === data.graficoPor)?.label ??
                data.graficoPor
              }}
            </span>
            <span v-if="data.tipoGestionHoras">
              Tipo gestión:
              {{
                ABC_VENTAS_TIPO_GESTION_HORAS.find((o) => o.value === data.tipoGestionHoras)
                  ?.label ?? data.tipoGestionHoras
              }}
            </span>
            <span v-if="data.agrupacionHoras && data.tipoGestionHoras !== 'ventaHoraria'">
              Agrupación:
              {{
                ABC_VENTAS_AGRUPACION_HORAS.find((o) => o.value === data.agrupacionHoras)?.label ??
                data.agrupacionHoras
              }}
            </span>
            <span v-if="data.diaSemanaAbc">
              Día semana:
              {{
                ABC_VENTAS_DIA_SEMANA_ABC.find((o) => o.value === data.diaSemanaAbc)?.label ??
                data.diaSemanaAbc
              }}
            </span>
            <span v-if="data.desgloseSemanal">
              Desglose:
              {{
                ABC_VENTAS_DESGLOSE_SEMANAL.find((o) => o.value === data.desgloseSemanal)?.label ??
                data.desgloseSemanal
              }}
            </span>
          </div>
          <div v-if="filtrosIntervaloActivos.length" class="cab-row cab-filtros">
            <span>Filtros: {{ filtrosIntervaloActivos.join(' · ') }}</span>
          </div>
        </header>

        <template v-if="matrizCrosstab">
          <div
            class="table-scroll matriz-horaria-wrap"
            :class="{ 'matriz-semanal-wrap': matrizSemanal }"
          >
            <table
              class="abc-table matriz-venta-horaria"
              :class="{ 'matriz-semanal': matrizSemanal }"
            >
              <thead>
                <tr>
                  <th class="matriz-fila-head"></th>
                  <th
                    v-for="col in matrizCrosstab.columnas"
                    :key="col.id"
                    class="col-num matriz-col-head"
                  >
                    {{ col.nombre }}
                  </th>
                  <th class="col-num matriz-col-head matriz-total-col">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="fila in matrizCrosstab.filas" :key="fila.codigo || fila.nombre">
                  <th class="matriz-fila-label" scope="row">
                    {{ (fila.nombre || fila.codigo || '').toUpperCase() }}
                  </th>
                  <td v-for="col in matrizCrosstab.columnas" :key="col.id" class="col-num">
                    {{ fmtMatrizValor(fila.celdas[col.id]) }}
                  </td>
                  <td class="col-num matriz-total-col">{{ fmtMatrizValor(fila.total) }}</td>
                </tr>
                <tr class="total-row matriz-total-row">
                  <th class="matriz-fila-label" scope="row">Total</th>
                  <td v-for="col in matrizCrosstab.columnas" :key="col.id" class="col-num">
                    {{ fmtMatrizValor(matrizCrosstab.totalesColumna[col.id]) }}
                  </td>
                  <td class="col-num matriz-total-col">
                    {{ fmtMatrizValor(matrizCrosstab.totalGeneral) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <template v-else-if="data.bloques?.length && usaTablaPlanaLegacy">
          <section v-for="b in data.bloques" :key="b.codigo" class="bloque-geo">
            <h2 class="bloque-geo-tit">{{ etiquetaBloqueGeo(b) }}</h2>
            <div class="table-scroll">
              <table class="abc-table abc-legacy">
                <thead>
                  <tr>
                    <th class="col-codigo">Codigo</th>
                    <th class="col-articulo">Articulo</th>
                    <th class="col-num">{{ etiquetaColUnidades }}</th>
                    <th class="col-num">Descuent</th>
                    <th class="col-num">Importe</th>
                    <th class="col-num">Coste</th>
                    <th class="col-num">Margen</th>
                    <th class="col-pct">% Margen</th>
                    <th class="col-pct">% Sob.Tot</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="g in b.grupos"
                    :key="g.codigo || '(sin)'"
                    :class="{ 'salto-pagina': g.saltoPagina }"
                  >
                    <td class="col-codigo">{{ g.codigo }}</td>
                    <td class="col-articulo" :title="g.nombre">{{ g.nombre }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                    <td class="col-pct">{{ fmt(pjeSobreTotalGrupo(g)) }}</td>
                  </tr>
                  <tr class="total-row total-intermedio">
                    <td class="col-codigo"></td>
                    <td class="col-articulo lab-tot"><strong>{{ etiquetaTotalBloqueGeo() }}</strong></td>
                    <td class="col-num">{{ rowTotales(b.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(b.totales).pjeMargen }}</td>
                    <td class="col-pct">{{ rowTotales(b.totales).pjeSobreTotal }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
          <div class="table-scroll">
            <table class="abc-table abc-legacy">
              <thead>
                <tr>
                  <th class="col-codigo">Codigo</th>
                  <th class="col-articulo">Articulo</th>
                  <th class="col-num">{{ etiquetaColUnidades }}</th>
                  <th class="col-num">Descuent</th>
                  <th class="col-num">Importe</th>
                  <th class="col-num">Coste</th>
                  <th class="col-num">Margen</th>
                  <th class="col-pct">% Margen</th>
                  <th class="col-pct">% Sob.Tot</th>
                </tr>
              </thead>
              <tbody>
                <tr class="total-row total-general">
                  <td class="col-codigo"></td>
                  <td class="col-articulo lab-tot"><strong>TOTAL GENERAL</strong></td>
                  <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                  <td class="col-pct">100,00</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <template v-else-if="usaInformePlanoHorasAbc">
          <div class="table-scroll">
            <table class="abc-table abc-legacy abc-horas-plano">
              <thead>
                <tr>
                  <th class="col-codigo">Codigo</th>
                  <th class="col-num">Tickets</th>
                  <th class="col-num">Unidades</th>
                  <th class="col-num">Descuento</th>
                  <th class="col-num">Importe</th>
                  <th class="col-num">Coste</th>
                  <th class="col-num">Margen</th>
                  <th class="col-pct">% Margen</th>
                  <th class="col-pct">% Sob. Tot</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="g in data.grupos" :key="g.codigo">
                  <td class="col-codigo">{{ g.codigo }}</td>
                  <td class="col-num">{{ g.totales.tickets ?? 0 }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                  <td class="col-pct">{{ fmt(pjeSobreTotalGrupo(g)) }}</td>
                </tr>
                <tr class="total-row total-general">
                  <td class="col-codigo col-total-horas lab-tot">
                    <strong>TOTAL GENERAL</strong>
                  </td>
                  <td class="col-num">{{ data.totales.tickets ?? 0 }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                  <td class="col-pct">100,00</td>
                </tr>
              </tbody>
            </table>
          </div>
          <figure v-if="graficoHorasAbc?.hayDatos" class="abc-horas-grafico">
            <svg
              :viewBox="`0 0 ${graficoHorasAbc.ancho} ${graficoHorasAbc.alto}`"
              xmlns="http://www.w3.org/2000/svg"
              role="img"
              :aria-label="etiquetaGraficoInformePlano"
            >
              <g class="ejes">
                <line
                  v-for="(m, i) in graficoHorasAbc.marcas"
                  :key="i"
                  :x1="graficoHorasAbc.x0"
                  :y1="m.y"
                  :x2="graficoHorasAbc.xFin"
                  :y2="m.y"
                  stroke="#cbd5e1"
                  stroke-width="1"
                />
                <text
                  v-for="(m, i) in graficoHorasAbc.marcas"
                  :key="`t-${i}`"
                  :x="graficoHorasAbc.x0 - 4"
                  :y="m.y + 3"
                  text-anchor="end"
                  font-size="9"
                  fill="#475569"
                >
                  {{ m.etiqueta }}
                </text>
              </g>
              <g v-for="(b, i) in graficoHorasAbc.barras" :key="b.codigo + i">
                <rect
                  :x="b.x"
                  :y="b.y"
                  :width="b.ancho"
                  :height="b.alto"
                  :fill="b.color"
                />
                <text
                  :x="b.etiquetaX"
                  :y="graficoHorasAbc.y0 + 14"
                  text-anchor="middle"
                  font-size="8"
                  fill="#334155"
                >
                  {{ b.etiqueta }}
                </text>
              </g>
            </svg>
          </figure>
        </template>
        <template v-else-if="data.bloques?.length">
          <section v-for="b in data.bloques" :key="b.codigo" class="bloque-geo">
            <h2 class="bloque-geo-tit">{{ etiquetaBloqueGeo(b) }}</h2>
            <section
              v-for="g in b.grupos"
              :key="g.codigo || '(sin)'"
              class="grupo"
              :class="{ 'salto-pagina': g.saltoPagina }"
            >
              <h3 class="grupo-tit">
                <span class="grupo-dim">{{ etiquetaBloqueGrupo }}</span>
                <span v-if="g.codigo || g.nombre" class="grupo-det">
                  {{ g.codigo || '-' }} {{ g.nombre || '' }}
                </span>
              </h3>
              <div class="table-scroll">
                <table class="abc-table">
                  <thead>
                    <tr>
                      <th class="col-codigo">Codigo</th>
                      <th class="col-articulo">{{ etiquetaColLineaSecundaria }}</th>
                      <th v-if="muestraColumnasExtendido" class="col-articulo">Familia</th>
                      <th v-if="muestraColumnasExtendido" class="col-articulo">Proveedor</th>
                      <th class="col-num">{{ etiquetaColUnidades }}</th>
                      <th class="col-num">Dto.</th>
                      <th class="col-num">Importe</th>
                      <th class="col-num">Coste</th>
                      <th class="col-num">Margen</th>
                      <th class="col-pct">%Margen</th>
                      <th v-if="muestraColumnaComision" class="col-num">Comision</th>
                      <th class="col-pct">%Sob.Tot</th>
                      <th class="col-num">M.Agr.</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="a in g.articulos" :key="`${a.codigo}-${a.descripcion}`">
                      <td class="col-codigo">{{ a.codigo }}</td>
                      <td class="col-articulo" :title="a.descripcion">{{ a.descripcion }}</td>
                      <td v-if="muestraColumnasExtendido" class="col-articulo" :title="a.familiaNombre">
                        {{ a.familia || '' }} {{ a.familiaNombre || '' }}
                      </td>
                      <td v-if="muestraColumnasExtendido" class="col-articulo" :title="a.proveedorNombre">
                        {{ a.proveedor || '' }} {{ a.proveedorNombre || '' }}
                      </td>
                      <td class="col-num">{{ fmtQty(a.unidades) }}</td>
                      <td class="col-num">{{ fmt(a.dto) }}</td>
                      <td class="col-num">{{ fmt(a.importe) }}</td>
                      <td class="col-num">{{ fmt(a.coste) }}</td>
                      <td class="col-num">{{ fmt(a.margen) }}</td>
                      <td class="col-pct">{{ fmt(a.pjeMargen) }}</td>
                      <td v-if="muestraColumnaComision" class="col-num">
                        {{ a.comision != null ? fmt(a.comision) : '' }}
                      </td>
                      <td class="col-pct">{{ fmt(a.pjeSobreTotal) }}</td>
                      <td class="col-num">{{ fmt(a.mAgr) }}</td>
                    </tr>
                    <tr v-if="mostrarTotalGrupoInforme" class="total-row">
                      <td
                        class="col-codigo"
                        :colspan="2 + (muestraColumnasExtendido ? 2 : 0)"
                      >
                        <strong>{{ etiquetaTotalGrupo }}</strong>
                      </td>
                      <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                      <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                      <td v-if="muestraColumnaComision" class="col-num">
                        {{ rowTotales(g.totales).comision }}
                      </td>
                      <td class="col-pct">{{ rowTotales(g.totales).pjeSobreTotal }}</td>
                      <td class="col-num"></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </section>
            <div class="table-scroll">
              <table class="abc-table total-bloque">
                <tbody>
                  <tr class="total-row">
                    <td class="col-codigo" colspan="2"><strong>{{ etiquetaTotalBloqueGeo() }}</strong></td>
                    <td class="col-num">{{ rowTotales(b.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(b.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(b.totales).pjeMargen }}</td>
                    <td class="col-pct">{{ rowTotales(b.totales).pjeSobreTotal }}</td>
                    <td class="col-num"></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>
        <template v-else-if="usaTablaPlanaLegacy">
          <div class="table-scroll">
            <table class="abc-table abc-legacy">
              <thead>
                <tr>
                  <th class="col-codigo">Codigo</th>
                  <th class="col-articulo">Articulo</th>
                  <th class="col-num">{{ etiquetaColUnidades }}</th>
                  <th class="col-num">Descuent</th>
                  <th class="col-num">Importe</th>
                  <th class="col-num">Coste</th>
                  <th class="col-num">Margen</th>
                  <th class="col-pct">% Margen</th>
                  <th class="col-pct">% Sob.Tot</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="g in data.grupos"
                  :key="g.codigo || '(sin)'"
                  :class="{ 'salto-pagina': g.saltoPagina }"
                >
                  <td class="col-codigo">{{ g.codigo }}</td>
                  <td class="col-articulo" :title="g.nombre">{{ g.nombre }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                  <td class="col-pct">{{ fmt(pjeSobreTotalGrupo(g)) }}</td>
                </tr>
                <tr class="total-row total-intermedio">
                  <td class="col-codigo"></td>
                  <td class="col-articulo lab-tot"><strong>TOTAL</strong></td>
                  <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                  <td class="col-pct">{{ rowTotales(data.totales).pjeSobreTotal }}</td>
                </tr>
                <tr class="total-row total-general">
                  <td class="col-codigo"></td>
                  <td class="col-articulo lab-tot"><strong>TOTAL GENERAL</strong></td>
                  <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                  <td class="col-pct">100,00</td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
        <template v-else>
          <section
            v-for="g in data.grupos"
            :key="g.codigo || '(sin)'"
            class="grupo"
            :class="{ 'salto-pagina': g.saltoPagina }"
          >
            <h3 class="grupo-tit">
              <span class="grupo-dim">{{ etiquetaBloqueGrupo }}</span>
              <span v-if="g.codigo || g.nombre" class="grupo-det">
                {{ g.codigo || '-' }} {{ g.nombre || '' }}
              </span>
            </h3>
            <div class="table-scroll">
              <table class="abc-table">
                <thead>
                  <tr>
                    <th class="col-codigo">Codigo</th>
                    <th class="col-articulo">{{ etiquetaColLineaSecundaria }}</th>
                    <th v-if="muestraColumnasExtendido" class="col-articulo">Familia</th>
                    <th v-if="muestraColumnasExtendido" class="col-articulo">Proveedor</th>
                    <th class="col-num">{{ etiquetaColUnidades }}</th>
                    <th class="col-num">Dto.</th>
                    <th class="col-num">Importe</th>
                    <th class="col-num">Coste</th>
                    <th class="col-num">Margen</th>
                    <th class="col-pct">%Margen</th>
                    <th v-if="muestraColumnaComision" class="col-num">Comision</th>
                    <th class="col-pct">%Sob.Tot</th>
                    <th v-if="!ocultaColumnaMAgr" class="col-num">M.Agr.</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="a in g.articulos" :key="`${a.codigo}-${a.descripcion}`">
                    <td class="col-codigo">{{ a.codigo }}</td>
                    <td class="col-articulo" :title="a.descripcion">{{ a.descripcion }}</td>
                    <td v-if="muestraColumnasExtendido" class="col-articulo" :title="a.familiaNombre">
                      {{ a.familia || '' }} {{ a.familiaNombre || '' }}
                    </td>
                    <td v-if="muestraColumnasExtendido" class="col-articulo" :title="a.proveedorNombre">
                      {{ a.proveedor || '' }} {{ a.proveedorNombre || '' }}
                    </td>
                    <td class="col-num">{{ fmtQty(a.unidades) }}</td>
                    <td class="col-num">{{ fmt(a.dto) }}</td>
                    <td class="col-num">{{ fmt(a.importe) }}</td>
                    <td class="col-num">{{ fmt(a.coste) }}</td>
                    <td class="col-num">{{ fmt(a.margen) }}</td>
                    <td class="col-pct">{{ fmt(a.pjeMargen) }}</td>
                    <td v-if="muestraColumnaComision" class="col-num">
                      {{ a.comision != null ? fmt(a.comision) : '' }}
                    </td>
                    <td class="col-pct">{{ fmt(a.pjeSobreTotal) }}</td>
                    <td class="col-num">{{ fmt(a.mAgr) }}</td>
                  </tr>
                  <tr v-if="mostrarTotalGrupoInforme" class="total-row">
                    <td class="col-codigo" :colspan="colspanEtiquetaGrupo">
                      <strong>{{ etiquetaTotalGrupo }}</strong>
                    </td>
                    <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                    <td v-if="muestraColumnaComision" class="col-num">
                      {{ rowTotales(g.totales).comision }}
                    </td>
                    <td class="col-pct">{{ rowTotales(g.totales).pjeSobreTotal }}</td>
                    <td class="col-num"></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </template>

        <div
          v-if="!matrizCrosstab && !usaTablaPlanaLegacy && !usaInformePlanoHorasAbc"
          class="table-scroll total-general-wrap"
        >
          <table class="abc-table total-general">
            <thead class="sr-only">
              <tr>
                <th class="col-codigo">Codigo</th>
                <th class="col-articulo">Articulo</th>
                <th class="col-num">{{ etiquetaColUnidades }}</th>
                <th class="col-num">Dto.</th>
                <th class="col-num">Importe</th>
                <th class="col-num">Coste</th>
                <th class="col-num">Margen</th>
                <th class="col-pct">%Margen</th>
                <th class="col-pct">%Sob.Tot</th>
                <th class="col-num">M.Agr.</th>
              </tr>
            </thead>
            <tbody>
              <tr class="total-row">
                <td class="col-codigo" :colspan="colspanEtiquetaGrupo"><strong>TOTAL GENERAL</strong></td>
                <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                <td v-if="muestraColumnaComision" class="col-num">{{ rowTotales(data.totales).comision }}</td>
                <td class="col-pct">100,00</td>
                <td class="col-num"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
        </div>
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
@import '../listados/listado-informe-layout.css';

.volver-hub {
  display: block;
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

.abc-form-view .head-compact h2 {
  font-size: 1.05rem;
}

.abc-form-view {
  --stock-col-etiq: 6.25rem;
}

.layout-abc {
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

.celda-intervalo input.numerico {
  font-variant-numeric: tabular-nums;
  font-family: ui-monospace, Consolas, monospace;
  text-align: right;
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

.informe-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
}

.informe {
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.75rem;
  color: #0f172a;
}

.informe.abc-legacy-informe {
  border: none;
  border-radius: 0;
  padding: 0.35rem 0.25rem;
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

.abc-legacy-informe .table-scroll {
  border: none;
  border-radius: 0;
}

.abc-table.abc-legacy {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.abc-table.abc-legacy th,
.abc-table.abc-legacy td {
  border: none;
  padding: 2px 5px;
  vertical-align: top;
}

.abc-table.abc-legacy thead th {
  background: #006f6f;
  color: #fff;
  font-weight: 700;
  text-align: left;
}

.abc-table.abc-legacy th.col-num,
.abc-table.abc-legacy td.col-num,
.abc-table.abc-legacy th.col-pct,
.abc-table.abc-legacy td.col-pct {
  text-align: right;
}

.abc-table.abc-legacy .lab-tot {
  text-align: right;
}

.abc-table.abc-legacy tr.total-intermedio td {
  border-top: 1px solid #333;
  font-weight: 700;
}

.abc-table.abc-legacy tr.total-general td {
  border-top: 1px solid #333;
  border-bottom: 3px double #333;
  font-weight: 700;
}

.informe.abc-flujo-continuo {
  border: none;
  border-radius: 0;
  padding: 0.25rem 0;
}

.abc-flujo-continuo .table-scroll {
  border: none;
  border-radius: 0;
}

.abc-flujo-continuo .total-general-wrap {
  border: none;
}

.abc-table.abc-flujo {
  width: 100%;
  min-width: 0;
  max-width: 100%;
}

.abc-table.abc-flujo th,
.abc-table.abc-flujo td {
  border-left: none;
  border-right: none;
  border-bottom: 1px solid #cbd5e1;
}

.abc-table.abc-flujo th {
  background: #e8e8e8;
  position: static;
}

.abc-table.abc-flujo tr.grupo-marca td {
  border-bottom: none;
  background: #fff;
}

.abc-table.abc-flujo tr.grupo-marca .marca-dim {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding-top: 0.55rem;
}

.abc-table.abc-flujo tr.grupo-marca .marca-det {
  font-size: 0.88rem;
  font-weight: 600;
  color: #334155;
  padding-bottom: 0.2rem;
  border-bottom: 1px solid #ddd !important;
}

.abc-table.abc-flujo tbody tr:nth-child(even):not(.total-row):not(.grupo-marca) {
  background: transparent;
}

.informe-cab {
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
}

.cab-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 0.2rem;
}

.bloque-geo {
  margin-bottom: 1.25rem;
}

.bloque-geo-tit {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  font-weight: 700;
  color: #0f172a;
}

.grupo {
  margin-bottom: 1.25rem;
}

.grupo-tit {
  margin: 0 0 0.4rem;
  font-size: 0.95rem;
  font-weight: 700;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.grupo-dim {
  font-size: 0.82rem;
  letter-spacing: 0.02em;
}

.grupo-det {
  font-size: 0.95rem;
  font-weight: 600;
  color: #334155;
}

.table-scroll {
  overflow-x: auto;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}

.abc-table {
  width: max-content;
  min-width: 0;
  max-width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.78rem;
}

.abc-table th,
.abc-table td {
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  padding: 0.28rem 0.4rem;
  vertical-align: middle;
}

.abc-table th:last-child,
.abc-table td:last-child {
  border-right: none;
}

.abc-table th {
  background: #e2e8f0;
  text-align: left;
  font-weight: 700;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 1;
}

.abc-table tbody tr:nth-child(even):not(.total-row) {
  background: #f8fafc;
}

.col-codigo {
  width: 5.5rem;
  font-family: ui-monospace, Consolas, monospace;
}

.col-articulo {
  width: 14rem;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.col-num,
.col-pct {
  width: 5.6rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  font-family: ui-monospace, Consolas, monospace;
}

.col-pct {
  width: 4.8rem;
}

.abc-table th.col-num,
.abc-table th.col-pct {
  text-align: right;
}

.total-row td {
  border-top: 2px solid #475569;
  font-weight: 700;
  background: #f1f5f9;
}

.total-general {
  margin-top: 0.75rem;
}

.matriz-venta-horaria {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.72rem;
}

.matriz-venta-horaria th,
.matriz-venta-horaria td {
  border: 1px solid #334155;
  padding: 0.25rem 0.35rem;
}

.matriz-venta-horaria .matriz-fila-label {
  text-align: left;
  font-weight: 700;
  min-width: 7rem;
}

.matriz-venta-horaria .matriz-col-head,
.matriz-venta-horaria tbody .col-num {
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.matriz-venta-horaria thead .matriz-col-head {
  font-weight: 700;
  font-size: 0.65rem;
  line-height: 1.2;
}

.matriz-venta-horaria .matriz-total-col {
  font-weight: 700;
}

.matriz-venta-horaria .matriz-total-row th,
.matriz-venta-horaria .matriz-total-row td {
  background: #f1f5f9;
}

/* Más específico que `.abc-table.abc-legacy { width: 100% }` */
.abc-table.abc-legacy.abc-horas-plano {
  width: max-content;
  max-width: 100%;
  table-layout: auto;
  font-size: 0.7rem;
}

.abc-table.abc-legacy.abc-horas-plano thead th {
  background: #006f6f;
  color: #fff;
  font-weight: 700;
  padding: 0.12rem 0.22rem;
  white-space: nowrap;
}

.abc-table.abc-legacy.abc-horas-plano th,
.abc-table.abc-legacy.abc-horas-plano td {
  border: 1px solid #94a3b8;
  padding: 0.12rem 0.22rem;
}

.abc-table.abc-legacy.abc-horas-plano .col-codigo {
  width: auto;
  min-width: 0;
  max-width: none;
  padding-right: 0.35rem;
  white-space: nowrap;
  text-align: left;
}

.abc-table.abc-legacy.abc-horas-plano .col-total-horas {
  text-align: right;
  white-space: nowrap;
}

.abc-table.abc-legacy.abc-horas-plano .col-num,
.abc-table.abc-legacy.abc-horas-plano .col-pct {
  width: auto;
  min-width: 0;
  max-width: none;
  padding-left: 0.22rem;
  padding-right: 0.22rem;
  text-align: right;
}

.abc-legacy-informe .table-scroll:has(.abc-horas-plano) {
  display: inline-block;
  width: auto;
  max-width: 100%;
  vertical-align: top;
}

.abc-horas-grafico {
  margin-top: 1rem;
  max-width: 100%;
  overflow-x: auto;
}

.abc-horas-grafico svg {
  display: block;
  width: 100%;
  max-width: 520px;
  height: auto;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

@media print {
  .no-print {
    display: none !important;
  }

  .abc-page {
    gap: 0;
  }

  .informe {
    border: none;
    padding: 0;
  }

  .table-scroll {
    overflow: visible;
    border: none;
  }

  .abc-table {
    min-width: 0;
    font-size: 0.7rem;
  }

  .abc-table th {
    background: #ddd !important;
    position: static;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .grupo {
    page-break-inside: avoid;
  }

  .grupo.salto-pagina {
    page-break-before: always;
  }

  .bloque-geo {
    page-break-inside: avoid;
  }
}
</style>
