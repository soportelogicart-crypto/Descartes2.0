<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
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
import { proveedorTabs, proveedorVacio, validarProveedorObligatorios, validarUsoProveedor } from '@/config/proveedores-tabs'
import { normalizarIban } from '@/utils/iban'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import ProveedorToolbar from '@/components/proveedores/ProveedorToolbar.vue'
import ProveedorTabForm from '@/components/proveedores/ProveedorTabForm.vue'
import ProveedorInteresesModal from '@/components/proveedores/ProveedorInteresesModal.vue'
import ProveedorContactosModal from '@/components/proveedores/ProveedorContactosModal.vue'
import ProveedorEstadisticaModal from '@/components/proveedores/ProveedorEstadisticaModal.vue'
import { api } from '@/api/client'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const MODULO = 'proveedores'
const FILTER_KEYS = ['codigo', 'nombre', 'nif']
const columns = getGridColumns('proveedores')

const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()
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
const mensaje = ref<string | null>(null)

const tabActiva = ref(proveedorTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)
const mostrarIntereses = ref(false)
const mostrarContactos = ref(false)
const mostrarEstadistica = ref(false)
const confirmBorrarFichaOpen = ref(false)
const codigoAutomatico = ref(false)
const avisoOpen = ref(false)
const avisoTitulo = ref('Aviso')
const avisoMensaje = ref('')
const campoAviso = ref<string | null>(null)
const camposInvalidos = ref<string[]>([])

async function mostrarAviso(titulo: string, message: string, campo: string | null = null) {
  avisoTitulo.value = titulo
  avisoMensaje.value = message
  campoAviso.value = campo
  avisoOpen.value = true
}

async function cerrarAviso() {
  const key = campoAviso.value
  avisoOpen.value = false
  avisoMensaje.value = ''
  campoAviso.value = null
  if (!key) return
  await nextTick()
  document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)?.focus()
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

const tabSeleccionada = computed(() => proveedorTabs.find((t) => t.id === tabActiva.value) ?? proveedorTabs[0])
const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
/** La ficha recorre lo que se ve en la rejilla: si la busqueda deja 3 filas, el contador es x/3. */
const filasNavegacion = computed(() => filas.value.filter((f) => !f._nuevo))
const totalFicha = computed(() => filasNavegacion.value.length)
const hayProveedor = computed(() => Boolean(ficha.value.codigo) || esNuevo.value)
const codigoReadOnlyFicha = computed(() => !esNuevo.value || codigoAutomatico.value)

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
  etiquetaEntidad: 'el proveedor',
  mensajeExito: 'Proveedor eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  mensaje.value = null
  await listar()
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
    mensaje.value = 'Proveedor actualizado'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el proveedor')
  }
}

function normalizarFichaProveedor(data: Record<string, unknown>): Record<string, unknown> {
  const next = { ...data }
  const banco = next.cuentaBanco
  if (banco == null || banco === '' || banco === 0 || banco === '0' || banco === '0.0') {
    next.cuentaBanco = ''
  } else {
    const s = String(banco).trim()
    next.cuentaBanco = /^\d+\.0+$/.test(s) ? s.replace(/\.0+$/, '') : s
  }
  const tf = String(next.tratamientoFiscal ?? '').trim().toUpperCase()
  next.tratamientoFiscal = tf || 'N'
  const sii = String(next.codigoTransaccionSII ?? '').trim()
  next.codigoTransaccionSII = sii || '1'
  return next
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    ficha.value = normalizarFichaProveedor((await obtener(codigo)) as Record<string, unknown>)
    indiceFicha.value = filasNavegacion.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    codigoAutomatico.value = false
    camposInvalidos.value = []
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
    mensaje.value = 'Seleccione un proveedor existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  const vacio = proveedorVacio()
  codigoAutomatico.value = false
  mensaje.value = null
  try {
    const { data } = await api.get('/api/mantenimiento/proveedores/siguiente-codigo', {
      params: { empresa: puestoContexto.empresaCodigo || undefined },
    })
    if (data.automatico && data.codigo) {
      vacio.codigo = String(data.codigo)
      codigoAutomatico.value = true
    }
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo obtener el siguiente codigo')
  }
  ficha.value = vacio
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  camposInvalidos.value = []
  tabActiva.value = 'generales'
  vista.value = 'ficha'
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

function payloadProveedor(): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    ...ficha.value,
    iban: normalizarIban(ficha.value.iban),
  }
  const banco = String(payload.cuentaBanco ?? '').trim()
  if (!banco || banco === '0') {
    payload.cuentaBanco = 0
  } else if (/^-?\d+(\.\d+)?$/.test(banco)) {
    payload.cuentaBanco = Number(banco)
  }
  delete payload.acumIva
  return payload
}

