<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  clonarFilaGrid,
  esFilaActiva,
  filaVaciaDesdeColumnas,
  getGridColumns,
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
import ListPagination from '@/components/common/ListPagination.vue'
import EntidadGrid, { type GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const ENTIDAD = 'trabajadores'
const MODULO = 'trabajadores'
const FILTER_KEYS = ['codigo', 'nombre']
const columns = getGridColumns(ENTIDAD)

function trabajadorVacio(): Record<string, unknown> {
  return {
    codigo: '',
    nombre: '',
    comision: 0,
    agente: false,
    vendedor: false,
    operario: false,
    tecnico: false,
    observaciones: '',
    usuarioCodigo: '',
    password: '',
    tarjeta: 0,
    conceptoDescuadre: '',
    horaInicio: '',
    horaFinal: '',
    horaInicio2: '',
    horaFinal2: '',
    activo: true,
  }
}

function tieneRolTrabajador(ficha: Record<string, unknown>): boolean {
  return !!(ficha.agente || ficha.vendedor || ficha.operario || ficha.tecnico)
}

function validarTrabajador(ficha: Record<string, unknown>): string | null {
  if (!String(ficha.codigo ?? '').trim()) return 'El codigo es obligatorio'
  if (!String(ficha.nombre ?? '').trim()) return 'La descripcion es obligatoria'
  if (!String(ficha.usuarioCodigo ?? '').trim()) return 'El usuario es obligatorio'
  if (!tieneRolTrabajador(ficha)) {
    return 'Debe marcar al menos un tipo: Agente, Vendedor, Operario o Tecnico'
  }
  const password = String(ficha.password ?? '').trim()
  if (password !== '' && !/^-?\d+$/.test(password)) {
    return 'La contrasena debe ser numerica'
  }
  return null
}

const CAMPOS_OBLIGATORIOS_TRABAJADOR: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'nombre', label: 'Descripcion' },
  { key: 'roles', label: 'Tipo' },
  { key: 'usuarioCodigo', label: 'Usuario' },
]

function camposInvalidosTrabajador(ficha: Record<string, unknown>): string[] {
  const campos: string[] = []
  if (!String(ficha.codigo ?? '').trim()) campos.push('codigo')
  if (!String(ficha.nombre ?? '').trim()) campos.push('nombre')
  if (!tieneRolTrabajador(ficha)) campos.push('roles')
  if (!String(ficha.usuarioCodigo ?? '').trim()) campos.push('usuarioCodigo')
  return campos
}

function primerCampoObligatorioVacio(ficha: Record<string, unknown>): string | null {
  return camposInvalidosTrabajador(ficha)[0] ?? null
}

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar } = useMantenimiento(() => ENTIDAD)
pageSize.value = 50

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const filtroActivo = ref<'activos' | 'todos' | 'inactivos'>('activos')
const mensaje = ref<string | null>(null)
const confirmBorrarFichaOpen = ref(false)
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Aviso')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const camposInvalidos = ref<string[]>([])
const codigoInput = ref<HTMLInputElement | null>(null)
const optionsMap = ref<GridOptionsMap>({})
const usuariosOpciones = ref<{ value: string; label: string }[]>([])
let vistaMontada = true

onBeforeUnmount(() => {
  vistaMontada = false
})

function mostrarAvisoModal(titulo: string, mensajeTexto: string, campo?: string | null) {
  campoAvisoActual.value = campo ?? null
  if (campo) camposInvalidos.value = [campo]
  avisoModalTitulo.value = titulo
  avisoModalMensaje.value = mensajeTexto
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

function codigoTrabajadorExiste(codigo: string): boolean {
  const c = codigo.trim().toLowerCase()
  if (!c) return false
  return filasTodas.value.some((f) => String(f.codigo ?? '').trim().toLowerCase() === c)
}

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
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && filaSeleccionada.value && !filaSeleccionada.value._nuevo
)
const puedeGuardarGrid = computed(
  () => puedeEditar.value && filasTodas.value.some((f) => f._dirty && !f._nuevo)
)
const soloLecturaGrid = computed(() => !puedeEditar.value)
const editableKeysGrid = computed(() => (puedeEditar.value ? ['activo'] : []))

