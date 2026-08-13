<script setup lang="ts">
/**
 * Preview WYSIWYG de etiqueta antes de imprimir (005 / T024 / US4).
 * Usa el mismo HTML que Electron `printLabel` (renderEtiquetaDesdeCola).
 */
import { computed, nextTick, ref, watch } from 'vue'
import type { EtiquetaColaLinea } from '@/types/etiquetas'
import { renderEtiquetaDesdeCola } from '@/composables/useRenderEtiqueta'
import {
  listarPlantillasEtiqueta,
  resolverPlantillaEtiqueta,
} from '@/composables/usePlantillaEtiqueta'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import { extractApiError } from '@/composables/extractApiError'

const props = withDefaults(
  defineProps<{
    open: boolean
    lineas: EtiquetaColaLinea[]
    plantillaId?: number | null
    empresa?: string | null
    impresoraNombre?: string
    imprimiendo?: boolean
    /** Muestra botón Imprimir (permiso editar). */
    permitirImprimir?: boolean
  }>(),
  {
    plantillaId: null,
    empresa: null,
    impresoraNombre: '',
    imprimiendo: false,
    permitirImprimir: true,
  }
)

const emit = defineEmits<{
  cerrar: []
  imprimir: []
}>()

const idx = ref(0)
const loading = ref(false)
const error = ref<string | null>(null)
const html = ref('')
const pageWidthMm = ref(50)
const pageHeightMm = ref(30)
const plantillaNombre = ref('')
const stageRef = ref<HTMLElement | null>(null)
const scale = ref(4)

const lineaActual = computed(() => props.lineas[idx.value] ?? null)
const total = computed(() => props.lineas.length)
const copias = computed(() => Math.max(1, Math.trunc(Number(lineaActual.value?.cantidad) || 1)))

const titulo = computed(() => {
  const l = lineaActual.value
  if (!l) return 'Vista previa etiqueta'
  return `Vista previa · ${l.articulo}`
})

const iframeStyle = computed(() => {
  const w = pageWidthMm.value
  const h = pageHeightMm.value
  const s = scale.value
  return {
    width: `${w}mm`,
    height: `${h}mm`,
    transform: `scale(${s})`,
    transformOrigin: 'top left',
  }
})

const stageSize = computed(() => ({
  width: `${pageWidthMm.value * scale.value}mm`,
  height: `${pageHeightMm.value * scale.value}mm`,
}))

function fitScale() {
  const el = stageRef.value?.parentElement
  if (!el) return
  const availW = Math.max(200, el.clientWidth - 32)
  const availH = Math.max(160, el.clientHeight - 16)
  const mmToPx = 96 / 25.4
  const needW = pageWidthMm.value * mmToPx
  const needH = pageHeightMm.value * mmToPx
  const s = Math.min(8, Math.max(2.5, Math.min(availW / needW, availH / needH) * 0.92))
  scale.value = s
}

async function cargarPreview() {
  const linea = lineaActual.value
  if (!linea || !props.open) {
    html.value = ''
    return
  }

  loading.value = true
  error.value = null
  try {
    const lista = await listarPlantillasEtiqueta(props.empresa ?? linea.empresa)
    const plantilla: DocumentoPlantilla = resolverPlantillaEtiqueta(lista, {
      plantillaId: props.plantillaId,
    })
    plantillaNombre.value = plantilla.nombre || 'Etiqueta'
    const prep = await renderEtiquetaDesdeCola(linea, {
      empresa: props.empresa ?? linea.empresa,
      plantilla,
    })
    html.value = prep.html
    pageWidthMm.value = prep.pageWidthMm
    pageHeightMm.value = prep.pageHeightMm
    await nextTick()
    fitScale()
  } catch (e: unknown) {
    html.value = ''
    error.value = extractApiError(e, 'No se pudo generar la vista previa')
  } finally {
    loading.value = false
  }
}

function anterior() {
  if (idx.value > 0) idx.value -= 1
}

function siguiente() {
  if (idx.value < total.value - 1) idx.value += 1
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      idx.value = 0
      void cargarPreview()
    } else {
      html.value = ''
      error.value = null
    }
  }
)

watch(
  () => [idx.value, props.plantillaId, props.lineas] as const,
  () => {
    if (props.open) void cargarPreview()
  }
)

