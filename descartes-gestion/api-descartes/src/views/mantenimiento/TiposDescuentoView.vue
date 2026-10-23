<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { getGridColumns, type GridFila } from '@/config/entidad-grid-columns'
import type { EntidadLookupId } from '@/config/entidad-lookup'
import { extractApiError, listarEntidadCompleta } from '@/composables/useMantenimiento'
import { useMantenimientoServerSearch } from '@/composables/useMantenimientoServerSearch'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  normalizarFechaSolo,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadLookupField from '@/components/common/EntidadLookupField.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'

const MODULO = 'tipos-descuento'
const columns = getGridColumns('tipos-descuento')
const FILTER_KEYS = ['tipoDescuento', 'tipo', 'codigoTipo', 'fechaInicio', 'fechaFin', 'articuloRegalo']
const DATE_KEYS = ['fechaInicio', 'fechaFin']
const SERVER_SEARCH_KEYS = ['tipoDescuento', 'codigoTipo'] as const
const MAX_TIENDAS = 10

type TipoMeta = {
  value: string
  label: string
  entidad: EntidadLookupId | null
}

const TIPOS: TipoMeta[] = [
  { value: '.', label: 'General', entidad: null },
  { value: 'F', label: 'Familia', entidad: 'familias' },
  { value: 'A', label: 'Articulo', entidad: 'articulos' },
  { value: 'S', label: 'Subfamilia', entidad: 'subfamilias' },
  { value: 'T', label: 'Seccion', entidad: 'secciones' },
  { value: 'C', label: 'Cliente', entidad: 'clientes' },
  { value: 'P', label: 'Proveedor', entidad: 'proveedores' },
  { value: 'I', label: 'Subseccion', entidad: 'subsecciones' },
  { value: 'M', label: 'Macrofamilia', entidad: 'macrofamilias' },
]

type Clave = {
  tipoDescuento: string
  tipo: string
  codigo: string
  fechaInicio: string
}

type TipoDescuentoItem = Clave & {
  fechaFin: string | null
  descuento: number
  articuloRegalo: string | null
  cantidadRegalo: number
  importeMinimo: number
  importeMaximo: number
  controlStock: boolean
  bloquearTiendas: string[]
}

type Formulario = {
  tipoDescuento: string
  tipo: string
  codigo: string
  fechaInicio: string
  fechaFin: string
  descuento: number | null
  articuloRegalo: string
  cantidadRegalo: number | null
  importeMinimo: number | null
  importeMaximo: number | null
  controlStock: boolean
  tiendas: string[]
}

const { puede } = usePermisos()
const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const itemsTodos = ref<TipoDescuentoItem[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const { serverQuery, onServerSearch } = useMantenimientoServerSearch({
  filters: filtros,
  serverKeys: SERVER_SEARCH_KEYS,
  reload: cargar,
  cancel: cancelarListado,
})
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const indiceSeleccionado = ref(0)
const esNuevo = ref(false)
const modoEdicion = ref(false)
const claveOriginal = ref<Clave | null>(null)
const form = reactive<Formulario>(formularioVacio())

const confirmOpen = ref(false)
const confirmMessage = ref('')
let listadoController: AbortController | null = null

const filasTodas = computed<GridFila[]>(() => itemsTodos.value.map(aFilaGrid))

const filas = computed<GridFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value, {
    dateKeys: DATE_KEYS,
  }) as GridFila[]
  if (!puedeCrear.value) return filtradas
  return [...filtradas, filaNueva()]
})

watch(filas, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => filas.value[indiceSeleccionado.value] ?? null)
const soloLectura = computed(() => !modoEdicion.value && !esNuevo.value)
const puedeGuardar = computed(
  () => (esNuevo.value ? puedeCrear.value : puedeEditar.value) && (modoEdicion.value || esNuevo.value)
)
const puedeAbrirFicha = computed(
  () => Boolean(filaSeleccionada.value) && !filaSeleccionada.value?._nuevo
)
const puedeMostrarEliminar = computed(() => puedeEliminar.value && puedeAbrirFicha.value)

