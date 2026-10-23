<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { liquidarValesLote, listarVales } from '@/api/ventas'
import type { Vale } from '@/types/ventas'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { etiquetaTipoVale } from '@/config/vales-tipo-legacy'
import { api } from '@/api/client'

type Opt = { value: string; label: string }

const { puede } = usePermisos()
const puedeEditar = computed(() => puede('ventas-vales', 'editar'))

const hoy = new Date().toISOString().slice(0, 10)
const loading = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const msg = ref<string | null>(null)
const items = ref<Vale[]>([])
const total = ref(0)
const tiendas = ref<Opt[]>([])

const filtros = ref({
  empresa: '',
  cliente: '',
  fechaDesde: hoy,
  fechaHasta: hoy,
})

const liquidarForm = ref({ fechaLiquidacion: hoy })
const seleccionados = ref<Set<string>>(new Set())
const modalLiquidar = ref(false)
const motivoOperador = ref('')
const liquidando = ref(false)

function claveVale(v: Vale) {
  return `${v.empresa}-${v.codigo}`
}

const seleccionadosLista = computed(() =>
  items.value.filter((v) => seleccionados.value.has(claveVale(v)) && !v.liquidado && !v.caducado)
)

const todosMarcados = computed(() => {
  const elegibles = items.value.filter((v) => !v.liquidado && !v.caducado)
  return elegibles.length > 0 && elegibles.every((v) => seleccionados.value.has(claveVale(v)))
})

function toggleTodos() {
  const elegibles = items.value.filter((v) => !v.liquidado && !v.caducado)
  if (todosMarcados.value) {
    seleccionados.value = new Set()
  } else {
    seleccionados.value = new Set(elegibles.map(claveVale))
  }
}

function toggleFila(v: Vale) {
  const k = claveVale(v)
  const next = new Set(seleccionados.value)
  if (next.has(k)) next.delete(k)
  else next.add(k)
  seleccionados.value = next
}

const avisoLimite = computed(() => {
  const datos = items.value.length
  const t = total.value
  if (t > datos && datos > 0) {
    return `Mostrando ${datos} de ${t}. Acote la búsqueda con los filtros.`
  }
  if (datos >= GRID_LIMITE_INICIAL) {
    return `Mostrando los ${GRID_LIMITE_INICIAL} primeros. Acote la búsqueda con los filtros.`
  }
  return ''
})

function fechaTabla(iso: string | null | undefined) {
  if (!iso) return '—'
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})/)
  if (m) return `${m[3]}/${m[2]}/${m[1]}`
  return iso.slice(0, 10)
}

function etiquetaEstado(v: Vale) {
  if (v.liquidado) return 'Liquidado'
  if (v.caducado) return 'Caducado'
  return 'Pendiente'
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const tiendasRes = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (tiendasRes.data.items ?? [])
      .map((t: { codigo?: string; descripcion?: string }) => {
        const codigo = String(t.codigo ?? '').trim()
        return { value: codigo, label: `${codigo} — ${String(t.descripcion ?? '').trim()}` }
      })
      .filter((t: Opt) => t.value !== '')
      .sort((a: Opt, b: Opt) => a.value.localeCompare(b.value, undefined, { numeric: true }))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar las tiendas')
  } finally {
    loadingOpts.value = false
  }
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarVales({
      estado: 'pendientes',
      alcance: 'liquidacion',
      empresa: filtros.value.empresa.trim() || undefined,
      cliente: filtros.value.cliente.trim() || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      page: 1,
      pageSize: GRID_LIMITE_INICIAL,
    })
    items.value = data.items
    total.value = data.total
    seleccionados.value = new Set()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar vales')
  } finally {
    loading.value = false
  }
}

function buscar() {
  return cargar()
}

function abrirModalLiquidar() {
  if (!puedeEditar.value || !seleccionadosLista.value.length) return
  motivoOperador.value = ''
  modalLiquidar.value = true
}

