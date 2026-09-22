<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import { familiaColumns, 
  FAMILIA_CAMPOS_OBLIGATORIOS,
  camposFamiliaObligatoriosVacios,
  clonarFamilia,
  familiaVacia,
  nombreMacrofamilia,
  payloadFamilia,
  validarFamiliaObligatorios,
  type FamiliaFila,
} from '@/config/familias-columns'
import FamiliasGrid from '@/components/familias/FamiliasGrid.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'

const MODULO = 'familias'
const ENTIDAD = 'familias'
const FILTER_KEYS = ['codigo', 'descripcion', 'macroFamiliaCodigo', 'macroFamiliaNombre']

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar } =
  useMantenimiento(() => ENTIDAD)

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<FamiliaFila[]>([])
const macroOpciones = ref<{ value: string; label: string }[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const mensaje = ref<string | null>(null)
const confirmBorrarFichaOpen = ref(false)
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Campo obligatorio')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const camposInvalidos = ref<string[]>([])
const codigoInput = ref<HTMLInputElement | null>(null)
let vistaMontada = true

onBeforeUnmount(() => {
  vistaMontada = false
})

function mostrarAvisoModal(titulo: string, message: string, campo?: string | null) {
  campoAvisoActual.value = campo ?? null
  if (campo) camposInvalidos.value = [campo]
  avisoModalTitulo.value = titulo
  avisoModalMensaje.value = message
  avisoModalOpen.value = true
}

async function cerrarAvisoModal() {
  const key = campoAvisoActual.value
  avisoModalOpen.value = false
  avisoModalMensaje.value = ''
  campoAvisoActual.value = null
  if (!key) return
  await nextTick()
  await nextTick()
  if (key === 'codigo') {
    codigoInput.value?.focus()
    codigoInput.value?.select()
    return
  }
  document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)?.focus()
}

const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)

