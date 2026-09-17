<script setup lang="ts">
import MantenimientoListadoButton from '@/components/mantenimiento/MantenimientoListadoButton.vue'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { resolverArticulo as resolverArticuloApi } from '@/api/articulos'
import {
  filaVaciaDesdeColumnas,
  getGridColumns,
  type GridFila,
} from '@/config/entidad-grid-columns'
import { extractApiError, listarEntidadCompleta } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  normalizarFechaSolo,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

const MODULO = 'oferta-proveedores'
const columns = getGridColumns('oferta-proveedores')
const FILTER_KEYS = ['articulo', 'proveedor', 'fechaInicio', 'fechaFin']
const DATE_KEYS = ['fechaInicio', 'fechaFin']

type OfertaFila = {
  articulo: string
  proveedor: string
  fechaInicio: string | null
  fechaFin: string | null
  precioEsp: number
  lUpdate: string | null
  pjeDto: number
  pjeDto2: number
  pjeDto3: number
  articuloDescripcion?: string | null
  proveedorNombre?: string | null
  cantidad1: number
  cantidad2: number
  cantidad3: number
  cantidad4: number
  cantidad5: number
  cantidad6: number
  cantidad7: number
  cantidad8: number
  precioEsp1: number
  precioEsp2: number
  precioEsp3: number
  precioEsp4: number
  precioEsp5: number
  precioEsp6: number
  precioEsp7: number
  precioEsp8: number
}

const { puede } = usePermisos()
const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const indiceSeleccionado = ref(0)
const esNuevo = ref(false)
const modoEdicion = ref(false)

const articuloDescripcion = ref('')
const proveedorNombre = ref('')
const form = reactive(ofertaVacia())

const buscarArticuloOpen = ref(false)
const buscarProveedorOpen = ref(false)

const filas = computed<GridFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value, {
    dateKeys: DATE_KEYS,
  }) as GridFila[]
  if (!puedeCrear.value) return filtradas
  if (filtradas.some((f) => f._nuevo)) return filtradas
  return [...filtradas, filaVaciaDesdeColumnas(columns)]
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
    Boolean(filaSeleccionada.value.articulo)
)
const puedeAbrirFicha = computed(
  () => Boolean(filaSeleccionada.value) && !filaSeleccionada.value?._nuevo
)

const confirmOpen = ref(false)
const confirmMessage = ref('')

const escalados = computed(() =>
  [1, 2, 3, 4, 5, 6, 7, 8].map((i) => ({
    i,
    cantidadKey: `cantidad${i}` as keyof typeof form,
    precioKey: `precioEsp${i}` as keyof typeof form,
  }))
)

function ofertaVacia(): OfertaFila {
  return {
    articulo: '',
    proveedor: '',
    fechaInicio: null,
    fechaFin: null,
    precioEsp: 0,
    lUpdate: null,
    pjeDto: 0,
    pjeDto2: 0,
    pjeDto3: 0,
    articuloDescripcion: '',
    proveedorNombre: '',
    cantidad1: 0,
    cantidad2: 0,
    cantidad3: 0,
    cantidad4: 0,
    cantidad5: 0,
    cantidad6: 0,
    cantidad7: 0,
    cantidad8: 0,
    precioEsp1: 0,
    precioEsp2: 0,
    precioEsp3: 0,
    precioEsp4: 0,
    precioEsp5: 0,
    precioEsp6: 0,
    precioEsp7: 0,
    precioEsp8: 0,
  }
}

function fechaGrid(value: string | null | undefined): string {
  if (!value) return ''
  return normalizarFechaSolo(value) || String(value).slice(0, 10)
}

function aFilaGrid(item: OfertaFila): GridFila {
  return {
    codigo: `${item.articulo}|${item.proveedor}`,
    articulo: item.articulo,
    proveedor: item.proveedor,
    fechaInicio: fechaGrid(item.fechaInicio),
    fechaFin: fechaGrid(item.fechaFin),
  }
}

function aplicarForm(data: OfertaFila) {
  Object.assign(form, ofertaVacia(), data)
  articuloDescripcion.value = String(data.articuloDescripcion ?? '')
  proveedorNombre.value = String(data.proveedorNombre ?? '')
}

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const { items, total: t } = await listarEntidadCompleta('oferta-proveedores')
    total.value = t
    filasTodas.value = (items as OfertaFila[]).map(aFilaGrid)
    indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Error al cargar ofertas')
    filasTodas.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}


function seleccionar(index: number) {
  indiceSeleccionado.value = index
}


