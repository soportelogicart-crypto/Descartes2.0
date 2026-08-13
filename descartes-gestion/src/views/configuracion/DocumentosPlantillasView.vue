<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import type { DocumentoPlantilla, DocumentoTipo } from '@/config/documentos-plantillas'
import {
  DOCUMENTOS_PLANTILLAS_SCOPES,
  ETIQUETA_TAMANOS_MM,
  conTamanoEtiqueta,
  esPlantillaTicket,
  parseClaveTamanoEtiqueta,
  perteneceAlScope,
  scopeDesdeRuta,
  type DocumentosPlantillasScope,
} from '@/config/documentos-plantillas'
import {
  useDocumentoPlantillasEditables,
  type DocumentoPlantillaServidor,
} from '@/composables/useDocumentoPlantillasEditables'
import DocumentoPlantillaDesigner from '@/components/documentos/DocumentoPlantillaDesigner.vue'
import DocumentoPlantillaPreview from '@/components/documentos/DocumentoPlantillaPreview.vue'
import TicketPlantillaDesigner from '@/components/documentos/TicketPlantillaDesigner.vue'
import TicketPlantillaPreview from '@/components/documentos/TicketPlantillaPreview.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { textoTicketDesdePlantilla } from '@/config/documentos-plantillas/ticket-texto'
import { imprimirTermicaDispositivo } from '@/api/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import { listarImpresorasSistema } from '@/composables/useImpresorasSistema'

const TIPOS_TODOS: { value: DocumentoTipo; label: string }[] = [
  { value: 'albaran', label: 'Albarán' },
  { value: 'factura-contado', label: 'Factura contado' },
  { value: 'factura-credito', label: 'Factura crédito' },
  { value: 'factura-rectificativa', label: 'Rectificativa' },
  { value: 'ticket', label: 'Ticket 80 mm' },
  { value: 'etiqueta', label: 'Etiqueta' },
]

const route = useRoute()
const scope = computed<DocumentosPlantillasScope>(() => {
  const meta = route.meta.plantillasScope
  if (meta === 'albaranes' || meta === 'tickets' || meta === 'etiquetas') return meta
  return scopeDesdeRuta(route.path)
})
const scopeMeta = computed(() => DOCUMENTOS_PLANTILLAS_SCOPES[scope.value])
const TIPOS = computed(() =>
  TIPOS_TODOS.filter((t) => perteneceAlScope(t.value, scope.value))
)
const scopeUnicoTipo = computed(() => TIPOS.value.length === 1)


const puestoContexto = usePuestoContextoStore()
const {
  items,
  cargando,
  guardando,
  cargarEmpresa,
  obtener,
  guardar,
  crear,
  activar,
  eliminar,
  esqueletoPorTipo,
} = useDocumentoPlantillasEditables()

const plantillaId = ref<number | null>(null)
const draft = ref<DocumentoPlantilla | null>(null)
const dirty = ref(false)
const mensaje = ref<string | null>(null)
const errorMsg = ref<string | null>(null)
const tab = ref<'diseno' | 'preview' | 'json'>('diseno')
const silenciarDirty = ref(false)
const imprimiendo = ref(false)
const impresorasPrueba = ref<{ name: string; label: string }[]>([])
const impresoraPrueba = ref('')

async function cargarImpresorasPrueba() {
  try {
    const res = await listarImpresorasSistema()
    impresorasPrueba.value = (res.printers || []).map((p) => ({
      name: p.name,
      label: p.displayName || p.name,
    }))
    if (!impresoraPrueba.value && impresorasPrueba.value.length) {
      const def = res.printers.find((p) => p.isDefault)
      impresoraPrueba.value = def?.name || impresorasPrueba.value[0].name
    }
  } catch {
    impresorasPrueba.value = []
  }
}

/** Modal crear / guardar como (prompt no funciona en Electron). */
const modalCrear = ref(false)
const modalModo = ref<'nueva' | 'como'>('nueva')
const modalTipo = ref<DocumentoTipo>('albaran')
const modalNombre = ref('')
/** Clave `WxH` del tamaño al crear plantilla etiqueta. */
const modalTamanoEtiqueta = ref('50x30')
const modalError = ref<string | null>(null)

