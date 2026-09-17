<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, nextTick, onActivated, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import { articuloColumns, 
  articuloFilaVacia,
  clonarArticuloFila,
  type ArticuloFila,
} from '@/config/articulos-columns'
import {
  ARTICULO_CAMPOS_OBLIGATORIOS,
  articuloTabs,
  articuloVacio,
  camposArticuloObligatoriosVacios,
} from '@/config/articulos-tabs'
import ArticulosGrid from '@/components/articulos/ArticulosGrid.vue'
import ArticuloToolbar from '@/components/articulos/ArticuloToolbar.vue'
import ArticuloTabForm from '@/components/articulos/ArticuloTabForm.vue'
import ArticuloSidePanels from '@/components/articulos/ArticuloSidePanels.vue'
import ArticuloFichaPlantaModal from '@/components/articulos/ArticuloFichaPlantaModal.vue'
import ArticuloConsultaModal from '@/components/articulos/ArticuloConsultaModal.vue'
import ArticuloEansModal from '@/components/articulos/ArticuloEansModal.vue'
import ArticuloEscandalloModal from '@/components/articulos/ArticuloEscandalloModal.vue'
import ArticuloEtiquetasRapidaModal from '@/components/articulos/ArticuloEtiquetasRapidaModal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'

const MODULO = 'articulos'
const ENTIDAD = 'articulos'
const FILTER_KEYS = ['codigo', 'descripcion', 'familia', 'impuestoCodigo', 'proveedorHabitual', 'precioVen1']

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const { items, total, page, pageSize, loading, error, listar, obtener, crear, actualizar, eliminar } =
  useMantenimiento(() => ENTIDAD)
const puestoContexto = usePuestoContextoStore()

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
/** Impresión rápida etiquetas (005 / US1): módulo etiquetas. */
const puedeEtiquetasVer = computed(() => puede('etiquetas', 'ver'))
const puedeEtiquetasImprimir = computed(() => puede('etiquetas', 'editar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<ArticuloFila[]>([])
const filaNuevaDraft = ref<ArticuloFila>(articuloFilaVacia())
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const indiceSeleccionado = ref(0)
const mensaje = ref<string | null>(null)

const familiaOpciones = ref<{ value: string; label: string }[]>([])
const impuestoOpciones = ref<{ value: string; label: string }[]>([])
const proveedorOpciones = ref<{ value: string; label: string }[]>([])

const listadoOptionsMap = computed(() => ({
  familias: familiaOpciones.value,
  impuestos: impuestoOpciones.value,
  proveedores: proveedorOpciones.value,
}))

function etiquetaOpcion(
  opciones: { value: string; label: string }[],
  codigo: unknown
): string {
  const c = String(codigo ?? '').trim()
  if (!c) return ''
  const hit = opciones.find((o) => o.value === c)
  if (!hit) return ''
  // label "01 - BEBIDAS" -> usar parte descripcion + label completo
  const sep = hit.label.indexOf(' - ')
  return sep >= 0 ? hit.label.slice(sep + 3) : hit.label
}

const filas = computed<ArticuloFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value, {
    extraTexto: {
      familia: (f) => etiquetaOpcion(familiaOpciones.value, f.familia),
      impuestoCodigo: (f) => etiquetaOpcion(impuestoOpciones.value, f.impuestoCodigo),
      proveedorHabitual: (f) => etiquetaOpcion(proveedorOpciones.value, f.proveedorHabitual),
    },
  }) as ArticuloFila[]
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaNuevaDraft.value]
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

const tabActiva = ref(articuloTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const codigoAutomatico = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)
const mostrarFichaPlanta = ref(false)
const mostrarConsulta = ref(false)
const mostrarEans = ref(false)
const mostrarEscandallo = ref(false)
const mostrarEtiquetas = ref(false)
const camposInvalidos = ref<string[]>([])
const avisoOpen = ref(false)
const avisoTitulo = ref('Campo obligatorio')
const avisoMensaje = ref('')
/** Campo que acaba de avisar el modal (para enfocar al aceptar). */
const campoAvisoActual = ref<string | null>(null)
const codigoInput = ref<HTMLInputElement | null>(null)
const descripcionInput = ref<HTMLInputElement | null>(null)

