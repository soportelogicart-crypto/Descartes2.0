<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { descargarRecibosPdf, listarRecibosImpresion } from '@/api/facturacion'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePdfPreview } from '@/composables/usePdfPreview'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import type { ReciboImpresionItem } from '@/types/facturacion'
import PdfPreviewModal from '@/components/common/PdfPreviewModal.vue'

const puesto = usePuestoContextoStore()
const loading = ref(false)
const printing = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<ReciboImpresionItem[]>([])
const selected = ref<Record<string, boolean>>({})
const tiendas = ref<Array<{ value: string; label: string }>>([])
const { pdfOpen, pdfUrl, pdfTitulo, abrirPdf, cerrarPdf } = usePdfPreview('Impresión de recibos')

const hoy = new Date().toISOString().slice(0, 10)
const form = ref({
  empresa: '',
  estado: 'pendientes',
  vencimientoDesde: '',
  vencimientoHasta: hoy,
  cliente: '',
  factura: '' as string | number,
})

function key(r: ReciboImpresionItem) {
  return `${r.empresa}|${r.facturaTipo}|${r.factura}|${r.recibo}`
}

const seleccionados = computed(() => items.value.filter((r) => selected.value[key(r)]))
const todos = computed(
  () => items.value.length > 0 && items.value.every((r) => selected.value[key(r)])
)

function toggleTodos(valor: boolean) {
  selected.value = Object.fromEntries(items.value.map((r) => [key(r), valor]))
}

async function cargarOpciones() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
    form.value.empresa = String(puesto.empresaCodigo ?? '').trim()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las tiendas')
  }
}

async function buscar() {
  if (!form.value.empresa) {
    error.value = 'Indique la tienda'
    return
  }
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarRecibosImpresion({
      empresa: form.value.empresa,
      estado: form.value.estado,
      vencimientoDesde: form.value.vencimientoDesde || undefined,
      vencimientoHasta: form.value.vencimientoHasta || undefined,
      cliente: form.value.cliente || undefined,
      factura: Number(form.value.factura) || undefined,
    })
    items.value = data.items
    selected.value = {}
    mensaje.value = `${data.totales.recibos} recibos · ${data.totales.importe.toFixed(2)} €`
  } catch (e: unknown) {
    items.value = []
    error.value = extractApiError(e, 'No se pudieron cargar los recibos')
  } finally {
    loading.value = false
  }
}

async function imprimir() {
  if (!seleccionados.value.length) {
    error.value = 'Seleccione al menos un recibo'
    return
  }
  printing.value = true
  error.value = null
  try {
    const blob = await descargarRecibosPdf({
      recibos: seleccionados.value.map((r) => ({
        empresa: r.empresa,
        facturaTipo: r.facturaTipo,
        factura: r.factura,
        recibo: r.recibo,
      })),
    })
    const head = await blob.slice(0, 5).text()
    if (!head.startsWith('%PDF')) throw new Error('La API no devolvió un PDF válido')
    abrirPdf(blob, `Recibos (${seleccionados.value.length})`)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron imprimir los recibos')
  } finally {
    printing.value = false
  }
}

onMounted(cargarOpciones)
</script>

