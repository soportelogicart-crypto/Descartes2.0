<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { emitirValeRegaloTpv } from '@/api/tpv'
import { extractApiError } from '@/composables/extractApiError'
import { imprimirComprobanteValeRegalo } from '@/composables/comprobanteValeRegalo'
import { useAuthStore } from '@/stores/auth'
import type { TpvFormaPago } from '@/types/tpv'
import { CLIENTE_RAPIDO_TPV } from '@/types/tpv'

const props = defineProps<{
  open: boolean
  empresa: string
  puesto: string
  sesion: number
  formasPago: TpvFormaPago[]
  clienteCodigo: string
  clienteNombre: string
  guardando?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  hecho: [mensaje: string]
  error: [mensaje: string]
}>()

const auth = useAuthStore()
const formaPago = ref('')
const importeStr = ref('')
const concepto = ref('')
const fechaCaducidad = ref('')
const procesando = ref(false)
const errorLocal = ref<string | null>(null)

const importe = computed(() => {
  const n = Number(importeStr.value.replace(',', '.'))
  return Number.isFinite(n) ? n : 0
})

const valido = computed(
  () => Boolean(formaPago.value) && importe.value > 0 && !procesando.value && !props.guardando
)

const clienteEtiqueta = computed(() => {
  const cod = props.clienteCodigo.trim()
  if (!cod || cod === CLIENTE_RAPIDO_TPV) {
    return 'Venta rápida (sin cliente)'
  }
  const nom = props.clienteNombre.trim()
  return nom ? `${cod} · ${nom}` : cod
})

function reiniciarFormulario() {
  errorLocal.value = null
  importeStr.value = ''
  concepto.value = ''
  fechaCaducidad.value = ''
  const actual = formaPago.value
  const codigos = props.formasPago.map((f) => f.codigo)
  if (!actual || !codigos.includes(actual)) {
    formaPago.value = props.formasPago[0]?.codigo ?? ''
  }
}

watch(
  () => props.open,
  (abierto) => {
    if (!abierto) return
    reiniciarFormulario()
  },
  { immediate: true }
)

watch(
  () => props.formasPago,
  () => {
    if (!props.open) return
    if (!formaPago.value && props.formasPago[0]) {
      formaPago.value = props.formasPago[0].codigo
    }
  },
  { deep: true }
)

function pulsar(tecla: string) {
  if (tecla === ',') {
    if (!importeStr.value.includes(',')) importeStr.value = (importeStr.value || '0') + ','
    return
  }
  const [, decimales] = importeStr.value.split(',')
  if (decimales !== undefined && decimales.length >= 2) return
  if (importeStr.value.replace(',', '').length >= 8) return
  importeStr.value = (importeStr.value + tecla).replace(/^0+(?=\d)/, '')
}

