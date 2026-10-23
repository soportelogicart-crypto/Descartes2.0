<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  clonarFilaGrid,
  filaVaciaDesdeColumnas,
  getGridColumns,
  payloadFilaGrid,
  validarFilaGrid,
  type GridFila,
} from '@/config/entidad-grid-columns'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import {
  IMPUESTO_CAMPOS_OBLIGATORIOS,
  camposImpuestoObligatoriosVacios,
  impuestoTabs,
  impuestoVacio,
  validarImpuestoObligatorios,
} from '@/config/impuestos-tabs'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { useMantenimientoServerSearch } from '@/composables/useMantenimientoServerSearch'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import ImpuestoTabForm from '@/components/impuestos/ImpuestoTabForm.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const ENTIDAD = 'impuestos'
const MODULO = 'impuestos'
const FILTER_KEYS = ['codigo', 'descripcion']
const SERVER_FILTER_KEYS = ['codigo', 'descripcion'] as const
const columns = getGridColumns(ENTIDAD)

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, cancelarListado, obtener, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))
const soloLecturaGrid = computed(() => !puedeCrear.value && !puedeEditar.value)

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const { serverQuery, onServerSearch } = useMantenimientoServerSearch({
  filters: filtros,
  serverKeys: SERVER_FILTER_KEYS,
  reload: cargar,
  cancel: cancelarListado,
})
const indiceSeleccionado = ref(0)
const filtroActivo = ref<'activos' | 'todos' | 'inactivos'>('activos')
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

const tabActiva = ref(impuestoTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)

const filas = computed<GridFila[]>(() => {
  const datos = filasTodas.value.filter((f) => !f._nuevo)
  const filtradas = aplicarFiltrosColumnas(datos, filtros.value)
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaVaciaDesdeColumnas(columns)]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const puedeMostrarGuardar = computed(() => puedeCrear.value || puedeEditar.value)
const puedeGuardarGrid = computed(() => {
  const fila = filaSeleccionada.value
  if (!fila || fila._nuevo) return false
  return puedeEditar.value
})
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && filaSeleccionada.value && !filaSeleccionada.value._nuevo
)

const tabSeleccionada = computed(() => impuestoTabs.find((t) => t.id === tabActiva.value) ?? impuestoTabs[0])
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
  etiquetaEntidad: 'el impuesto',
  mensajeExito: 'Impuesto eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar(q = serverQuery.value) {
  mensaje.value = null
  const params: Record<string, string | number | boolean> = { page: page.value, pageSize: pageSize.value }
  if (q) params.q = q
  if (filtroActivo.value === 'activos') params.activo = true
  if (filtroActivo.value === 'inactivos') params.activo = false
  const seqFiltro = filtroActivo.value
  await listar(params)
  if (!vistaMontada || seqFiltro !== filtroActivo.value) return
  filasTodas.value = items.value.map((item) => clonarFilaGrid(item, columns))
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}


async function onCambioFiltroActivo() {
  page.value = 1
  indiceSeleccionado.value = 0
  await cargar()
}

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function actualizarFila(_index: number, fila: GridFila) {
  const codigo = String(fila.codigo ?? '')
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo) === codigo ? { ...fila, _nuevo: false } : f
  )
}


