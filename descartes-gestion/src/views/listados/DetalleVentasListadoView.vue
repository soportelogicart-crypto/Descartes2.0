<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import {
  generarDetalleVentas,
  type DetalleVentasParams,
  type DetalleVentasResult,
} from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { hoyIso, inicioAnioIso } from '@/composables/useAtajosFecha'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

type Opt = { value: string; label: string }
type EntidadRango = 'articulos' | 'clientes'

/** Filtros con rango desde/hasta, en el orden del formulario de legacy. */
const RANGOS: { key: string; label: string; entidad?: EntidadRango }[] = [
  { key: 'articulo', label: 'Artículo', entidad: 'articulos' },
  { key: 'familia', label: 'Familia' },
  { key: 'subfamilia', label: 'Subfamilia' },
  { key: 'macroFamilia', label: 'MacroFamilia' },
  { key: 'agrupacion', label: 'Agrupación' },
  { key: 'puesto', label: 'Puesto' },
  { key: 'vendedor', label: 'Vendedor' },
  { key: 'cliente', label: 'Cliente', entidad: 'clientes' },
  { key: 'proveedor', label: 'Proveedor' },
  { key: 'seccion', label: 'Sección' },
  { key: 'subSeccion', label: 'Subsección' },
  { key: 'actividad', label: 'Actividad' },
  { key: 'codigoPostal', label: 'Código postal' },
  { key: 'tipoDescuento', label: 'Tipo de descuento' },
  { key: 'factura', label: 'Factura' },
  { key: 'lote', label: 'Lote' },
]

function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

const puesto = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<DetalleVentasResult | null>(null)
const tiendas = ref<Opt[]>([])

const form = reactive({
  fechaDesde: inicioAnioIso(),
  fechaHasta: hoyIso(),
  tipoVenta: 'todos' as 'todos' | 'albaranes' | 'tickets',
  tienda: '',
})

const rangos = reactive<Record<string, { desde: string; hasta: string }>>(
  Object.fromEntries(RANGOS.map((r) => [r.key, { desde: '', hasta: '' }]))
)

const tieneDatos = computed(() => (resultado.value?.grupos.length ?? 0) > 0)

function metaImpresion(): string[] {
  const lines = [
    `Periodo: ${form.fechaDesde} — ${form.fechaHasta}`,
    form.tienda ? `Tienda: ${form.tienda}` : 'Tienda: todas',
    `Ventas: ${form.tipoVenta}`,
    `Generado: ${new Date().toLocaleString('es-ES')}`,
  ]
  return lines
}

async function cargarTiendas() {
  loadingOpts.value = true
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre?: string }) => ({
      value: normalizarEmpresaCodigo(String(t.codigo ?? '')),
      label: `${t.codigo}${t.nombre ? ` — ${t.nombre}` : ''}`,
    }))
    const emp = puesto.empresaCodigo?.trim()
    if (emp && !form.tienda) form.tienda = normalizarEmpresaCodigo(emp)
  } catch {
    /* ignore */
  } finally {
    loadingOpts.value = false
  }
}

function construirParams(): DetalleVentasParams {
  const params: DetalleVentasParams = {
    fechaDesde: form.fechaDesde,
    fechaHasta: form.fechaHasta,
    tipoVenta: form.tipoVenta,
  }
  if (form.tienda.trim()) params.tienda = form.tienda.trim()
  for (const r of RANGOS) {
    const v = rangos[r.key]
    if (v.desde.trim()) params[`${r.key}Desde`] = v.desde.trim()
    if (v.hasta.trim()) params[`${r.key}Hasta`] = v.hasta.trim()
  }
  return params
}