const metaTipo = computed(() => TIPOS.find((t) => t.value === form.tipo) ?? null)
const entidadCodigo = computed(() => metaTipo.value?.entidad ?? null)

function formularioVacio(): Formulario {
  return {
    tipoDescuento: '',
    tipo: '.',
    codigo: '',
    fechaInicio: '',
    fechaFin: '',
    descuento: 0,
    articuloRegalo: '',
    cantidadRegalo: 0,
    importeMinimo: 0,
    importeMaximo: 0,
    controlStock: false,
    tiendas: [],
  }
}

function filaNueva(): GridFila {
  const fila: GridFila = { _nuevo: true, codigo: '' }
  for (const col of columns) fila[col.key] = col.type === 'number' ? 0 : ''
  return fila
}

function claveTexto(clave: Clave): string {
  return `${clave.tipoDescuento}|${clave.tipo}|${clave.codigo}|${clave.fechaInicio}`
}

function fechaGrid(value: string | null | undefined): string {
  if (!value) return ''
  return normalizarFechaSolo(value) || String(value).slice(0, 10)
}

function etiquetaTipo(tipo: string): string {
  const meta = TIPOS.find((t) => t.value === tipo)
  return meta ? `${tipo} ${meta.label}` : tipo
}

function aFilaGrid(item: TipoDescuentoItem, indice: number): GridFila {
  return {
    codigo: claveTexto(item),
    _indice: indice,
    tipoDescuento: item.tipoDescuento,
    tipo: etiquetaTipo(item.tipo),
    codigoTipo: item.tipo === '.' ? '' : item.codigo,
    fechaInicio: fechaGrid(item.fechaInicio),
    fechaFin: fechaGrid(item.fechaFin),
    descuento: item.descuento,
    importeMinimo: item.importeMinimo,
    importeMaximo: item.importeMaximo,
    articuloRegalo: item.articuloRegalo ?? '',
  }
}

function itemDeFila(fila: GridFila | null | undefined): TipoDescuentoItem | null {
  if (!fila || fila._nuevo) return null
  const indice = Number(fila._indice)
  return Number.isInteger(indice) ? (itemsTodos.value[indice] ?? null) : null
}

function aplicarForm(item: TipoDescuentoItem) {
  Object.assign(form, formularioVacio(), {
    tipoDescuento: item.tipoDescuento,
    tipo: item.tipo,
    codigo: item.tipo === '.' ? '' : item.codigo,
    fechaInicio: fechaGrid(item.fechaInicio),
    fechaFin: fechaGrid(item.fechaFin),
    descuento: item.descuento,
    articuloRegalo: item.articuloRegalo ?? '',
    cantidadRegalo: item.cantidadRegalo,
    importeMinimo: item.importeMinimo,
    importeMaximo: item.importeMaximo,
    controlStock: item.controlStock,
    tiendas: [...item.bloquearTiendas],
  })
  claveOriginal.value = {
    tipoDescuento: item.tipoDescuento,
    tipo: item.tipo,
    codigo: item.codigo,
    fechaInicio: item.fechaInicio,
  }
}

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

function cancelarListado() {
  listadoController?.abort()
  listadoController = null
  loading.value = false
}

async function cargar(q = serverQuery.value) {
  cancelarListado()
  const controller = new AbortController()
  listadoController = controller
  loading.value = true
  error.value = null
  try {
    const params: Record<string, string | number | boolean> = {}
    if (q) params.q = q
    const { items, total: t } = await listarEntidadCompleta('tipos-descuento', params, controller.signal)
    if (controller.signal.aborted) return
    total.value = t
    itemsTodos.value = items as TipoDescuentoItem[]
  } catch (e: unknown) {
    if (controller.signal.aborted) return
    error.value = extractApiError(e, 'Error al cargar los tipos de descuento')
    itemsTodos.value = []
    total.value = 0
  } finally {
    if (listadoController === controller) {
      listadoController = null
      loading.value = false
    }
  }
}

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const item = itemDeFila(filas.value[idx])
  if (!item) {
    mensaje.value = 'Seleccione un tipo de descuento'
    return
  }
  aplicarForm(item)
  indiceSeleccionado.value = idx
  esNuevo.value = false
  modoEdicion.value = false
  vista.value = 'ficha'
  mensaje.value = null
}