async function onGuardarGrid() {
  const fila = filaSeleccionada.value
  if (!fila || fila._nuevo) return
  const errorValidacion = validarFilaGrid(fila, columns)
  if (errorValidacion) {
    mensaje.value = errorValidacion
    return
  }
  try {
    await actualizar(String(fila.codigo), payloadFilaGrid(fila, columns))
    mensaje.value = 'Impuesto actualizado'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el impuesto')
  }
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    ficha.value = await obtener(codigo)
    indiceFicha.value = filasNavegacion.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    tabActiva.value = 'generales'
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
    mensaje.value = 'Seleccione un impuesto existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = impuestoVacio()
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  tabActiva.value = 'generales'
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
  const vacios = camposImpuestoObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = IMPUESTO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    const msg = validarImpuestoObligatorios(ficha.value) ?? `El campo "${label}" es obligatorio.`
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
      const msg = `Ya existe un impuesto con el codigo "${codigo}".`
      mensaje.value = msg
      mostrarAvisoModal('Codigo duplicado', msg, 'codigo')
      return
    }
  }
  try {
    if (esNuevo.value) {
      const creado = await crear(ficha.value)
      mensaje.value = 'Impuesto creado correctamente'
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(codigo, ficha.value)
      mensaje.value = 'Impuesto actualizado'
      await cargar()
      await abrirFichaPorCodigo(codigo)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar el impuesto')
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
    mensaje.value = 'Impuesto eliminado'
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
  <section class="impuestos-view">
    <h2>Impuestos</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver impuestos.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Impuestos"
            :columnas="columns"
            :filas="filas"
            @aviso="mensaje = $event"
          />
          <button v-if="puedeCrear" type="button" class="tool-btn" :disabled="loading" @click="onNuevo">
            Nuevo
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada || filaSeleccionada._nuevo"
            @click="abrirFicha()"
          >
            Ficha
          </button>
          <label class="filtro-activo">
            Estado
            <select v-model="filtroActivo" @change="onCambioFiltroActivo">
              <option value="activos">Activos</option>
              <option value="todos">Todos</option>
              <option value="inactivos">Inactivos</option>
            </select>
          </label>
          <div class="toolbar-spacer"></div>
          <button
            v-if="puedeMostrarGuardar"
            type="button"
            class="tool-btn primary"
            :disabled="loading || !puedeGuardarGrid"
            @click="onGuardarGrid"
          >
            Guardar
          </button>
          <button
            v-if="puedeMostrarEliminar"
            type="button"
            class="tool-btn danger"
            :disabled="loading"
            @click="solicitarEliminar"
          >
            Eliminar
          </button>
        </div>

        <EntidadGrid
          :columns="columns"
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :readonly="soloLecturaGrid"
          :loading="loading"
          :total-servidor="total"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
          @search="onServerSearch"
        />

        <p class="hint">
          Filtra por <strong>Codigo</strong> y <strong>Descripcion</strong> escribiendo bajo cada columna. Doble clic o
          <strong>Ficha</strong> abre el detalle.
        </p>
        </div>
      </template>

      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

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
              maxlength="2"
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

        <div class="tabs">
          <button
            v-for="tab in impuestoTabs"
            :key="tab.id"
            type="button"
            class="tab"
            :class="{ active: tabActiva === tab.id }"
            @click="tabActiva = tab.id"
          >
            {{ tab.label }}
          </button>
        </div>

        <ImpuestoTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :readonly="soloLecturaFicha"
          :codigo-read-only="!esNuevo"
          :ocultar-cabecera="true"
          :campos-invalidos="camposInvalidos"
          @update:model-value="ficha = $event"
        />
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar impuesto"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        title="Eliminar impuesto"
        message="Va a eliminar este impuesto de la base de datos. Esta accion no se puede deshacer."
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
.impuestos-view h2 {
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

.filtro-activo {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.78rem;
  color: #475569;
}

.filtro-activo select {
  padding: 0.3rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
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
}

.tool-btn.active {
  background: #e0f2fe;
  border-color: #38bdf8;
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
  width: 4rem;
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

.campo-invalido input {
  border-color: #ef4444;
  background: #fef2f2;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.25rem 0.35rem 0;
  background: #fff;
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
  max-width: 1100px;
}

.tab {
  border: 1px solid #94a3b8;
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  background: #e8edf2;
  padding: 0.3rem 0.6rem;
  font-size: 0.78rem;
  cursor: pointer;
}

.tab.active {
  background: #f8fafc;
  font-weight: 600;
  border-color: #94a3b8;
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
