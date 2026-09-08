<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { formatDecimalDisplay, parseDecimalInput } from '@/composables/useDecimalInput'

type MesEstadistica = {
  mes: number
  numVentas: number
  importe: number
  prevision: number
  puntos: number
  fidelizacion: number
}

type Estadistica = {
  anio: number
  meses: MesEstadistica[]
  totales: {
    numVentas: number
    importe: number
    prevision: number
    puntos: number
    fidelizacion: number
  }
}

const props = defineProps<{
  open: boolean
  clienteCodigo: string
  clienteNombre?: string
  puedeEditar?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const MESES = [
  'Enero',
  'Febrero',
  'Marzo',
  'Abril',
  'Mayo',
  'Junio',
  'Julio',
  'Agosto',
  'Septiembre',
  'Octubre',
  'Noviembre',
  'Diciembre',
]

const anio = ref(new Date().getFullYear())
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const datos = ref<Estadistica | null>(null)

const puedeEscribir = computed(() => props.puedeEditar !== false && !!props.clienteCodigo)

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
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/estadistica`,
      { params: { anio: anio.value } }
    )
    datos.value = data as Estadistica
  } catch (e) {
    datos.value = null
    error.value = extractApiError(e, 'No se pudo cargar la estadistica del cliente')
  } finally {
    loading.value = false
  }
}

async function cambiarAnio(delta: number) {
  anio.value += delta
  await cargar()
}

/** Graba al salir de la casilla, como el grid legacy. */
async function guardarPrevision(mes: number, raw: string) {
  if (!puedeEscribir.value) return
  const valor = parseDecimalInput(raw).value ?? 0
  saving.value = true
  error.value = null
  try {
    const { data } = await api.put(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/estadistica/${anio.value}/${mes}`,
      { prevision: valor }
    )
    datos.value = data as Estadistica
  } catch (e) {
    error.value = extractApiError(e, 'No se pudo guardar la prevision')
    await cargar()
  } finally {
    saving.value = false
  }
}

