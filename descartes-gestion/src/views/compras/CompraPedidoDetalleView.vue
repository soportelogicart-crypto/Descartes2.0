<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import {
  actualizarPedidoProveedor,
  crearPedidoProveedor,
  obtenerPedidoProveedor,
  recibirPedidoProveedor,
} from '@/api/compras'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import type {
  PedidoProveedorDetalle,
  PedidoProveedorLinea,
  PedidoProveedorPayload,
} from '@/types/compras'
import { extractApiError } from '@/composables/extractApiError'
import {
  imprimirA4CompraPreparado,
  prepararImpresionPedidoProveedor,
  type PrepImpresionA4,
} from '@/composables/useImpresionCompraDocumento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import PedidoRecepcionModal from '@/components/compras/PedidoRecepcionModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const route = useRoute()
const router = useRouter()
const { puede } = usePermisos()
const puesto = usePuestoContextoStore()

const pathInstancia = route.fullPath

function esEstaInstanciaActiva(): boolean {
  return route.fullPath === pathInstancia
}

const puedeCrear = computed(() => puede('compras', 'crear'))
const puedeEditar = computed(() => puede('compras', 'editar'))

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ficha = ref<PedidoProveedorDetalle | null>(null)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const buscarProveedorOpen = ref(false)
const buscarArticuloOpen = ref(false)
const recepcionOpen = ref(false)
const recibiendo = ref(false)
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const a4Imprimiendo = ref(false)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
const tiendas = ref<{ value: string; label: string }[]>([])
const almacenes = ref<{ value: number; label: string }[]>([])

type FormLinea = {
  numLin?: number
  articulo: string
  descripcion: string
  cantidadPed: number
  cantidadSer: number
  precioPed: number
  pjeDto: number
  almacen: number | null
}

const form = ref({
  empresa: '',
  fechaPedido: new Date().toISOString().slice(0, 10),
  fechaMaxRecepcion: '',
  proveedor: '',
  razonSocial: '',
  vendedor: '',
  almacen: null as number | null,
  observaciones: '',
  observInternas: '',
  lineas: [] as FormLinea[],
})

const titulo = computed(() => {
  if (esNuevo.value) return 'Nuevo pedido a proveedor'
  if (!ficha.value) return 'Pedido a proveedor'
  return `Pedido ${ficha.value.empresa}-${ficha.value.pedido}`
})

const bloqueado = computed(() => {
  if (esNuevo.value) return false
  if (!ficha.value) return true
  return ficha.value.editable === false || ficha.value.situacionLabel === 'servido'
})

const soloLecturaMotivo = computed(() => {
  if (!ficha.value || esNuevo.value) return null
  if (ficha.value.situacionLabel === 'servido') return 'Pedido completamente servido'
  if (ficha.value.editable === false) return 'Documento no editable'
  return null
})

const camposEditables = computed(() => modoEdicion.value && !bloqueado.value)

const situacionBadge = computed(() => {
  const label = ficha.value?.situacionLabel || 'pendiente'
  if (label === 'servido') return { text: 'Servido', cls: 'sit-servido' }
  if (label === 'parcial') return { text: 'Parcial', cls: 'sit-parcial' }
  return { text: 'Pendiente', cls: 'sit-pendiente' }
})

const puedeRecibir = computed(
  () =>
    puedeEditar.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    ficha.value.situacionLabel !== 'servido'
)

const puedeImprimir = computed(() => !!ficha.value && !esNuevo.value)

function lineaVacia(): FormLinea {
  return {
    articulo: '',
    descripcion: '',
    cantidadPed: 0,
    cantidadSer: 0,
    precioPed: 0,
    pjeDto: 0,
    almacen: form.value.almacen,
  }
}

function fmtFecha(iso: string | null | undefined) {
  if (!iso) return ''
  return iso.slice(0, 10)
}

function fmtNum(n: number | null | undefined, dec = 2) {
  return Number(n ?? 0).toFixed(dec)
}

