<script setup lang="ts">
import { computed, ref } from 'vue'
import type { DocumentoPlantilla, PlantillaBloque } from '@/config/documentos-plantillas'
import {
  CATALOGO_BLOQUES_TICKET,
  crearBloquePorTipo,
  etiquetaTipoBloque,
} from '@/config/documentos-plantillas/bloques-catalogo'

const props = defineProps<{
  modelValue: DocumentoPlantilla
  readonly?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: DocumentoPlantilla]
  dirty: [value: boolean]
}>()

const selectedId = ref<string | null>(null)

const plantilla = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selected = computed(() => plantilla.value.blocks.find((b) => b.id === selectedId.value) ?? null)

const bloquesOrdenados = computed(() =>
  [...plantilla.value.blocks].sort((a, b) => a.y - b.y || a.x - b.x)
)

function markDirty() {
  emit('dirty', true)
}

function setBlocks(blocks: PlantillaBloque[]) {
  // Reasignar Y en pila (espaciado ticket)
  let y = 2
  const stacked = blocks.map((b) => {
    const next = { ...b, x: 2, w: 76, y }
    y += Math.max(b.h, 4) + 1
    return next
  })
  plantilla.value = { ...plantilla.value, blocks: stacked }
  markDirty()
}

function puedeAnadir(type: string) {
  const cat = CATALOGO_BLOQUES_TICKET.find((c) => c.type === type)
  if (!cat?.unico) return true
  return !plantilla.value.blocks.some((b) => b.type === type)
}

function anadir(type: PlantillaBloque['type']) {
  if (props.readonly || !puedeAnadir(type)) return
  const bloque = crearBloquePorTipo(type)
  if (type === 'titulo-documento') bloque.label = 'TICKET'
  setBlocks([...bloquesOrdenados.value, bloque])
  selectedId.value = bloque.id
}

function quitar(id: string) {
  if (props.readonly) return
  setBlocks(bloquesOrdenados.value.filter((b) => b.id !== id))
  if (selectedId.value === id) selectedId.value = null
}

function mover(id: string, dir: -1 | 1) {
  if (props.readonly) return
  const list = [...bloquesOrdenados.value]
  const idx = list.findIndex((b) => b.id === id)
  const dest = idx + dir
  if (idx < 0 || dest < 0 || dest >= list.length) return
  const [item] = list.splice(idx, 1)
  list.splice(dest, 0, item)
  setBlocks(list)
}

function patchSelected(patch: Partial<PlantillaBloque>) {
  if (!selected.value || props.readonly) return
  const blocks = plantilla.value.blocks.map((b) =>
    b.id === selected.value!.id ? { ...b, ...patch } : b
  )
  plantilla.value = { ...plantilla.value, blocks }
  markDirty()
}
</script>

