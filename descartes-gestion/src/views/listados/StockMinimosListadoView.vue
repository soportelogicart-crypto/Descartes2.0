<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import { generarListadoStockMinimos, type StockMinimosResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { useAlmacenesOpciones } from '@/composables/useAlmacenesOpciones'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

const auth = useAuthStore()
const { registrarReciente } = useListadosRecientes()
const { almacenes, cargarAlmacenes } = useAlmacenesOpciones()

const generando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<StockMinimosResult | null>(null)
const almacen = ref(0)

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

function formatoUnidades(n: number): string {
  const r = Math.round(n * 10000) / 10000
  return r.toLocaleString('es-ES', { maximumFractionDigits: 4 })
}

function metaImpresion(): string[] {
  const alm = almacenes.value.find((a) => a.value === almacen.value)
  const lines = [`Almacén: ${alm?.label ?? 'Todos'}`]
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
    const data = await generarListadoStockMinimos({ almacen: almacen.value || undefined })
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Ningún artículo está por debajo del mínimo con estos filtros.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran las primeras ${data.limite} filas.`
    } else {
      mensaje.value = `${data.items.length} artículo(s) bajo mínimo.`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el listado')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

function exportarExcel() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const cab = [
    'Artículo',
    'Descripción',
    'Almacén',
    'Stock',
    'Mínimo',
    'Óptimo',
    'Faltan',
    'Proveedor',
  ]
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [
        r.articulo,
        r.descripcion,
        r.almacen,
        numCsv(r.stockActual, 4),
        numCsv(r.minimo, 4),
        numCsv(r.optimo, 4),
        numCsv(r.faltan, 4),
        r.proveedorCodigo,
      ]
        .map(escCsv)
        .join(';')
    )
  }
  descargarCsv('stock-bajo-minimos.csv', lines)
  mensaje.value = `Excel (CSV) de ${filas.length} fila(s)`
}

function imprimir() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const res = imprimirListadoHtml({
    titulo: 'Stock bajo mínimos',
    metaLineas: metaImpresion(),
    thead: ['Artículo', 'Descripción', 'Alm.', 'Stock', 'Mín.', 'Faltan'],
    filas: filas.map((r) => [
      r.articulo,
      r.descripcion,
      r.almacen,
      formatoUnidades(r.stockActual),
      formatoUnidades(r.minimo),
      formatoUnidades(r.faltan),
    ]),
    pie: [`${filas.length} filas`],
    filenameFallback: 'stock-minimos.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('stock-minimos')
  void cargarAlmacenes()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Stock bajo mínimos"
    descripcion="Artículos cuya existencia real está por debajo del mínimo definido en la tabla de mínimos (por almacén)."
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label>
        <span>Almacén</span>
        <select v-model.number="almacen">
          <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
        </select>
      </label>
    </template>

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="tieneDatos" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Artículo</th>
            <th>Descripción</th>
            <th class="num">Alm.</th>
            <th class="num">Stock</th>
            <th class="num">Mínimo</th>
            <th class="num">Óptimo</th>
            <th class="num">Faltan</th>
            <th>Prov.</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in resultado!.items" :key="`${row.articulo}-${row.almacen}-${i}`">
            <td>{{ row.articulo }}</td>
            <td>{{ row.descripcion }}</td>
            <td class="num" :title="row.almacenNombre">{{ row.almacen }}</td>
            <td class="num">{{ formatoUnidades(row.stockActual) }}</td>
            <td class="num">{{ formatoUnidades(row.minimo) }}</td>
            <td class="num">{{ formatoUnidades(row.optimo) }}</td>
            <td class="num faltan">{{ formatoUnidades(row.faltan) }}</td>
            <td>{{ row.proveedorCodigo }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="7"><strong>{{ resultado!.totales.filas }} filas</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">No hay filas que mostrar.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';
.faltan {
  color: #b91c1c;
  font-weight: 600;
}
</style>