async function onGuardarFicha() {
  const errorValidacion = validarProveedorObligatorios(ficha.value)
  if (errorValidacion) {
    camposInvalidos.value = [errorValidacion.campo]
    tabActiva.value = errorValidacion.tab
    await mostrarAviso('Campo obligatorio', errorValidacion.mensaje, errorValidacion.campo)
    return
  }
  const validacionUso = validarUsoProveedor(ficha.value)
  if (validacionUso) {
    camposInvalidos.value = [validacionUso.campo]
    tabActiva.value = validacionUso.tab
    await mostrarAviso(validacionUso.titulo, validacionUso.mensaje, validacionUso.campo)
    return
  }
  camposInvalidos.value = []
  try {
    if (esNuevo.value) {
      const payload = {
        ...payloadProveedor(),
        empresaCodigo: puestoContexto.empresaCodigo || undefined,
      }
      const creado = await crear(payload)
      mensaje.value = 'Proveedor creado correctamente'
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(String(ficha.value.codigo), payloadProveedor())
      mensaje.value = 'Proveedor actualizado'
      await cargar()
      await abrirFichaPorCodigo(String(ficha.value.codigo))
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el proveedor')
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
  const codigo = String(ficha.value.codigo ?? '').trim()
  confirmBorrarFichaOpen.value = false
  if (!codigo) return
  try {
    await eliminar(codigo)
    mensaje.value = 'Proveedor eliminado'
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
  mostrarIntereses.value = false
  mostrarContactos.value = false
  mostrarEstadistica.value = false
  codigoAutomatico.value = false
}

function actualizarFicha(value: Record<string, unknown>) {
  ficha.value = value
}

function onEstadistica() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el proveedor antes de consultar la estadistica'
    return
  }
  mostrarEstadistica.value = true
}

function onIntereses() {
  if (!hayProveedor.value) return
  mostrarIntereses.value = true
}

function onContactos() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el proveedor antes de gestionar contactos'
    return
  }
  mostrarContactos.value = true
}

