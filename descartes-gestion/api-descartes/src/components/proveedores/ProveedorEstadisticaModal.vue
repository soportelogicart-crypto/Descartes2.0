<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

type SerieMes = { mes: number; importe: number }
type Serie = { meses: SerieMes[]; total: number }
type Estadistica = { anio: number; compras: Serie; ventas: Serie }

const props = defineProps<{
  open: boolean
  proveedorCodigo: string
  proveedorNombre?: string
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
const error = ref<string | null>(null)
const datos = ref<Estadistica | null>(null)

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    anio.value = new Date().getFullYear()
    await cargar()
  }
)

async function cargar() {
  if (!props.proveedorCodigo) return
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/proveedores/${encodeURIComponent(props.proveedorCodigo)}/estadistica`,
      { params: { anio: anio.value } }
    )
    datos.value = data as Estadistica
  } catch (e) {
    datos.value = null
    error.value = extractApiError(e, 'No se pudo cargar la estadistica del proveedor')
  } finally {
    loading.value = false
  }
}

async function cambiarAnio(delta: number) {
  anio.value += delta
  await cargar()
}

function fmt(n: number | null | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function techoEscala(max: number) {
  if (max <= 0) return 1
  const magnitud = 10 ** Math.floor(Math.log10(max))
  const normalizado = max / magnitud
  const factor = normalizado <= 1 ? 1 : normalizado <= 2 ? 2 : normalizado <= 5 ? 5 : 10
  return factor * magnitud
}

const GRAFICO = { ancho: 360, alto: 190, izquierda: 46, derecha: 8, arriba: 10, abajo: 26 }

function graficoDe(serie: Serie | undefined) {
  const meses = serie?.meses ?? []
  const maxImporte = Math.max(0, ...meses.map((m) => m.importe))
  const techo = techoEscala(maxImporte)
  const x0 = GRAFICO.izquierda
  const y0 = GRAFICO.alto - GRAFICO.abajo
  const anchoUtil = GRAFICO.ancho - GRAFICO.izquierda - GRAFICO.derecha
  const altoUtil = y0 - GRAFICO.arriba
  const paso = anchoUtil / 12
  const anchoBarra = paso * 0.55
  const altura = (valor: number) => (valor <= 0 ? 0 : (valor / techo) * altoUtil)

  return {
    x0,
    y0,
    ancho: GRAFICO.ancho,
    alto: GRAFICO.alto,
    xFin: GRAFICO.ancho - GRAFICO.derecha,
    hayDatos: maxImporte > 0,
    marcas: [0, 0.25, 0.5, 0.75, 1].map((r) => ({
      y: y0 - r * altoUtil,
      etiqueta: (techo * r).toLocaleString('es-ES', { maximumFractionDigits: 0 }),
    })),
    barras: meses.map((m, i) => {
      const centro = x0 + paso * i + paso / 2
      const h = altura(m.importe)
      return {
        mes: m.mes,
        etiqueta: MESES[m.mes - 1].slice(0, 1),
        x: centro - anchoBarra / 2,
        y: y0 - h,
        ancho: anchoBarra,
        alto: h,
        etiquetaX: centro,
      }
    }),
  }
}

const graficoCompras = computed(() => graficoDe(datos.value?.compras))
const graficoVentas = computed(() => graficoDe(datos.value?.ventas))
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>
          Estadistica — {{ proveedorCodigo }}
          <span v-if="proveedorNombre" class="nombre">{{ proveedorNombre }}</span>
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
        <section class="bloque">
          <h4>Compras</h4>
          <div class="bloque-grid">
            <table class="grid">
              <thead>
                <tr>
                  <th>Mes</th>
                  <th class="num">Importe</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="fila in datos?.compras.meses ?? []" :key="`c-${fila.mes}`">
                  <td>{{ MESES[fila.mes - 1] }}</td>
                  <td class="num">{{ fmt(fila.importe) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <td class="num">{{ fmt(datos?.compras.total) }}</td>
                </tr>
              </tfoot>
            </table>
            <figure class="chart">
              <svg
                :viewBox="`0 0 ${graficoCompras.ancho} ${graficoCompras.alto}`"
                role="img"
                :aria-label="`Compras mensuales de ${anio}`"
              >
                <g class="ejes">
                  <line
                    v-for="marca in graficoCompras.marcas"
                    :key="marca.y"
                    :x1="graficoCompras.x0"
                    :x2="graficoCompras.xFin"
                    :y1="marca.y"
                    :y2="marca.y"
                  />
                  <text
                    v-for="marca in graficoCompras.marcas"
                    :key="`t-${marca.y}`"
                    :x="graficoCompras.x0 - 5"
                    :y="marca.y + 3"
                    text-anchor="end"
                  >
                    {{ marca.etiqueta }}
                  </text>
                </g>
                <g v-if="graficoCompras.hayDatos">
                  <rect
                    v-for="barra in graficoCompras.barras"
                    :key="barra.mes"
                    class="barra"
                    :x="barra.x"
                    :y="barra.y"
                    :width="barra.ancho"
                    :height="barra.alto"
                  />
                </g>
                <text
                  v-for="barra in graficoCompras.barras"
                  :key="`m-${barra.mes}`"
                  class="mes-label"
                  :x="barra.etiquetaX"
                  :y="graficoCompras.y0 + 14"
                  text-anchor="middle"
                >
                  {{ barra.etiqueta }}
                </text>
              </svg>
              <p v-if="!graficoCompras.hayDatos" class="sin-datos">Sin compras en {{ anio }}</p>
            </figure>
          </div>
        </section>

        <section class="bloque">
          <h4>Ventas de sus articulos</h4>
          <div class="bloque-grid">
            <table class="grid">
              <thead>
                <tr>
                  <th>Mes</th>
                  <th class="num">Importe</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="fila in datos?.ventas.meses ?? []" :key="`v-${fila.mes}`">
                  <td>{{ MESES[fila.mes - 1] }}</td>
                  <td class="num">{{ fmt(fila.importe) }}</td>
                </tr>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total</td>
                  <td class="num">{{ fmt(datos?.ventas.total) }}</td>
                </tr>
              </tfoot>
            </table>
            <figure class="chart">
              <svg
                :viewBox="`0 0 ${graficoVentas.ancho} ${graficoVentas.alto}`"
                role="img"
                :aria-label="`Ventas mensuales de ${anio}`"
              >
                <g class="ejes">
                  <line
                    v-for="marca in graficoVentas.marcas"
                    :key="marca.y"
                    :x1="graficoVentas.x0"
                    :x2="graficoVentas.xFin"
                    :y1="marca.y"
                    :y2="marca.y"
                  />
                  <text
                    v-for="marca in graficoVentas.marcas"
                    :key="`tv-${marca.y}`"
                    :x="graficoVentas.x0 - 5"
                    :y="marca.y + 3"
                    text-anchor="end"
                  >
                    {{ marca.etiqueta }}
                  </text>
                </g>
                <g v-if="graficoVentas.hayDatos">
                  <rect
                    v-for="barra in graficoVentas.barras"
                    :key="barra.mes"
                    class="barra ventas"
                    :x="barra.x"
                    :y="barra.y"
                    :width="barra.ancho"
                    :height="barra.alto"
                  />
                </g>
                <text
                  v-for="barra in graficoVentas.barras"
                  :key="`mv-${barra.mes}`"
                  class="mes-label"
                  :x="barra.etiquetaX"
                  :y="graficoVentas.y0 + 14"
                  text-anchor="middle"
                >
                  {{ barra.etiqueta }}
                </text>
              </svg>
              <p v-if="!graficoVentas.hayDatos" class="sin-datos">Sin ventas en {{ anio }}</p>
            </figure>
          </div>
        </section>

        <p class="hint">
          Compras: acumulados de ProvCompras, la misma fuente que el legacy. Ventas: albaranes de
          articulos cuyo ultimo proveedor es este (importe sin IVA ni recargo, con descuento de
          cabecera y sin rectificativas).
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
  width: min(920px, 100%);
  max-height: min(92vh, 820px);
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
  display: flex;
  flex-direction: column;
  gap: 0.9rem;
}

.bloque h4 {
  margin: 0 0 0.4rem;
  font-size: 0.82rem;
}

.bloque-grid {
  display: grid;
  grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
  gap: 0.75rem;
  align-items: start;
}

@media (max-width: 720px) {
  .bloque-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.grid th,
.grid td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.18rem 0.35rem;
  text-align: left;
}

.grid th {
  background: #f1f5f9;
}

.grid th.num,
.grid td.num {
  text-align: right;
}

.grid tfoot td {
  font-weight: 700;
  background: #f8fafc;
}

.chart {
  margin: 0;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 0.4rem 0.5rem;
  background: #fbfdff;
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

.barra {
  fill: #2563eb;
}

.barra.ventas {
  fill: #0f766e;
}

.sin-datos {
  margin: 0.3rem 0 0;
  font-size: 0.72rem;
  color: #64748b;
}

.hint {
  margin: 0;
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
