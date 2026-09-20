<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { obtenerAbcCompras } from '@/api/compras'
import type { AbcComprasFiltros, AbcComprasGrupo, AbcComprasResponse, AbcComprasTotales } from '@/types/compras'
import { extractApiError } from '@/composables/useMantenimiento'
import {
  abcComprasIntervalosPorDimension,
  abcFiltroSoloDigitosCompras,
  type AbcComprasFiltroRango,
} from '@/config/abc-compras-filtros'
import {
  ABC_COMPRAS_DIVISAS,
  ABC_COMPRAS_IM_ARTICULOS,
  ABC_COMPRAS_VALOR,
  abcComprasEtiquetaUnidadesJerarquia,
  abcComprasFormatoOpcionesPorDimension,
  abcComprasMuestraComboFormato,
  abcComprasOcultaValorYSoloActualizado,
  abcComprasOrdenDefectoPorDimension,
  abcComprasOrdenPorDimension,
  abcComprasUsaFormatoExtendidoSubfamilias,
  abcComprasUsaTablaPlanaArticulos,
} from '@/config/abc-compras-opciones'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import type { EntidadLookupId } from '@/config/entidad-lookup'
import {
  abcComprasPjeSobreTotalGrupo,
  construirHtmlInformeAbcCompras,
} from '@/composables/abcComprasInformeHtml'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { exportarAbcComprasExcel } from '@/composables/abcInformeExcel'
import {
  abcComprasListadoPorDimension,
  etiquetaBloqueGrupoAbcCompras,
  etiquetaTotalGrupoAbcCompras,
  type AbcComprasDimensionId,
} from '@/config/abc-compras-dimensiones'
import {
  dimensionDesdePathAbcCompras,
  useRutaInstanciaKeepAlive,
} from '@/composables/useRutaInstanciaKeepAlive'

const route = useRoute()
const router = useRouter()
const { pathInstancia, esEstaInstanciaActiva } = useRutaInstanciaKeepAlive()

const dimensionEfectiva = computed(() => {
  if (esEstaInstanciaActiva()) {
    return String(route.params.dimension ?? '').trim()
  }
  return dimensionDesdePathAbcCompras(pathInstancia)
})

const defListado = computed(() => abcComprasListadoPorDimension(dimensionEfectiva.value))

const loading = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const data = ref<AbcComprasResponse | null>(null)

const form = ref<AbcComprasFiltros>({
  dimension: 'familias',
  orden: 'importe',
  imArticulos: 'si',
  divisa: 'EU',
  valor: 'precioMedio',
  formatoJerarquia: 'normal',
  soloActualizado: false,
  fechaDesde: '',
  fechaHasta: '',
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
  proveedorDesde: '',
  proveedorHasta: '',
  almacenDesde: '',
  almacenHasta: '',
  seccionDesde: '',
  seccionHasta: '',
  subSeccionDesde: '',
  subSeccionHasta: '',
  loteDesde: '',
  loteHasta: '',
  ultimaVentaDesde: '',
  ultimaVentaHasta: '',
  centralDesde: '',
  centralHasta: '',
})

type CampoForm = keyof AbcComprasFiltros

const rangosIntervalos = computed(() =>
  abcComprasIntervalosPorDimension(dimensionEfectiva.value || form.value.dimension),
)

const opcionesOrden = computed(() =>
  abcComprasOrdenPorDimension(dimensionEfectiva.value || form.value.dimension),
)

const usaTablaPlana = computed(() =>
  abcComprasUsaTablaPlanaArticulos(dimensionEfectiva.value || form.value.dimension),
)

const tieneDatos = computed(() => (data.value?.grupos.length ?? 0) > 0)

function volverAlSelector() {
  void router.push({ path: '/listados/abc-compras' })
}

const etiquetaColUnidades = computed(() =>
  abcComprasEtiquetaUnidadesJerarquia(form.value.formatoJerarquia),
)

const muestraComboFormato = computed(() =>
  abcComprasMuestraComboFormato(dimensionEfectiva.value || form.value.dimension),
)