async function onInteresesUpdate(value: string) {
  ficha.value = { ...ficha.value, interesesComerciales: value }
  if (soloLecturaFicha.value && ficha.value.codigo) {
    try {
      await actualizar(String(ficha.value.codigo), { interesesComerciales: value })
      mensaje.value = 'Intereses actualizados'
    } catch (e: unknown) {
      mensaje.value = extractApiError(e, 'No se pudieron guardar los intereses')
    }
  }
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
  <section class="proveedores-view">
    <h2>Proveedores</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver proveedores.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="listado-panel">
          <div class="toolbar">
            <MantenimientoListadoButton
            titulo="Proveedores"
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
            Filtra por <strong>Codigo</strong>, <strong>Razon social</strong> y <strong>NIF</strong> escribiendo bajo cada columna.
            Doble clic o <strong>Ficha</strong> abre el detalle.
          </p>
        </div>
      </template>

      <template v-else>
        <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

        <div class="ficha-panel">
          <ProveedorToolbar
            :puede-crear="puedeCrear"
            :puede-editar="puedeEditar"
            :puede-eliminar="puedeEliminar"
            :puede-guardar="puedeCrear || puedeEditar"
            :modo-edicion="modoEdicion || esNuevo"
            :indice="indiceFicha < 0 ? undefined : indiceFicha"
            :total="totalFicha"
            :loading="loading"
            :hay-proveedor="hayProveedor"
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
            @estadistica="onEstadistica"
            @contactos="onContactos"
            @intereses="onIntereses"
          />

          <div class="ficha-header">
            <label class="hdr-codigo" :class="{ 'campo-invalido': camposInvalidos.includes('codigo') }">
              <span>Codigo *</span>
              <input
                v-model="ficha.codigo"
                data-field-key="codigo"
                :readonly="codigoReadOnlyFicha"
                maxlength="6"
              />
            </label>
            <label class="hdr-nombre" :class="{ 'campo-invalido': camposInvalidos.includes('nombre') }">
              <span>Razon social *</span>
              <input
                v-model="ficha.nombre"
                data-field-key="nombre"
                :readonly="soloLecturaFicha"
                maxlength="50"
              />
            </label>
          </div>

          <div class="tabs">
            <button
              v-for="tab in proveedorTabs"
              :key="tab.id"
              type="button"
              class="tab"
              :class="{ active: tabActiva === tab.id }"
              @click="tabActiva = tab.id"
            >
              {{ tab.label }}
            </button>
          </div>

          <ProveedorTabForm
            :sections="tabSeleccionada.sections"
            :model-value="ficha"
            :readonly="soloLecturaFicha"
            :codigo-read-only="codigoReadOnlyFicha"
            :ocultar-cabecera="true"
            :campos-invalidos="camposInvalidos"
            @update:model-value="actualizarFicha"
          />
        </div>

        <ProveedorInteresesModal
          :open="mostrarIntereses"
          :model-value="String(ficha.interesesComerciales ?? '')"
          :readonly="soloLecturaFicha"
          @update:model-value="onInteresesUpdate"
          @cerrar="mostrarIntereses = false"
        />

        <ProveedorContactosModal
          v-if="ficha.codigo"
          :open="mostrarContactos"
          :proveedor-codigo="String(ficha.codigo)"
          :puede-editar="puedeEditar"
          @cerrar="mostrarContactos = false"
        />

        <ProveedorEstadisticaModal
          :open="mostrarEstadistica"
          :proveedor-codigo="String(ficha.codigo ?? '')"
          :proveedor-nombre="String(ficha.nombre ?? '')"
          @cerrar="mostrarEstadistica = false"
        />
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar proveedor"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        title="Eliminar proveedor"
        message="Va a eliminar este proveedor. Esta accion no se puede deshacer."
        @confirm="confirmarBorrarFicha"
        @cancel="confirmBorrarFichaOpen = false"
      />
      <ConfirmDialog
        :open="avisoOpen"
        :title="avisoTitulo"
        :message="avisoMensaje"
        confirm-label="Aceptar"
        :danger="false"
        hide-cancel
        @confirm="cerrarAviso"
        @cancel="cerrarAviso"
      />
    </template>
  </section>
</template>

<style scoped>
.proveedores-view h2 {
  margin: 0 0 0.75rem;
}

.listado-panel > .toolbar,
.listado-panel :deep(.grid-wrap),
.listado-panel .paginacion {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
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
  margin-bottom: 0.45rem;
  padding: 0.3rem 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 0.8rem;
}

.ficha-panel {
  width: 52rem;
  max-width: 100%;
  box-sizing: border-box;
  border-radius: 8px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.06);
}

.ficha-panel :deep(.toolbar) {
  width: 100%;
  border-radius: 8px 8px 0 0;
}

.ficha-header {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem 1rem;
  align-items: center;
  padding: 0.55rem 0.75rem;
  background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
}

.ficha-header label {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.78rem;
  color: #475569;
}

.ficha-header label span {
  white-space: nowrap;
  font-weight: 600;
}

.hdr-codigo input {
  width: 5rem;
}

.hdr-nombre {
  flex: 1;
  min-width: 12rem;
}

.hdr-nombre input {
  flex: 1;
  min-width: 0;
  max-width: 22rem;
}

.ficha-header input {
  padding: 0.25rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.82rem;
  background: #fff;
}

.ficha-header input:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}

.campo-invalido {
  color: #b91c1c;
}

.campo-invalido input {
  border-color: #ef4444;
  background: #fef2f2;
}

.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.2rem;
  padding: 0.35rem 0.55rem 0;
  background: #f8fafc;
  border-left: 1px solid #c5cdd8;
  border-right: 1px solid #c5cdd8;
}

.tab {
  border: 1px solid #94a3b8;
  border-bottom: none;
  border-radius: 6px 6px 0 0;
  background: #e2e8f0;
  padding: 0.35rem 0.75rem;
  font-size: 0.78rem;
  color: #475569;
  cursor: pointer;
}

.tab:hover {
  background: #f1f5f9;
}

.tab.active {
  background: #eef2f6;
  color: #0f172a;
  font-weight: 700;
  border-bottom: 1px solid #eef2f6;
  margin-bottom: -1px;
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