function esRutaNuevo(): boolean {
  if (route.name === 'compras-pedido-nuevo') return true
  return (route.path || '').replace(/\/+$/, '').endsWith('/compras/pedidos/nuevo')
}

function aplicarFicha(data: PedidoProveedorDetalle) {
  ficha.value = data
  form.value = {
    empresa: data.empresa,
    fechaPedido: fmtFecha(data.fechaPedido) || new Date().toISOString().slice(0, 10),
    fechaMaxRecepcion: fmtFecha(data.fechaMaxRecepcion),
    proveedor: data.proveedor || '',
    razonSocial: data.razonSocial || '',
    vendedor: data.vendedor || '',
    almacen: data.almacen ?? null,
    observaciones: data.observaciones || '',
    observInternas: data.observInternas || '',
    lineas: (data.lineas?.length ? data.lineas : [lineaVacia()]).map((l) => ({
      numLin: l.numLin,
      articulo: l.articulo || '',
      descripcion: l.descripcion || '',
      cantidadPed: Number(l.cantidadPed ?? 0),
      cantidadSer: Number(l.cantidadSer ?? 0),
      precioPed: Number(l.precioPed ?? 0),
      pjeDto: Number(l.pjeDto ?? 0),
      almacen: l.almacen ?? data.almacen ?? null,
    })),
  }
}

async function cargarTiendas() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', {
      params: { activo: true, pageSize: 200 },
    })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => ({
      value: String(t.codigo).trim(),
      label: `${String(t.codigo).trim()} - ${t.nombre}`,
    }))
  } catch {
    tiendas.value = []
  }
}

async function cargarAlmacenes() {
  try {
    const { data } = await api.get('/api/mantenimiento/almacenes', {
      params: { activo: true, pageSize: 200 },
    })
    almacenes.value = (data.items ?? []).map(
      (a: { codigo: string | number; nombre?: string }) => {
        const n = Number(a.codigo)
        return {
          value: n,
          label: a.nombre ? `${n} - ${a.nombre}` : String(n),
        }
      }
    )
  } catch {
    almacenes.value = []
  }
}

async function cargar() {
  if (!esEstaInstanciaActiva()) return
  if (esRutaNuevo()) {
    await iniciarNuevo()
    return
  }

  const empresa = String(route.params.empresa ?? '').trim()
  const pedido = Number(route.params.pedido ?? 0)
  if (!empresa || !Number.isFinite(pedido) || pedido <= 0) {
    error.value = 'Pedido no válido'
    ficha.value = null
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarFicha(await obtenerPedidoProveedor(empresa, pedido))
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    ficha.value = null
    error.value = extractApiError(e, 'No se pudo cargar el pedido a proveedor')
  } finally {
    loading.value = false
  }
}

async function iniciarNuevo() {
  esNuevo.value = true
  modoEdicion.value = true
  ficha.value = null
  error.value = null
  mensaje.value = null
  const empresa = puesto.empresaCodigo || tiendas.value[0]?.value || ''
  form.value = {
    empresa,
    fechaPedido: new Date().toISOString().slice(0, 10),
    fechaMaxRecepcion: '',
    proveedor: '',
    razonSocial: '',
    vendedor: '',
    almacen: null,
    observaciones: '',
    observInternas: '',
    lineas: [lineaVacia()],
  }
}

function volverListado() {
  router.push({ name: 'compras-pedidos' })
}

function onNuevo() {
  if (!puedeCrear.value) return
  router.push({ name: 'compras-pedido-nuevo' })
}

function onModificar() {
  if (!puedeEditar.value || bloqueado.value || !ficha.value) return
  modoEdicion.value = true
  mensaje.value = null
}

async function onCancelar() {
  if (esNuevo.value) {
    volverListado()
    return
  }
  modoEdicion.value = false
  await cargar()
}