<template>
  <div class="ticket-designer">
    <p class="hint">
      Ticket 80 mm. Los <strong>literales</strong> salen del puesto (Literal 1…9); cuántos se imprimen lo
      marca la tienda en <em>Literales ticket</em>. Los pies de factura/presupuesto/vale están en
      Tiendas → Datos generales.
    </p>

    <div class="workspace">
      <aside class="palette">
        <h4>Añadir</h4>
        <button
          v-for="c in CATALOGO_BLOQUES_TICKET"
          :key="c.type"
          type="button"
          class="pal-btn"
          :disabled="readonly || !puedeAnadir(c.type)"
          @click="anadir(c.type)"
        >
          {{ c.nombre }}
        </button>
      </aside>

      <div class="stack">
        <div
          v-for="(b, idx) in bloquesOrdenados"
          :key="b.id"
          class="row"
          :class="{ selected: selectedId === b.id }"
          @click="selectedId = b.id"
        >
          <span class="ord">{{ idx + 1 }}</span>
          <div class="info">
            <strong>{{ etiquetaTipoBloque(b.type) }}</strong>
            <em v-if="b.label">{{ b.label }}</em>
          </div>
          <div class="row-actions">
            <button type="button" :disabled="readonly || idx === 0" @click.stop="mover(b.id, -1)">↑</button>
            <button
              type="button"
              :disabled="readonly || idx === bloquesOrdenados.length - 1"
              @click.stop="mover(b.id, 1)"
            >
              ↓
            </button>
            <button type="button" class="danger" :disabled="readonly" @click.stop="quitar(b.id)">
              ✕
            </button>
          </div>
        </div>
        <p v-if="!bloquesOrdenados.length" class="empty">Añada bloques desde la izquierda.</p>
      </div>

      <aside class="inspector">
        <h4>Contenido</h4>
        <template v-if="selected">
          <p class="tipo-tag">{{ etiquetaTipoBloque(selected.type) }}</p>
          <label v-if="selected.type === 'texto' || selected.type === 'titulo-documento' || selected.type === 'separador'">
            Texto
            <input
              :value="selected.label ?? ''"
              :readonly="readonly"
              @input="patchSelected({ label: ($event.target as HTMLInputElement).value })"
            />
          </label>
          <p v-else-if="selected.type === 'literales-puesto'" class="note">
            Imprime Literal1…N del puesto. N = campo <strong>Literales ticket</strong> de la tienda.
          </p>
          <p v-else class="note">Bloque de datos de venta / empresa.</p>
          <button type="button" class="btn-del" :disabled="readonly" @click="quitar(selected.id)">
            Quitar bloque
          </button>
        </template>
        <p v-else class="empty">Seleccione un bloque.</p>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.ticket-designer {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  min-width: 0;
}

.hint {
  margin: 0;
  font-size: 0.78rem;
  color: #64748b;
  line-height: 1.4;
}

.workspace {
  display: grid;
  grid-template-columns: 9rem minmax(0, 1fr) minmax(11rem, 13rem);
  gap: 0.5rem;
  align-items: start;
}

.palette,
.inspector {
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #f8fafc;
  padding: 0.45rem;
}

.palette h4,
.inspector h4 {
  margin: 0 0 0.35rem;
  font-size: 0.75rem;
  color: #334155;
}

.pal-btn,
.btn-del,
.row-actions button {
  display: block;
  width: 100%;
  margin-bottom: 0.25rem;
  padding: 0.28rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  font-size: 0.7rem;
  text-align: left;
  cursor: pointer;
}

.pal-btn:disabled,
.row-actions button:disabled,
.btn-del:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.stack {
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  background: #e8edf2;
  padding: 0.5rem;
  min-height: 16rem;
  max-height: min(70vh, 720px);
  overflow: auto;
}

.row {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 0.45rem;
  margin-bottom: 0.3rem;
  background: #fff;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  cursor: pointer;
}

.row.selected {
  border-color: #2563eb;
  box-shadow: 0 0 0 2px rgb(37 99 235 / 25%);
}

.ord {
  font-size: 0.7rem;
  font-weight: 700;
  color: #64748b;
  width: 1.2rem;
}

.info {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.05rem;
}

.info strong {
  font-size: 0.8rem;
}

.info em {
  font-style: normal;
  font-size: 0.7rem;
  color: #64748b;
}

.row-actions {
  display: flex;
  gap: 0.2rem;
}

.row-actions button {
  width: auto;
  margin: 0;
  padding: 0.15rem 0.35rem;
}

.row-actions .danger,
.btn-del {
  color: #b91c1c;
  border-color: #fecaca;
}

.tipo-tag {
  margin: 0 0 0.4rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: #1e40af;
}

.inspector label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 0.5rem;
}

.inspector input {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 500;
}

.note,
.empty {
  margin: 0.35rem 0;
  font-size: 0.72rem;
  color: #64748b;
  line-height: 1.35;
}

@media (max-width: 900px) {
  .workspace {
    grid-template-columns: 1fr;
  }
}
</style>
