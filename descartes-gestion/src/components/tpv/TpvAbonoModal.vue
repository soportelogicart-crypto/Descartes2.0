<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  crearAbonoDesdeVenta,
  listarVentas,
  obtenerVenta,
} from '@/api/ventas'
import TpvTecladoNumerico from '@/components/tpv/TpvTecladoNumerico.vue'
import { extractApiError } from '@/composables/extractApiError'
import type { VentaDetalle, VentaLinea, VentaResumen } from '@/types/ventas'

const props = defineProps<{
  open: boolean
  empresa: string
}>()

const emit = defineEmits<{
  cancelar: []
  creado: [VentaDetalle]
}>()

const numero = ref('')
const inputNumero = ref<HTMLInputElement | null>(null)
const resultados = ref<VentaResumen[]>([])
const origen = ref<VentaDetalle | null>(null)
const seleccionadas = ref<number[]>([])
const observacion = ref('')
const loading = ref(false)
const error = ref<string | null>(null)

const abonadas = computed(() => new Set(origen.value?.nroLinsAbonados ?? []))
const lineasDocumento = computed(() =>
  (origen.value?.lineas ?? []).filter((l) => {
    const articulo = String(l.articulo ?? '').trim().toUpperCase()
    return articulo !== '' && articulo !== 'NO' && Number(l.nroLin) > 0 && Math.abs(Number(l.cantidad)) > 0
  })
)
const abonables = computed(() =>
  lineasDocumento.value.filter((l) => !abonadas.value.has(Number(l.nroLin)))
)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    numero.value = ''
    resultados.value = []
    origen.value = null
    seleccionadas.value = []
    observacion.value = ''
    error.value = null
    await foco()
  }
)

/** El cajero teclea el documento nada más abrir, sin tocar el campo. */
async function foco() {
  await nextTick()
  requestAnimationFrame(() => {
    inputNumero.value?.focus()
    inputNumero.value?.select()
  })
}

function pulsar(tecla: string) {
  if (numero.value.length >= 12) return
  numero.value = (numero.value + tecla).replace(/^0+(?=\d)/, '')
  void foco()
}

function borrarDigito() {
  numero.value = numero.value.slice(0, -1)
  void foco()
}

function limpiarNumero() {
  numero.value = ''
  void foco()
}

/** Un abono no puede volver a abonarse: nunca es documento origen válido. */
function esAbono(v: VentaResumen | VentaDetalle): boolean {
  return Number(v.albaranOrigenAbono ?? 0) > 0 || Number(v.importe) < 0
}

function etiqueta(v: VentaResumen | VentaDetalle): string {
  const ft = String(v.facturaTipo ?? '').trim().toUpperCase()
  if (ft === 'T' && v.factura) return `Ticket ${v.factura}`
  if (ft === 'F' && v.factura) return `Factura ${v.factura}`
  return `Albarán ${v.albaran}`
}

