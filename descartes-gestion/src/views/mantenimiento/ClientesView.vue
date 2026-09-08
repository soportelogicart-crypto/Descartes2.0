<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
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
  CLIENTE_CAMPOS_OBLIGATORIOS,
  camposClienteObligatoriosVacios,
  clienteTabs,
  clienteVacio,
  fechaParaInput,
  normalizarIbanCliente,
  tabDeCampoCliente,
  validarClienteObligatorios,
  validarUsoCliente,
} from '@/config/clientes-tabs'
import { extractApiError, useMantenimiento } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { useEliminarFilaGrid } from '@/composables/useEliminarFilaGrid'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import ListPagination from '@/components/common/ListPagination.vue'
import EntidadGrid, { type GridOptionsMap } from '@/components/mantenimiento/EntidadGrid.vue'
import ClienteToolbar from '@/components/clientes/ClienteToolbar.vue'
import ClienteTabForm from '@/components/clientes/ClienteTabForm.vue'
import ClienteInteresesModal from '@/components/clientes/ClienteInteresesModal.vue'
import ClienteDireccionModal from '@/components/clientes/ClienteDireccionModal.vue'
import ClienteContactosModal from '@/components/clientes/ClienteContactosModal.vue'
import ClienteEstadisticaModal from '@/components/clientes/ClienteEstadisticaModal.vue'
import ClienteConsumoModal from '@/components/clientes/ClienteConsumoModal.vue'
import { usePuestoContextoStore } from '@/stores/puestoContexto'

const MODULO = 'clientes'
const FILTER_KEYS = ['codigo', 'tiendaCodigo', 'nombre', 'nif', 'telefono1']
const SERVER_SEARCH_KEYS = ['nombre', 'codigo', 'nif', 'telefono1']
const columns = getGridColumns('clientes')

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
const optionsMap = ref<GridOptionsMap>({})

const tabActiva = ref(clienteTabs[0].id)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const codigoAutomatico = ref(false)
const ficha = ref<Record<string, unknown>>({})
const indiceFicha = ref(-1)
const mostrarIntereses = ref(false)
const mostrarDireccion = ref(false)
const mostrarContactos = ref(false)
const mostrarEstadistica = ref(false)
const mostrarConsumo = ref(false)
const motorFidelizacion = ref<'NINGUNO' | 'EUROS' | 'PUNTOS'>('NINGUNO')
const avisoModalOpen = ref(false)
const avisoModalTitulo = ref('Campo obligatorio')
const avisoModalMensaje = ref('')
const campoAvisoActual = ref<string | null>(null)
const camposInvalidos = ref<string[]>([])
const codigoInput = ref<HTMLInputElement | null>(null)

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
  tabActiva.value = tabDeCampoCliente(key)
  await nextTick()
  await nextTick()
  if (key === 'nombre') {
    document.querySelector<HTMLInputElement>('[data-field-key="nombre"]')?.focus()
    return
  }
  document.querySelector<HTMLElement>(`[data-field-key="${key}"]`)?.focus()
}

watch(
  ficha,
  () => {
    if (camposInvalidos.value.length === 0) return
    const pendientes = camposClienteObligatoriosVacios(ficha.value)
    camposInvalidos.value = camposInvalidos.value.filter((k) => pendientes.includes(k))
  },
  { deep: true }
)

function actualizarFicha(next: Record<string, unknown>) {
  ficha.value = next
}

let motorFidelizacionSeq = 0

async function resolverMotorFidelizacion() {
  const seq = ++motorFidelizacionSeq
  const tienda = String(
    ficha.value.tiendaCodigo || puestoContexto.empresaCodigo || ''
  ).trim()
  if (!tienda) {
    motorFidelizacion.value = 'NINGUNO'
    return
  }
  try {
    const { data } = await api.get(
      `/api/mantenimiento/tiendas/${encodeURIComponent(tienda)}`
    )
    if (seq !== motorFidelizacionSeq) return
    const motor = String(data.motorFidelizacion ?? 'NINGUNO').trim().toUpperCase()
    motorFidelizacion.value =
      motor === 'EUROS' || motor === 'PUNTOS' ? motor : 'NINGUNO'
  } catch {
    if (seq === motorFidelizacionSeq) motorFidelizacion.value = 'NINGUNO'
  }
}

