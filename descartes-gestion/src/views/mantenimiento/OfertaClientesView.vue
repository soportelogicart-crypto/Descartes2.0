<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { api } from '@/api/client'
import {
  filaVaciaDesdeColumnas,
  getGridColumns,
  type GridFila,
} from '@/config/entidad-grid-columns'
import { leerGridPageSize } from '@/composables/useGridPageSize'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import {
  aplicarFiltrosColumnas,
  filtrosIniciales,
  type ColumnFilter,
} from '@/composables/useGridColumnFilters'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import ListPagination from '@/components/common/ListPagination.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'
import EntidadGrid from '@/components/mantenimiento/EntidadGrid.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'

const MODULO = 'oferta-clientes'
const columns = getGridColumns('oferta-clientes')
const FILTER_KEYS = ['articulo', 'articuloDescripcion', 'cliente', 'clienteNombre', 'precio']

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
  bloquearOfeEmpresa1: string
  bloquearOfeEmpresa2: string
  bloquearOfeEmpresa3: string
  bloquearOfeEmpresa4: string
  bloquearOfeEmpresa5: string
  bloquearOfeEmpresa6: string
  bloquearOfeEmpresa7: string
  bloquearOfeEmpresa8: string
  bloquearOfeEmpresa9: string
  bloquearOfeEmpresa10: string
}

type RefPrecio = { t: string; precio: number; sinIva: number }

const { puede } = usePermisos()
const puedeCrear = computed(() => puede(MODULO, 'crear'))
const puedeEditar = computed(() => puede(MODULO, 'editar'))
const puedeEliminar = computed(() => puede(MODULO, 'eliminar'))
const puedeVer = computed(() => puede(MODULO, 'ver'))

const vista = ref<'grid' | 'ficha'>('grid')
const filasTodas = ref<GridFila[]>([])
const filtros = ref<Record<string, ColumnFilter>>(filtrosIniciales(FILTER_KEYS))
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize())
const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const indiceSeleccionado = ref(0)
const esNuevo = ref(false)
const modoEdicion = ref(false)

const articuloDescripcion = ref('')
const clienteNombre = ref('')
const form = reactive(ofertaVacia())
const ivaPct = ref(0)
const preciosArticulo = ref<number[]>(Array.from({ length: 9 }, () => 0))
const costeArticulo = ref(0)

const buscarArticuloOpen = ref(false)
const buscarClienteOpen = ref(false)
const buscarEmpresaOpen = ref(false)
const empresaSlot = ref(1)

const confirmOpen = ref(false)
const confirmMessage = ref('')
const avisoOpen = ref(false)
const avisoMensaje = ref('')
const campoAviso = ref<string | null>(null)

