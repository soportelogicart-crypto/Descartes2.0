<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { generarExtractoClientes, type ExtractoClientesResult } from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { hoyIso, inicioAnioIso } from '@/composables/useAtajosFecha'
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
const puesto = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const resultado = ref<ExtractoClientesResult | null>(null)
const tiendas = ref<Opt[]>([])

const form = ref({
  fechaDesde: inicioAnioIso(),
  fechaHasta: hoyIso(),
  cliente: '',
  empresa: '',
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)

function metaImpresion(): string[] {
  const r = resultado.value
  const lines = [
    `Periodo: ${form.value.fechaDesde} — ${form.value.fechaHasta}`,
    r ? `Cliente: ${r.cliente} — ${r.razonSocial}` : `Cliente: ${form.value.cliente}`,
    form.value.empresa ? `Tienda: ${form.value.empresa}` : 'Tienda: todas',
    r ? `Saldo anterior: ${numCsv(r.saldoAnterior)} €` : '',
  ].filter(Boolean)
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
    const emp = puesto.empresaCodigo?.trim()
    if (emp && !form.value.empresa) form.value.empresa = normalizarEmpresaCodigo(emp)
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
  const cliente = form.value.cliente.trim()
  if (!cliente) {
    error.value = 'Indique el cliente (código o lupa).'
    return
  }
  const id = ++seq
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await generarExtractoClientes({
      fechaDesde: form.value.fechaDesde,
      fechaHasta: form.value.fechaHasta,
      cliente,
      empresa: form.value.empresa.trim() || undefined,
    })
    if (id !== seq) return
    resultado.value = data
    if (!data.items.length) {
      mensaje.value = `Sin movimientos en el periodo. Saldo anterior: ${numCsv(data.saldoAnterior)} €. Riesgo acumulado: ${numCsv(data.riesgoAcumulado)} €.`
    } else if (data.truncado) {
      mensaje.value = `Se muestran los primeros ${data.limite} movimientos. Acote el periodo. Saldo final periodo: ${numCsv(data.saldoFinal)} €.`
    } else {
      mensaje.value = `${data.totales.movimientos} movimiento(s). Saldo final periodo: ${numCsv(data.saldoFinal)} €. Riesgo acumulado (hoy): ${numCsv(data.riesgoAcumulado)} €.`
    }
  } catch (e: unknown) {
    if (id !== seq) return
    error.value = extractApiError(e, 'No se pudo generar el extracto')
    resultado.value = null
  } finally {
    if (id === seq) generando.value = false
  }
}

function exportarExcel() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const r = resultado.value!
  const cab = ['Fecha', 'Tipo', 'Documento', 'Tienda', 'Concepto', 'Debe', 'Haber', 'Saldo']
  const lines = [cab.map(escCsv).join(';')]
  lines.push(
    [
      '',
      '',
      '',
      '',
      'Saldo anterior',
      '',
      '',
      numCsv(r.saldoAnterior),
    ]
      .map(escCsv)
      .join(';')
  )
  for (const row of filas) {
    lines.push(
      [
        row.fecha,
        row.tipo,
        row.documento,
        row.empresa,
        row.concepto,
        numCsv(row.debe),
        numCsv(row.haber),
        numCsv(row.saldo),
      ]
        .map(escCsv)
        .join(';')
    )
  }
  const t = r.totales
  lines.push('')
  lines.push(
    ['TOTAL periodo', '', '', '', '', numCsv(t.debe), numCsv(t.haber), numCsv(r.saldoFinal)].map(escCsv).join(';')
  )
  descargarCsv('extracto-clientes.csv', lines)
  mensaje.value = 'Excel (CSV) exportado'
}

async function imprimir() {
  const filas = resultado.value?.items ?? []
  if (!filas.length) return
  const r = resultado.value!
  const t = r.totales
  const res = await imprimirListadoHtml({
    titulo: 'Extracto de clientes',
    metaLineas: metaImpresion(),
    thead: ['Fecha', 'Documento', 'Concepto', 'Debe', 'Haber', 'Saldo'],
    filas: filas.map((row) => [
      row.fecha,
      row.documento,
      row.concepto,
      numCsv(row.debe),
      numCsv(row.haber),
      numCsv(row.saldo),
    ]),
    pie: [
      `Total debe: ${numCsv(t.debe)} €`,
      `Total haber: ${numCsv(t.haber)} €`,
      `Saldo final periodo: ${numCsv(r.saldoFinal)} €`,
      `Riesgo acumulado (hoy): ${numCsv(r.riesgoAcumulado)} €`,
    ],
    filenameFallback: 'extracto-clientes.html',
  })
  mensaje.value = res.message
}

onMounted(() => {
  registrarReciente('extracto-clientes')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    titulo="Extracto de clientes"
    descripcion="Movimientos del cliente en el periodo: facturas, albaranes sin facturar y cobros registrados en la fecha de cobro del albarán. El saldo arrastra el periodo anterior (misma lógica). No incluye liquidación de recibos sin fecha en base de datos."
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label class="cliente-field">
        <span>Cliente</span>
        <EntidadLookupField v-model="form.cliente" entidad="clientes" titulo-modal="Buscar cliente" />
      </label>
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

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="resultado && !generando" class="resumen-saldos">
      <span><strong>Saldo anterior:</strong> {{ numCsv(resultado.saldoAnterior) }} €</span>
      <span v-if="tieneDatos"><strong>Saldo final periodo:</strong> {{ numCsv(resultado.saldoFinal) }} €</span>
      <span><strong>Riesgo acumulado:</strong> {{ numCsv(resultado.riesgoAcumulado) }} €</span>
    </div>

    <div v-if="tieneDatos" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Documento</th>
            <th>Tienda</th>
            <th>Concepto</th>
            <th class="num">Debe</th>
            <th class="num">Haber</th>
            <th class="num">Saldo</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, i) in resultado!.items" :key="`${row.documento}-${row.fecha}-${i}`">
            <td>{{ row.fecha }}</td>
            <td>{{ row.documento }}</td>
            <td>{{ row.empresa }}</td>
            <td>{{ row.concepto }}</td>
            <td class="num">{{ row.debe ? numCsv(row.debe) : '' }}</td>
            <td class="num">{{ row.haber ? numCsv(row.haber) : '' }}</td>
            <td class="num">{{ numCsv(row.saldo) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4"><strong>Totales periodo</strong></td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.debe) }}</strong>
            </td>
            <td class="num">
              <strong>{{ numCsv(resultado!.totales.haber) }}</strong>
            </td>
            <td class="num">
              <strong>{{ numCsv(resultado!.saldoFinal) }}</strong>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">No hay movimientos en el periodo.</p>
  </ListadoInformeLayout>
</template>

<style scoped>
@import './listado-grid.css';

.cliente-field {
  min-width: 14rem;
}

.resumen-saldos {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem 1.5rem;
  margin-bottom: 0.75rem;
  font-size: 0.9rem;
  color: #334155;
}

</style>
