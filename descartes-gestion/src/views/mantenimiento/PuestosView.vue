<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, onMounted, ref, watch } from 'vue'
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
import { puestoTabs, puestoVacio, validarPuestoObligatorios } from '@/config/puestos-tabs'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid, { type GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import PuestoToolbar from '@/components/puestos/PuestoToolbar.vue'
import PuestoTabForm from '@/components/puestos/PuestoTabForm.vue'
import { api } from '@/api/client'

const ENTIDAD = 'puestos-trabajo'
const MODULO = 'puestos-trabajo'
const FILTER_KEYS = ['codigo', 'descripcion', 'tiendaCodigo']
const columns = getGridColumns(ENTIDAD)

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))
const soloLecturaGrid = computed(() => !puedeCrear.value && !puedeEditar.value)

const optionsMap = ref<GridOptionsMap>({})
const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const mensaje = ref<string | null>(null)

const tabActiva = ref(puestoTabs[0].id)
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

const tabSeleccionada = computed(() => puestoTabs.find((t) => t.id === tabActiva.value) ?? puestoTabs[0])
const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
const totalFicha = computed(() => filasTodas.value.filter((f) => !f._nuevo).length)

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
  etiquetaEntidad: 'el puesto',
  mensajeExito: 'Puesto dado de baja',
})

onMounted(async () => {
  if (!puedeVer.value) return
  try {
    const [tiendasRes, trabajadoresRes] = await Promise.all([
      api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } }),
      api.get('/api/mantenimiento/trabajadores', { params: { activo: true, pageSize: 500 } }),
    ])
    const opciones = (lista: { codigo: string; nombre: string }[]) =>
      lista.map((x) => ({
        value: String(x.codigo).trim(),
        label: `${String(x.codigo).trim()} - ${x.nombre}`,
      }))
    optionsMap.value = {
      tiendas: opciones(tiendasRes.data.items ?? []),
      trabajadores: opciones(trabajadoresRes.data.items ?? []),
    }
  } catch {
    optionsMap.value = { tiendas: [], trabajadores: [] }
  }
  await cargar()
})

