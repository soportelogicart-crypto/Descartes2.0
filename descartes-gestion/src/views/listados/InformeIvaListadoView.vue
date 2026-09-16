<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import { generarInformeIva, type InformeIvaResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { hoyIso, inicioAnioIso, rangoAtajoFecha, type AtajoFechaId } from '@/composables/useAtajosFecha'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

type Opt = { value: string; label: string }

const auth = useAuthStore()
const puesto = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<InformeIvaResult | null>(null)
const tiendas = ref<Opt[]>([])

const form = ref({
  fechaDesde: inicioAnioIso(),
  fechaHasta: hoyIso(),
  empresa: '',
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

function aplicarAtajo(id: AtajoFechaId) {
  const r = rangoAtajoFecha(id)
  form.value.fechaDesde = r.desde
  form.value.fechaHasta = r.hasta
}

function metaImpresion(): string[] {
  const lines = [
    `Periodo: ${form.value.fechaDesde} — ${form.value.fechaHasta}`,
    form.value.empresa ? `Tienda: ${form.value.empresa}` : 'Tienda: todas',
  ]
  const u = auth.usuario?.nombre
  if (u) lines.push(`Usuario: ${u}`)
  lines.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lines
}

async function cargarTiendas() {
  loadingOpts.value = true
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre?: string }) => ({
      value: String(t.codigo ?? '').trim(),
      label: `${t.codigo}${t.nombre ? ` — ${t.nombre}` : ''}`,
    }))
    const emp = puesto.empresaCodigo?.trim().toUpperCase()
    if (emp && !form.value.empresa) form.value.empresa = emp
  } catch {
    /* ignore */
  } finally {
    loadingOpts.value = false
  }
}

let seq = 0
async function generar() {
  if (form.value.fechaDesde > form.value.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarInformeIva({
      fechaDesde: form.value.fechaDesde,
      fechaHasta: form.value.fechaHasta,
      empresa: form.value.empresa.trim() || undefined,
    })
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Sin ventas en el periodo seleccionado.'
    } else {
      const t = data.totales
      mensaje.value = `${data.items.length} tipo(s) de IVA. Total con IVA: ${numCsv(t.importeTotal)} €`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el informe')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

function exportarExcel() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const cab = ['Impuesto', 'Nombre', '% IVA', 'Base', 'Cuota IVA', 'Total', 'Líneas']
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [
        r.impuestoCodigo,
        r.impuestoNombre,
        numCsv(r.pjeIva, 2),
        numCsv(r.baseImponible),
        numCsv(r.cuotaIva),
        numCsv(r.importeTotal),
        r.numLineas,
      ]
        .map(escCsv)
        .join(';')
    )
  }
  const t = resultado.value!.totales
  lines.push('')
  lines.push(
    ['TOTAL', '', '', numCsv(t.baseImponible), numCsv(t.cuotaIva), numCsv(t.importeTotal), ''].map(escCsv).join(';')
  )
  descargarCsv('informe-iva.csv', lines)
  mensaje.value = `Excel (CSV) exportado`
}

function imprimir() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const t = resultado.value!.totales
  const res = imprimirListadoHtml({
    titulo: 'Informe de IVA',
    metaLineas: metaImpresion(),
    thead: ['Imp.', 'Descripción', '% IVA', 'Base', 'Cuota', 'Total'],
    filas: filas.map((r) => [
      r.impuestoCodigo,
      r.impuestoNombre,
      numCsv(r.pjeIva, 2),
      numCsv(r.baseImponible),
      numCsv(r.cuotaIva),
      numCsv(r.importeTotal),
    ]),
    pie: [
      `Base total: ${numCsv(t.baseImponible)} €`,
      `Cuota: ${numCsv(t.cuotaIva)} €`,
      `Total: ${numCsv(t.importeTotal)} €`,
    ],
    filenameFallback: 'informe-iva.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('informe-iva')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Informe de IVA"
    descripcion="Ventas de mostrador y gestión agrupadas por tipo de IVA (líneas de albarán no anuladas). Importes con IVA incluido en línea."
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
      <div class="atajos">
        <span class="atajos-label">Atajos:</span>
        <button type="button" @click="aplicarAtajo('hoy')">Hoy</button>
        <button type="button" @click="aplicarAtajo('mes')">Mes</button>
        <button type="button" @click="aplicarAtajo('anio')">Año</button>
      </div>
      <label>
        <span>Tienda</span>
        <select v-model="form.empresa" :disabled="loadingOpts">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
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
            <th>Impuesto</th>
            <th>Descripción</th>
            <th class="num">% IVA</th>
            <th class="num">Base imponible</th>
            <th class="num">Cuota IVA</th>
            <th class="num">Total</th>
            <th class="num">Líneas</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in resultado!.items" :key="`${row.impuestoCodigo}-${row.pjeIva}-${i}`">
            <td>{{ row.impuestoCodigo }}</td>
            <td>{{ row.impuestoNombre }}</td>
            <td class="num">{{ numCsv(row.pjeIva, 2) }}</td>
            <td class="num">{{ numCsv(row.baseImponible) }}</td>
            <td class="num">{{ numCsv(row.cuotaIva) }}</td>
            <td class="num">{{ numCsv(row.importeTotal) }}</td>
            <td class="num">{{ row.numLineas }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="3"><strong>Totales</strong></td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.baseImponible) }}</strong>
            </td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.cuotaIva) }}</strong>
            </td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.importeTotal) }}</strong>
            </td>
            <td />
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">No hay filas que mostrar.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';

.atajos-label {
  font-size: 0.78rem;
  color: #64748b;
}
</style>
