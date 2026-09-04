<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { EntidadConfig } from '@/config/entidades'
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
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid, { type GridOptionsMap } from './EntidadGrid.vue'
import MantenimientoForm from './MantenimientoForm.vue'

const props = defineProps<{
  entidad: string
  config: EntidadConfig
}>()

const ENTITY_FILTER_KEYS: Record<string, string[]> = {
  'formas-pago': ['codigo', 'descripcion'],
}

const { puede } = usePermisos()
const { items, loading, error, listar, obtener, crear, actualizar, eliminar } = useMantenimiento(
  () => props.entidad
)

const columns = computed(() => getGridColumns(props.entidad))
const filterKeys = computed(() => ENTITY_FILTER_KEYS[props.entidad] ?? [])
const tieneFiltrosColumnas = computed(() => filterKeys.value.length > 0)
const tieneColumnaActivo = computed(() => columns.value.some((c) => c.key === 'activo'))
const busqueda = ref('')
const filtroActivo = ref<'todos' | 'activos' | 'inactivos'>('activos')
const mostrarBuscar = ref(false)
const modo = ref<'grid' | 'formulario'>('grid')
const esNuevo = ref(false)
const seleccionado = ref<Record<string, unknown>>({})
const mensaje = ref<string | null>(null)
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>({})
const indiceSeleccionado = ref(0)
const optionsMap = ref<GridOptionsMap>({})

const puedeCrear = computed(() => puede(props.config.modulo, 'crear'))
const puedeEditar = computed(() => puede(props.config.modulo, 'editar'))
const puedeEliminar = computed(() => puede(props.config.modulo, 'eliminar'))
const puedeVer = computed(() => puede(props.config.modulo, 'ver'))
const soloLecturaGrid = computed(() => !puedeCrear.value && !puedeEditar.value)

const filas = computed<GridFila[]>(() => {
  const datos = filasTodas.value.filter((f) => !f._nuevo)
  const filtradas = tieneFiltrosColumnas.value
    ? aplicarFiltrosColumnas(datos, filtros.value)
    : datos
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaVaciaDesdeColumnas(columns.value)]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const puedeMostrarGuardar = computed(() => puedeCrear.value || puedeEditar.value)
const puedeGuardar = computed(() => {
  const fila = filaSeleccionada.value
  if (!fila || fila._nuevo) return false
  return puedeEditar.value
})
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && filaSeleccionada.value && !filaSeleccionada.value._nuevo
)

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
      descripcion: String(f.descripcion ?? f.nombre ?? ''),
      _nuevo: f._nuevo,
    }
  },
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    mensaje.value = msg
  },
  etiquetaEntidad: 'el registro',
  mensajeExito: 'Registro eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  filtros.value = filtrosIniciales(filterKeys.value)
  await cargarOpciones()
  await cargar()
})

watch(
  () => props.entidad,
  async () => {
    modo.value = 'grid'
    esNuevo.value = false
    seleccionado.value = {}
    mensaje.value = null
    busqueda.value = ''
    filtroActivo.value = 'activos'
    mostrarBuscar.value = false
    filtros.value = filtrosIniciales(filterKeys.value)
    indiceSeleccionado.value = 0
    if (puedeVer.value) {
      await cargarOpciones()
      await cargar()
    }
  }
)

async function cargarOpciones() {
  const sources = new Set(
    columns.value.map((c) => c.optionsSource).filter((s): s is NonNullable<typeof s> => Boolean(s))
  )
  const next: GridOptionsMap = {}

  async function load(source: string, path: string, labelKey: 'nombre' | 'descripcion') {
    const { data } = await api.get(`/api/mantenimiento/${path}`, {
      params: { activo: true, pageSize: 500 },
    })
    next[source] = (data.items ?? []).map((item: Record<string, unknown>) => ({
      value: String(item.codigo ?? '').trim(),
      label: `${String(item.codigo ?? '').trim()} - ${String(item[labelKey] ?? '')}`,
    }))
  }

  const jobs: Promise<void>[] = []
  if (sources.has('roles')) jobs.push(load('roles', 'roles', 'nombre'))
  if (sources.has('usuarios')) jobs.push(load('usuarios', 'usuarios', 'nombre'))
  if (sources.has('tiendas')) jobs.push(load('tiendas', 'tiendas', 'nombre'))
  if (sources.has('trabajadores')) jobs.push(load('trabajadores', 'trabajadores', 'nombre'))
  if (sources.has('almacenes')) jobs.push(load('almacenes', 'almacenes', 'descripcion'))
  if (sources.has('impuestos')) jobs.push(load('impuestos', 'impuestos', 'descripcion'))
  if (sources.has('proveedores')) jobs.push(load('proveedores', 'proveedores', 'nombre'))
  await Promise.all(jobs)
  optionsMap.value = next
}