const confirmEliminar = ref(false)
const confirmDescartar = ref(false)
const pendienteTrasDescartar = ref<'nueva' | 'seleccionar' | null>(null)
const pendienteSeleccionarId = ref<number | null>(null)

const actual = computed(() =>
  plantillaId.value != null ? itemsFiltrados.value.find((p) => p.id === plantillaId.value) : undefined
)

const itemsFiltrados = computed(() =>
  items.value.filter((p) => perteneceAlScope(p.tipo, scope.value))
)

const empresaCodigo = computed(() => String(puestoContexto.empresaCodigo ?? '').trim().toUpperCase())

const modalTitulo = computed(() =>
  modalModo.value === 'nueva' ? 'Nueva plantilla' : 'Guardar como…'
)

function etiquetaTipo(tipo: string) {
  return TIPOS_TODOS.find((t) => t.value === tipo)?.label ?? tipo
}

function aplicarItem(item: DocumentoPlantillaServidor) {
  silenciarDirty.value = true
  plantillaId.value = item.id
  draft.value = {
    ...item.definicion,
    nombre: item.nombre,
    descripcion: item.descripcion ?? item.definicion.descripcion,
    version: item.version,
    tipo: item.tipo as DocumentoPlantilla['tipo'],
  }
  dirty.value = false
  void nextTick(() => {
    silenciarDirty.value = false
  })
}

function cargar(id: number) {
  const item = obtener(id)
  if (!item) return
  mensaje.value = null
  errorMsg.value = null
  aplicarItem(item)
}

async function init() {
  errorMsg.value = null
  mensaje.value = null
  if (!empresaCodigo.value) {
    errorMsg.value = 'Configure la empresa del puesto para guardar plantillas en el servidor.'
    return
  }
  const res = await cargarEmpresa(empresaCodigo.value)
  if (!res.ok) {
    errorMsg.value = res.message
    return
  }
  mensaje.value = res.message
  const primera = itemsFiltrados.value[0]
  if (primera) cargar(primera.id)
}

onMounted(() => {
  void init()
  void cargarImpresorasPrueba()
})

watch(scope, () => {
  plantillaId.value = null
  draft.value = null
  dirty.value = false
  mensaje.value = null
  errorMsg.value = null
  const primera = itemsFiltrados.value[0]
  if (primera) cargar(primera.id)
})

const jsonVista = computed(() => (draft.value ? JSON.stringify(draft.value, null, 2) : ''))

function onSeleccionar(id: number) {
  if (id === plantillaId.value) return
  if (dirty.value) {
    pendienteTrasDescartar.value = 'seleccionar'
    pendienteSeleccionarId.value = id
    confirmDescartar.value = true
    return
  }
  cargar(id)
}

async function onGuardar() {
  if (!draft.value || plantillaId.value == null) return
  const nombre = String(draft.value.nombre ?? '').trim()
  if (!nombre) {
    errorMsg.value = 'Indique un nombre para la plantilla.'
    return
  }
  errorMsg.value = null
  mensaje.value = null
  draft.value = { ...draft.value, nombre }
  const res = await guardar(plantillaId.value, draft.value, {
    nombre,
    descripcion: draft.value.descripcion?.trim() || null,
  })
  if (!res.ok) {
    errorMsg.value = res.message
    return
  }
  if (res.item) aplicarItem(res.item)
  mensaje.value = 'Plantilla actualizada en el servidor.'
}

function abrirModalNueva() {
  if (!empresaCodigo.value) {
    errorMsg.value = 'Configure la empresa del puesto.'
    return
  }
  if (dirty.value) {
    pendienteTrasDescartar.value = 'nueva'
    confirmDescartar.value = true
    return
  }
  modalModo.value = 'nueva'
  const tipoActual =
    draft.value && perteneceAlScope(draft.value.tipo, scope.value)
      ? draft.value.tipo
      : scopeMeta.value.tipoDefault
  modalTipo.value = tipoActual
  const base = esqueletoPorTipo(modalTipo.value)
  modalNombre.value = base?.nombre ?? 'Nueva plantilla'
  modalTamanoEtiqueta.value = '50x30'
  if (modalTipo.value === 'etiqueta' && base) {
    const w = base.page.widthMm ?? 50
    const h = base.page.heightMm ?? 30
    modalTamanoEtiqueta.value = `${w}x${h}`
    modalNombre.value = `Etiqueta ${w}×${h}`
  }
  modalError.value = null
  modalCrear.value = true
}

