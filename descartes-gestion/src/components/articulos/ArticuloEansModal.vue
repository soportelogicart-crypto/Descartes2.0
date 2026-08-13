<script setup lang="ts">
import { ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
import { eanCheckDigit } from '@/config/documentos-plantillas/barcode-ean'

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
const loaded = ref(false)
const dirty = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const filtro = ref('')
const mostrarBuscar = ref(false)
const asignarEan = ref('')

watch(
  () => [props.open, props.codigo] as const,
  async ([open, codigo]) => {
    if (open && codigo) {
      asignarEan.value = ''
      filtro.value = ''
      await cargar()
    } else if (!open) {
      loaded.value = false
      dirty.value = false
    }
  },
  { immediate: true }
)

async function cargar() {
  loading.value = true
  loaded.value = false
  dirty.value = false
  error.value = null
  mensaje.value = null
  try {
    const { data } = await api.get(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.codigo.trim())}/eans`
    )
    filas.value = (data.items ?? []).map((i: EanFila) => ({
      ean: normalizarEanVista(i.ean),
      tipo: String(i.tipo ?? ''),
      unidades: Number(i.unidades ?? 0),
    }))
    if (!filas.value.length) filas.value.push({ ean: '', tipo: '', unidades: 0 })
    loaded.value = true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron cargar los EAN')
    filas.value = [{ ean: '', tipo: '', unidades: 0 }]
    loaded.value = false
  } finally {
    loading.value = false
  }
}

/** Evita notación científica / floats en UI. */
function normalizarEanVista(raw: unknown): string {
  if (raw == null) return ''
  if (typeof raw === 'number' && Number.isFinite(raw)) {
    return Math.trunc(raw).toString()
  }
  let s = String(raw).trim()
  if (/e\+/i.test(s)) {
    const n = Number(s)
    if (Number.isFinite(n)) return Math.trunc(n).toString()
  }
  if (/^\d+\.0+$/.test(s)) s = s.split('.')[0] ?? s
  // Solo dígitos (escáner / pegado con espacios)
  if (/[\d]/.test(s) && !/^\d+$/.test(s)) {
    const digits = s.replace(/\D/g, '')
    if (digits.length >= 4) return digits
  }
  return s
}

function onEanInput(index: number) {
  dirty.value = true
  const fila = filas.value[index]
  if (fila) fila.ean = normalizarEanVista(fila.ean)
  const last = filas.value[filas.value.length - 1]
  if (last && last.ean.trim() !== '' && index === filas.value.length - 1) {
    filas.value.push({ ean: '', tipo: '', unidades: 0 })
  }
}

async function comprobarEanDisponible(ean: string): Promise<boolean> {
  const digits = normalizarEanVista(ean)
  if (!digits) return true
  try {
    const { data } = await api.get<{
      disponible: boolean
      codigoArticulo: string | null
      ean: string
    }>('/api/mantenimiento/articulos/ean-lookup', { params: { ean: digits } })
    if (!data.disponible && data.codigoArticulo && data.codigoArticulo !== props.codigo.trim()) {
      error.value = `El EAN ${data.ean} ya está asignado al artículo ${data.codigoArticulo}`
      return false
    }
    return true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo comprobar el EAN')
    return false
  }
}

async function onEanBlur(index: number) {
  if (props.readonly) return
  const ean = normalizarEanVista(filas.value[index]?.ean ?? '')
  if (filas.value[index]) filas.value[index].ean = ean
  if (!ean) return
  error.value = null
  await comprobarEanDisponible(ean)
}

/**
 * Persiste la lista de EAN del artículo.
 * No envía borrado total accidental si aún no se ha cargado del servidor.
 */
async function persistir(items: EanFila[], opts?: { allowEmpty?: boolean }): Promise<boolean> {
  if (props.readonly) return false
  if (!loaded.value && !opts?.allowEmpty) {
    error.value = 'Espere a que carguen los EAN antes de guardar'
    return false
  }

  saving.value = true
  error.value = null
  mensaje.value = null
  try {
    const limpios = items
      .map((i) => ({
        ean: normalizarEanVista(i.ean),
        tipo: String(i.tipo ?? ''),
        unidades: Number(i.unidades ?? 0),
      }))
      .filter((i) => i.ean !== '')

    if (limpios.length === 0 && !opts?.allowEmpty) {
      // Evitar DELETE de todos los EAN por lista vacía (p. ej. modal sin cargar).
      error.value = 'No hay EAN para guardar. Si quiere borrar todos, déjelo explícito.'
      return false
    }

    for (const fila of limpios) {
      const ok = await comprobarEanDisponible(fila.ean)
      if (!ok) return false
    }

    const { data } = await api.put(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.codigo.trim())}/eans`,
      { items: limpios }
    )
    filas.value = (data.items ?? []).map((i: EanFila) => ({
      ean: normalizarEanVista(i.ean),
      tipo: String(i.tipo ?? ''),
      unidades: Number(i.unidades ?? 0),
    }))
    filas.value.push({ ean: '', tipo: '', unidades: 0 })
    loaded.value = true
    dirty.value = false
    mensaje.value = limpios.length ? `Guardados ${limpios.length} EAN` : 'EAN eliminados'
    return true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron guardar los EAN')
    return false
  } finally {
    saving.value = false
  }
}