async function cargar() {
  mensaje.value = null
  await listar({ page: page.value, pageSize: pageSize.value })
  filasTodas.value = items.value.map((item) => clonarFilaGrid(item, columns))
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
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
    mensaje.value = 'Puesto actualizado'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el puesto')
  }
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    ficha.value = await obtener(codigo)
    indiceFicha.value = filasTodas.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    tabActiva.value = 'generales'
    vista.value = 'ficha'
    mensaje.value = null
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mensaje.value = 'Seleccione un puesto existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = puestoVacio()
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  tabActiva.value = 'generales'
  vista.value = 'ficha'
  mensaje.value = null
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const errorValidacion = validarPuestoObligatorios(ficha.value)
  if (errorValidacion) {
    mensaje.value = errorValidacion
    return
  }
  try {
    if (esNuevo.value) {
      const creado = await crear(ficha.value)
      mensaje.value = 'Puesto creado correctamente'
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(String(ficha.value.codigo), ficha.value)
      mensaje.value = 'Puesto actualizado'
      await cargar()
      await abrirFichaPorCodigo(String(ficha.value.codigo))
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el puesto')
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
  if (!confirm('Dar de baja este puesto?')) return
  try {
    await eliminar(String(ficha.value.codigo))
    mensaje.value = 'Puesto dado de baja'
    await cargar()
    volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo dar de baja')
  }
}

function volverAlGrid() {
  vista.value = 'grid'
  modoEdicion.value = false
  esNuevo.value = false
  ficha.value = {}
}

async function onPrimero() {
  if (totalFicha.value === 0) return
  const fila = filasTodas.value[0]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onAnterior() {
  if (indiceFicha.value <= 0) return
  const fila = filasTodas.value[indiceFicha.value - 1]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onSiguiente() {
  const max = totalFicha.value - 1
  if (indiceFicha.value < 0 || indiceFicha.value >= max) return
  const fila = filasTodas.value[indiceFicha.value + 1]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onUltimo() {
  const max = totalFicha.value - 1
  if (max < 0) return
  const fila = filasTodas.value[max]
  if (fila?.codigo != null) await abrirFichaPorCodigo(String(fila.codigo))
}
</script>

<template>
  <section class="puestos-view">
    <h2>Puestos de trabajo</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver puestos.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Puestos de trabajo"
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
          :options-map="optionsMap"
          v-model:filters="filtros"
          :readonly="soloLecturaGrid"
          :loading="loading"
          :total-servidor="total"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
        />

        <p class="hint">
          Filtra por <strong>Codigo</strong> y <strong>Descripcion</strong> escribiendo bajo cada columna. Doble clic o
          <strong>Ficha</strong> abre el detalle.
        </p>
        </div>
      </template>

      <template v-else>
        <div class="ficha-shell">
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

          <PuestoToolbar
            :puede-crear="puedeCrear"
            :puede-editar="puedeEditar"
            :puede-eliminar="puedeEliminar"
            :puede-guardar="puedeCrear || puedeEditar"
            :modo-edicion="modoEdicion || esNuevo"
            :indice="indiceFicha < 0 ? undefined : indiceFicha"
            :total="totalFicha"
            :loading="loading"
            @nuevo="onNuevo"
            @modificar="onModificar"
            @borrar="onBorrarFicha"
            @buscar="volverAlGrid"
            @guardar="onGuardarFicha"
            @cancelar="onCancelarFicha"
            @primero="onPrimero"
            @anterior="onAnterior"
            @siguiente="onSiguiente"
            @ultimo="onUltimo"
          />
        </div>

        <div class="ficha-header">
          <label>
            Codigo *
            <input
              v-model="ficha.codigo"
              :readonly="!esNuevo"
              maxlength="3"
              class="codigo-input"
              required
            />
          </label>
          <label class="descripcion-input">
            Descripcion *
            <input v-model="ficha.descripcion" :readonly="soloLecturaFicha" maxlength="50" required />
          </label>
        </div>

        <div class="tabs">
          <button
            v-for="tab in puestoTabs"
            :key="tab.id"
            type="button"
            class="tab"
            :class="{ active: tabActiva === tab.id }"
            @click="tabActiva = tab.id"
          >
            {{ tab.label }}
          </button>
        </div>

        <PuestoTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :readonly="soloLecturaFicha"
          :codigo-read-only="!esNuevo"
          :ocultar-cabecera="true"
          @update:model-value="ficha = $event"
        />
        </div>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar puesto"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
    </template>
  </section>
</template>

<style scoped>
.puestos-view h2 {
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

.toolbar-spacer {
  flex: 1;
}

.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
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

.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
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

.ficha-shell {
  width: 100%;
  max-width: 64rem;
  box-sizing: border-box;
}

.puestos-view .sticky-chrome {
  width: 100%;
}

.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem 0.65rem;
  align-items: end;
  width: 100%;
  box-sizing: border-box;
  padding: 0.35rem 0.5rem;
  background: #fff;
  border: 1px solid #c5cdd8;
  border-bottom: none;
  border-radius: 8px 8px 0 0;
}

.ficha-header label {
  display: grid;
  gap: 0.1rem;
  font-size: 0.75rem;
}

.codigo-input {
  width: 3.5rem;
}

.descripcion-input {
  flex: 1;
  min-width: 12rem;
}

.descripcion-input input {
  width: 100%;
  box-sizing: border-box;
}

.ficha-header input {
  padding: 0.15rem 0.35rem;
  border: 1px solid #c5cdd8;
  border-radius: 3px;
  font-size: 0.78rem;
  height: 1.65rem;
  box-sizing: border-box;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.25rem 0.35rem 0;
  background: #fff;
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
  width: 100%;
  box-sizing: border-box;
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
