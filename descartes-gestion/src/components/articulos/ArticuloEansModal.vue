<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'

type EanFila = { ean: string; tipo: string; unidades: number }

const props = defineProps<{
  open: boolean
  codigo: string
  descripcion?: string
  readonly?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
}>()

const filas = ref<EanFila[]>([])
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const filtro = ref('')
const mostrarBuscar = ref(false)

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
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(props.codigo)}/eans`)
    filas.value = (data.items ?? []).map((i: EanFila) => ({
      ean: String(i.ean ?? ''),
      tipo: String(i.tipo ?? ''),
      unidades: Number(i.unidades ?? 0),
    }))
    if (!filas.value.length) filas.value.push({ ean: '', tipo: '', unidades: 0 })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar los EAN')
    filas.value = [{ ean: '', tipo: '', unidades: 0 }]
  } finally {
    loading.value = false
  }
}

function filasVisibles() {
  const q = filtro.value.trim()
  if (!q) return filas.value
  return filas.value.filter((f) => f.ean.includes(q))
}

function onEanInput(index: number) {
  // Si editan la ultima fila vacia y tiene valor, anadir otra *
  const last = filas.value[filas.value.length - 1]
  if (last && last.ean.trim() !== '' && index === filas.value.length - 1) {
    filas.value.push({ ean: '', tipo: '', unidades: 0 })
  }
}

async function persistir(items: EanFila[]) {
  saving.value = true
  error.value = null
  try {
    const { data } = await api.put(`/api/mantenimiento/articulos/${encodeURIComponent(props.codigo)}/eans`, {
      items: items.filter((i) => i.ean.trim() !== ''),
    })
    filas.value = (data.items ?? []).map((i: EanFila) => ({
      ean: String(i.ean ?? ''),
      tipo: String(i.tipo ?? ''),
      unidades: Number(i.unidades ?? 0),
    }))
    filas.value.push({ ean: '', tipo: '', unidades: 0 })
    mensaje.value = 'EAN guardados'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron guardar los EAN')
  } finally {
    saving.value = false
  }
}

async function generar() {
  if (props.readonly) return
  const codigo = props.codigo.trim()
  const digits = codigo.replace(/\D/g, '')
  const actuales = filas.value.filter((f) => f.ean.trim() !== '')
  const set = new Set(actuales.map((f) => f.ean))

  // 1) Codigo articulo como EAN (como legacy muestra 5283)
  if (digits && !set.has(digits)) {
    actuales.push({ ean: digits, tipo: '', unidades: 0 })
    set.add(digits)
  }

  // 2) EAN-13 simple tipo 9710 + codigo relleno (ajustable)
  if (digits) {
    const body = ('000000000' + digits).slice(-9)
    const candidate = `9710${body}`.slice(0, 13)
    if (!set.has(candidate)) {
      actuales.push({ ean: candidate, tipo: '', unidades: 0 })
    }
  }

  await persistir(actuales)
}

async function guardarYSalir() {
  if (!props.readonly) {
    await persistir(filas.value)
    if (error.value) return
  }
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="emit('cerrar')">
    <div class="modal">
      <header class="modal-header">
        <div class="tools">
          <button type="button" class="tool" title="Buscar" @click="mostrarBuscar = !mostrarBuscar">
            Buscar
          </button>
          <button
            type="button"
            class="tool"
            title="Generar"
            :disabled="readonly || saving || loading"
            @click="generar"
          >
            Generar
          </button>
          <button type="button" class="tool" title="Salir" :disabled="saving" @click="guardarYSalir">
            Salir
          </button>
        </div>
      </header>

      <p class="title">Eans</p>
      <p class="sub">{{ codigo }} — {{ descripcion }}</p>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>

      <div v-if="mostrarBuscar" class="buscar">
        <input v-model="filtro" type="search" placeholder="Filtrar EAN..." />
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Eans</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="loading">
              <td>Cargando...</td>
            </tr>
            <tr v-for="(fila, index) in filas" v-else :key="index" v-show="!filtro || fila.ean.includes(filtro)">
              <td>
                <input
                  v-model="fila.ean"
                  type="text"
                  maxlength="18"
                  :readonly="readonly"
                  :placeholder="index === filas.length - 1 ? '*' : ''"
                  @input="onEanInput(index)"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<style scoped>
.overlay {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.35);
  display: grid;
  place-items: center;
  z-index: 65;
  padding: 1rem;
}

.modal {
  width: min(280px, 92vw);
  max-height: 80vh;
  display: flex;
  flex-direction: column;
  background: #e8edf2;
  border: 1px solid #64748b;
  border-radius: 4px;
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
}

.modal-header {
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid #94a3b8;
  background: #f1f5f9;
}

.tools {
  display: flex;
  gap: 0.3rem;
  justify-content: center;
}

.tool {
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  padding: 0.25rem 0.45rem;
  font-size: 0.72rem;
  cursor: pointer;
}

.tool:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.title {
  margin: 0.35rem 0 0;
  text-align: center;
  font-weight: 700;
  font-size: 0.95rem;
}

.sub,
.error,
.ok {
  margin: 0;
  padding: 0.15rem 0.5rem;
  font-size: 0.72rem;
  text-align: center;
}

.sub {
  color: #64748b;
}

.error {
  color: #b91c1c;
}

.ok {
  color: #047857;
}

.buscar {
  padding: 0.25rem 0.5rem;
}

.buscar input {
  width: 100%;
  box-sizing: border-box;
  padding: 0.25rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
}

.table-wrap {
  flex: 1;
  overflow: auto;
  margin: 0.25rem 0.5rem 0.6rem;
  background: #fff;
  border: 1px solid #94a3b8;
  min-height: 220px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th {
  background: #f8fafc;
  border-bottom: 1px solid #cbd5e1;
  font-size: 0.75rem;
  padding: 0.25rem;
  text-align: left;
}

td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0;
}

td input {
  width: 100%;
  border: none;
  padding: 0.3rem 0.35rem;
  font-size: 0.85rem;
  box-sizing: border-box;
}

td input:read-only {
  background: #f1f5f9;
}
</style>