function abrirModalGuardarComo() {
  if (!draft.value) return
  if (!empresaCodigo.value) {
    errorMsg.value = 'Configure la empresa del puesto.'
    return
  }
  modalModo.value = 'como'
  modalTipo.value = draft.value.tipo
  modalNombre.value = `${draft.value.nombre} (nueva)`
  modalError.value = null
  modalCrear.value = true
}

function onTipoModalChange() {
  if (modalModo.value !== 'nueva') return
  const base = esqueletoPorTipo(modalTipo.value)
  if (modalTipo.value === 'etiqueta') {
    const parsed = parseClaveTamanoEtiqueta(modalTamanoEtiqueta.value) ?? { widthMm: 50, heightMm: 30 }
    modalNombre.value = `Etiqueta ${parsed.widthMm}×${parsed.heightMm}`
    return
  }
  if (base && (!modalNombre.value.trim() || modalNombre.value.startsWith('Etiqueta ') || modalNombre.value === 'Nueva plantilla')) {
    modalNombre.value = base.nombre
  }
}

function onTamanoEtiquetaModalChange() {
  if (modalModo.value !== 'nueva' || modalTipo.value !== 'etiqueta') return
  const parsed = parseClaveTamanoEtiqueta(modalTamanoEtiqueta.value)
  if (!parsed) return
  const nombreGen = /^Etiqueta \d+×\d+$/
  if (!modalNombre.value.trim() || nombreGen.test(modalNombre.value)) {
    modalNombre.value = `Etiqueta ${parsed.widthMm}×${parsed.heightMm}`
  }
}

function cerrarModalCrear() {
  modalCrear.value = false
  modalError.value = null
}

async function confirmarModalCrear() {
  const nombre = modalNombre.value.trim()
  if (!nombre) {
    modalError.value = 'Indique un nombre.'
    return
  }
  if (!empresaCodigo.value) {
    modalError.value = 'No hay empresa en el puesto.'
    return
  }

  let plantilla: DocumentoPlantilla
  if (modalModo.value === 'nueva') {
    if (!perteneceAlScope(modalTipo.value, scope.value)) {
      modalTipo.value = scopeMeta.value.tipoDefault
    }
    const base = esqueletoPorTipo(modalTipo.value)
    if (!base) {
      modalError.value = 'Tipo no válido.'
      return
    }
    plantilla = { ...base, nombre }
    if (modalTipo.value === 'etiqueta') {
      const parsed = parseClaveTamanoEtiqueta(modalTamanoEtiqueta.value)
      if (!parsed) {
        modalError.value = 'Seleccione un tamaño de etiqueta.'
        return
      }
      plantilla = conTamanoEtiqueta({ ...plantilla, nombre }, parsed.widthMm, parsed.heightMm)
      plantilla = {
        ...plantilla,
        nombre,
        descripcion: `Etiqueta de artículo ${parsed.widthMm}×${parsed.heightMm} mm.`,
      }
    }
  } else {
    if (!draft.value) return
    const tipoDestino = perteneceAlScope(modalTipo.value, scope.value)
      ? modalTipo.value
      : scopeMeta.value.tipoDefault
    plantilla = { ...draft.value, nombre, tipo: tipoDestino }
  }

  modalError.value = null
  const res = await crear(empresaCodigo.value, plantilla, { nombre, activar: false })
  if (!res.ok) {
    modalError.value = res.message
    return
  }
  modalCrear.value = false
  mensaje.value =
    modalModo.value === 'nueva'
      ? 'Plantilla nueva creada en el servidor.'
      : 'Nueva plantilla creada (la anterior no se ha modificado).'
  errorMsg.value = null
  if (res.item) cargar(res.item.id)
}

function onConfirmDescartar() {
  confirmDescartar.value = false
  dirty.value = false
  const accion = pendienteTrasDescartar.value
  pendienteTrasDescartar.value = null
  if (accion === 'nueva') {
    abrirModalNueva()
    return
  }
  if (accion === 'seleccionar' && pendienteSeleccionarId.value != null) {
    const id = pendienteSeleccionarId.value
    pendienteSeleccionarId.value = null
    cargar(id)
  }
}

