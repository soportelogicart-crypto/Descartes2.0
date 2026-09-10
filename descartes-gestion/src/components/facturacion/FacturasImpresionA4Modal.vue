<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import DocumentoPlantillaPreview from '@/components/documentos/DocumentoPlantillaPreview.vue'
import type { FacturaImpresionPreparada } from '@/composables/useImpresionFacturaDocumento'
// El scoped CSS no viaja en el clon del folio: se inyecta en el HTML a imprimir.
import documentoA4Css from '@/assets/documento-a4.css?raw'

const props = defineProps<{
  open: boolean
  documentos: FacturaImpresionPreparada[]
  impresoraNombre: string
  imprimiendo?: boolean
  /** Render fuera de pantalla: la previsualización vive en una ventana aparte. */
  oculto?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  imprimir: []
}>()

const previewHost = ref<HTMLElement | null>(null)

const puedeImprimir = computed(() => props.documentos.length > 0)

watch(
  () => props.open,
  async (v) => {
    if (v && !props.oculto) {
      await nextTick()
      previewHost.value?.scrollTo?.(0, 0)
    }
  }
)

/** HTML de todos los folios (una página por factura) para impresión. */
async function capturarHtmlFolio(): Promise<string> {
  await nextTick()
  const folios = Array.from(
    previewHost.value?.querySelectorAll('.folio') ?? []
  ) as HTMLElement[]
  if (folios.length === 0) return ''

  const paginas = folios
    .map((folio) => {
      const clone = folio.cloneNode(true) as HTMLElement
      clone.removeAttribute('style')
      clone.setAttribute(
        'style',
        'position:relative;width:210mm;height:297mm;overflow:hidden;box-sizing:border-box;background:#fff;margin:0;'
      )
      clone.querySelectorAll('.block').forEach((el) => {
        const htmlEl = el as HTMLElement
        if (!htmlEl.style.position) htmlEl.style.position = 'absolute'
        htmlEl.style.boxSizing = 'border-box'
      })
      return clone.outerHTML
    })
    .join('')

  const titulo = props.documentos.length === 1 ? props.documentos[0].titulo : 'Facturas'
  return `<!doctype html><html><head><meta charset="utf-8"/><title>${titulo}</title>
<style>
  @page { size: A4 portrait; margin: 0; }
  html, body {
    margin: 0;
    padding: 0;
    width: 210mm;
    background: #fff;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  body { font-family: "Segoe UI", Arial, sans-serif; color: #0f172a; }
  .folio { position: relative; width: 210mm; height: 297mm; overflow: hidden; box-sizing: border-box; background: #fff; break-after: page; }
  .folio:last-child { break-after: auto; }
  .page { position: relative; background: #fff; }
  .block { position: absolute; box-sizing: border-box; overflow: hidden; }
  table { border-collapse: collapse; width: 100%; }
  img { max-width: 100%; }
${documentoA4Css}
</style></head><body>${paginas}</body></html>`
}

defineExpose({ capturarHtmlFolio })
</script>

<template>
  <Teleport to="body">
    <div v-if="open && oculto" class="fuera-de-pantalla" aria-hidden="true">
      <div ref="previewHost">
        <DocumentoPlantillaPreview
          v-for="doc in documentos"
          :key="`${doc.clave.empresa}|${doc.clave.facturaTipo}|${doc.clave.factura}`"
          :plantilla="doc.plantilla"
          :datos="doc.datos"
        />
      </div>
    </div>

    <div v-else-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal-a4" role="dialog" aria-modal="true">
        <header>
          <h3>
            {{
              documentos.length === 1
                ? documentos[0].titulo
                : `Impresión de ${documentos.length} facturas`
            }}
          </h3>
          <p class="meta">
            Impresora por defecto:
            <strong>{{ impresoraNombre || '— (sin asignar en el puesto)' }}</strong>
          </p>
        </header>
        <div ref="previewHost" class="preview-scroll">
          <template v-if="documentos.length">
            <div v-for="doc in documentos" :key="`${doc.clave.empresa}|${doc.clave.facturaTipo}|${doc.clave.factura}`" class="doc">
              <p v-if="documentos.length > 1" class="doc-titulo">{{ doc.titulo }}</p>
              <DocumentoPlantillaPreview :plantilla="doc.plantilla" :datos="doc.datos" />
            </div>
          </template>
          <p v-else class="warn">No hay plantilla configurada para estas facturas en el puesto.</p>
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
.fuera-de-pantalla {
  position: fixed;
  top: 0;
  left: -20000px;
  /* Holgado sobre el A4: si no, el preview reduce la escala y se imprimiría
     más pequeño que 1:1 (DocumentoPlantillaPreview.fitScale). */
  width: 1000px;
  pointer-events: none;
}
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
.doc + .doc {
  margin-top: 1rem;
  border-top: 1px dashed #94a3b8;
  padding-top: 0.75rem;
}
.doc-titulo {
  margin: 0 0 0.35rem;
  font-size: 0.82rem;
  font-weight: 600;
  color: #334155;
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