function buildPayload(): PedidoProveedorPayload {
  const lineas: PedidoProveedorLinea[] = form.value.lineas
    .filter((l) => l.articulo.trim() !== '')
    .map((l) => ({
      numLin: l.numLin,
      articulo: l.articulo.trim(),
      descripcion: l.descripcion.trim() || null,
      cantidadPed: Number(l.cantidadPed) || 0,
      cantidadSer: Number(l.cantidadSer) || 0,
      precioPed: Number(l.precioPed) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      almacen: l.almacen,
    }))
  return {
    empresa: form.value.empresa.trim(),
    fechaPedido: form.value.fechaPedido || null,
    fechaMaxRecepcion: form.value.fechaMaxRecepcion || null,
    proveedor: form.value.proveedor.trim() || null,
    vendedor: form.value.vendedor.trim() || null,
    observaciones: form.value.observaciones.trim() || null,
    observInternas: form.value.observInternas.trim() || null,
    almacen: form.value.almacen,
    lineas,
  }
}

async function onGuardar() {
  if (!camposEditables.value) return
  const payload = buildPayload()
  if (!payload.empresa) {
    error.value = 'Seleccione tienda'
    return
  }
  if (!payload.proveedor) {
    error.value = 'Indique proveedor'
    return
  }
  if (!payload.lineas.length) {
    error.value = 'Añada al menos una línea con artículo'
    return
  }

  saving.value = true
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    if (esNuevo.value) {
      const created = await crearPedidoProveedor(payload)
      mensaje.value = 'Pedido creado'
      await router.replace({
        name: 'compras-pedido-detalle',
        params: { empresa: created.empresa, pedido: String(created.pedido) },
      })
      if (esEstaInstanciaActiva()) {
        aplicarFicha(created)
        modoEdicion.value = false
        esNuevo.value = false
      }
    } else if (ficha.value) {
      const updated = await actualizarPedidoProveedor(
        ficha.value.empresa,
        ficha.value.pedido,
        payload
      )
      aplicarFicha(updated)
      modoEdicion.value = false
      mensaje.value = 'Pedido guardado'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el pedido')
  } finally {
    saving.value = false
    loading.value = false
  }
}

function abrirBuscarProveedor() {
  if (!camposEditables.value) return
  buscarProveedorOpen.value = true
}

function onProveedorSeleccionado(r: EntidadBuscarResultado) {
  form.value.proveedor = r.codigo
  form.value.razonSocial = r.etiqueta.replace(/^\s*\S+\s*[-–]\s*/, '') || r.etiqueta
  buscarProveedorOpen.value = false
}

function abrirBuscarArticulo(idx: number) {
  if (!camposEditables.value) return
  lineaArticuloIdx.value = idx
  articuloBusquedaInicial.value = form.value.lineas[idx]?.articulo ?? ''
  buscarArticuloOpen.value = true
}

async function onArticuloSeleccionado(r: EntidadBuscarResultado) {
  const idx = lineaArticuloIdx.value
  const linea = form.value.lineas[idx]
  if (!linea) return
  linea.articulo = r.codigo
  const parts = r.etiqueta.split(/\s*[-–]\s*/)
  linea.descripcion = parts.length > 1 ? parts.slice(1).join(' - ').trim() : r.etiqueta
  buscarArticuloOpen.value = false
  if (idx === form.value.lineas.length - 1) {
    form.value.lineas.push(lineaVacia())
  }
  await nextTick()
}

async function aplicarArticuloResuelto(idx: number, art: Awaited<ReturnType<typeof resolverArticulo>>) {
  const linea = form.value.lineas[idx]
  if (!linea) return
  linea.articulo = art.codigo
  linea.descripcion = String(art.descripcion ?? '').trim()
  const precio = Number(art.precioUltimo ?? art.precioMedio ?? art.precioVen1 ?? 0)
  if (precio > 0 && !linea.precioPed) linea.precioPed = precio
  const uds = Number(art.unidadesPaquete)
  if (uds > 0 && (!linea.cantidadPed || linea.cantidadPed === 1)) {
    linea.cantidadPed = uds
  } else if (!linea.cantidadPed) {
    linea.cantidadPed = 1
  }
  if (idx === form.value.lineas.length - 1) {
    form.value.lineas.push(lineaVacia())
  }
  await nextTick()
  const next = document.querySelector<HTMLInputElement>(
    `tr:nth-child(${idx + 2}) .col-art input`
  )
  next?.focus()
  next?.select()
}

