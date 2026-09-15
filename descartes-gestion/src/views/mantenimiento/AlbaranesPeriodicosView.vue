<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  actualizarAlbaranPeriodico,
  crearAlbaranPeriodico,
  eliminarAlbaranPeriodico,
  generarAlbaranPeriodico,
  buscarPlantillasAlbaranPeriodico,
} from '@/api/facturacion'
import { getGridColumns, type GridFila } from '@/config/entidad-grid-columns'
import { extractApiError, listarEntidadCompleta } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import { usePlantillaPeriodicaStore } from '@/stores/plantillaPeriodica'
import type { AlbaranPeriodicoListItem } from '@/types/facturacion'
import type { VentaResumen } from '@/types/ventas'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'

const MODULO = 'albaranes-periodicos'
const columns = getGridColumns('albaranes-periodicos')
const FILTER_KEYS = [
  'cliente',
  'razonSocial',
  'albaran',
  'periodicidadLabel',
  'ultimaGeneracionFmt',
  'proximaGeneracionFmt',
  'importePlantilla',
  'plantillaEstado',
]
const DATE_KEYS = ['ultimaGeneracionFmt', 'proximaGeneracionFmt']

/** Filtros de texto que se resuelven en el servidor (paginación global). */
const SERVER_SEARCH_KEYS = ['cliente', 'razonSocial', 'albaran'] as const

const PERIODICIDAD_PRESETS = [
  { value: 7, label: 'Semanal (7 días)' },
  { value: 30, label: 'Mensual' },
  { value: 60, label: 'Bimestral' },
  { value: 90, label: 'Trimestral' },
  { value: 365, label: 'Anual' },
  { value: 0, label: 'Personalizado…' },
] as const

const CLASES_PLANTILLA = [
  { value: '', label: 'Todos' },
  { value: 'presupuesto', label: 'Presupuesto' },
  { value: 'albaran', label: 'Albarán' },
] as const

const router = useRouter()
const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()
const plantillaPeriodica = usePlantillaPeriodicaStore()

const MODULO_GENERAR = 'facturacion-manual'

const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeNuevaPlantilla = computed(() => puedeCrear.value && puede('ventas', 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))
const puedeGenerar = computed(() => puede(MODULO_GENERAR, 'crear'))

const empresaCodigo = computed(() => String(puestoContexto.empresaCodigo ?? '').trim())

const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const generando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)

type GenerarFeedback = {
  generado: boolean
  motivo: string
  rutaVentas: string | null
  etiquetaAlbaran: string | null
}
const generarFeedback = ref<GenerarFeedback | null>(null)
const indiceSeleccionado = ref(0)
const busqueda = ref('')

const addOpen = ref(false)
const editOpen = ref(false)
const confirmOpen = ref(false)
const confirmMessage = ref('')
const buscarClienteOpen = ref(false)

const ventasLoading = ref(false)
const ventasBusquedaHecha = ref(false)
const ventasError = ref<string | null>(null)
const ventasResultados = ref<VentaResumen[]>([])
const ventaSeleccionada = ref<VentaResumen | null>(null)

const buscarVentas = reactive({
  cliente: '',
  claseDocumento: '',
  documento: '',
})

const addForm = reactive({
  presetPeriodicidad: 30,
  periodicidadCustom: 30,
  ultimaGeneracion: new Date().toISOString().slice(0, 10),
  marcarReferenciaPeriodico: true,
})

const editForm = reactive({
  empresa: '',
  tipo: '',
  albaran: 0,
  cliente: '',
  razonSocial: '',
  presetPeriodicidad: 30,
  periodicidadCustom: 30,
  ultimaGeneracion: '',
})

