<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
import { imprimirEtiquetasEncargo } from '@/composables/useImpresionEtiquetaEncargo'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { useEncargoVentaStore } from '@/stores/encargoVenta'

const props = defineProps<{
  empresa: string
  tipo: string
  albaran: number
  /** Total del albarán (para mostrar lo pendiente tras lo entregado a cuenta). */
  importe?: number | null
  /** Entregado a cuenta (ficha.pagoACuenta). */
  pagoACuenta?: number | null
}>()

type EncargoAlbaran = {
  numero: number
  nombre: string
  telefono: string
  origenNombre: string
  destinoNombre: string
  modalidad: string
  tipo: string
  albaran: number
  albaranEmpresa?: string
  etiquetasPedidas: number
  etiquetasImpresas: number
  estado: string
  codigoBarras?: string
}

const emit = defineEmits<{
  /** true si el albarán abierto pertenece a un encargo (la ficha lo usa para dejar Finalizar disponible). */
  encargo: [esEncargo: boolean]
}>()

const router = useRouter()
const puesto = usePuestoContextoStore()
const store = useEncargoVentaStore()
const encargo = ref<EncargoAlbaran | null>(null)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ocupado = ref(false)

const estadoTxt: Record<string, string> = {
  pendiente_revelar: 'Pendiente de revelar',
  pendiente_entrega: 'Ya revelado, pendiente de entrega',
  entregado: 'Entregado',
}

function textoEstado(e: EncargoAlbaran): string {
  if (e.estado === 'pendiente_revelar' && e.tipo === 'impresion') return 'Pendiente de imprimir'
  if (e.estado === 'pendiente_entrega' && e.tipo === 'impresion') return 'Pendiente de entrega'
  return estadoTxt[e.estado] || e.estado
}

const llevaEtiquetas = computed(() => !!encargo.value && encargo.value.tipo !== 'impresion')

const sinImprimir = computed(() => {
  const e = encargo.value
  if (!e || e.tipo === 'impresion') return 0
  return Math.max(0, e.etiquetasPedidas - e.etiquetasImpresas)
})

const textoBotonEtiquetas = computed(() => {
  const e = encargo.value
  if (!e) return ''
  if (e.etiquetasImpresas <= 0) return `Imprimir ${e.etiquetasPedidas} etiqueta(s)`
  return sinImprimir.value > 0
    ? `Imprimir etiquetas (${e.etiquetasPedidas}, hay cambios)`
    : `Reimprimir ${e.etiquetasPedidas} etiqueta(s)`
})

const importeFmt = (n: number) =>
  `${n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} €`

const aCuenta = computed(() => Number(props.pagoACuenta ?? 0))
const pendiente = computed(() => {
  if (props.importe == null) return null
  return Math.max(0, Number(props.importe) - aCuenta.value)
})

watch(
  () => [props.empresa, props.tipo, props.albaran] as const,
  () => {
    mensaje.value = null
    void cargar()
  },
  { immediate: true }
)

// Una venta guardada ha creado/actualizado el encargo: releerlo y mostrar el aviso si lo hay.
watch(
  () => store.tick,
  () => {
    if (esDeEstaVenta()) void cargar(true)
  }
)

function esDeEstaVenta(): boolean {
  return (
    store.tick > 0 &&
    store.empresa === props.empresa &&
    store.tipo === props.tipo &&
    store.albaran === props.albaran
  )
}

/** Tras leer el encargo: avisa de lo que dejó el guardado de la venta (fallo o etiquetas por imprimir). */
function mostrarAvisoDeGuardado() {
  if (!esDeEstaVenta()) return
  if (store.aviso) {
    error.value = store.aviso
  } else if (sinImprimir.value > 0) {
    mensaje.value = `Este albarán lleva ${encargo.value?.etiquetasPedidas} sobre(s): imprima las etiquetas.`
  }
  store.consumir()
}

