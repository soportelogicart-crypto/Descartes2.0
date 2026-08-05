<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import GridFilterRow from '@/components/common/GridFilterRow.vue'

const MODULO = 'oferta-clientes'
const FILTER_KEYS = ['articulo', 'articuloDescripcion', 'cliente', 'clienteNombre', 'precio']
const GRID_COLUMNS = [
  { key: 'articulo', label: 'Articulo' },
  { key: 'articuloDescripcion', label: 'Descripcion' },
  { key: 'cliente', label: 'Cliente' },
  { key: 'clienteNombre', label: 'Cliente nombre' },
  { key: 'precio', label: 'Precio' },
]

type OfertaFila = {
  articulo: string
  cliente: string
  precio: number | null
  lUpdate: string | null
  rappelPorDto: boolean
  articuloDescripcion?: string | null
  clienteNombre?: string | null
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
  bloquearOfeEmpresa1: string | null
  bloquearOfeEmpresa2: string | null
  bloquearOfeEmpresa3: string | null
  bloquearOfeEmpresa4: string | null
  bloquearOfeEmpresa5: string | null
  bloquearOfeEmpresa6: string | null
  bloquearOfeEmpresa7: string | null
  bloquearOfeEmpresa8: string | null
  bloquearOfeEmpresa9: string | null
  bloquearOfeEmpresa10: string | null
}

const { puede } = usePermisos()
const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const itemsTodas = ref<OfertaFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const indiceSeleccionado = ref(0)
const esNuevo = ref(false)
const modoEdicion = ref(false)

const tiendas = ref<{ value: string; label: string }[]>([])
const articuloDescripcion = ref('')
const clienteNombre = ref('')

const form = reactive(ofertaVacia())

const itemsFiltrados = computed(() =>
  aplicarFiltrosColumnas(itemsTodas.value, filtros.value) as OfertaFila[]
)

watch(itemsFiltrados, (lista) => {
  if (indiceSeleccionado.value >= lista.length) {
    indiceSeleccionado.value = Math.max(0, lista.length - 1)
  }
})

const filaSeleccionada = computed(() => itemsFiltrados.value[indiceSeleccionado.value] ?? null)
const soloLectura = computed(() => !modoEdicion.value && !esNuevo.value)
const puedeGuardar = computed(() => (puedeCrear.value || puedeEditar.value) && (modoEdicion.value || esNuevo.value))

const confirmOpen = ref(false)
const confirmMessage = ref('')

const escalados = computed(() =>
  [1, 2, 3, 4, 5, 6, 7, 8].map((i) => ({
    i,
    cantidadKey: `cantidad${i}` as keyof typeof form,
    precioKey: `precioEsp${i}` as keyof typeof form,
  }))
)

const empresas = computed(() =>
  [1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((i) => ({
    i,
    key: `bloquearOfeEmpresa${i}` as keyof typeof form,
  }))
)

function ofertaVacia(): OfertaFila {
  const base: OfertaFila = {
    articulo: '',
    cliente: '',
    precio: null,
    lUpdate: null,
    rappelPorDto: false,
    articuloDescripcion: '',
    clienteNombre: '',
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
    bloquearOfeEmpresa1: null,
    bloquearOfeEmpresa2: null,
    bloquearOfeEmpresa3: null,
    bloquearOfeEmpresa4: null,
    bloquearOfeEmpresa5: null,
    bloquearOfeEmpresa6: null,
    bloquearOfeEmpresa7: null,
    bloquearOfeEmpresa8: null,
    bloquearOfeEmpresa9: null,
    bloquearOfeEmpresa10: null,
  }
  return base
}

function asignarForm(data: Partial<OfertaFila>) {
  const vacio = ofertaVacia()
  Object.assign(form, vacio, data)
  articuloDescripcion.value = String(data.articuloDescripcion ?? '')
  clienteNombre.value = String(data.clienteNombre ?? '')
}

onMounted(async () => {
  if (!puedeVer.value) return
  await Promise.all([cargar(), cargarTiendas()])
})

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { activo: true, pageSize: 200 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
  } catch {
    tiendas.value = []
  }
}

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/mantenimiento/oferta-clientes', {
      params: { page: 1, pageSize: 500 },
    })
    itemsTodas.value = data.items ?? []
    total.value = data.total ?? itemsTodas.value.length
    indiceSeleccionado.value = Math.min(
      indiceSeleccionado.value,
      Math.max(0, itemsFiltrados.value.length - 1)
    )
  } catch (e) {
    error.value = extractApiError(e, 'Error al cargar ofertas')
    itemsTodas.value = []
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

function onNuevo() {
  if (!puedeCrear.value) return
  esNuevo.value = true
  modoEdicion.value = true
  asignarForm(ofertaVacia())
  vista.value = 'ficha'
  mensaje.value = null
}

function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = itemsFiltrados.value[idx]
  if (!fila) return
  indiceSeleccionado.value = idx
  esNuevo.value = false
  modoEdicion.value = false
  asignarForm(fila)
  vista.value = 'ficha'
  mensaje.value = null
}

