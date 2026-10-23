<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { listarVentasTpv } from '@/api/tpv'
import FiltroLupaField from '@/components/common/FiltroLupaField.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import { extractApiError } from '@/composables/extractApiError'
import type { VentaResumen } from '@/types/ventas'

const props = defineProps<{
  open: boolean
  empresa: string
  aviso?: string | null
}>()

const emit = defineEmits<{
  ver: [VentaResumen]
  cerrar: []
}>()

const ESTADOS = [
  { value: '', label: 'Todos' },
  { value: 'B', label: 'Presupuesto o venta sin finalizar (B)' },
  { value: 'D', label: 'Otros (D)' },
  { value: 'F', label: 'Facturado (F)' },
  { value: 'G', label: 'Contado diferido (G)' },
] as const

const CLASES = [
  { value: '', label: 'Todos' },
  { value: 'albaran', label: 'Albarán' },
  { value: 'presupuesto', label: 'Presupuesto' },
  { value: 'factura', label: 'Factura' },
  { value: 'ticket', label: 'Ticket' },
] as const

type BuscarEntidad = 'clientes' | 'trabajadores' | 'puestos-trabajo'

const loading = ref(false)
const error = ref<string | null>(null)
const items = ref<VentaResumen[]>([])
const total = ref(0)
const tiendas = ref<{ value: string; label: string }[]>([])
const filtros = ref(filtrosVacios())
const buscarOpen = ref(false)
const buscarEntidad = ref<BuscarEntidad>('clientes')
const buscarCampo = ref<'cliente' | 'vendedor' | 'puesto'>('cliente')
const buscarTitulo = ref('Buscar')
const buscarInicial = ref('')

function filtrosVacios() {
  return {
    empresa: props.empresa.trim(),
    fechaDesde: '',
    fechaHasta: '',
    puesto: '',
    vendedor: '',
    cliente: '',
    estado: '',
    claseDocumento: '',
  }
}

function normalizarEmpresa(v: string): string {
  const t = v.trim()
  if (/^\d+$/.test(t)) return String(Number.parseInt(t, 10))
  return t
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    error.value = null
    items.value = []
    total.value = 0
    filtros.value = filtrosVacios()
    void cargarTiendas()
  }
)

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', {
      params: { activo: true, pageSize: 200 },
    })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = normalizarEmpresa(String(t.codigo))
      return { value: codigo, label: `${codigo} - ${t.nombre}` }
    })
  } catch {
    const codigo = normalizarEmpresa(props.empresa)
    tiendas.value = codigo ? [{ value: codigo, label: codigo }] : []
  }
}

async function buscar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarVentasTpv({
      empresa: filtros.value.empresa ? normalizarEmpresa(filtros.value.empresa) : undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      puesto: filtros.value.puesto || undefined,
      vendedor: filtros.value.vendedor || undefined,
      cliente: filtros.value.cliente || undefined,
      estado: filtros.value.estado || undefined,
      claseDocumento: filtros.value.claseDocumento || undefined,
      page: 1,
      pageSize: 200,
    })
    items.value = data.items
    total.value = data.total
  } catch (e: unknown) {
    items.value = []
    total.value = 0
    error.value = extractApiError(e, 'No se pudieron cargar las ventas')
  } finally {
    loading.value = false
  }
}

function abrirBuscar(campo: 'cliente' | 'vendedor' | 'puesto') {
  const mapa = {
    cliente: { entidad: 'clientes' as const, titulo: 'Buscar cliente', inicial: filtros.value.cliente },
    vendedor: {
      entidad: 'trabajadores' as const,
      titulo: 'Buscar vendedor',
      inicial: filtros.value.vendedor,
    },
    puesto: {
      entidad: 'puestos-trabajo' as const,
      titulo: 'Buscar puesto (caja/TPV)',
      inicial: filtros.value.puesto,
    },
  }
  const cfg = mapa[campo]
  buscarCampo.value = campo
  buscarEntidad.value = cfg.entidad
  buscarTitulo.value = cfg.titulo
  buscarInicial.value = cfg.inicial
  buscarOpen.value = true
}

function onEntidadSeleccionada(r: EntidadBuscarResultado) {
  filtros.value[buscarCampo.value] = r.codigo
  buscarOpen.value = false
}

function fmtFecha(iso: string | null | undefined) {
  return iso ? iso.slice(0, 10) : ''
}

function fmtFactura(v: VentaResumen) {
  const n = Number(v.factura ?? 0)
  if (Number.isFinite(n) && n > 0) {
    const t = String(v.facturaTipo ?? '').trim().toUpperCase() || 'F'
    return `${t}-${n}`
  }
  return '—'
}

