<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

type Agrupacion = 'macrofamilia' | 'familia' | 'subfamilia' | 'agrupacion'
type Medida = 'importe' | 'cantidad'

type Columna = { codigo: string; descripcion: string }

type Ejercicio = {
  anio: number
  filas: { mes: number; valores: Record<string, number>; total: number }[]
  totales: Record<string, number>
  total: number
}

type Consumo = {
  anio: number
  anioAnterior: number
  agrupacion: Agrupacion
  medida: Medida
  columnas: Columna[]
  ejercicios: Ejercicio[]
  porcentajes: Record<string, number | null>
  porcentajeTotal: number | null
}

const props = defineProps<{
  open: boolean
  clienteCodigo: string
  clienteNombre?: string
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const MESES = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic']

const AGRUPACIONES: { value: Agrupacion; label: string }[] = [
  { value: 'macrofamilia', label: 'Macrofamilia' },
  { value: 'familia', label: 'Familia' },
  { value: 'subfamilia', label: 'Subfamilia' },
  { value: 'agrupacion', label: 'Agrupacion' },
]

const anio = ref(new Date().getFullYear())
const agrupacion = ref<Agrupacion>('macrofamilia')
const medida = ref<Medida>('importe')
const loading = ref(false)
const error = ref<string | null>(null)
const datos = ref<Consumo | null>(null)

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    anio.value = new Date().getFullYear()
    await cargar()
  }
)