async function onArticuloKeydown(e: KeyboardEvent, idx: number) {
  if (!camposEditables.value) return
  if (e.key === 'F4') {
    e.preventDefault()
    barcodeWatcher.cancel()
    abrirBuscarArticulo(idx)
    return
  }
  if (e.key !== 'Enter') return
  e.preventDefault()
  barcodeWatcher.cancel()
  await resolverArticuloEnLinea(idx, String(form.value.lineas[idx]?.articulo ?? ''))
}

let barcodeLineaIdx = 0
const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  if (!camposEditables.value) return
  await resolverArticuloEnLinea(barcodeLineaIdx, codigo)
})

async function resolverArticuloEnLinea(idx: number, q: string) {
  const codigo = String(q ?? '').trim()
  if (!codigo) {
    abrirBuscarArticulo(idx)
    return
  }
  error.value = null
  try {
    const art = await resolverArticulo(codigo)
    await aplicarArticuloResuelto(idx, art)
  } catch (err: unknown) {
    error.value = extractApiError(err, 'Artículo no encontrado')
    abrirBuscarArticulo(idx)
  }
}

function onArticuloInput(idx: number) {
  if (!camposEditables.value) return
  barcodeLineaIdx = idx
  barcodeWatcher.onInput(String(form.value.lineas[idx]?.articulo ?? ''))
}

function quitarLinea(idx: number) {
  if (!camposEditables.value) return
  const linea = form.value.lineas[idx]
  if (linea && Number(linea.cantidadSer) > 0) {
    error.value = `No se puede quitar la línea ${linea.articulo}: ya tiene cantidad servida`
    return
  }
  if (form.value.lineas.length <= 1) {
    form.value.lineas = [lineaVacia()]
    return
  }
  form.value.lineas.splice(idx, 1)
}

function abrirRecepcion() {
  if (!puedeRecibir.value) return
  error.value = null
  mensaje.value = null
  recepcionOpen.value = true
}

async function onRecepcionConfirmar(payload: {
  fechaAlbaran: string
  suAlbaran: string
  almacen: number | null
  lineas: { numLin: number; cantidad: number }[]
}) {
  if (!ficha.value) return
  recibiendo.value = true
  error.value = null
  mensaje.value = null
  try {
    const result = await recibirPedidoProveedor(ficha.value.empresa, ficha.value.pedido, {
      fechaAlbaran: payload.fechaAlbaran || null,
      suAlbaran: payload.suAlbaran || null,
      almacen: payload.almacen,
      lineas: payload.lineas,
    })
    recepcionOpen.value = false
    aplicarFicha(result.pedido)
    mensaje.value = `Albarán ${result.albaran.empresa}-${result.albaran.albaran} creado`
    await router.push({
      name: 'compras-albaran-detalle',
      params: {
        empresa: result.albaran.empresa,
        albaran: String(result.albaran.albaran),
      },
    })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo recibir el pedido')
  } finally {
    recibiendo.value = false
  }
}

async function onImprimir() {
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  loading.value = true
  try {
    a4Prep.value = await prepararImpresionPedidoProveedor(ficha.value, {
      puestoCodigo: String(puesto.puestoCodigo || ''),
    })
    a4Open.value = true
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo preparar la impresión A4')
  } finally {
    loading.value = false
  }
}