async function buscar() {
  const documento = Number(numero.value.trim())
  if (!Number.isInteger(documento) || documento <= 0) {
    error.value = 'Introduzca un número de ticket, factura o albarán válido'
    return
  }
  loading.value = true
  error.value = null
  origen.value = null
  resultados.value = []
  try {
    const data = await listarVentas({
      empresa: props.empresa,
      documento,
      page: 1,
      pageSize: 50,
    })
    // Red de seguridad: si la API no filtra por documento devolvería el listado
    // completo, y en caja eso es peor que no encontrar nada.
    const exactos = data.items.filter(
      (v) =>
        !esAbono(v) && (Number(v.albaran) === documento || Number(v.factura) === documento)
    )
    // El número impreso en el ticket es el de factura; solo si ninguno coincide
    // se interpreta como número interno de albarán. Así no salen dos documentos.
    const porFactura = exactos.filter((v) => Number(v.factura) === documento)
    const encontrados = porFactura.length ? porFactura : exactos

    resultados.value = encontrados
    if (!encontrados.length) {
      error.value = `No se ha encontrado ninguna venta con el número ${documento}`
    } else if (encontrados.length === 1) {
      await seleccionarOrigen(encontrados[0])
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo buscar el documento')
  } finally {
    loading.value = false
  }
}

async function seleccionarOrigen(resumen: VentaResumen) {
  loading.value = true
  error.value = null
  try {
    const detalle = await obtenerVenta(resumen.empresa, resumen.tipo, resumen.albaran)
    origen.value = detalle
    resultados.value = []
    seleccionadas.value = (detalle.lineas ?? [])
      .filter((l) => {
        const articulo = String(l.articulo ?? '').trim().toUpperCase()
        return (
          articulo !== '' &&
          articulo !== 'NO' &&
          Number(l.nroLin) > 0 &&
          Math.abs(Number(l.cantidad)) > 0 &&
          !(detalle.nroLinsAbonados ?? []).includes(Number(l.nroLin))
        )
      })
      .map((l) => Number(l.nroLin))
    if (!detalle.permiteAbonoParcial) {
      error.value = 'Este documento no admite abono'
    } else if (!seleccionadas.value.length) {
      error.value = 'Todas las líneas de este documento ya están abonadas'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el documento')
  } finally {
    loading.value = false
  }
}

function toggleLinea(linea: VentaLinea) {
  const nro = Number(linea.nroLin)
  if (abonadas.value.has(nro)) return
  seleccionadas.value = seleccionadas.value.includes(nro)
    ? seleccionadas.value.filter((n) => n !== nro)
    : [...seleccionadas.value, nro]
}

async function crearAbono() {
  const venta = origen.value
  if (!venta || !venta.permiteAbonoParcial || !seleccionadas.value.length) return
  loading.value = true
  error.value = null
  try {
    const creado = await crearAbonoDesdeVenta(venta.empresa, venta.tipo, venta.albaran, {
      nroLins: seleccionadas.value,
      observacion: observacion.value.trim() || undefined,
    })
    emit('creado', creado)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el abono')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" role="dialog" aria-modal="true" @mousedown.self.prevent>
      <section class="ventana">
        <header class="barra">ABONO DE VENTA</header>

        <div class="cuerpo">
          <form class="busqueda" @submit.prevent="buscar">
            <label for="tpv-abono-numero">Nº ticket, factura o albarán</label>
            <input
              id="tpv-abono-numero"
              ref="inputNumero"
              v-model="numero"
              type="text"
              inputmode="numeric"
              autocomplete="off"
            />
            <button type="submit" :disabled="loading">BUSCAR</button>
          </form>

          <TpvTecladoNumerico
            v-if="!origen"
            class="pad-documento"
            @tecla="pulsar"
            @borrar="borrarDigito"
            @limpiar="limpiarNumero"
          />

          <p v-if="error" class="error">{{ error }}</p>
          <p v-else-if="loading" class="estado">Consultando…</p>

          <div v-if="resultados.length > 1" class="resultados">
            <button
              v-for="r in resultados"
              :key="`${r.empresa}-${r.tipo}-${r.albaran}`"
              type="button"
              @click="seleccionarOrigen(r)"
            >
              <strong>{{ etiqueta(r) }}</strong>
              <span>{{ r.fecha }} · {{ r.razonSocial || r.cliente || 'Sin cliente' }}</span>
              <span>{{ Number(r.importe).toFixed(2) }} €</span>
            </button>
          </div>

          <template v-if="origen">
            <div class="documento">
              <strong>{{ etiqueta(origen) }}</strong>
              <span>Albarán interno {{ origen.albaran }}</span>
              <span>{{ origen.razonSocial || origen.cliente || 'Sin cliente' }}</span>
            </div>

            <div class="atajos">
              <button type="button" @click="seleccionadas = abonables.map((l) => Number(l.nroLin))">
                TODAS
              </button>
              <button type="button" @click="seleccionadas = []">NINGUNA</button>
            </div>

            <div class="tabla-wrap">
              <table>
                <thead>
                  <tr>
                    <th></th>
                    <th>Artículo</th>
                    <th>Descripción</th>
                    <th class="num">Cant.</th>
                    <th class="num">Importe</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="l in lineasDocumento"
                    :key="l.nroLin"
                    :class="{ abonada: abonadas.has(Number(l.nroLin)) }"
                    @click="toggleLinea(l)"
                  >
                    <td>
                      <input
                        type="checkbox"
                        :checked="seleccionadas.includes(Number(l.nroLin))"
                        :disabled="abonadas.has(Number(l.nroLin))"
                        @click.stop
                        @change="toggleLinea(l)"
                      />
                    </td>
                    <td>{{ l.articulo }}</td>
                    <td>
                      {{ l.descripcion }}
                      <small v-if="abonadas.has(Number(l.nroLin))">Ya abonada</small>
                    </td>
                    <td class="num">{{ l.cantidad }}</td>
                    <td class="num">{{ Number(l.importe).toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <label class="observacion">
              Observación (opcional)
              <input v-model="observacion" type="text" maxlength="200" />
            </label>
            <p class="estado">Líneas seleccionadas: {{ seleccionadas.length }}</p>
          </template>
        </div>

        <footer class="pie">
          <button type="button" :disabled="loading" @click="emit('cancelar')">CANCELAR</button>
          <button
            type="button"
            class="aceptar"
            :disabled="
              loading ||
              !origen?.permiteAbonoParcial ||
              !seleccionadas.length
            "
            @click="crearAbono"
          >
            CREAR ABONO
          </button>
        </footer>
      </section>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5000;
  display: grid;
  place-items: center;
  padding: 1rem;
  background: rgb(0 0 0 / 55%);
}

.ventana {
  display: flex;
  flex-direction: column;
  width: min(58rem, 96vw);
  max-height: 92vh;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-weight: 600;
}

.cuerpo {
  min-height: 0;
  padding: 0.75rem;
  overflow: auto;
}

.busqueda {
  display: grid;
  grid-template-columns: auto minmax(10rem, 1fr) auto;
  align-items: center;
  gap: 0.5rem;
}

input,
button {
  min-height: 2.65rem;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  font: inherit;
}

input {
  padding: 0.4rem 0.6rem;
}

input:focus {
  outline: none;
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
}

button {
  padding: 0.4rem 0.8rem;
  background: #fff;
  color: #1e293b;
  font-weight: 600;
  cursor: pointer;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

button:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

button:active:not(:disabled) {
  transform: translateY(1px);
}

button:disabled {
  opacity: 0.45;
  cursor: default;
}

/* Caja táctil: sin teclado físico el nº de documento se marca aquí. */
.pad-documento {
  width: min(19rem, 100%);
  margin: 0.6rem 0;
}

.error {
  padding: 0.5rem 0.65rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 10px;
  color: #be123c;
}

.estado {
  margin: 0.45rem 0;
}

.resultados {
  display: grid;
  gap: 4px;
  margin-top: 0.65rem;
}

.resultados button {
  display: grid;
  grid-template-columns: 10rem 1fr 8rem;
  text-align: left;
}

.documento {
  display: flex;
  gap: 1rem;
  margin-top: 0.75rem;
  padding: 0.55rem 0.7rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  border-radius: 10px;
  color: #f8fafc;
}

.atajos {
  display: flex;
  gap: 4px;
  margin: 0.5rem 0;
}

.tabla-wrap {
  max-height: 43vh;
  overflow: auto;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
}

th {
  position: sticky;
  top: 0;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  color: #64748b;
  font-size: 0.72rem;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

tbody tr {
  cursor: pointer;
}

tbody tr:hover td {
  background: #f8fafc;
}

tr.abonada {
  color: #94a3b8;
  text-decoration: line-through;
}

td small {
  display: block;
  color: #be123c;
  text-decoration: none;
}

.num {
  text-align: right;
}

.observacion {
  display: grid;
  gap: 0.25rem;
  margin-top: 0.6rem;
}

.pie {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.pie .aceptar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.pie .aceptar:hover:not(:disabled) {
  background: #047857;
  border-color: #047857;
}

@media (max-width: 700px) {
  .busqueda {
    grid-template-columns: 1fr;
  }

  .resultados button {
    grid-template-columns: 1fr;
  }
}
</style>
