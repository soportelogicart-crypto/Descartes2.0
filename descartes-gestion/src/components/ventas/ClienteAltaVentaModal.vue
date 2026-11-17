<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

const props = defineProps<{
  open: boolean
  empresa: string
}>()

const emit = defineEmits<{
  cerrar: []
  creado: [codigo: string]
}>()

const nombreInput = ref<HTMLInputElement | null>(null)
const codigoInput = ref<HTMLInputElement | null>(null)
const guardando = ref(false)
const error = ref('')
const codigoAutomatico = ref(false)
const formas = ref<{ codigo: string; descripcion: string }[]>([])

const form = ref(vacio())

function vacio() {
  return {
    codigo: '',
    nombre: '',
    nif: '',
    telefono1: '',
    direccion: '',
    codigoPostal: '',
    poblacion: '',
    formaPago: '',
  }
}

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) return
    form.value = vacio()
    error.value = ''
    codigoAutomatico.value = false
    await Promise.all([cargarCodigo(), cargarFormas()])
    await nextTick()
    if (codigoAutomatico.value) nombreInput.value?.focus()
    else codigoInput.value?.focus()
  }
)

async function cargarCodigo() {
  try {
    const { data } = await api.get('/api/mantenimiento/clientes/siguiente-codigo', {
      params: { empresa: props.empresa || undefined },
    })
    if (data.automatico && data.codigo) {
      form.value = { ...form.value, codigo: String(data.codigo) }
      codigoAutomatico.value = true
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo obtener el código de cliente')
  }
}

async function cargarFormas() {
  try {
    const { data } = await api.get('/api/mantenimiento/formas-pago', {
      params: { pageSize: 500, activo: true },
    })
    const items = Array.isArray(data?.items) ? data.items : []
    formas.value = items
      .map((f: { codigo?: string; descripcion?: string; nombre?: string }) => ({
        codigo: String(f.codigo ?? '').trim(),
        descripcion: String(f.descripcion ?? f.nombre ?? f.codigo ?? '').trim(),
      }))
      .filter((f: { codigo: string }) => f.codigo !== '')
  } catch {
    formas.value = []
  }
}

async function guardar() {
  if (guardando.value) return
  const nombre = form.value.nombre.trim()
  const nif = form.value.nif.trim()
  const formaPago = form.value.formaPago.trim()
  const codigo = form.value.codigo.trim()
  if (!codigo) {
    error.value = 'El código de cliente es obligatorio.'
    return
  }
  if (!nombre) {
    error.value = 'La razón social es obligatoria.'
    return
  }
  if (!nif) {
    error.value = 'El NIF es obligatorio.'
    return
  }
  if (!formaPago) {
    error.value = 'Debe asignar una forma de pago al cliente.'
    return
  }
  const direccion = form.value.direccion.trim()
  const codigoPostal = form.value.codigoPostal.trim()
  const poblacion = form.value.poblacion.trim()
  guardando.value = true
  error.value = ''
  try {
    const { data } = await api.post('/api/mantenimiento/clientes', {
      codigo,
      nombre,
      nif,
      telefono1: form.value.telefono1.trim(),
      direccion,
      codigoPostal,
      poblacion,
      formaPago,
      tiendaCodigo: props.empresa,
      direccionEnvio: direccion,
      codigoPostalEnvio: codigoPostal,
      poblacionEnvio: poblacion,
      pais: 'España',
      paisEnvio: 'España',
    })
    const creado = String(data?.codigo ?? codigo).trim()
    emit('creado', creado)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo crear el cliente')
  } finally {
    guardando.value = false
  }
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="emit('cerrar')">
    <form class="panel" @submit.prevent="guardar">
      <header>
        <h2>Nuevo cliente</h2>
        <button type="button" class="cerrar" @click="emit('cerrar')">Cerrar</button>
      </header>
      <p class="nota">Alta corta. El NIF es obligatorio. El resto de la ficha se completa después en Mantenimiento.</p>
      <p v-if="error" class="error">{{ error }}</p>
      <div class="campos">
        <label v-if="!codigoAutomatico">
          Código
          <input ref="codigoInput" v-model="form.codigo" maxlength="9" required />
        </label>
        <label v-else>
          Código
          <input :value="form.codigo" readonly />
        </label>
        <label class="ancho">
          Razón social
          <input ref="nombreInput" v-model="form.nombre" maxlength="50" required />
        </label>
        <label>
          NIF
          <input v-model="form.nif" maxlength="15" required />
        </label>
        <label>
          Teléfono
          <input v-model="form.telefono1" maxlength="15" />
        </label>
        <label class="ancho">
          Dirección
          <input v-model="form.direccion" maxlength="50" />
        </label>
        <label>
          Código postal
          <input v-model="form.codigoPostal" maxlength="8" />
        </label>
        <label>
          Población
          <input v-model="form.poblacion" maxlength="50" />
        </label>
        <label class="ancho">
          Forma de pago
          <select v-model="form.formaPago" required>
            <option value="">Seleccione</option>
            <option v-for="f in formas" :key="f.codigo" :value="f.codigo">
              {{ f.codigo }} — {{ f.descripcion }}
            </option>
          </select>
        </label>
      </div>
      <footer>
        <button type="button" @click="emit('cerrar')">Cancelar</button>
        <button type="submit" class="primary" :disabled="guardando">
          {{ guardando ? 'Guardando…' : 'Crear y usar en la venta' }}
        </button>
      </footer>
    </form>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(15, 23, 42, 0.45);
  padding: 1rem;
}
.panel {
  width: min(36rem, 100%);
  background: #fff;
  color: #0f172a;
  border-radius: 8px;
  padding: 1rem 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
h2 {
  margin: 0;
  font-size: 1.1rem;
}
.cerrar,
footer button {
  border: 1px solid #64748b;
  background: #fff;
  border-radius: 5px;
  padding: 0.35rem 0.7rem;
  cursor: pointer;
}
.primary {
  border-color: #1d4ed8;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
}
.nota {
  margin: 0;
  color: #334155;
  font-size: 0.9rem;
}
.error {
  margin: 0;
  color: #b91c1c;
}
.campos {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem 0.75rem;
}
label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.85rem;
}
.ancho {
  grid-column: 1 / -1;
}
input,
select {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  padding: 0.35rem 0.45rem;
  font: inherit;
}
footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.4rem;
}
</style>
