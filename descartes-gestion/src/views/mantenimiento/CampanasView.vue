<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  aFilaGrid,
  campanaVacia,
  clonarCampana,
  filaNuevaGrid,
  lineaVacia,
  payloadCampana,
  validarCampanaObligatorios,
  type CampanaFilaGrid,
  type CampanaForm,
  type CampanaLinea,
} from '@/config/campanas-columns'
import { getGridColumns, type GridFila } from '@/config/entidad-grid-columns'
import { extractApiError, listarEntidadCompleta } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

const MODULO = 'campanas'
const columns = getGridColumns('campanas')
const FILTER_KEYS = ['campana', 'descripcion', 'fecha', 'fechaFinalizacion']
const DATE_KEYS = ['fecha', 'fechaFinalizacion']

const TIPO_CAMPANA_OPTS = [
  { value: 0, label: 'Informacion' },
  { value: 1, label: 'Vales' },
  { value: 2, label: 'Mailings' },
] as const

/** Valor TipoCampaña que activa Tipo / Vale múltiple / Aplicar cliente varios. */
const TIPO_CAMPANA_VALES = 1

const TIPO_AVISO_OPTS = [
  { value: 0, label: 'Nunca' },
  { value: 1, label: 'Siempre' },
  { value: 2, label: 'Primera vez' },
  { value: 3, label: 'Si no emitido' },
  { value: 4, label: 'Si emitido' },
  { value: 5, label: 'Manual' },
] as const

const TIPO_IMPORTE_OPTS = [
  { value: '', label: '' },
  { value: 'I', label: 'Importe descuento' },
  { value: 'P', label: '% descuento' },
  { value: 'T', label: 'Ticket' },
]

type BuscarEntidadCampana =
  | 'articulos'
  | 'familias'
  | 'macrofamilias'
  | 'subfamilias'
  | 'secciones'
  | 'subsecciones'

/** Meta de letra Tipo / TipoLiquidacion (mismo mecanismo legacy). */
type TipoCodigoMeta = {
  label: string
  entidad: BuscarEntidadCampana | null
  /** false = no hay campo código (N, .) */
  conCodigo: boolean
}

const TIPO_CODIGO_META: Record<string, TipoCodigoMeta> = {
  A: { label: 'Articulo', entidad: 'articulos', conCodigo: true },
  F: { label: 'Familia', entidad: 'familias', conCodigo: true },
  I: { label: 'SubSecciones', entidad: 'subsecciones', conCodigo: true },
  M: { label: 'MacroFamilia', entidad: 'macrofamilias', conCodigo: true },
  N: { label: 'Nuevo Cliente', entidad: null, conCodigo: false },
  S: { label: 'SubFamilias', entidad: 'subfamilias', conCodigo: true },
  T: { label: 'Secciones', entidad: 'secciones', conCodigo: true },
  '.': { label: 'Total ticket', entidad: null, conCodigo: false },
}

function claveTipoLetra(letra: string): string {
  const t = letra.trim()
  return t === '.' ? '.' : t.toUpperCase()
}

function metaDeTipoLetra(letra: string): TipoCodigoMeta | undefined {
  const k = claveTipoLetra(letra)
  if (!k) return undefined
  return TIPO_CODIGO_META[k]
}

function esLetraTipoValida(letra: string): boolean {
  const k = claveTipoLetra(letra)
  if (!k) return true
  return k in TIPO_CODIGO_META
}

const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const empresaCodigo = computed(() => String(puestoContexto.empresaCodigo ?? '').trim())

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<CampanaFilaGrid[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const indiceSeleccionado = ref(0)
const indiceFicha = ref(-1)
const esNuevo = ref(false)
const modoEdicion = ref(false)

const form = reactive<CampanaForm>(campanaVacia())
const lineaSeleccionada = ref(0)

const buscarCodigoOpen = ref(false)
const buscarCodigoEntidad = ref<BuscarEntidadCampana>('articulos')
const buscarCodigoTitulo = ref('Buscar')
const buscarCodigoTarget = ref<'codigo' | 'codigoLiquidacion'>('codigo')
const buscarClienteOpen = ref(false)
const buscarClienteLinea = ref(-1)
const codigoDescripcion = ref('')
const codigoLiquidacionDescripcion = ref('')

const confirmOpen = ref(false)
const confirmMessage = ref('')
const avisoOpen = ref(false)
const avisoTitulo = ref('Campo obligatorio')
const avisoMensaje = ref('')
const campoAviso = ref<string | null>(null)

const filas = computed<GridFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value as GridFila[], filtros.value, {
    dateKeys: DATE_KEYS,
  }) as GridFila[]
  if (!puedeCrear.value) return filtradas
  if (filtradas.some((f) => f._nuevo)) return filtradas
  return [...filtradas, filaNuevaGrid() as GridFila]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const soloLectura = computed(() => !modoEdicion.value && !esNuevo.value)