function estadoTexto(v: VentaResumen) {
  const ft = String(v.facturaTipo ?? '').trim().toUpperCase()
  const estado = String(v.estado ?? '').trim().toUpperCase()
  const factura = Number(v.factura ?? 0)
  if (ft === 'A') return 'Abono'
  if (ft === 'F') return estado === 'G' ? 'Contado diferido' : 'Facturado'
  if (ft === 'T' && factura > 0) return 'Ticket'
  if (ft === 'R') return estado === 'B' && Number(v.sesion ?? 0) <= 0 ? 'Sin finalizar' : 'Presupuesto'
  if (estado === 'F') return 'Facturado'
  if (estado === 'G') return 'Contado diferido'
  if (estado === 'B') return 'Sin finalizar'
  return estado === '' ? 'Albarán' : estado
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay">
      <section class="ventana" role="dialog" aria-modal="true" aria-label="Consulta de tickets">
        <header class="barra">CONSULTA TICKETS</header>
        <div class="cuerpo">
          <form class="filtros" @submit.prevent="buscar">
            <label>
              Tienda
              <select v-model="filtros.empresa">
                <option value="">Todas</option>
                <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
            </label>
            <div class="fechas">
              <label>
                Desde
                <input v-model="filtros.fechaDesde" type="date" />
              </label>
              <label>
                Hasta
                <input v-model="filtros.fechaHasta" type="date" />
              </label>
            </div>
            <FiltroLupaField
              v-model="filtros.puesto"
              label="Puesto (caja/TPV)"
              placeholder="Código"
              maxlength="2"
              @buscar="abrirBuscar('puesto')"
            />
            <FiltroLupaField
              v-model="filtros.vendedor"
              label="Vendedor"
              placeholder="Código"
              maxlength="4"
              @buscar="abrirBuscar('vendedor')"
            />
            <FiltroLupaField
              v-model="filtros.cliente"
              label="Cliente"
              placeholder="Código / nombre"
              @buscar="abrirBuscar('cliente')"
            />
            <label>
              Tipo documento
              <select v-model="filtros.claseDocumento">
                <option v-for="c in CLASES" :key="c.value || 'todos'" :value="c.value">
                  {{ c.label }}
                </option>
              </select>
            </label>
            <label>
              Estado
              <select v-model="filtros.estado">
                <option v-for="e in ESTADOS" :key="e.value || 'todos'" :value="e.value">
                  {{ e.label }}
                </option>
              </select>
            </label>
            <button type="submit" class="buscar" :disabled="loading">
              {{ loading ? 'BUSCANDO…' : 'BUSCAR' }}
            </button>
          </form>

          <div class="resultados">
            <p v-if="aviso" class="error">{{ aviso }}</p>
            <p v-if="error" class="error">{{ error }}</p>
            <p v-else-if="loading" class="estado">Buscando…</p>
            <p v-else-if="total > items.length" class="estado">
              Mostrando {{ items.length }} de {{ total }}. Ajuste el filtro para acotar.
            </p>
            <div class="tabla-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Albarán</th>
                    <th>Cliente</th>
                    <th>Estado</th>
                    <th class="num">Importe</th>
                    <th>Factura</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="v in items" :key="`${v.empresa}-${v.tipo}-${v.albaran}`">
                    <td>{{ fmtFecha(v.fecha) }}</td>
                    <td>{{ v.tipo }}-{{ v.albaran }}</td>
                    <td>
                      <span class="cod">{{ v.cliente }}</span>
                      <span v-if="v.razonSocial">{{ v.razonSocial }}</span>
                    </td>
                    <td>{{ estadoTexto(v) }}</td>
                    <td class="num">{{ Number(v.importe ?? 0).toFixed(2) }}</td>
                    <td>{{ fmtFactura(v) }}</td>
                    <td>
                      <button type="button" class="ver" @click="emit('ver', v)">VER</button>
                    </td>
                  </tr>
                  <tr v-if="!loading && items.length === 0">
                    <td colspan="7">Sin resultados. Pulse Buscar.</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <footer class="pie">
          <button type="button" class="cerrar" @click="emit('cerrar')">CERRAR</button>
        </footer>
      </section>
    </div>

    <EntidadBuscarModal
      :open="buscarOpen"
      :entidad="buscarEntidad"
      :titulo="buscarTitulo"
      :busqueda-inicial="buscarInicial"
      @seleccionar="onEntidadSeleccionada"
      @cerrar="buscarOpen = false"
    />
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 4800;
  display: grid;
  place-items: center;
  padding: 0.75rem;
  background: rgb(0 0 0 / 55%);
}
.ventana {
  width: min(72rem, 98vw);
  height: min(42rem, 94vh);
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, sans-serif;
}
.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}
.cuerpo {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: 16.5rem minmax(0, 1fr);
  gap: 0.75rem;
  padding: 0.75rem;
}
.filtros {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-height: 0;
  overflow: auto;
  padding: 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #f8fafc;
}
.filtros label,
.fechas label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: #475569;
}
.filtros input,
.filtros select {
  width: 100%;
  box-sizing: border-box;
  padding: 0.35rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  background: #fff;
}
.fechas {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.35rem;
}
.buscar,
.ver,
.cerrar {
  min-height: 2.6rem;
  border: 1px solid #0f172a;
  border-radius: 6px;
  background: #0f172a;
  color: #fff;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}
.buscar:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.resultados {
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}
.tabla-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
th,
td {
  padding: 0.35rem 0.4rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: middle;
}
th {
  position: sticky;
  top: 0;
  background: #f1f5f9;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.cod {
  display: block;
  color: #64748b;
  font-size: 0.75rem;
}
.ver {
  min-height: 2.1rem;
  padding: 0 0.7rem;
  background: #1d4ed8;
  border-color: #1d4ed8;
}
.estado,
.error {
  margin: 0;
  font-size: 0.82rem;
}
.error {
  color: #991b1b;
}
.pie {
  display: flex;
  justify-content: flex-end;
  padding: 0.55rem 0.75rem 0.75rem;
}
.cerrar {
  min-width: 8rem;
  background: #fff;
  color: #0f172a;
}
</style>