async function onImprimirA4Confirmado() {
  if (!a4Prep.value) return
  a4Imprimiendo.value = true
  error.value = null
  try {
    const html = (await a4ModalRef.value?.capturarHtmlFolio()) || ''
    mensaje.value = await imprimirA4CompraPreparado(a4Prep.value, html)
    a4Open.value = false
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir el pedido a proveedor')
  } finally {
    a4Imprimiendo.value = false
  }
}

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarAlmacenes()])
  await cargar()
})

watch(
  () => [route.params.empresa, route.params.pedido, route.name] as const,
  () => {
    if (!esEstaInstanciaActiva()) return
    void cargar()
  }
)
</script>

<template>
  <section class="pedido-detalle">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar && !!ficha && !bloqueado"
      :puede-eliminar="false"
      :puede-guardar="camposEditables"
      :puede-imprimir="puedeImprimir"
      :puede-finalizar="false"
      :puede-abonar="false"
      :puede-navegar="false"
      :modo-edicion="modoEdicion"
      :bloqueado="bloqueado"
      :hay-documento="!!ficha || esNuevo"
      :loading="loading || saving"
      :indice="-1"
      :total="0"
      @nuevo="onNuevo"
      @modificar="onModificar"
      @buscar="volverListado"
      @guardar="onGuardar"
      @cancelar="onCancelar"
      @imprimir="onImprimir"
    />

    <div class="head">
      <div>
        <h2>{{ titulo }}</h2>
        <p class="hint">
          {{
            esNuevo
              ? 'Complete cabecera y líneas; luego Guardar.'
              : 'Cant. pedida / servida. Modificar si el pedido no está servido.'
          }}
        </p>
      </div>
      <p v-if="soloLecturaMotivo" class="badge-bloqueo">Solo lectura — {{ soloLecturaMotivo }}</p>
      <p v-else-if="modoEdicion" class="badge-ok">Editando</p>
      <span v-if="ficha && !esNuevo" class="badge-sit" :class="situacionBadge.cls">
        {{ situacionBadge.text }}
      </span>
      <button
        v-if="puedeRecibir"
        type="button"
        class="btn-recibir"
        :disabled="loading || recibiendo"
        title="Generar albarán de compra desde cantidades a recibir"
        @click="abrirRecepcion"
      >
        Recibir
      </button>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    <p v-if="loading && !ficha && !esNuevo" class="msg">Cargando...</p>

    <template v-if="ficha || esNuevo">
      <div class="panel cabecera">
        <h3>Cabecera</h3>
        <div class="grid-cab">
          <label>
            Tienda
            <select
              v-if="esNuevo && camposEditables"
              v-model="form.empresa"
              title="Tienda"
            >
              <option value="">—</option>
              <option v-for="t in tiendas" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <input v-else :value="form.empresa" readonly />
          </label>
          <label>
            Pedido
            <input :value="esNuevo ? '(nuevo)' : ficha?.pedido" readonly />
          </label>
          <label>
            Fecha
            <input v-model="form.fechaPedido" type="date" :readonly="!camposEditables" />
          </label>
          <label>
            Fecha máx. recepción
            <input
              v-model="form.fechaMaxRecepcion"
              type="date"
              :readonly="!camposEditables"
            />
          </label>
          <label class="proveedor-field">
            Proveedor
            <div class="con-lupa">
              <input
                v-model="form.proveedor"
                :readonly="!camposEditables"
                @keydown.f4.prevent="abrirBuscarProveedor"
              />
              <button
                type="button"
                class="btn-lupa"
                :disabled="!camposEditables"
                title="Buscar proveedor"
                @click="abrirBuscarProveedor"
              >
                <ToolIcon name="buscar" />
              </button>
            </div>
          </label>
          <label class="span-2">
            Razón social
            <input v-model="form.razonSocial" :readonly="!camposEditables" />
          </label>
          <label>
            Vendedor
            <input v-model="form.vendedor" maxlength="4" :readonly="!camposEditables" />
          </label>
          <label>
            Almacén
            <select
              v-if="camposEditables"
              v-model.number="form.almacen"
              title="Almacén"
            >
              <option :value="null">—</option>
              <option v-for="a in almacenes" :key="a.value" :value="a.value">{{ a.label }}</option>
            </select>
            <input v-else :value="form.almacen ?? ''" readonly />
          </label>
          <label v-if="ficha">
            Importe
            <input class="num" :value="fmtNum(ficha.importe)" readonly />
          </label>
          <label class="span-2">
            Observaciones
            <textarea v-model="form.observaciones" rows="2" :readonly="!camposEditables" />
          </label>
          <label class="span-2">
            Obs. internas
            <textarea v-model="form.observInternas" rows="2" :readonly="!camposEditables" />
          </label>
        </div>
      </div>

      <div class="panel lineas">
        <div class="lineas-head">
          <h3>Líneas</h3>
          <button
            v-if="camposEditables"
            type="button"
            class="btn-add"
            @click="form.lineas.push(lineaVacia())"
          >
            + Línea
          </button>
        </div>
        <div class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="col-n">#</th>
                <th class="col-art">Artículo</th>
                <th>Descripción</th>
                <th class="num col-q">Pedida</th>
                <th class="num col-q">Servida</th>
                <th class="num col-p">Precio</th>
                <th class="num col-d">% Dto</th>
                <th v-if="camposEditables" class="col-act" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, idx) in form.lineas" :key="l.numLin ?? `n-${idx}`">
                <td class="col-n">{{ l.numLin ?? idx + 1 }}</td>
                <td class="col-art">
                  <div v-if="camposEditables && l.cantidadSer <= 0" class="con-lupa">
                    <input
                      v-model="l.articulo"
                      @input="onArticuloInput(idx)"
                      @keydown="onArticuloKeydown($event, idx)"
                    />
                    <button
                      type="button"
                      class="btn-lupa"
                      title="Buscar artículo"
                      @click="abrirBuscarArticulo(idx)"
                    >
                      <ToolIcon name="buscar" />
                    </button>
                  </div>
                  <span v-else>{{ l.articulo || '—' }}</span>
                </td>
                <td>
                  <input
                    v-if="camposEditables"
                    v-model="l.descripcion"
                    class="desc-input"
                  />
                  <span v-else>{{ l.descripcion || '—' }}</span>
                </td>
                <td class="num col-q">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.cantidadPed"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.cantidadPed) }}</span>
                </td>
                <td class="num col-q">
                  <span :class="{ 'ser-parcial': l.cantidadSer > 0 && l.cantidadSer < l.cantidadPed }">
                    {{ fmtNum(l.cantidadSer) }}
                  </span>
                </td>
                <td class="num col-p">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.precioPed"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.precioPed) }}</span>
                </td>
                <td class="num col-d">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.pjeDto"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.pjeDto) }}</span>
                </td>
                <td v-if="camposEditables" class="col-act">
                  <button
                    type="button"
                    class="btn-del"
                    title="Quitar"
                    :disabled="l.cantidadSer > 0"
                    @click="quitarLinea(idx)"
                  >
                    ×
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <EntidadBuscarModal
      :open="buscarProveedorOpen"
      entidad="proveedores"
      titulo="Buscar proveedor"
      :busqueda-inicial="form.proveedor"
      @seleccionar="onProveedorSeleccionado"
      @cerrar="buscarProveedorOpen = false"
    />
    <EntidadBuscarModal
      :open="buscarArticuloOpen"
      entidad="articulos"
      titulo="Buscar artículo"
      :busqueda-inicial="articuloBusquedaInicial"
      @seleccionar="onArticuloSeleccionado"
      @cerrar="buscarArticuloOpen = false"
    />
    <PedidoRecepcionModal
      :open="recepcionOpen"
      :pedido="ficha"
      :loading="recibiendo"
      @confirmar="onRecepcionConfirmar"
      @cancelar="recepcionOpen = false"
    />
    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Pedido a proveedor'"
      :plantilla="a4Prep?.plantilla ?? null"
      :datos="a4Prep?.datos ?? null"
      :impresora-nombre="a4Prep?.impresoraNombre || ''"
      :imprimiendo="a4Imprimiendo"
      @cerrar="a4Open = false"
      @imprimir="onImprimirA4Confirmado"
    />
  </section>