function abrirFichaPorClave(clave: Clave) {
  const buscada = claveTexto(clave)
  const idx = filas.value.findIndex((f) => f.codigo === buscada)
  if (idx >= 0) abrirFicha(idx)
  else volverAlGrid()
}

function onNuevo() {
  if (!puedeCrear.value) return
  Object.assign(form, formularioVacio())
  claveOriginal.value = null
  esNuevo.value = true
  modoEdicion.value = true
  vista.value = 'ficha'
  mensaje.value = null
}

function onModificar() {
  if (!puedeEditar.value || esNuevo.value) return
  modoEdicion.value = true
}

function onCancelar() {
  if (esNuevo.value || !claveOriginal.value) {
    volverAlGrid()
    return
  }
  abrirFichaPorClave(claveOriginal.value)
}

function volverAlGrid() {
  vista.value = 'grid'
  esNuevo.value = false
  modoEdicion.value = false
}

function onTipoChange() {
  form.codigo = ''
}

function anadirTienda() {
  if (form.tiendas.length >= MAX_TIENDAS) return
  form.tiendas.push('')
}

function quitarTienda(indice: number) {
  form.tiendas.splice(indice, 1)
}

function claveAEliminar(): Clave | null {
  if (vista.value === 'grid') {
    const item = itemDeFila(filaSeleccionada.value)
    return item
      ? { tipoDescuento: item.tipoDescuento, tipo: item.tipo, codigo: item.codigo, fechaInicio: item.fechaInicio }
      : null
  }
  return esNuevo.value ? null : claveOriginal.value
}

