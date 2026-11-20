<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
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

const { t, locale } = useI18n()
const route = useRoute()
const puesto = usePuestoContextoStore()
const { registrarReciente } = useListadosRecientes()

const esProveedor = computed(() => route.name === 'listados-comisiones-proveedor')
const titulo = computed(() =>
  esProveedor.value ? t('listado.comisionesProveedorTitulo') : t('listado.comisionesTrabajadoresTitulo')
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
  const idiomaFecha = locale.value === 'ca' ? 'ca-ES' : locale.value === 'en' ? 'en-GB' : 'es-ES'
  const lineas = [
    form.fechaDesde || form.fechaHasta
      ? t('listado.periodo', { desde: form.fechaDesde || '…', hasta: form.fechaHasta || '…' })
      : t('listado.periodoTodos'),
    form.tienda ? t('listado.tiendaFiltro', { nombre: form.tienda }) : t('listado.tiendaTodas'),
  ]
  if (form.pedidoDesde || form.pedidoHasta) {
    lineas.push(t('listado.rango', { rotulo: t('listado.pedido'), desde: form.pedidoDesde || '…', hasta: form.pedidoHasta || '…' }))
  }
  if (form.clienteDesde || form.clienteHasta) {
    lineas.push(t('listado.rango', { rotulo: t('listado.cliente'), desde: form.clienteDesde || '…', hasta: form.clienteHasta || '…' }))
  }
  if (form.terceroDesde || form.terceroHasta) {
    const rotulo = esProveedor.value ? t('listado.proveedor') : t('listado.trabajador')
    lineas.push(t('listado.rango', { rotulo, desde: form.terceroDesde || '…', hasta: form.terceroHasta || '…' }))
  }
  lineas.push(t('listado.generado', { cuando: new Date().toLocaleString(idiomaFecha) }))
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
    error.value = t('listado.fechaInvalida')
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
      mensaje.value = t('listado.limitado', { limite: resultado.value.limite })
    }
  } catch (e: unknown) {
    resultado.value = null
    error.value = extractApiError(e, t('listado.errorGenerar'))
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
      [t('listado.fecha'), t('listado.pedido'), t('listado.cliente'), t('usuarios.nombre'), t('listado.proveedor'), t('listado.proveedor'), t('listado.importePedido'), t('listado.comision')]
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
      ['', '', '', '', '', t('listado.total'), numCsv(r.totales.importePedido ?? 0), numCsv(r.totales.comision)]
        .map(escCsv)
        .join(';')
    )
  } else {
    lines.push(
      [t('listado.fecha'), t('listado.pedido'), t('listado.cliente'), t('usuarios.nombre'), t('listado.trabajador'), t('listado.trabajador'), t('listado.importePedido'), t('listado.porcentaje'), t('listado.comision')]
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
      ['', '', '', '', '', t('listado.total'), '', '', numCsv(r.totales.comision)].map(escCsv).join(';')
    )
  }
  descargarCsv(esProveedor.value ? 'comisiones-proveedor.csv' : 'comisiones-trabajadores.csv', lines)
  mensaje.value = t('listado.excelOk')
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
      ? [t('listado.fecha'), t('listado.pedido'), t('listado.cliente'), t('listado.proveedor'), t('listado.importePedido'), t('listado.comision')]
      : [t('listado.fecha'), t('listado.pedido'), t('listado.cliente'), t('listado.trabajador'), t('listado.importePedido'), t('listado.porcentaje'), t('listado.comision')],
    filas,
    pie: [t('listado.pieComision', { importe: numCsv(r.totales.comision) }), t('listado.pieLineas', { n: r.totales.lineas })],
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
        ? t('listado.comisionesProveedorDesc')
        : t('listado.comisionesTrabajadoresDesc')
    "
    :generando="generando"
    :tiene-datos="tieneDatos"
    @generar="generar"
    @excel="exportarExcel"
    @imprimir="imprimir"
  >
    <template #filtros>
      <label>
        <span>{{ t('listado.desde') }}</span>
        <input v-model="form.fechaDesde" type="date" />
      </label>
      <label>
        <span>{{ t('listado.hasta') }}</span>
        <input v-model="form.fechaHasta" type="date" />
      </label>
      <label>
        <span>{{ t('listado.tienda') }}</span>
        <select v-model="form.tienda" :disabled="loadingOpts">
          <option value="">{{ t('listado.todas') }}</option>
          <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </label>
    </template>

    <template #mas-filtros>
      <div class="rangos">
        <div class="rango">
          <span class="rango-titulo">{{ t('listado.pedido') }}</span>
          <input v-model="form.pedidoDesde" inputmode="numeric" :placeholder="t('listado.desde')" />
          <input v-model="form.pedidoHasta" inputmode="numeric" :placeholder="t('listado.hasta')" />
        </div>
        <div class="rango">
          <span class="rango-titulo">{{ t('listado.cliente') }}</span>
          <EntidadLookupField v-model="form.clienteDesde" entidad="clientes" :titulo-modal="t('listado.buscarCliente')" />
          <EntidadLookupField v-model="form.clienteHasta" entidad="clientes" :titulo-modal="t('listado.buscarCliente')" />
        </div>
        <div class="rango">
          <span class="rango-titulo">{{ esProveedor ? t('listado.proveedor') : t('listado.trabajador') }}</span>
          <EntidadLookupField
            v-model="form.terceroDesde"
            :entidad="esProveedor ? 'proveedores' : 'trabajadores'"
            :titulo-modal="t('listado.buscar')"
          />
          <EntidadLookupField
            v-model="form.terceroHasta"
            :entidad="esProveedor ? 'proveedores' : 'trabajadores'"
            :titulo-modal="t('listado.buscar')"
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
            <th>{{ t('listado.fecha') }}</th>
            <th>{{ t('listado.pedido') }}</th>
            <th>{{ t('listado.cliente') }}</th>
            <th>{{ t('listado.proveedor') }}</th>
            <th class="num">{{ t('listado.importePedido') }}</th>
            <th class="num">{{ t('listado.comision') }}</th>
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
            <td colspan="4"><strong>{{ t('listado.total') }}</strong></td>
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
            <th>{{ t('listado.fecha') }}</th>
            <th>{{ t('listado.pedido') }}</th>
            <th>{{ t('listado.cliente') }}</th>
            <th>{{ t('listado.trabajador') }}</th>
            <th class="num">{{ t('listado.importePedido') }}</th>
            <th class="num">{{ t('listado.porcentaje') }}</th>
            <th class="num">{{ t('listado.comision') }}</th>
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
            <td colspan="6"><strong>{{ t('listado.totalComision') }}</strong></td>
            <td class="num"><strong>{{ numCsv(resultado!.totales.comision) }}</strong></td>
          </tr>
        </tfoot>
      </table>
    </div>
    <p v-else-if="resultado && !generando" class="sin-datos">{{ t('listado.sinComisiones') }}</p>
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
