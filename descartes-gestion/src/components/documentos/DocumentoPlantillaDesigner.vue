<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { DocumentoPlantilla, PlantillaBloque, PlantillaBloqueTipo } from '@/config/documentos-plantillas'
import {
  ETIQUETA_TAMANOS_MM,
  claveTamanoEtiqueta,
  conTamanoEtiqueta,
  esPlantillaEtiqueta,
  pageSizeMm,
  parseClaveTamanoEtiqueta,
} from '@/config/documentos-plantillas'
import {
  CATALOGO_BLOQUES,
  CATALOGO_BLOQUES_ETIQUETA,
  CATALOGO_GRUPOS_A4,
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

const canvasRef = ref<HTMLElement | null>(null)
const contenidoTextoRef = ref<HTMLTextAreaElement | null>(null)
/** Texto del bloque seleccionado (evita perder el foco al sincronizar con el padre). */
const labelEditLocal = ref('')
/** ~1 mm en pantalla a 96 dpi (proporción real). */
const FOLIO_PX_PER_MM = 96 / 25.4
const pxPerMm = ref(FOLIO_PX_PER_MM)
const selectedId = ref<string | null>(null)
const dirty = ref(false)

type DragMode = 'move' | 'resize'
type DragState = {
  mode: DragMode
  blockId: string
  startX: number
  startY: number
  orig: Pick<PlantillaBloque, 'x' | 'y' | 'w' | 'h'>
  /** Hasta que el ratón se mueva un poco, es un clic (no arrastre). */
  pendiente?: boolean
}

const drag = ref<DragState | null>(null)

const plantilla = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const esEtiqueta = computed(() => esPlantillaEtiqueta(plantilla.value))

const pageDims = computed(() => pageSizeMm(plantilla.value))
const PAGE_W = computed(() => pageDims.value.widthMm)
const PAGE_H = computed(() => pageDims.value.heightMm)

const catalogo = computed(() =>
  esEtiqueta.value ? CATALOGO_BLOQUES_ETIQUETA : CATALOGO_BLOQUES
)

const catalogoAgrupado = computed(() =>
  CATALOGO_GRUPOS_A4.map((g) => ({
    ...g,
    items: CATALOGO_BLOQUES.filter((c) => (c.grupo ?? 'cuerpo') === g.id),
  })).filter((g) => g.items.length > 0)
)

const bindsSugeridos = computed(() => {
  if (!esEtiqueta.value) return CAMPOS_BIND_SUGERIDOS
  const art = CAMPOS_BIND_SUGERIDOS.filter((p) => p.startsWith('articulo.'))
  const rest = CAMPOS_BIND_SUGERIDOS.filter(
    (p) => !p.startsWith('articulo.') && !p.startsWith('cliente.') && p !== 'lineas'
  )
  return [...art, ...rest]
})

const selected = computed(() => plantilla.value.blocks.find((b) => b.id === selectedId.value) ?? null)

const pageStyle = computed(() => ({
  width: `${PAGE_W.value * pxPerMm.value}px`,
  height: `${PAGE_H.value * pxPerMm.value}px`,
}))

const marginStyle = computed(() => {
  const m = plantilla.value.page.marginMm
  return {
    left: `${m.left * pxPerMm.value}px`,
    top: `${m.top * pxPerMm.value}px`,
    width: `${(PAGE_W.value - m.left - m.right) * pxPerMm.value}px`,
    height: `${(PAGE_H.value - m.top - m.bottom) * pxPerMm.value}px`,
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

function commitLabelEdit(raw: string) {
  labelEditLocal.value = raw
  if (!selected.value || props.readonly) return
  if ((selected.value.label ?? '') === raw) return
  patchBlockContent(selected.value.id, { label: raw })
}

watch(
  () => `${selected.value?.id ?? ''}\0${selected.value?.label ?? ''}`,
  () => {
    labelEditLocal.value = selected.value?.label ?? ''
  },
  { immediate: true }
)

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

const fontSizeMm = computed({
  get: () => {
    const n = Number(selected.value?.props?.fontSizeMm)
    return Number.isFinite(n) && n > 0 ? n : (esEtiqueta.value ? 3 : 2.2)
  },
  set: (v: number) => {
    const n = Number(v)
    if (!Number.isFinite(n) || n <= 0) {
      setProp('fontSizeMm', '')
      return
    }
    setProp('fontSizeMm', Math.min(20, Math.max(1, Math.round(n * 10) / 10)))
  },
})

const fontWeight = computed({
  get: () => String(selected.value?.props?.fontWeight ?? 'normal'),
  set: (v: string) => setProp('fontWeight', v === 'bold' ? 'bold' : ''),
})

const textAlign = computed({
  get: () => {
    if (selected.value?.props?.align === 'right') return 'right'
    if (selected.value?.props?.centrado) return 'center'
    return 'left'
  },
  set: (v: string) => {
    if (!selected.value || props.readonly) return
    const propsMap = { ...(selected.value.props ?? {}) }
    delete propsMap.align
    delete propsMap.centrado
    if (v === 'right') propsMap.align = 'right'
    else if (v === 'center') propsMap.centrado = true
    patchBlockContent(selected.value.id, { props: propsMap })
  },
})

const muestraTipografia = computed(
  () =>
    selected.value &&
    (selected.value.type === 'campo' ||
      selected.value.type === 'texto' ||
      selected.value.type === 'titulo-documento' ||
      selected.value.type === 'bloque-cliente' ||
      selected.value.type === 'bloque-meta' ||
      selected.value.type === 'empresa-cabecera' ||
      selected.value.type === 'tabla-lineas' ||
      selected.value.type === 'totales-iva' ||
      selected.value.type === 'datos-bancarios' ||
      selected.value.type === 'vencimientos' ||
      selected.value.type === 'pie')
)

const CAMPOS_CLIENTE = [
  { path: 'cliente.codigo', label: 'Código' },
  { path: 'cliente.nombre', label: 'Nombre / razón social' },
  { path: 'cliente.direccion', label: 'Dirección' },
  { path: 'cliente.cp', label: 'Código postal' },
  { path: 'cliente.poblacion', label: 'Población' },
  { path: 'cliente.provincia', label: 'Provincia' },
  { path: 'cliente.pais', label: 'País' },
  { path: 'cliente.telefono', label: 'Teléfono' },
  { path: 'cliente.cif', label: 'NIF / CIF' },
] as const

function campoClienteActivo(path: string): boolean {
  return selected.value?.bind?.includes(path) ?? false
}

function toggleCampoCliente(path: string, activo: boolean) {
  if (!selected.value || selected.value.type !== 'bloque-cliente' || props.readonly) return
  const bind = activo
    ? [...new Set([...(selected.value.bind ?? []), path])]
    : (selected.value.bind ?? []).filter((p) => p !== path)
  patchBlockContent(selected.value.id, { bind })
}

const muestraBarras = computed(() => selected.value?.type === 'codigo-barras')

const BARCODE_PRESETS = [
  { id: 'S', label: 'Pequeño', w: 24, h: 7 },
  { id: 'M', label: 'Mediano', w: 32, h: 10 },
  { id: 'L', label: 'Grande', w: 40, h: 13 },
  { id: 'XL', label: 'Extra', w: 46, h: 16 },
] as const

const barcodePresetKey = computed(() => {
  if (!selected.value || selected.value.type !== 'codigo-barras') return ''
  const { w, h } = selected.value
  const hit = BARCODE_PRESETS.find((p) => p.w === w && p.h === h)
  return hit?.id ?? 'custom'
})

const barcodeShowValue = computed({
  get: () => selected.value?.props?.showValue !== false,
  set: (v: boolean) => {
    if (!selected.value || props.readonly) return
    const propsMap = { ...(selected.value.props ?? {}) }
    if (v) delete propsMap.showValue
    else propsMap.showValue = false
    patchBlockContent(selected.value.id, { props: propsMap })
  },
})

const barcodeModuleWidth = computed({
  get: () => {
    const n = Number(selected.value?.props?.moduleWidth)
    return Number.isFinite(n) && n >= 0.6 ? n : 1.2
  },
  set: (v: number) => {
    const n = Number(v)
    if (!Number.isFinite(n)) return
    setProp('moduleWidth', Math.min(3, Math.max(0.6, Math.round(n * 10) / 10)))
  },
})

function aplicarPresetBarras(id: string) {
  if (!selected.value || selected.value.type !== 'codigo-barras' || props.readonly) return
  const preset = BARCODE_PRESETS.find((p) => p.id === id)
  if (!preset) return
  const maxW = PAGE_W.value
  const maxH = PAGE_H.value
  patchBlock(selected.value.id, {
    w: Math.min(preset.w, maxW - selected.value.x),
    h: Math.min(preset.h, maxH - selected.value.y),
  })
}

function etiquetaBloque(type: PlantillaBloqueTipo, label?: string) {
  return label?.trim() || etiquetaTipoBloque(type)
}

function etiquetaBloqueCanvas(b: PlantillaBloque): string {
  if (b.type === 'titulo-documento') {
    return b.label?.trim() || 'DOCUMENTO'
  }
  return etiquetaBloque(b.type, b.label)
}

function tieneBloque(type: PlantillaBloqueTipo) {
  return plantilla.value.blocks.some((b) => b.type === type)
}

function puedeAnadir(type: PlantillaBloqueTipo) {
  const meta = catalogo.value.find((c) => c.type === type)
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
  if (esEtiqueta.value) {
    if (type === 'codigo-barras') {
      block.bind = ['articulo.ean']
      block.w = Math.min(32, PAGE_W.value - 2)
      block.h = Math.min(10, PAGE_H.value - 2)
      block.x = 1.5
      block.y = 1.5
      block.props = { ...(block.props ?? {}), showValue: true, moduleWidth: 1.2 }
    } else if (type === 'campo' || type === 'texto' || type === 'titulo-documento') {
      block.w = Math.min(40, PAGE_W.value - 2)
      block.h = Math.min(6, PAGE_H.value - 2)
      if (type === 'campo') block.bind = ['articulo.descripcion']
      block.props = { ...(block.props ?? {}), fontSizeMm: 3.5 }
    }
  }
  // Evitar id duplicado
  if (plantilla.value.blocks.some((b) => b.id === block.id)) {
    block.id = `${block.id}-${Date.now().toString(36)}`
  }
  const colocado =
    !esEtiqueta.value && type !== 'qr-verifactu' ? colocarSinSolape(block) : clampBlock(block)
  plantilla.value = { ...plantilla.value, blocks: [...plantilla.value.blocks, colocado] }
  selectedId.value = colocado.id
  markDirty()
  if (type === 'texto' || type === 'titulo-documento') void enfocarEditorContenido(true)
}

const tieneCampoNumeroDocumento = computed(() =>
  plantilla.value.blocks.some(
    (b) => b.type === 'campo' && (b.bind ?? []).includes('documento.numero')
  )
)

function anadirNumeroDocumento() {
  if (props.readonly || tieneCampoNumeroDocumento.value) return
  const block: PlantillaBloque = {
    id: `numero-${Date.now().toString(36)}`,
    type: 'campo',
    x: 12,
    y: 44,
    w: 90,
    h: 7,
    label: plantilla.value.tipo === 'albaran' ? 'ALBARÁN' : 'FACTURA',
    bind: ['documento.numero'],
    props: { inline: true, fontSizeMm: 2.8, fontWeight: 'bold' },
  }
  const colocado = colocarSinSolape(block)
  plantilla.value = { ...plantilla.value, blocks: [...plantilla.value.blocks, colocado] }
  selectedId.value = colocado.id
  markDirty()
}

function rectsSolapan(
  a: Pick<PlantillaBloque, 'x' | 'y' | 'w' | 'h'>,
  b: Pick<PlantillaBloque, 'x' | 'y' | 'w' | 'h'>,
  gap = 1
) {
  return a.x < b.x + b.w + gap && a.x + a.w + gap > b.x && a.y < b.y + b.h + gap && a.y + a.h + gap > b.y
}

/** Si el hueco por defecto está ocupado, baja el recuadro en pasos de 4 mm. */
function colocarSinSolape(block: PlantillaBloque): PlantillaBloque {
  let b = clampBlock(block)
  const others = plantilla.value.blocks
  for (let i = 0; i < 60; i++) {
    if (!others.some((o) => rectsSolapan(b, o))) return b
    const nextY = b.y + 4
    if (nextY + b.h <= PAGE_H.value - 2) {
      b = clampBlock({ ...b, y: nextY })
      continue
    }
    const nextX = b.x + 4
    if (nextX + b.w <= PAGE_W.value - 2) {
      b = clampBlock({ ...b, x: nextX, y: plantilla.value.page.marginMm.top })
      continue
    }
    break
  }
  return b
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

function snapMm(n: number) {
  const step = esEtiqueta.value ? 0.5 : 1
  return Math.round(n / step) * step
}

function nudgeSeleccionado(dx: number, dy: number) {
  if (!selected.value || props.readonly) return
  patchBlock(selected.value.id, {
    x: selected.value.x + dx,
    y: selected.value.y + dy,
  })
}

function clampBlock(b: PlantillaBloque): PlantillaBloque {
  const min = esEtiqueta.value ? 2 : 8
  const maxW = PAGE_W.value
  const maxH = PAGE_H.value
  let w = Math.max(min, b.w)
  let h = Math.max(min, b.h)
  let x = Math.max(0, Math.min(b.x, maxW - w))
  let y = Math.max(0, Math.min(b.y, maxH - h))
  w = Math.min(w, maxW - x)
  h = Math.min(h, maxH - y)
  return { ...b, x: round1(x), y: round1(y), w: round1(w), h: round1(h) }
}

function aplicarTamanoEtiqueta(widthMm: number, heightMm: number) {
  if (props.readonly || !esEtiqueta.value) return
  plantilla.value = conTamanoEtiqueta(plantilla.value, widthMm, heightMm)
  markDirty()
  void nextTick(() => fitScale())
}

function onPresetTamano(clave: string) {
  if (!clave || clave === 'custom') return
  const parsed = parseClaveTamanoEtiqueta(clave)
  if (parsed) aplicarTamanoEtiqueta(parsed.widthMm, parsed.heightMm)
}

const tamanoPresetKey = computed(() => {
  if (!esEtiqueta.value) return ''
  const w = plantilla.value.page.widthMm ?? 50
  const h = plantilla.value.page.heightMm ?? 30
  return claveTamanoEtiqueta(w, h) ?? 'custom'
})

function onWidthMmChange(raw: string) {
  const n = Number(String(raw).replace(',', '.'))
  if (!Number.isFinite(n) || n <= 0) return
  aplicarTamanoEtiqueta(n, plantilla.value.page.heightMm ?? 30)
}

function onHeightMmChange(raw: string) {
  const n = Number(String(raw).replace(',', '.'))
  if (!Number.isFinite(n) || n <= 0) return
  aplicarTamanoEtiqueta(plantilla.value.page.widthMm ?? 50, n)
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

function setProp(key: string, value: string | number | boolean) {
  if (!selected.value || props.readonly) return
  const propsMap = { ...(selected.value.props ?? {}) }
  if (value === '' || value === false) delete propsMap[key]
  else propsMap[key] = value
  patchBlockContent(selected.value.id, { props: propsMap })
}

function blockFontStyle(b: PlantillaBloque): Record<string, string> {
  if (
    b.type !== 'campo' &&
    b.type !== 'texto' &&
    b.type !== 'titulo-documento' &&
    b.type !== 'bloque-cliente' &&
    b.type !== 'bloque-meta' &&
    b.type !== 'empresa-cabecera' &&
    b.type !== 'tabla-lineas' &&
    b.type !== 'totales-iva' &&
    b.type !== 'datos-bancarios' &&
    b.type !== 'vencimientos' &&
    b.type !== 'pie'
  ) {
    return {}
  }
  const mm = Number(b.props?.fontSizeMm)
  if ((!Number.isFinite(mm) || mm <= 0) && !esEtiqueta.value) return {}
  const sizeMm = Number.isFinite(mm) && mm > 0 ? mm : 3
  const style: Record<string, string> = {
    fontSize: `${Math.max(9, sizeMm * pxPerMm.value)}px`,
    lineHeight: '1.15',
  }
  if (b.props?.fontWeight === 'bold') style.fontWeight = '700'
  if (b.props?.align === 'right') style.textAlign = 'right'
  else if (b.props?.centrado) style.textAlign = 'center'
  return style
}

function esCampoFormulario(el: EventTarget | null): boolean {
  if (!(el instanceof HTMLElement)) return false
  if (el.closest('input, textarea, select, option, [contenteditable="true"]')) {
    return true
  }
  const tag = el.tagName
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable
}

async function enfocarEditorContenido(seleccionarTodo = false) {
  await nextTick()
  const el = contenidoTextoRef.value
  if (!el) return
  el.focus()
  if (seleccionarTodo) el.select()
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
  if (e.button !== 0) return
  e.stopPropagation()
  selectedId.value = b.id
  drag.value = {
    mode: 'move',
    blockId: b.id,
    startX: e.clientX,
    startY: e.clientY,
    orig: { x: b.x, y: b.y, w: b.w, h: b.h },
    pendiente: true,
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
  const dist = Math.hypot(e.clientX - d.startX, e.clientY - d.startY)
  if (d.pendiente) {
    if (dist < 5) return
    d.pendiente = false
  }
  const dx = (e.clientX - d.startX) / pxPerMm.value
  const dy = (e.clientY - d.startY) / pxPerMm.value
  if (d.mode === 'move') {
    patchBlock(d.blockId, { x: snapMm(d.orig.x + dx), y: snapMm(d.orig.y + dy) })
  } else {
    patchBlock(d.blockId, { w: snapMm(d.orig.w + dx), h: snapMm(d.orig.h + dy) })
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
  if (esCampoFormulario(document.activeElement)) return
  if (e.key === 'Delete' && selectedId.value) {
    e.preventDefault()
    quitarBloque(selectedId.value)
    return
  }
  if (!selectedId.value) return
  const step = e.shiftKey ? 5 : 1
  if (e.key === 'ArrowLeft') {
    e.preventDefault()
    nudgeSeleccionado(-step, 0)
  } else if (e.key === 'ArrowRight') {
    e.preventDefault()
    nudgeSeleccionado(step, 0)
  } else if (e.key === 'ArrowUp') {
    e.preventDefault()
    nudgeSeleccionado(0, -step)
  } else if (e.key === 'ArrowDown') {
    e.preventDefault()
    nudgeSeleccionado(0, step)
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
  const available = Math.max(280, el.clientWidth - 24)
  const availableH = Math.max(160, el.clientHeight - 16)
  const w = PAGE_W.value || 210
  const h = PAGE_H.value || 30
  if (esEtiqueta.value) {
    // Tamaño real en pantalla (~1 CSS mm). Solo reducir si no cabe en el panel.
    const real = FOLIO_PX_PER_MM
    const fit = Math.min(available / w, availableH / h)
    pxPerMm.value = Math.min(real, Math.max(1.5, fit))
  } else {
    pxPerMm.value = Math.min(4.2, Math.max(2.0, available / w))
  }
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
    void nextTick(() => fitScale())
  }
)

watch(
  () => [PAGE_W.value, PAGE_H.value],
  () => {
    void nextTick(() => fitScale())
  }
)

watch(selectedId, (id, prev) => {
  if (!id || id === prev) return
  const b = plantilla.value.blocks.find((x) => x.id === id)
  if (b?.type === 'texto' || b?.type === 'titulo-documento') void enfocarEditorContenido(false)
})

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
      <span class="hint">
        <template v-if="esEtiqueta">
          Etiqueta {{ PAGE_W }}×{{ PAGE_H }} mm · arrastre recuadros · Supr para borrar
        </template>
        <template v-else>
          Arrastre para mover · esquina para tamaño · flechas 1 mm (Mayús 5 mm) · Supr borra
        </template>
      </span>
      <div v-if="esEtiqueta" class="tamano-etiq">
        <label>
          Formato
          <select
            :value="tamanoPresetKey"
            :disabled="readonly"
            title="Tamaño de etiqueta"
            @change="onPresetTamano(($event.target as HTMLSelectElement).value)"
          >
            <option
              v-for="t in ETIQUETA_TAMANOS_MM"
              :key="t.label"
              :value="`${t.widthMm}x${t.heightMm}`"
            >
              {{ t.label }}
            </option>
            <option v-if="tamanoPresetKey === 'custom'" value="custom">
              Personalizado ({{ PAGE_W }}×{{ PAGE_H }} mm)
            </option>
          </select>
        </label>
        <label>
          Ancho
          <input
            type="number"
            min="10"
            max="200"
            step="1"
            :value="plantilla.page.widthMm ?? 50"
            :disabled="readonly"
            @change="onWidthMmChange(($event.target as HTMLInputElement).value)"
          />
          mm
        </label>
        <label>
          Alto
          <input
            type="number"
            min="10"
            max="200"
            step="1"
            :value="plantilla.page.heightMm ?? 30"
            :disabled="readonly"
            @change="onHeightMmChange(($event.target as HTMLInputElement).value)"
          />
          mm
        </label>
      </div>
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
        <template v-if="esEtiqueta">
          <button
            v-for="item in catalogo"
            :key="item.type"
            type="button"
            class="pal-btn"
            :disabled="readonly || !puedeAnadir(item.type)"
            :title="item.unico && !puedeAnadir(item.type) ? 'Ya existe en la plantilla' : item.nombre"
            @click="anadirBloque(item.type)"
          >
            + {{ item.nombre }}
          </button>
        </template>
        <template v-else>
          <button
            type="button"
            class="pal-btn pal-btn-important"
            :disabled="readonly || tieneCampoNumeroDocumento"
            :title="
              tieneCampoNumeroDocumento
                ? 'La plantilla ya contiene el número del documento'
                : 'Añade FACTURA/ALBARÁN y su número alineados'
            "
            @click="anadirNumeroDocumento"
          >
            + Nº de documento
          </button>
          <div v-for="grupo in catalogoAgrupado" :key="grupo.id" class="pal-grupo">
            <h5>{{ grupo.titulo }}</h5>
            <button
              v-for="item in grupo.items"
              :key="item.type"
              type="button"
              class="pal-btn"
              :disabled="readonly || !puedeAnadir(item.type)"
              :title="item.unico && !puedeAnadir(item.type) ? 'Ya está en la plantilla' : item.nombre"
              @click="anadirBloque(item.type)"
            >
              + {{ item.nombre }}
            </button>
          </div>
        </template>
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
            @dblclick="
              (b.type === 'texto' || b.type === 'titulo-documento') && enfocarEditorContenido(true)
            "
          >
            <span class="block-label" :style="blockFontStyle(b)">{{ etiquetaBloqueCanvas(b) }}</span>
            <span v-if="selectedId === b.id" class="block-size">{{ b.w }}×{{ b.h }} mm</span>
            <span v-if="b.bind?.length && esEtiqueta" class="block-bind" :style="blockFontStyle(b)">
              {{ b.bind.join(' · ') }}
            </span>
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

      <aside class="inspector" @keydown.stop @keyup.stop>
        <template v-if="selected">
          <h4>Contenido</h4>
          <p class="tipo-tag">{{ etiquetaTipoBloque(selected.type) }}</p>

          <div class="geom">
            <label>Izq. <input type="number" step="0.5" :value="selected.x" :disabled="readonly" title="Distancia desde la izquierda (mm)" @change="onGeomInput('x', ($event.target as HTMLInputElement).value)" /></label>
            <label>Arriba <input type="number" step="0.5" :value="selected.y" :disabled="readonly" title="Distancia desde arriba (mm)" @change="onGeomInput('y', ($event.target as HTMLInputElement).value)" /></label>
            <label>Ancho <input type="number" step="0.5" :value="selected.w" :disabled="readonly" title="Ancho (mm)" @change="onGeomInput('w', ($event.target as HTMLInputElement).value)" /></label>
            <label>Alto <input type="number" step="0.5" :value="selected.h" :disabled="readonly" title="Alto (mm)" @change="onGeomInput('h', ($event.target as HTMLInputElement).value)" /></label>
          </div>
          <div v-if="!readonly && !esEtiqueta" class="nudge">
            <span>Mover 1 mm</span>
            <button type="button" title="Arriba" @click="nudgeSeleccionado(0, -1)">↑</button>
            <button type="button" title="Izquierda" @click="nudgeSeleccionado(-1, 0)">←</button>
            <button type="button" title="Derecha" @click="nudgeSeleccionado(1, 0)">→</button>
            <button type="button" title="Abajo" @click="nudgeSeleccionado(0, 1)">↓</button>
          </div>

          <label class="field" for="bloque-texto-libre">
            {{
              selected.type === 'texto'
                ? 'Texto a imprimir'
                : selected.type === 'titulo-documento'
                  ? 'Texto del título (impresión)'
                  : 'Nombre del recuadro'
            }}
            <textarea
              v-if="selected.type === 'texto' || selected.type === 'titulo-documento'"
              id="bloque-texto-libre"
              ref="contenidoTextoRef"
              :value="labelEditLocal"
              :rows="selected.type === 'titulo-documento' ? 2 : 4"
              :disabled="readonly"
              spellcheck="false"
              :placeholder="
                selected.type === 'titulo-documento'
                  ? 'Ej. FACTURA DE CRÉDITO (una línea; ensanche el recuadro si no cabe)'
                  : 'Escriba aquí el texto (p. ej. Oferta, IVA incl.)'
              "
              @input="commitLabelEdit(($event.target as HTMLTextAreaElement).value)"
              @keydown.stop
            />
            <input
              v-else
              id="bloque-texto-libre"
              type="text"
              :value="labelEditLocal"
              :disabled="readonly"
              placeholder="Nombre interno del recuadro"
              @input="commitLabelEdit(($event.target as HTMLInputElement).value)"
              @keydown.stop
            />
            <span v-if="selected.type === 'texto' || selected.type === 'titulo-documento'" class="help">
              Escriba en el panel derecho (doble clic en el recuadro). Las flechas mueven el recuadro
              solo si el cursor no está aquí.
            </span>
          </label>

          <div v-if="muestraBarras" class="tipografia">
            <h5>Código de barras</h5>
            <label class="field">
              Tamaño
              <select
                :value="barcodePresetKey"
                :disabled="readonly"
                @change="aplicarPresetBarras(($event.target as HTMLSelectElement).value)"
              >
                <option
                  v-for="p in BARCODE_PRESETS"
                  :key="p.id"
                  :value="p.id"
                >
                  {{ p.label }} ({{ p.w }}×{{ p.h }} mm)
                </option>
                <option v-if="barcodePresetKey === 'custom'" value="custom">
                  Personalizado ({{ selected.w }}×{{ selected.h }} mm)
                </option>
              </select>
              <span class="help">También puede arrastrar la esquina del recuadro (W × H).</span>
            </label>
            <label class="field">
              Grosor de barras
              <div class="font-row">
                <input
                  v-model.number="barcodeModuleWidth"
                  type="number"
                  min="0.6"
                  max="3"
                  step="0.1"
                  :disabled="readonly"
                />
                <select
                  :value="barcodeModuleWidth"
                  :disabled="readonly"
                  @change="barcodeModuleWidth = Number(($event.target as HTMLSelectElement).value)"
                >
                  <option :value="0.8">Fino</option>
                  <option :value="1.2">Normal</option>
                  <option :value="1.6">Medio</option>
                  <option :value="2">Grueso</option>
                </select>
              </div>
            </label>
            <label class="field check">
              <input v-model="barcodeShowValue" type="checkbox" :disabled="readonly" />
              Mostrar dígitos debajo
            </label>
          </div>

          <div v-if="muestraTipografia" class="tipografia">
            <h5>Tipografía</h5>
            <label class="field">
              Tamaño (mm)
              <div class="font-row">
                <input
                  v-model.number="fontSizeMm"
                  type="number"
                  min="1"
                  max="20"
                  step="0.5"
                  :disabled="readonly"
                />
                <select
                  :value="fontSizeMm"
                  :disabled="readonly"
                  title="Presets"
                  @change="fontSizeMm = Number(($event.target as HTMLSelectElement).value)"
                >
                  <option :value="2">2</option>
                  <option :value="2.5">2.5</option>
                  <option :value="3">3</option>
                  <option :value="3.5">3.5</option>
                  <option :value="4">4</option>
                  <option :value="5">5</option>
                  <option :value="6">6</option>
                  <option :value="8">8</option>
                  <option :value="10">10</option>
                </select>
              </div>
              <span class="help">En etiqueta: mm reales de impresión (p. ej. 4–6 para precio).</span>
            </label>
            <label class="field">
              Grosor
              <select v-model="fontWeight" :disabled="readonly">
                <option value="normal">Normal</option>
                <option value="bold">Negrita</option>
              </select>
            </label>
            <label class="field">
              Alineación
              <select v-model="textAlign" :disabled="readonly">
                <option value="left">Izquierda</option>
                <option value="center">Centro</option>
                <option value="right">Derecha</option>
              </select>
            </label>
          </div>

          <label
            v-if="selected.type === 'titulo-documento' || selected.type === 'totales-iva'"
            class="field"
          >
            {{ selected.type === 'totales-iva' ? 'Etiqueta total' : 'Subtítulo opcional (2ª línea)' }}
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
              placeholder="Vacío = solo el título de arriba"
              @keydown.stop
            />
            <span v-if="selected.type === 'titulo-documento'" class="help">
              Si rellena esto, en la factura saldrá debajo del título principal. Déjelo vacío para una
              sola línea.
            </span>
          </label>

          <label v-if="selected.type === 'pie'" class="field">
            Plantilla pie
            <textarea v-model="piePlantilla" rows="3" :readonly="readonly" spellcheck="false" />
            <span class="help">Use variables como empresa.razonSocial entre dobles llaves.</span>
          </label>

          <div v-if="selected.type === 'bloque-cliente'" class="campos-cliente">
            <h5>Datos que se imprimen</h5>
            <label v-for="campo in CAMPOS_CLIENTE" :key="campo.path" class="field check">
              <input
                type="checkbox"
                :checked="campoClienteActivo(campo.path)"
                :disabled="readonly"
                @change="
                  toggleCampoCliente(campo.path, ($event.target as HTMLInputElement).checked)
                "
              />
              {{ campo.label }}
            </label>
            <span class="help">Desmarque los datos que no quiera mostrar.</span>
          </div>

          <label
            v-if="
              selected.type !== 'emblema' &&
              selected.type !== 'texto' &&
              selected.type !== 'qr-verifactu' &&
              selected.type !== 'bloque-cliente'
            "
            class="field"
          >
            Campos enlazados (uno por línea)
            <textarea v-model="bindText" rows="5" :readonly="readonly" spellcheck="false" />
          </label>

          <div
            v-if="
              selected.type !== 'emblema' &&
              selected.type !== 'texto' &&
              selected.type !== 'qr-verifactu' &&
              selected.type !== 'bloque-cliente' &&
              !readonly
            "
            class="sugeridos"
          >
            <span class="help">Añadir sugerido:</span>
            <select @change="addBindSugerido(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''">
              <option value="">—</option>
              <option v-for="c in bindsSugeridos" :key="c" :value="c">{{ c }}</option>
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

.tamano-etiq {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}

.tamano-etiq label {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  font-weight: 600;
  color: #475569;
}

.tamano-etiq input {
  width: 3.5rem;
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.tamano-etiq select {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  min-width: 8.5rem;
  max-width: 12rem;
}

.hint {
  flex: 1;
  min-width: 12rem;
}

.workspace {
  display: grid;
  grid-template-columns: 10.5rem minmax(0, 1fr) minmax(12rem, 13.5rem);
  gap: 0.5rem;
  align-items: start;
}

.palette,
.inspector {
  user-select: text;
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

.pal-grupo {
  margin-bottom: 0.45rem;
}

.pal-grupo h5 {
  margin: 0 0 0.2rem;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #64748b;
}

.nudge {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.2rem;
  margin: 0 0 0.55rem;
  font-size: 0.68rem;
  color: #64748b;
}

.nudge button {
  width: 1.7rem;
  height: 1.7rem;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font-size: 0.85rem;
  line-height: 1;
}

.nudge button:hover {
  border-color: #2563eb;
  color: #1d4ed8;
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

.pal-btn-important {
  border-color: #60a5fa;
  background: #eff6ff;
  color: #1d4ed8;
  font-weight: 700;
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

.field textarea {
  min-height: 5.5rem;
  resize: vertical;
  background: #fff;
  color: #0f172a;
  font-weight: 500;
  font-size: 0.88rem;
  line-height: 1.35;
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

.tipografia {
  margin: 0.35rem 0 0.55rem;
  padding: 0.45rem 0.5rem;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
}

.tipografia h5 {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  color: #0f172a;
}

.campos-cliente {
  margin: 0.35rem 0 0.55rem;
  padding: 0.45rem 0.5rem;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  background: #eff6ff;
}

.campos-cliente h5 {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  color: #1e3a8a;
}

.campos-cliente .field {
  margin-bottom: 0.25rem;
}

.font-row {
  display: grid;
  grid-template-columns: 1fr 4.2rem;
  gap: 0.25rem;
}

.field.check {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  font-weight: 600;
}

.field.check input {
  width: auto;
  min-width: 0;
}

.block-bind {
  font-weight: 600;
  color: #334155;
  overflow: hidden;
  word-break: break-word;
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
  white-space: pre-wrap;
  word-break: break-word;
}

.type-titulo-documento .block-label {
  font-size: 0.72rem;
}

.block-size {
  font-size: 0.62rem;
  font-weight: 600;
  color: #2563eb;
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
  width: 14px;
  height: 14px;
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
