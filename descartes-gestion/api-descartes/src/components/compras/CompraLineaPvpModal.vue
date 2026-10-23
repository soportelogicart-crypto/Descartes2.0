<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { api } from '@/api/client'
import DecimalInput from '@/components/common/DecimalInput.vue'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  open: boolean
  codigo: string
  descripcion?: string | null
  costeBase: number
  costeConTransporte: number
}>()

const emit = defineEmits<{
  cerrar: []
  guardado: [codigo: string]
}>()

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const pvps = ref<number[]>(Array.from({ length: 9 }, () => 0))

const costeRef = computed(() => props.costeConTransporte > 0 ? props.costeConTransporte : props.costeBase)

function margenDesdePrecio(precio: number): number {
  const c = costeRef.value
  if (precio <= 0 || c <= 0) return 0
  return Math.round(((precio - c) / precio) * 10000) / 100
}

function precioDesdeMargen(margen: number): number {
  const c = costeRef.value
  if (c <= 0) return 0
  const m = Math.max(0, Math.min(99.99, margen))
  return Math.round((c / (1 - m / 100)) * 100) / 100
}

async function cargar() {
  if (!props.codigo.trim()) return
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get<Record<string, unknown>>(
      `/api/mantenimiento/articulos/${encodeURIComponent(props.codigo.trim())}`
    )
    pvps.value = Array.from({ length: 9 }, (_, i) => {
      const k = `precioVen${i + 1}`
      return Number(data[k] ?? (i === 0 ? data.precioVenta : 0) ?? 0) || 0
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar el artículo')
  } finally {
    loading.value = false
  }
}

async function guardar() {
  if (!props.codigo.trim()) return
  saving.value = true
  error.value = null
  try {
    const body: Record<string, number> = {}
    for (let i = 0; i < 9; i++) {
      body[`precioVen${i + 1}`] = Number(pvps.value[i]) || 0
    }
    if (body.precioVen1 > 0) body.precioVenta = body.precioVen1
    await api.put(`/api/mantenimiento/articulos/${encodeURIComponent(props.codigo.trim())}`, body)
    emit('guardado', props.codigo.trim())
    emit('cerrar')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el PVP')
  } finally {
    saving.value = false
  }
}

watch(
  () => [props.open, props.codigo] as const,
  ([open]) => {
    if (open) void cargar()
  }
)
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal-pvp">
        <header>
          <h3>Modificar precio de venta</h3>
          <p class="sub">
            {{ codigo }} — {{ descripcion || 'Artículo' }}
          </p>
        </header>

        <div v-if="loading" class="hint">Cargando…</div>
        <p v-if="error" class="err">{{ error }}</p>

        <div v-if="!loading" class="costes">
          <div><span>Coste</span><strong>{{ costeBase.toFixed(4) }}</strong></div>
          <div><span>Coste + transporte</span><strong>{{ costeConTransporte.toFixed(4) }}</strong></div>
        </div>

        <table v-if="!loading" class="pvp-tabla">
          <thead>
            <tr>
              <th>Tarifa</th>
              <th class="num">Margen %</th>
              <th class="num">Precio</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(_, i) in pvps" :key="i">
              <td>PVP {{ i + 1 }}</td>
              <td class="num">
                <DecimalInput
                  :model-value="margenDesdePrecio(pvps[i])"
                  class="num-in"
                  :empty-as-null="false"
                  @update:model-value="(v) => (pvps[i] = precioDesdeMargen(Number(v) || 0))"
                />
              </td>
              <td class="num">
                <DecimalInput v-model="pvps[i]" class="num-in" :empty-as-null="false" />
              </td>
            </tr>
          </tbody>
        </table>

        <footer>
          <button type="button" @click="emit('cerrar')">Cancelar</button>
          <button type="button" class="primary" :disabled="saving || loading" @click="guardar">
            {{ saving ? 'Guardando…' : 'Guardar PVP' }}
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
  z-index: 6000;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal-pvp {
  background: #fff;
  border-radius: 10px;
  width: min(32rem, 96vw);
  max-height: 90vh;
  overflow: auto;
  padding: 1rem 1.1rem;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.18);
}
header h3 {
  margin: 0 0 0.25rem;
}
.sub {
  margin: 0 0 0.75rem;
  color: #64748b;
  font-size: 0.88rem;
}
.costes {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
}
.costes strong {
  display: block;
  color: #b91c1c;
}
.pvp-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
  margin-bottom: 0.75rem;
}
.pvp-tabla th,
.pvp-tabla td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.35rem;
}
.pvp-tabla th {
  background: #f8fafc;
  text-align: left;
}
.num {
  text-align: right;
}
.num-in {
  width: 5.5rem;
  text-align: right;
}
footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
footer button {
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  background: #fff;
  cursor: pointer;
}
footer button.primary {
  background: #1d4ed8;
  border-color: #1d4ed8;
  color: #fff;
}
.err {
  color: #b91c1c;
  font-size: 0.85rem;
}
.hint {
  color: #64748b;
  font-size: 0.85rem;
}
</style>