</template>

<style scoped>
.pedido-detalle h2 {
  margin: 0 0 0.25rem;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}
.hint {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}
.badge-bloqueo {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  background: #fef3c7;
  color: #92400e;
  font-size: 0.8rem;
  font-weight: 600;
}
.badge-ok {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  background: #dcfce7;
  color: #166534;
  font-size: 0.8rem;
  font-weight: 600;
}
.badge-sit {
  margin: 0;
  padding: 0.35rem 0.65rem;
  border-radius: 6px;
  font-size: 0.8rem;
  font-weight: 600;
}
.sit-pendiente {
  background: #e2e8f0;
  color: #334155;
}
.sit-parcial {
  background: #fef3c7;
  color: #92400e;
}
.sit-servido {
  background: #dcfce7;
  color: #166534;
}
.btn-recibir {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #b45309;
  background: #f59e0b;
  color: #1c1917;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
}
.btn-recibir:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
.error {
  color: #b91c1c;
}
.ok {
  color: #166534;
}
.msg {
  color: #475569;
}
.ser-parcial {
  color: #b45309;
  font-weight: 600;
}

.panel {
  margin-bottom: 0.85rem;
  padding: 0.75rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
}
.panel h3 {
  margin: 0 0 0.55rem;
  font-size: 0.9rem;
  color: #0f172a;
}
.lineas-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 0.45rem;
}
.lineas-head h3 {
  margin: 0;
}
.btn-add {
  padding: 0.25rem 0.55rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #f8fafc;
  cursor: pointer;
  font-size: 0.8rem;
}

