<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { TpvFormaPago } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  total: number
  formasPago: TpvFormaPago[]
  permiteFactura: boolean
  guardando?: boolean
}>()

const emit = defineEmits<{
  confirmar: [{ tipoDocumento: string; formaPago: string; entregado: number }]
  cancelar: []
}>()

const tipoDocumento = ref('T')
const formaPago = ref('')
const entrada = ref('')

const tiposDocumento = [
  { codigo: 'T', etiqueta: 'TICKET' },
  { codigo: 'A', etiqueta: 'ALBARAN' },
  { codigo: 'P', etiqueta: 'PRESUPUESTO' },
  { codigo: 'F', etiqueta: 'FACTURA' },
]

/** Igual que Gestión: Ticket y Factura requieren forma de pago de contado. */
const requierePago = computed(
  () => tipoDocumento.value === 'T' || tipoDocumento.value === 'F'
)

const entregado = computed(() => {
  const n = Number(entrada.value.replace(',', '.'))
  return Number.isFinite(n) ? n : 0
})

/** Solo tiene sentido dar cambio en efectivo; con datáfono se cobra el importe justo. */
const cambio = computed(() => {
  if (entregado.value <= 0) return 0
  return Math.round((entregado.value - props.total) * 100) / 100
})

const falta = computed(() => entregado.value > 0 && cambio.value < 0)
const valido = computed(
  () =>
    (!requierePago.value || Boolean(formaPago.value)) &&
    (!requierePago.value || !falta.value)
)

function euros(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    tipoDocumento.value = 'T'
    entrada.value = ''
    // No seleccionar por defecto: el cajero debe indicar cómo cobra.
    formaPago.value = ''
  },
  { immediate: true }
)

function pulsar(tecla: string) {
  if (tecla === ',') {
    if (!entrada.value.includes(',')) entrada.value = (entrada.value || '0') + ','
    return
  }
  const [, decimales] = entrada.value.split(',')
  if (decimales !== undefined && decimales.length >= 2) return
  if (entrada.value.replace(',', '').length >= 8) return
  entrada.value = (entrada.value + tecla).replace(/^0+(?=\d)/, '')
}

function borrarUno() {
  entrada.value = entrada.value.slice(0, -1)
}

function exacto() {
  entrada.value = props.total.toFixed(2).replace('.', ',')
}

function confirmar() {
  if (!valido.value || props.guardando) return
  emit('confirmar', {
    tipoDocumento: tipoDocumento.value,
    formaPago: requierePago.value ? formaPago.value : '',
    entregado: requierePago.value ? entregado.value : 0,
  })
}
</script>

<template>
  <div v-if="open" class="overlay" @mousedown.prevent>
    <div class="ventana" role="dialog" aria-modal="true">
      <header class="barra">FINALIZAR VENTA</header>

      <div class="cuerpo">
        <div class="visor total">
          <span class="visor-lbl">TOTAL</span>
          <span class="visor-val">{{ euros(total) }}</span>
        </div>

        <p class="rotulo">TIPO DE DOCUMENTO</p>
        <div class="tipos">
          <button
            v-for="t in tiposDocumento"
            :key="t.codigo"
            type="button"
            class="btn-legacy tipo"
            :class="{ activa: tipoDocumento === t.codigo }"
            :disabled="t.codigo === 'F' && !permiteFactura"
            :title="
              t.codigo === 'F' && !permiteFactura
                ? 'Para factura seleccione un cliente con NIF y razon social'
                : t.etiqueta
            "
            @click="tipoDocumento = t.codigo"
          >
            {{ t.etiqueta }}
          </button>
        </div>
        <p v-if="!permiteFactura" class="nota">
          Factura requiere cliente real con NIF y razon social.
        </p>

        <template v-if="requierePago">
          <p class="rotulo">FORMA DE PAGO (SELECCIONE UNA)</p>
          <div v-if="formasPago.length" class="formas">
          <button
            v-for="f in formasPago"
            :key="f.codigo"
            type="button"
            class="btn-legacy forma"
            :class="{ activa: formaPago === f.codigo }"
            :title="f.descripcion"
            @click="formaPago = f.codigo"
          >
            {{ f.etiqueta }}
          </button>
          </div>
          <p v-else class="sin-formas">
            No hay formas de pago de contado configuradas (CobroDeArqueo). Revise Mantenimiento →
            Formas de pago.
          </p>

          <p class="rotulo">ENTREGADO (opcional)</p>
          <div class="visor">
            <span class="visor-lbl">€</span>
            <span class="visor-val">{{ entrada || '0' }}</span>
          </div>
          <p class="cambio" :class="{ falta }">
            <template v-if="falta">Faltan {{ euros(-cambio) }}</template>
            <template v-else-if="entregado > 0">Cambio: <strong>{{ euros(cambio) }}</strong></template>
            <template v-else>Sin importe entregado no se calcula cambio</template>
          </p>

          <div class="pad">
            <button
              v-for="t in ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0']"
              :key="t"
              type="button"
              class="btn-legacy num"
              @click="pulsar(t)"
            >
              {{ t }}
            </button>
            <button type="button" class="btn-legacy num" @click="pulsar(',')">,</button>
            <button type="button" class="btn-legacy num aux" @click="borrarUno">←</button>
            <button type="button" class="btn-legacy aux exacto" @click="exacto">EXACTO</button>
          </div>
        </template>
        <p v-else class="nota-documento">
          {{ tipoDocumento === 'A' ? 'El albaran queda pendiente de facturar.' : 'El presupuesto no genera cobro.' }}
        </p>
      </div>

      <footer class="pie">
        <button
          type="button"
          class="btn-legacy aceptar"
          :disabled="!valido || guardando"
          @click="confirmar"
        >
          {{ guardando ? 'FINALIZANDO…' : requierePago ? 'COBRAR Y FINALIZAR' : 'FINALIZAR' }}
        </button>
        <button
          type="button"
          class="btn-legacy cancelar"
          :disabled="guardando"
          @click="emit('cancelar')"
        >
          CANCELAR
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
}