async function confirmarLiquidarLote() {
  if (!puedeEditar.value || liquidando.value) return
  const motivo = motivoOperador.value.trim()
  if (!motivo) {
    error.value = 'Indique el motivo del operador'
    return
  }
  liquidando.value = true
  msg.value = null
  error.value = null
  try {
    const res = await liquidarValesLote({
      items: seleccionadosLista.value.map((v) => ({ empresa: v.empresa, codigo: v.codigo })),
      motivoOperador: motivo,
      fechaLiquidacion: liquidarForm.value.fechaLiquidacion,
    })
    modalLiquidar.value = false
    const n = res.liquidados.length
    msg.value =
      res.errores.length > 0
        ? `Liquidados ${n} vale(s). ${res.errores.length} con error.`
        : `Liquidados ${n} vale(s).`
    if (res.errores.length) {
      error.value = res.errores.map((e) => `${e.codigo}: ${e.message}`).join(' · ')
    }
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo liquidar')
  } finally {
    liquidando.value = false
  }
}

function docVale(v: Vale) {
  if (!v.factura) return '—'
  const ft = String(v.facturaTipo ?? '').trim()
  return ft ? `${ft} ${v.factura}` : String(v.factura)
}

onMounted(async () => {
  await cargarOpciones()
  await cargar()
})
</script>

<template>
  <section class="vales-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Liquidación de vales</h2>
        <p class="hint">
          Solo vales pendientes: regalo, compra y fidelización. Los movimientos de caja (I/S/P) no se listan aquí.
        </p>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel filtros-form" @submit.prevent="buscar">
          <div class="fila fechas">
            <span class="fila-label">Desde</span>
            <input v-model="filtros.fechaDesde" type="date" class="fila-control" />
          </div>
          <div class="fila fechas">
            <span class="fila-label">Hasta</span>
            <input v-model="filtros.fechaHasta" type="date" class="fila-control" />
          </div>
          <div class="fila fila-lookup">
            <span class="fila-label">Empresa</span>
            <div class="fila-stack">
              <select v-model="filtros.empresa" class="fila-control" :disabled="loadingOpts">
                <option value="">Todas</option>
                <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
              </select>
              <EntidadLookupField
                v-model="filtros.empresa"
                entidad="tiendas"
                compact
                titulo-modal="Buscar tienda"
              />
            </div>
          </div>
          <div class="fila fila-lookup">
            <span class="fila-label">Cliente</span>
            <EntidadLookupField
              v-model="filtros.cliente"
              entidad="clientes"
              compact
              titulo-modal="Buscar cliente"
            />
          </div>
          <button type="submit" class="btn primary btn-buscar" :disabled="loading || loadingOpts">
            {{ loading ? 'Buscando…' : 'Buscar' }}
          </button>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="msg" class="msg">{{ msg }}</p>
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="avisoLimite" class="hint aviso-limite">{{ avisoLimite }}</p>

        <div v-if="puedeEditar" class="barra-lote">
          <button
            type="button"
            class="btn primary"
            :disabled="!seleccionadosLista.length || loading"
            @click="abrirModalLiquidar"
          >
            Liquidar seleccionados ({{ seleccionadosLista.length }})
          </button>
        </div>

        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th v-if="puedeEditar" class="col-check">
                  <input type="checkbox" :checked="todosMarcados" @change="toggleTodos" />
                </th>
                <th>Empresa</th>
                <th class="num">Código</th>
                <th>Cliente</th>
                <th>Tipo</th>
                <th class="num">Importe</th>
                <th class="num">Saldo</th>
                <th>Documento</th>
                <th>Motivo op.</th>
                <th>Fecha</th>
                <th>Caducidad</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="v in items" :key="`${v.empresa}-${v.codigo}`">
                <td v-if="puedeEditar" class="col-check">
                  <input
                    type="checkbox"
                    :disabled="v.liquidado || v.caducado"
                    :checked="seleccionados.has(claveVale(v))"
                    @change="toggleFila(v)"
                  />
                </td>
                <td>{{ v.empresa }}</td>
                <td class="num">{{ v.codigo }}</td>
                <td>{{ v.cliente ?? '—' }}</td>
                <td>{{ etiquetaTipoVale(v.tipo, v.tipoLabel) }}</td>
                <td class="num">{{ Number(v.importe).toFixed(2) }}</td>
                <td class="num">{{ Number(v.saldoPendiente ?? v.importe).toFixed(2) }}</td>
                <td>{{ docVale(v) }}</td>
                <td>{{ v.motivoOperador || '—' }}</td>
                <td>{{ fechaTabla(v.fecha) }}</td>
                <td>{{ fechaTabla(v.fechaCaducidad) }}</td>
                <td>{{ etiquetaEstado(v) }}</td>
              </tr>
              <tr v-if="!loading && !items.length">
                <td :colspan="puedeEditar ? 12 : 11">Sin vales pendientes</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div v-if="modalLiquidar" class="modal-backdrop" @click.self="modalLiquidar = false">
      <form class="modal-panel" @submit.prevent="confirmarLiquidarLote">
        <h3>Liquidar vales</h3>
        <p class="hint">{{ seleccionadosLista.length }} vale(s) seleccionado(s).</p>
        <label class="modal-field">
          <span>Motivo operador</span>
          <input v-model="motivoOperador" maxlength="30" required autofocus />
        </label>
        <label class="modal-field">
          <span>Fecha liquidación</span>
          <input v-model="liquidarForm.fechaLiquidacion" type="date" required />
        </label>
        <div class="modal-actions">
          <button type="button" class="btn" :disabled="liquidando" @click="modalLiquidar = false">
            Cancelar
          </button>
          <button type="submit" class="btn primary" :disabled="liquidando">
            {{ liquidando ? 'Liquidando…' : 'Confirmar' }}
          </button>
        </div>
      </form>
    </div>
  </section>
