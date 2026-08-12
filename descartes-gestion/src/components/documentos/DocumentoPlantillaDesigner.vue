<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { DocumentoPlantilla, PlantillaBloque, PlantillaBloqueTipo } from '@/config/documentos-plantillas'
import {
  CATALOGO_BLOQUES,
  CAMPOS_BIND_SUGERIDOS,
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

const PAGE_W = 210
const PAGE_H = 297

const canvasRef = ref<HTMLElement | null>(null)
const pxPerMm = ref(2.4)
const selectedId = ref<string | null>(null)
const dirty = ref(false)

type DragMode = 'move' | 'resize'
type DragState = {
  mode: DragMode
  blockId: string
  startX: number
  startY: number
  orig: Pick<PlantillaBloque, 'x' | 'y' | 'w' | 'h'>
}

const drag = ref<DragState | null>(null)

const plantilla = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selected = computed(() => plantilla.value.blocks.find((b) => b.id === selectedId.value) ?? null)

const pageStyle = computed(() => ({
  width: `${PAGE_W * pxPerMm.value}px`,
  height: `${PAGE_H * pxPerMm.value}px`,
}))

const marginStyle = computed(() => {
  const m = plantilla.value.page.marginMm
  return {
    left: `${m.left * pxPerMm.value}px`,
    top: `${m.top * pxPerMm.value}px`,
    width: `${(PAGE_W - m.left - m.right) * pxPerMm.value}px`,
    height: `${(PAGE_H - m.top - m.bottom) * pxPerMm.value}px`,
  }
})

const bindText = computed({
  get: () => (selected.value?.bind ?? []).join('\n'),
  set: (raw: string) => {
    if (!selected.value || props.readonly) return
    const bind = raw
      .split(/\r?\n/)
      .map((s) => s.trim())
      .filter(Boolean)
    patchBlockContent(selected.value.id, { bind })
  },
})

const piePlantilla = computed({
  get: () => String(selected.value?.props?.plantilla ?? ''),
  set: (v: string) => setProp('plantilla', v),
})

const etiquetaTotal = computed({
  get: () => String(selected.value?.props?.etiquetaTotal ?? ''),
  set: (v: string) => setProp('etiquetaTotal', v),
})

const etiquetaModo = computed({
  get: () => String(selected.value?.props?.etiquetaModo ?? ''),
  set: (v: string) => setProp('etiquetaModo', v),
})

function etiquetaBloque(type: PlantillaBloqueTipo, label?: string) {
  return label?.trim() || etiquetaTipoBloque(type)
}

function tieneBloque(type: PlantillaBloqueTipo) {
  return plantilla.value.blocks.some((b) => b.type === type)
}

function puedeAnadir(type: PlantillaBloqueTipo) {
  const meta = CATALOGO_BLOQUES.find((c) => c.type === type)
  if (!meta) return false
  if (meta.unico && tieneBloque(type)) return false
  return true
}

function markDirty() {
  dirty.value = true
  emit('dirty', true)
}

function anadirBloque(type: PlantillaBloqueTipo) {
  if (props.readonly || !puedeAnadir(type)) return
  const block = crearBloquePorTipo(type)
  // Evitar id duplicado
  if (plantilla.value.blocks.some((b) => b.id === block.id)) {
    block.id = `${block.id}-${Date.now().toString(36)}`
  }
  plantilla.value = { ...plantilla.value, blocks: [...plantilla.value.blocks, block] }
  selectedId.value = block.id
  markDirty()
}

function quitarBloque(id?: string) {
  if (props.readonly) return
  const target = id ?? selectedId.value
  if (!target) return
  if (!confirm('¿Eliminar este recuadro?')) return
  plantilla.value = {
    ...plantilla.value,
    blocks: plantilla.value.blocks.filter((b) => b.id !== target),
  }
  if (selectedId.value === target) selectedId.value = null
  markDirty()
}

function blockStyle(b: PlantillaBloque) {
  return {
    left: `${b.x * pxPerMm.value}px`,
    top: `${b.y * pxPerMm.value}px`,
    width: `${b.w * pxPerMm.value}px`,
    height: `${b.h * pxPerMm.value}px`,
  }
}

function round1(n: number) {
  return Math.round(n * 10) / 10
}

function clampBlock(b: PlantillaBloque): PlantillaBloque {
  const min = 8
  let w = Math.max(min, b.w)
  let h = Math.max(min, b.h)
  let x = Math.max(0, Math.min(b.x, PAGE_W - w))
  let y = Math.max(0, Math.min(b.y, PAGE_H - h))
  w = Math.min(w, PAGE_W - x)
  h = Math.min(h, PAGE_H - y)
  return { ...b, x: round1(x), y: round1(y), w: round1(w), h: round1(h) }
}

function patchBlock(id: string, patch: Partial<PlantillaBloque>) {
  const blocks = plantilla.value.blocks.map((b) => {
    if (b.id !== id) return b
    return clampBlock({ ...b, ...patch })
  })
  plantilla.value = { ...plantilla.value, blocks }
  markDirty()
}

function patchBlockContent(id: string, patch: Partial<PlantillaBloque>) {
  const blocks = plantilla.value.blocks.map((b) => {
    if (b.id !== id) return b
    return { ...b, ...patch }
  })
  plantilla.value = { ...plantilla.value, blocks }
  markDirty()
}

function setProp(key: string, value: string) {
  if (!selected.value || props.readonly) return
  const propsMap = { ...(selected.value.props ?? {}) }
  if (value.trim() === '') delete propsMap[key]
  else propsMap[key] = value
  patchBlockContent(selected.value.id, { props: propsMap })
}

function onLabelInput(raw: string) {
  if (!selected.value || props.readonly) return
  patchBlockContent(selected.value.id, { label: raw })
}

function selectBlock(id: string, e: MouseEvent) {
  e.stopPropagation()
  selectedId.value = id
}

function clearSelection() {
  selectedId.value = null
}

function startMove(b: PlantillaBloque, e: MouseEvent) {
  if (props.readonly) return
  e.preventDefault()
  e.stopPropagation()
  selectedId.value = b.id
  drag.value = {
    mode: 'move',
    blockId: b.id,
    startX: e.clientX,
    startY: e.clientY,
    orig: { x: b.x, y: b.y, w: b.w, h: b.h },
  }
}

function startResize(b: PlantillaBloque, e: MouseEvent) {
  if (props.readonly) return
  e.preventDefault()
  e.stopPropagation()
  selectedId.value = b.id
  drag.value = {
    mode: 'resize',
    blockId: b.id,
    startX: e.clientX,
    startY: e.clientY,
    orig: { x: b.x, y: b.y, w: b.w, h: b.h },
  }
}

function onPointerMove(e: MouseEvent) {
  const d = drag.value
  if (!d) return
  const dx = (e.clientX - d.startX) / pxPerMm.value
  const dy = (e.clientY - d.startY) / pxPerMm.value
  if (d.mode === 'move') {
    patchBlock(d.blockId, { x: d.orig.x + dx, y: d.orig.y + dy })
  } else {
    patchBlock(d.blockId, { w: d.orig.w + dx, h: d.orig.h + dy })
  }
}

function onPointerUp() {
  drag.value = null
}

function onGeomInput(field: 'x' | 'y' | 'w' | 'h', raw: string) {
  if (!selected.value || props.readonly) return
  const n = Number(raw.replace(',', '.'))
  if (!Number.isFinite(n)) return
  patchBlock(selected.value.id, { [field]: n })
}

function onKeydown(e: KeyboardEvent) {
  if (props.readonly) return
  if (e.key === 'Delete' || e.key === 'Backspace') {
    const t = e.target as HTMLElement | null
    if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return
    if (selectedId.value) {
      e.preventDefault()
      quitarBloque(selectedId.value)
    }
  }
}

function addColumna() {
  if (!selected.value || selected.value.type !== 'tabla-lineas' || props.readonly) return
  const columns = [...(selected.value.columns ?? [])]
  columns.push({ key: `col${columns.length + 1}`, label: 'Nueva', width: 20 })
  patchBlockContent(selected.value.id, { columns })
}

function removeColumna(idx: number) {
  if (!selected.value || selected.value.type !== 'tabla-lineas' || props.readonly) return
  const columns = (selected.value.columns ?? []).filter((_, i) => i !== idx)
  patchBlockContent(selected.value.id, { columns })
}

function patchColumna(idx: number, field: 'key' | 'label' | 'width', raw: string) {
  if (!selected.value || selected.value.type !== 'tabla-lineas' || props.readonly) return
  const columns = (selected.value.columns ?? []).map((c, i) => {
    if (i !== idx) return c
    if (field === 'width') {
      const n = Number(raw.replace(',', '.'))
      return { ...c, width: Number.isFinite(n) ? n : c.width }
    }
    return { ...c, [field]: raw }
  })
  patchBlockContent(selected.value.id, { columns })
}

function addBindSugerido(path: string) {
  if (!selected.value || props.readonly) return
  const bind = [...(selected.value.bind ?? [])]
  if (!bind.includes(path)) bind.push(path)
  patchBlockContent(selected.value.id, { bind })
}

function fitScale() {
  const el = canvasRef.value
  if (!el) return
  const available = Math.max(320, el.clientWidth - 24)
  // A4 más grande en pantalla (antes tope 2.8 ≈ 588px de ancho)
  pxPerMm.value = Math.min(4.2, Math.max(2.0, available / PAGE_W))
}

onMounted(() => {
  void nextTick(() => {
    fitScale()
    requestAnimationFrame(() => fitScale())
  })
  window.addEventListener('mousemove', onPointerMove)
  window.addEventListener('mouseup', onPointerUp)
  window.addEventListener('resize', fitScale)
  window.addEventListener('keydown', onKeydown)
})

onBeforeUnmount(() => {
  window.removeEventListener('mousemove', onPointerMove)
  window.removeEventListener('mouseup', onPointerUp)
  window.removeEventListener('resize', fitScale)
  window.removeEventListener('keydown', onKeydown)
})

watch(
  () => props.modelValue.id,
  () => {
    selectedId.value = null
    dirty.value = false
    emit('dirty', false)
  }
)

defineExpose({
  markClean() {
    dirty.value = false
    emit('dirty', false)
  },
})
</script>

<template>
  <div class="designer">
    <div class="toolbar">
      <span class="hint">Añada o quite recuadros · arrastre para mover · esquina para redimensionar · Supr para borrar</span>
      <button
        type="button"
        class="btn-del"
        :disabled="readonly || !selected"
        @click="quitarBloque()"
      >
        Quitar recuadro
      </button>
    </div>

    <div class="workspace">
      <aside class="palette">
        <h4>Añadir</h4>
        <button
          v-for="item in CATALOGO_BLOQUES"
          :key="item.type"
          type="button"
          class="pal-btn"
          :disabled="readonly || !puedeAnadir(item.type)"
          :title="item.unico && !puedeAnadir(item.type) ? 'Ya existe en la plantilla' : item.nombre"
          @click="anadirBloque(item.type)"
        >
          + {{ item.nombre }}
        </button>
      </aside>

      <div ref="canvasRef" class="canvas-wrap" @mousedown="clearSelection">
        <div class="page" :style="pageStyle">
          <div class="margin-guide" :style="marginStyle" />
          <div
            v-for="b in plantilla.blocks"
            :key="b.id"
            class="block"
            :class="[`type-${b.type}`, { selected: selectedId === b.id }]"
            :style="blockStyle(b)"
            @mousedown="startMove(b, $event)"
            @click="selectBlock(b.id, $event)"
          >
            <span class="block-label">{{ etiquetaBloque(b.type, b.label) }}</span>
            <span v-if="b.type === 'tabla-lineas' && b.columns" class="block-cols">
              {{ b.columns.map((c) => c.label).join(' · ') }}
            </span>
            <span
              v-if="!readonly"
              class="resize-handle"
              title="Redimensionar"
              @mousedown="startResize(b, $event)"
            />
          </div>
        </div>
      </div>

      <aside class="inspector">
        <template v-if="selected">
          <h4>Contenido</h4>
          <p class="tipo-tag">{{ etiquetaTipoBloque(selected.type) }}</p>

          <div class="geom">
            <label>X <input type="number" step="0.5" :value="selected.x" :disabled="readonly" @change="onGeomInput('x', ($event.target as HTMLInputElement).value)" /></label>
            <label>Y <input type="number" step="0.5" :value="selected.y" :disabled="readonly" @change="onGeomInput('y', ($event.target as HTMLInputElement).value)" /></label>
            <label>W <input type="number" step="0.5" :value="selected.w" :disabled="readonly" @change="onGeomInput('w', ($event.target as HTMLInputElement).value)" /></label>
            <label>H <input type="number" step="0.5" :value="selected.h" :disabled="readonly" @change="onGeomInput('h', ($event.target as HTMLInputElement).value)" /></label>
          </div>

          <label class="field">
            Etiqueta
            <input
              type="text"
              :value="selected.label ?? ''"
              :readonly="readonly"
              placeholder="Texto visible del recuadro"
              @input="onLabelInput(($event.target as HTMLInputElement).value)"
            />
          </label>

          <label
            v-if="selected.type === 'titulo-documento' || selected.type === 'totales-iva'"
            class="field"
          >
            {{ selected.type === 'totales-iva' ? 'Etiqueta total' : 'Modo (CREDITO…)' }}
            <input
              v-if="selected.type === 'totales-iva'"
              v-model="etiquetaTotal"
              type="text"
              :readonly="readonly"
              placeholder="IMPORTE EU"
            />
            <input
              v-else
              v-model="etiquetaModo"
              type="text"
              :readonly="readonly"
              placeholder="CREDITO"
            />
          </label>

          <label v-if="selected.type === 'pie'" class="field">
            Plantilla pie
            <textarea v-model="piePlantilla" rows="3" :readonly="readonly" spellcheck="false" />
            <span class="help">Use variables como empresa.razonSocial entre dobles llaves.</span>
          </label>

          <label
            v-if="selected.type !== 'emblema' && selected.type !== 'texto'"
            class="field"
          >
            Campos enlazados (uno por línea)
            <textarea v-model="bindText" rows="5" :readonly="readonly" spellcheck="false" />
          </label>

          <div
            v-if="selected.type !== 'emblema' && selected.type !== 'texto' && !readonly"
            class="sugeridos"
          >
            <span class="help">Añadir sugerido:</span>
            <select @change="addBindSugerido(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
              <option value="">—</option>
              <option v-for="c in CAMPOS_BIND_SUGERIDOS" :key="c" :value="c">{{ c }}</option>
            </select>
          </div>

          <div v-if="selected.type === 'tabla-lineas'" class="cols-ed">
            <div class="cols-cab">
              <h5>Columnas</h5>
              <button type="button" class="btn-mini" :disabled="readonly" @click="addColumna">+ Columna</button>
            </div>
            <div v-for="(col, idx) in selected.columns ?? []" :key="idx" class="col-row">
              <input
                type="text"
                :value="col.key"
                :readonly="readonly"
                placeholder="key"
                title="Clave de dato"
                @change="patchColumna(idx, 'key', ($event.target as HTMLInputElement).value)"
              />
              <input
                type="text"
                :value="col.label"
                :readonly="readonly"
                placeholder="Etiqueta"
                @change="patchColumna(idx, 'label', ($event.target as HTMLInputElement).value)"
              />
              <input
                type="number"
                class="w-num"
                :value="col.width"
                :readonly="readonly"
                title="Ancho relativo"
                @change="patchColumna(idx, 'width', ($event.target as HTMLInputElement).value)"
              />
              <button type="button" class="btn-mini danger" :disabled="readonly" @click="removeColumna(idx)">×</button>
            </div>
          </div>

          <button type="button" class="btn-del full" :disabled="readonly" @click="quitarBloque()">
            Quitar este recuadro
          </button>
        </template>
        <p v-else class="empty">Seleccione un recuadro para editar su contenido.</p>
      </aside>
    </div>
  </div>
</template>

<style scoped>
.designer {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  min-width: 0;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.75rem;
  color: #64748b;
}

.hint {
  flex: 1;
  min-width: 12rem;
}

.workspace {
  display: grid;
  grid-template-columns: 8.5rem minmax(0, 1fr) minmax(12rem, 13.5rem);
  gap: 0.5rem;
  align-items: start;
}

.palette,
.inspector {
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #f8fafc;
  padding: 0.45rem;
  max-height: min(72vh, 900px);
  overflow: auto;
}

.palette h4,
.inspector h4 {
  margin: 0 0 0.35rem;
  font-size: 0.75rem;
  color: #334155;
}

.pal-btn,
.btn-del,
.btn-mini {
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
.btn-del:disabled,
.btn-mini:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.pal-btn:not(:disabled):hover {
  border-color: #2563eb;
  color: #1d4ed8;
}

.btn-del {
  width: auto;
  text-align: center;
  color: #b91c1c;
  border-color: #fecaca;
}

.btn-del.full {
  width: 100%;
  margin-top: 0.65rem;
}

.btn-mini {
  width: auto;
  margin: 0;
  padding: 0.15rem 0.35rem;
}

.btn-mini.danger {
  color: #b91c1c;
}

.tipo-tag {
  margin: 0 0 0.45rem;
  font-size: 0.72rem;
  font-weight: 700;
  color: #1e40af;
}

.geom {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.25rem;
  margin-bottom: 0.45rem;
}

.geom label {
  display: flex;
  align-items: center;
  gap: 0.2rem;
  font-size: 0.7rem;
  font-weight: 600;
  color: #475569;
}

.geom input,
.field input,
.field textarea,
.sugeridos select,
.col-row input {
  width: 100%;
  min-width: 0;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.72rem;
  box-sizing: border-box;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-bottom: 0.45rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #475569;
}

.help {
  font-weight: 400;
  font-size: 0.65rem;
  color: #94a3b8;
}

.sugeridos {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin-bottom: 0.5rem;
}

.cols-ed {
  margin-top: 0.25rem;
}

.cols-cab {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.3rem;
}

.cols-cab h5 {
  margin: 0;
  font-size: 0.72rem;
}

.col-row {
  display: grid;
  grid-template-columns: 1fr 1fr 2.5rem auto;
  gap: 0.2rem;
  margin-bottom: 0.2rem;
}

.col-row .w-num {
  text-align: right;
}

.empty {
  margin: 1rem 0.25rem;
  font-size: 0.75rem;
  color: #94a3b8;
}

.canvas-wrap {
  overflow: auto;
  padding: 0.65rem;
  background: #e8edf2;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  max-height: min(82vh, 1100px);
  min-height: 28rem;
}

.page {
  position: relative;
  margin: 0 auto;
  background: #fff;
  box-shadow: 0 2px 10px rgb(15 23 42 / 12%);
  user-select: none;
}

.margin-guide {
  position: absolute;
  border: 1px dashed #cbd5e1;
  pointer-events: none;
  box-sizing: border-box;
}

.block {
  position: absolute;
  box-sizing: border-box;
  border: 1px solid #64748b;
  background: rgb(219 234 254 / 55%);
  cursor: grab;
  overflow: hidden;
  padding: 0.15rem 0.3rem;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.block:active {
  cursor: grabbing;
}

.block.selected {
  border-color: #2563eb;
  box-shadow: 0 0 0 2px rgb(37 99 235 / 35%);
  z-index: 2;
}

.block-label {
  font-size: 0.78rem;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.block-cols {
  font-size: 0.68rem;
  color: #475569;
  line-height: 1.25;
  overflow: hidden;
}

.resize-handle {
  position: absolute;
  right: 0;
  bottom: 0;
  width: 10px;
  height: 10px;
  background: #2563eb;
  cursor: nwse-resize;
  border-radius: 2px 0 0 0;
}

.type-tabla-lineas {
  background: rgb(254 243 199 / 55%);
  border-color: #d97706;
}

.type-totales-iva,
.type-vencimientos {
  background: rgb(209 250 229 / 55%);
  border-color: #059669;
}

.type-titulo-documento {
  background: rgb(224 231 255 / 70%);
  border-color: #4f46e5;
}

.type-codigo-barras {
  background: rgb(241 245 249 / 80%);
}

.type-pie {
  background: rgb(226 232 240 / 70%);
}

.type-emblema {
  background: rgb(254 226 226 / 55%);
  border-color: #dc2626;
  border-style: dashed;
}

.type-qr-verifactu {
  background: rgb(237 233 254 / 65%);
  border-color: #7c3aed;
  border-style: dashed;
}

@media (max-width: 1100px) {
  .workspace {
    grid-template-columns: 1fr;
  }

  .palette {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    max-height: none;
  }

  .palette h4 {
    width: 100%;
  }

  .pal-btn {
    width: auto;
    margin: 0;
  }
}
</style>