const puedeGuardar = computed(
  () => (puedeCrear.value || puedeEditar.value) && (modoEdicion.value || esNuevo.value)
)
const puedeMostrarEliminar = computed(
  () =>
    puedeEliminar.value &&
    filaSeleccionada.value &&
    !filaSeleccionada.value._nuevo &&
    Boolean(filaSeleccionada.value.campana)
)
/** La ficha recorre lo que se ve en la rejilla: si la busqueda deja 3 filas, el contador es x/3. */
const filasNavegacion = computed(() => filas.value.filter((f) => !f._nuevo))
const totalFicha = computed(() => filasNavegacion.value.length)

const esCampanaVales = computed(() => Number(form.tipoCampana) === TIPO_CAMPANA_VALES)

const tipoCodigoMeta = computed(() => metaDeTipoLetra(form.tipo) ?? null)
const mostrarMetaTipo = computed(() => esCampanaVales.value && tipoCodigoMeta.value != null)
const mostrarCodigoTipo = computed(
  () => mostrarMetaTipo.value && Boolean(tipoCodigoMeta.value?.conCodigo)
)

const tipoLiquidacionMeta = computed(() => metaDeTipoLetra(form.tipoLiquidacion) ?? null)
const mostrarMetaLiquidacion = computed(() => tipoLiquidacionMeta.value != null)
const mostrarCodigoLiquidacion = computed(
  () => mostrarMetaLiquidacion.value && Boolean(tipoLiquidacionMeta.value?.conCodigo)
)

function normalizarLetraTipo(raw: string): string {
  const t = raw.trim()
  if (!t) return ''
  if (t.startsWith('.')) return '.'
  return t.toUpperCase().slice(0, 1)
}

function onTipoInput(raw: string) {
  const prev = claveTipoLetra(form.tipo)
  form.tipo = normalizarLetraTipo(raw)
  const next = claveTipoLetra(form.tipo)
  if (prev !== next) {
    form.codigo = ''
    codigoDescripcion.value = ''
  }
}

function onTipoLiquidacionInput(raw: string) {
  const prev = claveTipoLetra(form.tipoLiquidacion)
  form.tipoLiquidacion = normalizarLetraTipo(raw)
  const next = claveTipoLetra(form.tipoLiquidacion)
  if (prev !== next) {
    form.codigoLiquidacion = ''
    codigoLiquidacionDescripcion.value = ''
  }
}

function onTipoCampanaChange() {
  if (!esCampanaVales.value) {
    // Fuera de Vales estos controles no aplican en UI; no borramos datos al cambiar
  }
}

function avisarCodigoInexistente(campoFocus: string | null = null) {
  mostrarAviso(campoFocus, 'Codigo inexistente', 'Aviso')
}

async function resolverDescripcionEntidad(
  entidad: BuscarEntidadCampana,
  codigo: string
): Promise<string | null> {
  const c = codigo.trim()
  if (!c) return ''
  try {
    const { data } = await api.get(
      `/api/mantenimiento/${entidad}/${encodeURIComponent(c)}`
    )
    return String(data.descripcion ?? data.nombre ?? data.etiqueta ?? '').trim()
  } catch {
    return null
  }
}

async function onBlurLetraTipo(campo: 'tipo' | 'tipoLiquidacion') {
  const letra = campo === 'tipo' ? form.tipo : form.tipoLiquidacion
  if (!letra.trim()) return
  if (!esLetraTipoValida(letra)) {
    if (campo === 'tipo') {
      form.tipo = ''
      form.codigo = ''
      codigoDescripcion.value = ''
    } else {
      form.tipoLiquidacion = ''
      form.codigoLiquidacion = ''
      codigoLiquidacionDescripcion.value = ''
    }
    avisarCodigoInexistente(campo === 'tipo' ? 'tipo' : 'tipoLiquidacion')
  }
}

async function onBlurCodigoTipo() {
  const meta = tipoCodigoMeta.value
  if (!meta?.conCodigo || !meta.entidad) {
    codigoDescripcion.value = ''
    return
  }
  const codigo = String(form.codigo ?? '').trim()
  if (!codigo) {
    codigoDescripcion.value = ''
    return
  }
  const desc = await resolverDescripcionEntidad(meta.entidad, codigo)
  if (desc === null) {
    form.codigo = ''
    codigoDescripcion.value = ''
    avisarCodigoInexistente('codigo')
    return
  }
  codigoDescripcion.value = desc
}

async function onBlurCodigoLiquidacion() {
  const meta = tipoLiquidacionMeta.value
  if (!meta?.conCodigo || !meta.entidad) {
    codigoLiquidacionDescripcion.value = ''
    return
  }
  const codigo = String(form.codigoLiquidacion ?? '').trim()
  if (!codigo) {
    codigoLiquidacionDescripcion.value = ''
    return
  }
  const desc = await resolverDescripcionEntidad(meta.entidad, codigo)
  if (desc === null) {
    form.codigoLiquidacion = ''
    codigoLiquidacionDescripcion.value = ''
    avisarCodigoInexistente('codigoLiquidacion')
    return
  }
  codigoLiquidacionDescripcion.value = desc
}