async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || !fila.articulo || !fila.proveedor) {
    mensaje.value = 'Seleccione una oferta'
    return
  }
  try {
    const { data } = await api.get(
      `/api/mantenimiento/oferta-proveedores/${encodeURIComponent(String(fila.articulo))}/${encodeURIComponent(String(fila.proveedor))}`
    )
    aplicarForm(data)
    indiceSeleccionado.value = idx
    esNuevo.value = false
    modoEdicion.value = false
    vista.value = 'ficha'
    mensaje.value = null
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

function onNuevo() {
  if (!puedeCrear.value) return
  aplicarForm(ofertaVacia())
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
  if (esNuevo.value) {
    volverAlGrid()
    return
  }
  modoEdicion.value = false
  abrirFicha(indiceSeleccionado.value)
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
    if (!fila || fila._nuevo) return
    confirmMessage.value = `Va a eliminar la oferta ${fila.articulo} / ${fila.proveedor}.`
  } else {
    if (esNuevo.value) return
    confirmMessage.value = `Va a eliminar la oferta ${form.articulo} / ${form.proveedor}.`
  }
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const articulo =
    vista.value === 'grid' ? String(filaSeleccionada.value?.articulo ?? '') : form.articulo
  const proveedor =
    vista.value === 'grid' ? String(filaSeleccionada.value?.proveedor ?? '') : form.proveedor
  if (!articulo || !proveedor) return
  try {
    await api.delete(
      `/api/mantenimiento/oferta-proveedores/${encodeURIComponent(articulo)}/${encodeURIComponent(proveedor)}`
    )
    mensaje.value = 'Oferta eliminada'
    await cargar()
    if (vista.value === 'ficha') volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

async function onGuardar() {
  if (!String(form.articulo).trim() || !String(form.proveedor).trim()) {
    mensaje.value = 'Articulo y proveedor son obligatorios'
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    const payload = { ...form }
    if (esNuevo.value) {
      const { data } = await api.post('/api/mantenimiento/oferta-proveedores', payload)
      mensaje.value = 'Oferta creada'
      await cargar()
      const idx = filas.value.findIndex(
        (i) => i.articulo === data.articulo && i.proveedor === data.proveedor
      )
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await api.put(
        `/api/mantenimiento/oferta-proveedores/${encodeURIComponent(form.articulo)}/${encodeURIComponent(form.proveedor)}`,
        payload
      )
      mensaje.value = 'Oferta actualizada'
      await cargar()
      await abrirFicha(indiceSeleccionado.value)
    }
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo guardar')
  } finally {
    saving.value = false
  }
}

async function resolverArticulo() {
  const codigo = String(form.articulo ?? '').trim()
  if (!codigo) {
    articuloDescripcion.value = ''
    return
  }
  try {
    const art = await resolverArticuloApi(codigo)
    form.articulo = art.codigo
    articuloDescripcion.value = String(art.descripcion ?? art.nombre ?? '')
  } catch {
    articuloDescripcion.value = ''
  }
}

async function resolverProveedor() {
  const codigo = String(form.proveedor ?? '').trim()
  if (!codigo) {
    proveedorNombre.value = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/proveedores/${encodeURIComponent(codigo)}`)
    proveedorNombre.value = String(data.nombre ?? '')
  } catch {
    proveedorNombre.value = ''
  }
}

function onArticuloSeleccionado(resultado: EntidadBuscarResultado) {
  form.articulo = resultado.codigo
  articuloDescripcion.value = resultado.etiqueta
}

function onProveedorSeleccionado(resultado: EntidadBuscarResultado) {
  form.proveedor = resultado.codigo
  proveedorNombre.value = resultado.etiqueta
}

function numModel(key: keyof typeof form): number {
  const v = form[key]
  return typeof v === 'number' ? v : Number(v) || 0
}
</script>

<template>
  <section class="ofertas-view">
    <h2>Ofertas proveedores</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver ofertas de proveedores.</p>

    <template v-else>
      <p v-if="mensaje" class="msg">{{ mensaje }}</p>
      <p v-if="error" class="error">{{ error }}</p>

      <template v-if="vista === 'grid'">
        <div class="listado-panel">
          <div class="toolbar">
            <MantenimientoListadoButton
            titulo="Ofertas proveedores"
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
                Articulo
                <div class="con-desc">
                  <div class="codigo-buscar">
                    <input
                      v-model="form.articulo"
                      maxlength="18"
                      :readonly="soloLectura || !esNuevo"
                      @blur="resolverArticulo"
                    />
                    <button
                      v-if="esNuevo"
                      type="button"
                      class="btn-lupa"
                      title="Buscar articulo"
                      @click="buscarArticuloOpen = true"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                  <span class="desc">{{ articuloDescripcion }}</span>
                </div>
              </label>
              <label>
                Proveedor
                <div class="con-desc">
                  <div class="codigo-buscar">
                    <input
                      v-model="form.proveedor"
                      maxlength="6"
                      :readonly="soloLectura || !esNuevo"
                      @blur="resolverProveedor"
                    />
                    <button
                      v-if="esNuevo"
                      type="button"
                      class="btn-lupa"
                      title="Buscar proveedor"
                      @click="buscarProveedorOpen = true"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                  <span class="desc">{{ proveedorNombre }}</span>
                </div>
              </label>
            </div>

            <div class="bloques">
              <div class="col-izquierda">
                <fieldset class="bloque bloque-compacto">
                  <legend>Periodo oferta</legend>
                  <div class="fila-campo">
                    <span>Fecha inicio</span>
                    <input
                      class="input-fecha"
                      :value="form.fechaInicio ?? ''"
                      type="date"
                      :readonly="soloLectura"
                      @input="form.fechaInicio = ($event.target as HTMLInputElement).value || null"
                    />
                  </div>
                  <div class="fila-campo">
                    <span>Fecha final</span>
                    <input
                      class="input-fecha"
                      :value="form.fechaFin ?? ''"
                      type="date"
                      :readonly="soloLectura"
                      @input="form.fechaFin = ($event.target as HTMLInputElement).value || null"
                    />
                  </div>
                </fieldset>

                <fieldset class="bloque bloque-compacto">
                  <legend>Descuento</legend>
                  <div class="fila-campo">
                    <span>Descuento</span>
                    <DecimalInput
                      class="input-dto"
                      v-model="form.pjeDto"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                  <div class="fila-campo">
                    <span>Dto 2</span>
                    <DecimalInput
                      class="input-dto"
                      v-model="form.pjeDto2"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                  <div class="fila-campo">
                    <span>Dto 3</span>
                    <DecimalInput
                      class="input-dto"
                      v-model="form.pjeDto3"
                      :empty-as-null="false"
                      :readonly="soloLectura"
                    />
                  </div>
                </fieldset>
              </div>

              <fieldset class="bloque escalados-box">
                <legend>Escalados</legend>
                <table class="escalados">
                  <thead>
                    <tr>
                      <th>Unidades</th>
                      <th>Precio</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td class="vacio"></td>
                      <td>
                        <DecimalInput
                          v-model="form.precioEsp"
                          :empty-as-null="false"
                          :readonly="soloLectura"
                        />
                      </td>
                    </tr>
                    <tr v-for="row in escalados" :key="row.i">
                      <td>
                        <DecimalInput
                          :model-value="numModel(row.cantidadKey)"
                          :empty-as-null="false"
                          :readonly="soloLectura"
                          @update:model-value="(form as Record<string, unknown>)[row.cantidadKey] = $event ?? 0"
                        />
                      </td>
                      <td>
                        <DecimalInput
                          :model-value="numModel(row.precioKey)"
                          :empty-as-null="false"
                          :readonly="soloLectura"
                          @update:model-value="(form as Record<string, unknown>)[row.precioKey] = $event ?? 0"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </fieldset>
            </div>
          </div>
        </div>
      </template>

      <EntidadBuscarModal
        :open="buscarArticuloOpen"
        entidad="articulos"
        titulo="Buscar articulo"
        @seleccionar="onArticuloSeleccionado"
        @cerrar="buscarArticuloOpen = false"
      />
      <EntidadBuscarModal
        :open="buscarProveedorOpen"
        entidad="proveedores"
        titulo="Buscar proveedor"
        @seleccionar="onProveedorSeleccionado"
        @cerrar="buscarProveedorOpen = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar oferta"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="confirmOpen = false"
      />
    </template>
  </section>
</template>

<style scoped>
.ofertas-view h2 {
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

.ficha {
  width: 100%;
  background: #f0f4f8;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  padding: 0.65rem;
  box-sizing: border-box;
}

.cabecera {
  display: grid;
  gap: 0.5rem;
  margin-bottom: 0.65rem;
}

.cabecera label {
  display: grid;
  gap: 0.2rem;
  font-size: 0.8rem;
}

.con-desc {
  display: grid;
  grid-template-columns: minmax(10rem, 12rem) 1fr;
  gap: 0.5rem;
  align-items: center;
}

.codigo-buscar {
  display: flex;
  gap: 0.25rem;
  align-items: stretch;
}

.codigo-buscar input {
  flex: 1;
  min-width: 0;
  box-sizing: border-box;
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

.desc {
  font-size: 0.85rem;
  font-weight: 600;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
  flex: 0 0 auto;
  width: 16.5rem;
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

.bloque-compacto {
  width: 100%;
  box-sizing: border-box;
}

.escalados-box {
  flex: 0 0 auto;
  width: 14rem;
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

.input-dto {
  width: 4.75rem;
  max-width: 4.75rem;
  text-align: right;
}

.ficha input {
  padding: 0.25rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font-size: 0.8rem;
  box-sizing: border-box;
}

.ficha input:read-only {
  background: #f1f5f9;
}

.escalados {
  width: 100%;
  border-collapse: collapse;
}

.escalados th,
.escalados td {
  padding: 0.15rem;
  font-size: 0.75rem;
}

.escalados th {
  text-align: left;
  color: #475569;
}

.escalados input {
  width: 100%;
  max-width: 6.5rem;
}

.escalados .vacio {
  width: 40%;
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

  .escalados-box {
    width: 100%;
  }

  .con-desc {
    grid-template-columns: 1fr;
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