let seq = 0
async function generar() {
  if (!form.fechaDesde || !form.fechaHasta) {
    error.value = 'Indique la fecha desde y la fecha hasta.'
    return
  }
  if (form.fechaDesde > form.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarDetalleVentas(construirParams())
    if (id !== seq) return
    resultado.value = data
    if (!data.grupos.length) {
      mensaje.value = 'No hay ventas con los filtros indicados.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran las primeras ${data.limite} líneas. Acote el periodo o los filtros.`
    } else {
      mensaje.value = `${data.totales.lineas} línea(s) de ${data.totales.clientes} cliente(s).`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el detalle de ventas')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

function documento(l: { esTicket: boolean; albaran: number }): string {
  return `${l.esTicket ? 'Ticket' : 'Albarán'} ${l.albaran}`
}

function exportarExcel() {
  const r = resultado.value
  if (!r || !r.grupos.length) return
  const cab = ['Cliente', 'Nombre', 'Fecha', 'Documento', 'Artículo', 'Descripción', 'Precio', 'Unidades', 'Dto.', 'Importe']
  const lines = [cab.map(escCsv).join(';')]
  for (const g of r.grupos) {
    for (const l of g.lineas) {
      lines.push(
        [
          g.clienteCodigo,
          g.clienteNombre,
          l.fecha,
          documento(l),
          l.articulo,
          l.descripcion,
          numCsv(l.precio),
          numCsv(l.unidades),
          numCsv(l.pjeDto),
          numCsv(l.importe),
        ]
          .map(escCsv)
          .join(';')
      )
    }
    lines.push(
      ['', '', '', '', '', `TOTAL CLIENTE ${g.clienteCodigo}`, '', numCsv(g.totales.unidades), '', numCsv(g.totales.importe)]
        .map(escCsv)
        .join(';')
    )
  }
  lines.push('')
  lines.push(
    ['', '', '', '', '', 'TOTAL GENERAL', '', numCsv(r.totales.unidades), '', numCsv(r.totales.importe)]
      .map(escCsv)
      .join(';')
  )
  descargarCsv('detalle-ventas.csv', lines)
  mensaje.value = 'Excel (CSV) exportado'
}

async function imprimir() {
  const r = resultado.value
  if (!r || !r.grupos.length) return
  const filas: string[][] = []
  for (const g of r.grupos) {
    filas.push([`CLIENTE ${g.clienteCodigo} ${g.clienteNombre}`, '', '', '', '', '', '', ''])
    for (const l of g.lineas) {
      filas.push([
        l.fecha,
        documento(l),
        l.articulo,
        l.descripcion,
        numCsv(l.precio),
        numCsv(l.unidades),
        l.pjeDto ? numCsv(l.pjeDto) : '',
        numCsv(l.importe),
      ])
    }
    filas.push(['', '', '', '', '', 'TOTAL CLIENTE', numCsv(g.totales.unidades), numCsv(g.totales.importe)])
  }
  const res = await imprimirListadoHtml({
    titulo: 'Detalle de ventas',
    metaLineas: metaImpresion(),
    thead: ['Fecha', 'Documento', 'Artículo', 'Descripción', 'Precio', 'Unidades', 'Dto.', 'Importe'],
    columnas: [
      { ancho: '10%', alineacion: 'left' },
      { ancho: '12%', alineacion: 'left' },
      { ancho: '12%', alineacion: 'left' },
      { ancho: '30%', alineacion: 'left' },
      { ancho: '8%', alineacion: 'right' },
      { ancho: '9%', alineacion: 'right' },
      { ancho: '6%', alineacion: 'right' },
      { ancho: '13%', alineacion: 'right' },
    ],
    filas,
    pie: [`TOTAL GENERAL: ${numCsv(r.totales.importe)} €`, `Líneas: ${r.totales.lineas}`],
    filenameFallback: 'detalle-ventas.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('detalle-ventas')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Detalle de ventas"
    descripcion="Cada venta de los artículos elegidos, agrupada por cliente: albaranes y tickets, con precio, unidades, descuento e importe. Los filtros siguen el formulario de legacy."
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label>
        <span>Desde</span>
        <input v-model="form.fechaDesde" type="date" />
      </label>
      <label>
        <span>Hasta</span>
        <input v-model="form.fechaHasta" type="date" />
      </label>
      <label>
        <span>Tienda</span>
        <select v-model="form.tienda" :disabled="loadingOpts">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </label>
      <label>
        <span>Ventas</span>
        <select v-model="form.tipoVenta">
          <option value="todos">Todas (albaranes y tickets)</option>
          <option value="albaranes">Solo albaranes</option>
          <option value="tickets">Solo tickets</option>
        </select>
      </label>
    </template>

    <template #mas-filtros>
      <div class="rangos">
        <div v-for="r in RANGOS" :key="r.key" class="rango">
          <span class="rango-titulo">{{ r.label }}</span>
          <template v-if="r.entidad">
            <EntidadLookupField v-model="rangos[r.key].desde" :entidad="r.entidad" titulo-modal="Buscar" />
            <EntidadLookupField v-model="rangos[r.key].hasta" :entidad="r.entidad" titulo-modal="Buscar" />
          </template>
          <template v-else>
            <input v-model="rangos[r.key].desde" placeholder="Desde" />
            <input v-model="rangos[r.key].hasta" placeholder="Hasta" />
          </template>
        </div>
      </div>
    </template>

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="tieneDatos" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Documento</th>
            <th>Artículo</th>
            <th>Descripción</th>
            <th class="num">Precio</th>
            <th class="num">Unidades</th>
            <th class="num">Dto.</th>
            <th class="num">Importe</th>
          </tr>
        </thead>
        <template v-for="g in resultado!.grupos" :key="g.clienteCodigo">
          <tbody>
            <tr class="cabecera-cliente">
              <td colspan="8"><strong>Cliente {{ g.clienteCodigo }}</strong> {{ g.clienteNombre }}</td>
            </tr>
            <tr v-for="(l, i) in g.lineas" :key="`${g.clienteCodigo}-${l.empresa}-${l.albaran}-${i}`">
              <td>{{ l.fecha }}</td>
              <td>{{ documento(l) }}</td>
              <td>{{ l.articulo }}</td>
              <td>{{ l.descripcion }}</td>
              <td class="num">{{ numCsv(l.precio) }}</td>
              <td class="num">{{ numCsv(l.unidades) }}</td>
              <td class="num">{{ l.pjeDto ? numCsv(l.pjeDto) : '' }}</td>
              <td class="num">{{ numCsv(l.importe) }}</td>
            </tr>
            <tr class="total-cliente">
              <td colspan="5"><strong>Total cliente</strong></td>
              <td class="num"><strong>{{ numCsv(g.totales.unidades) }}</strong></td>
              <td></td>
              <td class="num"><strong>{{ numCsv(g.totales.importe) }}</strong></td>
            </tr>
          </tbody>
        </template>
        <tfoot>
          <tr>
            <td colspan="5"><strong>Total general</strong></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.unidades) }}</strong></td>
            <td></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.importe) }}</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">No hay ventas con los filtros indicados.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';

.rangos {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr));
  gap: 0.5rem 1rem;
}

.rango {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.rango-titulo {
  font-weight: 600;
  font-size: 0.85rem;
  color: #334155;
}

.cabecera-cliente td {
  background: #f1f5f9;
  padding-top: 0.5rem;
}

.total-cliente td {
  border-bottom: 1px solid #cbd5e1;
}
</style>