</template>

<style scoped>
.vales-page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
}
.toolbar h2 {
  margin: 0;
  font-size: 1.15rem;
}
.hint {
  color: #64748b;
  font-size: 0.82rem;
  margin: 0.25rem 0 0;
}
.aviso-limite {
  margin: 0;
}
.layout {
  display: grid;
  grid-template-columns: minmax(14rem, 17rem) minmax(0, 1fr);
  gap: 0.75rem;
  align-items: start;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.sidebar {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  max-width: 17rem;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.65rem;
  background: #f8fafc;
  box-sizing: border-box;
}
.fila {
  display: grid;
  grid-template-columns: 5.2rem minmax(0, 1fr);
  gap: 0.35rem;
  align-items: start;
}
.fila-label {
  font-size: 0.75rem;
  color: #334155;
  font-weight: 500;
  padding-top: 0.25rem;
}
.fila-control {
  width: 100%;
  box-sizing: border-box;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  height: 1.65rem;
  font: inherit;
  font-size: 0.78rem;
  background: #fff;
}
.fila-stack {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  min-width: 0;
}
.fila-lookup :deep(.lookup-row) {
  width: 100%;
}
.fila-lookup :deep(.lookup-combo-inline) {
  flex: 1;
  min-width: 0;
}
.btn-buscar {
  width: 100%;
  margin-top: 0.2rem;
}
.btn {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
}
.btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}
.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.resultado {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.45rem;
  text-align: left;
  vertical-align: middle;
  white-space: nowrap;
}
th {
  background: #f1f5f9;
  font-weight: 600;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.msg {
  color: #166534;
  margin: 0;
}
.barra-lote {
  display: flex;
  gap: 0.5rem;
}
.col-check {
  width: 2rem;
  text-align: center;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 50;
  padding: 1rem;
}
.modal-panel {
  background: #fff;
  border-radius: 8px;
  padding: 1rem 1.1rem;
  width: min(22rem, 100%);
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.15);
}
.modal-panel h3 {
  margin: 0;
  font-size: 1rem;
}
.modal-field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
}
.modal-field input {
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
}
.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
}
</style>
