<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { PagosACuentaRespuesta, PagoACuentaItem } from '@/api/ventas'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'

const props = defineProps<{
  pagos?: PagosACuentaRespuesta | null
  pagosError?: string | null
  pagosOcupado?: boolean
  /** Total ya conocido cuando todavía no hay listado (alta). */
  totalConocido?: number
  esNuevo?: boolean
  notaNuevo?: string
}>()

const emit = defineEmits<{
  'guardar-pago': [payload: { nro: number | null; importe: number; formaPago: string }]
  'borrar-pago': [nro: number]
}>()

const pagoNro = ref<number | null>(null)
const pagoForma = ref('')
const pagoFormaNombre = ref('')
const pagoImporte = ref<number | null>(0)
const buscarOpen = ref(false)
const pagoEnviado = ref(false)

const puedeAnotar = computed(() => Boolean(props.pagos?.editable || props.esNuevo))

const etiquetaFormaElegida = computed(() => {
  if (!pagoForma.value) return 'Buscar forma de pago'
  return pagoFormaNombre.value ? `${pagoForma.value} — ${pagoFormaNombre.value}` : pagoForma.value
})

const totalPagosTexto = computed(() => {
  const total = props.pagos?.total ?? Number(props.totalConocido ?? 0)
  return `${total.toFixed(2).replace('.', ',')} €`
})

function dinero(importe: number) {
  return `${importe.toFixed(2).replace('.', ',')} €`
}

function etiquetaForma(pago: PagoACuentaItem) {
  const nombre = pago.formaPagoNombre.trim()
  return nombre ? `${pago.formaPago} — ${nombre}` : pago.formaPago
}

function abrirPagoNuevo() {
  pagoNro.value = null
  pagoImporte.value = 0
}

function editarPago(pago: PagoACuentaItem) {
  pagoNro.value = pago.nro
  pagoForma.value = pago.formaPago
  pagoFormaNombre.value = pago.formaPagoNombre
  pagoImporte.value = pago.importe
}

function confirmarPago() {
  const importe = Math.round((Number(pagoImporte.value) || 0) * 100) / 100
  if (!pagoForma.value) {
    buscarOpen.value = true
    return
  }
  if (importe <= 0) return
  pagoEnviado.value = true
  emit('guardar-pago', { nro: pagoNro.value, importe, formaPago: pagoForma.value })
}

function onForma(sel: EntidadBuscarResultado) {
  buscarOpen.value = false
  pagoForma.value = sel.codigo
  pagoFormaNombre.value = sel.etiqueta
}

watch(
  () => props.pagosOcupado,
  (ocupado) => {
    if (ocupado || !pagoEnviado.value) return
    pagoEnviado.value = false
    if (!props.pagosError) {
      pagoNro.value = null
      pagoImporte.value = 0
    }
  }
)
</script>

<template>
  <div class="pagos-cuenta">
    <span class="label">Pago a cuenta</span>
    <strong class="pagos-total">{{ totalPagosTexto }}</strong>
    <p v-if="pagos?.motivo" class="pagos-nota">{{ pagos.motivo }}</p>
    <p v-else-if="esNuevo" class="pagos-nota">
      {{ notaNuevo || 'Añadir anota el adelanto en la caja abierta. En un albarán nuevo, primero hay que poner artículos.' }}
    </p>
    <table v-if="pagos?.items.length" class="pagos-tabla">
      <thead>
        <tr>
          <th>Fecha</th>
          <th>Importe</th>
          <th>Forma de pago</th>
          <th>Caja</th>
          <th v-if="pagos?.editable"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="p in pagos?.items ?? []" :key="p.nro">
          <td>{{ p.fecha }}</td>
          <td>{{ dinero(p.importe) }}</td>
          <td>{{ etiquetaForma(p) }}</td>
          <td>{{ p.enCajaHoy ? 'Caja de hoy' : 'Caja cerrada' }}</td>
          <td v-if="pagos?.editable" class="pagos-acciones">
            <button type="button" :disabled="pagosOcupado" @click="editarPago(p)">Cambiar</button>
            <button type="button" :disabled="pagosOcupado" @click="emit('borrar-pago', p.nro)">Borrar</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-else class="pagos-nota">Sin pagos a cuenta.</p>
    <div v-if="puedeAnotar" class="pagos-form">
      <label>
        Importe
        <DecimalInput v-model="pagoImporte" :empty-as-null="false" :disabled="pagosOcupado" />
      </label>
      <label>
        Forma de pago
        <div class="combo-input">
          <span class="combo-nombre">{{ etiquetaFormaElegida }}</span>
          <button
            type="button"
            class="btn-buscar"
            title="Buscar forma de pago"
            :disabled="pagosOcupado"
            @click="buscarOpen = true"
          >
            ...
          </button>
        </div>
      </label>
      <button type="button" :disabled="pagosOcupado" @click="confirmarPago">
        {{ pagoNro == null ? 'Añadir' : 'Guardar' }}
      </button>
      <button v-if="pagoNro != null" type="button" :disabled="pagosOcupado" @click="abrirPagoNuevo">
        Cancelar
      </button>
    </div>
    <p v-if="pagosError" class="pagos-error">{{ pagosError }}</p>
    <EntidadBuscarModal
      :open="buscarOpen"
      entidad="formas-pago"
      titulo="Forma de pago del adelanto"
      :codigo-actual="pagoForma"
      solo-cobro-de-arqueo
      @seleccionar="onForma"
      @cerrar="buscarOpen = false"
    />
  </div>
</template>

<style scoped>
.pagos-cuenta {
  display: grid;
  gap: 0.35rem;
}

.label {
  font-size: 0.75rem;
  color: #334155;
}

.pagos-total {
  font-variant-numeric: tabular-nums;
}

.pagos-nota,
.pagos-error {
  margin: 0;
  font-size: 0.75rem;
}

.pagos-error {
  color: #b91c1c;
}

.pagos-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.pagos-tabla th,
.pagos-tabla td {
  padding: 0.2rem 0.35rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
}

.pagos-acciones {
  display: flex;
  gap: 0.25rem;
}

.pagos-form {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: end;
}

.pagos-form label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.75rem;
}

.combo-input {
  display: flex;
  align-items: stretch;
  min-width: 10rem;
  height: 1.65rem;
  box-sizing: border-box;
  border: 1px solid #94a3b8;
  border-radius: 2px;
  background: #fff;
}

.combo-nombre {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0 0.3rem;
  font-size: 0.75rem;
  line-height: 1.65rem;
  color: #334155;
}

.btn-buscar {
  flex: 0 0 1.7rem;
  height: auto;
  border: none;
  border-left: 1px solid #e2e8f0;
  border-radius: 0;
  background: transparent;
  cursor: pointer;
}
</style>
