<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { consultarValeCobro } from '@/api/ventas'
import { extractApiError } from '@/composables/extractApiError'
import type { TpvFormaPago } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  total: number
  formasPago: TpvFormaPago[]
  permiteFactura: boolean
  guardando?: boolean
  cobrandoDatafono?: boolean
  /** Tras un abono en TPV: preseleccionar la forma del ticket origen (p. ej. tarjeta). */
  formaPagoInicial?: string
  empresa?: string
}>()

const emit = defineEmits<{
  confirmar: [
    {
      tipoDocumento: string
      formaPago: string
      entregado: number
      valeCodigo?: number
      valeImporte?: number
      formaPago2?: string
    },
  ]
  cancelar: []
}>()

const tipoDocumento = ref('T')
const formaPago = ref('')
const formaPago2 = ref('')
const entrada = ref('')
const valeNumero = ref('')
const valeSaldo = ref(0)
const valeCodigo = ref(0)
const valeAviso = ref('')
const valeBuscando = ref(false)
const valeInputRef = ref<HTMLInputElement | null>(null)

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

const esDevolucion = computed(() => props.total < -0.005)

const formaSeleccionada = computed(() =>
  props.formasPago.find((f) => f.codigo === formaPago.value)
)

const pedirNumeroVale = computed(
  () => requierePago.value && !esDevolucion.value && Boolean(formaSeleccionada.value?.vales)
)

const valeAplicadoPrevisto = computed(() => {
  if (!pedirNumeroVale.value || valeCodigo.value <= 0) return 0
  return Math.min(valeSaldo.value, Math.max(0, props.total))
})

const diferencia = computed(() => {
  if (!pedirNumeroVale.value || valeCodigo.value <= 0) return 0
  return Math.round((Math.max(0, props.total) - valeAplicadoPrevisto.value) * 100) / 100
})

const pedirDiferencia = computed(() => diferencia.value > 0.005)

const formasDiferencia = computed(() => props.formasPago.filter((f) => !f.vales))

const formaDiferencia = computed(() =>
  formasDiferencia.value.find((f) => f.codigo === formaPago2.value)
)

const datafonoDiferencia = computed(
  () => Boolean(formaDiferencia.value?.datafono && formaDiferencia.value.chipAcumuladoMenu)
)

const mostrarEntregado = computed(() => {
  if (!requierePago.value) return false
  if (!pedirNumeroVale.value) return true
  return pedirDiferencia.value && Boolean(formaPago2.value) && !datafonoDiferencia.value
})

const baseEntregado = computed(() => (pedirDiferencia.value ? diferencia.value : props.total))

/** Solo tiene sentido dar cambio en efectivo; con datáfono se cobra el importe justo. */
const cambio = computed(() => {
  if (!mostrarEntregado.value || entregado.value <= 0) return 0
  return Math.round((entregado.value - baseEntregado.value) * 100) / 100
})

const falta = computed(() => mostrarEntregado.value && entregado.value > 0 && cambio.value < 0)
const valido = computed(
  () =>
    (!requierePago.value || Boolean(formaPago.value)) &&
    (!pedirNumeroVale.value || valeCodigo.value > 0) &&
    (!pedirDiferencia.value || Boolean(formaPago2.value)) &&
    !falta.value
)

function textoForma(f: TpvFormaPago): string {
  if (f.vales) return f.descripcion || 'VALE'
  return f.etiqueta || f.descripcion
}

function euros(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' €'
}

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    tipoDocumento.value = 'T'
    entrada.value = ''
    formaPago2.value = ''
    valeNumero.value = ''
    valeSaldo.value = 0
    valeCodigo.value = 0
    valeAviso.value = ''
    const sugerida = String(props.formaPagoInicial ?? '').trim()
    if (
      sugerida &&
      props.formasPago.some((f) => f.codigo === sugerida)
    ) {
      formaPago.value = sugerida
    } else {
      formaPago.value = ''
    }
    await nextTick()
    if (pedirNumeroVale.value) valeInputRef.value?.focus()
  },
  { immediate: true }
)

watch([formaPago, tipoDocumento], async () => {
  formaPago2.value = ''
  entrada.value = ''
  if (!pedirNumeroVale.value) {
    valeNumero.value = ''
    valeSaldo.value = 0
    valeCodigo.value = 0
    valeAviso.value = ''
    return
  }
  await nextTick()
  valeInputRef.value?.focus()
})

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
  entrada.value = baseEntregado.value.toFixed(2).replace('.', ',')
}

