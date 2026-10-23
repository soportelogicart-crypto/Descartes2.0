<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { listarCobrosPagos } from '@/api/ventas'
import type { CobroPago } from '@/types/ventas'
import { GRID_LIMITE_INICIAL } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { api } from '@/api/client'

type Opt = { value: string; label: string }

const puestoContexto = usePuestoContextoStore()
const hoy = new Date().toISOString().slice(0, 10)
const loading = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const items = ref<CobroPago[]>([])
const total = ref(0)
const formasPago = ref<Opt[]>([])
const puestos = ref<Opt[]>([])
const filtros = ref({
  tipo: 'todos',
  formaPago: '',
  fechaDesde: hoy,
  fechaHasta: hoy,
  puesto: '',
})

function fechaTabla(iso: string | null | undefined) {
  if (!iso) return '—'
  return iso.slice(0, 19).replace('T', ' ')
}

function etiquetaMovimiento(t: CobroPago['tipo']) {
  return t === 'pago' ? 'Pago' : 'Cobro'
}

function etiquetaForma(codigo: string) {
  const c = String(codigo ?? '').trim()
  if (!c) return '—'
  const f = formasPago.value.find((x) => x.value === c)
  return f?.label ?? c
}

async function cargarOpciones() {
  loadingOpts.value = true
  try {
    const [fpRes, puestosRes] = await Promise.all([
      api.get('/api/mantenimiento/formas-pago', { params: { pageSize: 500, activo: true } }),
      api.get('/api/mantenimiento/puestos-trabajo', { params: { pageSize: 500 } }),
    ])
    formasPago.value = (fpRes.data.items ?? [])
      .map((f: { codigo?: string; descripcion?: string }) => {
        const codigo = String(f.codigo ?? '').trim()
        return {
          value: codigo,
          label: `${codigo} — ${String(f.descripcion ?? '').trim()}`,
        }
      })
      .filter((f: Opt) => f.value !== '')
      .sort((a: Opt, b: Opt) => a.value.localeCompare(b.value, undefined, { numeric: true }))
    puestos.value = (puestosRes.data.items ?? [])
      .map((p: { codigo?: string; descripcion?: string }) => {
        const codigo = String(p.codigo ?? '').trim()
        return {
          value: codigo,
          label: `${codigo} — ${String(p.descripcion ?? '').trim()}`,
        }
      })
      .filter((p: Opt) => p.value !== '')
      .sort((a: Opt, b: Opt) => a.value.localeCompare(b.value, undefined, { numeric: true }))

    const puestoPc = String(puestoContexto.puestoCodigo ?? '').trim()
    if (puestoPc && puestos.value.some((p) => p.value === puestoPc)) {
      filtros.value.puesto = puestoPc
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar formas de pago y puestos')
  } finally {
    loadingOpts.value = false
  }
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const data = await listarCobrosPagos({
      tipo: filtros.value.tipo,
      formaPago: filtros.value.formaPago || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
      puesto: filtros.value.puesto || undefined,
      page: 1,
      pageSize: GRID_LIMITE_INICIAL,
    })
    items.value = data.items
    total.value = data.total
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar cobros/pagos')
  } finally {
    loading.value = false
  }
}

function buscar() {
  return cargar()
}

const hayResultados = computed(() => items.value.length > 0)

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

onMounted(async () => {
  await cargarOpciones()
  await cargar()
})
</script>

<template>
  <section class="cobros-page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Cobros y Pagos</h2>
        <p class="hint">Clasificación según FormasPago.CobroPago (C = cobro, P = pago).</p>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel filtros-form" @submit.prevent="buscar">
          <div class="fila">
            <span class="fila-label">Tipo</span>
            <select v-model="filtros.tipo" class="fila-control">
              <option value="todos">Todos</option>
              <option value="cobro">Cobro</option>
              <option value="pago">Pago</option>
            </select>
          </div>
          <div class="fila">
            <span class="fila-label">Forma pago</span>
            <select v-model="filtros.formaPago" class="fila-control" :disabled="loadingOpts">
              <option value="">Todas</option>
              <option v-for="f in formasPago" :key="f.value" :value="f.value">{{ f.label }}</option>
            </select>
          </div>
          <div class="fila">
            <span class="fila-label">Puesto</span>
            <select v-model="filtros.puesto" class="fila-control" :disabled="loadingOpts">
              <option value="">Todos</option>
              <option v-for="p in puestos" :key="p.value" :value="p.value">{{ p.label }}</option>
            </select>
          </div>
          <div class="fila fechas">
            <span class="fila-label">Desde</span>
            <input v-model="filtros.fechaDesde" type="date" class="fila-control" />
          </div>
          <div class="fila fechas">
            <span class="fila-label">Hasta</span>
            <input v-model="filtros.fechaHasta" type="date" class="fila-control" />
          </div>
          <button type="submit" class="btn primary btn-buscar" :disabled="loading || loadingOpts">
            {{ loading ? 'Buscando…' : 'Buscar' }}
          </button>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="avisoLimite" class="hint aviso-limite">{{ avisoLimite }}</p>
        <p v-if="loading && !hayResultados" class="hint">Cargando movimientos…</p>

        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th>Movimiento</th>
                <th>Forma pago</th>
                <th class="num">Importe</th>
                <th>Fecha</th>
                <th>Puesto</th>
                <th>Empresa</th>
                <th>Tipo doc.</th>
                <th class="num">Albarán</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="(m, i) in items" :key="`${m.empresa}-${m.tipoAlbaran}-${m.albaran}-${i}`">
                <td>{{ etiquetaMovimiento(m.tipo) }}</td>
                <td>{{ etiquetaForma(m.formaPago) }}</td>
                <td class="num">{{ Number(m.importe).toFixed(2) }}</td>
                <td>{{ fechaTabla(m.fecha) }}</td>
                <td>{{ m.puesto ?? '—' }}</td>
                <td>{{ m.empresa }}</td>
                <td>{{ m.tipoAlbaran }}</td>
                <td class="num">{{ m.albaran }}</td>
              </tr>
              <tr v-if="!loading && !items.length">
                <td colspan="8">Sin movimientos</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.cobros-page {
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
  grid-template-columns: minmax(14rem, 16.5rem) minmax(0, 1fr);
  gap: 0.75rem;
  align-items: start;
  min-height: 0;
}
@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.sidebar {
  width: 100%;
  max-width: 16.5rem;
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
  align-items: center;
}
.fila-label {
  font-size: 0.75rem;
  color: #334155;
  font-weight: 500;
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
.btn-buscar {
  width: 100%;
  margin-top: 0.25rem;
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
</style>