.ventana {
  width: min(28rem, 94vw);
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  box-shadow: 3px 3px 10px rgba(0, 0, 0, 0.5);
  font-family: 'Segoe UI', Tahoma, sans-serif;
  color: #000;
}

.barra {
  padding: 0.35rem 0.6rem;
  background: linear-gradient(#00309c, #000060);
  color: #fff;
  font-size: 0.88rem;
  font-weight: 700;
}

.cuerpo {
  padding: 0.6rem;
}

.rotulo {
  margin: 0.55rem 0 0.25rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: #404040;
}

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.3rem 0.6rem;
  background: #000;
  border: 2px inset #808080;
  color: #4ade80;
  font-family: Consolas, monospace;
}

.visor.total .visor-val {
  font-size: 2rem;
}

.visor-lbl {
  font-size: 0.72rem;
  opacity: 0.8;
}

.visor-val {
  margin-left: auto;
  font-size: 1.5rem;
  font-weight: 700;
}

.tipos,
.formas {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.tipo.activa,
.forma.activa {
  background: #b8d0f0;
  border-style: inset;
}

.nota,
.nota-documento {
  margin: 0.3rem 0;
  font-size: 0.76rem;
  color: #404040;
}

.nota-documento {
  padding: 0.7rem;
  background: #e8edf5;
  border: 1px solid #8090a8;
  text-align: center;
}

.sin-formas {
  margin: 0;
  padding: 0.4rem;
  background: #ffd0cc;
  border: 1px solid #a00000;
  font-size: 0.78rem;
}

.cambio {
  margin: 0.35rem 0 0.5rem;
  font-size: 0.85rem;
  text-align: right;
  color: #303030;
}

.cambio.falta {
  color: #8b0000;
  font-weight: 700;
}

.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
}

.btn-legacy {
  min-height: 3rem;
  padding: 0.3rem 0.5rem;
  background: #d4d0c8;
  border: 2px outset #f5f5f5;
  color: #000;
  font-family: inherit;
  font-size: 0.85rem;
  font-weight: 700;
  cursor: pointer;
  touch-action: manipulation;
}

.btn-legacy:active:not(:disabled) {
  border-style: inset;
}

.btn-legacy:disabled {
  color: #8a8a8a;
  text-shadow: 1px 1px 0 #fff;
  cursor: not-allowed;
}

.num {
  font-size: 1.3rem;
}

.aux {
  background: #e8e0d0;
}

.exacto {
  font-size: 0.78rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 5px;
  padding: 0.6rem;
}

.aceptar {
  background: #c8e0c8;
}

.cancelar {
  background: #e0c8c8;
}
</style>