function onCancelDescartar() {
  confirmDescartar.value = false
  pendienteTrasDescartar.value = null
  pendienteSeleccionarId.value = null
}

function onEliminar() {
  if (plantillaId.value == null || !actual.value) return
  confirmEliminar.value = true
}

async function confirmarEliminar() {
  confirmEliminar.value = false
  if (plantillaId.value == null) return
  errorMsg.value = null
  const res = await eliminar(plantillaId.value)
  if (!res.ok) {
    errorMsg.value = res.message
    return
  }
  mensaje.value = res.message
  const siguiente = itemsFiltrados.value[0]
  if (siguiente) cargar(siguiente.id)
  else {
    plantillaId.value = null
    draft.value = null
  }
}

async function onActivar() {
  if (plantillaId.value == null) return
  errorMsg.value = null
  const res = await activar(plantillaId.value)
  if (!res.ok) {
    errorMsg.value = res.message
    return
  }
  mensaje.value = res.message
}

/** Prueba ESC/POS vía API → agente Electron (impresora térmica del puesto). */
async function onProbarTicket() {
  if (!draft.value || !esPlantillaTicket(draft.value)) return
  const puesto = String(puestoContexto.puestoCodigo ?? '').trim()
  if (!puesto) {
    errorMsg.value = 'Configure el puesto de este equipo para probar la impresión.'
    return
  }
  if (!impresoraPrueba.value) {
    await cargarImpresorasPrueba()
  }
  if (!impresoraPrueba.value) {
    errorMsg.value =
      'No hay impresoras Windows detectadas. Ejecute Descartes Electron y elija una impresora real (no «TICKETS»).'
    return
  }
  errorMsg.value = null
  mensaje.value = null
  imprimiendo.value = true
  try {
    const texto = textoTicketDesdePlantilla(draft.value)
    const res = await imprimirTermicaDispositivo(puesto, {
      texto,
      tipo: 'ticket',
      empresa: empresaCodigo.value,
      impresora: impresoraPrueba.value,
    })
    if (!res.agenteOnline) {
      errorMsg.value = res.message || 'Agente Electron no disponible. Ejecute Descartes Electron.'
      return
    }
    if (!res.ok) {
      errorMsg.value = res.message || 'La impresora no aceptó el ticket'
      return
    }
    mensaje.value = res.stub
      ? `Stub: ${res.message}`
      : res.message || 'Ticket enviado a la térmica'
  } catch (e: unknown) {
    errorMsg.value = extractApiError(e, 'No se pudo imprimir el ticket')
  } finally {
    imprimiendo.value = false
  }
}

function onResetearEsqueleto() {
  if (!draft.value) return
  const base = esqueletoPorTipo(draft.value.tipo)
  if (!base) {
    errorMsg.value = 'No hay esqueleto base para este tipo.'
    return
  }
  draft.value = {
    ...base,
    nombre: draft.value.nombre,
    descripcion: draft.value.descripcion,
  }
  dirty.value = true
  mensaje.value = 'Diseño restaurado al esqueleto. Pulse Guardar para conservarlo en el servidor.'
}

function onDraftUpdate(v: DocumentoPlantilla) {
  draft.value = v
  if (!silenciarDirty.value) dirty.value = true
}

function marcarDirty() {
  if (!silenciarDirty.value) dirty.value = true
}
</script>