async function cargar(conservar = false) {
  error.value = null
  if (!conservar) {
    encargo.value = null
    emit('encargo', false)
  }
  if (!props.empresa || !props.tipo || props.albaran <= 0) {
    encargo.value = null
    emit('encargo', false)
    return
  }
  const clave = `${props.empresa}/${props.tipo}/${props.albaran}`
  try {
    const { data } = await api.get('/api/encargos/albaran', {
      params: { empresa: props.empresa, tipo: props.tipo, albaran: props.albaran },
    })
    if (clave !== `${props.empresa}/${props.tipo}/${props.albaran}`) return
    encargo.value = data.encargo ?? null
    emit('encargo', encargo.value !== null)
    mostrarAvisoDeGuardado()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo leer el encargo de este albarán')
  }
}

/** El albarán sigue siendo A/nº al cobrar: hay que releer el estado a mano. */
const etiquetasDisponibles = computed(() =>
  llevaEtiquetas.value && encargo.value && encargo.value.estado !== 'entregado'
    ? encargo.value.etiquetasPedidas
    : 0
)

defineExpose({
  recargar: () => cargar(true),
  etiquetasDisponibles,
  error,
  imprimirEtiquetas: () => imprimirEtiquetas(),
})

async function imprimirEtiquetas() {
  const e = encargo.value
  if (!e || ocupado.value) return
  ocupado.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await imprimirEtiquetasEncargo(
      e.numero,
      String(puesto.puestoCodigo ?? ''),
      String(puesto.empresaCodigo ?? e.albaranEmpresa ?? props.empresa)
    )
    mensaje.value = `${res.etiquetas} etiqueta(s) enviadas${res.impresora ? ` a ${res.impresora}` : ''}.`
    await cargar(true)
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudieron imprimir las etiquetas')
  } finally {
    ocupado.value = false
  }
}

async function entregar() {
  const e = encargo.value
  if (!e || ocupado.value) return
  if (!window.confirm('¿Marcar este encargo como entregado al cliente? Ya no se podrán imprimir etiquetas.')) return
  ocupado.value = true
  error.value = null
  mensaje.value = null
  try {
    await api.post(`/api/encargos/${e.numero}/entregar`)
    mensaje.value = 'Encargo entregado.'
    await cargar(true)
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudo marcar como entregado')
  } finally {
    ocupado.value = false
  }
}

async function cambiarModalidad(valor: string) {
  const e = encargo.value
  if (!e || valor === e.modalidad || ocupado.value) return
  ocupado.value = true
  error.value = null
  try {
    await api.post(`/api/encargos/${e.numero}/modalidad`, { modalidad: valor })
    await cargar(true)
  } catch (err: unknown) {
    error.value = extractApiError(err, 'No se pudo cambiar la modalidad')
  } finally {
    ocupado.value = false
  }
}

function volver() {
  void router.push('/ventas/encargos')
}
</script>

