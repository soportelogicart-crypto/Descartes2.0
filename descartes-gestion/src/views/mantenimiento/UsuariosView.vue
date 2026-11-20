<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import { TEMAS } from '@/config/temas'
import { IDIOMAS } from '@/i18n'
import { aplicarPreferencias } from '@/preferencias/aplicar'
import { useAuthStore } from '@/stores/auth'
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
import { useMantenimientoServerSearch } from '@/composables/useMantenimientoServerSearch'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadGrid, { type GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const { t } = useI18n()
const auth = useAuthStore()
const idiomas = IDIOMAS
const temas = TEMAS

const ENTIDAD = 'usuarios'
const MODULO = 'usuarios'
const FILTER_KEYS = ['codigo', 'nombre', 'rolCodigo']
const SERVER_FILTER_KEYS = ['codigo', 'nombre'] as const
const columns = getGridColumns(ENTIDAD)

const USUARIO_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'nombre', label: 'Nombre' },
  { key: 'rolCodigo', label: 'Rol' },
  { key: 'password', label: 'Contrasena' },
]

function usuarioVacio(): Record<string, unknown> {
  return {
    codigo: '',
    nombre: '',
    rolCodigo: '',
    password: '',
    tiendasExtra: '',
    idioma: 'es',
    colorTema: 'azul',
    ayudasMenu: true,
    activo: true,
  }
}

function codigosTiendasExtra(ficha: Record<string, unknown>): string[] {
  return String(ficha.tiendasExtra ?? '')
    .split(/[,;]+/)
    .map((c) => c.trim())
    .filter((c) => c !== '')
}

function camposUsuarioObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.nombre ?? '').trim()) vacios.push('nombre')
  if (!String(ficha.rolCodigo ?? '').trim()) vacios.push('rolCodigo')
  if (!String(ficha.password ?? '').trim()) vacios.push('password')
  return vacios
}

function validarUsuario(ficha: Record<string, unknown>): string | null {
  const key = camposUsuarioObligatoriosVacios(ficha)[0]
  if (!key) return null
  const etiquetas: Record<string, string> = {
    codigo: t('usuarios.codigo'),
    nombre: t('usuarios.nombre'),
    rolCodigo: t('usuarios.rol'),
    password: t('usuarios.contrasena'),
  }
  return t('usuarios.obligatorio', { campo: etiquetas[key] ?? key })
}

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
const mensaje = ref<string | null>(null)
const confirmBorrarFichaOpen = ref(false)
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Campo obligatorio')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const camposInvalidos = ref<string[]>([])
const codigoInput = ref<HTMLInputElement | null>(null)
const optionsMap = ref<GridOptionsMap>({})
const rolesOpciones = ref<{ value: string; label: string }[]>([])
const tiendasOpciones = ref<{ value: string; label: string }[]>([])

const modoEdicion = ref(false)
const esNuevo = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)

function tiendaExtraMarcada(codigo: string): boolean {
  return codigosTiendasExtra(ficha.value).includes(codigo.trim())
}

function toggleTiendaExtra(codigo: string, marcada: boolean) {
  const codigos = codigosTiendasExtra(ficha.value).filter((c) => c !== codigo)
  if (marcada) codigos.push(codigo)
  ficha.value = { ...ficha.value, tiendasExtra: codigos.join(',') }
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
    codigoInput.value?.focus()
    codigoInput.value?.select()
    return
  }
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

const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
/** La ficha recorre lo que se ve en la rejilla: si la busqueda deja 3 filas, el contador es x/3. */
const filasNavegacion = computed(() => filas.value.filter((f) => !f._nuevo))
const totalFicha = computed(() => filasNavegacion.value.length)
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
  etiquetaEntidad: 'el usuario',
  mensajeExito: 'Usuario eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await Promise.all([cargarRoles(), cargarTiendas()])
  await cargar()
})

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendasOpciones.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo ?? '').trim(),
      label: `${String(t.codigo ?? '').trim()} - ${t.nombre}`,
    })).filter((t: { value: string }) => t.value !== '')
  } catch {
    tiendasOpciones.value = []
  }
}