watch(
  () => String(ficha.value.tiendaCodigo ?? ''),
  () => {
    void resolverMotorFidelizacion()
  }
)

let nifCheckSeq = 0
let ultimoNifAvisado = ''

async function onBlurCampo(key: string, value: string) {
  if (key !== 'nif' || soloLecturaFicha.value) return
  const nif = String(value ?? '').trim()
  if (!nif) {
    ultimoNifAvisado = ''
    return
  }
  // Evitar reavisar el mismo NIF tras cerrar el modal (refocus).
  if (nif === ultimoNifAvisado) return

  const excluir = esNuevo.value ? '' : String(ficha.value.codigo ?? '').trim()
  const seq = ++nifCheckSeq
  try {
    const { data } = await api.get('/api/mantenimiento/clientes/check-nif', {
      params: { nif, ...(excluir ? { excluir } : {}) },
    })
    if (seq !== nifCheckSeq) return
    if (!data?.duplicado) {
      ultimoNifAvisado = ''
      return
    }
    const codigoOtro = data.codigo ? String(data.codigo).trim() : ''
    const msg = codigoOtro
      ? `Ya existe un cliente activo con ese NIF (codigo ${codigoOtro}).`
      : 'Ya existe un cliente activo con ese NIF.'
    ultimoNifAvisado = nif
    mensaje.value = msg
    tabActiva.value = 'generales'
    await nextTick()
    mostrarAvisoModal('NIF duplicado', msg, 'nif')
  } catch {
    // Silencioso en blur; al guardar la API vuelve a validar.
  }
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

const tabSeleccionada = computed(() => clienteTabs.find((t) => t.id === tabActiva.value) ?? clienteTabs[0])
const soloLecturaFicha = computed(() => !modoEdicion.value && !esNuevo.value)
const codigoReadOnlyFicha = computed(() => !esNuevo.value || codigoAutomatico.value)
const totalFicha = computed(() => filasTodas.value.filter((f) => !f._nuevo).length)
const hayCliente = computed(() => Boolean(ficha.value.codigo) || esNuevo.value)

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
  etiquetaEntidad: 'el cliente',
  mensajeExito: 'Cliente eliminado',
})

onMounted(async () => {
  if (!puedeVer.value) return
  await cargarTiendas()
  await cargar()
})

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { activo: true, pageSize: 200 } })
    const tiendas = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
    optionsMap.value = { tiendas }
  } catch {
    optionsMap.value = { tiendas: [] }
  }
}

async function cargar(opts?: { silent?: boolean }) {
  mensaje.value = null
  const q = textoBusquedaServidor()
  ultimaQServidor = q
  const seq = ++cargaSeq
  await listar({ page: page.value, pageSize: pageSize.value, ...(q ? { q } : {}) }, { silent: opts?.silent === true })
  if (seq !== cargaSeq) return
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

function textoBusquedaServidor(): string {
  const opsConBusqueda = new Set(['contiene', 'comienza', 'finaliza', 'igual'])
  for (const key of SERVER_SEARCH_KEYS) {
    const f = filtros.value[key]
    if (!f || !opsConBusqueda.has(f.operador)) continue
    const v = String(f.valor ?? '').trim()
    if (v) return v
  }
  return ''
}

let ultimaQServidor = ''
let cargaSeq = 0
let debounceFiltros: ReturnType<typeof setTimeout> | null = null

function buscarServidorAhora() {
  if (debounceFiltros) clearTimeout(debounceFiltros)
  page.value = 1
  void cargar({ silent: true })
}

watch(
  () =>
    SERVER_SEARCH_KEYS.map((k) => {
      const f = filtros.value[k]
      return `${f?.operador ?? ''}|${f?.valor ?? ''}`
    }).join('||'),
  () => {
    if (debounceFiltros) clearTimeout(debounceFiltros)
    debounceFiltros = setTimeout(() => {
      const q = textoBusquedaServidor()
      if (q === ultimaQServidor) return
      page.value = 1
      void cargar({ silent: true })
    }, 500)
  }
)

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function actualizarFila(_index: number, fila: GridFila) {
  const codigo = String(fila.codigo ?? '')
  filasTodas.value = filasTodas.value.map((f) =>
    String(f.codigo) === codigo ? { ...fila, _nuevo: false } : f
  )
}

function onListado() {
  window.print()
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
    mensaje.value = 'Cliente actualizado'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar el cliente')
  }
}

