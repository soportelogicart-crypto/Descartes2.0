<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { PedidoProveedorDetalle } from '@/types/compras'
import DecimalInput from '@/components/common/DecimalInput.vue'

type LineaRecepcion = {
  numLin: number
  articulo: string
  descripcion: string
  cantidadPed: number
  cantidadSer: number
  pendiente: number
  recibir: number
}

const props = defineProps<{
  open: boolean
  pedido: PedidoProveedorDetalle | null
  loading?: boolean
}>()

const emit = defineEmits<{
  confirmar: [
    payload: {
      fechaAlbaran: string
      suAlbaran: string
      almacen: number | null
      lineas: { numLin: number; cantidad: number }[]
    },
  ]
  cancelar: []
}>()

const fechaAlbaran = ref(new Date().toISOString().slice(0, 10))
const suAlbaran = ref('')
const almacen = ref<number | null>(null)
const lineas = ref<LineaRecepcion[]>([])
const errorLocal = ref<string | null>(null)
const ignoreOverlayClick = ref(false)

const hayCantidades = computed(() =>
  lineas.value.some((l) => Number(l.recibir) > 0.0000001)
)

function initFromPedido() {
  const p = props.pedido
  if (!p) {
    lineas.value = []
    return
  }
  fechaAlbaran.value = new Date().toISOString().slice(0, 10)
  suAlbaran.value = ''
  almacen.value = p.almacen ?? null
  errorLocal.value = null
  lineas.value = (p.lineas ?? [])
    .filter((l) => (l.articulo || '').trim() !== '')
    .map((l) => {
      const ped = Number(l.cantidadPed ?? 0)
      const ser = Number(l.cantidadSer ?? 0)
      const pendiente = Math.max(0, ped - ser)
      return {
        numLin: Number(l.numLin ?? 0),
        articulo: l.articulo || '',
        descripcion: l.descripcion || '',
        cantidadPed: ped,
        cantidadSer: ser,
        pendiente,
        recibir: pendiente,
      }
    })
    .filter((l) => l.pendiente > 0.0000001 && l.numLin > 0)
}

watch(
  () => props.open,
  async (abierto) => {
    if (!abierto) {
      ignoreOverlayClick.value = false
      return
    }
    initFromPedido()
    ignoreOverlayClick.value = true
    await nextTick()
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        ignoreOverlayClick.value = false
      })
    })
  }
)

function todoPendiente() {
  for (const l of lineas.value) {
    l.recibir = l.pendiente
  }
}

function limpiar() {
  for (const l of lineas.value) {
    l.recibir = 0
  }
}

function onOverlayClick() {
  if (ignoreOverlayClick.value || props.loading) return
  emit('cancelar')
}

function confirmar() {
  errorLocal.value = null
  const out: { numLin: number; cantidad: number }[] = []
  for (const l of lineas.value) {
    const q = Number(l.recibir) || 0
    if (q <= 0.0000001) continue
    if (q > l.pendiente + 0.0000001) {
      errorLocal.value = `Línea ${l.numLin}: recibir (${q}) supera pendiente (${l.pendiente})`
      return
    }
    out.push({ numLin: l.numLin, cantidad: q })
  }
  if (out.length === 0) {
    errorLocal.value = 'Indique al menos una cantidad a recibir'
    return
  }
  emit('confirmar', {
    fechaAlbaran: fechaAlbaran.value,
    suAlbaran: suAlbaran.value.trim(),
    almacen: almacen.value,
    lineas: out,
  })
}

