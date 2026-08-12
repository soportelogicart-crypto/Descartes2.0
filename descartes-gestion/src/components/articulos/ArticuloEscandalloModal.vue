<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import DecimalInput from '@/components/common/DecimalInput.vue'

type EscFila = { ingrediente: string; descripcion: string; cantidad: number }

const props = defineProps<{
  open: boolean
  codigo: string
  descripcion?: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const filas = ref<EscFila[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)

watch(
  () => [props.open, props.codigo] as const,
  async ([open, codigo]) => {
    if (open && codigo) await cargar()
  }
)

async function cargar() {
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.codigo)}/escandallo`
    )
    filas.value = (data.items ?? []).map((i: EscFila) => ({
      ingrediente: String(i.ingrediente ?? ''),
      descripcion: String(i.descripcion ?? ''),
      cantidad: Number(i.cantidad ?? 0),
    }))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el escandallo')
    filas.value = []
  } finally {
    loading.value = false
  }
}

function anadir() {
  filas.value.push({ ingrediente: '', descripcion: '', cantidad: 1 })
}

function quitar(index: number) {
  filas.value.splice(index, 1)
}

async function resolverDescripcion(index: number) {
  const fila = filas.value[index]
  if (!fila) return
  const codigo = fila.ingrediente.trim()
  if (!codigo) {
    fila.descripcion = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(codigo)}`)
    fila.descripcion = String(data.descripcion ?? '')
  } catch {
    fila.descripcion = '(no encontrado)'
  }
}

async function guardar() {
  if (props.readonly) return
  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const { data } = await api.put(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.codigo)}/escandallo`,
      { items: filas.value }
    )
    filas.value = (data.items ?? []).map((i: EscFila) => ({
      ingrediente: String(i.ingrediente ?? ''),
      descripcion: String(i.descripcion ?? ''),
      cantidad: Number(i.cantidad ?? 0),
    }))
    mensaje.value = 'Escandallo guardado'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el escandallo')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <div>
          <h3>Escandallo</h3>
          <p class="sub">{{ codigo }} — {{ descripcion }}</p>
        </div>
        <button type="button" class="close" @click="emit('cerrar')">×</button>
      </header>

      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
      <p v-else class="hint">Composicion del articulo (tabla Escandallos): ingredientes y cantidades.</p>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Ingrediente</th>
              <th>Descripcion</th>
              <th>Cantidad</th>
              <th v-if="!readonly"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td :colspan="readonly ? 3 : 4">Cargando...</td>
            </tr>
            <tr v-else-if="!filas.length">
              <td :colspan="readonly ? 3 : 4">Sin ingredientes</td>
            </tr>
            <tr v-for="(fila, index) in filas" v-else :key="index">
              <td>
                <input
                  v-model="fila.ingrediente"
                  type="text"
                  maxlength="18"
                  :readonly="readonly"
                  @blur="resolverDescripcion(index)"
                />
              </td>
              <td>
                <input :value="fila.descripcion" type="text" readonly />
              </td>
              <td>
                <DecimalInput v-model="fila.cantidad" :empty-as-null="false" :readonly="readonly" />
              </td>
              <td v-if="!readonly">
                <button type="button" class="btn-link" @click="quitar(index)">Quitar</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="modal-footer">
        <button v-if="!readonly" type="button" class="btn" :disabled="loading || saving" @click="anadir">
          Anadir
        </button>
        <div class="spacer"></div>
        <button v-if="!readonly" type="button" class="btn primary" :disabled="loading || saving" @click="guardar">
          Guardar
        </button>
        <button type="button" class="btn" @click="emit('cerrar')">Cerrar</button>
      </footer>
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
  max-height: 90vh;
  display: flex;
  flex-direction: column;
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: start;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
}

.modal-header h3 {
  margin: 0;
  font-size: 1rem;
}

.sub {
  margin: 0.2rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
}

.close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}

.hint,
.error,
.ok {
  margin: 0;
  padding: 0.5rem 1rem 0.25rem;
  font-size: 0.8rem;
}

.hint {
  color: #64748b;
}

.error {
  color: #b91c1c;
}

.ok {
  color: #047857;
}

.table-wrap {
  flex: 1;
  overflow: auto;
  padding: 0.5rem 1rem;
  min-height: 180px;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.3rem;
  text-align: left;
}

th {
  font-size: 0.75rem;
  color: #475569;
}

input {
  width: 100%;
  min-width: 0;
  padding: 0.25rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font-size: 0.85rem;
}

input:read-only {
  background: #e8edf2;
}

.btn-link {
  border: none;
  background: transparent;
  color: #b91c1c;
  cursor: pointer;
  font-size: 0.8rem;
}

.modal-footer {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid #e2e8f0;
}

.spacer {
  flex: 1;
}

.btn {
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  padding: 0.35rem 0.75rem;
  cursor: pointer;
  font-size: 0.85rem;
}

.btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
