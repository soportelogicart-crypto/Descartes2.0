<script setup lang="ts">
/**
 * Impresión rápida desde ficha Artículo (005 / T026 / US1).
 * EAN + copias + preview + imprimir. No usa la cola.
 */
import { computed, nextTick, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/extractApiError'
import {
  avisoFlagsEtiquetas,
  cargarFlagsEtiquetasTienda,
  FLAGS_ETIQUETAS_DEFAULT,
} from '@/composables/useEtiquetasTiendaFlags'
import {
  cargarOpcionesImpresionEtiqueta,
  imprimirArticuloRapido,
} from '@/composables/useImpresionEtiquetas'
import { renderEtiquetaDesdeArticulo } from '@/composables/useRenderEtiqueta'
import {
  listarPlantillasEtiqueta,
  resolverPlantillaEtiqueta,
} from '@/composables/usePlantillaEtiqueta'
import type { EtiquetasTiendaFlags } from '@/types/etiquetas'

const props = defineProps<{
  open: boolean
  codigo: string
  descripcion?: string
  precio?: number | null
  empresa?: string | null
  puestoCodigo?: string | null
  /** Permiso etiquetas.editar */
  puedeImprimir?: boolean
}>()

const emit = defineEmits<{
  cerrar: []
  impresa: [message: string]
}>()

const loading = ref(false)
const imprimiendo = ref(false)
const error = ref<string | null>(null)
const eans = ref<string[]>([])
const eanSel = ref('')
const copias = ref(1)
const flags = ref<EtiquetasTiendaFlags>({ ...FLAGS_ETIQUETAS_DEFAULT })
const formatoOpciones = ref<
  { id: number; nombre: string; label: string; widthMm: number; heightMm: number; activa: boolean }[]
>([])
const plantillaId = ref<number | null>(null)
const impresoraNombre = ref('')

const html = ref('')
const pageWidthMm = ref(50)
const pageHeightMm = ref(30)
const scale = ref(4)
const stageRef = ref<HTMLElement | null>(null)

/** Exige EAN (solo propios o tienda sin «sin EAN»). */
const exigeEan = computed(
  () => flags.value.impEtiquetasSoloEansPropios || !flags.value.impEtiquetasSinEans
)

const avisoFlags = computed(() =>
  avisoFlagsEtiquetas(flags.value, Boolean(String(eanSel.value ?? '').trim()))
)

const puedeConfirmar = computed(() => {
  if (!props.puedeImprimir) return false
  if (exigeEan.value && !String(eanSel.value ?? '').trim()) return false
  if (
    flags.value.impEtiquetasSoloEansPropios &&
    eans.value.length > 0 &&
    !eans.value.includes(String(eanSel.value ?? '').trim())
  ) {
    return false
  }
  return true
})

const iframeStyle = computed(() => ({
  width: `${pageWidthMm.value}mm`,
  height: `${pageHeightMm.value}mm`,
  transform: `scale(${scale.value})`,
  transformOrigin: 'top left',
}))

const stageSize = computed(() => ({
  width: `${pageWidthMm.value * scale.value}mm`,
  height: `${pageHeightMm.value * scale.value}mm`,
}))

function fitScale() {
  const el = stageRef.value?.parentElement
  if (!el) return
  const availW = Math.max(180, el.clientWidth - 24)
  const availH = Math.max(120, el.clientHeight - 12)
  const mmToPx = 96 / 25.4
  const s = Math.min(
    7,
    Math.max(2.2, Math.min(availW / (pageWidthMm.value * mmToPx), availH / (pageHeightMm.value * mmToPx)) * 0.9)
  )
  scale.value = s
}

async function cargarEans() {
  const codigo = String(props.codigo ?? '').trim()
  if (!codigo) {
    eans.value = []
    eanSel.value = ''
    return
  }
  try {
    const { data } = await api.get(
      `/api/mantenimiento/articulos/${encodeURIComponent(codigo)}/eans`
    )
    const list = ((data.items ?? []) as { ean?: string }[])
      .map((i) => String(i.ean ?? '').trim())
      .filter(Boolean)
    eans.value = list
    eanSel.value = list[0] ?? ''
  } catch {
    eans.value = []
    eanSel.value = ''
  }
}

async function cargarOpciones() {
  const data = await cargarOpcionesImpresionEtiqueta({
    puestoCodigo: props.puestoCodigo,
    empresa: props.empresa,
  })
  formatoOpciones.value = data.opciones
  if (plantillaId.value == null || !data.opciones.some((o) => o.id === plantillaId.value)) {
    plantillaId.value = data.plantillaIdDefault
  }
  impresoraNombre.value = data.impresoraNombre
}

async function refrescarPreview() {
  const codigo = String(props.codigo ?? '').trim()
  if (!codigo || !props.open) {
    html.value = ''
    return
  }
  try {
    const lista = await listarPlantillasEtiqueta(props.empresa)
    const plantilla = resolverPlantillaEtiqueta(lista, {
      plantillaId: plantillaId.value != null ? Number(plantillaId.value) || null : null,
    })
    const prep = await renderEtiquetaDesdeArticulo(
      {
        codigo,
        descripcion: props.descripcion,
        ean: eanSel.value || null,
        precio: props.precio,
      },
      { empresa: props.empresa, plantilla }
    )
    html.value = prep.html
    pageWidthMm.value = prep.pageWidthMm
    pageHeightMm.value = prep.pageHeightMm
    await nextTick()
    fitScale()
  } catch (e: unknown) {
    html.value = ''
    error.value = extractApiError(e, 'No se pudo generar el preview')
  }
}

async function init() {
  loading.value = true
  error.value = null
  copias.value = 1
  try {
    flags.value = await cargarFlagsEtiquetasTienda(props.empresa)
    await Promise.all([cargarEans(), cargarOpciones()])
    if (exigeEan.value && !eanSel.value && eans.value[0]) {
      eanSel.value = eans.value[0]
    }
    await refrescarPreview()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo abrir impresión de etiquetas')
  } finally {
    loading.value = false
  }
}

async function onImprimir() {
  if (!props.puedeImprimir) {
    error.value = 'Sin permiso para imprimir etiquetas'
    return
  }
  const codigo = String(props.codigo ?? '').trim()
  if (!codigo) {
    error.value = 'Artículo sin código'
    return
  }
  if (!props.puestoCodigo) {
    error.value = 'Configure el puesto de este equipo'
    return
  }

  imprimiendo.value = true
  error.value = null
  try {
    const res = await imprimirArticuloRapido(
      {
        codigo,
        descripcion: props.descripcion,
        ean: eanSel.value || null,
        precio: props.precio,
      },
      {
        puestoCodigo: props.puestoCodigo,
        empresa: props.empresa,
        plantillaId: plantillaId.value != null ? Number(plantillaId.value) || null : null,
        copies: copias.value,
        eansPropios: eans.value,
      }
    )
    emit('impresa', res.message)
    emit('cerrar')
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir')
  } finally {
    imprimiendo.value = false
  }
}

watch(
  () => props.open,
  (v) => {
    if (v) void init()
    else {
      html.value = ''
      error.value = null
    }
  }
)

watch([eanSel, plantillaId], () => {
  if (props.open && !loading.value) void refrescarPreview()
})
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="overlay" @click.self="emit('cerrar')">
      <div class="modal" role="dialog" aria-modal="true" aria-label="Imprimir etiquetas">
        <header class="head">
          <div>
            <h3>Imprimir etiquetas</h3>
            <p class="meta">
              {{ codigo }}
              <template v-if="descripcion"> · {{ descripcion }}</template>
            </p>
          </div>
          <span class="imp" :title="impresoraNombre || 'Sin impresora'">
            {{ impresoraNombre || 'Sin impresora de puesto' }}
          </span>
        </header>

        <div class="body">
          <p v-if="loading" class="msg">Cargando…</p>
          <template v-else>
            <div class="form-row">
              <label>
                EAN
                <select v-model="eanSel" :disabled="imprimiendo">
                  <option v-if="!exigeEan" value="">
                    {{ eans.length ? '— Sin EAN (código artículo) —' : 'Sin EAN en ficha' }}
                  </option>
                  <option v-if="exigeEan && eans.length === 0" value="" disabled>
                    Sin EAN — no se puede imprimir
                  </option>
                  <option v-for="e in eans" :key="e" :value="e">{{ e }}</option>
                </select>
              </label>
              <label class="copias">
                Copias
                <input
                  v-model.number="copias"
                  type="number"
                  min="1"
                  max="500"
                  step="1"
                  :disabled="imprimiendo"
                />
              </label>
              <label class="formato">
                Formato
                <select v-model="plantillaId" :disabled="imprimiendo || formatoOpciones.length === 0">
                  <option v-if="formatoOpciones.length === 0" :value="null">Esqueleto</option>
                  <option v-for="o in formatoOpciones" :key="o.id" :value="o.id">{{ o.label }}</option>
                </select>
              </label>
            </div>

            <p v-if="avisoFlags" class="aviso">{{ avisoFlags }}</p>
            <p v-if="error" class="error">{{ error }}</p>

            <div ref="stageRef" class="stage-wrap">
              <div v-if="html" class="stage" :style="stageSize">
                <iframe class="folio" :srcdoc="html" :style="iframeStyle" title="Preview etiqueta" />
              </div>
              <p v-else class="msg">Sin preview</p>
            </div>
          </template>
        </div>

        <footer class="foot">
          <button type="button" class="btn" :disabled="imprimiendo" @click="emit('cerrar')">
            Cerrar
          </button>
          <button
            type="button"
            class="btn primary"
            :disabled="imprimiendo || loading || !puedeConfirmar"
            @click="onImprimir"
          >
            {{ imprimiendo ? 'Imprimiendo…' : `Imprimir (${Math.max(1, Math.trunc(copias) || 1)})` }}
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
  width: min(560px, 96vw);
  max-height: min(90vh, 760px);
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
  gap: 0.5rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid #e2e8f0;
  background: #f8fafc;
}

