<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import {
  filasDesdeCsv,
  guessColumnMap,
  parseCsvText,
  type ColumnMapKey,
  type CsvParseResult,
  type FilaImportCompra,
} from '@/utils/compraDocumentoImport'
import {
  previewImportAlbaranCompra,
  type AlbaranCompraImportPreviewLinea,
} from '@/api/compras'
import { extractApiError } from '@/composables/extractApiError'

const props = defineProps<{
  open: boolean
  proveedor: string
  empresa: string
  almacen: number
  /** Filas ya parseadas (CSV) o propuestas (PDF). */
  filasIniciales?: FilaImportCompra[]
  csvRaw?: string
  origen?: 'csv' | 'pdf' | 'manual'
  previewInicial?: AlbaranCompraImportPreviewLinea[]
  avisosIniciales?: string[]
}>()

const emit = defineEmits<{
  close: []
  confirm: [lineas: AlbaranCompraImportPreviewLinea[]]
}>()

const parsed = ref<CsvParseResult | null>(null)
const columnMap = ref<Partial<Record<ColumnMapKey, number>>>({})
const previewLineas = ref<AlbaranCompraImportPreviewLinea[]>([])
const avisos = ref<string[]>([])
const loading = ref(false)
const error = ref<string | null>(null)

const columnKeys: { key: ColumnMapKey; label: string }[] = [
  { key: 'codigo', label: 'Código / ref.' },
  { key: 'ean', label: 'EAN' },
  { key: 'descripcion', label: 'Descripción' },
  { key: 'cantidad', label: 'Cantidad' },
  { key: 'precio', label: 'Precio' },
  { key: 'dto', label: 'Dto' },
]

const filasLocales = computed(() => {
  if (parsed.value && Object.keys(columnMap.value).length) {
    return filasDesdeCsv(parsed.value, columnMap.value)
  }
  return props.filasIniciales ?? []
})

watch(
  () => props.open,
  (v) => {
    if (!v) return
    error.value = null
    avisos.value = []
    previewLineas.value = []
    if (props.csvRaw) {
      parsed.value = parseCsvText(props.csvRaw)
      columnMap.value = guessColumnMap(parsed.value.headers)
    } else {
      parsed.value = null
      columnMap.value = {}
    }
    if (props.previewInicial?.length) {
      previewLineas.value = [...props.previewInicial]
      avisos.value = props.avisosIniciales ?? []
    } else if (props.filasIniciales?.length && !props.csvRaw) {
      void cargarPreview(props.filasIniciales)
    }
  }
)

async function cargarPreview(filas: FilaImportCompra[]) {
  if (!filas.length) {
    error.value = 'No hay filas para importar'
    return
  }
  loading.value = true
  error.value = null
  try {
    const res = await previewImportAlbaranCompra({
      proveedor: props.proveedor,
      empresa: props.empresa,
      almacen: props.almacen,
      filas,
    })
    previewLineas.value = res.lineas
    avisos.value = res.avisos ?? []
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo validar la importación')
  } finally {
    loading.value = false
  }
}

async function onPreview() {
  await cargarPreview(filasLocales.value)
}

function onConfirmar() {
  if (!previewLineas.value.length) return
  emit('confirm', previewLineas.value)
  emit('close')
}
</script>

