<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { api } from '@/api/client'
import ListadoInformeLayout from '@/components/listados/ListadoInformeLayout.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import {
  generarComisionesProveedor,
  generarComisionesTrabajadores,
  type ComisionPedidoFiltros,
  type ComisionProveedorFila,
  type ComisionTrabajadorFila,
  type ComisionesPedidoResult,
} from '@/api/listados'
import { extractApiError } from '@/composables/useMantenimiento'
import { useListadosRecientes } from '@/composables/useListadosRecientes'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'

type Opt = { value: string; label: string }

const route = useRoute()
const puesto = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const esProveedor = computed(() => route.name === 'listados-comisiones-proveedor')
const titulo = computed(() =>
  esProveedor.value ? 'Comisiones de proveedor' : 'Comisiones de trabajadores'
)

const generando = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const tiendas = ref<Opt[]>([])
const resultado = ref<ComisionesPedidoResult<ComisionProveedorFila | ComisionTrabajadorFila> | null>(null)

const form = reactive({
  fechaDesde: '',
  fechaHasta: '',
  tienda: '',
  pedidoDesde: '',
  pedidoHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  terceroDesde: '',
  terceroHasta: '',
})

const tieneDatos = computed(() => (resultado.value?.items.length ?? 0) > 0)
const filasProveedor = computed(() =>
  esProveedor.value ? ((resultado.value?.items ?? []) as ComisionProveedorFila[]) : []
)
const filasTrabajador = computed(() =>
  esProveedor.value ? [] : ((resultado.value?.items ?? []) as ComisionTrabajadorFila[])
)

function normalizarEmpresaCodigo(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

function params(): ComisionPedidoFiltros {
  const p: ComisionPedidoFiltros = {}
  if (form.tienda.trim()) p.tienda = form.tienda.trim()
  if (form.fechaDesde) p.fechaDesde = form.fechaDesde
  if (form.fechaHasta) p.fechaHasta = form.fechaHasta
  if (form.pedidoDesde.trim()) p.pedidoDesde = form.pedidoDesde.trim()
  if (form.pedidoHasta.trim()) p.pedidoHasta = form.pedidoHasta.trim()
  if (form.clienteDesde.trim()) p.clienteDesde = form.clienteDesde.trim()
  if (form.clienteHasta.trim()) p.clienteHasta = form.clienteHasta.trim()
  if (esProveedor.value) {
    if (form.terceroDesde.trim()) p.proveedorDesde = form.terceroDesde.trim()
    if (form.terceroHasta.trim()) p.proveedorHasta = form.terceroHasta.trim()
  } else {
    if (form.terceroDesde.trim()) p.trabajadorDesde = form.terceroDesde.trim()
    if (form.terceroHasta.trim()) p.trabajadorHasta = form.terceroHasta.trim()
  }
  return p
}

function metaImpresion(): string[] {
  const lineas = [
    form.fechaDesde || form.fechaHasta
      ? `Periodo: ${form.fechaDesde || '…'} — ${form.fechaHasta || '…'}`
      : 'Periodo: todos',
    form.tienda ? `Tienda: ${form.tienda}` : 'Tienda: todas',
  ]
  if (form.pedidoDesde || form.pedidoHasta) {
    lineas.push(`Pedido: ${form.pedidoDesde || '…'} — ${form.pedidoHasta || '…'}`)
  }
  if (form.clienteDesde || form.clienteHasta) {
    lineas.push(`Cliente: ${form.clienteDesde || '…'} — ${form.clienteHasta || '…'}`)
  }
  if (form.terceroDesde || form.terceroHasta) {
    const rotulo = esProveedor.value ? 'Proveedor' : 'Trabajador'
    lineas.push(`${rotulo}: ${form.terceroDesde || '…'} — ${form.terceroHasta || '…'}`)
  }
  lineas.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  return lineas
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
    tiendas.value = []
  } finally {
    loadingOpts.value = false
  }
}

