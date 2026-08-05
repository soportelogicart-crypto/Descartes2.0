<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AbcInformeReport from '@/components/AbcInformeReport.vue'
import { extractApiError } from '@/composables/extractApiError'
import { t } from '@/i18n/abc-ventas'
import type { AbcVentasResponse } from '@/types/abc-ventas'
import { loadAbcPreview } from '@/utils/abcPreviewStore'
import { exportarExcel, exportarPdf } from '@/utils/exportAbc'

const router = useRouter()

const data = ref<AbcVentasResponse | null>(null)
const titulo = ref('')
const vendedorLabel = ref('')
const returnTo = ref('home')
const exporting = ref(false)
const error = ref<string | null>(null)
const missing = ref(false)

const tr = (key: string) => t(data.value?.idioma || 'castellano', key)

onMounted(() => {
  const payload = loadAbcPreview()
  if (!payload) {
    missing.value = true
    return
  }
  data.value = payload.data
  titulo.value = payload.titulo
  vendedorLabel.value = payload.vendedorLabel
  returnTo.value = payload.returnTo || 'home'
  document.title = payload.titulo || 'Previsualización ABC'
})

function cerrar() {
  if (window.opener && !window.opener.closed) {
    window.close()
    return
  }
  router.push({ name: returnTo.value })
}

function imprimir() {
  window.print()
}

async function onExportExcel() {
  if (!data.value || exporting.value) return
  exporting.value = true
  error.value = null
  try {
    await exportarExcel(data.value)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo exportar a Excel')
  } finally {
    exporting.value = false
  }
}

async function onExportPdf() {
  if (!data.value || exporting.value) return
  exporting.value = true
  error.value = null
  try {
    await exportarPdf(data.value)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo exportar a PDF')
  } finally {
    exporting.value = false
  }
}
</script>

<template>
  <section class="preview-page page-enter">
    <div class="toolbar no-print">
      <div class="toolbar-title">
        <h2>{{ tr('previsualizacion') }}</h2>
        <span v-if="titulo" class="subtitle">{{ titulo }}</span>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="!data || exporting" @click="onExportExcel">
          {{ tr('excel') }}
        </button>
        <button type="button" class="btn" :disabled="!data || exporting" @click="onExportPdf">
          {{ tr('pdf') }}
        </button>
        <button type="button" class="btn primary" :disabled="!data" @click="imprimir">
          {{ tr('imprimir') }}
        </button>
        <button type="button" class="btn ghost" @click="cerrar">{{ tr('cerrar') }}</button>
      </div>
    </div>

    <p v-if="error" class="error no-print">{{ error }}</p>
    <p v-if="missing" class="hint no-print">{{ tr('previewVacia') }}</p>

    <AbcInformeReport
      v-if="data"
      :data="data"
      :titulo="titulo"
      :vendedor-label="vendedorLabel"
    />
  </section>
</template>

<style scoped>
.preview-page {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding: 1.1rem 1.35rem 1.75rem;
  min-height: 100vh;
  background: #e8e2d8;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1rem;
  background: rgba(250, 248, 244, 0.92);
  border: 1px solid var(--line);
  backdrop-filter: blur(6px);
  position: sticky;
  top: 0;
  z-index: 5;
}

.toolbar-title {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.toolbar-title h2 {
  margin: 0;
  font-family: var(--font-display);
  font-size: 1.25rem;
  letter-spacing: -0.02em;
}

.subtitle {
  font-size: 0.85rem;
  color: var(--muted);
}

.toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
}

.error {
  color: var(--danger);
  margin: 0;
}

.hint {
  margin: 0;
  color: var(--muted);
}

@media print {
  .preview-page {
    padding: 0;
    background: #fff;
  }

  .no-print {
    display: none !important;
  }
}
</style>