const codigoReadOnlyFicha = computed(() => !esNuevo.value || codigoAutomatico.value)

function mostrarAviso(titulo: string, message: string) {
  campoAvisoActual.value = null
  avisoTitulo.value = titulo
  avisoMensaje.value = message
  avisoOpen.value = true
}

/** Un solo aviso: el primer campo obligatorio vacio. */
function mostrarAvisoCampoObligatorio(key: string, message: string) {
  campoAvisoActual.value = key
  camposInvalidos.value = [key]
  avisoTitulo.value = 'Campo obligatorio'
  avisoMensaje.value = message
  avisoOpen.value = true
}

async function cerrarAviso() {
  const key = campoAvisoActual.value
  avisoOpen.value = false
  avisoMensaje.value = ''
  campoAvisoActual.value = null
  if (!key) return
  await nextTick()
  await enfocarCampo(key)
}

async function enfocarCampo(key: string) {
  tabActiva.value = 'general'
  await nextTick()
  await nextTick()
  if (key === 'codigo') {
    codigoInput.value?.focus()
    return
  }
  if (key === 'descripcion') {
    descripcionInput.value?.focus()
    return
  }
  const el = document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)
  el?.focus()
}

function esCampoInvalido(key: string) {
  return camposInvalidos.value.includes(key)
}

function limpiarCampoInvalido(key: string) {
  if (!camposInvalidos.value.includes(key)) return
  camposInvalidos.value = camposInvalidos.value.filter((k) => k !== key)
}

function onFichaUpdate(next: Record<string, unknown>) {
  ficha.value = next
  if (camposInvalidos.value.length === 0) return
  // Solo quita el resaltado de los campos que ya se han rellenado.
  camposInvalidos.value = camposInvalidos.value.filter((k) => !String(next[k] ?? '').trim())
}

const tabSeleccionada = computed(() => articuloTabs.find((t) => t.id === tabActiva.value) ?? articuloTabs[0])
const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)

