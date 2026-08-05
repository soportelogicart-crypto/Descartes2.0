<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { lookupCodigoPostal } from '@/composables/useCodigoPostalLookup'

export type ClienteDireccion = {
  cliente: string
  tipo: string
  nroLin: number
  contacto: string | null
  direccion: string | null
  poblacion: string | null
  codigoPostal: string | null
  provincia: string | null
  pais: string | null
  email: string | null
  telefono1: string | null
  telefono2: string | null
  fax: string | null
  lUpdate: string | null
  departamento: string | null
  portes: string | null
}

const TIPO_OPCIONES = [
  { value: 'E', label: 'Direccion de envio' },
  { value: 'F', label: 'Direccion de envio facturas' },
] as const

const PORTES_OPCIONES = [
  { value: '', label: '--' },
  { value: 'P', label: 'Pagados' },
  { value: 'D', label: 'Debidos' },
] as const

const props = defineProps<{
  open: boolean
  clienteCodigo: string
  /** Si true, oculta acciones de guardado (los inputs siguen editables solo con permiso). */
  puedeEditar?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const items = ref<ClienteDireccion[]>([])
const seleccion = ref<{ tipo: string; nroLin: number } | null>(null)
const esNueva = ref(false)

const form = reactive({
  tipo: 'E',
  departamento: '',
  direccion: '',
  codigoPostal: '',
  poblacion: '',
  provincia: '',
  pais: '',
  telefono1: '',
  telefono2: '',
  email: '',
  contacto: '',
  portes: '',
})

const tituloTipo = (tipo: string) =>
  TIPO_OPCIONES.find((o) => o.value === tipo)?.label ?? tipo

const puedeEscribir = computed(() => props.puedeEditar !== false)
const puedeGuardar = computed(() => puedeEscribir.value && !saving.value && !!props.clienteCodigo)
const poblacionesSugeridas = ref<string[]>([])
let cpLookupSeq = 0

watch(
  () => props.open,
  async (abierto) => {
    if (abierto) await cargar()
  }
)

async function onCodigoPostalChange() {
  if (!puedeEscribir.value) return
  const cp = form.codigoPostal.trim()
  if (cp.length < 4) {
    poblacionesSugeridas.value = []
    return
  }
  const seq = ++cpLookupSeq
  try {
    const data = await lookupCodigoPostal(cp)
    if (seq !== cpLookupSeq || !data) return
    poblacionesSugeridas.value = data.poblaciones
    if (data.poblacion) form.poblacion = data.poblacion
    if (data.provincia) form.provincia = data.provincia
  } catch {
    // Silencioso: el usuario puede rellenar a mano
  }
}

function vaciarFormulario() {
  form.tipo = 'E'
  form.departamento = ''
  form.direccion = ''
  form.codigoPostal = ''
  form.poblacion = ''
  form.provincia = ''
  form.pais = ''
  form.telefono1 = ''
  form.telefono2 = ''
  form.email = ''
  form.contacto = ''
  form.portes = ''
  poblacionesSugeridas.value = []
}

function cargarEnFormulario(item: ClienteDireccion) {
  form.tipo = item.tipo || 'E'
  form.departamento = String(item.departamento ?? '')
  form.direccion = String(item.direccion ?? '')
  form.codigoPostal = String(item.codigoPostal ?? '')
  form.poblacion = String(item.poblacion ?? '')
  form.provincia = String(item.provincia ?? '')
  form.pais = String(item.pais ?? '')
  form.telefono1 = String(item.telefono1 ?? '')
  form.telefono2 = String(item.telefono2 ?? '')
  form.email = String(item.email ?? '')
  form.contacto = String(item.contacto ?? '')
  form.portes = String(item.portes ?? '')
}

function payloadFormulario() {
  return {
    tipo: form.tipo,
    departamento: form.departamento,
    direccion: form.direccion,
    codigoPostal: form.codigoPostal,
    poblacion: form.poblacion,
    provincia: form.provincia,
    pais: form.pais,
    telefono1: form.telefono1,
    telefono2: form.telefono2,
    email: form.email,
    contacto: form.contacto,
    portes: form.portes || null,
  }
}

async function cargar(seleccionarPrimera = true) {
  loading.value = true
  error.value = null
  seleccion.value = null
  esNueva.value = false
  vaciarFormulario()
  try {
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/direcciones`)
    items.value = data.items ?? []
    if (seleccionarPrimera && items.value.length > 0) {
      seleccionar(items.value[0])
    } else {
      esNueva.value = true
      vaciarFormulario()
    }
  } catch (e) {
    error.value = extractApiError(e, 'Error al cargar direcciones')
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
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/direcciones`)
    items.value = data.items ?? []
  } catch (e) {
    error.value = extractApiError(e, 'Error al cargar direcciones')
  }
  seleccion.value = null
  esNueva.value = true
  vaciarFormulario()
}