const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
const totalFicha = computed(() => filasTodas.value.filter((f) => !f._nuevo).length)
const puedeGuardarFicha = computed(
  () => (puedeCrear.value || puedeEditar.value) && (modoEdicion.value || esNuevo.value)
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
      descripcion: String(f.nombre ?? ''),
      _nuevo: f._nuevo,
    }
  },
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    mensaje.value = msg
  },
  etiquetaEntidad: 'el trabajador',
  mensajeExito: 'Trabajador eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargarUsuarios()
  await cargar()
})

async function cargarUsuarios() {
  try {
    const { data } = await api.get('/api/mantenimiento/usuarios', { params: { activo: true, pageSize: 500 } })
    usuariosOpciones.value = (data.items ?? []).map((u: { codigo: string; nombre: string }) => ({
      value: String(u.codigo ?? '').trim(),
      label: `${String(u.codigo ?? '').trim()} - ${u.nombre}`,
    }))
    optionsMap.value = { usuarios: usuariosOpciones.value }
  } catch {
    usuariosOpciones.value = []
    optionsMap.value = { usuarios: [] }
  }
}

async function cargar() {
  mensaje.value = null
  const params: Record<string, string | number | boolean> = { page: page.value, pageSize: pageSize.value }
  if (filtroActivo.value === 'activos') params.activo = true
  if (filtroActivo.value === 'inactivos') params.activo = false
  const seqFiltro = filtroActivo.value
  await listar(params)
  if (!vistaMontada || seqFiltro !== filtroActivo.value) return
  filasTodas.value = items.value.map((item) => clonarFilaGrid(item, columns))
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

function onPage(p: number) {
  page.value = p
  void cargar()
}

function onPageSize(n: number) {
  pageSize.value = n
  page.value = 1
  void cargar()
}

async function onCambioFiltroActivo() {
  page.value = 1
  indiceSeleccionado.value = 0
  await cargar()
}

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function actualizarFila(index: number, fila: GridFila) {
  indiceSeleccionado.value = index
  const codigo = String(fila.codigo ?? '').trim()
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo ?? '').trim() === codigo ? { ...fila, _nuevo: false, _dirty: true } : f
  )
}

async function onGuardarGrid() {
  const dirty = filasTodas.value.filter((f) => f._dirty && !f._nuevo)
  if (dirty.length === 0) {
    mensaje.value = 'No hay cambios para guardar'
    return
  }

  try {
    let bajas = 0
    let actualizadas = 0
    for (const fila of dirty) {
      const codigo = String(fila.codigo ?? '').trim()
      if (!codigo) continue
      if (!esFilaActiva(fila)) {
        await eliminar(codigo)
        bajas += 1
      } else {
        await actualizar(codigo, { activo: true })
        actualizadas += 1
      }
    }
    await cargar()
    if (bajas > 0 && actualizadas === 0) {
      mensaje.value = bajas === 1 ? 'Trabajador dado de baja' : `${bajas} trabajadores dados de baja`
    } else if (bajas > 0) {
      mensaje.value = `Guardado: ${actualizadas} reactivados, ${bajas} dados de baja`
    } else {
      mensaje.value =
        actualizadas === 1 ? 'Trabajador actualizado' : `${actualizadas} trabajadores actualizados`
    }
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el trabajador')
    mostrarAvisoModal('Error al guardar', mensaje.value)
  }
}

function onListado() {
  window.print()
}