const filas = computed<FamiliaFila[]>(() => {
  const datos = filasTodas.value.filter((f) => !f._nuevo)
  const filtradas = aplicarFiltrosColumnas(datos, filtros.value) as FamiliaFila[]
  if (!puedeCrear.value) return filtradas
  return [...filtradas, familiaVacia()]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && filaSeleccionada.value && !filaSeleccionada.value._nuevo
)

const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
/** La ficha recorre lo que se ve en la rejilla: si la busqueda deja 3 filas, el contador es x/3. */
const filasNavegacion = computed(() => filas.value.filter((f) => !f._nuevo))
const totalFicha = computed(() => filasNavegacion.value.length)
const puedeGuardarFicha = computed(() => (puedeCrear.value || puedeEditar.value) && (modoEdicion.value || esNuevo.value))

function quitarFilaNueva() {
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

const {
  confirmOpen,
  confirmMessage,
  solicitarEliminar,
  confirmarEliminar,
  cancelarEliminar,
} = useEliminarFilaGrid({
  puedeEliminar,
  filaSeleccionada: () => {
    const f = filaSeleccionada.value
    if (!f) return null
    return {
      codigo: f.codigo == null ? undefined : String(f.codigo),
      descripcion: String(f.descripcion ?? ''),
      _nuevo: f._nuevo,
    }
  },
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    mensaje.value = msg
  },
  etiquetaEntidad: 'la familia',
  mensajeExito: 'Familia eliminada',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargarMacros()
  await cargar()
})

async function cargarMacros() {
  const { data } = await api.get('/api/mantenimiento/macrofamilias', { params: { pageSize: 500 } })
  macroOpciones.value = (data.items ?? []).map((m: { codigo: string; descripcion: string }) => ({
    value: String(m.codigo).trim(),
    label: `${String(m.codigo).trim()} - ${m.descripcion}`,
  }))
}

function mapFilasDesdeApi() {
  filasTodas.value = items.value.map((item) => {
    const fila = clonarFamilia(item)
    fila.macroFamiliaNombre = nombreMacrofamilia(fila.macroFamiliaCodigo ?? '', macroOpciones.value)
    return fila
  })
}

async function cargar() {
  mensaje.value = null
  await listar({ page: page.value, pageSize: pageSize.value })
  if (!vistaMontada) return
  mapFilasDesdeApi()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}


function seleccionar(index: number) {
  indiceSeleccionado.value = index
}


async function abrirFichaPorCodigo(codigo: string) {
  try {
    ficha.value = await obtener(codigo)
    indiceFicha.value = filasNavegacion.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    vista.value = 'ficha'
    mensaje.value = null
    camposInvalidos.value = []
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mensaje.value = 'Seleccione una familia existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = familiaVacia()
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  vista.value = 'ficha'
  mensaje.value = null
  camposInvalidos.value = []
  await nextTick()
  await nextTick()
  codigoInput.value?.focus()
  codigoInput.value?.select()
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const vacios = camposFamiliaObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = FAMILIA_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    const msg = validarFamiliaObligatorios(ficha.value) ?? `El campo "${label}" es obligatorio.`
    mensaje.value = msg
    mostrarAvisoModal('Campo obligatorio', msg, key)
    return
  }
  camposInvalidos.value = []
  const codigo = String(ficha.value.codigo ?? '').trim()
  if (esNuevo.value) {
    const existe = filasTodas.value.some(
      (f) => String(f.codigo ?? '').trim().toLowerCase() === codigo.toLowerCase()
    )
    if (existe) {
      const msg = `Ya existe una familia con el codigo "${codigo}".`
      mensaje.value = msg
      mostrarAvisoModal('Codigo duplicado', msg, 'codigo')
      return
    }
  }
  try {
    if (esNuevo.value) {
      const creado = await crear(payloadFamilia(ficha.value))
      mensaje.value = 'Familia creada correctamente'
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(codigo, payloadFamilia(ficha.value))
      mensaje.value = 'Familia actualizada'
      await cargar()
      await abrirFichaPorCodigo(codigo)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar la familia')
    mensaje.value = msg
    if (/ya existe|duplicad|codigo/i.test(msg)) {
      mostrarAvisoModal('Codigo duplicado', msg, 'codigo')
    } else {
      mostrarAvisoModal('Error al guardar', msg)
    }
  }
}

function onCancelarFicha() {
  if (esNuevo.value) {
    volverAlGrid()
    return
  }
  modoEdicion.value = false
  if (ficha.value.codigo) {
    abrirFichaPorCodigo(String(ficha.value.codigo))
  }
}

async function onBorrarFicha() {
  if (!puedeEliminar.value || esNuevo.value || !ficha.value.codigo) return
  confirmBorrarFichaOpen.value = true
}

async function confirmarBorrarFicha() {
  confirmBorrarFichaOpen.value = false
  if (!ficha.value.codigo) return
  try {
    await eliminar(String(ficha.value.codigo))
    mensaje.value = 'Familia eliminada'
    await cargar()
    volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

function volverAlGrid() {
  vista.value = 'grid'
  modoEdicion.value = false
  esNuevo.value = false
  ficha.value = {}
  camposInvalidos.value = []
}

async function onPrimero() {
  if (totalFicha.value === 0) return
  const fila = filasNavegacion.value[0]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onAnterior() {
  if (indiceFicha.value <= 0) return
  const fila = filasNavegacion.value[indiceFicha.value - 1]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onSiguiente() {
  const max = totalFicha.value - 1
  if (indiceFicha.value < 0 || indiceFicha.value >= max) return
  const fila = filasNavegacion.value[indiceFicha.value + 1]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onUltimo() {
  const max = totalFicha.value - 1
  if (max < 0) return
  const fila = filasNavegacion.value[max]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
</script>

<template>
  <section class="familias-view">
    <h2>Familias</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver familias.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Familias"
            :columnas="familiaColumns"
            :filas="filas"
            @aviso="mensaje = $event"
          >
            <ToolIcon name="listado" />
            <span>Listado</span>
          </MantenimientoListadoButton>
          <button v-if="puedeCrear" type="button" class="tool-btn" :disabled="loading" @click="onNuevo">
            <ToolIcon name="nuevo" />
            <span>Nuevo</span>
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada || filaSeleccionada._nuevo"
            @click="abrirFicha()"
          >
            Ficha
          </button>
          <div class="toolbar-spacer"></div>
          <button
            v-if="puedeMostrarEliminar"
            type="button"
            class="tool-btn danger"
            :disabled="loading"
            @click="solicitarEliminar"
          >
            <ToolIcon name="borrar" />
            <span>Eliminar</span>
          </button>
        </div>

        <FamiliasGrid
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :macro-opciones="macroOpciones"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :loading="loading"
          @seleccionar="seleccionar"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
        />

        <p class="hint">
          Escriba bajo cada columna para filtrar. Doble clic o <strong>Ficha</strong> abre el detalle.
        </p>
        </div>
      </template>

      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">Volver a la rejilla</button>

          <div class="toolbar ficha-toolbar">
            <div class="toolbar-group">
              <button type="button" class="tool-btn" :disabled="loading || !puedeCrear" @click="onNuevo">
                <ToolIcon name="nuevo" />
                <span>Nuevo</span>
              </button>
              <button
                type="button"
                class="tool-btn"
                :disabled="loading || !puedeEditar || modoEdicion || esNuevo"
                @click="onModificar"
              >
                <ToolIcon name="modificar" />
                <span>Modificar</span>
              </button>
              <button
                type="button"
                class="tool-btn"
                :disabled="loading || !puedeEliminar || esNuevo"
                @click="onBorrarFicha"
              >
                <ToolIcon name="borrar" />
                <span>Borrar</span>
              </button>
              <button type="button" class="tool-btn" :disabled="loading" @click="volverAlGrid">
                <ToolIcon name="buscar" />
                <span>Buscar</span>
              </button>
            </div>

            <div class="toolbar-group nav">
              <button type="button" class="nav-btn" :disabled="loading || indiceFicha < 0" @click="onPrimero">
                |&lt;
              </button>
              <button type="button" class="nav-btn" :disabled="loading || indiceFicha <= 0" @click="onAnterior">
                &lt;
              </button>
              <span class="nav-counter">{{ totalFicha ? indiceFicha + 1 : 0 }}/{{ totalFicha }}</span>
              <button
                type="button"
                class="nav-btn"
                :disabled="loading || totalFicha === 0 || indiceFicha < 0 || indiceFicha >= totalFicha - 1"
                @click="onSiguiente"
              >
                &gt;
              </button>
              <button
                type="button"
                class="nav-btn"
                :disabled="loading || totalFicha === 0 || indiceFicha < 0 || indiceFicha >= totalFicha - 1"
                @click="onUltimo"
              >
                &gt;|
              </button>
            </div>

            <div class="toolbar-group">
              <button
                v-if="modoEdicion || esNuevo"
                type="button"
                class="tool-btn primary"
                :disabled="loading || !puedeGuardarFicha"
                @click="onGuardarFicha"
              >
                Guardar
              </button>
              <button
                v-if="modoEdicion || esNuevo"
                type="button"
                class="tool-btn"
                :disabled="loading"
                @click="onCancelarFicha"
              >
                Cancelar
              </button>
            </div>
          </div>
        </div>

        <div class="ficha-header">
          <label :class="{ 'campo-invalido': camposInvalidos.includes('codigo') }">
            Codigo *
            <input
              ref="codigoInput"
              v-model="ficha.codigo"
              data-field-key="codigo"
              :readonly="!esNuevo"
              maxlength="6"
              class="codigo-input"
            />
          </label>
          <label
            class="descripcion-input"
            :class="{ 'campo-invalido': camposInvalidos.includes('descripcion') }"
          >
            Descripcion *
            <input
              v-model="ficha.descripcion"
              data-field-key="descripcion"
              :readonly="soloLecturaFicha"
              maxlength="40"
            />
          </label>
        </div>

        <fieldset class="form-section">
          <legend>Datos adicionales</legend>
          <div class="section-grid">
            <label class="field" :class="{ 'campo-invalido': camposInvalidos.includes('macroFamiliaCodigo') }">
              <span class="field-label">Macrofamilia *</span>
              <EntidadLookupField
                :model-value="ficha.macroFamiliaCodigo ?? null"
                entidad="macrofamilias"
                :readonly="soloLecturaFicha"
                field-key="macroFamiliaCodigo"
                empty-as-null
                @update:model-value="ficha.macroFamiliaCodigo = ($event as string) ?? ''"
              />
            </label>
            <label class="field">
              <span class="field-label">Cuenta Ctb.</span>
              <DecimalInput
                :model-value="(ficha.cuentaCtb as number | null) ?? null"
                :empty-as-null="false"
                :readonly="soloLecturaFicha"
                @update:model-value="ficha.cuentaCtb = $event ?? 0"
              />
            </label>
            <label class="field">
              <span class="field-label">Cuenta Ctb. Ita</span>
              <input v-model="ficha.cuentaCtbIta" type="text" maxlength="6" :readonly="soloLecturaFicha" />
            </label>
            <label class="field">
              <span class="field-label">Id WEB</span>
              <DecimalInput
                :model-value="(ficha.idWeb as number | null) ?? null"
                :empty-as-null="false"
                :integer="true"
                :readonly="soloLecturaFicha"
                @update:model-value="ficha.idWeb = $event ?? 0"
              />
            </label>
            <label class="field">
              <span class="field-label">Cta. Traspaso Entrada</span>
              <DecimalInput
                :model-value="(ficha.ctaTraspasoEntrada as number | null) ?? null"
                :empty-as-null="false"
                :readonly="soloLecturaFicha"
                @update:model-value="ficha.ctaTraspasoEntrada = $event ?? 0"
              />
            </label>
            <label class="field">
              <span class="field-label">Cta. Traspaso Salida</span>
              <DecimalInput
                :model-value="(ficha.ctaTraspasoSalida as number | null) ?? null"
                :empty-as-null="false"
                :readonly="soloLecturaFicha"
                @update:model-value="ficha.ctaTraspasoSalida = $event ?? 0"
              />
            </label>
          </div>
        </fieldset>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar familia"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        title="Eliminar familia"
        message="Va a eliminar esta familia de la base de datos. Esta accion no se puede deshacer."
        @confirm="confirmarBorrarFicha"
        @cancel="confirmBorrarFichaOpen = false"
      />
      <ConfirmDialog
        :open="avisoModalOpen"
        :title="avisoModalTitulo"
        :message="avisoModalMensaje"
        confirm-label="Aceptar"
        :danger="false"
        hide-cancel
        @confirm="cerrarAvisoModal"
        @cancel="cerrarAvisoModal"
      />
    </template>
  </section>
</template>

<style scoped>
.familias-view h2 {
  margin: 0 0 0.75rem;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
  padding: 0.5rem;
  background: linear-gradient(180deg, #f8fafc 0%, #e5e7eb 100%);
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  margin-bottom: 0.5rem;
}

.ficha-toolbar {
  position: relative;
  gap: 0.75rem;
}

.toolbar-group {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.toolbar-group.nav {
  position: absolute;
  left: 50%;
  transform: translateX(-50%);
  margin: 0;
}

.ficha-toolbar .toolbar-group:last-child {
  margin-left: auto;
}

.toolbar-spacer {
  flex: 1;
}

.tool-btn {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
  color: #1e293b;
}

.tool-btn.primary {
  background: #2563eb;
  border-color: #1d4ed8;
  color: #fff;
}

.tool-btn.danger {
  color: #b91c1c;
  border-color: #fecaca;
}

.tool-btn:disabled,
.nav-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.nav-btn {
  min-width: 2rem;
  padding: 0.3rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}

.nav-counter {
  font-size: 0.8rem;
  min-width: 3.5rem;
  text-align: center;
}

.btn-volver {
  margin-bottom: 0.5rem;
  padding: 0.3rem 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.8rem;
}

.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
  padding: 0.45rem 0.65rem;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-bottom: none;
  border-radius: 8px 8px 0 0;
  max-width: 1100px;
}

.ficha-header label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}

.codigo-input {
  width: 6rem;
}

.descripcion-input {
  flex: 1;
  min-width: 220px;
}

.ficha-header input {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
}

.campo-invalido {
  color: #b91c1c;
  font-weight: 600;
}

.campo-invalido input,
.campo-invalido select {
  border-color: #ef4444;
  background: #fef2f2;
}

.form-section {
  margin: 0;
  padding: 0.5rem 0.65rem 0.65rem;
  border: 1px solid #c5cdd8;
  border-radius: 0 0 8px 8px;
  background: #f0f4f8;
  max-width: 1100px;
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}

.section-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.4rem 0.6rem;
}

.field {
  display: grid;
  gap: 0.15rem;
  min-width: 0;
  font-size: 0.78rem;
}

.field-label {
  font-size: 0.72rem;
  color: #475569;
}

.field input,
.field select {
  width: 100%;
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  background: #fff;
}

.field input:read-only,
.field select:disabled {
  background: #f1f5f9;
  color: #64748b;
}

@media (max-width: 720px) {
  .section-grid {
    grid-template-columns: 1fr;
  }
}

.msg {
  color: #047857;
}

.error {
  color: #b91c1c;
}

.hint {
  margin: 0.5rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
}

@media print {
  .toolbar,
  .hint,
  .btn-volver,
  h2 {
    display: none !important;
  }
}
</style>