function onModificar() {
  if (!puedeEditar.value || esNuevo.value) return
  modoEdicion.value = true
}

function volverAlGrid() {
  vista.value = 'grid'
  esNuevo.value = false
  modoEdicion.value = false
  mensaje.value = null
}

function onCancelar() {
  if (esNuevo.value) {
    volverAlGrid()
    return
  }
  const fila = filaSeleccionada.value
  if (fila) asignarForm(fila)
  modoEdicion.value = false
}

async function resolverArticulo() {
  const codigo = form.articulo.trim()
  if (!codigo) {
    articuloDescripcion.value = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(codigo)}`)
    articuloDescripcion.value = String(data.descripcion ?? '')
  } catch {
    articuloDescripcion.value = ''
  }
}

async function resolverCliente() {
  const codigo = form.cliente.trim()
  if (!codigo) {
    clienteNombre.value = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(codigo)}`)
    clienteNombre.value = String(data.nombre ?? '')
  } catch {
    clienteNombre.value = ''
  }
}

function payload(): Record<string, unknown> {
  const body: Record<string, unknown> = {
    articulo: form.articulo.trim(),
    cliente: form.cliente.trim(),
    precio: form.precio,
    rappelPorDto: form.rappelPorDto,
  }
  for (let i = 1; i <= 8; i++) {
    body[`cantidad${i}`] = form[`cantidad${i}` as keyof OfertaFila]
    body[`precioEsp${i}`] = form[`precioEsp${i}` as keyof OfertaFila]
  }
  for (let i = 1; i <= 10; i++) {
    const key = `bloquearOfeEmpresa${i}` as keyof OfertaFila
    const val = form[key]
    body[key] = val == null || String(val).trim() === '' ? null : String(val).trim()
  }
  return body
}

function validar(): string | null {
  if (!form.articulo.trim()) return 'El articulo es obligatorio'
  if (!form.cliente.trim()) return 'El cliente es obligatorio'
  return null
}

async function onGuardar() {
  if (!puedeGuardar.value) return
  const err = validar()
  if (err) {
    mensaje.value = err
    return
  }
  saving.value = true
  mensaje.value = null
  try {
    const body = payload()
    if (esNuevo.value) {
      await api.post('/api/mantenimiento/oferta-clientes', body)
      mensaje.value = 'Oferta creada'
    } else {
      await api.put(
        `/api/mantenimiento/oferta-clientes/${encodeURIComponent(form.articulo.trim())}/${encodeURIComponent(form.cliente.trim())}`,
        body
      )
      mensaje.value = 'Oferta actualizada'
    }
    await cargar()
    const idx = itemsFiltrados.value.findIndex(
      (i) => i.articulo === form.articulo.trim() && i.cliente === form.cliente.trim()
    )
    if (idx >= 0) {
      indiceSeleccionado.value = idx
      asignarForm(itemsFiltrados.value[idx])
      esNuevo.value = false
      modoEdicion.value = false
    } else {
      volverAlGrid()
    }
  } catch (e) {
    mensaje.value = extractApiError(e, 'No se pudo guardar la oferta')
  } finally {
    saving.value = false
  }
}