function solicitarEliminar() {
  if (!puedeEliminar.value) return
  const clave = claveAEliminar()
  if (!clave) return
  confirmMessage.value = `Va a eliminar el tipo de descuento ${clave.tipoDescuento} (${etiquetaTipo(clave.tipo)}${
    clave.tipo === '.' ? '' : ` ${clave.codigo}`
  }) desde ${fechaGrid(clave.fechaInicio)}.`
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const clave = claveAEliminar()
  if (!clave) return
  try {
    await api.delete('/api/mantenimiento/tipos-descuento', { params: clave })
    mensaje.value = 'Tipo de descuento eliminado'
    await cargar()
    if (vista.value === 'ficha') volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

function codigoForm(): string {
  return String(form.codigo ?? '').trim()
}

function validar(): string | null {
  if (!form.tipoDescuento.trim()) return 'El tipo de descuento es obligatorio'
  if (!form.fechaInicio) return 'La fecha de inicio es obligatoria'
  if (form.tipo !== '.' && !codigoForm()) return `Indique ${metaTipo.value?.label.toLowerCase() ?? 'el codigo'}`
  if (form.fechaFin && form.fechaFin < form.fechaInicio) return 'La fecha final no puede ser anterior al inicio'
  return null
}

async function onGuardar() {
  const aviso = validar()
  if (aviso) {
    mensaje.value = aviso
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    const payload = {
      tipoDescuento: form.tipoDescuento.trim(),
      tipo: form.tipo,
      codigo: form.tipo === '.' ? '.' : codigoForm(),
      fechaInicio: form.fechaInicio,
      fechaFin: form.fechaFin || null,
      descuento: form.descuento ?? 0,
      articuloRegalo: String(form.articuloRegalo ?? '').trim() || null,
      cantidadRegalo: form.cantidadRegalo ?? 0,
      importeMinimo: form.importeMinimo ?? 0,
      importeMaximo: form.importeMaximo ?? 0,
      controlStock: form.controlStock,
      bloquearTiendas: form.tiendas.map((c) => String(c ?? '').trim()).filter((c) => c !== ''),
      claveOriginal: esNuevo.value ? undefined : claveOriginal.value,
    }
    const { data } = esNuevo.value
      ? await api.post('/api/mantenimiento/tipos-descuento', payload)
      : await api.put('/api/mantenimiento/tipos-descuento', payload)
    mensaje.value = esNuevo.value ? 'Tipo de descuento creado' : 'Tipo de descuento actualizado'
    await cargar()
    abrirFichaPorClave(data as Clave)
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="tipos-descuento-view">
    <h2>Tipo descuento</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver tipos de descuento.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="listado-panel">
          <div class="toolbar">
            <MantenimientoListadoButton
              titulo="Tipos de descuento"
              :columnas="columns"
              :filas="filas"
              @aviso="mensaje = $event"
            />
            <button v-if="puedeCrear" type="button" class="tool-btn" :disabled="loading" @click="onNuevo">
              Nuevo
            </button>
            <button type="button" class="tool-btn" :disabled="!puedeAbrirFicha" @click="abrirFicha()">
              Ficha
            </button>
            <div class="toolbar-spacer"></div>
            <button
              v-if="puedeEliminar"
              type="button"
              class="tool-btn danger"
              :disabled="!puedeMostrarEliminar || loading"
              @click="solicitarEliminar"
            >
              Borrar
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
            @search="onServerSearch"
          />

          <p class="hint">
            Doble clic o <strong>Ficha</strong> abre el detalle. La fila
            <strong>*</strong> crea con Nuevo.
          </p>
        </div>
      </template>

      <template v-else>
        <div class="ficha-panel">
          <div class="sticky-chrome">
            <button type="button" class="btn-volver" @click="volverAlGrid">← Volver a la rejilla</button>
            <div class="toolbar">
              <button v-if="puedeCrear" type="button" class="tool-btn" @click="onNuevo">Nuevo</button>
              <button
                v-if="puedeEditar"
                type="button"
                class="tool-btn"
                :disabled="esNuevo || modoEdicion"
                @click="onModificar"
              >
                Modificar
              </button>
              <button
                v-if="puedeEliminar"
                type="button"
                class="tool-btn danger"
                :disabled="esNuevo"
                @click="solicitarEliminar"
              >
                Borrar
              </button>
              <div class="toolbar-spacer"></div>
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

          <div class="ficha">
            <div class="cabecera">
              <label>
                Tipo descuento
                <input v-model="form.tipoDescuento" class="input-clave" maxlength="6" :readonly="soloLectura" />
              </label>
              <label>
                Tipo
                <select v-model="form.tipo" class="input-tipo" :disabled="soloLectura" @change="onTipoChange">
                  <option v-for="tipo in TIPOS" :key="tipo.value" :value="tipo.value">
                    {{ tipo.value }} {{ tipo.label }}
                  </option>
                </select>
              </label>
              <label v-if="entidadCodigo" class="campo-codigo">
                {{ metaTipo?.label }}
                <EntidadLookupField
                  v-model="form.codigo"
                  :entidad="entidadCodigo"
                  :readonly="soloLectura"
                  :max-length="18"
                  :titulo-modal="`Buscar ${metaTipo?.label.toLowerCase()}`"
                />
              </label>
            </div>

            <div class="bloques">
              <div class="col-izquierda">
                <fieldset class="bloque">
                  <legend>Periodo oferta</legend>
                  <div class="fila-campo">
                    <span>Fecha inicio</span>
                    <input v-model="form.fechaInicio" class="input-fecha" type="date" :readonly="soloLectura" />
                  </div>
                  <div class="fila-campo">
                    <span>Fecha final</span>
                    <input v-model="form.fechaFin" class="input-fecha" type="date" :readonly="soloLectura" />
                  </div>
                </fieldset>

                <fieldset class="bloque">
                  <legend>Descuento</legend>
                  <div class="fila-campo">
                    <span>% Descuento</span>
                    <DecimalInput
                      v-model="form.descuento"
                      class="input-num"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                </fieldset>

                <fieldset class="bloque">
                  <legend>Intervalo de importe</legend>
                  <div class="fila-campo">
                    <span>Importe minimo</span>
                    <DecimalInput
                      v-model="form.importeMinimo"
                      class="input-num"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                  <div class="fila-campo">
                    <span>Importe maximo</span>
                    <DecimalInput
                      v-model="form.importeMaximo"
                      class="input-num"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                </fieldset>
              </div>

              <div class="col-derecha">
                <fieldset class="bloque">
                  <legend>Regalo</legend>
                  <label class="campo-apilado">
                    Articulo regalo
                    <EntidadLookupField
                      v-model="form.articuloRegalo"
                      entidad="articulos"
                      :readonly="soloLectura"
                      :max-length="18"
                      titulo-modal="Buscar articulo"
                    />
                  </label>
                  <div class="fila-campo">
                    <span>Cantidad</span>
                    <DecimalInput
                      v-model="form.cantidadRegalo"
                      class="input-num"
                      :empty-as-null="false"
                      :integer="true"
                      :readonly="soloLectura"
                    />
                  </div>
                  <label class="check">
                    <input v-model="form.controlStock" type="checkbox" :disabled="soloLectura" />
                    Control de stock
                  </label>
                </fieldset>

                <fieldset class="bloque">
                  <legend>Tiendas excluidas</legend>
                  <p v-if="form.tiendas.length === 0" class="sin-tiendas">Ninguna: vale en todas las tiendas.</p>
                  <div v-for="(_tienda, indice) in form.tiendas" :key="indice" class="tienda">
                    <EntidadLookupField
                      v-model="form.tiendas[indice]"
                      entidad="tiendas"
                      :readonly="soloLectura"
                      titulo-modal="Buscar tienda"
                    />
                    <button
                      v-if="!soloLectura"
                      type="button"
                      class="tool-btn"
                      @click="quitarTienda(indice)"
                    >
                      Quitar
                    </button>
                  </div>
                  <button
                    v-if="!soloLectura"
                    type="button"
                    class="tool-btn"
                    :disabled="form.tiendas.length >= MAX_TIENDAS"
                    @click="anadirTienda"
                  >
                    Añadir tienda
                  </button>
                </fieldset>
              </div>
            </div>
          </div>
        </div>
      </template>

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar tipo de descuento"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="confirmOpen = false"
      />
    </template>
  </section>
</template>

<style scoped>
.tipos-descuento-view h2 {
  margin: 0 0 0.75rem;
}

.listado-panel > .toolbar,
.listado-panel :deep(.grid-wrap),
.listado-panel .paginacion {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
}

.ficha-panel {
  width: fit-content;
  max-width: 100%;
  box-sizing: border-box;
}

.sticky-chrome {
  width: 100%;
  box-sizing: border-box;
}

.toolbar {
  display: flex;
  flex-wrap: nowrap;
  gap: 0.35rem;
  align-items: center;
  width: 100%;
  box-sizing: border-box;
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

.ficha {
  width: 100%;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  padding: 0.65rem;
  box-sizing: border-box;
}

.cabecera {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.5rem 0.9rem;
  margin-bottom: 0.65rem;
}

.cabecera label,
.campo-apilado {
  display: grid;
  gap: 0.2rem;
  font-size: 0.8rem;
}

.campo-codigo {
  min-width: 20rem;
}

.input-clave {
  width: 6.5rem;
}

.input-tipo {
  width: 10rem;
}

.bloques {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.65rem;
}

.col-izquierda {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: 16.5rem;
}

.col-derecha {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  width: 22rem;
}

.bloque {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
  display: grid;
  gap: 0.35rem;
}

.bloque legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.fila-campo {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.78rem;
}

.input-fecha {
  width: 9.5rem;
  max-width: 9.5rem;
}

.input-num {
  width: 6rem;
  max-width: 6rem;
  text-align: right;
}

.ficha input,
.ficha select {
  padding: 0.25rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  box-sizing: border-box;
}

.ficha input:read-only {
  background: #f1f5f9;
}

.ficha input[type='checkbox'] {
  padding: 0;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.78rem;
}

.tienda {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.sin-tiendas {
  margin: 0;
  font-size: 0.78rem;
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

@media (max-width: 900px) {
  .bloques {
    flex-direction: column;
  }

  .col-izquierda,
  .col-derecha {
    width: 100%;
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
