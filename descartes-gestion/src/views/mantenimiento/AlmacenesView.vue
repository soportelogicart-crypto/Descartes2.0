<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { useMantenimientoServerSearch } from '@/composables/useMantenimientoServerSearch'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import { almacenColumns, 
  almacenVacio,
  clonarFila,
  payloadAlmacen,
  validarAlmacen,
  type AlmacenFila,
} from '@/config/almacenes-columns'
import {
  ALMACEN_CAMPOS_OBLIGATORIOS,
  almacenFichaVacia,
  almacenTabs,
  camposAlmacenObligatoriosVacios,
} from '@/config/almacenes-tabs'
import AlmacenesGrid from '@/components/almacenes/AlmacenesGrid.vue'
import AlmacenTabForm from '@/components/almacenes/AlmacenTabForm.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

const FILTER_KEYS = ['codigo', 'descripcion']
const SERVER_FILTER_KEYS = ['descripcion'] as const

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, cancelarListado, obtener, crear, actualizar, eliminar } = useMantenimiento(
  () => 'almacenes'
)

const puedeCrear = computed(() => puede('almacenes', 'crear'))
const puedeEditar = computed(() => puede('almacenes', 'editar'))
const puedeEliminar = computed(() => puede('almacenes', 'eliminar'))
const puedeVer = computed(() => puede('almacenes', 'ver'))
const soloLectura = computed(() => !puedeCrear.value && !puedeEditar.value)

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<AlmacenFila[]>([])
const filaNuevaDraft = ref<AlmacenFila>(almacenVacio())
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
const mensajeTipo = ref<'ok' | 'error' | 'aviso'>('ok')
const confirmBorrarFichaOpen = ref(false)
let vistaMontada = true

const tabActiva = ref(almacenTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)
const camposInvalidos = ref<string[]>([])
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Campo obligatorio')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const descripcionInput = ref<HTMLInputElement | null>(null)

onBeforeUnmount(() => {
  vistaMontada = false
})

function mostrarAviso(texto: string | null, tipo: 'ok' | 'error' | 'aviso' = 'ok') {
  mensaje.value = texto
  mensajeTipo.value = tipo
}

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
    const el = document.querySelector<HTMLInputElement>('[data-field-key="codigo"]')
    el?.focus()
    el?.select()
    return
  }
  if (key === 'descripcion') {
    descripcionInput.value?.focus()
    return
  }
  document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)?.focus()
}

function esCampoInvalido(key: string) {
  return camposInvalidos.value.includes(key)
}

function limpiarCampoInvalido(key: string) {
  if (!camposInvalidos.value.includes(key)) return
  camposInvalidos.value = camposInvalidos.value.filter((k) => k !== key)
}

watch(
  ficha,
  () => {
    if (camposInvalidos.value.length === 0) return
    const pendientes = camposAlmacenObligatoriosVacios(ficha.value)
    camposInvalidos.value = camposInvalidos.value.filter((k) => pendientes.includes(k))
  },
  { deep: true }
)

