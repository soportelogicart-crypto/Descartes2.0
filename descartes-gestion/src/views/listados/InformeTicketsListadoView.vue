<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import { generarInformeTickets, type InformeTicketsResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { hoyIso, inicioMesIso } from '@/composables/useAtajosFecha'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { useAuthStore } from '@/stores/auth'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

type Opt = { value: string; label: string }

function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

const auth = useAuthStore()
const puestoCtx = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<InformeTicketsResult | null>(null)
const tiendas = ref<Opt[]>([])

const form = ref({
  fechaDesde: inicioMesIso(),
  fechaHasta: hoyIso(),
  empresa: '',
  puesto: '',
  vendedor: '',
  soloNumerados: true,
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

function etiquetaTicket(row: { numeroTicket: number | null; albaran: number }): string {
  if (row.numeroTicket != null && row.numeroTicket > 0) return `T-${row.numeroTicket}`
  return `Alb. ${row.albaran}`
}

function metaImpresion(): string[] {
  const lines = [
    `Periodo: ${form.value.fechaDesde} — ${form.value.fechaHasta}`,
    form.value.empresa ? `Tienda: ${form.value.empresa}` : 'Tienda: todas',
  ]
  if (form.value.puesto.trim()) lines.push(`Puesto: ${form.value.puesto.trim()}`)
  if (form.value.vendedor.trim()) lines.push(`Vendedor: ${form.value.vendedor.trim()}`)
  if (!form.value.soloNumerados) lines.push('Incluye tickets sin numerar')
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
      value: normalizarEmpresaCodigo(String(t.codigo ?? '')),
      label: `${t.codigo}${t.nombre ? ` — ${t.nombre}` : ''}`,
    }))
    const emp = puestoCtx.empresaCodigo?.trim()
    if (emp && !form.value.empresa) form.value.empresa = normalizarEmpresaCodigo(emp)
    const pue = puestoCtx.puestoCodigo?.trim()
    if (pue && !form.value.puesto) form.value.puesto = pue
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
    const data = await generarInformeTickets({
      fechaDesde: form.value.fechaDesde,
      fechaHasta: form.value.fechaHasta,
      empresa: form.value.empresa.trim() || undefined,
      puesto: form.value.puesto.trim() || undefined,
      vendedor: form.value.vendedor.trim() || undefined,
      soloNumerados: form.value.soloNumerados,
    })
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = 'Sin tickets en el periodo y filtros actuales.'
    } else if (data.truncado) {
      mensaje.value = `Se muestran los primeros ${data.limite} tickets. Acote fechas o puesto.`
    } else {
      mensaje.value = `${data.totales.tickets} ticket(s). Total: ${numCsv(data.totales.importe)} €`
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
  const cab = [
    'Fecha',
    'Tienda',
    'Ticket',
    'Albarán',
    'Puesto',
    'Vendedor',
    'Cliente',
    'Nombre',
    'Importe',
    'Sesión',
    'Estado',
    'Forma pago',
  ]
  const lines = [cab.map(escCsv).join(';')]
  for (const r of filas) {
    lines.push(
      [
        r.fecha,
        r.empresa,
        r.numeroTicket ?? '',
        r.albaran,
        r.puesto,
        r.vendedor,
        r.cliente,
        r.razonSocial,
        numCsv(r.importe),
        r.sesion ?? '',
        r.estado,
        r.formaPago,
      ]
        .map(escCsv)
        .join(';')
    )
  }
  if (resultado.value) {
    lines.push('')
    lines.push(
      ['TOTAL', '', '', '', '', '', '', '', numCsv(resultado.value.totales.importe), '', '', '']
        .map(escCsv)
        .join(';')
    )
  }
  descargarCsv('informe-tickets.csv', lines)
  mensaje.value = `Excel (CSV) de ${filas.length} ticket(s)`
}

async function imprimir() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const t = resultado.value!.totales
  const res = await imprimirListadoHtml({
    titulo: 'Informe de tickets',
    metaLineas: metaImpresion(),
    thead: ['Fecha', 'Ticket', 'Puesto', 'Cliente', 'Importe', 'Fpago'],
    filas: filas.map((r) => [
      r.fecha,
      etiquetaTicket(r),
      r.puesto,
      r.razonSocial || r.cliente,
      numCsv(r.importe),
      r.formaPago,
    ]),
    pie: [`${t.tickets} tickets`, `Total: ${numCsv(t.importe)} €`],
    filenameFallback: 'informe-tickets.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('informe-tickets')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Informe de tickets"
    descripcion="Diario de tickets de venta (FacturaTipo T). Por defecto solo tickets numerados y no anulados."
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
        <select v-model="form.empresa" :disabled="loadingOpts">
          <option value="">Todas</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </label>
    </template>

    <template #mas-filtros>
      <label>
        <span>Puesto</span>
        <input v-model="form.puesto" type="text" maxlength="20" placeholder="Vacío = todos" />
      </label>
      <label>
        <span>Vendedor</span>
        <input v-model="form.vendedor" type="text" maxlength="20" placeholder="Código vendedor" />
      </label>
      <label class="check">
        <input v-model="form.soloNumerados" type="checkbox" />
        Solo tickets con número (Factura &gt; 0)
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
            <th>Fecha</th>
            <th>Ticket</th>
            <th>Tienda</th>
            <th>Puesto</th>
            <th>Cliente</th>
            <th class="num">Importe</th>
            <th>Sesión</th>
            <th>Estado</th>
            <th>Forma pago</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in resultado!.items" :key="`${row.empresa}-${row.albaran}-${i}`">
            <td>{{ row.fecha }}</td>
            <td>{{ etiquetaTicket(row) }}</td>
            <td>{{ row.empresa }}</td>
            <td>{{ row.puesto }}</td>
            <td>{{ row.razonSocial || row.cliente }}</td>
            <td class="num">{{ numCsv(row.importe) }}</td>
            <td class="num">{{ row.sesion ?? '—' }}</td>
            <td>{{ row.estado }}</td>
            <td>{{ row.formaPago }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5"><strong>Totales</strong></td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.importe) }}</strong>
            </td>
            <td colspan="3">
              <strong>{{ resultado!.totales.tickets }} tickets</strong>
            </td>
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