function quitarFilaNueva() {
  filaNuevaDraft.value = articuloFilaVacia()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

onMounted(async () => {
  if (!puedeVer.value) return
  await cargarOpciones()
  await cargar()
  await restaurarDesdeRuta()
})

onActivated(() => {
  if (vista.value === 'ficha') {
    void sincronizarRutaFicha()
    return
  }
  void restaurarDesdeRuta()
})

watch(vista, (v) => {
  if (v === 'grid') enfocarScanInput()
})

function queryFichaActiva(): boolean {
  const codigo = String(route.query.codigo ?? '').trim()
  return route.query.vista === 'ficha' || codigo !== '' || route.query.nuevo === '1'
}

async function sincronizarRutaFicha() {
  if (vista.value !== 'ficha') return
  const codigo = String(ficha.value.codigo ?? '').trim()
  const query: Record<string, string> = { vista: 'ficha' }
  if (esNuevo.value) {
    query.nuevo = '1'
    if (codigo) query.codigo = codigo
  } else if (codigo) {
    query.codigo = codigo
  }
  const mismo =
    route.query.vista === query.vista &&
    String(route.query.codigo ?? '') === (query.codigo ?? '') &&
    String(route.query.nuevo ?? '') === (query.nuevo ?? '')
  if (mismo) return
  await router.replace({ path: route.path, query })
}

async function restaurarDesdeRuta() {
  if (!puedeVer.value) return
  if (!queryFichaActiva()) {
    if (vista.value === 'ficha') resetVistaGrid()
    else enfocarScanInput()
    return
  }
  if (route.query.nuevo === '1') {
    if (vista.value === 'ficha' && esNuevo.value) return
    await onNuevoFicha()
    return
  }
  const codigo = String(route.query.codigo ?? '').trim()
  if (!codigo) return
  if (vista.value === 'ficha' && String(ficha.value.codigo ?? '').trim() === codigo) return
  await abrirFichaPorCodigo(codigo, { sincronizarRuta: false })
}

watch(
  () =>
    [route.query.vista, route.query.codigo, route.query.nuevo].map((v) => String(v ?? '')).join('|'),
  () => {
    void restaurarDesdeRuta()
  }
)

async function cargarOpciones() {
  const [familiasRes, impuestosRes, proveedoresRes] = await Promise.all([
    api.get('/api/mantenimiento/familias', { params: { pageSize: 500 } }),
    api.get('/api/mantenimiento/impuestos', { params: { activo: true, pageSize: 100 } }),
    api.get('/api/mantenimiento/proveedores', { params: { activo: true, pageSize: 500 } }),
  ])
  familiaOpciones.value = (familiasRes.data.items ?? []).map((f: { codigo: string; descripcion: string }) => ({
    value: String(f.codigo).trim(),
    label: `${String(f.codigo).trim()} - ${f.descripcion}`,
  }))
  impuestoOpciones.value = (impuestosRes.data.items ?? []).map((i: { codigo: string; descripcion: string }) => ({
    value: String(i.codigo).trim(),
    label: `${String(i.codigo).trim()} - ${i.descripcion}`,
  }))
  proveedorOpciones.value = (proveedoresRes.data.items ?? []).map((p: { codigo: string; nombre: string }) => ({
    value: String(p.codigo).trim(),
    label: `${String(p.codigo).trim()} - ${p.nombre}`,
  }))
}

function mapFilasDesdeApi() {
  filasTodas.value = items.value.map((item) => clonarArticuloFila(item))
}

async function cargar() {
  mensaje.value = null
  await listar()
  mapFilasDesdeApi()
  filaNuevaDraft.value = articuloFilaVacia()
  indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
}

function onFiltroSearch() {
  const q = String(filtros.value.codigo?.valor ?? '').trim()
  if (q) void intentarAbrirFichaPorReferencia(q)
}

/** Abre ficha si `q` es código, alternativo o EAN (escáner / Intro). */
async function intentarAbrirFichaPorReferencia(raw: string): Promise<boolean> {
  const q = String(raw ?? '').trim()
  if (q.length < 4 || /\s/.test(q) || !/^[0-9A-Za-z\-]+$/.test(q)) return false
  try {
    const art = await resolverArticulo(q)
    await abrirFichaPorCodigo(art.codigo)
    return true
  } catch {
    return false
  }
}

const scanCodigo = ref('')
const scanInputRef = ref<HTMLInputElement | null>(null)
const scanBusy = ref(false)

function enfocarScanInput() {
  if (vista.value !== 'grid') return
  void nextTick(() => {
    requestAnimationFrame(() => {
      scanInputRef.value?.focus()
      scanInputRef.value?.select()
    })
  })
}

async function abrirPorEscaneo(raw: string) {
  const q = String(raw ?? '').trim()
  if (!q || scanBusy.value || vista.value !== 'grid') return
  scanBusy.value = true
  mensaje.value = null
  try {
    const art = await resolverArticulo(q)
    scanCodigo.value = ''
    await abrirFichaPorCodigo(art.codigo)
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'Artículo no encontrado (código / EAN)')
    enfocarScanInput()
  } finally {
    scanBusy.value = false
  }
}

const scanWatcher = createBarcodeScanWatcher((codigo) => {
  void abrirPorEscaneo(codigo)
})

function onScanInput() {
  scanWatcher.onInput(scanCodigo.value)
}

function onScanKeydown(e: KeyboardEvent) {
  if (e.key !== 'Enter') return
  e.preventDefault()
  scanWatcher.cancel()
  void abrirPorEscaneo(scanCodigo.value)
}

const {
  confirmOpen,
  confirmMessage,
  solicitarEliminar,
  confirmarEliminar,
  cancelarEliminar,
} = useEliminarFilaGrid({
  puedeEliminar,
  filaSeleccionada,
  eliminarApi: eliminar,
  recargar: cargar,
  quitarFilaNueva,
  setMensaje: (msg) => {
    mensaje.value = msg
  },
  etiquetaEntidad: 'el articulo',
  mensajeExito: 'Articulo eliminado',
})

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}