<template>
  <section class="docs-view">
    <div class="cabecera">
      <RouterLink to="/configuracion" class="volver">← Configuración</RouterLink>
      <div class="cab-row">
        <div>
          <h2>{{ scopeMeta.titulo }}</h2>
          <p class="intro">
            {{ scopeMeta.intro }}
            <template v-if="empresaCodigo"> Empresa {{ empresaCodigo }}.</template>
          </p>
        </div>
        <div class="acciones">
          <button type="button" class="btn" :disabled="guardando" @click="abrirModalNueva">
            Nueva
          </button>
          <button
            type="button"
            class="btn"
            :disabled="!draft || guardando"
            @click="abrirModalGuardarComo"
          >
            Guardar como…
          </button>
          <button
            v-if="scope === 'tickets'"
            type="button"
            class="btn"
            :disabled="!draft || !esPlantillaTicket(draft) || imprimiendo || guardando"
            @click="onProbarTicket"
          >
            {{ imprimiendo ? 'Imprimiendo…' : 'Probar ticket' }}
          </button>
          <label v-if="scope === 'tickets' && draft && esPlantillaTicket(draft)" class="imp-prueba">
            Impresora
            <select v-model="impresoraPrueba">
              <option disabled value="">— Elegir —</option>
              <option v-for="p in impresorasPrueba" :key="p.name" :value="p.name">{{ p.label }}</option>
            </select>
          </label>
          <button type="button" class="btn" :disabled="!draft || guardando" @click="onResetearEsqueleto">
            Esqueleto base
          </button>
          <button
            type="button"
            class="btn"
            :disabled="!actual || actual.activa || guardando"
            @click="onActivar"
          >
            Activar
          </button>
          <button type="button" class="btn danger" :disabled="!draft || guardando" @click="onEliminar">
            Eliminar
          </button>
          <button type="button" class="btn primary" :disabled="!draft || guardando" @click="onGuardar">
            {{ guardando ? 'Guardando…' : 'Guardar' }}
          </button>
        </div>
      </div>
      <p v-if="cargando" class="msg">Cargando plantillas del servidor…</p>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="errorMsg" class="err">{{ errorMsg }}</p>
      <p v-if="dirty" class="aviso">Cambios sin guardar</p>
    </div>

    <div class="layout">
      <aside class="lista">
        <button type="button" class="btn-nueva-lista" :disabled="guardando" @click="abrirModalNueva">
          ＋ Nueva plantilla
        </button>
        <button
          v-for="p in itemsFiltrados"
          :key="p.id"
          type="button"
          class="item"
          :class="{ active: plantillaId === p.id }"
          @click="onSeleccionar(p.id)"
        >
          <span class="tipo">
            {{ etiquetaTipo(p.tipo) }}
            <em v-if="p.activa" class="activa">activa</em>
          </span>
          <strong>{{ p.nombre }}</strong>
          <span class="meta">{{ p.definicion.blocks.length }} bloques · v{{ p.version }}</span>
        </button>
        <p v-if="!cargando && itemsFiltrados.length === 0" class="vacio">
          No hay plantillas de {{ scopeMeta.titulo.toLowerCase() }} para esta empresa.
        </p>
      </aside>

      <div v-if="draft" class="panel">
        <header class="panel-cab">
          <div class="panel-meta">
            <label class="campo-nombre">
              <span>Nombre</span>
              <input
                v-model="draft.nombre"
                type="text"
                maxlength="100"
                @input="marcarDirty"
              />
            </label>
            <label class="campo-desc">
              <span>Descripción</span>
              <input
                v-model="draft.descripcion"
                type="text"
                maxlength="250"
                placeholder="Opcional"
                @input="marcarDirty"
              />
            </label>
          </div>
          <div class="tabs">
            <button type="button" :class="{ active: tab === 'diseno' }" @click="tab = 'diseno'">Diseño</button>
            <button type="button" :class="{ active: tab === 'preview' }" @click="tab = 'preview'">Vista previa</button>
            <button type="button" :class="{ active: tab === 'json' }" @click="tab = 'json'">JSON</button>
          </div>
        </header>

        <p v-if="draft?.tipo === 'etiqueta'" class="hint-etiq">
          Al crear una plantilla Etiqueta elija el tamaño en el desplegable (30×20 … 100×70). Puede
          tener varias. «Activar» marca el default de la empresa; en Puestos → Generales II →
          Etiquetas artículo elija la plantilla por defecto del puesto. Al imprimir se podrá elegir el
          formato.
        </p>

        <DocumentoPlantillaDesigner
          v-if="tab === 'diseno' && draft && !esPlantillaTicket(draft)"
          :model-value="draft"
          @update:model-value="onDraftUpdate"
          @dirty="marcarDirty"
        />
        <TicketPlantillaDesigner
          v-else-if="tab === 'diseno' && draft && esPlantillaTicket(draft)"
          :model-value="draft"
          @update:model-value="onDraftUpdate"
          @dirty="marcarDirty"
        />

        <DocumentoPlantillaPreview
          v-else-if="tab === 'preview' && draft && !esPlantillaTicket(draft)"
          :plantilla="draft"
        />
        <TicketPlantillaPreview
          v-else-if="tab === 'preview' && draft && esPlantillaTicket(draft)"
          :plantilla="draft"
        />

        <div v-else class="json-wrap">
          <pre>{{ jsonVista }}</pre>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="modalCrear"
        class="overlay-crear"
        role="dialog"
        aria-modal="true"
        @click.self="cerrarModalCrear"
      >
        <div class="modal-crear">
          <h3>{{ modalTitulo }}</h3>
          <label v-if="modalModo === 'nueva' && !scopeUnicoTipo" class="campo">
            <span>Tipo</span>
            <select v-model="modalTipo" @change="onTipoModalChange">
              <option v-for="t in TIPOS" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </label>
          <p v-else-if="modalModo === 'nueva' && scopeUnicoTipo" class="campo-fijo">
            Tipo: <strong>{{ etiquetaTipo(modalTipo) }}</strong>
          </p>
          <label v-if="modalModo === 'nueva' && modalTipo === 'etiqueta'" class="campo">
            <span>Tamaño</span>
            <select v-model="modalTamanoEtiqueta" @change="onTamanoEtiquetaModalChange">
              <option
                v-for="t in ETIQUETA_TAMANOS_MM"
                :key="t.label"
                :value="`${t.widthMm}x${t.heightMm}`"
              >
                {{ t.label }}
              </option>
            </select>
          </label>
          <label class="campo">
            <span>Nombre</span>
            <input
              v-model="modalNombre"
              type="text"
              maxlength="100"
              autofocus
              @keydown.enter.prevent="confirmarModalCrear"
            />
          </label>
          <p v-if="modalError" class="modal-err">{{ modalError }}</p>
          <footer>
            <button type="button" class="btn" :disabled="guardando" @click="cerrarModalCrear">Cancelar</button>
            <button type="button" class="btn primary" :disabled="guardando" @click="confirmarModalCrear">
              {{ guardando ? 'Creando…' : 'Crear' }}
            </button>
          </footer>
        </div>
      </div>
    </Teleport>

    <ConfirmDialog
      :open="confirmDescartar"
      title="Cambios sin guardar"
      message="Hay cambios sin guardar. ¿Descartarlos?"
      confirm-label="Descartar"
      cancel-label="Cancelar"
      :danger="true"
      @confirm="onConfirmDescartar"
      @cancel="onCancelDescartar"
    />
    <ConfirmDialog
      :open="confirmEliminar"
      title="Eliminar plantilla"
      :message="`¿Eliminar la plantilla «${actual?.nombre ?? ''}»?`"
      confirm-label="Eliminar"
      cancel-label="Cancelar"
      :danger="true"
      @confirm="confirmarEliminar"
      @cancel="confirmEliminar = false"
    />
  </section>