function confirmar() {
  if (!valido.value || props.guardando) return
  emit('confirmar', {
    tipoDocumento: tipoDocumento.value,
    formaPago: requierePago.value ? formaPago.value : '',
    entregado: mostrarEntregado.value ? entregado.value : 0,
    ...(valeCodigo.value > 0
      ? { valeCodigo: valeCodigo.value, valeImporte: valeAplicadoPrevisto.value }
      : {}),
    ...(pedirDiferencia.value && formaPago2.value ? { formaPago2: formaPago2.value } : {}),
  })
}

async function buscarVale() {
  const codigo = Number(String(valeNumero.value).replace(/\D/g, ''))
  valeSaldo.value = 0
  valeCodigo.value = 0
  valeAviso.value = ''
  if (!codigo) return
  const empresa = String(props.empresa ?? '').trim()
  if (!empresa) {
    valeAviso.value = 'No hay tienda para comprobar el vale'
    return
  }
  valeBuscando.value = true
  try {
    const vale = await consultarValeCobro(empresa, codigo)
    valeCodigo.value = vale.codigo
    valeSaldo.value = Number(vale.saldo) || 0
    formaPago2.value = ''
    const aplicable = Math.min(valeSaldo.value, Math.abs(props.total))
    const sobraVale = Math.round((valeSaldo.value - aplicable) * 100) / 100
    const faltaCompra = Math.round((Math.abs(props.total) - aplicable) * 100) / 100
    if (faltaCompra > 0.005) {
      valeAviso.value = `Vale ${vale.codigo}: se aplican ${euros(aplicable)}. Faltan ${euros(faltaCompra)}.`
    } else if (sobraVale > 0.005) {
      valeAviso.value = `Vale ${vale.codigo}: se aplican ${euros(aplicable)} y se imprime otro de ${euros(sobraVale)}.`
    } else {
      valeAviso.value = `Vale ${vale.codigo}: se aplican ${euros(aplicable)}.`
    }
  } catch (e: unknown) {
    valeAviso.value = extractApiError(e, 'Vale no encontrado')
  } finally {
    valeBuscando.value = false
  }
}
</script>

<template>
  <div v-if="open" class="overlay" @mousedown.prevent>
    <div class="ventana" role="dialog" aria-modal="true">
      <header class="barra">{{ esDevolucion ? 'DEVOLUCIÓN / ABONO' : 'FINALIZAR VENTA' }}</header>

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

        <p v-if="esDevolucion && requierePago" class="nota devolucion">
          Importe negativo: al confirmar con tarjeta (datáfono) se enviará una
          <strong>devolución</strong> al TPV integrado.
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
            {{ textoForma(f) }}
          </button>
          </div>
          <p v-else class="sin-formas">
            No hay formas de pago de contado configuradas (CobroDeArqueo). Revise Mantenimiento →
            Formas de pago.
          </p>
          <p
            v-if="
              esDevolucion &&
              formaSeleccionada?.datafono &&
              formaSeleccionada.chipAcumuladoMenu
            "
            class="nota datafono-hint"
          >
            Forma con datáfono: pulse «Cobrar y finalizar» para devolver el importe en el pinpad.
          </p>

          <template v-if="pedirNumeroVale">
            <p class="rotulo">Nº VALE</p>
            <div class="vale-linea">
              <input
                ref="valeInputRef"
                v-model="valeNumero"
                class="vale-input"
                inputmode="numeric"
                placeholder="Número o código de barras"
                :disabled="valeBuscando"
                @mousedown.stop
                @input="valeCodigo = 0; valeSaldo = 0; formaPago2 = ''"
                @keydown.enter.prevent="buscarVale"
              />
              <button type="button" class="btn-legacy" :disabled="valeBuscando" @click="buscarVale">
                Comprobar
              </button>
            </div>
            <p v-if="valeAviso" class="nota">{{ valeAviso }}</p>
          </template>

          <template v-if="pedirDiferencia">
            <div class="bloque-diferencia">
              <div class="dif-cab">
                <span class="dif-titulo">FALTA POR COBRAR</span>
                <strong class="dif-importe">{{ euros(diferencia) }}</strong>
              </div>
              <p class="dif-detalle">
                Vale {{ valeCodigo }}: {{ euros(valeAplicadoPrevisto) }} · Total {{ euros(total) }}
              </p>
              <p class="rotulo">FORMA DE PAGO DE LA DIFERENCIA</p>
              <div v-if="formasDiferencia.length" class="formas">
                <button
                  v-for="f in formasDiferencia"
                  :key="'dif-' + f.codigo"
                  type="button"
                  class="btn-legacy forma"
                  :class="{ activa: formaPago2 === f.codigo }"
                  :title="f.descripcion"
                  @click="formaPago2 = f.codigo"
                >
                  {{ textoForma(f) }}
                </button>
              </div>
              <p v-else class="sin-formas">No hay otra forma de pago de contado para la diferencia.</p>
            </div>
          </template>

          <template v-if="mostrarEntregado">
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
          <p v-if="cobrandoDatafono" class="datafono-espera">
            Esperando respuesta del datáfono…
          </p>
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
          {{
            cobrandoDatafono
              ? 'ESPERANDO DATÁFONO…'
              : guardando
                ? 'FINALIZANDO…'
                : requierePago
                  ? 'COBRAR Y FINALIZAR'
                  : 'FINALIZAR'
          }}
        </button>
        <button
          type="button"
          class="btn-legacy cancelar"
          :disabled="guardando && !cobrandoDatafono"
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
  max-height: min(92vh, 46rem);
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  color: #0f172a;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #0f172a, #1e293b);
  color: #f8fafc;
  font-size: 0.85rem;
  font-weight: 600;
}