.head h3 {
  margin: 0 0 0.2rem;
  font-size: 1rem;
}

.meta {
  margin: 0;
  font-size: 0.8rem;
  color: #64748b;
  max-width: 28rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.imp {
  font-size: 0.78rem;
  color: #334155;
  max-width: 12rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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

.form-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 0.85rem;
  align-items: flex-end;
}

.form-row label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #475569;
  flex: 1 1 8rem;
}

.form-row .copias {
  flex: 0 0 5rem;
}

.form-row .formato {
  flex: 1 1 12rem;
}

.form-row select,
.form-row input {
  padding: 0.4rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 400;
}

.stage-wrap {
  flex: 1 1 auto;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 160px;
  padding: 0.65rem;
  background: #e2e8f0;
  border-radius: 6px;
}

.stage {
  position: relative;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 4px 14px rgba(15, 23, 42, 0.16);
}

.folio {
  border: 0;
  display: block;
  background: #fff;
  pointer-events: none;
}

.msg {
  margin: 0;
  color: #64748b;
  text-align: center;
}

.error {
  margin: 0;
  color: #b91c1c;
  font-size: 0.88rem;
}

.aviso {
  margin: 0;
  color: #b45309;
  font-size: 0.85rem;
  background: #fffbeb;
  border: 1px solid #fcd34d;
  border-radius: 4px;
  padding: 0.4rem 0.55rem;
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