async function cargar() {
  mensaje.value = null
  const params: Record<string, unknown> = { page: 1, pageSize: 500 }
  if (!tieneFiltrosColumnas.value) {
    params.q = busqueda.value
  }
  if (filtroActivo.value === 'activos') params.activo = true
  if (filtroActivo.value === 'inactivos') params.activo = false
  await listar(params)
  filasTodas.value = items.value.map((item) => clonarFilaGrid(item, columns.value))
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

async function aplicarBusqueda() {
  indiceSeleccionado.value = 0
  await cargar()
}

async function aplicarFiltroActivo() {
  indiceSeleccionado.value = 0
  await cargar()
}

function onListado() {
  window.print()
}

function onNuevo() {
  if (!puedeCrear.value) return
  esNuevo.value = true
  seleccionado.value = { activo: true }
  modo.value = 'formulario'
  mensaje.value = null
}

async function abrirFormulario(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mensaje.value = 'Seleccione un registro existente'
    return
  }
  try {
    seleccionado.value = await obtener(String(fila.codigo))
    esNuevo.value = false
    modo.value = 'formulario'
    mensaje.value = null
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar el registro')
  }
}

async function onGuardarGrid() {
  const fila = filaSeleccionada.value
  if (!fila || fila._nuevo) return

  const errorValidacion = validarFilaGrid(fila, columns.value)
  if (errorValidacion) {
    mensaje.value = errorValidacion
    return
  }

  try {
    await actualizar(String(fila.codigo), payloadFilaGrid(fila, columns.value))
    mensaje.value = 'Registro actualizado'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar')
  }
}

async function guardarFormulario() {
  try {
    if (esNuevo.value) {
      await crear(seleccionado.value)
      mensaje.value = 'Registro creado'
    } else {
      await actualizar(String(seleccionado.value.codigo), seleccionado.value)
      mensaje.value = 'Registro actualizado'
    }
    modo.value = 'grid'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'Error al guardar')
  }
}

function cancelarFormulario() {
  modo.value = 'grid'
  esNuevo.value = false
  seleccionado.value = {}
}
</script>

<template>
  <section class="entidad-grid-view">
    <h2>{{ config.titulo }}</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver esta entidad.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="modo === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <button
            v-if="!tieneFiltrosColumnas"
            type="button"
            class="tool-btn"
            :class="{ active: mostrarBuscar }"
            @click="mostrarBuscar = !mostrarBuscar"
          >
            Buscar
          </button>
          <button type="button" class="tool-btn" @click="onListado">Listado</button>
          <button v-if="puedeCrear" type="button" class="tool-btn" :disabled="loading" @click="onNuevo">
            Nuevo
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada || filaSeleccionada._nuevo"
            @click="abrirFormulario()"
          >
            Ficha
          </button>

          <div class="toolbar-spacer"></div>

          <select
            v-if="tieneFiltrosColumnas && tieneColumnaActivo"
            v-model="filtroActivo"
            class="filtro-activo-select"
            @change="aplicarFiltroActivo"
          >
            <option value="activos">Activos</option>
            <option value="todos">Todos</option>
            <option value="inactivos">Inactivos</option>
          </select>

          <button
            v-if="puedeMostrarGuardar"
            type="button"
            class="tool-btn primary"
            :disabled="loading || !puedeGuardar"
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

        <div v-if="!tieneFiltrosColumnas && mostrarBuscar" class="buscar-panel">
          <input v-model="busqueda" placeholder="Buscar..." @keyup.enter="aplicarBusqueda" />
          <select v-if="tieneColumnaActivo" v-model="filtroActivo" @change="aplicarBusqueda">
            <option value="activos">Activos</option>
            <option value="todos">Todos</option>
            <option value="inactivos">Inactivos</option>
          </select>
          <button type="button" @click="aplicarBusqueda">Buscar</button>
        </div>

        <EntidadGrid
          v-if="columns.length"
          :columns="columns"
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :filterable-keys="tieneFiltrosColumnas ? filterKeys : undefined"
          v-model:filters="filtros"
          :options-map="optionsMap"
          :readonly="soloLecturaGrid"
          :loading="loading"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFormulario"
          @nuevo="onNuevo"
        />

        <p v-else class="error">No hay columnas de rejilla configuradas para esta entidad.</p>

        <p v-if="tieneFiltrosColumnas" class="hint">
          Filtra por columnas con el embudo. La fila <strong>*</strong> no se edita aqui: use
          <strong>Nuevo</strong> o doble clic para crear en ficha.
        </p>
        <p v-else class="hint">
          La fila <strong>*</strong> no se edita aqui: use <strong>Nuevo</strong> o doble clic para crear en ficha.
        </p>
        </div>
      </template>

      <template v-else>
        <button type="button" class="btn-volver" @click="cancelarFormulario">← Volver a la rejilla</button>
        <MantenimientoForm
          v-model="seleccionado"
          :campos="config.campos"
          :es-nuevo="esNuevo"
          :entidad="entidad"
          @guardar="guardarFormulario"
          @cancelar="cancelarFormulario"
        />
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar registro"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
    </template>
  </section>
</template>

<style scoped>
.entidad-grid-view h2 {
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

.tool-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.filtro-activo-select {
  padding: 0.35rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
}

.buscar-panel {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
  padding: 0.5rem;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.buscar-panel input {
  flex: 1;
  min-width: 12rem;
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
}

.buscar-panel select {
  padding: 0.45rem 0.55rem;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
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
  .buscar-panel,
  .hint,
  .btn-volver,
  h2 {
    display: none;
  }
}
</style>
