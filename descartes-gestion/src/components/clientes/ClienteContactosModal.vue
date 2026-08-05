<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

export type ClienteContacto = {
  cliente: string
  num: string
  nroLin: number
  nombre: string | null
  nif: string | null
  direccion: string | null
  lUpdate: string | null
  departamento: string | null
  email: string | null
  telefono: string | null
  observaciones: string | null
  facturas: boolean
  comercial: boolean
}

const props = defineProps<{
  open: boolean
  clienteCodigo: string
  puedeEditar?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const items = ref<ClienteContacto[]>([])
const seleccion = ref<{ num: string; nroLin: number } | null>(null)
const esNueva = ref(false)

const form = reactive({
  departamento: '',
  nombre: '',
  email: '',
  telefono: '',
  observaciones: '',
  facturas: false,
  comercial: false,
})

const puedeEscribir = computed(() => props.puedeEditar !== false)
const puedeGuardar = computed(() => puedeEscribir.value && !saving.value && !!props.clienteCodigo)

watch(
  () => props.open,
  async (abierto) => {
    if (abierto) await cargar()
  }
)

function vaciarFormulario() {
  form.departamento = ''
  form.nombre = ''
  form.email = ''
  form.telefono = ''
  form.observaciones = ''
  form.facturas = false
  form.comercial = false
}

function cargarEnFormulario(item: ClienteContacto) {
  form.departamento = String(item.departamento ?? '')
  form.nombre = String(item.nombre ?? '')
  form.email = String(item.email ?? '')
  form.telefono = String(item.telefono ?? '')
  form.observaciones = String(item.observaciones ?? '')
  form.facturas = Boolean(item.facturas)
  form.comercial = Boolean(item.comercial)
}

function payloadFormulario() {
  return {
    departamento: form.departamento,
    nombre: form.nombre,
    email: form.email,
    telefono: form.telefono,
    observaciones: form.observaciones,
    facturas: form.facturas,
    comercial: form.comercial,
  }
}

async function cargar(seleccionarPrimera = true) {
  loading.value = true
  error.value = null
  seleccion.value = null
  esNueva.value = false
  vaciarFormulario()
  try {
    const { data } = await api.get(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/contactos`
    )
    items.value = data.items ?? []
    if (seleccionarPrimera && items.value.length > 0) {
      seleccionar(items.value[0])
    } else {
      esNueva.value = true
      vaciarFormulario()
    }
  } catch (e) {
    error.value = extractApiError(e, 'Error al cargar contactos')
    items.value = []
    esNueva.value = true
    vaciarFormulario()
  } finally {
    loading.value = false
  }
}

async function refrescarListaYLimpiar() {
  error.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/contactos`
    )
    items.value = data.items ?? []
  } catch (e) {
    error.value = extractApiError(e, 'Error al cargar contactos')
  }
  seleccion.value = null
  esNueva.value = true
  vaciarFormulario()
}

function seleccionar(item: ClienteContacto) {
  esNueva.value = false
  seleccion.value = { num: item.num, nroLin: item.nroLin }
  cargarEnFormulario(item)
}

function onNueva() {
  if (!puedeEscribir.value) return
  esNueva.value = true
  seleccion.value = null
  vaciarFormulario()
}

async function onGuardar() {
  if (!puedeGuardar.value) return
  saving.value = true
  error.value = null
  try {
    const body = payloadFormulario()
    if (esNueva.value || !seleccion.value) {
      await api.post(
        `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/contactos`,
        body
      )
    } else {
      const { num, nroLin } = seleccion.value
      await api.put(
        `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/contactos/${encodeURIComponent(num)}/${nroLin}`,
        body
      )
    }
    await refrescarListaYLimpiar()
  } catch (e) {
    error.value = extractApiError(e, 'Error al guardar el contacto')
  } finally {
    saving.value = false
  }
}

async function onEliminar() {
  if (!puedeEscribir.value || !seleccion.value || esNueva.value) return
  if (!confirm('¿Eliminar este contacto?')) return
  saving.value = true
  error.value = null
  try {
    const { num, nroLin } = seleccion.value
    await api.delete(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/contactos/${encodeURIComponent(num)}/${nroLin}`
    )
    await refrescarListaYLimpiar()
  } catch (e) {
    error.value = extractApiError(e, 'Error al eliminar el contacto')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>Contactos — {{ clienteCodigo }}</h3>
        <button type="button" class="btn" @click="emit('cerrar')">Salir</button>
      </header>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="loading" class="loading">Cargando...</p>

      <div v-else class="body">
        <section class="lista">
          <div class="lista-header">
            <h4>Existentes</h4>
            <button v-if="puedeEscribir" type="button" class="btn btn-primary" @click="onNueva">Nuevo</button>
          </div>
          <table class="grid">
            <thead>
              <tr>
                <th>Nombre</th>
                <th>Departamento</th>
                <th>Telefono</th>
                <th>E-mail</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in items"
                :key="`${item.num}-${item.nroLin}`"
                :class="{ selected: seleccion?.num === item.num && seleccion?.nroLin === item.nroLin && !esNueva }"
                @click="seleccionar(item)"
              >
                <td>{{ item.nombre }}</td>
                <td>{{ item.departamento }}</td>
                <td>{{ item.telefono }}</td>
                <td>{{ item.email }}</td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="4">Sin contactos</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="formulario">
          <h4>{{ esNueva || !seleccion ? 'Nuevo contacto' : 'Editar contacto' }}</h4>
          <div class="fields">
            <label>
              Departamento
              <input v-model="form.departamento" maxlength="30" :disabled="!puedeEscribir" />
            </label>
            <label>
              Nombre
              <input v-model="form.nombre" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label class="span-2">
              E-mail
              <input v-model="form.email" type="email" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Telefono
              <input v-model="form.telefono" maxlength="15" :disabled="!puedeEscribir" />
            </label>
            <label class="span-2">
              Observaciones
              <input v-model="form.observaciones" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label class="check">
              <input v-model="form.facturas" type="checkbox" :disabled="!puedeEscribir" />
              <span>Facturas</span>
            </label>
            <label class="check">
              <input v-model="form.comercial" type="checkbox" :disabled="!puedeEscribir" />
              <span>Comercial</span>
            </label>
          </div>

          <div v-if="puedeEscribir" class="acciones">
            <button type="button" class="btn btn-primary" :disabled="!puedeGuardar" @click="onGuardar">
              Guardar
            </button>
            <button
              type="button"
              class="btn btn-danger"
              :disabled="!seleccion || esNueva || saving"
              @click="onEliminar"
            >
              Eliminar
            </button>
          </div>
        </section>
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
  width: min(820px, 100%);
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
  padding: 0.65rem 0.85rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}

.modal-header h3 {
  margin: 0;
  font-size: 0.95rem;
}

.body {
  display: grid;
  grid-template-columns: 1fr 1.15fr;
  gap: 0;
  overflow: auto;
  min-height: 0;
}

@media (max-width: 720px) {
  .body {
    grid-template-columns: 1fr;
  }
}

.lista,
.formulario {
  padding: 0.75rem 0.85rem;
}

.lista {
  border-right: 1px solid #e2e8f0;
  overflow: auto;
}

.lista-header,
.formulario h4 {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 0 0 0.5rem;
}

.lista-header h4,
.formulario h4 {
  margin: 0;
  font-size: 0.85rem;
}

.grid {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.grid th,
.grid td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.4rem;
  text-align: left;
}

.grid th {
  background: #f1f5f9;
}

.grid tbody tr {
  cursor: pointer;
}

.grid tbody tr:hover {
  background: #eff6ff;
}

.grid tbody tr.selected {
  background: #dbeafe;
}

.fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem 0.65rem;
}

.fields label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  color: #475569;
}

.fields label.span-2 {
  grid-column: span 2;
}

.fields label.check {
  flex-direction: row;
  align-items: center;
  gap: 0.45rem;
  margin-top: 0.35rem;
  font-size: 0.85rem;
  color: #0f172a;
}

.fields input[type='text'],
.fields input[type='email'],
.fields input:not([type]) {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.85rem;
  background: #fff;
  color: #0f172a;
}

.fields input:disabled {
  background: #f1f5f9;
  color: #64748b;
}

.fields input[type='checkbox'] {
  width: 1rem;
  height: 1rem;
}

.acciones {
  display: flex;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.btn {
  padding: 0.3rem 0.65rem;
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

.btn-primary {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}

.btn-danger {
  background: #fff;
  border-color: #dc2626;
  color: #dc2626;
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