async function cargar() {
  if (!props.clienteCodigo) return
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/consumo`,
      { params: { anio: anio.value, agrupacion: agrupacion.value, medida: medida.value } }
    )
    datos.value = data as Consumo
  } catch (e) {
    datos.value = null
    error.value = extractApiError(e, 'No se pudo cargar el consumo del cliente')
  } finally {
    loading.value = false
  }
}

async function cambiarAnio(delta: number) {
  anio.value += delta
  await cargar()
}

/** Legacy: la rejilla de consumo va sin decimales. */
function fmt(n: number | null | undefined) {
  const valor = n ?? 0
  if (valor === 0) return ''
  return valor.toLocaleString('es-ES', { maximumFractionDigits: 0 })
}

function fmtPorcentaje(n: number | null | undefined) {
  if (n === null || n === undefined) return ''
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>
          Consumo — {{ clienteCodigo }}
          <span v-if="clienteNombre" class="nombre">{{ clienteNombre }}</span>
        </h3>
        <button type="button" class="btn" @click="emit('cerrar')">Salir</button>
      </header>

      <div class="filtros">
        <div class="anio-nav">
          <button type="button" class="btn" :disabled="loading" @click="cambiarAnio(-1)">◀</button>
          <strong>{{ anio - 1 }}-{{ anio }}</strong>
          <button type="button" class="btn" :disabled="loading" @click="cambiarAnio(1)">▶</button>
        </div>
        <label>
          Agrupacion
          <select v-model="agrupacion" :disabled="loading" @change="cargar()">
            <option v-for="opt in AGRUPACIONES" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </label>
        <label>
          Acumulado
          <select v-model="medida" :disabled="loading" @change="cargar()">
            <option value="importe">Importe</option>
            <option value="cantidad">Unidades</option>
          </select>
        </label>
      </div>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="loading" class="loading">Cargando...</p>

      <div v-else class="body">
        <p v-if="!datos?.columnas.length" class="vacio">
          No hay agrupaciones definidas para esta seleccion.
        </p>

        <table v-else class="grid">
          <thead>
            <tr>
              <th class="mes">Mes</th>
              <th v-for="col in datos.columnas" :key="col.codigo" class="num">
                <span class="col-codigo">{{ col.codigo }}</span>
                <span class="col-desc" :title="col.descripcion">{{ col.descripcion }}</span>
              </th>
              <th class="num">Total</th>
            </tr>
          </thead>

          <tbody v-for="(ejercicio, indice) in datos.ejercicios" :key="ejercicio.anio">
            <tr v-if="indice > 0" class="separador">
              <td class="mes">Mes</td>
              <td v-for="col in datos.columnas" :key="col.codigo" class="num">
                {{ col.descripcion }}
              </td>
              <td class="num">Total</td>
            </tr>
            <tr v-for="fila in ejercicio.filas" :key="`${ejercicio.anio}-${fila.mes}`">
              <td class="mes">{{ MESES[fila.mes - 1] }}</td>
              <td v-for="col in datos.columnas" :key="col.codigo" class="num">
                {{ fmt(fila.valores[col.codigo]) }}
              </td>
              <td class="num total-fila">{{ fmt(fila.total) }}</td>
            </tr>
            <tr class="total-anio">
              <td class="mes">{{ ejercicio.anio }}</td>
              <td v-for="col in datos.columnas" :key="col.codigo" class="num">
                {{ fmt(ejercicio.totales[col.codigo]) }}
              </td>
              <td class="num">{{ fmt(ejercicio.total) }}</td>
            </tr>
          </tbody>

          <tfoot>
            <tr>
              <td class="mes">%</td>
              <td v-for="col in datos.columnas" :key="col.codigo" class="num">
                {{ fmtPorcentaje(datos.porcentajes[col.codigo]) }}
              </td>
              <td class="num">{{ fmtPorcentaje(datos.porcentajeTotal) }}</td>
            </tr>
          </tfoot>
        </table>

        <p class="hint">
          Lineas de albaranes de venta, con el descuento de cabecera aplicado y sin
          rectificativas. Los dos bloques son {{ datos?.anioAnterior }} y {{ datos?.anio }}; la
          ultima fila es la variacion porcentual entre ambos.
        </p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 60;
  padding: 1rem;
}

.modal {
  width: min(1100px, 100%);
  max-height: min(92vh, 760px);
  background: #fff;
  border-radius: 10px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.65rem;
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}

.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
  display: flex;
  gap: 0.5rem;
  align-items: baseline;
  min-width: 0;
}

.nombre {
  font-weight: 400;
  font-size: 0.8rem;
  color: #475569;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.filtros {
  display: flex;
  align-items: flex-end;
  gap: 0.85rem;
  padding: 0.5rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
}

.filtros label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.72rem;
  color: #475569;
}

.filtros select {
  padding: 0.25rem 0.35rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.8rem;
  background: #fff;
  color: #0f172a;
}

.anio-nav {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.9rem;
  padding-bottom: 0.15rem;
}

.body {
  padding: 0.75rem 0.85rem;
  overflow: auto;
  min-height: 0;
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.grid th,
.grid td {
  border: 1px solid #e2e8f0;
  padding: 0.2rem 0.35rem;
  text-align: left;
  white-space: nowrap;
}

.grid th {
  background: #f1f5f9;
  vertical-align: bottom;
}

.grid th.num,
.grid td.num {
  text-align: right;
}

.grid td.mes,
.grid th.mes {
  background: #f1f5f9;
  font-weight: 600;
  width: 3.2rem;
}

.col-codigo {
  display: block;
  font-weight: 700;
}

.col-desc {
  display: block;
  font-weight: 400;
  font-size: 0.68rem;
  color: #64748b;
  max-width: 6.5rem;
  overflow: hidden;
  text-overflow: ellipsis;
}

.grid td.total-fila {
  font-weight: 600;
  background: #f8fafc;
}

.grid tr.total-anio td {
  font-weight: 700;
  background: #e2e8f0;
}

.grid tr.separador td {
  background: #f1f5f9;
  color: #475569;
  font-size: 0.68rem;
  font-weight: 600;
}

.grid tfoot td {
  font-weight: 700;
  background: #dbeafe;
}

.hint {
  margin: 0.6rem 0 0;
  font-size: 0.72rem;
  color: #64748b;
}

.vacio {
  margin: 0;
  font-size: 0.85rem;
  color: #475569;
}

.btn {
  padding: 0.25rem 0.6rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.8rem;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.loading,
.error {
  padding: 0.75rem 0.85rem;
  margin: 0;
  font-size: 0.85rem;
}

.error {
  color: #b91c1c;
  background: #fef2f2;
  border-bottom: 1px solid #fecaca;
}
</style>