async function refrescarDescripcionesCodigo() {
  codigoDescripcion.value = ''
  codigoLiquidacionDescripcion.value = ''
  const metaT = metaDeTipoLetra(form.tipo)
  if (metaT?.conCodigo && metaT.entidad && String(form.codigo ?? '').trim()) {
    const d = await resolverDescripcionEntidad(metaT.entidad, form.codigo)
    if (d) codigoDescripcion.value = d
  }
  const metaL = metaDeTipoLetra(form.tipoLiquidacion)
  if (metaL?.conCodigo && metaL.entidad && String(form.codigoLiquidacion ?? '').trim()) {
    const d = await resolverDescripcionEntidad(metaL.entidad, form.codigoLiquidacion)
    if (d) codigoLiquidacionDescripcion.value = d
  }
}

function mostrarAviso(campo: string | null, message: string, titulo = 'Campo obligatorio') {
  campoAviso.value = campo
  avisoTitulo.value = titulo
  avisoMensaje.value = message
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

function aplicarForm(data: CampanaForm) {
  Object.assign(form, campanaVacia(), data)
  lineaSeleccionada.value = 0
  void refrescarDescripcionesCodigo()
}

function exigirEmpresa(): string | null {
  const emp = empresaCodigo.value
  if (!emp) {
    const msg = 'Configure el puesto (empresa) antes de trabajar con campanas.'
    error.value = msg
    mensaje.value = msg
    return null
  }
  return emp
}

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  const emp = exigirEmpresa()
  if (!emp) {
    filasTodas.value = []
    total.value = 0
    return
  }
  loading.value = true
  error.value = null
  try {
    const { items, total: t } = await listarEntidadCompleta('campanas', { empresa: emp })
    total.value = t
    filasTodas.value = items.map(aFilaGrid)
    indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Error al cargar campanas')
    filasTodas.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}


function seleccionar(index: number) {
  indiceSeleccionado.value = index
}


async function abrirFichaPorCampana(campana: string | number) {
  const emp = exigirEmpresa()
  if (!emp) return
  try {
    const { data } = await api.get(
      `/api/mantenimiento/campanas/${encodeURIComponent(emp)}/${encodeURIComponent(String(campana))}`
    )
    aplicarForm(clonarCampana(data))
    const campanaNorm = String(campana).trim()
    const idx = filas.value.findIndex(
      (f) => !f._nuevo && String(f.campana ?? '').trim() === campanaNorm
    )
    if (idx >= 0) indiceSeleccionado.value = idx
    indiceFicha.value = filasNavegacion.value.findIndex(
      (f) => String(f.campana ?? '').trim() === campanaNorm
    )
    esNuevo.value = false
    modoEdicion.value = false
    vista.value = 'ficha'
    mensaje.value = null
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.campana == null || fila.campana === '') {
    mensaje.value = 'Seleccione una campana existente'
    return
  }
  indiceSeleccionado.value = idx
  await abrirFichaPorCampana(String(fila.campana).trim())
}

async function onNuevo() {
  if (!puedeCrear.value) return
  if (!exigirEmpresa()) return
  aplicarForm(campanaVacia())
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  vista.value = 'ficha'
  mensaje.value = null
  await nextTick()
  document.querySelector<HTMLElement>('[data-field-key="descripcion"]')?.focus()
}

function onModificar() {
  if (!puedeEditar.value || esNuevo.value) return
  modoEdicion.value = true
}

function onCancelar() {
  if (esNuevo.value) {
    volverAlGrid()
    return
  }
  modoEdicion.value = false
  if (form.campana) void abrirFichaPorCampana(form.campana)
}

function volverAlGrid() {
  vista.value = 'grid'
  esNuevo.value = false
  modoEdicion.value = false
}

function solicitarEliminar() {
  if (!puedeEliminar.value) return
  if (vista.value === 'grid') {
    const fila = filaSeleccionada.value
    if (!fila || fila._nuevo || !fila.campana) return
    confirmMessage.value = `Va a eliminar la campana ${fila.campana}. Esta accion no se puede deshacer.`
  } else {
    if (esNuevo.value || !form.campana) return
    confirmMessage.value = `Va a eliminar la campana ${form.campana}. Esta accion no se puede deshacer.`
  }
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const emp = exigirEmpresa()
  if (!emp) return
  const campana =
    vista.value === 'grid'
      ? String(filaSeleccionada.value?.campana ?? '')
      : String(form.campana ?? '')
  if (!campana) return
  try {
    await api.delete(
      `/api/mantenimiento/campanas/${encodeURIComponent(emp)}/${encodeURIComponent(campana)}`
    )
    mensaje.value = 'Campana eliminada'
    await cargar()
    if (vista.value === 'ficha') volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

async function onGuardar() {
  if (!puedeGuardar.value) return
  const emp = exigirEmpresa()
  if (!emp) return
  const errVal = validarCampanaObligatorios(form)
  if (errVal) {
    mostrarAviso('descripcion', errVal)
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    const payload = payloadCampana(form, emp)
    if (esNuevo.value) {
      const { data } = await api.post('/api/mantenimiento/campanas', payload)
      mensaje.value = 'Campana creada'
      await cargar()
      const campanaCreada = data?.campana != null ? String(data.campana) : ''
      if (campanaCreada) await abrirFichaPorCampana(campanaCreada)
      else volverAlGrid()
    } else {
      await api.put(
        `/api/mantenimiento/campanas/${encodeURIComponent(emp)}/${encodeURIComponent(form.campana)}`,
        payload
      )
      mensaje.value = 'Campana actualizada'
      await cargar()
      await abrirFichaPorCampana(form.campana)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar')
    mensaje.value = msg
    mostrarAviso(null, msg, 'Error al guardar')
  } finally {
    saving.value = false
  }
}

async function onPrimero() {
  if (totalFicha.value === 0) return
  const fila = filasNavegacion.value[0]
  if (fila?.campana != null && fila.campana !== '') await abrirFichaPorCampana(String(fila.campana))
}
async function onAnterior() {
  if (indiceFicha.value <= 0) return
  const fila = filasNavegacion.value[indiceFicha.value - 1]
  if (fila?.campana != null && fila.campana !== '') await abrirFichaPorCampana(String(fila.campana))
}
async function onSiguiente() {
  const max = totalFicha.value - 1
  if (indiceFicha.value < 0 || indiceFicha.value >= max) return
  const fila = filasNavegacion.value[indiceFicha.value + 1]
  if (fila?.campana != null && fila.campana !== '') await abrirFichaPorCampana(String(fila.campana))
}
async function onUltimo() {
  const max = totalFicha.value - 1
  if (max < 0) return
  const fila = filasNavegacion.value[max]
  if (fila?.campana != null && fila.campana !== '') await abrirFichaPorCampana(String(fila.campana))
}

function abrirBuscarCodigo(target: 'codigo' | 'codigoLiquidacion') {
  const meta = target === 'codigo' ? tipoCodigoMeta.value : tipoLiquidacionMeta.value
  if (!meta?.conCodigo || !meta.entidad) return
  if (target === 'codigo' && !esCampanaVales.value) return
  if (soloLectura.value) {
    if (!puedeEditar.value || esNuevo.value) return
    modoEdicion.value = true
  }
  buscarCodigoTarget.value = target
  buscarCodigoEntidad.value = meta.entidad
  buscarCodigoTitulo.value = `Buscar ${meta.label}`
  buscarCodigoOpen.value = true
}

function onCodigoSeleccionado(resultado: EntidadBuscarResultado) {
  if (buscarCodigoTarget.value === 'codigoLiquidacion') {
    form.codigoLiquidacion = resultado.codigo
    codigoLiquidacionDescripcion.value = resultado.etiqueta
  } else {
    form.codigo = resultado.codigo
    codigoDescripcion.value = resultado.etiqueta
  }
}

function asegurarEdicionLineas(): boolean {
  if (!soloLectura.value) return true
  if (!puedeEditar.value || esNuevo.value) return false
  modoEdicion.value = true
  return true
}

function agregarLinea() {
  if (!asegurarEdicionLineas()) return
  form.lineas.push(lineaVacia())
  lineaSeleccionada.value = form.lineas.length - 1
}

function quitarLinea() {
  if (!asegurarEdicionLineas()) return
  const idx = lineaSeleccionada.value
  if (idx < 0 || idx >= form.lineas.length) return
  form.lineas.splice(idx, 1)
  lineaSeleccionada.value = Math.min(idx, Math.max(0, form.lineas.length - 1))
}

function abrirBuscarCliente(index: number) {
  if (!asegurarEdicionLineas()) return
  buscarClienteLinea.value = index
  buscarClienteOpen.value = true
}

function onClienteSeleccionado(resultado: EntidadBuscarResultado) {
  const idx = buscarClienteLinea.value
  const lin = form.lineas[idx]
  if (!lin) return
  lin.cliente = resultado.codigo
  lin.clienteNombre = resultado.etiqueta
}

async function resolverClienteLinea(lin: CampanaLinea) {
  const codigo = String(lin.cliente ?? '').trim()
  if (!codigo) {
    lin.clienteNombre = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(codigo)}`)
    lin.clienteNombre = String(data.nombre ?? data.razonSocial ?? '')
  } catch {
    lin.clienteNombre = ''
  }
}
</script>

<template>
  <section class="campanas-view">
    <h2>Campañas</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver campanas.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="listado-panel">
          <div class="toolbar">
            <MantenimientoListadoButton
            titulo="Campañas"
            :columnas="columns"
            :filas="filas"
            @aviso="mensaje = $event"
          />
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
              <span>Borrar</span>
            </button>
          </div>

          <EntidadGrid
            :columns="columns"
            :filas="filas"
            :indice-seleccionado="indiceSeleccionado"
            :readonly="true"
            :loading="loading"
            :total-servidor="total"
            :filterable-keys="FILTER_KEYS"
            :date-keys="DATE_KEYS"
            v-model:filters="filtros"
            @seleccionar="seleccionar"
            @abrir="abrirFicha"
            @nuevo="onNuevo"
          />

          <p class="hint">
            Doble clic o <strong>Ficha</strong> abre el detalle. La fila <strong>*</strong> crea con Nuevo.
          </p>
        </div>
      </template>

      <template v-else>
        <div class="ficha-panel">
          <div class="sticky-chrome">
            <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>
            <div class="toolbar ficha-toolbar">
              <div class="toolbar-group">
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
                  v-if="puedeEditar"
                  type="button"
                  class="tool-btn"
                  :disabled="loading || esNuevo || modoEdicion"
                  @click="onModificar"
                >
                  <ToolIcon name="modificar" />
                  <span>Modificar</span>
                </button>
                <button
                  v-if="puedeEliminar"
                  type="button"
                  class="tool-btn danger"
                  :disabled="loading || esNuevo"
                  @click="solicitarEliminar"
                >
                  <ToolIcon name="borrar" />
                  <span>Borrar</span>
                </button>
              </div>

              <div class="toolbar-spacer"></div>

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

              <div class="toolbar-spacer"></div>

              <div class="toolbar-group">
                <button
                  v-if="puedeGuardar"
                  type="button"
                  class="tool-btn primary"
                  :disabled="saving"
                  @click="onGuardar"
                >
                  Guardar
                </button>
                <button v-if="modoEdicion || esNuevo" type="button" class="tool-btn" @click="onCancelar">
                  Cancelar
                </button>
              </div>
            </div>
          </div>

          <div class="ficha">
            <div class="cabecera">
              <label>
                Campaña
                <input :value="form.campana" readonly class="campana-input" />
              </label>
              <label class="grow">
                Descripción *
                <input
                  v-model="form.descripcion"
                  data-field-key="descripcion"
                  maxlength="80"
                  :readonly="soloLectura"
                />
              </label>
              <label>
                Tipo de Campaña
                <select
                  v-model.number="form.tipoCampana"
                  :disabled="soloLectura"
                  @change="onTipoCampanaChange"
                >
                  <option v-for="o in TIPO_CAMPANA_OPTS" :key="o.value" :value="o.value">
                    {{ o.label }}
                  </option>
                </select>
              </label>
            </div>

            <label class="obs-label">
              Observaciones
              <textarea v-model="form.observaciones" rows="2" :readonly="soloLectura" />
            </label>

            <fieldset class="bloque params-box">
              <legend>Parámetros</legend>
              <div class="row-tipo">
                <label class="tipo-char">
                  Tipo
                  <input
                    :value="form.tipo"
                    maxlength="1"
                    class="input-tipo"
                    data-field-key="tipo"
                    :readonly="soloLectura || !esCampanaVales"
                    :disabled="!esCampanaVales"
                    @input="onTipoInput(($event.target as HTMLInputElement).value)"
                    @blur="onBlurLetraTipo('tipo')"
                  />
                </label>
                <span v-if="mostrarMetaTipo" class="tipo-meta-label">{{ tipoCodigoMeta?.label }}</span>
                <label v-if="mostrarCodigoTipo" class="codigo-art">
                  <div class="codigo-buscar">
                    <input
                      v-model="form.codigo"
                      maxlength="18"
                      class="input-sm"
                      data-field-key="codigo"
                      :readonly="soloLectura || !esCampanaVales"
                      :disabled="!esCampanaVales"
                      @blur="onBlurCodigoTipo"
                    />
                    <button
                      v-if="!soloLectura && esCampanaVales"
                      type="button"
                      class="btn-lupa"
                      :title="`Buscar ${tipoCodigoMeta?.label}`"
                      @click="abrirBuscarCodigo('codigo')"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                </label>
                <span v-if="mostrarCodigoTipo && codigoDescripcion" class="codigo-desc">{{
                  codigoDescripcion
                }}</span>
              </div>

              <div class="row-checks">
                <label class="check">
                  <input
                    v-model="form.valeMultiple"
                    type="checkbox"
                    :disabled="soloLectura || !esCampanaVales"
                  />
                  Vale Múltiple
                </label>
                <label class="check">
                  <input
                    v-model="form.aplicarClienteVarios"
                    type="checkbox"
                    :disabled="soloLectura || !esCampanaVales"
                  />
                  Aplicar al cliente varios
                </label>
              </div>
            </fieldset>

            <div class="fechas-importes-row">
              <fieldset class="bloque fechas-box">
                <legend>Fechas</legend>
                <div class="fechas-grid">
                  <label class="fila-fecha">
                    <span>Fecha Inicio</span>
                    <input v-model="form.fecha" type="date" class="input-sm" :readonly="soloLectura" />
                  </label>
                  <label class="fila-fecha">
                    <span>Fecha Final</span>
                    <input
                      v-model="form.fechaFinalizacion"
                      type="date"
                      class="input-sm"
                      :readonly="soloLectura"
                    />
                  </label>
                  <label class="fila-fecha">
                    <span>Validez desde</span>
                    <input
                      v-model="form.fechaInicioCaducidadVale"
                      type="date"
                      class="input-sm"
                      :readonly="soloLectura"
                    />
                  </label>
                  <label class="fila-fecha">
                    <span>Hasta</span>
                    <input
                      v-model="form.fechaCaducidadVale"
                      type="date"
                      class="input-sm"
                      :readonly="soloLectura"
                    />
                  </label>
                </div>
              </fieldset>

              <fieldset class="bloque importes-box">
                <legend>Importes</legend>
                <div class="importes-grid">
                  <label class="fila-importe">
                    <span>Importe Descuento</span>
                    <select v-model="form.tipoImporte" class="input-sm" :disabled="soloLectura">
                      <option v-for="o in TIPO_IMPORTE_OPTS" :key="o.value" :value="o.value">
                        {{ o.label }}
                      </option>
                    </select>
                  </label>
                  <label class="fila-importe">
                    <span>Importe</span>
                    <DecimalInput
                      v-model="form.importeVale"
                      class="input-sm"
                      :empty-as-null="true"
                      :readonly="soloLectura"
                    />
                  </label>
                  <label class="fila-importe">
                    <span>Importe mínimo</span>
                    <DecimalInput
                      v-model="form.importeMinimo"
                      class="input-sm"
                      :empty-as-null="true"
                      :readonly="soloLectura"
                    />
                  </label>
                  <label class="check">
                    <input
                      v-model="form.porcentajeSobreCompra"
                      type="checkbox"
                      :disabled="soloLectura"
                    />
                    % Sobre Importe Ticket
                  </label>
                  <label class="check">
                    <input v-model="form.diaSinIva" type="checkbox" :disabled="soloLectura" />
                    Día sin IVA
                  </label>
                </div>
              </fieldset>
            </div>

            <div class="liquidacion-block">
              <p class="liq-intro">
                El vale se podrá liquidar en la siguiente compra siempre que el importe total
              </p>
              <div class="liquidacion-row">
                <span class="liq-text">de</span>
                <input
                  :value="form.tipoLiquidacion"
                  maxlength="1"
                  class="tipo-liq"
                  data-field-key="tipoLiquidacion"
                  :readonly="soloLectura"
                  @input="onTipoLiquidacionInput(($event.target as HTMLInputElement).value)"
                  @blur="onBlurLetraTipo('tipoLiquidacion')"
                />
                <span v-if="mostrarMetaLiquidacion" class="tipo-meta-label">{{
                  tipoLiquidacionMeta?.label
                }}</span>
                <template v-if="mostrarCodigoLiquidacion">
                  <div class="codigo-buscar">
                    <input
                      v-model="form.codigoLiquidacion"
                      maxlength="18"
                      class="input-sm"
                      data-field-key="codigoLiquidacion"
                      :readonly="soloLectura"
                      @blur="onBlurCodigoLiquidacion"
                    />
                    <button
                      v-if="!soloLectura"
                      type="button"
                      class="btn-lupa"
                      :title="`Buscar ${tipoLiquidacionMeta?.label}`"
                      @click="abrirBuscarCodigo('codigoLiquidacion')"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                  <span v-if="codigoLiquidacionDescripcion" class="codigo-desc">{{
                    codigoLiquidacionDescripcion
                  }}</span>
                </template>
                <span class="liq-text">sea superior a</span>
                <DecimalInput
                  v-model="form.importeLiquidacion"
                  :empty-as-null="true"
                  :readonly="soloLectura"
                  class="importe-liq"
                />
              </div>
            </div>

            <div class="informar-row">
              <span class="section-title">Informar</span>
              <label>
                Tipo aviso
                <select v-model.number="form.tipoAviso" :disabled="soloLectura">
                  <option v-for="o in TIPO_AVISO_OPTS" :key="o.value" :value="o.value">
                    {{ o.label }}
                  </option>
                </select>
              </label>
              <label class="check">
                <input v-model="form.avisoMultiple" type="checkbox" :disabled="soloLectura" />
                Aviso múltiple
              </label>
            </div>

            <div class="literales-row">
              <fieldset class="bloque literales-box">
                <legend>Literales Vales</legend>
                <div class="literales">
                  <input v-model="form.literalVale1" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalVale2" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalVale3" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalVale4" maxlength="60" :readonly="soloLectura" />
                </div>
              </fieldset>

              <fieldset class="bloque literales-box">
                <legend>Literales Información</legend>
                <div class="literales">
                  <input v-model="form.literalAviso1" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalAviso2" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalAviso3" maxlength="60" :readonly="soloLectura" />
                  <input v-model="form.literalAviso4" maxlength="60" :readonly="soloLectura" />
                </div>
              </fieldset>
            </div>

            <fieldset class="bloque lineas-box">
              <legend>Clientes de la campaña</legend>
              <div class="lineas-toolbar">
                <button type="button" class="tool-btn" :disabled="!puedeEditar && !esNuevo" @click="agregarLinea">
                  Añadir linea
                </button>
                <button
                  type="button"
                  class="tool-btn danger"
                  :disabled="(!puedeEditar && !esNuevo) || form.lineas.length === 0"
                  @click="quitarLinea"
                >
                  Quitar
                </button>
                <span v-if="soloLectura && puedeEditar" class="hint-lineas">
                  Pulsa Añadir (pasa a Modificar) o Modificar para editar lineas.
                </span>
              </div>
              <div class="lineas-wrap">
                <table class="lineas-table">
                  <thead>
                    <tr>
                      <th>Cliente</th>
                      <th>Nombre</th>
                      <th>Aviso</th>
                      <th>Emitido</th>
                      <th>Liquidado</th>
                      <th>Observación</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(lin, i) in form.lineas"
                      :key="i"
                      :class="{ selected: i === lineaSeleccionada }"
                      @click="lineaSeleccionada = i"
                    >
                      <td>
                        <div class="codigo-buscar">
                          <input
                            v-model="lin.cliente"
                            maxlength="9"
                            :readonly="soloLectura"
                            @blur="resolverClienteLinea(lin)"
                          />
                          <button
                            v-if="!soloLectura"
                            type="button"
                            class="btn-lupa"
                            title="Buscar cliente"
                            @click="abrirBuscarCliente(i)"
                          >
                            <ToolIcon name="buscar" />
                          </button>
                        </div>
                      </td>
                      <td>
                        <input v-model="lin.clienteNombre" readonly class="nombre-cli" />
                      </td>
                      <td>
                        <input v-model="lin.fechaEnvio" type="date" :readonly="soloLectura" />
                      </td>
                      <td>
                        <input v-model="lin.fechaEmision" type="date" :readonly="soloLectura" />
                      </td>
                      <td>
                        <input v-model="lin.asistencia" type="date" :readonly="soloLectura" />
                      </td>
                      <td>
                        <input v-model="lin.observaciones" :readonly="soloLectura" />
                      </td>
                    </tr>
                    <tr v-if="form.lineas.length === 0">
                      <td colspan="6" class="empty">Sin lineas. Usa &quot;Añadir linea&quot;.</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </fieldset>
          </div>
        </div>
      </template>

      <EntidadBuscarModal
        :open="buscarCodigoOpen"
        :entidad="buscarCodigoEntidad"
        :titulo="buscarCodigoTitulo"
        @seleccionar="onCodigoSeleccionado"
        @cerrar="buscarCodigoOpen = false"
      />
      <EntidadBuscarModal
        :open="buscarClienteOpen"
        entidad="clientes"
        @seleccionar="onClienteSeleccionado"
        @cerrar="buscarClienteOpen = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar campana"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="confirmOpen = false"
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
.campanas-view h2 {
  margin: 0 0 0.75rem;
}

.listado-panel > .toolbar,
.listado-panel :deep(.grid-wrap) {
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

.ficha-toolbar {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.5rem;
  align-items: center;
  width: 100%;
  box-sizing: border-box;
}

.toolbar-group {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.35rem;
  align-items: center;
  flex-shrink: 0;
}

.toolbar-group.nav {
  flex-shrink: 0;
}

.toolbar-spacer {
  flex: 1 1 auto;
  min-width: 0.5rem;
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

.ficha-panel {
  width: 100%;
  max-width: 58rem;
  min-width: min(100%, 42rem);
  box-sizing: border-box;
}

.sticky-chrome {
  width: 100%;
  box-sizing: border-box;
}

.ficha {
  width: 100%;
  min-width: 0;
  background: #e8edf2;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  padding: 0.65rem;
  box-sizing: border-box;
  display: grid;
  gap: 0.55rem;
}

.cabecera {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: end;
}

.cabecera label,
.obs-label,
.params-right label,
.fechas-grid label,
.liquidacion-row label,
.informar-row label,
.codigo-art {
  display: grid;
  gap: 0.15rem;
  font-size: 0.78rem;
  color: #334155;
}

.cabecera .grow {
  flex: 1;
  min-width: 220px;
}

.campana-input {
  width: 5rem;
}

.ficha input,
.ficha select,
.ficha textarea {
  padding: 0.2rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  background: #fff;
  box-sizing: border-box;
}

.ficha textarea {
  width: 100%;
  resize: vertical;
  font-family: inherit;
}

.bloque {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  border: 1px solid #b6c0cc;
  border-radius: 6px;
  background: #f3f6f9;
}

.bloque legend {
  font-size: 0.78rem;
  font-weight: 600;
  padding: 0 0.25rem;
  color: #1e293b;
}

.params-box {
  margin-bottom: 0.55rem;
}

.fechas-importes-row {
  display: grid;
  grid-template-columns: auto auto;
  gap: 0.55rem;
  margin-bottom: 0.55rem;
  justify-content: start;
  align-items: start;
}

.fechas-box,
.importes-box {
  width: fit-content;
  max-width: 100%;
}

.importes-box {
  background: #f7ebe0;
  border-color: #e0c4a8;
}

.fechas-grid {
  display: grid;
  gap: 0.28rem 0.45rem;
}

.fila-fecha,
.fila-importe {
  display: grid !important;
  grid-template-columns: 6.5rem auto;
  gap: 0.35rem;
  align-items: center;
  font-size: 0.75rem;
}

.fila-fecha span,
.fila-importe span {
  color: #475569;
}

.importes-grid {
  display: grid;
  gap: 0.28rem 0.45rem;
}

.input-sm,
.fechas-box input[type='date'],
.importes-box select,
.importes-box :deep(input) {
  width: 7.5rem;
  max-width: 7.5rem;
  padding: 0.15rem 0.3rem !important;
  font-size: 0.75rem !important;
  box-sizing: border-box;
}

.codigo-art .input-sm {
  width: 5rem;
  max-width: 5rem;
}

@media (max-width: 700px) {
  .fechas-importes-row {
    grid-template-columns: 1fr;
  }
}

.row-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 1rem;
  align-items: end;
}

.row-tipo {
  display: flex;
  flex-wrap: wrap;
  gap: 0.55rem 0.85rem;
  align-items: center;
}

.tipo-char .input-tipo {
  width: 1.5rem;
  min-width: 1.5rem;
  max-width: 1.5rem;
  padding: 0.15rem 0.1rem;
  text-align: center;
  text-transform: uppercase;
  font-weight: 700;
  font-size: 0.75rem;
  box-sizing: border-box;
}

.tipo-meta-label {
  font-size: 0.78rem;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
}

.codigo-desc {
  font-size: 0.8rem;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
}

.hint-lineas {
  font-size: 0.75rem;
  color: #64748b;
  align-self: center;
}

.check {
  display: inline-flex !important;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.78rem;
  grid-template-columns: none !important;
}

.codigo-buscar {
  display: flex;
  gap: 0.25rem;
  align-items: stretch;
}

.codigo-buscar input {
  flex: 1;
  min-width: 0;
  width: 8rem;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: auto;
  align-self: stretch;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
  color: #334155;
  flex-shrink: 0;
  box-sizing: border-box;
}

.btn-lupa:hover {
  background: #e0f2fe;
  border-color: #38bdf8;
}

.liquidacion-block {
  margin-bottom: 0.55rem;
}

.liq-intro {
  margin: 0 0 0.35rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #1e293b;
}

.liquidacion-row,
.informar-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
  align-items: center;
  padding: 0.4rem 0.5rem;
  background: #f3f6f9;
  border: 1px solid #b6c0cc;
  border-radius: 6px;
}

.liq-text,
.section-title {
  font-size: 0.78rem;
  font-weight: 600;
  color: #1e293b;
  align-self: center;
}

.tipo-liq {
  width: 1.5rem;
  min-width: 1.5rem;
  max-width: 1.5rem;
  padding: 0.15rem 0.1rem;
  text-align: center;
  text-transform: uppercase;
  font-weight: 700;
  font-size: 0.75rem;
  box-sizing: border-box;
}

.importe-liq {
  width: 5.5rem;
}

.literales-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem;
  margin-bottom: 0.55rem;
  align-items: start;
}

.literales-box {
  min-width: 0;
}

.literales {
  display: grid;
  gap: 0.3rem;
}

.literales input {
  width: 100%;
  box-sizing: border-box;
}

@media (max-width: 700px) {
  .literales-row {
    grid-template-columns: 1fr;
  }
}

.lineas-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
  margin-bottom: 0.4rem;
}

.lineas-wrap {
  overflow: auto;
  max-height: 220px;
  border: 1px solid #cbd5e1;
  background: #fff;
}

.lineas-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.lineas-table th,
.lineas-table td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.2rem 0.3rem;
  text-align: left;
  white-space: nowrap;
}

.lineas-table th {
  background: #eef2f7;
  position: sticky;
  top: 0;
  z-index: 1;
}

.lineas-table tr.selected {
  background: #dbeafe;
}

.lineas-table .nombre-cli {
  min-width: 10rem;
  width: 100%;
}

.lineas-table .empty {
  text-align: center;
  color: #64748b;
  padding: 0.6rem;
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

@media (max-width: 900px) {
  .fechas-importes-row {
    grid-template-columns: 1fr;
  }

  .ficha-panel {
    min-width: 0;
  }

  .ficha-toolbar {
    flex-wrap: wrap;
  }
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