function cargarFichaDesdeData(data: Record<string, unknown>, codigo: string) {
  ficha.value = {
    ...trabajadorVacio(),
    ...data,
    comision: data.comision ?? 0,
    password: '',
    tarjeta: data.tarjeta ?? 0,
    conceptoDescuadre: String(data.conceptoDescuadre ?? '').trim(),
    horaInicio: data.horaInicio ?? '',
    horaFinal: data.horaFinal ?? '',
    horaInicio2: data.horaInicio2 ?? '',
    horaFinal2: data.horaFinal2 ?? '',
    observaciones: data.observaciones ?? '',
    activo: data.activo !== false && data.activo !== 0,
  }
  indiceFicha.value = filasTodas.value.findIndex((f) => String(f.codigo) === codigo)
  modoEdicion.value = false
  esNuevo.value = false
  vista.value = 'ficha'
  mensaje.value = null
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mensaje.value = 'Seleccione un trabajador existente'
    return
  }
  try {
    const data = await obtener(String(fila.codigo))
    cargarFichaDesdeData(data, String(fila.codigo))
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    const data = await obtener(codigo)
    cargarFichaDesdeData(data, codigo)
    camposInvalidos.value = []
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = trabajadorVacio()
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

function payloadFicha(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    codigo: ficha.value.codigo,
    nombre: ficha.value.nombre,
    comision: Number(ficha.value.comision ?? 0),
    agente: !!ficha.value.agente,
    vendedor: !!ficha.value.vendedor,
    operario: !!ficha.value.operario,
    tecnico: !!ficha.value.tecnico,
    observaciones: ficha.value.observaciones ?? '',
    usuarioCodigo: ficha.value.usuarioCodigo || null,
    tarjeta: ficha.value.tarjeta === '' || ficha.value.tarjeta == null ? 0 : Number(ficha.value.tarjeta),
    conceptoDescuadre: String(ficha.value.conceptoDescuadre ?? '').trim() || null,
    horaInicio: ficha.value.horaInicio || null,
    horaFinal: ficha.value.horaFinal || null,
    horaInicio2: ficha.value.horaInicio2 || null,
    horaFinal2: ficha.value.horaFinal2 || null,
    activo: ficha.value.activo === true || ficha.value.activo === 1 || ficha.value.activo === '1',
  }
  const password = String(ficha.value.password ?? '').trim()
  if (password !== '') {
    payload.password = password
  }
  return payload
}

