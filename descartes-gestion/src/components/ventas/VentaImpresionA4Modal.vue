<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import DocumentoPlantillaPreview from '@/components/documentos/DocumentoPlantillaPreview.vue'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'
// El scoped CSS no viaja en el clon del folio: se inyecta en el HTML a imprimir.
import documentoA4Css from '@/assets/documento-a4.css?raw'

const props = defineProps<{
  open: boolean
  titulo: string
  plantilla: DocumentoPlantilla | null
  datos: DocumentoPreviewDatos | null
  impresoraNombre: string
  imprimiendo?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  imprimir: []
}>()

const previewHost = ref<HTMLElement | null>(null)

const puedeImprimir = computed(() => Boolean(props.plantilla && props.datos))

watch(
  () => props.open,
  async (v) => {
    if (v) {
      await nextTick()
      previewHost.value?.scrollTo?.(0, 0)
    }
  }
)

/** HTML del folio para impresión (Electron o ventana). */
async function capturarHtmlFolio(): Promise<string> {
  await nextTick()
  const folio = previewHost.value?.querySelector('.folio') as HTMLElement | null
  if (!folio) return ''
  const clone = folio.cloneNode(true) as HTMLElement
  clone.removeAttribute('style')
  clone.setAttribute(
    'style',
    'position:relative;width:210mm;height:297mm;overflow:hidden;box-sizing:border-box;background:#fff;margin:0;'
  )
  // Asegurar posiciones absolutas de bloques (por si el scoped CSS no viaja).
  clone.querySelectorAll('.block').forEach((el) => {
    const htmlEl = el as HTMLElement
    if (!htmlEl.style.position) htmlEl.style.position = 'absolute'
    htmlEl.style.boxSizing = 'border-box'
  })
  return `<!doctype html><html><head><meta charset="utf-8"/><title>${props.titulo}</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  html, body {
    margin: 0;
    padding: 0;
    width: 210mm;
    height: 297mm;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; }
  .folio { position: relative; width: 210mm; height: 297mm; overflow: hidden; box-sizing: border-box; background: #fff; }
  .page { position: relative; background: #fff; }
  .block { position: absolute; box-sizing: border-box; overflow: hidden; }
  table { border-collapse: collapse; width: 100%; }
  img { max-width: 100%; }
${documentoA4Css}
</style></head><body>${clone.outerHTML}</body></html>`
}

defineExpose({ capturarHtmlFolio })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal-a4" role="dialog" aria-modal="true">
        <header>
          <h3>{{ titulo }}</h3>
          <p class="meta">
            Impresora por defecto:
            <strong>{{ impresoraNombre || '— (sin asignar en el puesto)' }}</strong>
          </p>
        </header>
        <div ref="previewHost" class="preview-scroll">
          <DocumentoPlantillaPreview
            v-if="plantilla"
            :plantilla="plantilla"
            :datos="datos"
          />
          <p v-else class="warn">No hay plantilla configurada para este documento en el puesto.</p>
        </div>
        <footer>
          <button type="button" @click="emit('cerrar')">Cerrar</button>
          <button
            type="button"
            class="primary"
            :disabled="!puedeImprimir || imprimiendo"
            @click="emit('imprimir')"
          >
            {{ imprimiendo ? 'Imprimiendo…' : 'Imprimir' }}
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
  z-index: 80;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal-a4 {
  width: min(920px, 100%);
  max-height: 92vh;
  display: flex;
  flex-direction: column;
  background: #f8fafc;
  border-radius: 10px;
  box-shadow: 0 18px 50px rgba(15, 23, 42, 0.35);
  overflow: hidden;
}
header {
  padding: 0.85rem 1rem 0.5rem;
  border-bottom: 1px solid #e2e8f0;
  background: #fff;
}
header h3 {
  margin: 0;
  font-size: 1.05rem;
}
.meta {
  margin: 0.35rem 0 0;
  font-size: 0.85rem;
  color: #475569;
}
.preview-scroll {
  flex: 1;
  overflow: auto;
  padding: 0.75rem;
  background: #e2e8f0;
}
footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-top: 1px solid #e2e8f0;
  background: #fff;
}
button {
  padding: 0.4rem 0.85rem;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  background: #fff;
  cursor: pointer;
}
button.primary {
  background: #0f766e;
  border-color: #0f766e;
  color: #fff;
}
button:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.warn {
  color: #b45309;
  padding: 1rem;
}
</style>