async function generar() {
  if (form.fechaDesde && form.fechaHasta && form.fechaDesde > form.fechaHasta) {
    error.value = 'La fecha desde no puede ser posterior a la fecha hasta.'
    return
  }
  generando.value = true
  error.value = null
  mensaje.value = null
  try {
    resultado.value = esProveedor.value
      ? await generarComisionesProveedor(params())
      : await generarComisionesTrabajadores(params())
    if (resultado.value.truncado) {
      mensaje.value = `Listado limitado a ${resultado.value.limite} líneas.`
    }
  } catch (e: unknown) {
    resultado.value = null
    error.value = extractApiError(e, 'No se pudo generar el informe')
  } finally {
    generando.value = false
  }
}

function exportarExcel() {
  const r = resultado.value
  if (!r || !r.items.length) return
  const lines: string[] = []
  if (esProveedor.value) {
    lines.push(
      ['Fecha', 'Pedido', 'Cliente', 'Nombre', 'Proveedor', 'Nombre proveedor', 'Importe pedido', 'Comisión']
        .map(escCsv)
        .join(';')
    )
    for (const l of filasProveedor.value) {
      lines.push(
        [
          l.fecha,
          l.pedido,
          l.cliente,
          l.clienteNombre,
          l.proveedor,
          l.proveedorNombre,
          numCsv(l.importePedido),
          numCsv(l.comision),
        ]
          .map(escCsv)
          .join(';')
      )
    }
    lines.push(
      ['', '', '', '', '', 'Total', numCsv(r.totales.importePedido ?? 0), numCsv(r.totales.comision)]
        .map(escCsv)
        .join(';')
    )
  } else {
    lines.push(
      ['Fecha', 'Pedido', 'Cliente', 'Nombre', 'Trabajador', 'Nombre trabajador', 'Importe pedido', '%', 'Comisión']
        .map(escCsv)
        .join(';')
    )
    for (const l of filasTrabajador.value) {
      lines.push(
        [
          l.fecha,
          l.pedido,
          l.cliente,
          l.clienteNombre,
          l.trabajador,
          l.trabajadorNombre,
          numCsv(l.importePedido),
          numCsv(l.pje),
          numCsv(l.comision),
        ]
          .map(escCsv)
          .join(';')
      )
    }
    lines.push(
      ['', '', '', '', '', 'Total', '', '', numCsv(r.totales.comision)].map(escCsv).join(';')
    )
  }
  descargarCsv(esProveedor.value ? 'comisiones-proveedor.csv' : 'comisiones-trabajadores.csv', lines)
  mensaje.value = 'Excel (CSV) exportado'
}

async function imprimir() {
  const r = resultado.value
  if (!r || !r.items.length) return
  const filas = esProveedor.value
    ? filasProveedor.value.map((l) => [
        l.fecha,
        String(l.pedido),
        `${l.cliente} ${l.clienteNombre}`.trim(),
        `${l.proveedor} ${l.proveedorNombre}`.trim(),
        numCsv(l.importePedido),
        numCsv(l.comision),
      ])
    : filasTrabajador.value.map((l) => [
        l.fecha,
        String(l.pedido),
        `${l.cliente} ${l.clienteNombre}`.trim(),
        `${l.trabajador} ${l.trabajadorNombre}`.trim(),
        numCsv(l.importePedido),
        numCsv(l.pje),
        numCsv(l.comision),
      ])
  const res = await imprimirListadoHtml({
    titulo: titulo.value,
    metaLineas: metaImpresion(),
    thead: esProveedor.value
      ? ['Fecha', 'Pedido', 'Cliente', 'Proveedor', 'Importe pedido', 'Comisión']
      : ['Fecha', 'Pedido', 'Cliente', 'Trabajador', 'Importe pedido', '%', 'Comisión'],
    filas,
    pie: [`Total comisión: ${numCsv(r.totales.comision)} €`, `Líneas: ${r.totales.lineas}`],
    filenameFallback: esProveedor.value ? 'comisiones-proveedor.html' : 'comisiones-trabajadores.html',
  })
  mensaje.value = res.message
}