const opcionesFormato = computed(() =>
  abcComprasFormatoOpcionesPorDimension(dimensionEfectiva.value || form.value.dimension),
)

const ocultaValorYSoloActualizado = computed(() =>
  abcComprasOcultaValorYSoloActualizado(dimensionEfectiva.value || form.value.dimension),
)

const formatoExtendidoSubfamilias = computed(() =>
  abcComprasUsaFormatoExtendidoSubfamilias(
    dimensionEfectiva.value || form.value.dimension,
    data.value?.formatoJerarquia ?? form.value.formatoJerarquia,
  ),
)

const etiquetaBloqueGrupo = computed(() =>
  etiquetaBloqueGrupoAbcCompras(dimensionEfectiva.value || data.value?.dimension || ''),
)

watch(
  dimensionEfectiva,
  (dim) => {
    if (!dim) return
    form.value.dimension = dim as AbcComprasDimensionId
    form.value.orden = abcComprasOrdenDefectoPorDimension(dim)
    const formatos = abcComprasFormatoOpcionesPorDimension(dim)
    if (formatos.length === 0) {
      form.value.formatoJerarquia = 'normal'
    } else if (!formatos.some((o) => o.value === form.value.formatoJerarquia)) {
      form.value.formatoJerarquia = 'normal'
    }
  },
  { immediate: true },
)

const buscar = ref<{ rango: AbcComprasFiltroRango; lado: 'desde' | 'hasta' } | null>(null)

function valorFiltro(campo: CampoForm): string {
  const v = form.value[campo]
  if (v === undefined || v === null) return ''
  return String(v)
}

function asignarFiltro(campo: CampoForm, val: string) {
  ;(form.value as Record<string, unknown>)[campo] = val
}

function onFiltroInput(r: AbcComprasFiltroRango, lado: 'desde' | 'hasta', raw: string) {
  const key = (lado === 'desde' ? r.desde : r.hasta) as CampoForm
  let v = raw
  if (abcFiltroSoloDigitosCompras(r.formato)) {
    v = v.replace(/\D/g, '')
  }
  asignarFiltro(key, v)
}

function abrirBuscar(r: AbcComprasFiltroRango, lado: 'desde' | 'hasta') {
  if (!r.entidad) return
  buscar.value = { rango: r, lado }
}

function onEntidadElegida(res: EntidadBuscarResultado) {
  if (!buscar.value) return
  const { rango, lado } = buscar.value
  const key = (lado === 'desde' ? rango.desde : rango.hasta) as CampoForm
  asignarFiltro(key, res.codigo)
  buscar.value = null
}

async function generar() {
  loading.value = true
  error.value = null
  mensaje.value = null
  data.value = null
  try {
    form.value.dimension = (dimensionEfectiva.value || form.value.dimension) as AbcComprasDimensionId
    data.value = await obtenerAbcCompras(form.value)
  } catch (e) {
    error.value = extractApiError(e)
  } finally {
    loading.value = false
  }
}

