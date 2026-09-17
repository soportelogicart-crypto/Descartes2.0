<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import {
  clonarFilaGrid,
  esFilaActiva,
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
  contadoresFieldsDer,
  contadoresFieldsIzq,
  tiendaTabs,
  tiendaVacia,
  tabDeCampoTienda,
  validarTiendaObligatorios,
} from '@/config/tiendas-tabs'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import TiendaToolbar from '@/components/tiendas/TiendaToolbar.vue'
import TiendaTabForm from '@/components/tiendas/TiendaTabForm.vue'
import TiendaContadoresModal from '@/components/tiendas/TiendaContadoresModal.vue'

const MODULO = 'tiendas'
const FILTER_KEYS = ['codigo', 'nombre', 'nif', 'poblacion', 'telefono1']
const columns = getGridColumns('tiendas')

const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar } = useMantenimiento(() => MODULO)

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))
const soloLecturaGrid = computed(() => !puedeCrear.value && !puedeEditar.value)

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const filtroActivo = ref<'activos' | 'todos' | 'inactivos'>('activos')
const mensaje = ref<string | null>(null)
const mensajeTipo = ref<'ok' | 'error' | 'aviso'>('ok')
const confirmBajaFichaOpen = ref(false)
let vistaMontada = true

onBeforeUnmount(() => {
  vistaMontada = false
})

function mostrarAviso(texto: string | null, tipo: 'ok' | 'error' | 'aviso' = 'ok') {
  mensaje.value = texto
  mensajeTipo.value = tipo
}

function avisoDesdeTexto(msg: string | null) {
  if (!msg) {
    mostrarAviso(null)
    return
  }
  const esError =
    /no se|error|dependencias|permiso|obligatori|conflicto|central|ya existe|duplicad|invalido|inválido/i.test(
      msg
    )
  mostrarAviso(msg, esError ? 'error' : 'ok')
}

async function codigoTiendaExiste(codigo: string): Promise<boolean> {
  const c = codigo.trim().toLowerCase()
  if (!c) return false
  if (filasTodas.value.some((f) => String(f.codigo ?? '').trim().toLowerCase() === c)) {
    return true
  }
  try {
    await obtener(codigo.trim())
    return true
  } catch {
    // 404 u otro error: la API validara en el alta
    return false
  }
}

const tabActiva = ref(tiendaTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)
const mostrarContadores = ref(false)
const camposInvalidos = ref<string[]>([])
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Campo obligatorio')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const codigoInput = ref<HTMLInputElement | null>(null)

const camposInvalidosSet = computed(() => new Set(camposInvalidos.value))

watch(
  ficha,
  () => {
    if (camposInvalidos.value.length === 0) return
    // Solo quita el resaltado de los campos que ya se han rellenado.
    const pendientes = validarTiendaObligatorios(ficha.value).campos
    camposInvalidos.value = camposInvalidos.value.filter((k) => pendientes.includes(k))
  },
  { deep: true }
)

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
  await enfocarCampoTienda(key)
}

async function enfocarCampoTienda(key: string) {
  const tab = tabDeCampoTienda(key)
  if (tab) tabActiva.value = tab
  await nextTick()
  await nextTick()
  if (key === 'codigo') {
    codigoInput.value?.focus()
    codigoInput.value?.select()
    return
  }
  const el = document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)
  el?.focus()
}

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

const tabSeleccionada = computed(() => tiendaTabs.find((t) => t.id === tabActiva.value) ?? tiendaTabs[0])
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
      descripcion: String(f.nombre ?? ''),
      _nuevo: f._nuevo,
    }
  },
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: avisoDesdeTexto,
  etiquetaEntidad: 'la tienda',
  mensajeExito: 'Tienda dada de baja',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  mostrarAviso(null)
  const params: Record<string, string | number | boolean> = { page: page.value, pageSize: pageSize.value }
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

function actualizarFila(index: number, fila: GridFila) {
  indiceSeleccionado.value = index
  const codigo = String(fila.codigo ?? '').trim()
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo ?? '').trim() === codigo ? { ...fila, _nuevo: false, _dirty: true } : f
  )
}


function filasPendientesGuardar(): GridFila[] {
  const dirty = filasTodas.value.filter((f) => f._dirty && !f._nuevo)
  if (dirty.length > 0) return dirty
  const sel = filaSeleccionada.value
  if (sel && !sel._nuevo) return [sel]
  return []
}

