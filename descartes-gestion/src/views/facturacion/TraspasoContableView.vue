<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ejecutarTraspasoContable, listarTraspasoContable } from '@/api/facturacion'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import type { FacturaTraspasoContable } from '@/types/facturacion'

const { puede } = usePermisos()
const hoy = new Date().toISOString().slice(0, 10)
const inicioMes = `${hoy.slice(0, 8)}01`

const loading = ref(false)
const traspasando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<FacturaTraspasoContable[]>([])
const seleccion = ref(new Set<string>())
const filtros = ref({
  empresa: '',
  facturaTipo: '',
  fechaDesde: inicioMes,
  fechaHasta: hoy,
})

const clave = (f: FacturaTraspasoContable) => `${f.empresa}|${f.facturaTipo}|${f.factura}`
const todasSeleccionadas = computed(
  () => items.value.length > 0 && items.value.every((f) => seleccion.value.has(clave(f)))
)
const seleccionadas = computed(() => items.value.filter((f) => seleccion.value.has(clave(f))))
const importeSeleccionado = computed(() =>
  seleccionadas.value.reduce((total, f) => total + Number(f.importe || 0), 0)
)

async function cargar() {
  if (!puede('facturacion-contabilidad', 'ver')) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarTraspasoContable({
      empresa: filtros.value.empresa || undefined,
      facturaTipo: filtros.value.facturaTipo || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
    })
    items.value = data.items
    seleccion.value = new Set()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron consultar las facturas pendientes')
  } finally {
    loading.value = false
  }
}

function toggle(f: FacturaTraspasoContable) {
  const nueva = new Set(seleccion.value)
  const k = clave(f)
  if (nueva.has(k)) nueva.delete(k)
  else nueva.add(k)
  seleccion.value = nueva
}

function toggleTodas() {
  seleccion.value = todasSeleccionadas.value
    ? new Set()
    : new Set(items.value.map(clave))
}

async function traspasar() {
  if (!puede('facturacion-contabilidad', 'crear')) {
    error.value = 'No tiene permiso para realizar el traspaso contable'
    return
  }
  if (seleccionadas.value.length === 0) {
    error.value = 'Seleccione al menos una factura'
    return
  }
  const texto =
    `Se crearán el libro de emitidas, los asientos y los efectos de cobro de ` +
    `${seleccionadas.value.length} factura(s). ¿Continuar?`
  if (!window.confirm(texto)) return

  traspasando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await ejecutarTraspasoContable(
      seleccionadas.value.map((f) => ({
        empresa: f.empresa,
        facturaTipo: f.facturaTipo,
        factura: f.factura,
      }))
    )
    mensaje.value =
      `Traspasadas ${data.totales.facturas} factura(s): ` +
      `${data.totales.asientos} asiento(s) y ${data.totales.efectos} efecto(s).`
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo completar el traspaso contable')
  } finally {
    traspasando.value = false
  }
}

function dinero(value: number) {
  return Number(value || 0).toLocaleString('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

onMounted(cargar)
</script>

<template>
  <section class="page">
    <header>
      <div>
        <h2>Traspaso contable</h2>
        <p>Genera libro de facturas emitidas, asiento contable y efectos de cobro.</p>
      </div>
      <button
        class="primary"
        :disabled="traspasando || seleccionadas.length === 0"
        @click="traspasar"
      >
        {{ traspasando ? 'Traspasando…' : `Traspasar (${seleccionadas.length})` }}
      </button>
    </header>

    <form class="filtros" @submit.prevent="cargar">
      <label>Empresa <input v-model.trim="filtros.empresa" maxlength="3" /></label>
      <label>
        Tipo
        <select v-model="filtros.facturaTipo">
          <option value="">Todos</option>
          <option value="F">Facturas</option>
          <option value="A">Abonos</option>
        </select>
      </label>
      <label>Desde <input v-model="filtros.fechaDesde" type="date" /></label>
      <label>Hasta <input v-model="filtros.fechaHasta" type="date" /></label>
      <button type="submit" :disabled="loading">{{ loading ? 'Buscando…' : 'Buscar' }}</button>
    </form>

    <p v-if="error" class="alert error">{{ error }}</p>
    <p v-if="mensaje" class="alert ok">{{ mensaje }}</p>

    <div class="resumen">
      <span>{{ items.length }} pendiente(s)</span>
      <span>Seleccionado: {{ dinero(importeSeleccionado) }} €</span>
    </div>

    <div class="tabla-wrap">
      <table>
        <thead>
          <tr>
            <th><input type="checkbox" :checked="todasSeleccionadas" @change="toggleTodas" /></th>
            <th>Empresa</th>
            <th>Tipo</th>
            <th>Factura</th>
            <th>Fecha</th>
            <th>Cliente</th>
            <th>Razón social</th>
            <th>F. pago</th>
            <th>Efectos</th>
            <th class="numero">Importe</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="f in items" :key="clave(f)" @dblclick="toggle(f)">
            <td>
              <input type="checkbox" :checked="seleccion.has(clave(f))" @change="toggle(f)" />
            </td>
            <td>{{ f.empresa }}</td>
            <td>{{ f.facturaTipo }}</td>
            <td>{{ f.factura }}</td>
            <td>{{ f.fecha }}</td>
            <td>{{ f.cliente }}</td>
            <td>{{ f.razonSocial }}</td>
            <td>{{ f.formaPago }}</td>
            <td class="numero">{{ f.numEfectos }}</td>
            <td class="numero">{{ dinero(f.importe) }} €</td>
          </tr>
          <tr v-if="!loading && items.length === 0">
            <td colspan="10" class="vacio">No hay facturas pendientes con esos filtros.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

<style scoped>
.page { padding: 1rem; }
header { display: flex; justify-content: space-between; gap: 1rem; align-items: start; }
h2 { margin: 0 0 .25rem; }
header p { margin: 0; color: #64748b; }
.filtros { display: flex; flex-wrap: wrap; gap: .75rem; align-items: end; margin: 1rem 0; }
label { display: grid; gap: .25rem; font-size: .85rem; }
input, select, button { min-height: 2rem; }
button { cursor: pointer; }
button:disabled { cursor: default; opacity: .55; }
.primary { padding: .5rem 1rem; background: #2563eb; color: white; border: 0; border-radius: .3rem; }
.alert { padding: .65rem .8rem; border-radius: .3rem; }
.error { color: #991b1b; background: #fee2e2; }
.ok { color: #166534; background: #dcfce7; }
.resumen { display: flex; justify-content: space-between; margin: .5rem 0; font-weight: 600; }
.tabla-wrap { overflow: auto; border: 1px solid #dbe2ea; }
table { width: 100%; border-collapse: collapse; white-space: nowrap; }
th, td { padding: .45rem .55rem; border-bottom: 1px solid #e5e7eb; text-align: left; }
th { position: sticky; top: 0; background: #f8fafc; }
tbody tr:hover { background: #eff6ff; }
.numero { text-align: right; }
.vacio { padding: 2rem; text-align: center; color: #64748b; }
</style>