/** Asigna un EAN existente (escrito/escaneado) a este artículo. */
async function asignar() {
  if (props.readonly || saving.value || loading.value) return
  if (!loaded.value) {
    error.value = 'Espere a que carguen los EAN'
    return
  }
  const ean = normalizarEanVista(asignarEan.value)
  if (!ean) {
    error.value = 'Indique el EAN a asignar'
    return
  }
  if (!/^\d{4,18}$/.test(ean)) {
    error.value = 'EAN inválido (solo dígitos, 4–18)'
    return
  }
  error.value = null
  mensaje.value = null
  const ok = await comprobarEanDisponible(ean)
  if (!ok) return

  const actuales = filas.value
    .map((f) => ({ ...f, ean: normalizarEanVista(f.ean) }))
    .filter((f) => f.ean !== '')
  if (actuales.some((f) => f.ean === ean)) {
    mensaje.value = 'Ese EAN ya está en la lista'
    return
  }
  actuales.push({ ean, tipo: '', unidades: 0 })
  const saved = await persistir(actuales, { allowEmpty: false })
  if (saved) {
    asignarEan.value = ''
    mensaje.value = `EAN ${ean} asignado y guardado`
  }
}

async function generar() {
  if (props.readonly || saving.value || loading.value) return
  if (!loaded.value) {
    error.value = 'Espere a que carguen los EAN'
    return
  }
  const codigo = props.codigo.trim()
  const digits = codigo.replace(/\D/g, '')
  if (!digits) {
    error.value = 'El código de artículo no tiene dígitos para generar un EAN'
    return
  }

  // Solo EAN sintético 9710… (NO añadir el código de artículo como EAN).
  const body9 = digits.slice(-9).padStart(9, '0')
  const base12 = (`9710${body9}`).slice(0, 12)
  const candidate = base12 + String(eanCheckDigit(base12))

  const actuales = filas.value
    .map((f) => ({ ...f, ean: normalizarEanVista(f.ean) }))
    .filter((f) => f.ean !== '')
  if (actuales.some((f) => f.ean === candidate)) {
    mensaje.value = `Ya existe el EAN ${candidate}`
    return
  }
  actuales.push({ ean: candidate, tipo: '', unidades: 0 })
  error.value = null
  const saved = await persistir(actuales, { allowEmpty: false })
  if (saved) {
    mensaje.value = `Generado y guardado EAN ${candidate}`
  }
}

async function guardarYSalir() {
  if (!props.readonly) {
    if (!loaded.value) {
      error.value = 'Espere a que carguen los EAN'
      return
    }
    if (dirty.value) {
      const ok = await persistir(filas.value, { allowEmpty: false })
      if (!ok) return
    }
  }
  emit('cerrar')
}

async function onCerrarOverlay() {
  if (saving.value || loading.value) return
  if (!props.readonly && dirty.value && loaded.value) {
    const ok = await persistir(filas.value, { allowEmpty: false })
    if (!ok) return
  }
  emit('cerrar')
}
</script>

<template>
  <div v-if="open" class="overlay" @click.self="onCerrarOverlay">
    <div class="modal">
      <header class="modal-header">
        <div class="tools">
          <button type="button" class="tool" title="Buscar" @click="mostrarBuscar = !mostrarBuscar">
            Buscar
          </button>
          <button
            type="button"
            class="tool"
            title="Generar EAN 9710… y guardar"
            :disabled="readonly || saving || loading || !loaded"
            @click="generar"
          >
            Generar
          </button>
          <button
            type="button"
            class="tool primary"
            title="Guardar y salir"
            :disabled="saving || loading"
            @click="guardarYSalir"
          >
            {{ saving ? 'Guardando…' : 'Salir' }}
          </button>
        </div>
      </header>

      <p class="title">Eans</p>
      <p class="sub">{{ codigo }} — {{ descripcion }}</p>
      <p v-if="error" class="error">{{ error }}</p>
      <p v-else-if="mensaje" class="ok">{{ mensaje }}</p>
      <p v-else-if="dirty" class="aviso">Cambios sin guardar</p>

      <div v-if="!readonly" class="asignar">
        <label>Asignar EAN</label>
        <div class="asignar-row">
          <input
            v-model="asignarEan"
            type="text"
            maxlength="18"
            placeholder="EAN / código de barras"
            :disabled="saving || loading || !loaded"
            @keydown.enter.prevent="asignar"
          />
          <button
            type="button"
            class="tool"
            :disabled="saving || loading || !loaded || !asignarEan.trim()"
            @click="asignar"
          >
            Asignar
          </button>
        </div>
      </div>

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
            <tr
              v-for="(fila, index) in filas"
              v-else
              :key="index"
              v-show="!filtro || fila.ean.includes(filtro)"
            >
              <td>
                <input
                  v-model="fila.ean"
                  type="text"
                  maxlength="18"
                  :readonly="readonly"
                  :placeholder="index === filas.length - 1 ? '*' : ''"
                  @input="onEanInput(index)"
                  @blur="onEanBlur(index)"
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
  width: min(320px, 92vw);
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

.tool.primary {
  background: #dbeafe;
  border-color: #93c5fd;
  font-weight: 600;
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
.ok,
.aviso {
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

.aviso {
  color: #b45309;
}

.asignar {
  padding: 0.35rem 0.5rem 0.15rem;
}

.asignar label {
  display: block;
  font-size: 0.7rem;
  color: #475569;
  margin-bottom: 0.2rem;
}

.asignar-row {
  display: flex;
  gap: 0.3rem;
}

.asignar-row input {
  flex: 1;
  min-width: 0;
  box-sizing: border-box;
  padding: 0.25rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
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