function seleccionar(item: ClienteDireccion) {
  esNueva.value = false
  seleccion.value = { tipo: item.tipo, nroLin: item.nroLin }
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
        `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/direcciones`,
        body
      )
    } else {
      const { tipo, nroLin } = seleccion.value
      await api.put(
        `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/direcciones/${encodeURIComponent(tipo)}/${nroLin}`,
        body
      )
    }
    await refrescarListaYLimpiar()
  } catch (e) {
    error.value = extractApiError(e, 'Error al guardar la direccion')
  } finally {
    saving.value = false
  }
}

async function onEliminar() {
  if (!puedeEscribir.value || !seleccion.value || esNueva.value) return
  if (!confirm('¿Eliminar esta direccion?')) return
  saving.value = true
  error.value = null
  try {
    const { tipo, nroLin } = seleccion.value
    await api.delete(
      `/api/mantenimiento/clientes/${encodeURIComponent(props.clienteCodigo)}/direcciones/${encodeURIComponent(tipo)}/${nroLin}`
    )
    await refrescarListaYLimpiar()
  } catch (e) {
    error.value = extractApiError(e, 'Error al eliminar la direccion')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="open" class="overlay" role="dialog" aria-modal="true" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <h3>Direcciones — {{ clienteCodigo }}</h3>
        <button type="button" class="btn" @click="emit('cerrar')">Salir</button>
      </header>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-if="loading" class="loading">Cargando...</p>

      <div v-else class="body">
        <section class="lista">
          <div class="lista-header">
            <h4>Existentes</h4>
            <button v-if="puedeEscribir" type="button" class="btn btn-primary" @click="onNueva">Nueva</button>
          </div>
          <table class="grid">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Direccion</th>
                <th>Poblacion</th>
                <th>CP</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in items"
                :key="`${item.tipo}-${item.nroLin}`"
                :class="{ selected: seleccion?.tipo === item.tipo && seleccion?.nroLin === item.nroLin && !esNueva }"
                @click="seleccionar(item)"
              >
                <td>{{ tituloTipo(item.tipo) }}</td>
                <td>{{ item.direccion }}</td>
                <td>{{ item.poblacion }}</td>
                <td>{{ item.codigoPostal }}</td>
              </tr>
              <tr v-if="items.length === 0">
                <td colspan="4">Sin direcciones</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="formulario">
          <h4>{{ esNueva || !seleccion ? 'Nueva direccion' : 'Editar direccion' }}</h4>
          <div class="fields">
            <label>
              Tipo
              <select v-model="form.tipo" :disabled="!puedeEscribir">
                <option v-for="opt in TIPO_OPCIONES" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
            </label>
            <label>
              Departamento
              <input v-model="form.departamento" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label class="span-2">
              Direccion
              <input v-model="form.direccion" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Codigo Postal
              <input
                v-model="form.codigoPostal"
                maxlength="8"
                :disabled="!puedeEscribir"
                @change="onCodigoPostalChange"
                @blur="onCodigoPostalChange"
              />
            </label>
            <label>
              Poblacion
              <input
                v-model="form.poblacion"
                maxlength="50"
                :disabled="!puedeEscribir"
                list="cp-poblaciones"
              />
              <datalist id="cp-poblaciones">
                <option v-for="p in poblacionesSugeridas" :key="p" :value="p" />
              </datalist>
            </label>
            <label>
              Provincia
              <input v-model="form.provincia" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Pais
              <input v-model="form.pais" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Telefono1
              <input v-model="form.telefono1" maxlength="15" :disabled="!puedeEscribir" />
            </label>
            <label>
              Telefono2
              <input v-model="form.telefono2" maxlength="15" :disabled="!puedeEscribir" />
            </label>
            <label>
              Email
              <input v-model="form.email" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Contacto
              <input v-model="form.contacto" maxlength="50" :disabled="!puedeEscribir" />
            </label>
            <label>
              Portes
              <select v-model="form.portes" :disabled="!puedeEscribir">
                <option v-for="opt in PORTES_OPCIONES" :key="opt.value" :value="opt.value">
                  {{ opt.label }}
                </option>
              </select>
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
  width: min(860px, 100%);
  max-height: min(90vh, 720px);
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
  grid-template-columns: 1fr 1.2fr;
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

.fields input,
.fields select {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.85rem;
  background: #fff;
  color: #0f172a;
}

.fields input:disabled,
.fields select:disabled {
  background: #f1f5f9;
  color: #64748b;
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