watch(
  () => route.name,
  (name) => {
    resultado.value = null
    error.value = null
    mensaje.value = null
    if (name === 'listados-comisiones-proveedor' || name === 'listados-comisiones-trabajadores') {
      registrarReciente(String(name).replace('listados-', ''))
    }
  }
)

onMounted(() => {
  registrarReciente(esProveedor.value ? 'comisiones-proveedor' : 'comisiones-trabajadores')
  void cargarTiendas()
})
</script>

<template>
  <ListadoInformeLayout
    :titulo="titulo"
    :descripcion="
      esProveedor
        ? 'Una línea por pedido con comisión de proveedor. El importe de la comisión es el guardado en el pedido.'
        : 'Una línea por comisión de trabajador. Si el pedido tiene dos, sale dos veces. El importe es el porcentaje sobre el total del pedido.'
    "
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
    </template>

    <template #mas-filtros>
      <div class="rangos">
        <div class="rango">
          <span class="rango-titulo">Pedido</span>
          <input v-model="form.pedidoDesde" inputmode="numeric" placeholder="Desde" />
          <input v-model="form.pedidoHasta" inputmode="numeric" placeholder="Hasta" />
        </div>
        <div class="rango">
          <span class="rango-titulo">Cliente</span>
          <EntidadLookupField v-model="form.clienteDesde" entidad="clientes" titulo-modal="Buscar cliente" />
          <EntidadLookupField v-model="form.clienteHasta" entidad="clientes" titulo-modal="Buscar cliente" />
        </div>
        <div class="rango">
          <span class="rango-titulo">{{ esProveedor ? 'Proveedor' : 'Trabajador' }}</span>
          <EntidadLookupField
            v-model="form.terceroDesde"
            :entidad="esProveedor ? 'proveedores' : 'trabajadores'"
            titulo-modal="Buscar"
          />
          <EntidadLookupField
            v-model="form.terceroHasta"
            :entidad="esProveedor ? 'proveedores' : 'trabajadores'"
            titulo-modal="Buscar"
          />
        </div>
      </div>
    </template>

    <template #aviso>
      <p v-if="error" class="flash flash-error">{{ error }}</p>
      <p v-else-if="mensaje" class="flash flash-ok">{{ mensaje }}</p>
    </template>

    <div v-if="tieneDatos && esProveedor" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Proveedor</th>
            <th class="num">Importe pedido</th>
            <th class="num">Comisión</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in filasProveedor" :key="`${l.empresa}-${l.pedido}`">
            <td>{{ l.fecha }}</td>
            <td>{{ l.pedido }}</td>
            <td>{{ l.cliente }} {{ l.clienteNombre }}</td>
            <td>{{ l.proveedor }} {{ l.proveedorNombre }}</td>
            <td class="num">{{ numCsv(l.importePedido) }}</td>
            <td class="num">{{ numCsv(l.comision) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="4"><strong>Total</strong></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.importePedido ?? 0) }}</strong></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.comision) }}</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <div v-else-if="tieneDatos" class="grid-wrap">
      <table class="grid">
        <thead>
          <tr>
            <th>Fecha</th>
            <th>Pedido</th>
            <th>Cliente</th>
            <th>Trabajador</th>
            <th class="num">Importe pedido</th>
            <th class="num">%</th>
            <th class="num">Comisión</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(l, i) in filasTrabajador" :key="`${l.empresa}-${l.pedido}-${l.trabajador}-${i}`">
            <td>{{ l.fecha }}</td>
            <td>{{ l.pedido }}</td>
            <td>{{ l.cliente }} {{ l.clienteNombre }}</td>
            <td>{{ l.trabajador }} {{ l.trabajadorNombre }}</td>
            <td class="num">{{ numCsv(l.importePedido) }}</td>
            <td class="num">{{ numCsv(l.pje) }}</td>
            <td class="num">{{ numCsv(l.comision) }}</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6"><strong>Total comisión</strong></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.comision) }}</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">No hay comisiones con los filtros indicados.</p>
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
</style>