async function confirmar() {
  if (!valido.value) return
  procesando.value = true
  errorLocal.value = null
  const emp = props.empresa.trim()
  const pue = props.puesto.trim()
  const ses = props.sesion
  try {
    const cliente = props.clienteCodigo.trim()
    const res = await emitirValeRegaloTpv(emp, pue, ses, {
      formaPago: formaPago.value,
      importe: importe.value,
      subtipo: 'R',
      concepto: concepto.value.trim() || undefined,
      fechaCaducidad: fechaCaducidad.value || undefined,
      cliente: cliente && cliente !== CLIENTE_RAPIDO_TPV ? cliente : undefined,
    })
    const mov = res.ultimoMovimiento
    const codVale = mov?.valeCodigo != null ? Number(mov.valeCodigo) : 0
    const fp = props.formasPago.find((f) => f.codigo === formaPago.value)
    const cajero = String(auth.usuario?.nombre || auth.usuario?.codigo || '').trim() || '-'
    let avisoImpresion = ''
    try {
      const print = await imprimirComprobanteValeRegalo({
        puestoCodigo: pue,
        res,
        cajeroLabel: cajero,
        abrirCajon: Boolean(fp?.abrirCajon),
      })
      avisoImpresion = print.stub
        ? ` Ticket (prueba): ${print.message ?? ''}`
        : print.impresora
          ? ` Ticket enviado a «${print.impresora}».`
          : ''
    } catch (e: unknown) {
      emit(
        'hecho',
        `Vale ${codVale > 0 ? codVale : ''} registrado (+${importe.value.toFixed(2)} €), pero no se pudo imprimir: ${extractApiError(e, 'error de impresión')}`
      )
      emit('cerrar')
      return
    }
    const base =
      codVale > 0
        ? `Vale regalo nº ${codVale} por ${importe.value.toFixed(2).replace('.', ',')} €.`
        : `Vale regalo emitido por ${importe.value.toFixed(2).replace('.', ',')} €.`
    emit('hecho', `${base}${avisoImpresion}`)
    emit('cerrar')
  } catch (e: unknown) {
    errorLocal.value = extractApiError(e, 'No se pudo emitir el vale regalo')
  } finally {
    procesando.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @mousedown.self.prevent>
      <div class="ventana" role="dialog" aria-modal="true" @mousedown.stop>
        <header class="barra">VALE REGALO</header>
        <div class="cuerpo">
          <p class="hint">
            Registra una entrada de caja y emite un vale canjeable. El importe suma al acumulado de
            la forma de pago elegida.
          </p>
          <p class="cliente">
            Cliente: <strong>{{ clienteEtiqueta }}</strong>
          </p>
          <label class="campo">
            Forma de pago
            <select
              v-if="formasPago.length"
              v-model="formaPago"
              :disabled="procesando"
              @mousedown.stop
              @click.stop
            >
              <option v-for="f in formasPago" :key="f.codigo" :value="f.codigo">
                {{ f.codigo }} — {{ f.descripcion || f.etiqueta }}
              </option>
            </select>
            <span v-else class="hint">No hay formas de pago de contado en el puesto.</span>
          </label>
          <label class="campo">
            Caducidad (opcional)
            <input
              v-model="fechaCaducidad"
              type="date"
              :disabled="procesando"
              @mousedown.stop
              @click.stop
            />
          </label>
          <label class="campo">
            Concepto (opcional)
            <input
              v-model="concepto"
              type="text"
              maxlength="50"
              :disabled="procesando"
              @mousedown.stop
              @click.stop
            />
          </label>
        <div class="visor">
          <span>IMPORTE</span>
          <strong>{{ importeStr || '0' }} €</strong>
        </div>
        <div class="pad">
          <button
            v-for="t in ['7', '8', '9', '4', '5', '6', '1', '2', '3', '0']"
            :key="t"
            type="button"
            class="btn num"
            :disabled="procesando"
            @click="pulsar(t)"
          >
            {{ t }}
          </button>
          <button type="button" class="btn num" :disabled="procesando" @click="pulsar(',')">,</button>
          <button
            type="button"
            class="btn num"
            :disabled="procesando"
            @click="importeStr = importeStr.slice(0, -1)"
          >
            ←
          </button>
          <button type="button" class="btn limpiar" :disabled="procesando" @click="importeStr = ''">
            BORRAR
          </button>
        </div>
        <p v-if="errorLocal" class="err">{{ errorLocal }}</p>
      </div>
      <footer class="pie">
        <button type="button" class="btn aceptar" :disabled="!valido" @click="confirmar">
          {{ procesando ? 'Emitiendo…' : 'EMITIR VALE' }}
        </button>
        <button type="button" class="btn cancelar" :disabled="procesando" @click="emit('cerrar')">
          CANCELAR
        </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 5100;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
}

.ventana {
  width: min(26rem, 94vw);
  max-height: 92vh;
  overflow: auto;
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 20px 45px rgba(15, 23, 42, 0.35);
  color: #0f172a;
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
}

.barra {
  padding: 0.6rem 0.85rem;
  background: linear-gradient(90deg, #7c2d12, #c2410c);
  color: #fff;
  font-size: 0.85rem;
  font-weight: 600;
}

.cuerpo {
  padding: 0.75rem;
}

.hint {
  margin: 0 0 0.5rem;
  color: #64748b;
  font-size: 0.78rem;
  line-height: 1.35;
}

.cliente {
  margin: 0 0 0.65rem;
  font-size: 0.82rem;
}

.campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-bottom: 0.55rem;
  font-size: 0.78rem;
  font-weight: 600;
}

.campo select,
.campo input {
  padding: 0.45rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  font: inherit;
  font-weight: 400;
}

.visor {
  display: flex;
  align-items: baseline;
  padding: 0.45rem 0.7rem;
  background: #0f172a;
  border-radius: 10px;
  color: #fb923c;
  font-family: 'Cascadia Mono', Consolas, monospace;
}

.visor strong {
  margin-left: auto;
  font-size: 1.6rem;
}

.pad {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 5px;
  margin-top: 0.6rem;
}

.btn {
  min-height: 3rem;
  padding: 0.3rem 0.5rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  color: #1e293b;
  font-family: inherit;
  font-weight: 600;
  cursor: pointer;
  touch-action: manipulation;
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.num {
  font-size: 1.25rem;
  font-weight: 500;
}

.limpiar {
  background: #f1f5f9;
  color: #475569;
  font-size: 0.75rem;
}

.err {
  margin: 0.5rem 0 0;
  color: #b91c1c;
  font-size: 0.8rem;
}

.pie {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
  padding: 0.75rem;
  border-top: 1px solid #e2e8f0;
}

.aceptar {
  background: #c2410c;
  border-color: #c2410c;
  color: #fff;
}

.cancelar {
  background: #e2e8f0;
  border-color: #cbd5e1;
  color: #334155;
}
</style>