function solicitarEliminar() {
  const fila = vista.value === 'ficha' ? form : filaSeleccionada.value
  if (!fila || !puedeEliminar.value) return
  confirmMessage.value = `Va a eliminar la oferta articulo ${fila.articulo} / cliente ${fila.cliente}. Esta accion no se puede deshacer.`
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const fila = vista.value === 'ficha' ? form : filaSeleccionada.value
  if (!fila) return
  try {
    await api.delete(
      `/api/mantenimiento/oferta-clientes/${encodeURIComponent(String(fila.articulo).trim())}/${encodeURIComponent(String(fila.cliente).trim())}`
    )
    mensaje.value = 'Oferta eliminada'
    if (vista.value === 'ficha') volverAlGrid()
    await cargar()
  } catch (e) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar la oferta')
  }
}

function numModel(key: keyof typeof form): number {
  const v = form[key]
  return typeof v === 'number' ? v : Number(v) || 0
}

function setNum(key: keyof typeof form, raw: string) {
  ;(form as Record<string, unknown>)[key] = raw === '' ? 0 : Number(raw.replace(',', '.'))
}

function empModel(key: keyof typeof form): string {
  const v = form[key]
  return v == null ? '' : String(v)
}

function setEmp(key: keyof typeof form, raw: string) {
  ;(form as Record<string, unknown>)[key] = raw.trim() === '' ? null : raw.trim()
}
</script>

<template>
  <section class="ofertas-view">
    <h2>Oferta de clientes</h2>

    <p v-if="!puedeVer" class="error">No tiene permiso para ver ofertas de clientes.</p>

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
            :disabled="!filaSeleccionada"
            @click="abrirFicha()"
          >
            Ficha
          </button>
          <div class="toolbar-spacer"></div>
          <button
            v-if="puedeEliminar"
            type="button"
            class="tool-btn danger"
            :disabled="!filaSeleccionada || loading"
            @click="solicitarEliminar"
          >
            Borrar
          </button>
        </div>

        <p v-if="loading" class="hint">Cargando...</p>
        <table v-else class="grid">
          <thead>
            <tr>
              <th class="col-ind"></th>
              <th>Articulo</th>
              <th>Descripcion</th>
              <th>Cliente</th>
              <th>Cliente nombre</th>
              <th>Precio</th>
            </tr>
            <GridFilterRow
              :columns="GRID_COLUMNS"
              :filterable-keys="FILTER_KEYS"
              v-model:filters="filtros"
            />
          </thead>
          <tbody>
            <tr
              v-for="(item, index) in itemsFiltrados"
              :key="`${item.articulo}-${item.cliente}`"
              :class="{ selected: index === indiceSeleccionado }"
              @click="seleccionar(index)"
              @dblclick="abrirFicha(index)"
            >
              <td class="col-ind">{{ index === indiceSeleccionado ? '>' : '' }}</td>
              <td>{{ item.articulo }}</td>
              <td>{{ item.articuloDescripcion }}</td>
              <td>{{ item.cliente }}</td>
              <td>{{ item.clienteNombre }}</td>
              <td>{{ item.precio }}</td>
            </tr>
            <tr v-if="itemsFiltrados.length === 0">
              <td colspan="6">Sin ofertas</td>
            </tr>
          </tbody>
        </table>
        <p class="hint">Total: {{ total }}. Doble clic o Ficha abre el detalle.</p>
      </template>

      <template v-else>
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
                <input
                  v-model="form.articulo"
                  maxlength="18"
                  :readonly="soloLectura || !esNuevo"
                  @blur="resolverArticulo"
                />
                <span class="desc">{{ articuloDescripcion }}</span>
              </div>
            </label>
            <label>
              Cliente
              <div class="con-desc">
                <input
                  v-model="form.cliente"
                  maxlength="9"
                  :readonly="soloLectura || !esNuevo"
                  @blur="resolverCliente"
                />
                <span class="desc">{{ clienteNombre }}</span>
              </div>
            </label>
            <label class="precio">
              Precio
              <input
                :value="form.precio ?? ''"
                type="number"
                step="any"
                :readonly="soloLectura"
                @input="form.precio = ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value)"
              />
            </label>
          </div>

          <div class="bloque-escalados">
            <div class="escalados-header">
              <h3>Escalados</h3>
              <label class="check">
                <input v-model="form.rappelPorDto" type="checkbox" :disabled="soloLectura" />
                por descuento
              </label>
            </div>
            <table class="escalados">
              <thead>
                <tr>
                  <th>Unidades</th>
                  <th>Precio</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="row in escalados" :key="row.i">
                  <td>
                    <input
                      :value="numModel(row.cantidadKey)"
                      type="number"
                      step="any"
                      :readonly="soloLectura"
                      @input="setNum(row.cantidadKey, ($event.target as HTMLInputElement).value)"
                    />
                  </td>
                  <td>
                    <input
                      :value="numModel(row.precioKey)"
                      type="number"
                      step="any"
                      :readonly="soloLectura"
                      @input="setNum(row.precioKey, ($event.target as HTMLInputElement).value)"
                    />
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <div class="bloque-empresas">
            <h3>Excluir la aplicacion en las siguientes Empresas:</h3>
            <div class="empresas-grid">
              <input
                v-for="emp in empresas"
                :key="emp.i"
                :value="empModel(emp.key)"
                list="tiendas-oferta"
                maxlength="3"
                :readonly="soloLectura"
                @input="setEmp(emp.key, ($event.target as HTMLInputElement).value)"
              />
            </div>
            <datalist id="tiendas-oferta">
              <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
            </datalist>
          </div>
        </div>
      </template>

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