<template>
  <div v-if="open" class="modal-backdrop" @click.self="emit('close')">
    <div class="modal" role="dialog" aria-labelledby="import-title">
      <header class="modal-head">
        <h2 id="import-title">Importar líneas del proveedor</h2>
        <button type="button" class="close" @click="emit('close')">×</button>
      </header>

      <p class="hint">
        Proveedor <strong>{{ proveedor }}</strong> · revise antes de volcar al albarán (no se guarda hasta
        pulsar Guardar en la ficha).
      </p>

      <section v-if="parsed && parsed.headers.length" class="map-section">
        <h3>Columnas del CSV</h3>
        <div class="map-grid">
          <label v-for="col in columnKeys" :key="col.key">
            <span>{{ col.label }}</span>
            <select v-model.number="columnMap[col.key]">
              <option :value="undefined">—</option>
              <option v-for="(h, idx) in parsed.headers" :key="idx" :value="idx">
                {{ h || `Col ${idx + 1}` }}
              </option>
            </select>
          </label>
        </div>
        <button type="button" class="btn sec" :disabled="loading" @click="onPreview">
          {{ loading ? 'Validando…' : 'Validar con la base de datos' }}
        </button>
      </section>

      <section v-else-if="origen === 'pdf'" class="map-section">
        <p v-if="previewLineas.length">
          Líneas propuestas desde el PDF. Compruebe cantidades y precios antes de volcar.
        </p>
        <p v-else>Líneas extraídas del PDF (texto digital). Pulse validar si faltan artículos.</p>
        <button
          v-if="!previewLineas.length"
          type="button"
          class="btn sec"
          :disabled="loading"
          @click="onPreview"
        >
          {{ loading ? 'Validando…' : 'Validar artículos' }}
        </button>
      </section>

      <p v-if="error" class="err">{{ error }}</p>
      <ul v-if="avisos.length" class="avisos">
        <li v-for="(a, i) in avisos" :key="i">{{ a }}</li>
      </ul>

      <div v-if="previewLineas.length" class="preview-wrap">
        <table>
          <thead>
            <tr>
              <th>Leído</th>
              <th>Artículo</th>
              <th>Descripción</th>
              <th class="num">Cant.</th>
              <th class="num">Precio</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(l, idx) in previewLineas" :key="idx" :class="{ warn: l.estado !== 'ok' }">
              <td>{{ l.codigoLeido }}</td>
              <td>{{ l.articulo || '—' }}</td>
              <td>{{ l.descripcion || '' }}</td>
              <td class="num">{{ l.cantidad }}</td>
              <td class="num">{{ l.precio }}</td>
              <td>{{ l.estadoLabel }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <footer class="modal-foot">
        <button type="button" class="btn sec" @click="emit('close')">Cancelar</button>
        <button
          type="button"
          class="btn pri"
          :disabled="!previewLineas.some((l) => l.estado === 'ok')"
          @click="onConfirmar"
        >
          Volcar líneas al albarán
        </button>
      </footer>
    </div>
  </div>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 2000;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  padding: 1rem;
}
.modal {
  width: min(920px, 100%);
  max-height: min(90vh, 720px);
  background: #fff;
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  box-shadow: 0 16px 48px rgba(0, 0, 0, 0.18);
}
.modal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
}
.modal-head h2 {
  margin: 0;
  font-size: 1.05rem;
}
.close {
  border: none;
  background: transparent;
  font-size: 1.4rem;
  cursor: pointer;
  color: #64748b;
}
.hint {
  margin: 0;
  padding: 0.65rem 1rem;
  font-size: 0.85rem;
  color: #475569;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.map-section {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
}
.map-section h3 {
  margin: 0 0 0.5rem;
  font-size: 0.9rem;
}
.map-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 0.5rem;
  margin-bottom: 0.65rem;
}
.map-grid label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
  color: #64748b;
}
.map-grid select {
  font-size: 0.85rem;
  padding: 0.25rem;
}
.preview-wrap {
  flex: 1;
  overflow: auto;
  padding: 0 1rem;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.35rem 0.4rem;
  text-align: left;
}
.num {
  text-align: right;
}
tr.warn {
  background: #fff7ed;
}
.avisos {
  margin: 0.5rem 1rem;
  padding-left: 1.2rem;
  font-size: 0.82rem;
  color: #b45309;
}
.err {
  margin: 0.5rem 1rem;
  color: #b91c1c;
  font-size: 0.85rem;
}
.modal-foot {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-top: 1px solid #e2e8f0;
}
.btn {
  border-radius: 8px;
  padding: 0.45rem 0.85rem;
  font-size: 0.88rem;
  cursor: pointer;
  border: 1px solid #cbd5e1;
  background: #fff;
}
.btn.pri {
  background: #2563eb;
  border-color: #2563eb;
  color: #fff;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
