<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import {
  generarListadoStock,
  type StockAgruparPor,
  type StockListadoFila,
  type StockListadoResult,
} from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { useAlmacenesOpciones } from '@/composables/useAlmacenesOpciones'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

const AGRUPAR_OPCIONES: { value: StockAgruparPor; label: string }[] = [
  { value: 'articulo', label: 'Artículo' },
  { value: 'familia', label: 'Familia' },
  { value: 'subfamilia', label: 'Subfamilia' },
  { value: 'macrofamilia', label: 'Macrofamilia' },
  { value: 'agrupacion', label: 'Agrupación' },
  { value: 'proveedor', label: 'Proveedor habitual' },
]

const auth = useAuthStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<StockListadoResult | null>(null)
const { almacenes, cargarAlmacenes } = useAlmacenesOpciones()

const form = ref({
  agruparPor: 'articulo' as StockAgruparPor,
  almacen: 0,
  ocultarCero: true,
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

const etiquetaGrupo = computed(() => {
  const m = AGRUPAR_OPCIONES.find((o) => o.value === form.value.agruparPor)
  return m?.label ?? 'Grupo'
})

function formatoUnidades(n: number): string {
  const r = Math.round(n * 10000) / 10000
  return r.toLocaleString('es-ES', { maximumFractionDigits: 4 })
}

function metaImpresion(): string[] {
  const lines: string[] = []
  const alm = almacenes.value.find((a) => a.value === form.value.almacen)
  lines.push(`Agrupar por: ${etiquetaGrupo.value}`)
  lines.push(`Almacén: ${alm?.label ?? 'Todos'}`)
  if (form.value.ocultarCero) lines.push('Sin filas a cantidad cero')
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

let seq = 0
async function generar() {
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarListadoStock({
      agruparPor: form.value.agruparPor,
      almacen: form.value.almacen || undefined,
      ocultarCero: form.value.ocultarCero,
    })
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Sin datos con los filtros actuales.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran las primeras ${data.limite} filas. Acote almacén o agrupación si necesita el listado completo.`
    } else {
      mensaje.value = `${data.items.length} fila(s). Total unidades: ${formatoUnidades(data.totales.unidades)}`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el listado')
    resultado.value = null
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
      [r.grupoCodigo, r.grupoNombre, numCsv(r.unidades, 4), r.numArticulos].map(escCsv).join(';')
    )
  }
  if (resultado.value) {
    lines.push('')
    lines.push(
      ['TOTAL', '', numCsv(resultado.value.totales.unidades, 4), ''].map(escCsv).join(';')
    )
  }
  descargarCsv(`stock-${form.value.agruparPor}.csv`, lines)
  mensaje.value = `Excel (CSV) de ${filas.length} fila(s)`
}

function imprimir() {
  const filas = filasParaExport()
  if (!filas.length) return
  const res = imprimirListadoHtml({
    titulo: 'Stock',
    subtitulo: etiquetaGrupo.value,
    metaLineas: metaImpresion(),
    thead: ['Código', 'Descripción / nombre', 'Unidades', 'Artículos'],
    filas: filas.map((r) => [
      r.grupoCodigo,
      r.grupoNombre,
      formatoUnidades(r.unidades),
      r.numArticulos,
    ]),
    pie: resultado.value
      ? [`Total unidades: ${formatoUnidades(resultado.value.totales.unidades)}`, `${filas.length} filas`]
      : undefined,
    filenameFallback: 'stock.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('stock')
  void cargarAlmacenes()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Stock"
    descripcion="Existencias calculadas desde movimientos de stock por almacén. Elija cómo agrupar el resultado."
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label>
        <span>Agrupar por</span>
        <select v-model="form.agruparPor">
          <option v-for="o in AGRUPAR_OPCIONES" :key="o.value" :value="o.value">{{ o.label }}</option>
        </select>
      </label>
      <label>
        <span>Almacén</span>
        <select v-model.number="form.almacen">
          <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
      </label>
    </template>

    <template #mas-filtros>
      <label class="check">
        <input v-model="form.ocultarCero" type="checkbox" />
        Ocultar grupos con cantidad cero
      </label>
    </template>

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="tieneDatos" ref="printArea" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Código</th>
            <th>{{ etiquetaGrupo === 'Artículo' ? 'Descripción' : 'Nombre' }}</th>
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
    <p v-else-if="resultado && !generando" class="sin-datos">No hay filas que mostrar.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';

.check {
  flex-direction: row !important;
  align-items: center;
  gap: 0.5rem !important;
  font-size: 0.88rem !important;
  color: #334155 !important;
}
</style>