async function onGuardarFicha() {
  const primerVacio = primerCampoObligatorioVacio(ficha.value)
  if (primerVacio) {
    const label =
      CAMPOS_OBLIGATORIOS_TRABAJADOR.find((c) => c.key === primerVacio)?.label ?? primerVacio
    const msg =
      primerVacio === 'roles'
        ? 'Debe marcar al menos un tipo: Agente, Vendedor, Operario o Tecnico'
        : `El campo "${label}" es obligatorio.`
    mensaje.value = msg
    mostrarAvisoModal(
      primerVacio === 'roles' ? 'Tipo obligatorio' : 'Campo obligatorio',
      msg,
      primerVacio
    )
    return
  }
  camposInvalidos.value = []

  const errorValidacion = validarTrabajador(ficha.value)
  if (errorValidacion) {
    mensaje.value = errorValidacion
    mostrarAvisoModal('Datos incompletos', errorValidacion)
    return
  }

  const codigo = String(ficha.value.codigo ?? '').trim()
  if (esNuevo.value && codigoTrabajadorExiste(codigo)) {
    const msg = `Ya existe un trabajador con el codigo "${codigo}".`
    mensaje.value = msg
    mostrarAvisoModal('Codigo duplicado', msg, 'codigo')
    return
  }

  try {
    const payload = payloadFicha()
    if (esNuevo.value) {
      const creado = await crear(payload)
      mensaje.value = 'Trabajador creado correctamente'
      camposInvalidos.value = []
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else if (payload.activo === false) {
      await eliminar(codigo)
      mensaje.value = 'Trabajador dado de baja'
      camposInvalidos.value = []
      await cargar()
      volverAlGrid()
      return
    } else {
      await actualizar(codigo, payload)
      mensaje.value = 'Trabajador actualizado'
      camposInvalidos.value = []
      await cargar()
      await abrirFichaPorCodigo(codigo)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar el trabajador')
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

function onBorrarFicha() {
  if (!puedeEliminar.value || esNuevo.value || !ficha.value.codigo) return
  confirmBorrarFichaOpen.value = true
}

async function confirmarBorrarFicha() {
  confirmBorrarFichaOpen.value = false
  if (!ficha.value.codigo) return
  try {
    await eliminar(String(ficha.value.codigo))
    mensaje.value = 'Trabajador eliminado'
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
  <section class="trabajadores-view">
    <h2>Trabajadores</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver trabajadores.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="toolbar">
          <button type="button" class="tool-btn" @click="onListado">Listado</button>
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
            v-if="puedeEditar"
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
            Dar de baja
          </button>
        </div>

        <EntidadGrid
          :columns="columns"
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :options-map="optionsMap"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :readonly="soloLecturaGrid"
          :editable-keys="editableKeysGrid"
          :loading="loading"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
        />

        <ListPagination
          :page="page"
          :page-size="pageSize"
          :total="total"
          :loading="loading"
          @update:page="onPage"
          @update:page-size="onPageSize"
        />

        <p class="hint">
          Desmarque <strong>Activo</strong> y pulse <strong>Guardar</strong> para dar de baja. Filtro
          <strong>Estado</strong> para ver activos, todos o inactivos.
        </p>
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

        <div class="ficha">
          <fieldset class="form-section">
            <legend>Datos del trabajador</legend>
            <div class="ficha-campos">
              <label :class="{ 'campo-invalido': camposInvalidos.includes('codigo') }">
                Codigo *
                <input
                  ref="codigoInput"
                  v-model="ficha.codigo"
                  data-field-key="codigo"
                  :readonly="!esNuevo"
                  maxlength="4"
                  class="codigo-input"
                />
              </label>
              <label
                class="nombre-input"
                :class="{ 'campo-invalido': camposInvalidos.includes('nombre') }"
              >
                Descripcion *
                <input
                  v-model="ficha.nombre"
                  data-field-key="nombre"
                  :readonly="soloLecturaFicha"
                  maxlength="50"
                />
              </label>
              <label>
                Comision
                <input
                  v-model.number="ficha.comision"
                  type="number"
                  step="any"
                  :readonly="soloLecturaFicha"
                  class="comision-input"
                />
              </label>
            </div>

            <div
              class="checks-row"
              :class="{ 'campo-invalido-roles': camposInvalidos.includes('roles') }"
            >
              <span class="checks-label">Tipo * <small>(al menos uno)</small></span>
              <label class="check-field">
                <input
                  v-model="ficha.agente"
                  type="checkbox"
                  data-field-key="roles"
                  :disabled="soloLecturaFicha"
                />
                Agente
              </label>
              <label class="check-field">
                <input v-model="ficha.vendedor" type="checkbox" :disabled="soloLecturaFicha" />
                Vendedor
              </label>
              <label class="check-field">
                <input v-model="ficha.operario" type="checkbox" :disabled="soloLecturaFicha" />
                Operario
              </label>
              <label class="check-field">
                <input v-model="ficha.tecnico" type="checkbox" :disabled="soloLecturaFicha" />
                Tecnico
              </label>
              <label class="check-field">
                <input v-model="ficha.activo" type="checkbox" :disabled="soloLecturaFicha" />
                Activo
              </label>
            </div>

            <label class="obs-field">
              Observaciones
              <textarea
                v-model="ficha.observaciones"
                :readonly="soloLecturaFicha"
                rows="3"
              ></textarea>
            </label>

            <div class="ficha-campos">
              <label :class="{ 'campo-invalido': camposInvalidos.includes('usuarioCodigo') }">
                Usuario *
                <select
                  v-model="ficha.usuarioCodigo"
                  data-field-key="usuarioCodigo"
                  :disabled="soloLecturaFicha"
                >
                  <option value="">-- Seleccionar --</option>
                  <option v-for="opt in usuariosOpciones" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </label>
              <label class="password-input">
                Password
                <input
                  v-model="ficha.password"
                  type="text"
                  inputmode="numeric"
                  :readonly="soloLecturaFicha"
                  :placeholder="esNuevo ? 'Numerica (visible)' : 'Dejar vacio para no cambiar'"
                  autocomplete="off"
                />
              </label>
              <label>
                Tarjeta
                <input
                  v-model.number="ficha.tarjeta"
                  type="number"
                  step="1"
                  :readonly="soloLecturaFicha"
                  class="tarjeta-input"
                />
              </label>
              <label>
                Con.Des
                <input
                  v-model="ficha.conceptoDescuadre"
                  :readonly="soloLecturaFicha"
                  maxlength="2"
                  class="concepto-input"
                />
              </label>
            </div>

            <div class="horas-row">
              <label>
                Hora inicio
                <input v-model="ficha.horaInicio" type="time" :readonly="soloLecturaFicha" />
              </label>
              <label>
                Hora final
                <input v-model="ficha.horaFinal" type="time" :readonly="soloLecturaFicha" />
              </label>
              <label>
                Hora inicio 2
                <input v-model="ficha.horaInicio2" type="time" :readonly="soloLecturaFicha" />
              </label>
              <label>
                Hora final 2
                <input v-model="ficha.horaFinal2" type="time" :readonly="soloLecturaFicha" />
              </label>
            </div>
          </fieldset>
        </div>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar trabajador"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        title="Eliminar trabajador"
        message="Va a eliminar este trabajador. Esta accion no se puede deshacer."
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
.trabajadores-view h2 {
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

.ficha {
  max-width: 760px;
  display: grid;
  gap: 0.65rem;
  padding: 0.5rem 0.65rem 0.65rem;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
}

.form-section {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
  display: grid;
  gap: 0.65rem;
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.ficha-campos,
.horas-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
}

.ficha-campos label,
.horas-row label,
.obs-field {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
  color: #475569;
}

.campo-invalido {
  color: #b91c1c !important;
  font-weight: 700;
}

.campo-invalido input,
.campo-invalido select {
  border-color: #dc2626 !important;
  background: #fef2f2 !important;
  box-shadow: 0 0 0 1px #fca5a5;
}

.checks-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1rem;
  align-items: center;
  padding: 0.35rem 0.45rem;
  border-radius: 4px;
}

.checks-label {
  font-size: 0.78rem;
  color: #475569;
  font-weight: 600;
}

.checks-label small {
  font-weight: 400;
  color: #64748b;
}

.campo-invalido-roles {
  background: #fef2f2;
  border: 1px solid #fca5a5;
}

.campo-invalido-roles .checks-label {
  color: #b91c1c;
}

.codigo-input {
  width: 5rem;
}

.nombre-input {
  flex: 1;
  min-width: 180px;
}

.comision-input {
  width: 6rem;
}

.tarjeta-input {
  width: 6rem;
}

.concepto-input {
  width: 4rem;
  text-transform: uppercase;
}

.password-input {
  min-width: 140px;
}

.check-field {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: #334155;
}

.obs-field textarea {
  width: 100%;
  min-height: 4rem;
  resize: vertical;
  padding: 0.35rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  font-family: inherit;
}

.ficha-campos input[type='text'],
.ficha-campos input[type='number'],
.ficha-campos input[type='password'],
.ficha-campos input:not([type]),
.ficha-campos select,
.horas-row input[type='time'] {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  min-height: 1.7rem;
}

.ficha-campos input:read-only,
.ficha-campos input:disabled,
.ficha-campos select:disabled,
.horas-row input:read-only,
.horas-row input:disabled,
.obs-field textarea:read-only {
  background: #f1f5f9;
  color: #64748b;
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