.grid-cab {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.45rem 0.65rem;
}
.grid-cab label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.72rem;
  color: #475569;
}
.grid-cab input,
.grid-cab textarea,
.grid-cab select,
.desc-input,
td input {
  padding: 0.3rem 0.4rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font: inherit;
  background: #fff;
  color: #0f172a;
  width: 100%;
  box-sizing: border-box;
}
.grid-cab input:read-only,
.grid-cab textarea:read-only {
  background: #f8fafc;
}
.grid-cab input.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.grid-cab .span-2 {
  grid-column: span 2;
}

.con-lupa {
  display: flex;
  gap: 0.25rem;
  align-items: stretch;
  min-width: 0;
}
.con-lupa input {
  flex: 1;
  min-width: 0;
}
.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2rem;
  flex-shrink: 0;
  border: 1px solid #64748b;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  padding: 0;
}
.btn-lupa:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn-lupa :deep(.tool-icon) {
  width: 1rem;
  height: 1rem;
}

.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  max-height: min(50vh, 28rem);
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  border-bottom: 1px solid #e2e8f0;
  padding: 0.3rem 0.35rem;
  text-align: left;
  vertical-align: middle;
}
th {
  background: #f1f5f9;
  position: sticky;
  top: 0;
  z-index: 1;
  white-space: nowrap;
}
.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.col-n {
  width: 2.5rem;
}
.col-art {
  width: 9rem;
}
.col-q,
.col-p,
.col-d {
  width: 5.5rem;
}
.col-act {
  width: 2rem;
}
.btn-del {
  border: none;
  background: transparent;
  color: #b91c1c;
  font-size: 1.1rem;
  cursor: pointer;
  line-height: 1;
}
.btn-del:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

@media (max-width: 900px) {
  .grid-cab {
    grid-template-columns: 1fr 1fr;
  }
  .grid-cab .span-2 {
    grid-column: 1 / -1;
  }
}
</style>