watch(
  () => [pageWidthMm.value, pageHeightMm.value, props.open] as const,
  async () => {
    if (!props.open) return
    await nextTick()
    fitScale()
  }
)
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal" role="dialog" aria-modal="true" :aria-label="titulo">
        <header class="head">
          <div>
            <h3>{{ titulo }}</h3>
            <p class="meta">
              {{ plantillaNombre }} · {{ pageWidthMm }}×{{ pageHeightMm }} mm
              <template v-if="lineaActual">
                · Copias: <strong>{{ copias }}</strong>
              </template>
              <template v-if="impresoraNombre">
                · Impresora: <strong>{{ impresoraNombre }}</strong>
              </template>
            </p>
          </div>
          <div v-if="total > 1" class="nav">
            <button type="button" class="btn" :disabled="idx <= 0 || loading" @click="anterior">
              ←
            </button>
            <span class="nav-label">{{ idx + 1 }} / {{ total }}</span>
            <button
              type="button"
              class="btn"
              :disabled="idx >= total - 1 || loading"
              @click="siguiente"
            >
              →
            </button>
          </div>
        </header>

        <div class="body">
          <p v-if="loading" class="msg">Generando preview…</p>
          <p v-else-if="error" class="error">{{ error }}</p>
          <p v-else-if="!lineaActual" class="msg">No hay líneas para previsualizar.</p>
          <div v-else ref="stageRef" class="stage-wrap">
            <div class="stage" :style="stageSize">
              <iframe
                class="folio"
                :srcdoc="html"
                :style="iframeStyle"
                title="Preview etiqueta"
              />
            </div>
          </div>
          <dl v-if="lineaActual && !loading && !error" class="datos">
            <div>
              <dt>Código</dt>
              <dd>{{ lineaActual.articulo }}</dd>
            </div>
            <div>
              <dt>Descripción</dt>
              <dd>{{ lineaActual.descripcion || '—' }}</dd>
            </div>
            <div>
              <dt>EAN</dt>
              <dd>{{ lineaActual.ean || '—' }}</dd>
            </div>
            <div>
              <dt>Precio</dt>
              <dd>{{ Number(lineaActual.precio ?? 0).toFixed(2) }}</dd>
            </div>
          </dl>
        </div>

        <footer class="foot">
          <button type="button" class="btn" :disabled="imprimiendo" @click="emit('cerrar')">
            Cerrar
          </button>
          <button
            v-if="permitirImprimir"
            type="button"
            class="btn primary"
            :disabled="imprimiendo || !lineas.length || !!error || loading"
            @click="emit('imprimir')"
          >
            {{
              imprimiendo
                ? 'Imprimiendo…'
                : total > 1
                  ? `Imprimir ${total} línea(s)`
                  : 'Imprimir'
            }}
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
  z-index: 90;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, 0.5);
}

.modal {
  display: flex;
  flex-direction: column;
  width: min(640px, 96vw);
  max-height: min(92vh, 820px);
  background: #fff;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 20px 50px rgba(15, 23, 42, 0.35);
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}

.head h3 {
  margin: 0 0 0.25rem;
  font-size: 1rem;
  color: #0f172a;
}

.meta {
  margin: 0;
  font-size: 0.8rem;
  color: #64748b;
}

.nav {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.nav-label {
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  color: #334155;
  min-width: 3.5rem;
  text-align: center;
}

.body {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  overflow: auto;
  min-height: 0;
}

.stage-wrap {
  flex: 1 1 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 180px;
  padding: 0.75rem;
  background: #e2e8f0;
  border-radius: 6px;
}

.stage {
  position: relative;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 4px 16px rgba(15, 23, 42, 0.18);
}

.folio {
  border: 0;
  display: block;
  background: #fff;
  pointer-events: none;
}

.datos {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: 0.5rem 1rem;
  margin: 0;
  font-size: 0.82rem;
}

.datos div {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
}

.datos dt {
  color: #64748b;
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.02em;
}

.datos dd {
  margin: 0;
  color: #0f172a;
  word-break: break-word;
}

.msg {
  margin: 0;
  color: #64748b;
  text-align: center;
}

.error {
  margin: 0;
  color: #b91c1c;
  text-align: center;
}

.foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
}

.btn {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font: inherit;
  font-size: 0.88rem;
  color: #0f172a;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn.primary {
  border-color: #1d4ed8;
  background: #1d4ed8;
  color: #fff;
}
</style>