<template>
  <aside v-if="encargo || error" class="encargo-card" :class="{ cerrado: encargo?.estado === 'entregado' }">
    <p v-if="error" class="aviso error">{{ error }}</p>
    <p v-if="mensaje" class="aviso ok">{{ mensaje }}</p>
    <template v-if="encargo">
      <div class="cuerpo">
        <div class="datos">
          <div class="titulo">
            <span class="etiqueta-tipo">{{ encargo.tipo === 'impresion' ? 'Impresión' : 'Revelado' }}</span>
            <strong>Encargo {{ encargo.albaranEmpresa }}{{ encargo.albaran }}</strong>
            <span class="estado" :class="`estado-${encargo.estado}`">{{ textoEstado(encargo) }}</span>
          </div>
          <div class="cliente">
            {{ encargo.nombre }}<span v-if="encargo.telefono" class="tel">{{ encargo.telefono }}</span>
          </div>
          <div class="detalles">
            <span>{{ encargo.origenNombre }} → {{ encargo.destinoNombre }}</span>
            <span v-if="llevaEtiquetas">{{ encargo.etiquetasPedidas }} sobre(s)</span>
            <label v-if="encargo.estado !== 'entregado'" class="modalidad">
              Entrega
              <select
                :value="encargo.modalidad"
                :disabled="ocupado"
                @change="cambiarModalidad(($event.target as HTMLSelectElement).value)"
              >
                <option value="recogida">Recogida en tienda</option>
                <option value="envio">Mensajero</option>
              </select>
            </label>
            <span v-else>{{ encargo.modalidad === 'envio' ? 'Mensajero' : 'Recogida en tienda' }}</span>
            <span v-if="aCuenta > 0" class="cuenta">
              A cuenta {{ importeFmt(aCuenta) }}<template v-if="pendiente != null"> · Pendiente {{ importeFmt(pendiente) }}</template>
            </span>
          </div>
          <p class="pista">
            {{ encargo.estado !== 'entregado'
              ? 'Se entrega cuando el cliente recoge el carrete; el cobro no lo marca como entregado.'
              : 'Entregado al cliente.' }}
          </p>
        </div>
        <div class="acciones">
          <button
            v-if="llevaEtiquetas && encargo.estado !== 'entregado'"
            type="button"
            class="btn btn-principal"
            :class="{ urgente: sinImprimir > 0 }"
            :disabled="ocupado"
            @click="imprimirEtiquetas"
          >
            {{ ocupado ? 'Imprimiendo…' : textoBotonEtiquetas }}
          </button>
          <button
            v-if="encargo.estado !== 'entregado'"
            type="button"
            class="btn btn-exito"
            :disabled="ocupado"
            @click="entregar"
          >
            Marcar entregado
          </button>
          <button type="button" class="btn btn-secundario" @click="volver">Volver a encargos</button>
        </div>
      </div>
    </template>
  </aside>
</template>

<style scoped>
.encargo-card {
  margin: 0 0 0.6rem;
  padding: 0.65rem 0.85rem;
  border: 1px solid #e2e8f0;
  border-left: 4px solid #2563eb;
  border-radius: 8px;
  background: #fff;
  box-shadow: 0 1px 2px rgb(15 23 42 / 6%);
  font-size: 0.88rem;
  color: #1e293b;
}
.encargo-card.cerrado {
  border-left-color: #16a34a;
}
.aviso {
  margin: 0 0 0.5rem;
  padding: 0.35rem 0.6rem;
  border-radius: 6px;
}
.aviso.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.aviso.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.cuerpo {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem 1.25rem;
  flex-wrap: wrap;
}
.datos {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  min-width: 0;
}
.titulo {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  font-size: 0.98rem;
}
.etiqueta-tipo {
  padding: 0.05rem 0.45rem;
  border-radius: 4px;
  background: #dbeafe;
  color: #1d4ed8;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}
.estado {
  padding: 0.1rem 0.55rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 600;
  background: #fef3c7;
  color: #92400e;
}
.estado-pendiente_entrega { background: #dbeafe; color: #1e40af; }
.estado-entregado { background: #dcfce7; color: #166534; }
.cliente { font-weight: 600; }
.tel { margin-left: 0.5rem; font-weight: 400; color: #64748b; }
.detalles {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.3rem 1rem;
  color: #475569;
}
.modalidad {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.modalidad select {
  padding: 0.15rem 0.35rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  background: #fff;
  font-size: 0.85rem;
}
.cuenta { font-weight: 600; color: #1e293b; }
.pista { margin: 0; color: #64748b; font-size: 0.8rem; }
.acciones {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  flex-wrap: wrap;
  margin-left: auto;
}
.btn {
  padding: 0.42rem 0.85rem;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.15s, border-color 0.15s;
}
.btn:disabled { opacity: 0.6; cursor: default; }
.btn-principal { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.btn-principal:hover:not(:disabled) { background: #1d4ed8; }
.btn-principal.urgente { background: #f59e0b; border-color: #d97706; color: #fff; }
.btn-principal.urgente:hover:not(:disabled) { background: #d97706; }
.btn-exito { background: #fff; border-color: #16a34a; color: #15803d; }
.btn-exito:hover:not(:disabled) { background: #f0fdf4; }
.btn-secundario { background: #fff; border-color: #cbd5e1; color: #334155; }
.btn-secundario:hover:not(:disabled) { background: #f1f5f9; }
</style>