function normalizarTexto(texto: string) {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

const filas = computed(() => {
  const porColumna = aplicarFiltrosColumnas(filasTodas.value, filtros.value, {
    dateKeys: DATE_KEYS,
  }) as GridFila[]
  const q = normalizarTexto(busqueda.value.trim())
  if (!q) return porColumna
  return porColumna.filter((f) =>
    SERVER_SEARCH_KEYS.some((k) => normalizarTexto(String(f[k] ?? '')).includes(q))
  )
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const puedeAbrirPlantilla = computed(() => Boolean(filaSeleccionada.value))
const puedeMostrarEliminar = computed(
  () => puedeEliminar.value && Boolean(filaSeleccionada.value)
)
const puedeGenerarFila = computed(() => {
  const f = filaSeleccionada.value
  if (!f || !puedeGenerar.value) return false
  return f.plantillaEncontrada !== false
})
const periodicidadAdd = computed(() =>
  addForm.presetPeriodicidad === 0 ? addForm.periodicidadCustom : addForm.presetPeriodicidad
)
const periodicidadEdit = computed(() =>
  editForm.presetPeriodicidad === 0 ? editForm.periodicidadCustom : editForm.presetPeriodicidad
)

function fmtFecha(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = iso.slice(0, 10)
  const [y, m, day] = d.split('-')
  if (!y || !m || !day) return d
  return `${day}/${m}/${y}`
}

/** Misma fórmula que Gen.Alb: múltiplos de 30 suman meses; el resto, días. */
function proximaGeneracion(base: string, periodicidad: number): string {
  if (!base || periodicidad <= 0) return ''
  const [y, m, d] = base.slice(0, 10).split('-').map(Number)
  if (!y || !m || !d) return ''
  const fecha = new Date(Date.UTC(y, m - 1, d))
  if (periodicidad % 30 === 0) {
    fecha.setUTCMonth(fecha.getUTCMonth() + periodicidad / 30)
  } else {
    fecha.setUTCDate(fecha.getUTCDate() + periodicidad)
  }
  return fmtFecha(fecha.toISOString())
}

const proximaAdd = computed(() =>
  proximaGeneracion(addForm.ultimaGeneracion, periodicidadAdd.value)
)
const proximaEdit = computed(() =>
  proximaGeneracion(editForm.ultimaGeneracion, periodicidadEdit.value)
)

function fmtImporte(n: number | null | undefined): number {
  return Math.round((Number(n) || 0) * 100) / 100
}

function aFilaGrid(item: AlbaranPeriodicoListItem): GridFila {
  return {
    codigo: `${item.empresa}|${item.tipo}|${item.albaran}`,
    empresa: item.empresa,
    tipo: item.tipo,
    albaran: item.albaran,
    cliente: item.cliente,
    razonSocial: item.razonSocial,
    periodicidad: item.periodicidad,
    periodicidadLabel: item.periodicidadLabel,
    ultimaGeneracion: item.ultimaGeneracion,
    proximaGeneracion: item.proximaGeneracion,
    ultimaGeneracionFmt: fmtFecha(item.ultimaGeneracion),
    proximaGeneracionFmt: fmtFecha(item.proximaGeneracion),
    importePlantilla: fmtImporte(item.importePlantilla),
    plantillaEncontrada: item.plantillaEncontrada,
    plantillaEstado: item.plantillaEncontrada ? '' : 'No encontrada',
    referencia1: item.referencia1,
  }
}

function presetDePeriodicidad(dias: number): number {
  const preset = PERIODICIDAD_PRESETS.find((p) => p.value === dias && p.value !== 0)
  if (preset) return preset.value
  return 0
}

function exigirEmpresa(): string | null {
  const emp = empresaCodigo.value
  if (!emp) {
    const msg = 'Configure el puesto (empresa) antes de trabajar con albaranes periódicos.'
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

watch(empresaCodigo, () => {
  void cargar()
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
    const { items, total: t } = await listarEntidadCompleta('albaranes-periodicos', { empresa: emp })
    filasTodas.value = items.map(aFilaGrid)
    total.value = t
    indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Error al cargar albaranes periódicos')
    filasTodas.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}


function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function onListado() {
  window.print()
}

function abrirPlantilla(fila?: GridFila) {
  const row = fila ?? filaSeleccionada.value
  if (!row) return
  plantillaPeriodica.iniciarConsulta()
  void router.push({
    path: `/ventas/${encodeURIComponent(String(row.empresa))}/${encodeURIComponent(String(row.tipo))}/${row.albaran}`,
    query: { plantillaConsulta: '1' },
  })
}

function resetBuscarVentas() {
  buscarVentas.cliente = ''
  buscarVentas.claseDocumento = ''
  buscarVentas.documento = ''
  ventasResultados.value = []
  ventaSeleccionada.value = null
  ventasBusquedaHecha.value = false
  ventasError.value = null
}

function onNuevaPlantilla() {
  if (!puedeNuevaPlantilla.value) return
  const emp = exigirEmpresa()
  if (!emp) return
  mensaje.value = null
  plantillaPeriodica.iniciarAlta()
  void router.push({ name: 'ventas-nuevo', query: { plantillaPeriodica: '1' } })
}

function onRegistrarExistente() {
  if (!puedeCrear.value) return
  const emp = exigirEmpresa()
  if (!emp) return
  resetBuscarVentas()
  addForm.presetPeriodicidad = 30
  addForm.periodicidadCustom = 30
  addForm.ultimaGeneracion = new Date().toISOString().slice(0, 10)
  addForm.marcarReferenciaPeriodico = true
  addOpen.value = true
  mensaje.value = null
}

async function buscarDocumentosVentas() {
  const emp = exigirEmpresa()
  if (!emp) return
  ventasLoading.value = true
  ventaSeleccionada.value = null
  ventasBusquedaHecha.value = false
  ventasError.value = null
  try {
    const data = await buscarPlantillasAlbaranPeriodico({
      empresa: emp,
      cliente: buscarVentas.cliente.trim() || undefined,
      claseDocumento: buscarVentas.claseDocumento.trim() || undefined,
      documento: buscarVentas.documento.trim()
        ? Number(buscarVentas.documento)
        : undefined,
      page: 1,
      pageSize: 50,
    })
    ventasResultados.value = data.items ?? []
    ventasBusquedaHecha.value = true
  } catch (e: unknown) {
    ventasError.value = extractApiError(e, 'Error al buscar documentos')
    ventasResultados.value = []
    ventasBusquedaHecha.value = true
  } finally {
    ventasLoading.value = false
  }
}

function seleccionarVenta(v: VentaResumen) {
  ventaSeleccionada.value = v
}

function onClienteBuscar(resultado: EntidadBuscarResultado) {
  buscarVentas.cliente = resultado.codigo
  buscarClienteOpen.value = false
}

async function guardarAlta() {
  if (!puedeCrear.value) return
  const emp = exigirEmpresa()
  if (!emp) return
  if (!ventaSeleccionada.value) {
    mensaje.value = 'Seleccione un documento de venta'
    return
  }
  if (periodicidadAdd.value <= 0) {
    mensaje.value = 'La periodicidad debe ser mayor que 0'
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    await crearAlbaranPeriodico({
      empresa: ventaSeleccionada.value.empresa,
      tipo: ventaSeleccionada.value.tipo,
      albaran: ventaSeleccionada.value.albaran,
      periodicidad: periodicidadAdd.value,
      ultimaGeneracion: addForm.ultimaGeneracion,
      marcarReferenciaPeriodico: addForm.marcarReferenciaPeriodico,
    })
    addOpen.value = false
    mensaje.value = 'Base periódica registrada'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo registrar la base')
  } finally {
    saving.value = false
  }
}

function onEditar(index?: number) {
  if (!puedeEditar.value) return
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila) return
  editForm.empresa = String(fila.empresa ?? '')
  editForm.tipo = String(fila.tipo ?? '')
  editForm.albaran = Number(fila.albaran ?? 0)
  editForm.cliente = String(fila.cliente ?? '')
  editForm.razonSocial = String(fila.razonSocial ?? '')
  const dias = Number(fila.periodicidad ?? 30)
  editForm.presetPeriodicidad = presetDePeriodicidad(dias)
  editForm.periodicidadCustom = dias > 0 ? dias : 30
  editForm.ultimaGeneracion = String(fila.ultimaGeneracion ?? '').slice(0, 10)
    || new Date().toISOString().slice(0, 10)
  editOpen.value = true
  mensaje.value = null
}

async function guardarEdicion() {
  if (!puedeEditar.value) return
  if (periodicidadEdit.value <= 0) {
    mensaje.value = 'La periodicidad debe ser mayor que 0'
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    await actualizarAlbaranPeriodico(editForm.empresa, editForm.tipo, editForm.albaran, {
      periodicidad: periodicidadEdit.value,
      ultimaGeneracion: editForm.ultimaGeneracion,
    })
    editOpen.value = false
    mensaje.value = 'Base periódica actualizada'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo actualizar')
  } finally {
    saving.value = false
  }
}

function solicitarEliminar() {
  if (!puedeEliminar.value || !filaSeleccionada.value) return
  const f = filaSeleccionada.value
  confirmMessage.value = `Va a quitar la base periódica ${f.tipo}/${f.albaran} (${f.razonSocial || f.cliente}). La plantilla en Ventas no se borra.`
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const f = filaSeleccionada.value
  if (!f) return
  try {
    await eliminarAlbaranPeriodico(String(f.empresa), String(f.tipo), Number(f.albaran))
    mensaje.value = 'Base periódica eliminada'
    await cargar()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

function fechaReferenciaGeneracion(fila: GridFila): string {
  const hoy = new Date().toISOString().slice(0, 10)
  const proxima = String(fila.proximaGeneracion ?? '').slice(0, 10)
  if (!proxima) return hoy
  return proxima <= hoy ? proxima : hoy
}

function rutaVenta(empresa: string, tipo: string, albaran: number): string {
  return `/ventas/${encodeURIComponent(empresa)}/${encodeURIComponent(tipo)}/${albaran}`
}

async function generarAhora() {
  const f = filaSeleccionada.value
  if (!f || !puedeGenerarFila.value) return
  generando.value = true
  generarFeedback.value = null
  mensaje.value = null
  try {
    const fechaRef = fechaReferenciaGeneracion(f)
    const result = await generarAlbaranPeriodico(
      String(f.empresa),
      String(f.tipo),
      Number(f.albaran),
      fechaRef
    )
    if (result.generado && result.albaranGenerado) {
      const alb = result.albaranGenerado
      generarFeedback.value = {
        generado: true,
        motivo: '',
        etiquetaAlbaran: `${alb.tipo}-${alb.albaran}`,
        rutaVentas: rutaVenta(alb.empresa, alb.tipo, alb.albaran),
      }
      await cargar()
    } else {
      generarFeedback.value = {
        generado: false,
        motivo: result.motivoOmision ?? 'Nada pendiente en este periodo',
        etiquetaAlbaran: null,
        rutaVentas: null,
      }
    }
  } catch (e: unknown) {
    generarFeedback.value = {
      generado: false,
      motivo: extractApiError(e, 'No se pudo generar el albarán'),
      etiquetaAlbaran: null,
      rutaVentas: null,
    }
  } finally {
    generando.value = false
  }
}
</script>

<template>
  <section class="periodicos-view">
    <h2>Albaranes periódicos</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver albaranes periódicos.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <div
        v-if="generarFeedback"
        class="generar-feedback"
        :class="{ ok: generarFeedback.generado, warn: !generarFeedback.generado }"
        role="status"
      >
        <template v-if="generarFeedback.generado && generarFeedback.rutaVentas">
          Albarán <strong>{{ generarFeedback.etiquetaAlbaran }}</strong> generado.
          <router-link :to="generarFeedback.rutaVentas" class="link-ventas">
            Abrir en Ventas →
          </router-link>
        </template>
        <template v-else>
          {{ generarFeedback.motivo }}
        </template>
        <button type="button" class="btn-dismiss" title="Cerrar" @click="generarFeedback = null">
          ×
        </button>
      </div>

      <div class="mantenimiento-listado listado-panel">
        <div class="toolbar">
          <button type="button" class="tool-btn" @click="onListado">
            <ToolIcon name="listado" />
            <span>Listado</span>
          </button>
          <button
            v-if="puedeNuevaPlantilla"
            type="button"
            class="tool-btn primary"
            :disabled="loading"
            @click="onNuevaPlantilla"
          >
            <ToolIcon name="nuevo" />
            <span>Nueva plantilla</span>
          </button>
          <button
            v-if="puedeCrear"
            type="button"
            class="tool-btn"
            :disabled="loading"
            @click="onRegistrarExistente"
          >
            Documento existente
          </button>
          <button
            v-if="puedeEditar"
            type="button"
            class="tool-btn"
            :disabled="!filaSeleccionada"
            @click="onEditar()"
          >
            Editar
          </button>
          <button
            type="button"
            class="tool-btn"
            :disabled="!puedeAbrirPlantilla"
            @click="abrirPlantilla()"
          >
            Abrir plantilla
          </button>
          <button
            v-if="puedeGenerar"
            type="button"
            class="tool-btn primary"
            :disabled="!puedeGenerarFila || generando || loading"
            @click="generarAhora()"
          >
            Generar ahora
          </button>
          <div class="toolbar-spacer"></div>
          <label class="buscar-inline">
            <span>Buscar</span>
            <input
              v-model="busqueda"
              type="search"
              placeholder="Cliente, razón social o nº"
            />
          </label>
          <button type="button" class="tool-btn" :disabled="loading" @click="cargar()">
            Actualizar
          </button>
          <button
            v-if="puedeEliminar"
            type="button"
            class="tool-btn danger"
            :disabled="!puedeMostrarEliminar || loading"
            @click="solicitarEliminar"
          >
            <ToolIcon name="borrar" />
            <span>Quitar</span>
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
          v-model:filters="filtros"
          @seleccionar="seleccionar"
          @abrir="onEditar"
        />

        <p class="hint">
          Tienda activa: <strong>{{ empresaCodigo || '—' }}</strong>.
          Escriba bajo cada columna o en Buscar para filtrar. Doble clic abre edición.
          Gen.Alb por lotes sigue en Facturación → Generador manual.
        </p>
      </div>

      <Teleport to="body">
        <div v-if="addOpen" class="modal-overlay" @click.self="addOpen = false">
          <div class="modal-panel modal-wide" role="dialog" aria-labelledby="add-title">
            <h3 id="add-title">Registrar documento existente</h3>
            <p class="hint modal-intro">
              Busque un presupuesto o albarán ya creado en Ventas y marque su periodicidad.
            </p>

            <fieldset class="bloque">
              <legend>Buscar documento en Ventas</legend>
              <div class="filtros-ventas">
                <label>
                  Cliente
                  <div class="codigo-buscar">
                    <input v-model="buscarVentas.cliente" maxlength="15" />
                    <button type="button" class="btn-lupa" title="Buscar cliente" @click="buscarClienteOpen = true">
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                </label>
                <label>
                  Tipo
                  <select v-model="buscarVentas.claseDocumento">
                    <option v-for="opt in CLASES_PLANTILLA" :key="opt.value" :value="opt.value">
                      {{ opt.label }}
                    </option>
                  </select>
                </label>
                <label>
                  Nº documento
                  <input v-model="buscarVentas.documento" inputmode="numeric" />
                </label>
                <button type="button" class="tool-btn primary" @click="buscarDocumentosVentas">
                  {{ ventasLoading ? 'Buscando…' : 'Buscar' }}
                </button>
              </div>

              <p v-if="ventasError" class="error modal-error">{{ ventasError }}</p>
              <div v-if="ventasLoading" class="hint">Buscando…</div>
              <table v-else-if="ventasResultados.length" class="ventas-tabla">
                <thead>
                  <tr>
                    <th></th>
                    <th>Tipo</th>
                    <th>Nº</th>
                    <th>Fecha</th>
                    <th>Cliente</th>
                    <th>Importe</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="v in ventasResultados"
                    :key="`${v.empresa}-${v.tipo}-${v.albaran}`"
                    :class="{ selected: ventaSeleccionada?.albaran === v.albaran && ventaSeleccionada?.tipo === v.tipo }"
                    @click="seleccionarVenta(v)"
                  >
                    <td>
                      <input
                        type="radio"
                        name="ventaSel"
                        :checked="ventaSeleccionada?.albaran === v.albaran && ventaSeleccionada?.tipo === v.tipo"
                        @change="seleccionarVenta(v)"
                      />
                    </td>
                    <td>{{ v.tipo }}</td>
                    <td>{{ v.albaran }}</td>
                    <td>{{ fmtFecha(v.fecha) }}</td>
                    <td>{{ v.cliente }} — {{ v.razonSocial }}</td>
                    <td class="num">{{ fmtImporte(v.importe).toFixed(2) }}</td>
                  </tr>
                </tbody>
              </table>
              <p v-else-if="ventasBusquedaHecha" class="hint">No se encontraron documentos con esos filtros.</p>
              <p v-else class="hint">Indique filtros y pulse Buscar.</p>
            </fieldset>

            <fieldset class="bloque">
              <legend>Periodicidad</legend>
              <label>
                Preset
                <select v-model.number="addForm.presetPeriodicidad">
                  <option v-for="p in PERIODICIDAD_PRESETS" :key="p.value" :value="p.value">
                    {{ p.label }}
                  </option>
                </select>
              </label>
              <label v-if="addForm.presetPeriodicidad === 0">
                Días
                <input v-model.number="addForm.periodicidadCustom" type="number" min="1" />
              </label>
              <label>
                Fecha base (última generación)
                <input v-model="addForm.ultimaGeneracion" type="date" />
                <small class="ayuda">
                  Es el inicio del periodo ya generado. El primer albarán saldrá con fecha
                  <strong>{{ proximaAdd || '—' }}</strong>; si lo quiere para hoy, ponga la fecha
                  base un periodo antes.
                </small>
              </label>
              <label class="check">
                <input v-model="addForm.marcarReferenciaPeriodico" type="checkbox" />
                Marcar Referencia1 = PERIODICO si está vacía (compat. legacy)
              </label>
            </fieldset>

            <div class="modal-actions">
              <button type="button" class="tool-btn" @click="addOpen = false">Cancelar</button>
              <button type="button" class="tool-btn primary" :disabled="saving" @click="guardarAlta">
                Guardar
              </button>
            </div>
          </div>
        </div>

        <div v-if="editOpen" class="modal-overlay" @click.self="editOpen = false">
          <div class="modal-panel" role="dialog" aria-labelledby="edit-title">
            <h3 id="edit-title">Editar base periódica</h3>
            <p class="doc-ref">
              {{ editForm.tipo }}/{{ editForm.albaran }} — {{ editForm.cliente }}
              {{ editForm.razonSocial }}
            </p>

            <label>
              Preset periodicidad
              <select v-model.number="editForm.presetPeriodicidad">
                <option v-for="p in PERIODICIDAD_PRESETS" :key="p.value" :value="p.value">
                  {{ p.label }}
                </option>
              </select>
            </label>
            <label v-if="editForm.presetPeriodicidad === 0">
              Días
              <input v-model.number="editForm.periodicidadCustom" type="number" min="1" />
            </label>
            <label>
              Fecha base (última generación)
              <input v-model="editForm.ultimaGeneracion" type="date" />
              <small class="ayuda">
                Próxima generación: <strong>{{ proximaEdit || '—' }}</strong>
              </small>
            </label>

            <div class="modal-actions">
              <button type="button" class="tool-btn" @click="editOpen = false">Cancelar</button>
              <button type="button" class="tool-btn primary" :disabled="saving" @click="guardarEdicion">
                Guardar
              </button>
            </div>
          </div>
        </div>
      </Teleport>

      <EntidadBuscarModal
        :open="buscarClienteOpen"
        entidad="clientes"
        titulo="Buscar cliente"
        @seleccionar="onClienteBuscar"
        @cerrar="buscarClienteOpen = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Quitar base periódica"
        :message="confirmMessage"
        confirm-label="Quitar"
        @confirm="confirmarEliminar"
        @cancel="confirmOpen = false"
      />
    </template>
  </section>
</template>

<style scoped>
.periodicos-view h2 {
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

.buscar-inline {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
}

.buscar-inline input {
  width: 12rem;
  padding: 0.25rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
}

.tool-btn {
  padding: 0.35rem 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 8px;
  background: #fff;
  font-size: 0.8rem;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
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

.msg {
  color: #047857;
}

.error {
  color: #b91c1c;
}

.generar-feedback {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
  margin-bottom: 0.5rem;
  padding: 0.55rem 0.75rem;
  border-radius: 8px;
  font-size: 0.85rem;
}

.generar-feedback.ok {
  background: #ecfdf5;
  border: 1px solid #6ee7b7;
  color: #065f46;
}

.generar-feedback.warn {
  background: #fffbeb;
  border: 1px solid #fcd34d;
  color: #92400e;
}

.link-ventas {
  font-weight: 600;
  color: #1d4ed8;
  text-decoration: none;
}

.link-ventas:hover {
  text-decoration: underline;
}

.btn-dismiss {
  margin-left: auto;
  border: none;
  background: transparent;
  font-size: 1.1rem;
  line-height: 1;
  cursor: pointer;
  color: inherit;
  opacity: 0.7;
}

.btn-dismiss:hover {
  opacity: 1;
}

.modal-error {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.hint {
  margin: 0.5rem 0 0;
  font-size: 0.8rem;
  color: #64748b;
  max-width: 52rem;
}

.modal-overlay {
  position: fixed;
  inset: 0;
  z-index: 2000;
  background: rgba(15, 23, 42, 0.45);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.modal-panel {
  background: #fff;
  border-radius: 10px;
  border: 1px solid #cbd5e1;
  padding: 1rem 1.1rem;
  width: min(28rem, 100%);
  max-height: 90vh;
  overflow: auto;
  display: grid;
  gap: 0.65rem;
}

.modal-panel.modal-wide {
  width: min(52rem, 100%);
}

.modal-panel h3 {
  margin: 0;
  font-size: 1rem;
}

.doc-ref {
  margin: 0;
  font-size: 0.85rem;
  color: #475569;
}

.modal-panel label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.8rem;
}

.modal-panel input,
.modal-panel select {
  padding: 0.3rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font-size: 0.85rem;
}

.bloque {
  margin: 0;
  padding: 0.55rem;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.bloque legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.filtros-ventas {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: end;
  margin-bottom: 0.5rem;
}

.codigo-buscar {
  display: flex;
  gap: 0.25rem;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
}

.ventas-tabla {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.78rem;
}

.ventas-tabla th,
.ventas-tabla td {
  border: 1px solid #e2e8f0;
  padding: 0.25rem 0.35rem;
}

.ventas-tabla tr.selected {
  background: #dbeafe;
}

.ventas-tabla .num {
  text-align: right;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.ayuda {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.75rem;
  color: #475569;
  font-weight: 400;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 0.25rem;
}

@media print {
  .toolbar,
  .hint,
  h2 {
    display: none !important;
  }
}
</style>