async function onGuardarGrid() {
  const pendientes = filasPendientesGuardar()
  if (pendientes.length === 0) {
    mostrarAviso('No hay cambios para guardar', 'aviso')
    return
  }

  try {
    let bajas = 0
    let actualizadas = 0

    for (const fila of pendientes) {
      const errorValidacion = validarFilaGrid(fila, columns)
      if (errorValidacion) {
        mostrarAviso(errorValidacion, 'error')
        return
      }

      const codigo = String(fila.codigo ?? '').trim()
      if (!codigo) {
        mostrarAviso('Codigo de tienda no valido', 'error')
        return
      }

      // Desactivar = baja logica (no update de ficha completa).
      if (!esFilaActiva(fila)) {
        await eliminar(codigo)
        bajas += 1
        continue
      }

      await actualizar(codigo, payloadFilaGrid(fila, columns))
      actualizadas += 1
    }

    await cargar()
    if (bajas > 0 && actualizadas === 0) {
      mostrarAviso(bajas === 1 ? 'Tienda dada de baja' : `${bajas} tiendas dadas de baja`, 'ok')
    } else if (bajas > 0) {
      mostrarAviso(`Guardado: ${actualizadas} actualizadas, ${bajas} dadas de baja`, 'ok')
    } else {
      mostrarAviso(actualizadas === 1 ? 'Tienda actualizada' : `${actualizadas} tiendas actualizadas`, 'ok')
    }
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo guardar la tienda'), 'error')
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
    mostrarAviso(null)
    camposInvalidos.value = []
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo cargar la ficha'), 'error')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mostrarAviso('Seleccione una tienda existente', 'aviso')
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = tiendaVacia()
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  tabActiva.value = 'generales'
  vista.value = 'ficha'
  mostrarAviso(null)
  camposInvalidos.value = []
  await nextTick()
  await nextTick()
  codigoInput.value?.focus()
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const codigo = String(ficha.value.codigo ?? '').trim()
  if (esNuevo.value && codigo) {
    if (await codigoTiendaExiste(codigo)) {
      tabActiva.value = 'generales'
      mostrarAvisoModal(
        'Codigo duplicado',
        `Ya existe una tienda con el codigo "${codigo}".`,
        'codigo'
      )
      return
    }
  }

  const validacion = validarTiendaObligatorios(ficha.value)
  if (validacion.mensaje) {
    const key = validacion.campos[0] ?? null
    const tab = tabDeCampoTienda(key ?? '')
    if (tab) tabActiva.value = tab
    mostrarAvisoModal('Campo obligatorio', validacion.mensaje, key)
    return
  }
  camposInvalidos.value = []
  try {
    if (esNuevo.value) {
      const creado = await crear(ficha.value)
      mostrarAviso('Tienda creada correctamente', 'ok')
      await cargar()
      // cargar() limpia el mensaje; reponer confirmacion
      mostrarAviso('Tienda creada correctamente', 'ok')
      const idx = filas.value.findIndex((t) => String(t.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(codigo, ficha.value)
      await cargar()
      await abrirFichaPorCodigo(codigo)
      mostrarAviso('Tienda actualizada', 'ok')
    }
    modoEdicion.value = false
    esNuevo.value = false
    mostrarContadores.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar la tienda')
    if (/ya existe|duplicad/i.test(msg)) {
      tabActiva.value = 'generales'
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
  if (!puedeEliminar.value) {
    mostrarAviso('No tiene permiso para dar de baja tiendas', 'error')
    return
  }
  if (esNuevo.value) {
    mostrarAviso('Guarde la tienda antes de darla de baja, o pulse Cancelar para salir', 'aviso')
    return
  }
  if (!ficha.value.codigo) {
    mostrarAviso('No hay tienda seleccionada para dar de baja', 'aviso')
    return
  }
  confirmBajaFichaOpen.value = true
}

async function confirmarBajaFicha() {
  confirmBajaFichaOpen.value = false
  const codigo = String(ficha.value.codigo ?? '').trim()
  if (!codigo) {
    mostrarAviso('No hay tienda seleccionada para dar de baja', 'aviso')
    return
  }
  try {
    await eliminar(codigo)
    await cargar()
    volverAlGrid()
    mostrarAviso('Tienda dada de baja', 'ok')
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudo dar de baja'), 'error')
  }
}

function cancelarBajaFicha() {
  confirmBajaFichaOpen.value = false
}

function volverAlGrid() {
  vista.value = 'grid'
  modoEdicion.value = false
  esNuevo.value = false
  ficha.value = {}
  mostrarContadores.value = false
  camposInvalidos.value = []
}

const contadorKeys = [...contadoresFieldsIzq, ...contadoresFieldsDer]
  .filter((f) => !f.readOnly)
  .map((f) => f.key)
const guardandoContadores = ref(false)

async function abrirContadores() {
  if (!ficha.value.codigo) return
  try {
    ficha.value = await obtener(String(ficha.value.codigo))
    mostrarContadores.value = true
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudieron cargar los contadores'), 'error')
  }
}

async function onGuardarContadores(payload: Record<string, unknown>) {
  if (!puedeEditar.value) {
    mostrarAviso('No tiene permiso para modificar contadores', 'error')
    return
  }
  const codigo = String(payload.codigo ?? ficha.value.codigo ?? '').trim()
  if (!codigo) {
    mostrarAviso('No hay tienda seleccionada', 'aviso')
    return
  }
  const body: Record<string, unknown> = {}
  for (const key of contadorKeys) {
    if (key in payload) body[key] = payload[key]
  }
  guardandoContadores.value = true
  try {
    const actualizado = await actualizar(codigo, body)
    ficha.value = actualizado
    mostrarContadores.value = false
    mostrarAviso('Contadores actualizados', 'ok')
  } catch (e: unknown) {
    mostrarAviso(extractApiError(e, 'No se pudieron guardar los contadores'), 'error')
  } finally {
    guardandoContadores.value = false
  }
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

function tabTieneErrores(tabId: string): boolean {
  return camposInvalidos.value.some((campo) => tabDeCampoTienda(campo) === tabId)
}
</script>

<template>
  <section class="tiendas-view">
    <h2>Tiendas</h2>

    <p v-if="!puedeVer" class="flash flash-error">No tiene permiso para ver tiendas.</p>

    <template v-else>
      <p v-if="mensaje" class="flash" :class="`flash-${mensajeTipo}`" role="alert">{{ mensaje }}</p>
      <p v-if="error" class="flash flash-error" role="alert">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Tiendas"
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
            Dar de baja
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
        />

        <p class="hint">
          Filtra por <strong>Codigo</strong>, <strong>Nombre</strong>, <strong>NIF</strong>,
          <strong>Poblacion</strong> y <strong>Telefono</strong> escribiendo bajo cada columna. Doble clic o
          <strong>Ficha</strong> abre el detalle.
        </p>
        </div>
      </template>

      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

          <TiendaToolbar
            :puede-crear="puedeCrear"
            :puede-editar="puedeEditar"
            :puede-eliminar="puedeEliminar && !esNuevo"
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
            @contadores="abrirContadores"
            @primero="onPrimero"
            @anterior="onAnterior"
            @siguiente="onSiguiente"
            @ultimo="onUltimo"
          />

          <div class="ficha-header">
            <label :class="{ 'campo-invalido': camposInvalidosSet.has('codigo') }">
              Codigo *
              <input
                ref="codigoInput"
                v-model="ficha.codigo"
                data-field-key="codigo"
                :readonly="!esNuevo"
                maxlength="3"
                class="codigo-input"
              />
            </label>
            <label class="nombre-input">
              Nombre
              <input v-model="ficha.nombre" :readonly="soloLecturaFicha" />
            </label>
            <p v-if="ficha.esCentral" class="badge-central">Tienda central</p>
          </div>

          <div class="tabs">
            <button
              v-for="tab in tiendaTabs"
              :key="tab.id"
              type="button"
              class="tab"
              :class="{
                active: tabActiva === tab.id,
                'tab-error': tabTieneErrores(tab.id),
              }"
              @click="tabActiva = tab.id"
            >
              {{ tab.label }}
            </button>
          </div>
        </div>

        <TiendaTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :readonly="soloLecturaFicha"
          :codigo-read-only="!esNuevo"
          :ocultar-cabecera="true"
          :campos-invalidos="camposInvalidos"
          @update:model-value="ficha = $event"
        />
      </template>

      <TiendaContadoresModal
        :open="mostrarContadores"
        :model-value="ficha"
        :readonly="!puedeEditar"
        :saving="guardandoContadores"
        @update:model-value="ficha = $event"
        @guardar="onGuardarContadores"
        @cerrar="mostrarContadores = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Dar de baja tienda"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />

      <ConfirmDialog
        :open="confirmBajaFichaOpen"
        title="Dar de baja tienda"
        :message="`Va a dar de baja la tienda ${String(ficha.codigo ?? '').trim()}.`"
        @confirm="confirmarBajaFicha"
        @cancel="cancelarBajaFicha"
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
.tiendas-view h2 {
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

.sticky-chrome {
  max-width: 920px;
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
  max-width: 920px;
}

.ficha-header label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
}

.codigo-input {
  width: 4rem;
}

.nombre-input {
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
  font-weight: 700;
}

.ficha-header label.campo-invalido input {
  border-color: #dc2626;
  background: #fef2f2;
  box-shadow: 0 0 0 1px #fca5a5;
}

.badge-central {
  margin: 0;
  padding: 0.25rem 0.5rem;
  background: #fef3c7;
  color: #92400e;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem;
  padding: 0.25rem 0.35rem 0;
  background: #fff;
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
  max-width: 920px;
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
}

.tab.tab-error {
  color: #b91c1c;
  border-color: #f87171;
  background: #fee2e2;
}

.tab.tab-error.active {
  background: #fecaca;
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