.cuerpo {
  padding: 0.75rem;
  overflow: auto;
}

.rotulo {
  margin: 0.6rem 0 0.3rem;
  font-size: 0.68rem;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: #64748b;
}

.visor {
  display: flex;
  align-items: baseline;
  gap: 0.5rem;
  padding: 0.4rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #34d399;
  font-family: 'Cascadia Mono', Consolas, monospace;
}

.visor.total .visor-val {
  font-size: 2rem;
}

.visor-lbl {
  font-size: 0.7rem;
  color: #64748b;
}

.visor-val {
  margin-left: auto;
  font-size: 1.5rem;
  font-weight: 600;
}

.tipos,
.formas {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 4px;
}

.tipo.activa,
.forma.activa {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}

.nota.devolucion,
.nota.datafono-hint {
  margin: 0.35rem 0 0.5rem;
  padding: 0.45rem 0.55rem;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  color: #1e3a8a;
  font-size: 0.78rem;
  line-height: 1.35;
}

.nota,
.nota-documento {
  margin: 0.3rem 0;
  font-size: 0.76rem;
  color: #64748b;
}

.vale-linea {
  display: flex;
  gap: 0.4rem;
  margin-bottom: 0.35rem;
}

.vale-input {
  flex: 1;
  min-width: 0;
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font-size: 1rem;
}

.bloque-diferencia {
  margin: 0.55rem 0 0.35rem;
  padding: 0.7rem 0.75rem;
  border: 2px solid #d97706;
  border-radius: 10px;
  background: #fffbeb;
}

.dif-cab {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
}

.dif-titulo {
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.05em;
  color: #92400e;
}

.dif-importe {
  font-size: 1.55rem;
  font-weight: 800;
  color: #9a3412;
  font-variant-numeric: tabular-nums;
}

.dif-detalle {
  margin: 0.25rem 0 0.15rem;
  font-size: 0.75rem;
  color: #78350f;
}

.bloque-diferencia .rotulo {
  color: #92400e;
}

.nota-documento {
  padding: 0.7rem;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  text-align: center;
}

.sin-formas {
  margin: 0;
  padding: 0.5rem;
  background: #fff1f2;
  border: 1px solid #fecdd3;
  border-radius: 10px;
  color: #be123c;
  font-size: 0.78rem;
}

.cambio {
  margin: 0.35rem 0 0.5rem;
  font-size: 0.85rem;
  text-align: right;
  color: #475569;
}

.cambio.falta {
  color: #be123c;
  font-weight: 600;
}

.datafono-espera {
  margin: 0.5rem 0;
  padding: 0.65rem;
  border-radius: 10px;
  background: #ecfeff;
  color: #0e7490;
  font-weight: 700;
  text-align: center;
}

.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
}

.btn-legacy {
  min-height: 3rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-size: 0.85rem;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
  transition: background-color 0.12s ease, border-color 0.12s ease, transform 0.06s ease;
}

.btn-legacy:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #94a3b8;
}

.btn-legacy:active:not(:disabled) {
  transform: translateY(1px);
}

.btn-legacy:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.num {
  font-size: 1.3rem;
  font-weight: 500;
}

.aux {
  background: #f1f5f9;
  color: #475569;
}

.exacto {
  font-size: 0.78rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.aceptar {
  background: #059669;
  border-color: #059669;
  color: #fff;
}

.aceptar:hover:not(:disabled) {
  background: #047857;
  border-color: #047857;
}

.cancelar {
  background: #e2e8f0;
  border-color: #cbd5e1;
  color: #334155;
}
</style>