<template>
  <section class="page">
    <header>
      <div>
        <h2>Impresión de recibos</h2>
        <p>Recibos generados por las facturas a crédito.</p>
      </div>
      <div class="actions">
        <button type="button" :disabled="loading" @click="buscar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button
          type="button"
          class="primary"
          :disabled="printing || !seleccionados.length"
          @click="imprimir"
        >
          {{ printing ? 'Generando…' : 'Imprimir' }}
        </button>
      </div>
    </header>

    <div class="layout">
      <form @submit.prevent="buscar">
        <label>
          Tienda
          <select v-model="form.empresa">
            <option value="">—</option>
            <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </label>
        <label>
          Estado
          <select v-model="form.estado">
            <option value="pendientes">Pendientes</option>
            <option value="liquidados">Liquidados</option>
            <option value="todos">Todos</option>
          </select>
        </label>
        <label>Vencimiento desde <input v-model="form.vencimientoDesde" type="date" /></label>
        <label>Vencimiento hasta <input v-model="form.vencimientoHasta" type="date" /></label>
        <label>Cliente <input v-model="form.cliente" maxlength="12" /></label>
        <label>Factura <input v-model="form.factura" type="number" min="1" /></label>
      </form>

      <main>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
        <div class="grid">
          <table>
            <thead>
              <tr>
                <th><input type="checkbox" :checked="todos" @change="toggleTodos(($event.target as HTMLInputElement).checked)" /></th>
                <th>Vencimiento</th>
                <th>Factura</th>
                <th>Recibo</th>
                <th>Cliente</th>
                <th>Razón social</th>
                <th>Forma de pago</th>
                <th class="num">Importe</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in items"
                :key="key(r)"
                :class="{ checked: selected[key(r)] }"
                @click="selected[key(r)] = !selected[key(r)]"
              >
                <td @click.stop><input v-model="selected[key(r)]" type="checkbox" /></td>
                <td>{{ r.vencimiento }}</td>
                <td>{{ r.facturaTipo }}-{{ r.factura }}</td>
                <td>{{ r.recibo }}</td>
                <td>{{ r.cliente }}</td>
                <td>{{ r.razonSocial }}</td>
                <td>{{ r.formaPago }} {{ r.formaPagoDescripcion }}</td>
                <td class="num">{{ r.importe.toFixed(2) }}</td>
                <td>{{ r.liquidado ? 'Liquidado' : r.remesado ? 'Remesado' : 'Pendiente' }}</td>
              </tr>
              <tr v-if="!items.length && !loading">
                <td colspan="9" class="empty">Configure los filtros y pulse Buscar.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </main>
    </div>

    <PdfPreviewModal
      :open="pdfOpen"
      :url="pdfUrl"
      :titulo="pdfTitulo"
      @cerrar="cerrarPdf"
    />
  </section>
</template>

<style scoped>
.page { display: flex; flex-direction: column; gap: .75rem; height: 100%; min-height: 0; }
header { display: flex; justify-content: space-between; gap: .75rem; align-items: flex-start; }
h2 { margin: 0; }
header p { margin: .15rem 0 0; color: #64748b; font-size: .85rem; }
.actions { display: flex; gap: .4rem; }
button { padding: .4rem .8rem; border: 1px solid #94a3b8; border-radius: 5px; background: #fff; cursor: pointer; }
button.primary { color: #fff; background: #0f172a; border-color: #0f172a; }
button:disabled { opacity: .5; cursor: not-allowed; }
.layout { display: grid; grid-template-columns: 16rem minmax(0, 1fr); gap: .75rem; flex: 1; min-height: 0; }
form { display: flex; flex-direction: column; gap: .5rem; padding: .65rem; border: 1px solid #cbd5e1; border-radius: 5px; background: #f8fafc; align-self: start; }
label { display: grid; gap: .15rem; font-size: .78rem; color: #334155; }
input, select { box-sizing: border-box; width: 100%; height: 1.8rem; padding: .2rem .35rem; border: 1px solid #94a3b8; border-radius: 3px; background: #fff; }
main { min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: .5rem; }
.grid { overflow: auto; flex: 1; border: 1px solid #cbd5e1; border-radius: 4px; }
table { width: 100%; border-collapse: collapse; font-size: .8rem; }
th, td { padding: .32rem .4rem; border-bottom: 1px solid #e2e8f0; text-align: left; white-space: nowrap; }
th { position: sticky; top: 0; background: #f1f5f9; }
tbody tr { cursor: pointer; }
tbody tr:hover { background: #f8fafc; }
tbody tr.checked { background: #eff6ff; }
.num { text-align: right; font-variant-numeric: tabular-nums; }
.error { color: #b91c1c; margin: 0; }
.ok { color: #047857; margin: 0; }
.empty { text-align: center; color: #64748b; padding: 1rem; }
@media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
</style>