function fmt(n: number | undefined): string {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function rowTotales(t: AbcComprasTotales) {
  return {
    unidades: fmt(t.unidades),
    dto: fmt(t.dto),
    importe: fmt(t.importe),
    coste: fmt(t.coste),
    margen: fmt(t.margen),
    pjeMargen: fmt(t.pjeMargen),
    pjeSobreTotal: fmt(t.pjeSobreTotal ?? 0),
  }
}

function pjeSobreTotalGrupo(g: AbcComprasGrupo): string {
  return fmt(abcComprasPjeSobreTotalGrupo(g))
}

function exportarExcel() {
  if (!data.value || !defListado.value) return
  const slug = dimensionEfectiva.value || data.value.dimension || 'abc'
  exportarAbcComprasExcel(data.value, `abc-compras-${slug}.csv`)
  mensaje.value = 'Excel (CSV) generado'
}

async function imprimir() {
  if (!data.value || !defListado.value) return
  const periodo = `Fecha ${form.value.fechaDesde || '…'} - ${form.value.fechaHasta || '…'}`
  const html = construirHtmlInformeAbcCompras(data.value, {
    tituloCabecera: defListado.value.titulo,
    periodo,
  })
  const res = await imprimirListadoHtml({
    titulo: defListado.value.titulo,
    html,
    filenameFallback: 'abc-compras.html',
  })
  mensaje.value = res.message
}
</script>

<template>
  <section v-if="defListado" class="ventas-view abc-form-view">
    <div class="head head-compact no-print">
      <div>
        <button type="button" class="volver-hub" @click="volverAlSelector">← ABC de compras</button>
        <h2>{{ defListado.titulo }}</h2>
      </div>
      <div class="head-actions">
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="exportarExcel">
          Excel (CSV)
        </button>
        <button type="button" class="btn-accion" :disabled="!tieneDatos" @click="imprimir">
          Imprimir
        </button>
      </div>
    </div>

    <div class="layout-busqueda layout-abc no-print">
      <form class="panel-filtros panel-filtros-compact" @submit.prevent="generar">
      <fieldset class="bloque-opciones opciones-fila">
        <legend>Opciones</legend>
        <label>
          <span>Orden</span>
          <select v-model="form.orden">
            <option v-for="o in opcionesOrden" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </label>
        <label>
          <span>Divisa</span>
          <select v-model="form.divisa">
            <option v-for="o in ABC_COMPRAS_DIVISAS" :key="o.value" :value="o.value">
              {{ o.label }}
            </option>
          </select>
        </label>
        <label>
          <span>Im. artículos</span>
          <select v-model="form.imArticulos">
            <option v-for="o in ABC_COMPRAS_IM_ARTICULOS" :key="o.value" :value="o.value">
              {{ o.label }}
            </option>
          </select>
        </label>
        <label v-if="muestraComboFormato">
          <span>Formato</span>
          <select v-model="form.formatoJerarquia">
            <option v-for="o in opcionesFormato" :key="o.value" :value="o.value">
              {{ o.label }}
            </option>
          </select>
        </label>
        <label v-if="!ocultaValorYSoloActualizado">
          <span>Valor</span>
          <select v-model="form.valor">
            <option v-for="o in ABC_COMPRAS_VALOR" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </label>
        <label v-if="!ocultaValorYSoloActualizado">
          <span>Solo actualizado stock</span>
          <select v-model="form.soloActualizado">
            <option :value="false">No</option>
            <option :value="true">Sí</option>
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
                  :class="{ numerico: abcFiltroSoloDigitosCompras(r.formato) }"
                  type="text"
                  :value="valorFiltro(r.desde as CampoForm)"
                  :maxlength="r.maxLength"
                  @input="onFiltroInput(r, 'desde', ($event.target as HTMLInputElement).value)"
                />
                <button type="button" class="btn-lupa" @click="abrirBuscar(r, 'desde')">
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <input
                v-else
                :class="{ numerico: abcFiltroSoloDigitosCompras(r.formato) }"
                type="text"
                :value="valorFiltro(r.desde as CampoForm)"
                :maxlength="r.maxLength"
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
                  :class="{ numerico: abcFiltroSoloDigitosCompras(r.formato) }"
                  type="text"
                  :value="valorFiltro(r.hasta as CampoForm)"
                  :maxlength="r.maxLength"
                  @input="onFiltroInput(r, 'hasta', ($event.target as HTMLInputElement).value)"
                />
                <button type="button" class="btn-lupa" @click="abrirBuscar(r, 'hasta')">
                  <ToolIcon name="buscar" />
                </button>
              </div>
              <input
                v-else
                :class="{ numerico: abcFiltroSoloDigitosCompras(r.formato) }"
                type="text"
                :value="valorFiltro(r.hasta as CampoForm)"
                :maxlength="r.maxLength"
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
        <p v-else-if="data.grupos.length === 0" class="hint">Sin datos para los filtros indicados.</p>

      <div v-if="data && data.grupos.length" class="informe-wrap">
        <div class="informe" :class="{ 'abc-legacy-informe': usaTablaPlana }">
          <template v-if="usaTablaPlana">
            <div class="table-scroll">
              <table class="abc-table abc-legacy">
                <thead>
                  <tr>
                    <th>Código</th>
                    <th>Artículo</th>
                    <th class="col-num">{{ etiquetaColUnidades }}</th>
                    <th class="col-num">Dto</th>
                    <th class="col-num">Importe</th>
                    <th class="col-num">Coste</th>
                    <th class="col-num">Margen</th>
                    <th class="col-pct">% Marg</th>
                    <th class="col-pct">% Sob.Tot</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="g in data.grupos" :key="g.codigo">
                    <td>{{ g.codigo }}</td>
                    <td>{{ g.nombre }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                    <td class="col-pct">{{ pjeSobreTotalGrupo(g) }}</td>
                  </tr>
                  <tr class="total-row">
                    <td colspan="2"><strong>TOTAL GENERAL</strong></td>
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
            <section v-for="g in data.grupos" :key="g.codigo || '(sin)'" class="grupo">
              <h3 class="grupo-tit">
                <span class="grupo-dim">{{ etiquetaBloqueGrupo }}</span>
                <span class="grupo-det">{{ g.codigo || '-' }} {{ g.nombre || '' }}</span>
                <template v-if="formatoExtendidoSubfamilias">
                  <span class="grupo-dim">Familia</span>
                  <span class="grupo-det">
                    {{ g.metaFamiliaCodigo || '-' }} {{ g.metaFamiliaNombre || '' }}
                  </span>
                  <span class="grupo-dim">MacroFamilia</span>
                  <span class="grupo-det">
                    {{ g.metaMacroCodigo || '-' }} {{ g.metaMacroNombre || '' }}
                  </span>
                </template>
              </h3>
              <div class="table-scroll">
                <table class="abc-table">
                  <thead>
                    <tr>
                      <th>Código</th>
                      <th>Artículo</th>
                      <th class="col-num">{{ etiquetaColUnidades }}</th>
                      <th class="col-num">Dto</th>
                      <th class="col-num">Importe</th>
                      <th class="col-num">Coste</th>
                      <th class="col-num">Margen</th>
                      <th class="col-pct">% Marg</th>
                      <th class="col-pct">% Sob.Tot</th>
                      <th class="col-num">M.Agr.</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="a in g.articulos" :key="a.codigo">
                      <td>{{ a.codigo }}</td>
                      <td>{{ a.descripcion }}</td>
                      <td class="col-num">{{ fmt(a.unidades) }}</td>
                      <td class="col-num">{{ fmt(a.dto) }}</td>
                      <td class="col-num">{{ fmt(a.importe) }}</td>
                      <td class="col-num">{{ fmt(a.coste) }}</td>
                      <td class="col-num">{{ fmt(a.margen) }}</td>
                      <td class="col-pct">{{ fmt(a.pjeMargen) }}</td>
                      <td class="col-pct">{{ fmt(a.pjeSobreTotal) }}</td>
                      <td class="col-num">{{ fmt(a.mAgr) }}</td>
                    </tr>
                    <tr v-if="data.mostrarTotalGrupo !== false" class="total-row">
                      <td colspan="2">
                        <strong>{{ etiquetaTotalGrupoAbcCompras(data.dimension) }}</strong>
                      </td>
                      <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                      <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                      <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
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
                    <td colspan="2"><strong>TOTAL GENERAL</strong></td>
                    <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                    <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                    <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                    <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                    <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                    <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                    <td class="col-pct">100,00</td>
                    <td class="col-num"></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
        </div>
      </div>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscar != null"
      :entidad="(buscar?.rango.entidad ?? 'articulos') as EntidadLookupId"
      @cerrar="buscar = null"
      @seleccionar="onEntidadElegida"
    />
  </section>
</template>

<style scoped>
@import '../listados/listado-informe-layout.css';
@import '../listados/abc-form-view.css';
</style>