async function abrirFichaPorCodigo(codigo: string) {
  try {
    const data = await obtener(codigo)
    if (data.tiendaCodigo != null) {
      data.tiendaCodigo = String(data.tiendaCodigo).trim()
    }
    // Legacy Mid("NIEP"): Canarias = P. Corregir valores antiguos guardados como C.
    if (String(data.tratamientoFiscal ?? '').trim().toUpperCase() === 'C') {
      data.tratamientoFiscal = 'P'
    }
    if (data.formaPago != null) {
      data.formaPago = String(data.formaPago).trim()
    }
    ficha.value = data
    indiceFicha.value = filasTodas.value.findIndex((f) => String(f.codigo) === codigo)
    modoEdicion.value = false
    esNuevo.value = false
    tabActiva.value = 'generales'
    vista.value = 'ficha'
    mensaje.value = null
    camposInvalidos.value = []
    codigoAutomatico.value = false
    ultimoNifAvisado = ''
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || fila.codigo == null || fila.codigo === '') {
    mensaje.value = 'Seleccione un cliente existente'
    return
  }
  await abrirFichaPorCodigo(String(fila.codigo))
}

async function onNuevo() {
  if (!puedeCrear.value) return
  const base = {
    ...clienteVacio(),
    tiendaCodigo: puestoContexto.empresaCodigo || '',
  }
  codigoAutomatico.value = false
  // Legacy PreAlta: FormaPago = Divisa de la empresa/tienda
  const tienda = String(puestoContexto.empresaCodigo || '').trim()
  if (tienda) {
    try {
      const { data } = await api.get(`/api/mantenimiento/tiendas/${encodeURIComponent(tienda)}`)
      const divisa = String(data.divisa ?? '').trim()
      if (divisa) base.formaPago = divisa
    } catch {
      /* sin default */
    }
  }
  // Si la tienda tiene GenClientes, Prefijo + UltCliente+1 (ej. 001012100).
  try {
    const { data } = await api.get('/api/mantenimiento/clientes/siguiente-codigo', {
      params: { empresa: puestoContexto.empresaCodigo || undefined },
    })
    if (data.automatico && data.codigo) {
      base.codigo = String(data.codigo)
      codigoAutomatico.value = true
    }
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo obtener el siguiente codigo')
  }
  ficha.value = base
  esNuevo.value = true
  modoEdicion.value = true
  indiceFicha.value = -1
  tabActiva.value = 'generales'
  vista.value = 'ficha'
  mensaje.value = null
  camposInvalidos.value = []
  ultimoNifAvisado = ''
  await nextTick()
  await nextTick()
  if (codigoAutomatico.value) {
    document.querySelector<HTMLElement>('[data-field-key="nombre"]')?.focus()
  } else {
    codigoInput.value?.focus()
    codigoInput.value?.select()
  }
}

function onModificar() {
  if (!puedeEditar.value || !ficha.value.codigo) return
  modoEdicion.value = true
}

function normalizarFechasFicha(data: Record<string, unknown>): Record<string, unknown> {
  const dateKeys = [
    'fechaAlta',
    'ultimaCompra',
    'fechaNacimiento',
    'fechaFirmaMandato',
    'fechaCaducidadCarnet',
  ]
  const next = { ...data }
  for (const key of dateKeys) {
    if (!(key in next)) continue
    const normalized = fechaParaInput(next[key])
    next[key] = normalized === '' ? null : normalized
  }
  return next
}