async function cargarRoles() {
  try {
    const { data } = await api.get('/api/mantenimiento/roles', { params: { activo: true, pageSize: 500 } })
    rolesOpciones.value = (data.items ?? []).map((r: { codigo: string; nombre: string }) => ({
      value: String(r.codigo ?? '').trim(),
      label: `${String(r.codigo ?? '').trim()} - ${r.nombre}`,
    }))
    optionsMap.value = { roles: rolesOpciones.value }
  } catch {
    rolesOpciones.value = []
    optionsMap.value = { roles: [] }
  }
}

async function cargar(q = serverQuery.value, meta?: { silent?: boolean }) {
  mensaje.value = null
  const params: Record<string, string | number | boolean> = { page: page.value, pageSize: pageSize.value }
  if (q) params.q = q
  await listar(params, { silent: meta?.silent })
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
    mensaje.value = t('usuarios.actualizado')
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, t('usuarios.errorGuardar'))
  }
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    const data = await obtener(codigo)
    const idioma = data.idioma === 'ca' || data.idioma === 'en' || data.idioma === 'es' ? data.idioma : 'es'
    const color = TEMAS.some((tema) => tema.id === data.colorTema) ? String(data.colorTema) : 'azul'
    ficha.value = {
      ...data,
      password: String(data.password ?? ''),
      activo: data.activo !== false && data.activo !== 0,
      idioma,
      colorTema: color,
      ayudasMenu: data.ayudasMenu !== false && data.ayudasMenu !== 0,
    }
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
    mensaje.value = 'Seleccione un usuario existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  ficha.value = usuarioVacio()
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