</template>

<style scoped>
.docs-view {
  max-width: min(100%, 112rem);
}

.layout {
  display: grid;
  grid-template-columns: minmax(10rem, 12rem) minmax(0, 1fr);
  gap: 0.75rem;
  align-items: start;
}

.cabecera {
  margin-bottom: 0.75rem;
}

.volver {
  display: inline-block;
  margin-bottom: 0.35rem;
  font-size: 0.8rem;
  color: #2563eb;
  text-decoration: none;
}

.volver:hover {
  text-decoration: underline;
}

.cab-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.cabecera h2 {
  margin: 0 0 0.25rem;
  font-size: 1.25rem;
}

.intro {
  margin: 0;
  font-size: 0.85rem;
  color: #64748b;
}

.acciones {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  align-items: center;
}

.imp-prueba {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: #64748b;
}

.imp-prueba select {
  max-width: 14rem;
  padding: 0.25rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  font: inherit;
  font-size: 0.78rem;
}

.btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
}

.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.btn.danger {
  border-color: #fca5a5;
  color: #b91c1c;
}

.msg {
  margin: 0.4rem 0 0;
  font-size: 0.8rem;
  color: #047857;
}

.err {
  margin: 0.4rem 0 0;
  font-size: 0.8rem;
  color: #b91c1c;
}