.grid {
  width: 100%;
  max-width: 1100px;
  border-collapse: collapse;
  font-size: 0.85rem;
  background: #fff;
}

.grid th,
.grid td {
  border: 1px solid #e2e8f0;
  padding: 0.35rem 0.5rem;
  text-align: left;
}

.col-ind {
  width: 1.5rem;
  text-align: center;
}

.grid th {
  background: #f1f5f9;
}

.grid tbody tr {
  cursor: pointer;
}

.grid tbody tr:hover {
  background: #eff6ff;
}

.grid tbody tr.selected {
  background: #dbeafe;
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
  max-width: 480px;
  background: #f8fafc;
  border: 1px solid #c5cdd8;
  border-radius: 8px;
  padding: 0.75rem;
}

.cabecera {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.65rem;
  margin-bottom: 0.85rem;
}

.cabecera label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.78rem;
  color: #475569;
}

.con-desc {
  display: grid;
  grid-template-columns: 9rem 1fr;
  gap: 0.45rem;
  align-items: center;
}

.cabecera .precio .con-desc,
.cabecera .precio input {
  width: 100%;
}

.desc {
  font-size: 0.85rem;
  font-weight: 600;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bloque-escalados {
  margin-bottom: 0.85rem;
  padding: 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  max-width: 22rem;
}

.escalados-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.35rem;
}

.escalados-header h3,
.bloque-empresas h3 {
  margin: 0 0 0.45rem;
  font-size: 0.85rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
}

.escalados {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.escalados th,
.escalados td {
  border: 1px solid #cbd5e1;
  padding: 0.2rem;
}

.escalados th {
  background: #f1f5f9;
  text-align: center;
}

.escalados input {
  width: 100%;
  border: none;
  padding: 0.25rem 0.35rem;
  text-align: right;
}

.bloque-empresas {
  padding: 0.55rem;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.empresas-grid {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 0.45rem;
}

.empresas-grid input,
.cabecera input {
  padding: 0.25rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
}

input:read-only {
  background: #f1f5f9;
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
  .cabecera {
    grid-template-columns: 1fr;
  }
  .con-desc {
    grid-template-columns: 1fr;
  }
  .empresas-grid {
    grid-template-columns: repeat(3, minmax(0, 5rem));
  }
}
</style>
