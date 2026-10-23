<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { obtenerSituacionVentas } from '@/api/ventas'
import type { SituacionVentasResponse } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { api } from '@/api/client'
import DecimalInput from '@/components/common/DecimalInput.vue'

type Opt = { value: string; label: string }
type PuestoOpt = Opt & { ultSesion: number }

const puestoContexto = usePuestoContextoStore()
const hoy = new Date().toISOString().slice(0, 10)

const loading = ref(false)
const loadingOpts = ref(false)
const error = ref<string | null>(null)
const data = ref<SituacionVentasResponse | null>(null)
const puestos = ref<PuestoOpt[]>([])
const modo = ref<'sesion' | 'fechas'>('sesion')
const form = ref({
  puesto: '',
  sesion: 1,
  fechaDesde: hoy,
  fechaHasta: hoy,
})

function euros(n: number) {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fechaCorta(iso: string | null) {
  if (!iso) return '—'
  const m = iso.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/)
  if (!m) return iso
  return m[4] ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : `${m[3]}/${m[2]}/${m[1]}`
}

async function cargarPuestos() {
  loadingOpts.value = true
  try {
    const res = await api.get('/api/mantenimiento/puestos-trabajo', { params: { pageSize: 500 } })
    puestos.value = (res.data.items ?? [])
      .map((p: { codigo?: string; descripcion?: string; ultSesion?: number | null }) => {
        const codigo = String(p.codigo ?? '').trim()
        const ult = Number(p.ultSesion ?? 0)
        return {
          value: codigo,
          label: `${codigo} — ${p.descripcion ?? ''}`,
          ultSesion: ult > 0 ? ult : 1,
        }
      })
      .sort((a: PuestoOpt, b: PuestoOpt) => a.value.localeCompare(b.value, undefined, { numeric: true }))
    const puestoPc = String(puestoContexto.puestoCodigo ?? '').trim()
    const elegido = puestos.value.find((p) => p.value === puestoPc) ?? puestos.value[0]
    if (elegido) {
      form.value.puesto = elegido.value
      form.value.sesion = elegido.ultSesion
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar los puestos')
  } finally {
    loadingOpts.value = false
  }
}

function onPuesto() {
  const p = puestos.value.find((x) => x.value === form.value.puesto)
  if (p && modo.value === 'sesion') form.value.sesion = p.ultSesion
}

async function buscar() {
  loading.value = true
  error.value = null
  try {
    if (modo.value === 'sesion') {
      data.value = await obtenerSituacionVentas({
        modo: 'sesion',
        puesto: form.value.puesto.trim() || undefined,
        sesion: Number(form.value.sesion) || 0,
      })
    } else {
      data.value = await obtenerSituacionVentas({
        modo: 'fechas',
        puesto: form.value.puesto.trim() || undefined,
        fechaDesde: form.value.fechaDesde,
        fechaHasta: form.value.fechaHasta,
      })
    }
  } catch (e: unknown) {
    data.value = null
    error.value = extractApiError(e, 'No se pudo consultar la situación de ventas')
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await cargarPuestos()
  if (form.value.puesto) await buscar()
})
</script>

<template>
  <section class="situacion-view">
    <h2>Situación de ventas</h2>
    <p class="hint">
      Los mismos totales del terminal: tickets, facturas, albaranes y efectivo de la sesión.
      Por fechas suma las sesiones que empiezan en ese periodo.
    </p>
    <p v-if="error" class="error">{{ error }}</p>

    <form class="ficha-header" @submit.prevent="buscar">
      <label>
        Buscar por
        <select v-model="modo">
          <option value="sesion">Sesión</option>
          <option value="fechas">Fechas</option>
        </select>
      </label>
      <label class="campo-puesto">
        Puesto
        <select v-model="form.puesto" :disabled="loadingOpts" @change="onPuesto">
          <option value="">Todos</option>
          <option v-for="p in puestos" :key="p.value" :value="p.value">{{ p.label }}</option>
        </select>
      </label>
      <label v-if="modo === 'sesion'" class="campo-sesion">
        Sesión
        <DecimalInput v-model="form.sesion" :empty-as-null="false" :integer="true" :required="true" />
      </label>
      <template v-else>
        <label>
          Desde
          <input v-model="form.fechaDesde" type="date" required />
        </label>
        <label>
          Hasta
          <input v-model="form.fechaHasta" type="date" required />
        </label>
      </template>
      <button type="submit" class="tool-btn primary" :disabled="loading || loadingOpts">
        {{ loading ? 'Consultando…' : 'Consultar' }}
      </button>
    </form>

    <div v-if="data" class="panel">
      <p class="meta">
        <template v-if="data.modo === 'sesion'">
          <template v-if="data.puesto">Puesto <strong>{{ data.puesto }}</strong></template>
          <template v-else>Todos los puestos</template>
          · Sesión <strong>{{ data.sesion }}</strong>
        </template>
        <template v-else>
          Del <strong>{{ fechaCorta(data.fechaDesde) }}</strong> al
          <strong>{{ fechaCorta(data.fechaHasta) }}</strong>
          ·
          <template v-if="data.puesto">Puesto <strong>{{ data.puesto }}</strong></template>
          <template v-else>Todos los puestos</template>
          · <strong>{{ data.sesiones }}</strong> sesiones
        </template>
      </p>

      <table class="cuadro">
        <thead>
          <tr>
            <th></th>
            <th class="num">Número</th>
            <th class="num">Importe</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <th>Tickets</th>
            <td class="num">{{ data.tickets }}</td>
            <td class="num">{{ euros(data.importeTickets) }}</td>
          </tr>
          <tr>
            <th>Facturas</th>
            <td class="num">{{ data.facturas }}</td>
            <td class="num">{{ euros(data.importeFacturas) }}</td>
          </tr>
          <tr class="total">
            <th>Total</th>
            <td class="num">{{ data.totalNumero }}</td>
            <td class="num">{{ euros(data.totalImporte) }}</td>
          </tr>
          <tr>
            <th>Albaranes</th>
            <td class="num">{{ data.albaranes }}</td>
            <td class="num">{{ euros(data.importeAlbaranes) }}</td>
          </tr>
          <tr class="total">
            <th>Total</th>
            <td class="num">{{ data.totalConAlbaranesNumero }}</td>
            <td class="num">{{ euros(data.totalConAlbaranesImporte) }}</td>
          </tr>
          <tr class="efectivo">
            <th>Efectivo</th>
            <td></td>
            <td class="num">{{ euros(data.efectivo) }}</td>
          </tr>
        </tbody>
      </table>
      <p class="hint pie">
        El primer total es tickets más facturas. El segundo suma los albaranes.
        Efectivo es el acumulado de caja de las formas de pago de efectivo.
      </p>

      <template v-if="data.detalle.length || data.modo === 'fechas'">
        <h3>{{ data.modo === 'fechas' ? 'Sesiones del periodo' : 'Puestos' }}</h3>
        <p v-if="data.truncado" class="hint">Se muestran las 300 sesiones más recientes.</p>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th>Puesto</th>
                <th>Sesión</th>
                <th>Inicio</th>
                <th class="num">Tickets</th>
                <th class="num">Facturas</th>
                <th class="num">Albaranes</th>
                <th class="num">Importe</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in data.detalle" :key="`${s.empresa}-${s.puesto}-${s.sesion}`">
                <td>{{ s.puesto }}</td>
                <td>{{ s.sesion }}</td>
                <td>{{ fechaCorta(s.fechaInicio) }}</td>
                <td class="num">{{ s.tickets }}</td>
                <td class="num">{{ s.facturas }}</td>
                <td class="num">{{ s.albaranes }}</td>
                <td class="num">
                  {{ euros(s.importeTickets + s.importeFacturas + s.importeAlbaranes) }}
                </td>
              </tr>
              <tr v-if="!data.detalle.length">
                <td colspan="7">No hay sesiones.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </div>
  </section>
</template>

<style scoped>
.hint {
  color: #64748b;
  font-size: 0.85rem;
  margin: 0.25rem 0 0.6rem;
}
.hint.pie {
  max-width: 28rem;
}
.error {
  color: #b91c1c;
}
.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.65rem;
  align-items: end;
  width: fit-content;
  max-width: 100%;
  margin: 0 0 0.75rem;
  padding: 0.4rem 0.5rem;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
}
.ficha-header label {
  display: grid;
  gap: 0.1rem;
  font-size: 0.75rem;
}
.ficha-header input,
.ficha-header select {
  padding: 0.15rem 0.35rem;
  border: 1px solid #c5cdd8;
  border-radius: 3px;
  font-size: 0.78rem;
  height: 1.65rem;
  box-sizing: border-box;
}
.campo-puesto select {
  width: 16rem;
  max-width: 100%;
}
.campo-sesion :deep(input) {
  width: 4.5rem;
}
.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}
.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}
.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.panel {
  width: fit-content;
  max-width: 100%;
}
.meta {
  margin: 0 0 0.45rem;
  font-size: 0.85rem;
}
.cuadro {
  border-collapse: collapse;
  background: #fff;
  font-size: 0.9rem;
  min-width: 22rem;
}
.cuadro th,
.cuadro td {
  border: 1px solid #94a3b8;
  padding: 0.28rem 0.55rem;
}
.cuadro thead th {
  background: #f1f5f9;
  font-weight: 600;
  text-align: center;
}
.cuadro tbody th {
  text-align: left;
  font-weight: 600;
  background: #fff;
  width: 8rem;
}
.cuadro tr.total th,
.cuadro tr.total td {
  font-weight: 700;
  background: #f8fafc;
}
.cuadro tr.efectivo th,
.cuadro tr.efectivo td {
  background: #fff;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.cuadro .num {
  width: 7.5rem;
}
h3 {
  margin: 1rem 0 0.35rem;
  font-size: 0.95rem;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  max-width: 100%;
}
.grid-wrap table {
  border-collapse: collapse;
  font-size: 0.8rem;
}
.grid-wrap th,
.grid-wrap td {
  border: 1px solid #cbd5e1;
  padding: 0.15rem 0.4rem;
  white-space: nowrap;
}
.grid-wrap th {
  background: linear-gradient(180deg, #f1f5f9 0%, #e2e8f0 100%);
  text-align: center;
}
</style>