.aviso {
  margin: 0.25rem 0 0;
  font-size: 0.78rem;
  color: #b45309;
}

.hint-etiq {
  margin: 0 0 0.65rem;
  padding: 0.5rem 0.65rem;
  border-radius: 6px;
  background: #eff6ff;
  color: #1e40af;
  font-size: 0.8rem;
  line-height: 1.35;
}

.lista {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.btn-nueva-lista {
  padding: 0.45rem 0.65rem;
  border: 1px dashed #94a3b8;
  border-radius: 6px;
  background: #f8fafc;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  color: #2563eb;
  cursor: pointer;
  text-align: left;
}

.btn-nueva-lista:hover:not(:disabled) {
  border-color: #3b82f6;
  background: #eff6ff;
}

.btn-nueva-lista:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.vacio {
  margin: 0.5rem 0 0;
  font-size: 0.78rem;
  color: #94a3b8;
}

.item {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.15rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #fff;
  text-align: left;
  cursor: pointer;
  font: inherit;
}

.item:hover {
  border-color: #94a3b8;
}

.item.active {
  border-color: #3b82f6;
  background: #eff6ff;
}

.tipo {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: #64748b;
}

.activa {
  font-style: normal;
  text-transform: none;
  letter-spacing: 0;
  font-weight: 600;
  color: #047857;
  background: #d1fae5;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  font-size: 0.65rem;
}

.item strong {
  font-size: 0.88rem;
  color: #0f172a;
}

.meta {
  font-size: 0.72rem;
  color: #94a3b8;
}

.panel {
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  background: #fff;
  padding: 0.65rem 0.75rem 0.85rem;
  min-width: 0;
}

.panel-cab {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 0.5rem;
  align-items: flex-start;
  margin-bottom: 0.65rem;
  padding-bottom: 0.55rem;
  border-bottom: 1px solid #e2e8f0;
}

.panel-meta {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 12rem;
}

.campo-nombre,
.campo-desc {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.72rem;
  font-weight: 600;
  color: #64748b;
}

.campo-nombre input,
.campo-desc input {
  padding: 0.35rem 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 500;
  color: #0f172a;
  max-width: 28rem;
}

.campo-nombre input {
  font-size: 1rem;
  font-weight: 600;
}

.campo-nombre input:focus,
.campo-desc input:focus {
  outline: none;
  border-color: #3b82f6;
}

.tabs {
  display: flex;
  gap: 0.25rem;
}

.tabs button {
  padding: 0.25rem 0.6rem;
  border: 1px solid #cbd5e1;
  border-radius: 5px;
  background: #f8fafc;
  font-size: 0.75rem;
  cursor: pointer;
}

.tabs button.active {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.json-wrap pre {
  margin: 0;
  max-height: min(70vh, 860px);
  overflow: auto;
  padding: 0.65rem 0.75rem;
  background: #0f172a;
  color: #e2e8f0;
  border-radius: 6px;
  font-size: 0.72rem;
  line-height: 1.4;
}

@media (max-width: 900px) {
  .layout {
    grid-template-columns: 1fr;
  }
}

.overlay-crear {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.45);
  display: grid;
  place-items: center;
  z-index: 5000;
  padding: 1rem;
}

.modal-crear {
  width: min(400px, 100%);
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(15, 23, 42, 0.2);
  padding: 1rem 1.1rem 1.1rem;
}

.modal-crear h3 {
  margin: 0 0 0.85rem;
  font-size: 1.05rem;
}

.modal-crear .campo {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-bottom: 0.65rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #64748b;
}

.modal-crear .campo-fijo {
  margin: 0 0 0.65rem;
  font-size: 0.82rem;
  color: #475569;
}

.modal-crear .campo input,
.modal-crear .campo select {
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 500;
  color: #0f172a;
}

.modal-crear .campo input:focus,
.modal-crear .campo select:focus {
  outline: none;
  border-color: #3b82f6;
}

.modal-err {
  margin: 0 0 0.65rem;
  font-size: 0.8rem;
  color: #b91c1c;
}

.modal-crear footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.45rem;
  margin-top: 0.35rem;
}
</style>