async function abrirFichaPorCodigo(codigo: string, opts?: { sincronizarRuta?: boolean }) {
  try {
    ficha.value = await obtener(codigo)
    if (ficha.value.precioVen1 == null && ficha.value.precioVenta != null) {
      ficha.value.precioVen1 = ficha.value.precioVenta
    }
    indiceFicha.value = filas.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    codigoAutomatico.value = false
    camposInvalidos.value = []
    tabActiva.value = 'general'
    vista.value = 'ficha'
    mensaje.value = null
    if (opts?.sincronizarRuta !== false) {
      await sincronizarRutaFicha()
    }
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || !fila.codigo) {
    mensaje.value = 'Seleccione un articulo existente para abrir la ficha'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevoFicha() {
  if (!puedeCrear.value) return
  const vacio = articuloVacio()
  codigoAutomatico.value = false
  mensaje.value = null
  camposInvalidos.value = []
  try {
    const { data } = await api.get('/api/mantenimiento/articulos/siguiente-codigo', {
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
  tabActiva.value = 'general'
  vista.value = 'ficha'
  await sincronizarRutaFicha()
  await enfocarDescripcion()
}

async function enfocarDescripcion() {
  await nextTick()
  // Segundo tick: al venir del grid la ficha acaba de montarse.
  await nextTick()
  descripcionInput.value?.focus()
}

function onModificarFicha() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

async function onGuardarFicha() {
  const vacios = camposArticuloObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const label = ARTICULO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
    tabActiva.value = 'general'
    mostrarAvisoCampoObligatorio(key, `El campo "${label}" es obligatorio.`)
    return
  }
  camposInvalidos.value = []
  try {
    if (ficha.value.precioVen1 != null) ficha.value.precioVenta = ficha.value.precioVen1
    if (esNuevo.value) {
      const creado = await crear(ficha.value)
      mensaje.value = 'Articulo creado correctamente'
      await cargar()
      const idx = filas.value.findIndex((a) => String(a.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await actualizar(String(ficha.value.codigo), ficha.value)
      mensaje.value = 'Articulo actualizado'
      await cargar()
      await abrirFichaPorCodigo(String(ficha.value.codigo))
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar el articulo')
    mensaje.value = msg
    mostrarAviso('Error al guardar', msg)
  }
}

function onCancelarFicha() {
  if (esNuevo.value) {
    volverAlGrid()
    return
  }
  modoEdicion.value = false
  if (ficha.value.codigo) abrirFichaPorCodigo(String(ficha.value.codigo))
}

async function onBorrarFicha() {
  if (!puedeEliminar.value || esNuevo.value || !ficha.value.codigo) return
  if (!confirm('Dar de baja este articulo?')) return
  try {
    await eliminar(String(ficha.value.codigo))
    mensaje.value = 'Articulo dado de baja'
    await cargar()
    volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo dar de baja')
  }
}

function resetVistaGrid() {
  vista.value = 'grid'
  modoEdicion.value = false
  esNuevo.value = false
  codigoAutomatico.value = false
  camposInvalidos.value = []
  ficha.value = {}
  scanCodigo.value = ''
}

function volverAlGrid() {
  resetVistaGrid()
  enfocarScanInput()
  void router.replace({ path: route.path, query: {} })
}

function onAccionPendiente(nombre: string) {
  const detalle: Record<string, string> = {
    Excepciones:
      'En BD solo aparece ExcepcionesAsignacionPedidos (Region/Proveedor/Articulo), sin uso claro. Confirma el dialogo legacy para portarlo.',
  }
  mensaje.value = detalle[nombre] ?? `${nombre}: pendiente de portar`
}

function onEtiquetas() {
  if (!ficha.value.codigo || esNuevo.value) {
    mensaje.value = 'Guarde el articulo antes de imprimir etiquetas'
    return
  }
  if (!puedeEtiquetasVer.value && !puedeEtiquetasImprimir.value) {
    mensaje.value = 'Sin permiso de etiquetas'
    return
  }
  mostrarEtiquetas.value = true
}

function onEtiquetaImpresa(msg: string) {
  mensaje.value = msg
}

function onFichaPlanta() {
  if (!ficha.value.codigo || esNuevo.value) {
    mensaje.value = 'Guarde el articulo antes de abrir la ficha de planta'
    return
  }
  mostrarFichaPlanta.value = true
}

function onConsulta() {
  mostrarConsulta.value = true
}

function onEans() {
  if (!ficha.value.codigo || esNuevo.value) {
    mensaje.value = 'Guarde el articulo antes de gestionar EAN'
    return
  }
  mostrarEans.value = true
}

function onEscandallo() {
  if (!ficha.value.codigo || esNuevo.value) {
    mensaje.value = 'Guarde el articulo antes de gestionar el escandallo'
    return
  }
  mostrarEscandallo.value = true
}

function onBloqueo() {
  tabActiva.value = 'parametros'
  mensaje.value = 'Bloqueos de compra/venta estan en la pestana Parametros'
}

async function onPrimero() {
  const primera = filas.value.find((f) => !f._nuevo)
  if (primera?.codigo) await abrirFichaPorCodigo(String(primera.codigo))
}
async function onAnterior() {
  if (indiceFicha.value <= 0) return
  const fila = filas.value[indiceFicha.value - 1]
  if (fila?.codigo && !fila._nuevo) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onSiguiente() {
  const max = totalFicha.value - 1
  if (indiceFicha.value < 0 || indiceFicha.value >= max) return
  const fila = filas.value[indiceFicha.value + 1]
  if (fila?.codigo && !fila._nuevo) await abrirFichaPorCodigo(String(fila.codigo))
}
async function onUltimo() {
  const visibles = filas.value.filter((f) => !f._nuevo)
  const ultima = visibles[visibles.length - 1]
  if (ultima?.codigo) await abrirFichaPorCodigo(String(ultima.codigo))
}

const totalFicha = computed(() => filas.value.filter((f) => !f._nuevo).length)
</script>

<template>
  <section class="articulos-view">
    <h2>Articulos</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver articulos.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <!-- GRID (mismo patron Familias) -->
      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
        <div class="toolbar">
          <MantenimientoListadoButton
            titulo="Artículos"
            :columnas="articuloColumns"
            :filas="filas"
            :options-map="listadoOptionsMap"
            @aviso="mensaje = $event"
          />
          <button
            v-if="puedeCrear"
            type="button"
            class="tool-btn"
            :disabled="loading"
            @click="onNuevoFicha"
          >
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

          <label class="scan-box" title="Escanee o escriba código / EAN y pulse Intro">
            <span>Escanear</span>
            <input
              ref="scanInputRef"
              v-model="scanCodigo"
              type="text"
              maxlength="32"
              placeholder="Código / EAN"
              :disabled="loading || scanBusy"
              @input="onScanInput"
              @keydown="onScanKeydown"
            />
          </label>

          <div class="toolbar-spacer"></div>

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

        <ArticulosGrid
          :filas="filas"
          :indice-seleccionado="indiceSeleccionado"
          :familia-opciones="familiaOpciones"
          :impuesto-opciones="impuestoOpciones"
          :proveedor-opciones="proveedorOpciones"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :readonly="true"
          :loading="loading"
          :total-servidor="total"
          @seleccionar="seleccionar"
          @abrir="abrirFicha"
          @nuevo="onNuevoFicha"
          @search="onFiltroSearch"
        />

        <p class="hint">
          <strong>Escanear</strong> código o EAN abre la ficha. Escriba bajo cada columna para filtrar. Doble clic en una fila o en <strong>*</strong> para crear.
        </p>
        </div>
      </template>

      <!-- FICHA detallada (legacy) -->
      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

          <ArticuloToolbar
            :puede-crear="puedeCrear"
            :puede-editar="puedeEditar"
            :puede-eliminar="puedeEliminar"
            :puede-guardar="puedeCrear || puedeEditar"
            :puede-etiquetas="puedeEtiquetasVer || puedeEtiquetasImprimir"
            :modo-edicion="modoEdicion || esNuevo"
            :indice="indiceFicha < 0 ? undefined : indiceFicha"
            :total="totalFicha"
            :loading="loading"
            @nuevo="onNuevoFicha"
            @modificar="onModificarFicha"
            @borrar="onBorrarFicha"
            @buscar="volverAlGrid"
            @guardar="onGuardarFicha"
            @cancelar="onCancelarFicha"
            @primero="onPrimero"
            @anterior="onAnterior"
            @siguiente="onSiguiente"
            @ultimo="onUltimo"
            @escandallo="onEscandallo"
            @eans="onEans"
            @etiquetas="onEtiquetas"
            @ficha="onFichaPlanta"
            @consulta="onConsulta"
            @bloqueo="onBloqueo"
            @excepciones="onAccionPendiente('Excepciones')"
          />

          <div class="ficha-header">
            <label :class="{ 'campo-invalido': esCampoInvalido('codigo') }">
              Codigo *
              <input
                ref="codigoInput"
                v-model="ficha.codigo"
                data-field-key="codigo"
                :readonly="codigoReadOnlyFicha"
                maxlength="18"
                class="codigo-input"
                required
                @input="limpiarCampoInvalido('codigo')"
              />
            </label>
            <label class="nombre-input" :class="{ 'campo-invalido': esCampoInvalido('descripcion') }">
              Descripcion *
              <input
                ref="descripcionInput"
                v-model="ficha.descripcion"
                data-field-key="descripcion"
                :readonly="soloLecturaFicha"
                maxlength="50"
                required
                @input="limpiarCampoInvalido('descripcion')"
              />
            </label>
          </div>

          <div class="tabs">
            <button
              v-for="tab in articuloTabs"
              :key="tab.id"
              type="button"
              class="tab"
              :class="{ active: tabActiva === tab.id }"
              @click="tabActiva = tab.id"
            >
              {{ tab.label }}
            </button>
          </div>
        </div>

        <div class="ficha-body">
          <ArticuloTabForm
            :sections="tabSeleccionada.sections"
            :model-value="ficha"
            :readonly="soloLecturaFicha"
            :codigo-read-only="!esNuevo"
            :invalid-keys="camposInvalidos"
            @update:model-value="onFichaUpdate"
          />
          <ArticuloSidePanels :ficha="ficha" />
        </div>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar articulo"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
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

      <ArticuloFichaPlantaModal
        v-if="mostrarFichaPlanta && ficha.codigo"
        :open="mostrarFichaPlanta"
        :articulo-codigo="String(ficha.codigo)"
        :articulo-descripcion="String(ficha.descripcion ?? '')"
        :readonly="!puedeEditar"
        @cerrar="mostrarFichaPlanta = false"
        @guardado="abrirFichaPorCodigo(String(ficha.codigo))"
      />

      <ArticuloConsultaModal
        v-if="mostrarConsulta"
        :open="mostrarConsulta"
        @cerrar="mostrarConsulta = false"
      />

      <ArticuloEansModal
        v-if="mostrarEans && ficha.codigo"
        :open="mostrarEans"
        :codigo="String(ficha.codigo)"
        :descripcion="String(ficha.descripcion ?? '')"
        :readonly="!puedeEditar"
        @cerrar="mostrarEans = false"
      />

      <ArticuloEtiquetasRapidaModal
        :open="mostrarEtiquetas"
        :codigo="String(ficha.codigo ?? '')"
        :descripcion="String(ficha.descripcion ?? '')"
        :precio="Number(ficha.precioVen1 ?? ficha.precioVenta ?? 0)"
        :empresa="puestoContexto.empresaCodigo"
        :puesto-codigo="puestoContexto.puestoCodigo"
        :puede-imprimir="puedeEtiquetasImprimir"
        @cerrar="mostrarEtiquetas = false"
        @impresa="onEtiquetaImpresa"
      />

      <ArticuloEscandalloModal
        v-if="mostrarEscandallo && ficha.codigo"
        :open="mostrarEscandallo"
        :codigo="String(ficha.codigo)"
        :descripcion="String(ficha.descripcion ?? '')"
        :readonly="!puedeEditar"
        @cerrar="mostrarEscandallo = false"
      />
    </template>
  </section>
</template>

<style scoped>
.articulos-view h2 {
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

.scan-box {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-left: 0.35rem;
  font-size: 0.75rem;
  color: #475569;
  font-weight: 600;
}

.scan-box input {
  width: 11rem;
  padding: 0.25rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  font-weight: 500;
  color: #0f172a;
}

.scan-box input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgb(59 130 246 / 20%);
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
  width: 9rem;
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
}

.ficha-body {
  display: grid;
  grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
  align-items: stretch;
  max-width: 1100px;
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

@media (max-width: 1100px) {
  .ficha-body {
    grid-template-columns: 1fr;
  }
}

@media print {
  .toolbar,
  .hint,
  .btn-volver,
  h2,
  .sticky-chrome,
  .ficha-body,
  .tabs,
  .ficha-header {
    display: none !important;
  }
}
</style>