function sincronizarPreferenciasPropias() {
  if (!auth.usuario) return
  if (String(ficha.value.codigo ?? '').trim() !== auth.usuario.codigo.trim()) return
  auth.usuario = {
    ...auth.usuario,
    idioma: String(ficha.value.idioma || 'es'),
    colorTema: String(ficha.value.colorTema || 'azul'),
    ayudasMenu: ficha.value.ayudasMenu !== false,
  }
  aplicarPreferencias(auth.usuario)
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const vacios = camposUsuarioObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = USUARIO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    const msg = validarUsuario(ficha.value) ?? `El campo "${label}" es obligatorio.`
    mensaje.value = msg
    mostrarAvisoModal(t('usuarios.campoObligatorio'), msg, key)
    return
  }
  camposInvalidos.value = []
  try {
    const payload: Record<string, unknown> = {
      codigo: ficha.value.codigo,
      nombre: ficha.value.nombre,
      rolCodigo: ficha.value.rolCodigo,
      password: String(ficha.value.password ?? '').trim(),
      tiendasExtra: codigosTiendasExtra(ficha.value).join(','),
      idioma: ficha.value.idioma,
      colorTema: ficha.value.colorTema,
      ayudasMenu: ficha.value.ayudasMenu !== false,
      activo: ficha.value.activo,
    }

    if (esNuevo.value) {
      const creado = await crear(payload)
      mensaje.value = t('usuarios.creado')
      await cargar()
      const idx = filas.value.findIndex((p) => String(p.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      const codigo = String(ficha.value.codigo)
      await actualizar(codigo, payload)
      mensaje.value = t('usuarios.actualizado')
      await cargar()
      await abrirFichaPorCodigo(codigo)
      sincronizarPreferenciasPropias()
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, t('usuarios.errorGuardar'))
    mensaje.value = msg
    if (/ya existe|duplicad|codigo/i.test(msg)) {
      mostrarAvisoModal(t('usuarios.codigoDuplicado'), msg, 'codigo')
    } else {
      mostrarAvisoModal(t('usuarios.errorTitulo'), msg)
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
    mensaje.value = 'Usuario eliminado'
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
  <section class="usuarios-view">
    <h2>{{ t('usuarios.titulo') }}</h2>

    <p v-if="!puedeVer" class="error">{{ t('usuarios.sinPermiso') }}</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            :titulo="t('usuarios.titulo')"
            :columnas="columns"
            :filas="filas"
            :options-map="optionsMap"
            @aviso="mensaje = $event"
          />
          <button v-if="puedeCrear" type="button" class="tool-btn" :disabled="loading" @click="onNuevo">
            {{ t('usuarios.nuevo') }}
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada || filaSeleccionada._nuevo"
            @click="abrirFicha()"
          >
            {{ t('usuarios.ficha') }}
          </button>
          <div class="toolbar-spacer"></div>
          <button
            v-if="puedeMostrarGuardar"
            type="button"
            class="tool-btn primary"
            :disabled="loading || !puedeGuardarGrid"
            @click="onGuardarGrid"
          >
            {{ t('usuarios.guardar') }}
          </button>
          <button
            v-if="puedeMostrarEliminar"
            type="button"
            class="tool-btn danger"
            :disabled="loading"
            @click="solicitarEliminar"
          >
            {{ t('usuarios.eliminar') }}
          </button>
        </div>

        <EntidadGrid
          :columns="columns"
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :options-map="optionsMap"
          :readonly="soloLecturaGrid"
          :loading="loading"
          :total-servidor="total"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
          @search="onServerSearch"
        />

        <p class="hint">{{ t('usuarios.hint') }}</p>
        </div>
      </template>

      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← {{ t('usuarios.volver') }}</button>

          <div class="toolbar ficha-toolbar">
            <div class="toolbar-group">
              <button type="button" class="tool-btn" :disabled="loading || !puedeCrear" @click="onNuevo">
                <ToolIcon name="nuevo" />
                <span>{{ t('usuarios.nuevo') }}</span>
              </button>
              <button
                type="button"
                class="tool-btn"
                :disabled="loading || !puedeEditar || modoEdicion || esNuevo"
                @click="onModificar"
              >
                <ToolIcon name="modificar" />
                <span>{{ t('usuarios.modificar') }}</span>
              </button>
              <button
                type="button"
                class="tool-btn"
                :disabled="loading || !puedeEliminar || esNuevo"
                @click="onBorrarFicha"
              >
                <ToolIcon name="borrar" />
                <span>{{ t('usuarios.borrar') }}</span>
              </button>
              <button type="button" class="tool-btn" :disabled="loading" @click="volverAlGrid">
                <ToolIcon name="buscar" />
                <span>{{ t('usuarios.buscar') }}</span>
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
                {{ t('usuarios.guardar') }}
              </button>
              <button
                v-if="modoEdicion || esNuevo"
                type="button"
                class="tool-btn"
                :disabled="loading"
                @click="onCancelarFicha"
              >
                {{ t('usuarios.cancelar') }}
              </button>
            </div>
          </div>
        </div>

        <div class="ficha">
          <fieldset class="form-section">
            <legend>{{ t('usuarios.datos') }}</legend>
            <div class="ficha-campos">
              <label :class="{ 'campo-invalido': camposInvalidos.includes('codigo') }">
                {{ t('usuarios.codigo') }} *
                <input
                  ref="codigoInput"
                  v-model="ficha.codigo"
                  data-field-key="codigo"
                  :readonly="!esNuevo"
                  maxlength="20"
                  class="codigo-input"
                />
              </label>
              <label
                class="nombre-input"
                :class="{ 'campo-invalido': camposInvalidos.includes('nombre') }"
              >
                {{ t('usuarios.nombre') }} *
                <input
                  v-model="ficha.nombre"
                  data-field-key="nombre"
                  :readonly="soloLecturaFicha"
                  maxlength="50"
                />
              </label>
              <label :class="{ 'campo-invalido': camposInvalidos.includes('rolCodigo') }">
                {{ t('usuarios.rol') }} *
                <select
                  v-model="ficha.rolCodigo"
                  data-field-key="rolCodigo"
                  :disabled="soloLecturaFicha"
                >
                  <option value="">-- {{ t('usuarios.seleccionar') }} --</option>
                  <option v-for="opt in rolesOpciones" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </label>
              <label
                class="password-input"
                :class="{ 'campo-invalido': camposInvalidos.includes('password') }"
              >
                {{ t('usuarios.contrasena') }} *
                <input
                  v-model="ficha.password"
                  data-field-key="password"
                  type="text"
                  :readonly="soloLecturaFicha"
                  autocomplete="off"
                />
              </label>
              <label class="activo-field">
                <input v-model="ficha.activo" type="checkbox" :disabled="soloLecturaFicha" />
                {{ t('usuarios.activo') }}
              </label>
              <label>
                {{ t('usuarios.idioma') }}
                <select v-model="ficha.idioma" :disabled="soloLecturaFicha">
                  <option v-for="idioma in idiomas" :key="idioma.id" :value="idioma.id">
                    {{ idioma.etiqueta }}
                  </option>
                </select>
              </label>
              <label>
                {{ t('usuarios.color') }}
                <select v-model="ficha.colorTema" :disabled="soloLecturaFicha">
                  <option v-for="tema in temas" :key="tema.id" :value="tema.id">
                    {{ t(`temas.${tema.id}`) }}
                  </option>
                </select>
              </label>
              <label class="activo-field ayudas-field">
                <input v-model="ficha.ayudasMenu" type="checkbox" :disabled="soloLecturaFicha" />
                {{ t('usuarios.ayudas') }}
              </label>
              <p class="tiendas-extra-vacio ayudas-detalle">{{ t('usuarios.ayudasDetalle') }}</p>
            </div>
          </fieldset>
          <fieldset class="form-section">
            <legend>{{ t('usuarios.otrasTiendas') }}</legend>
            <p v-if="!tiendasOpciones.length" class="tiendas-extra-vacio">{{ t('usuarios.sinTiendas') }}</p>
            <div v-else class="tiendas-extra">
              <label v-for="tienda in tiendasOpciones" :key="tienda.value" class="tienda-extra">
                <input
                  type="checkbox"
                  :checked="tiendaExtraMarcada(tienda.value)"
                  :disabled="soloLecturaFicha"
                  @change="toggleTiendaExtra(tienda.value, ($event.target as HTMLInputElement).checked)"
                />
                {{ tienda.label }}
              </label>
            </div>
          </fieldset>
        </div>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        :title="t('usuarios.eliminarTitulo')"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
      />
      <ConfirmDialog
        :open="confirmBorrarFichaOpen"
        :title="t('usuarios.eliminarTitulo')"
        :message="t('usuarios.eliminarMensaje')"
        @confirm="confirmarBorrarFicha"
        @cancel="confirmBorrarFichaOpen = false"
      />
      <ConfirmDialog
        :open="avisoModalOpen"
        :title="avisoModalTitulo"
        :message="avisoModalMensaje"
        :confirm-label="t('comun.aceptar')"
        :danger="false"
        hide-cancel
        @confirm="cerrarAvisoModal"
        @cancel="cerrarAvisoModal"
      />
    </template>
  </section>
</template>

<style scoped>
.usuarios-view h2 {
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
  max-width: 720px;
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
}

.form-section legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #334155;
}

.ficha-campos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
}

.ficha-campos label {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
  color: #475569;
}

.codigo-input {
  width: 7rem;
}

.nombre-input {
  flex: 1;
  min-width: 180px;
}

.password-input {
  min-width: 160px;
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

.activo-field {
  display: flex !important;
  flex-direction: row !important;
  align-items: center;
  gap: 0.35rem;
  padding-bottom: 0.25rem;
}

.tiendas-extra {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem 0.8rem;
}

.tienda-extra {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.82rem;
}

.tiendas-extra-vacio {
  margin: 0;
  font-size: 0.78rem;
  color: #64748b;
}

.ayudas-detalle {
  flex-basis: 100%;
}

.ficha-campos input[type='text'],
.ficha-campos input:not([type]),
.ficha-campos select {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  min-height: 1.7rem;
}

.ficha-campos input:read-only,
.ficha-campos input:disabled,
.ficha-campos select:disabled {
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