async function onGuardarFicha() {
  const vacios = camposClienteObligatoriosVacios(ficha.value)
  if (vacios.length > 0) {
    const key = vacios[0]
    const msg =
      validarClienteObligatorios(ficha.value) ??
      CLIENTE_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.mensaje ??
      `El campo "${key}" es obligatorio.`
    mensaje.value = msg
    tabActiva.value = tabDeCampoCliente(key)
    await nextTick()
    mostrarAvisoModal('Campo obligatorio', msg, key)
    return
  }

  const validacionUso = validarUsoCliente(ficha.value)
  if (validacionUso) {
    mensaje.value = validacionUso.mensaje
    tabActiva.value = tabDeCampoCliente(validacionUso.campo)
    await nextTick()
    mostrarAvisoModal(validacionUso.titulo, validacionUso.mensaje, validacionUso.campo)
    return
  }

  camposInvalidos.value = []
  // Asegurar copia fiscal → envio al guardar (por si envio quedo vacio).
  const payload = normalizarFechasFicha({
    ...ficha.value,
    iban: normalizarIbanCliente(ficha.value.iban),
    direccionEnvio:
      String(ficha.value.direccionEnvio ?? '').trim() || String(ficha.value.direccion ?? '').trim(),
    codigoPostalEnvio:
      String(ficha.value.codigoPostalEnvio ?? '').trim() ||
      String(ficha.value.codigoPostal ?? '').trim(),
    poblacionEnvio:
      String(ficha.value.poblacionEnvio ?? '').trim() || String(ficha.value.poblacion ?? '').trim(),
    provinciaEnvio:
      String(ficha.value.provinciaEnvio ?? '').trim() || String(ficha.value.provincia ?? '').trim(),
    paisEnvio:
      String(ficha.value.paisEnvio ?? '').trim() || String(ficha.value.pais ?? '').trim() || 'España',
  })
  try {
    if (esNuevo.value) {
      const creado = await crear(payload)
      mensaje.value = 'Cliente creado correctamente'
      await cargar()
      const idx = filas.value.findIndex((c) => String(c.codigo) === String(creado.codigo))
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      const codigo = String(payload.codigo ?? '').trim()
      await actualizar(codigo, { ...payload, codigo })
      mensaje.value = 'Cliente actualizado'
      await cargar()
      await abrirFichaPorCodigo(codigo)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    const msg = extractApiError(e, 'No se pudo guardar el cliente')
    mensaje.value = msg
    if (/ya existe un cliente activo con ese nif|nif duplicad/i.test(msg)) {
      tabActiva.value = 'generales'
      await nextTick()
      mostrarAvisoModal('NIF duplicado', msg, 'nif')
    } else if (/nif es obligatorio/i.test(msg)) {
      tabActiva.value = 'generales'
      await nextTick()
      mostrarAvisoModal('Campo obligatorio', msg, 'nif')
    } else if (/razon social/i.test(msg)) {
      tabActiva.value = 'generales'
      await nextTick()
      mostrarAvisoModal('Campo obligatorio', msg, 'nombre')
    } else if (/forma de pago/i.test(msg)) {
      tabActiva.value = 'facturacion'
      await nextTick()
      mostrarAvisoModal('Campo obligatorio', msg, 'formaPago')
    } else if (/ya existe|duplicad|codigo/i.test(msg)) {
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
  if (ficha.value.codigo) abrirFichaPorCodigo(String(ficha.value.codigo))
}

async function onBorrarFicha() {
  if (!puedeEliminar.value || esNuevo.value || !ficha.value.codigo) return
  if (!confirm('Dar de baja este cliente?')) return
  try {
    await eliminar(String(ficha.value.codigo))
    mensaje.value = 'Cliente dado de baja'
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
  codigoAutomatico.value = false
  ficha.value = {}
  mostrarIntereses.value = false
  mostrarEstadistica.value = false
  mostrarConsumo.value = false
  camposInvalidos.value = []
}

function onEstadistica() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el cliente antes de consultar la estadistica'
    return
  }
  mostrarEstadistica.value = true
}

function onConsumo() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el cliente antes de consultar el consumo'
    return
  }
  mostrarConsumo.value = true
}

function onIntereses() {
  if (!hayCliente.value) return
  mostrarIntereses.value = true
}

function onDireccion() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el cliente antes de gestionar direcciones'
    return
  }
  mostrarDireccion.value = true
}

function onContactos() {
  if (!String(ficha.value.codigo ?? '').trim()) {
    mensaje.value = 'Guarde el cliente antes de gestionar contactos'
    return
  }
  mostrarContactos.value = true
}