function fmt(n: number) {
  return Number(n ?? 0).toFixed(2)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-show="open"
      class="overlay"
      role="dialog"
      aria-modal="true"
      @click.self="onOverlayClick"
    >
      <div class="modal">
        <header class="modal-header">
          <h3>Recibir mercancía</h3>
          <p v-if="pedido" class="sub">
            Pedido {{ pedido.empresa }}-{{ pedido.pedido }}
            <span v-if="pedido.proveedor"> · Prov. {{ pedido.proveedor }}</span>
          </p>
        </header>

        <div class="cab">
          <label>
            Fecha albarán
            <input v-model="fechaAlbaran" type="date" :disabled="loading" />
          </label>
          <label>
            Su albarán
            <input v-model="suAlbaran" :disabled="loading" placeholder="Nº proveedor" />
          </label>
          <label>
            Almacén
            <input
              :value="almacen ?? ''"
              type="number"
              :disabled="loading"
              @input="almacen = ($event.target as HTMLInputElement).value
                ? Number(($event.target as HTMLInputElement).value)
                : null"
            />
          </label>
        </div>

        <div class="acciones-lineas">
          <button type="button" class="btn-link" :disabled="loading" @click="todoPendiente">
            Todo el pendiente
          </button>
          <button type="button" class="btn-link" :disabled="loading" @click="limpiar">
            Limpiar
          </button>
        </div>

        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-n">#</th>
                <th class="col-art">Artículo</th>
                <th>Descripción</th>
                <th class="num">Pedida</th>
                <th class="num">Servida</th>
                <th class="num">Pendiente</th>
                <th class="num col-rec">A recibir</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in lineas" :key="l.numLin">
                <td class="col-n">{{ l.numLin }}</td>
                <td class="col-art">{{ l.articulo }}</td>
                <td>{{ l.descripcion || '—' }}</td>
                <td class="num">{{ fmt(l.cantidadPed) }}</td>
                <td class="num">{{ fmt(l.cantidadSer) }}</td>
                <td class="num">{{ fmt(l.pendiente) }}</td>
                <td class="num col-rec">
                  <DecimalInput v-model="l.recibir" :empty-as-null="false" :disabled="loading" />
                </td>
              </tr>
              <tr v-if="lineas.length === 0">
                <td colspan="7">No hay líneas con cantidad pendiente</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-if="errorLocal" class="error">{{ errorLocal }}</p>

        <footer class="modal-footer">
          <button type="button" class="btn-cancel" :disabled="loading" @click="emit('cancelar')">
            Cancelar
          </button>
          <button
            type="button"
            class="btn-ok"
            :disabled="loading || !hayCantidades"
            @click="confirmar"
          >
            {{ loading ? 'Recibiendo…' : 'Crear albarán' }}
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
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1200;
  padding: 1rem;
}
.modal {
  width: min(52rem, 100%);
  max-height: min(90vh, 40rem);
  overflow: auto;
  background: #fff;
  border-radius: 8px;
  border: 1px solid #94a3b8;
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.2);
  padding: 1rem 1.1rem 0.9rem;
}
.modal-header {
  margin-bottom: 0.75rem;
}
.modal-header h3 {
  margin: 0 0 0.2rem;
  font-size: 1.05rem;
  color: #0f172a;
}
.sub {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}
.cab {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0.5rem 0.75rem;
  margin-bottom: 0.55rem;
}
.cab label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.72rem;
  color: #475569;
}
.cab input {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
}
.acciones-lineas {
  display: flex;
  gap: 0.75rem;
  margin-bottom: 0.4rem;
}
.btn-link {
  border: none;
  background: none;
  color: #0369a1;
  cursor: pointer;
  font: inherit;
  font-size: 0.8rem;
  text-decoration: underline;
  padding: 0;
}
.btn-link:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: 18rem;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.35rem;
  text-align: left;
  vertical-align: middle;
}
th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-n {
  width: 2.2rem;
}
.col-art {
  width: 8rem;
}
.col-rec {
  width: 6rem;
}
.error {
  color: #b91c1c;
  margin: 0.5rem 0 0;
  font-size: 0.85rem;
}
.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.85rem;
  padding-top: 0.65rem;
  border-top: 1px solid #e2e8f0;
}
.btn-cancel,
.btn-ok {
  padding: 0.4rem 0.85rem;
  border-radius: 6px;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.btn-cancel {
  border: 1px solid #94a3b8;
  background: #fff;
  color: #334155;
}
.btn-ok {
  border: 1px solid #b45309;
  background: #f59e0b;
  color: #1c1917;
}
.btn-ok:disabled,
.btn-cancel:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

@media (max-width: 700px) {
  .cab {
    grid-template-columns: 1fr;
  }
}
</style>