function fmt(n: number | null | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** Techo "redondo" (1, 2 o 5 x 10^n) para que las marcas del eje sean legibles. */
function techoEscala(max: number) {
  if (max <= 0) return 1
  const magnitud = 10 ** Math.floor(Math.log10(max))
  const normalizado = max / magnitud
  const factor = normalizado <= 1 ? 1 : normalizado <= 2 ? 2 : normalizado <= 5 ? 5 : 10
  return factor * magnitud
}

const GRAFICO = { ancho: 380, alto: 210, izquierda: 46, derecha: 8, arriba: 10, abajo: 26 }

const grafico = computed(() => {
  const meses = datos.value?.meses ?? []
  const maxImporte = Math.max(0, ...meses.map((m) => m.importe), ...meses.map((m) => m.prevision))
  const techo = techoEscala(maxImporte)
  const x0 = GRAFICO.izquierda
  const y0 = GRAFICO.alto - GRAFICO.abajo
  const anchoUtil = GRAFICO.ancho - GRAFICO.izquierda - GRAFICO.derecha
  const altoUtil = y0 - GRAFICO.arriba
  const paso = anchoUtil / 12
  const conPrevision = meses.some((m) => m.prevision > 0)
  const anchoBarra = conPrevision ? paso * 0.32 : paso * 0.55

  const altura = (valor: number) => (valor <= 0 ? 0 : (valor / techo) * altoUtil)

  return {
    x0,
    y0,
    ancho: GRAFICO.ancho,
    alto: GRAFICO.alto,
    xFin: GRAFICO.ancho - GRAFICO.derecha,
    conPrevision,
    hayDatos: maxImporte > 0,
    marcas: [0, 0.25, 0.5, 0.75, 1].map((r) => ({
      y: y0 - r * altoUtil,
      etiqueta: (techo * r).toLocaleString('es-ES', { maximumFractionDigits: 0 }),
    })),
    barras: meses.map((m, i) => {
      const centro = x0 + paso * i + paso / 2
      const hImporte = altura(m.importe)
      const hPrevision = altura(m.prevision)
      return {
        mes: m.mes,
        etiqueta: MESES[m.mes - 1].slice(0, 1),
        importe: m.importe,
        prevision: m.prevision,
        importeX: conPrevision ? centro - anchoBarra - 1 : centro - anchoBarra / 2,
        previsionX: centro + 1,
        ancho: anchoBarra,
        importeY: y0 - hImporte,
        importeAlto: hImporte,
        previsionY: y0 - hPrevision,
        previsionAlto: hPrevision,
        etiquetaX: centro,
      }
    }),
  }
})
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>
          Estadistica — {{ clienteCodigo }}
          <span v-if="clienteNombre" class="nombre">{{ clienteNombre }}</span>
        </h3>
        <div class="anio-nav">
          <button type="button" class="btn" :disabled="loading" @click="cambiarAnio(-1)">◀</button>
          <strong>{{ anio }}</strong>
          <button type="button" class="btn" :disabled="loading" @click="cambiarAnio(1)">▶</button>
        </div>
        <button type="button" class="btn" @click="emit('cerrar')">Salir</button>
      </header>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="loading" class="loading">Cargando...</p>

      <div v-else class="body">
        <table class="grid tabla">
          <thead>
            <tr>
              <th>Mes</th>
              <th class="num">Numero</th>
              <th class="num">Importe</th>
              <th class="num">Prevision</th>
              <th class="num">Puntos</th>
              <th class="num">Fidelizacion</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="fila in datos?.meses ?? []" :key="fila.mes">
              <td>{{ MESES[fila.mes - 1] }}</td>
              <td class="num">{{ fila.numVentas }}</td>
              <td class="num">{{ fmt(fila.importe) }}</td>
              <td class="num prevision">
                <input
                  v-if="puedeEscribir"
                  type="text"
                  inputmode="decimal"
                  class="input-prevision"
                  :value="formatDecimalDisplay(fila.prevision)"
                  :disabled="saving"
                  @change="guardarPrevision(fila.mes, ($event.target as HTMLInputElement).value)"
                />
                <span v-else>{{ fmt(fila.prevision) }}</span>
              </td>
              <td class="num">{{ fmt(fila.puntos) }}</td>
              <td class="num">{{ fmt(fila.fidelizacion) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td>Total</td>
              <td class="num">{{ datos?.totales.numVentas ?? 0 }}</td>
              <td class="num">{{ fmt(datos?.totales.importe) }}</td>
              <td class="num">{{ fmt(datos?.totales.prevision) }}</td>
              <td class="num">{{ fmt(datos?.totales.puntos) }}</td>
              <td class="num">{{ fmt(datos?.totales.fidelizacion) }}</td>
            </tr>
          </tfoot>
        </table>

        <figure class="chart">
          <figcaption>Importe por mes {{ anio }}</figcaption>
          <svg
            :viewBox="`0 0 ${grafico.ancho} ${grafico.alto}`"
            role="img"
            :aria-label="`Importe mensual de ${anio}`"
          >
            <g class="ejes">
              <line
                v-for="marca in grafico.marcas"
                :key="marca.y"
                :x1="grafico.x0"
                :x2="grafico.xFin"
                :y1="marca.y"
                :y2="marca.y"
              />
              <text
                v-for="marca in grafico.marcas"
                :key="`t-${marca.y}`"
                :x="grafico.x0 - 5"
                :y="marca.y + 3"
                text-anchor="end"
              >
                {{ marca.etiqueta }}
              </text>
            </g>
            <g v-if="grafico.hayDatos">
              <rect
                v-for="barra in grafico.barras"
                :key="`i-${barra.mes}`"
                class="barra-importe"
                :x="barra.importeX"
                :y="barra.importeY"
                :width="barra.ancho"
                :height="barra.importeAlto"
              />
              <template v-if="grafico.conPrevision">
                <rect
                  v-for="barra in grafico.barras"
                  :key="`p-${barra.mes}`"
                  class="barra-prevision"
                  :x="barra.previsionX"
                  :y="barra.previsionY"
                  :width="barra.ancho"
                  :height="barra.previsionAlto"
                />
              </template>
            </g>
            <text
              v-for="barra in grafico.barras"
              :key="`m-${barra.mes}`"
              class="mes-label"
              :x="barra.etiquetaX"
              :y="grafico.y0 + 14"
              text-anchor="middle"
            >
              {{ barra.etiqueta }}
            </text>
          </svg>
          <p v-if="!grafico.hayDatos" class="sin-datos">Sin ventas en {{ anio }}</p>
          <p v-else-if="grafico.conPrevision" class="leyenda">
            <span class="marca importe"></span> Importe
            <span class="marca prevision"></span> Prevision
          </p>
        </figure>

        <p class="hint">
          Acumulados de ventas del cliente (tabla CliImpVentas), la misma fuente que el legacy. La
          prevision se graba al salir de la casilla.
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
  width: min(1040px, 100%);
  max-height: min(90vh, 680px);
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

.anio-nav {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin-left: auto;
  font-size: 0.9rem;
}

.body {
  padding: 0.75rem 0.85rem;
  overflow: auto;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(300px, 400px);
  gap: 0.85rem;
  align-items: start;
}

@media (max-width: 900px) {
  .body {
    grid-template-columns: minmax(0, 1fr);
  }
}

.chart {
  margin: 0;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 0.5rem 0.6rem;
  background: #fbfdff;
}

.chart figcaption {
  font-size: 0.72rem;
  color: #475569;
  margin-bottom: 0.25rem;
}

.chart svg {
  width: 100%;
  height: auto;
  display: block;
}

.chart .ejes line {
  stroke: #dbe3ec;
  stroke-width: 1;
}

.chart .ejes text,
.chart .mes-label {
  font-size: 8px;
  fill: #64748b;
}

.barra-importe {
  fill: #2563eb;
}

.barra-prevision {
  fill: #94a3b8;
}

.leyenda {
  margin: 0.35rem 0 0;
  font-size: 0.7rem;
  color: #475569;
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.leyenda .marca {
  width: 0.65rem;
  height: 0.65rem;
  border-radius: 2px;
  display: inline-block;
}

.leyenda .marca.importe {
  background: #2563eb;
}

.leyenda .marca.prevision {
  background: #94a3b8;
  margin-left: 0.5rem;
}

.sin-datos {
  margin: 0.35rem 0 0;
  font-size: 0.72rem;
  color: #64748b;
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.grid th,
.grid td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.25rem 0.4rem;
  text-align: left;
}

.grid th {
  background: #f1f5f9;
}

.grid th.num,
.grid td.num {
  text-align: right;
}

.grid td.prevision {
  padding: 0.15rem 0.4rem;
}

.grid tfoot td {
  font-weight: 700;
  background: #f8fafc;
}

.input-prevision {
  width: 6.5rem;
  text-align: right;
  padding: 0.2rem 0.3rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.8rem;
  background: #fff;
  color: #0f172a;
}

.hint {
  grid-column: 1 / -1;
  margin: 0.2rem 0 0;
  font-size: 0.72rem;
  color: #64748b;
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