function onInteresesUpdate(value: string) {
  ficha.value = { ...ficha.value, interesesComerciales: value }
  if (!modoEdicion.value && !esNuevo.value && ficha.value.codigo && puedeEditar.value) {
    actualizar(String(ficha.value.codigo), { interesesComerciales: value })
      .then(() => {
        mensaje.value = 'Intereses actualizados'
      })
      .catch((e: unknown) => {
        mensaje.value = extractApiError(e, 'No se pudieron guardar los intereses')
      })
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
</script>

<template>
  <section class="clientes-view">
    <h2>Clientes</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver clientes.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="mantenimiento-listado">
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
          :options-map="optionsMap"
          :filterable-keys="FILTER_KEYS"
          v-model:filters="filtros"
          :readonly="soloLecturaGrid"
          :loading="loading"
          @seleccionar="seleccionar"
          @actualizar="actualizarFila"
          @abrir="abrirFicha"
          @nuevo="onNuevo"
          @search="buscarServidorAhora"
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
          Filtra por <strong>Codigo</strong>, <strong>Tienda</strong>, <strong>Razon social</strong>,
          <strong>NIF</strong> y <strong>Telefono</strong> con el embudo. Doble clic o <strong>Ficha</strong> abre el
          detalle.
        </p>
        </div>
      </template>

      <template v-else>
        <div class="sticky-chrome">
          <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>

          <ClienteToolbar
            :puede-crear="puedeCrear"
            :puede-editar="puedeEditar"
            :puede-eliminar="puedeEliminar"
            :puede-guardar="puedeCrear || puedeEditar"
            :modo-edicion="modoEdicion || esNuevo"
            :indice="indiceFicha < 0 ? undefined : indiceFicha"
            :total="totalFicha"
            :loading="loading"
            :hay-cliente="hayCliente"
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
            @consumo="onConsumo"
            @contactos="onContactos"
            @intereses="onIntereses"
            @direccion="onDireccion"
          />

          <div class="ficha-header">
            <label :class="{ 'campo-invalido': camposInvalidos.includes('codigo') }">
              Codigo *
              <input
                ref="codigoInput"
                v-model="ficha.codigo"
                data-field-key="codigo"
                :readonly="codigoReadOnlyFicha"
                maxlength="9"
                class="codigo-input"
              />
            </label>
            <label
              class="nombre-input"
              :class="{ 'campo-invalido': camposInvalidos.includes('nombre') }"
            >
              Razon social *
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
              v-for="tab in clienteTabs"
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

        <ClienteTabForm
          :sections="tabSeleccionada.sections"
          :model-value="ficha"
          :motor-fidelizacion="motorFidelizacion"
          :readonly="soloLecturaFicha"
          :codigo-read-only="codigoReadOnlyFicha"
          :ocultar-cabecera="true"
          :campos-invalidos="camposInvalidos"
          @update:model-value="actualizarFicha"
          @blur-field="onBlurCampo"
        />
      </template>

      <ClienteInteresesModal
        :open="mostrarIntereses"
        :model-value="String(ficha.interesesComerciales ?? '')"
        :readonly="soloLecturaFicha"
        @update:model-value="onInteresesUpdate"
        @cerrar="mostrarIntereses = false"
      />

      <ClienteDireccionModal
        :open="mostrarDireccion"
        :cliente-codigo="String(ficha.codigo ?? '')"
        :puede-editar="puedeEditar || puedeCrear"
        @cerrar="mostrarDireccion = false"
      />

      <ClienteContactosModal
        :open="mostrarContactos"
        :cliente-codigo="String(ficha.codigo ?? '')"
        :puede-editar="puedeEditar || puedeCrear"
        @cerrar="mostrarContactos = false"
      />

      <ClienteEstadisticaModal
        :open="mostrarEstadistica"
        :cliente-codigo="String(ficha.codigo ?? '')"
        :cliente-nombre="String(ficha.nombre ?? '')"
        :puede-editar="puedeEditar"
        @cerrar="mostrarEstadistica = false"
      />

      <ClienteConsumoModal
        :open="mostrarConsumo"
        :cliente-codigo="String(ficha.codigo ?? '')"
        :cliente-nombre="String(ficha.nombre ?? '')"
        @cerrar="mostrarConsumo = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar cliente"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="cancelarEliminar"
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
.clientes-view h2 {
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
  width: 8rem;
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
    display: none;
  }
}
</style>
