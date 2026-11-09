<script setup lang="ts">
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  empresa: string
  tipo: string
  albaran: number
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
  etiquetasPedidas: number
  estado: string
}

const emit = defineEmits<{
  /** true si el albarán abierto pertenece a un encargo (la ficha lo usa para dejar Finalizar disponible). */
  encargo: [esEncargo: boolean]
}>()

const router = useRouter()
const encargo = ref<EncargoAlbaran | null>(null)
const error = ref<string | null>(null)

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

watch(
  () => [props.empresa, props.tipo, props.albaran] as const,
  () => {
    void cargar()
  },
  { immediate: true }
)

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
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo leer el encargo de este albarán')
  }
}

/** El albarán sigue siendo A/nº al cobrar: hay que releer el estado a mano. */
defineExpose({ recargar: () => cargar(true) })

function volver() {
  void router.push('/ventas/encargos')
}
</script>

<template>
  <aside v-if="encargo || error" class="encargo-albaran">
    <p v-if="error" class="error">{{ error }}</p>
    <template v-if="encargo">
      <strong>Encargo {{ encargo.albaran }}</strong>
      <span>{{ encargo.nombre }} <small v-if="encargo.telefono">{{ encargo.telefono }}</small></span>
      <span>{{ encargo.tipo === 'impresion' ? 'Impresión' : 'Revelado' }} · {{ encargo.origenNombre }} → {{ encargo.destinoNombre }}</span>
      <span>{{ encargo.modalidad === 'envio' ? 'Mensajero' : 'Recogida' }}<template v-if="encargo.tipo !== 'impresion'"> · {{ encargo.etiquetasPedidas }} etiqueta(s)</template></span>
      <span>{{ textoEstado(encargo) }}</span>
      <span v-if="encargo.estado !== 'entregado'">Se entrega al cobrar el tiquet o la factura. Después, vuelva a encargos.</span>
      <span v-else>Ya cobrado. Vuelva a encargos.</span>
      <button type="button" class="volver" @click="volver">Volver a encargos</button>
    </template>
  </aside>
</template>

<style scoped>
.encargo-albaran {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem 0.8rem;
  margin: 0 0 0.6rem;
  padding: 0.45rem 0.7rem;
  border: 1px solid #c5d0c2;
  background: #f6f8f5;
  font-size: 0.9rem;
}
.error { color: #8a1f1f; margin: 0; }
small { color: #555; }
button { cursor: pointer; }
button.volver {
  margin-left: auto;
  font-weight: 600;
  padding: 0.28rem 0.7rem;
  border: 1px solid #3d5c38;
  background: #e7f0e4;
}
</style>