const filas = computed<GridFila[]>(() => {
  const filtradas = aplicarFiltrosColumnas(filasTodas.value, filtros.value) as GridFila[]
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

const tablaPrecios = computed<RefPrecio[]>(() => {
  const factor = ivaPct.value > 0 ? 1 + ivaPct.value / 100 : 1
  const filasRef: RefPrecio[] = preciosArticulo.value.map((precio, idx) => ({
    t: String(idx + 1),
    precio,
    sinIva: factor > 1 ? precio / factor : precio,
  }))
  filasRef.push({
    t: 'C',
    precio: costeArticulo.value,
    sinIva: factor > 1 ? costeArticulo.value / factor : costeArticulo.value,
  })
  return filasRef
})

function ofertaVacia(): OfertaFila {
  return {
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
    bloquearOfeEmpresa1: '',
    bloquearOfeEmpresa2: '',
    bloquearOfeEmpresa3: '',
    bloquearOfeEmpresa4: '',
    bloquearOfeEmpresa5: '',
    bloquearOfeEmpresa6: '',
    bloquearOfeEmpresa7: '',
    bloquearOfeEmpresa8: '',
    bloquearOfeEmpresa9: '',
    bloquearOfeEmpresa10: '',
  }
}

function aFilaGrid(item: OfertaFila): GridFila {
  return {
    codigo: `${item.articulo}|${item.cliente}`,
    articulo: item.articulo,
    articuloDescripcion: item.articuloDescripcion ?? '',
    cliente: item.cliente,
    clienteNombre: item.clienteNombre ?? '',
    precio: item.precio,
  }
}

function aplicarForm(data: Partial<OfertaFila>) {
  const vacio = ofertaVacia()
  Object.assign(form, vacio, data)
  for (let i = 1; i <= 10; i++) {
    const key = `bloquearOfeEmpresa${i}` as keyof OfertaFila
    const val = data[key]
    ;(form as Record<string, unknown>)[key] = val == null ? '' : String(val)
  }
  articuloDescripcion.value = String(data.articuloDescripcion ?? '')
  clienteNombre.value = String(data.clienteNombre ?? '')
}

function mostrarAviso(campo: string, mensajeTexto: string) {
  campoAviso.value = campo
  avisoMensaje.value = mensajeTexto
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

onMounted(async () => {
  if (!puedeVer.value) return
  await cargar()
})

async function cargar() {
  loading.value = true
  error.value = null
  try {
    const { data } = await api.get('/api/mantenimiento/oferta-clientes', {
      params: { page: page.value, pageSize: pageSize.value },
    })
    const items = (data.items ?? []) as OfertaFila[]
    total.value = data.total ?? 0
    filasTodas.value = items.map(aFilaGrid)
    indiceSeleccionado.value = Math.min(indiceSeleccionado.value, Math.max(0, filas.value.length - 1))
  } catch (e: unknown) {
    error.value = extractApiError(e, 'Error al cargar ofertas')
    filasTodas.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
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

function seleccionar(index: number) {
  indiceSeleccionado.value = index
}

function onListado() {
  window.print()
}

async function abrirFicha(index?: number) {
  const idx = index ?? indiceSeleccionado.value
  const fila = filas.value[idx]
  if (!fila || fila._nuevo || !fila.articulo || !fila.cliente) {
    mensaje.value = 'Seleccione una oferta'
    return
  }
  try {
    const { data } = await api.get(
      `/api/mantenimiento/oferta-clientes/${encodeURIComponent(String(fila.articulo))}/${encodeURIComponent(String(fila.cliente))}`
    )
    aplicarForm(data)
    indiceSeleccionado.value = idx
    esNuevo.value = false
    modoEdicion.value = false
    vista.value = 'ficha'
    mensaje.value = null
    await cargarPreciosArticulo(String(data.articulo ?? ''))
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo cargar la ficha')
  }
}

async function onNuevo() {
  if (!puedeCrear.value) return
  aplicarForm(ofertaVacia())
  preciosArticulo.value = Array.from({ length: 9 }, () => 0)
  costeArticulo.value = 0
  ivaPct.value = 0
  esNuevo.value = true
  modoEdicion.value = true
  vista.value = 'ficha'
  mensaje.value = null
  await nextTick()
  document.querySelector<HTMLElement>('[data-field-key="articulo"]')?.focus()
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
  void abrirFicha(indiceSeleccionado.value)
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
    confirmMessage.value = `Va a eliminar la oferta ${fila.articulo} / ${fila.cliente}.`
  } else {
    if (esNuevo.value) return
    confirmMessage.value = `Va a eliminar la oferta ${form.articulo} / ${form.cliente}.`
  }
  confirmOpen.value = true
}

async function confirmarEliminar() {
  confirmOpen.value = false
  const articulo =
    vista.value === 'grid' ? String(filaSeleccionada.value?.articulo ?? '') : form.articulo
  const cliente =
    vista.value === 'grid' ? String(filaSeleccionada.value?.cliente ?? '') : form.cliente
  if (!articulo || !cliente) return
  try {
    await api.delete(
      `/api/mantenimiento/oferta-clientes/${encodeURIComponent(articulo)}/${encodeURIComponent(cliente)}`
    )
    mensaje.value = 'Oferta eliminada'
    await cargar()
    if (vista.value === 'ficha') volverAlGrid()
  } catch (e: unknown) {
    mensaje.value = extractApiError(e, 'No se pudo eliminar')
  }
}

function validarObligatorios(): boolean {
  if (!String(form.articulo).trim()) {
    mostrarAviso('articulo', 'El articulo es obligatorio.')
    return false
  }
  if (!String(form.cliente).trim()) {
    mostrarAviso('cliente', 'El cliente es obligatorio.')
    return false
  }
  return true
}

async function onGuardar() {
  if (!puedeGuardar.value) return
  if (!validarObligatorios()) return
  saving.value = true
  mensaje.value = null
  try {
    const payload = { ...form }
    if (esNuevo.value) {
      const { data } = await api.post('/api/mantenimiento/oferta-clientes', payload)
      mensaje.value = 'Oferta creada'
      await cargar()
      const idx = filas.value.findIndex(
        (i) => i.articulo === data.articulo && i.cliente === data.cliente
      )
      if (idx >= 0) await abrirFicha(idx)
      else volverAlGrid()
    } else {
      await api.put(
        `/api/mantenimiento/oferta-clientes/${encodeURIComponent(form.articulo)}/${encodeURIComponent(form.cliente)}`,
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

async function cargarPreciosArticulo(codigo: string) {
  if (!codigo.trim()) {
    preciosArticulo.value = Array.from({ length: 9 }, () => 0)
    costeArticulo.value = 0
    ivaPct.value = 0
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/articulos/${encodeURIComponent(codigo)}`)
    articuloDescripcion.value = String(data.descripcion ?? data.nombre ?? articuloDescripcion.value)
    preciosArticulo.value = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => {
      const v = data[`precioVen${i}`] ?? (i === 1 ? data.precioVenta : 0)
      return Number(v) || 0
    })
    costeArticulo.value = Number(data.precioBase ?? 0) || 0
    const impCodigo = String(data.impuestoCodigo ?? '').trim()
    if (impCodigo) {
      try {
        const imp = await api.get(`/api/mantenimiento/impuestos/${encodeURIComponent(impCodigo)}`)
        ivaPct.value = Number(imp.data.porcentajeIVA ?? 0) || 0
      } catch {
        ivaPct.value = 0
      }
    } else {
      ivaPct.value = 0
    }
  } catch {
    /* keep previous description if resolve fails */
  }
}

async function resolverArticulo() {
  const codigo = String(form.articulo ?? '').trim()
  if (!codigo) {
    articuloDescripcion.value = ''
    preciosArticulo.value = Array.from({ length: 9 }, () => 0)
    costeArticulo.value = 0
    ivaPct.value = 0
    return
  }
  await cargarPreciosArticulo(codigo)
}

async function resolverCliente() {
  const codigo = String(form.cliente ?? '').trim()
  if (!codigo) {
    clienteNombre.value = ''
    return
  }
  try {
    const { data } = await api.get(`/api/mantenimiento/clientes/${encodeURIComponent(codigo)}`)
    clienteNombre.value = String(data.nombre ?? data.razonSocial ?? '')
  } catch {
    clienteNombre.value = ''
  }
}

function onArticuloSeleccionado(resultado: EntidadBuscarResultado) {
  form.articulo = resultado.codigo
  articuloDescripcion.value = resultado.etiqueta
  void cargarPreciosArticulo(resultado.codigo)
}

function onClienteSeleccionado(resultado: EntidadBuscarResultado) {
  form.cliente = resultado.codigo
  clienteNombre.value = resultado.etiqueta
}

function abrirBuscarEmpresa(slot: number) {
  if (soloLectura.value) {
    if (!puedeEditar.value || esNuevo.value) return
    modoEdicion.value = true
  }
  empresaSlot.value = slot
  buscarEmpresaOpen.value = true
}

function onEmpresaSeleccionada(resultado: EntidadBuscarResultado) {
  const key = `bloquearOfeEmpresa${empresaSlot.value}` as keyof typeof form
  ;(form as Record<string, unknown>)[key] = resultado.codigo
  buscarEmpresaOpen.value = false
}

function empModel(key: keyof typeof form): string {
  const v = form[key]
  return v == null ? '' : String(v)
}

function setEmp(key: keyof typeof form, raw: string) {
  ;(form as Record<string, unknown>)[key] = raw.trim()
}

function fmtNum(n: number): string {
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 4 })
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
        <div class="listado-panel">
          <div class="toolbar">
            <button type="button" class="tool-btn" @click="onListado">Listado</button>
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
            :filterable-keys="FILTER_KEYS"
            v-model:filters="filtros"
            @seleccionar="seleccionar"
            @abrir="abrirFicha"
            @nuevo="onNuevo"
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
                    data-field-key="articulo"
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
              Cliente
              <div class="con-desc">
                <div class="codigo-buscar">
                  <input
                    v-model="form.cliente"
                    data-field-key="cliente"
                    maxlength="9"
                    :readonly="soloLectura || !esNuevo"
                    @blur="resolverCliente"
                  />
                  <button
                    v-if="esNuevo"
                    type="button"
                    class="btn-lupa"
                    title="Buscar cliente"
                    @click="buscarClienteOpen = true"
                  >
                    <ToolIcon name="buscar" />
                  </button>
                </div>
                <span class="desc">{{ clienteNombre }}</span>
              </div>
            </label>
            <label class="precio-base">
              Precio
              <DecimalInput
                v-model="form.precio"
                :empty-as-null="true"
                :readonly="soloLectura"
              />
            </label>
          </div>

          <div class="bloques">
            <fieldset class="bloque escalados-box">
              <legend>Escalados</legend>
              <label class="check">
                <input v-model="form.rappelPorDto" type="checkbox" :disabled="soloLectura" />
                por descuento
              </label>
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
                      <DecimalInput
                        :model-value="((form as Record<string, unknown>)[row.cantidadKey] as number | null) ?? null"
                        :empty-as-null="false"
                        :readonly="soloLectura"
                        @update:model-value="(form as Record<string, unknown>)[row.cantidadKey] = $event ?? 0"
                      />
                    </td>
                    <td>
                      <DecimalInput
                        :model-value="((form as Record<string, unknown>)[row.precioKey] as number | null) ?? null"
                        :empty-as-null="false"
                        :readonly="soloLectura"
                        @update:model-value="(form as Record<string, unknown>)[row.precioKey] = $event ?? 0"
                      />
                    </td>
                  </tr>
                </tbody>
              </table>
            </fieldset>

            <fieldset class="bloque precios-ref">
              <legend>Precios articulo</legend>
              <table class="ref-table">
                <thead>
                  <tr>
                    <th>T</th>
                    <th>Precio</th>
                    <th>Sin Iva</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in tablaPrecios" :key="row.t">
                    <td>{{ row.t }}</td>
                    <td class="num">{{ fmtNum(row.precio) }}</td>
                    <td class="num">{{ fmtNum(row.sinIva) }}</td>
                  </tr>
                </tbody>
              </table>
            </fieldset>
          </div>

          <fieldset class="bloque bloque-empresas">
            <legend>Excluir la aplicacion en las siguientes Empresas:</legend>
            <div class="empresas-grid">
              <div v-for="emp in empresas" :key="emp.i" class="emp-slot">
                <input
                  :value="empModel(emp.key)"
                  class="emp-codigo"
                  size="3"
                  maxlength="3"
                  :readonly="soloLectura"
                  @input="setEmp(emp.key, ($event.target as HTMLInputElement).value)"
                />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar empresa"
                  :disabled="soloLectura && !puedeEditar"
                  @click="abrirBuscarEmpresa(emp.i)"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
            </div>
          </fieldset>
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
        :open="buscarClienteOpen"
        entidad="clientes"
        titulo="Buscar cliente"
        @seleccionar="onClienteSeleccionado"
        @cerrar="buscarClienteOpen = false"
      />
      <EntidadBuscarModal
        :open="buscarEmpresaOpen"
        entidad="tiendas"
        titulo="Empresas"
        @seleccionar="onEmpresaSeleccionada"
        @cerrar="buscarEmpresaOpen = false"
      />

      <ConfirmDialog
        :open="confirmOpen"
        title="Eliminar oferta"
        :message="confirmMessage"
        @confirm="confirmarEliminar"
        @cancel="confirmOpen = false"
      />
      <ConfirmDialog
        :open="avisoOpen"
        title="Campo obligatorio"
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
.ofertas-view h2 {
  margin: 0 0 0.75rem;
}

.listado-panel {
  width: 100%;
  max-width: 100%;
  box-sizing: border-box;
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

.ficha-panel {
  width: fit-content;
  max-width: 100%;
  box-sizing: border-box;
}

.sticky-chrome {
  width: 100%;
  box-sizing: border-box;
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

.codigo-buscar,
.emp-slot {
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

.precio-base input {
  width: 8rem;
}

.bloques {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 0.65rem;
  margin-bottom: 0.65rem;
}

.bloque {
  margin: 0;
  padding: 0.45rem 0.55rem 0.55rem;
  border: 1px solid #c5cdd8;
  border-radius: 4px;
  background: #fff;
}

.bloque legend {
  padding: 0 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
}

.escalados-box {
  flex: 0 0 auto;
  width: 14rem;
}

.precios-ref {
  flex: 0 0 auto;
  width: 12.5rem;
}

.check {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  margin-bottom: 0.35rem;
}

.escalados,
.ref-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.75rem;
}

.escalados th,
.escalados td,
.ref-table th,
.ref-table td {
  border: 1px solid #cbd5e1;
  padding: 0.15rem 0.25rem;
}

.escalados th,
.ref-table th {
  background: #f1f5f9;
  text-align: center;
  color: #475569;
}

.escalados input {
  width: 100%;
  border: none;
  padding: 0.25rem 0.35rem;
  text-align: right;
  box-sizing: border-box;
}

.ref-table .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.bloque-empresas {
  width: fit-content;
  max-width: 100%;
  box-sizing: border-box;
  padding: 0.4rem 0.5rem 0.5rem;
}

.empresas-grid {
  display: grid;
  grid-template-columns: repeat(5, auto);
  gap: 0.3rem 0.4rem;
  justify-content: start;
}

.emp-slot {
  display: inline-flex;
  gap: 0.2rem;
  align-items: stretch;
  flex: 0 0 auto;
  width: auto;
}

.emp-slot input.emp-codigo,
.ficha input.emp-codigo {
  flex: 0 0 auto;
  width: 3ch;
  min-width: 3ch;
  max-width: 3ch;
  padding: 0.2rem 0.3rem;
  box-sizing: content-box;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.emp-slot .btn-lupa {
  flex-shrink: 0;
  width: 1.55rem;
  height: auto;
  align-self: stretch;
}

.emp-slot .btn-lupa:disabled {
  opacity: 0.45;
  cursor: not-allowed;
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