const filas = computed<AlmacenFila[]>(() => {
  const datos = filasTodas.value.filter((f) => !f._nuevo)
  const filtradas = aplicarFiltrosColumnas(datos, filtros.value)
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaNuevaDraft.value]
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
const puedeDarDeBaja = computed(
  () =>
    (puedeEliminar.value || puedeEditar.value) &&
    !!filaSeleccionada.value &&
    !filaSeleccionada.value._nuevo &&
    filaSeleccionada.value.activo !== false
)

const tabSeleccionada = computed(
  () => almacenTabs.find((t) => t.id === tabActiva.value) ?? almacenTabs[0]
)
const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
/** La ficha recorre lo que se ve en la rejilla: si la busqueda deja 3 filas, el contador es x/3. */
const filasNavegacion = computed(() => filas.value.filter((f) => !f._nuevo))
const totalFicha = computed(() => filasNavegacion.value.length)
const puedeGuardarFicha = computed(
  () => (puedeCrear.value || puedeEditar.value) && (modoEdicion.value || esNuevo.value)
)

function quitarFilaNueva() {
  filaNuevaDraft.value = almacenVacio()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

const {
  confirmOpen,
  confirmMessage,
  solicitarEliminar,
  confirmarEliminar,
  cancelarEliminar,
} = useEliminarFilaGrid({
  puedeEliminar: () => puedeDarDeBaja.value,
  filaSeleccionada: () => {
    const f = filaSeleccionada.value
    if (!f) return null
    return {
      codigo: f.codigo == null ? undefined : String(f.codigo),
      descripcion: String(f.descripcion ?? ''),
      _nuevo: f._nuevo,
    }
  },
  eliminarApi: async (codigo) => {
    if (puedeEliminar.value) {
      await eliminar(codigo)
      return
    }
    const fila = filasTodas.value.find((f) => String(f.codigo) === codigo)
    if (!fila) throw new Error('Almacen no encontrado')
    await actualizar(codigo, { ...payloadAlmacen(fila), activo: false })
  },
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    if (!msg) {
      mostrarAviso(null)
      return
    }
    const esError =
      /no se|error|dependencias|obligatori/i.test(msg) && !/dado de baja|eliminado/i.test(msg)
    mostrarAviso(msg, esError ? 'error' : 'ok')
  },
  etiquetaEntidad: 'el almacen',
  mensajeExito: 'Almacen dado de baja',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar(q = serverQuery.value) {
  mostrarAviso(null)
  const params: Record<string, string | number | boolean> = { page: page.value, pageSize: pageSize.value }
  if (q) params.q = q
  if (filtroActivo.value === 'activos') params.activo = true
  if (filtroActivo.value === 'inactivos') params.activo = false
  const seqFiltro = filtroActivo.value
  await listar(params)
  if (!vistaMontada || seqFiltro !== filtroActivo.value) return
  filasTodas.value = items.value.map(clonarFila)
  filaNuevaDraft.value = almacenVacio()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}


function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function actualizarFila(_index: number, fila: AlmacenFila) {
  if (fila._nuevo) {
    filaNuevaDraft.value = { ...fila }
    return
  }
  const codigo = String(fila.codigo ?? '')
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo) === codigo ? { ...fila, _nuevo: false } : f
  )
}

async function onGuardarGrid() {
  const fila = filaSeleccionada.value
  if (!fila || fila._nuevo) return

  const errorValidacion = validarAlmacen(fila)
  if (errorValidacion) {
    mostrarAviso(errorValidacion, 'error')
    return
  }

  try {
    const payload = payloadAlmacen(fila)
    await actualizar(String(fila.codigo), payload)
    mostrarAviso(
      payload.activo === false ? 'Almacen dado de baja' : 'Almacen actualizado',
      'ok'
    )
    await cargar()
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo guardar el almacen'), 'error')
  }
}


async function onCambioFiltroActivo() {
  page.value = 1
  indiceSeleccionado.value = 0
  await cargar()
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    ficha.value = await obtener(codigo)
    indiceFicha.value = filasNavegacion.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    tabActiva.value = 'generales'
    vista.value = 'ficha'
    mostrarAviso(null)
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo cargar la ficha'), 'error')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mostrarAviso('Seleccione un almacen existente', 'aviso')
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = almacenFichaVacia()
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  tabActiva.value = 'generales'
  vista.value = 'ficha'
  mostrarAviso(null)
  camposInvalidos.value = []
  await nextTick()
  await nextTick()
  document.querySelector<HTMLInputElement>('[data-field-key="codigo"]')?.focus()
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const vacios = camposAlmacenObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = ALMACEN_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    mostrarAvisoModal('Campo obligatorio', `El campo "${label}" es obligatorio.`, key)
    return
  }
  camposInvalidos.value = []
  try {
    const payload = payloadAlmacen(ficha.value as AlmacenFila)
    if (esNuevo.value) {
      const creado = await crear(payload)
      mostrarAviso('Almacen creado correctamente', 'ok')
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      const codigo = String(ficha.value.codigo)
      await actualizar(codigo, payload)
      mostrarAviso(
        payload.activo === false ? 'Almacen dado de baja' : 'Almacen actualizado',
        'ok'
      )
      await cargar()
      await abrirFichaPorCodigo(codigo)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar el almacen')
    if (/ya existe|duplicad/i.test(msg)) {
      mostrarAvisoModal('Codigo duplicado', msg, 'codigo')
      return
    }
    mostrarAvisoModal('Error al guardar', msg)
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
  if ((!puedeEliminar.value && !puedeEditar.value) || esNuevo.value || !ficha.value.codigo) return
  confirmBorrarFichaOpen.value = true
}

async function confirmarBorrarFicha() {
  confirmBorrarFichaOpen.value = false
  if (!ficha.value.codigo) return
  const codigo = String(ficha.value.codigo)
  try {
    if (puedeEliminar.value) {
      await eliminar(codigo)
    } else {
      await actualizar(codigo, { ...payloadAlmacen(ficha.value as AlmacenFila), activo: false })
    }
    mostrarAviso('Almacen dado de baja', 'ok')
    await cargar()
    volverAlGrid()
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo dar de baja el almacen'), 'error')
  }
}

function volverAlGrid() {
  vista.value = 'grid'
  modoEdicion.value = false
  esNuevo.value = false
  camposInvalidos.value = []
  ficha.value = {}
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
  <section class="almacenes-view">
    <h2>Almacenes</h2>

    <p v-if="!puedeVer" class="flash flash-error">No tiene permiso para ver almacenes.</p>

    <template v-else>
      <p v-if="mensaje" class="flash" :class="`flash-${mensajeTipo}`" role="alert">{{ mensaje }}</p>
      <p v-if="error" class="flash flash-error" role="alert">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Almacenes"
            :columnas="almacenColumns"
            :filas="filas"
            @aviso="mensaje = $event"
          />
          <button
            v-if="puedeCrear"
            type="button"
            class="tool-btn"
            :disabled="loading"
            @click="onNuevo"
          >
            <ToolIcon name="nuevo" />
            <span>Nuevo</span>
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada || filaSeleccionada._nuevo"
            @click="abrirFicha()"
          >
            <ToolIcon name="ficha" />
            <span>Ficha</span>
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
            <ToolIcon name="guardar" />
            <span>Guardar</span>
          </button>
          <button
            v-if="puedeDarDeBaja"
            type="button"
            class="tool-btn danger"
            :disabled="loading"
            @click="solicitarEliminar"
          >
            <ToolIcon name="borrar" />
            <span>Dar de baja</span>
          </button>
        </div>

        <AlmacenesGrid
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :readonly="soloLectura"
          :loading="loading"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
          @search="onServerSearch"
        />

        <p class="hint">
          Use <strong>Nuevo</strong> para el formulario de alta. Doble clic o <strong>Ficha</strong>
          abre el detalle. En rejilla solo se guardan almacenes existentes.
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
                :disabled="loading || (!puedeEliminar && !puedeEditar) || esNuevo"
                @click="onBorrarFicha"
              >
                <ToolIcon name="borrar" />
                <span>Dar de baja</span>
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
          <label :class="{ 'campo-invalido': esCampoInvalido('codigo') }">
            Codigo *
            <DecimalInput
              :model-value="(ficha.codigo as number | null) ?? null"
              field-key="codigo"
              :empty-as-null="true"
              :integer="true"
              :readonly="!esNuevo"
              class="codigo-input"
              :required="true"
              @update:model-value="(v) => { ficha.codigo = v; limpiarCampoInvalido('codigo') }"
            />
          </label>
          <label class="descripcion-input" :class="{ 'campo-invalido': esCampoInvalido('descripcion') }">
            Descripcion *
            <input
              ref="descripcionInput"
              v-model="ficha.descripcion"
              data-field-key="descripcion"
              :readonly="soloLecturaFicha"
              maxlength="40"
              required
              @input="limpiarCampoInvalido('descripcion')"
            />
          </label>
        </div>

        <div class="tabs">
          <button
            v-for="tab in almacenTabs"
            :key="tab.id"
            type="button"
            class="tab"
            :class="{ active: tabActiva === tab.id }"
            @click="tabActiva = tab.id"
          >
            {{ tab.label }}
          </button>
        </div>

        <AlmacenTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :readonly="soloLecturaFicha"
          :codigo-read-only="!esNuevo"
          :ocultar-cabecera="true"
          @update:model-value="ficha = $event"
        />
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Dar de baja almacen"
        :message="confirmMessage.replace('eliminar', 'dar de baja').replace('Esta accion no se puede deshacer.', 'Pasara a Baja=1 (inactivo).')"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        title="Dar de baja almacen"
        message="Va a dar de baja este almacen (Baja=1). Podra verlo filtrando por Inactivos."
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
.almacenes-view h2 {
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
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.1rem;
  min-width: 4.25rem;
  padding: 0.3rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.72rem;
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
  max-width: 720px;
}

.ficha-header label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}

.codigo-input {
  width: 5rem;
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

.ficha-header label.campo-invalido {
  color: #b91c1c;
  font-weight: 600;
}

.ficha-header label.campo-invalido input {
  border-color: #dc2626;
  background: #fef2f2;
  box-shadow: 0 0 0 1px #fecaca;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.25rem 0.35rem 0;
  background: #fff;
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
  max-width: 720px;
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

.flash {
  position: sticky;
  top: 0;
  z-index: 40;
  margin: 0 0 0.65rem;
  padding: 0.55rem 0.85rem;
  border-radius: 6px;
  border: 1px solid transparent;
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.35;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
}

.flash-ok {
  color: #065f46;
  background: #d1fae5;
  border-color: #34d399;
}

.flash-error {
  color: #7f1d1d;
  background: #fee2e2;
  border-color: #f87171;
}

.flash-aviso {
  color: #92400e;
  background: #fef3c7;
  border-color: #fbbf24;
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
    display: none;
  }
}
</style>
