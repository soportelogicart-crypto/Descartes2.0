<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { LineaSobreEncargo, SeleccionEtiquetaEncargo } from '@/composables/useSeleccionEtiquetasEncargo'

const props = defineProps<{ lineas: LineaSobreEncargo[] | null }>()
const emit = defineEmits<{
  confirmar: [seleccion: SeleccionEtiquetaEncargo[]]
  cancelar: []
}>()

const cantidades = ref<Record<number, number>>({})

watch(
  () => props.lineas,
  (lineas) => {
    cantidades.value = Object.fromEntries((lineas ?? []).map((l) => [l.nroLin, l.sobres]))
  },
  { immediate: true }
)

function fijar(l: LineaSobreEncargo, valor: unknown) {
  const n = Math.floor(Number(valor))
  cantidades.value[l.nroLin] = Number.isFinite(n) ? Math.min(l.sobres, Math.max(0, n)) : l.sobres
}

function todas() {
  for (const l of props.lineas ?? []) cantidades.value[l.nroLin] = l.sobres
}

function unaPorArticulo() {
  for (const l of props.lineas ?? []) cantidades.value[l.nroLin] = 1
}

const total = computed(() =>
  (props.lineas ?? []).reduce((s, l) => s + (cantidades.value[l.nroLin] ?? 0), 0)
)

function imprimir() {
  if (total.value < 1) return
  emit(
    'confirmar',
    (props.lineas ?? []).map((l) => ({ nroLin: l.nroLin, cantidad: cantidades.value[l.nroLin] ?? 0 }))
  )
}

const num = (n: number) => n.toLocaleString('es-ES', { maximumFractionDigits: 2 })
</script>

<template>
  <Teleport to="body">
  <div v-if="lineas" class="fondo">
    <div class="dialogo" role="dialog" aria-modal="true" aria-label="Etiquetas del encargo">
      <h3>Etiquetas del encargo</h3>
      <p class="ayuda">
        Elija cuántas etiquetas se imprimen de cada artículo. Con cantidad mayor que 1 puede sacar una sola.
      </p>
      <table>
        <thead>
          <tr>
            <th>Artículo</th>
            <th class="num">Cantidad</th>
            <th class="num">Sobres</th>
            <th class="num">Etiquetas</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in lineas" :key="l.nroLin">
            <td>
              <span class="cod">{{ l.articulo }}</span>
              {{ l.producto }}
            </td>
            <td class="num">{{ num(l.cantidad) }}</td>
            <td class="num">{{ l.sobres }}</td>
            <td class="num">
              <input
                type="number"
                min="0"
                :max="l.sobres"
                step="1"
                :value="cantidades[l.nroLin]"
                @input="fijar(l, ($event.target as HTMLInputElement).value)"
              />
            </td>
          </tr>
        </tbody>
      </table>
      <div class="rapidos">
        <button type="button" class="btn btn-secundario" @click="todas">Todas</button>
        <button type="button" class="btn btn-secundario" @click="unaPorArticulo">Una por artículo</button>
        <strong class="total">{{ total }} etiqueta(s)</strong>
      </div>
      <div class="acciones">
        <button type="button" class="btn btn-secundario" @click="emit('cancelar')">Cancelar</button>
        <button type="button" class="btn btn-principal" :disabled="total < 1" @click="imprimir">Imprimir</button>
      </div>
    </div>
  </div>
  </Teleport>
</template>

<style scoped>
.fondo {
  position: fixed;
  inset: 0;
  /* Por encima de VentaPostFinalizacionModal (5200), desde donde también se imprimen etiquetas. */
  z-index: 5300;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgb(15 23 42 / 45%);
}
.dialogo {
  width: min(560px, 94vw);
  max-height: 88vh;
  overflow: auto;
  padding: 1rem 1.15rem;
  border-radius: 10px;
  background: #fff;
  color: #1e293b;
  box-shadow: 0 10px 30px rgb(15 23 42 / 25%);
}
h3 { margin: 0 0 0.3rem; font-size: 1.05rem; }
.ayuda { margin: 0 0 0.7rem; color: #64748b; font-size: 0.85rem; }
table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
th, td { padding: 0.35rem 0.4rem; border-bottom: 1px solid #e2e8f0; text-align: left; }
th.num, td.num { text-align: right; }
.cod { margin-right: 0.35rem; font-weight: 600; color: #475569; }
input[type='number'] {
  width: 4.2rem;
  padding: 0.2rem 0.35rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  text-align: right;
  font: inherit;
}
.rapidos { display: flex; align-items: center; gap: 0.5rem; margin: 0.7rem 0; }
.total { margin-left: auto; }
.acciones { display: flex; justify-content: flex-end; gap: 0.5rem; }
.btn {
  padding: 0.42rem 0.85rem;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
}
.btn:disabled { opacity: 0.6; cursor: default; }
.btn-principal { background: #2563eb; border-color: #1d4ed8; color: #fff; }
.btn-secundario { background: #fff; border-color: #cbd5e1; color: #334155; }
.btn-secundario:hover { background: #f1f5f9; }
</style>
