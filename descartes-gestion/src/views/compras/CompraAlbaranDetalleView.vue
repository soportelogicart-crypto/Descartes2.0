<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { api } from '@/api/client'
import {
  actualizarAlbaranCompra,
  actualizarStockAlbaranCompra,
  crearAlbaranCompra,
  eliminarAlbaranCompra,
  obtenerAlbaranCompra,
} from '@/api/compras'
import { encolarDesdeAlbaranCompra } from '@/api/etiquetas'
import { resolverArticulo } from '@/api/articulos'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import type { AlbaranCompraDetalle, AlbaranCompraLinea, AlbaranCompraPayload } from '@/types/compras'
import { extractApiError } from '@/composables/extractApiError'
import {
  imprimirA4CompraPreparado,
  prepararImpresionAlbaranCompra,
  type PrepImpresionA4,
} from '@/composables/useImpresionCompraDocumento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import VentaToolbar from '@/components/ventas/VentaToolbar.vue'
import VentaImpresionA4Modal from '@/components/ventas/VentaImpresionA4Modal.vue'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import DecimalInput from '@/components/common/DecimalInput.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
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
const puedeEliminar = computed(() => puede('compras', 'eliminar'))
/** Generar cola de etiquetas desde albarán (005 / US5). */
const puedeGenerarEtiquetas = computed(
  () =>
    puede('etiquetas', 'crear') &&
    !!ficha.value &&
    !esNuevo.value &&
    (ficha.value.lineas?.some((l) => String(l.articulo ?? '').trim()) ?? false)
)

const loading = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const ficha = ref<AlbaranCompraDetalle | null>(null)
const modoEdicion = ref(false)
const esNuevo = ref(false)
const confirmBorrar = ref(false)
const confirmStock = ref(false)
const generandoEtiquetas = ref(false)
const a4Open = ref(false)
const a4Prep = ref<PrepImpresionA4 | null>(null)
const a4Imprimiendo = ref(false)
const a4ModalRef = ref<{ capturarHtmlFolio: () => Promise<string> } | null>(null)
const buscarProveedorOpen = ref(false)
const buscarArticuloOpen = ref(false)
const lineaArticuloIdx = ref(0)
const articuloBusquedaInicial = ref('')
const tiendas = ref<{ value: string; label: string }[]>([])
const almacenes = ref<{ value: number; label: string }[]>([])

type FormLinea = {
  nroLin?: number
  articulo: string
  descripcion: string
  cantidad: number
  precio: number
  pjeDto: number
  lote: string
  almacen: number | null
  pedido: number | null
}

const form = ref({
  empresa: '',
  fechaAlbaran: new Date().toISOString().slice(0, 10),
  suAlbaran: '',
  proveedor: '',
  razonSocial: '',
  fpago: '',
  almacen: null as number | null,
  serie: '',
  albaranDevolucion: false,
  observaciones: '',
  lineas: [] as FormLinea[],
})

const titulo = computed(() => {
  if (esNuevo.value) return 'Nuevo albarán de compra'
  if (!ficha.value) return 'Albarán de compra'
  return `Albarán compra ${ficha.value.empresa}-${ficha.value.albaran}`
})

const bloqueado = computed(() => {
  if (esNuevo.value) return false
  if (!ficha.value) return true
  return !ficha.value.editable || !!ficha.value.trasCtb || !!ficha.value.actualizado
})

const soloLecturaMotivo = computed(() => {
  if (!ficha.value || esNuevo.value) return null
  if (ficha.value.trasCtb) return 'Traspasado a contabilidad (TrasCtb)'
  if (ficha.value.actualizado) return 'Stock ya actualizado'
  if (ficha.value.editable === false) return 'Documento no editable'
  return null
})

const camposEditables = computed(() => modoEdicion.value && !bloqueado.value)

const puedeActualizarStock = computed(
  () =>
    puedeEditar.value &&
    !!ficha.value &&
    !esNuevo.value &&
    !modoEdicion.value &&
    !ficha.value.actualizado &&
    !ficha.value.trasCtb
)

const puedeImprimir = computed(() => !!ficha.value && !esNuevo.value)

function lineaVacia(): FormLinea {
  return {
    articulo: '',
    descripcion: '',
    cantidad: 0,
    precio: 0,
    pjeDto: 0,
    lote: '',
    almacen: form.value.almacen,
    pedido: null,
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
  if (route.name === 'compras-albaran-nuevo') return true
  return (route.path || '').replace(/\/+$/, '').endsWith('/compras/albaranes/nuevo')
}

function aplicarFicha(data: AlbaranCompraDetalle) {
  ficha.value = data
  form.value = {
    empresa: data.empresa,
    fechaAlbaran: fmtFecha(data.fechaAlbaran) || new Date().toISOString().slice(0, 10),
    suAlbaran: data.suAlbaran || '',
    proveedor: data.proveedor || '',
    razonSocial: data.razonSocial || '',
    fpago: data.fpago || '',
    almacen: data.almacen ?? null,
    serie: data.serie || '',
    albaranDevolucion: !!data.albaranDevolucion,
    observaciones: data.observaciones || '',
    lineas: (data.lineas?.length ? data.lineas : [lineaVacia()]).map((l) => ({
      nroLin: l.nroLin,
      articulo: l.articulo || '',
      descripcion: l.descripcion || '',
      cantidad: Number(l.cantidad ?? 0),
      precio: Number(l.precio ?? 0),
      pjeDto: Number(l.pjeDto ?? 0),
      lote: l.lote || '',
      almacen: l.almacen ?? data.almacen ?? null,
      pedido: l.pedido ?? null,
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
  const albaran = Number(route.params.albaran ?? 0)
  if (!empresa || !Number.isFinite(albaran) || albaran <= 0) {
    error.value = 'Albarán no válido'
    ficha.value = null
    return
  }

  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    aplicarFicha(await obtenerAlbaranCompra(empresa, albaran))
    modoEdicion.value = false
    esNuevo.value = false
  } catch (e: unknown) {
    ficha.value = null
    error.value = extractApiError(e, 'No se pudo cargar el albarán de compra')
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
    fechaAlbaran: new Date().toISOString().slice(0, 10),
    suAlbaran: '',
    proveedor: '',
    razonSocial: '',
    fpago: '',
    almacen: null as number | null,
    serie: '',
    albaranDevolucion: false,
    observaciones: '',
    lineas: [lineaVacia()],
  }
}

function volverListado() {
  router.push({ name: 'compras-albaranes' })
}

function onNuevo() {
  if (!puedeCrear.value) return
  router.push({ name: 'compras-albaran-nuevo' })
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

function buildPayload(): AlbaranCompraPayload {
  const lineas: AlbaranCompraLinea[] = form.value.lineas
    .filter((l) => l.articulo.trim() !== '')
    .map((l) => ({
      articulo: l.articulo.trim(),
      descripcion: l.descripcion.trim() || null,
      cantidad: Number(l.cantidad) || 0,
      precio: Number(l.precio) || 0,
      pjeDto: Number(l.pjeDto) || 0,
      lote: l.lote.trim() || null,
      almacen: l.almacen,
      pedido: l.pedido,
    }))
  return {
    empresa: form.value.empresa.trim(),
    fechaAlbaran: form.value.fechaAlbaran || null,
    suAlbaran: form.value.suAlbaran.trim() || null,
    proveedor: form.value.proveedor.trim() || null,
    fpago: form.value.fpago.trim() || null,
    observaciones: form.value.observaciones.trim() || null,
    albaranDevolucion: form.value.albaranDevolucion,
    almacen: form.value.almacen,
    serie: form.value.serie.trim() || null,
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
      const created = await crearAlbaranCompra(payload)
      mensaje.value = 'Albarán creado'
      await router.replace({
        name: 'compras-albaran-detalle',
        params: { empresa: created.empresa, albaran: String(created.albaran) },
      })
      // KeepAlive: nueva instancia al cambiar path; si misma, recargar
      if (esEstaInstanciaActiva()) {
        aplicarFicha(created)
        modoEdicion.value = false
        esNuevo.value = false
      }
    } else if (ficha.value) {
      const updated = await actualizarAlbaranCompra(
        ficha.value.empresa,
        ficha.value.albaran,
        payload
      )
      aplicarFicha(updated)
      modoEdicion.value = false
      mensaje.value = 'Albarán guardado'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo guardar el albarán')
  } finally {
    saving.value = false
    loading.value = false
  }
}

function pedirBorrar() {
  if (!puedeEliminar.value || bloqueado.value || !ficha.value) return
  confirmBorrar.value = true
}

async function onBorrarConfirmado() {
  confirmBorrar.value = false
  if (!ficha.value) return
  loading.value = true
  error.value = null
  try {
    await eliminarAlbaranCompra(ficha.value.empresa, ficha.value.albaran)
    volverListado()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo borrar el albarán')
  } finally {
    loading.value = false
  }
}

function pedirActualizarStock() {
  if (!puedeActualizarStock.value) return
  confirmStock.value = true
}

async function onStockConfirmado() {
  confirmStock.value = false
  if (!ficha.value) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const updated = await actualizarStockAlbaranCompra(ficha.value.empresa, ficha.value.albaran)
    aplicarFicha(updated)
    modoEdicion.value = false
    mensaje.value = 'Stock actualizado (entradas aplicadas)'
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo actualizar el stock')
  } finally {
    loading.value = false
  }
}

/** Vuelca líneas del albarán a la cola Etiquetas (005 / T032). */
async function onGenerarEtiquetas() {
  if (!puedeGenerarEtiquetas.value || !ficha.value) return
  generandoEtiquetas.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await encolarDesdeAlbaranCompra({
      empresa: ficha.value.empresa,
      albaran: ficha.value.albaran,
      puesto: puesto.puestoCodigo || undefined,
    })
    const n = res.items?.length ?? 0
    const omit = res.omitidas ?? 0
    if (n === 0) {
      mensaje.value =
        omit > 0
          ? `No se generaron etiquetas (${omit} línea(s) omitida(s): sin EAN o sin artículo)`
          : 'No hay líneas para generar etiquetas'
    } else {
      mensaje.value =
        `Generadas ${n} etiqueta(s) en cola` +
        (omit > 0 ? ` (${omit} omitida(s))` : '') +
        '. Abra menú Etiquetas para imprimir.'
    }
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron generar las etiquetas')
  } finally {
    generandoEtiquetas.value = false
  }
}

function irAColaEtiquetas() {
  void router.push({ name: 'etiquetas-cola' })
}

async function onImprimir() {
  if (!puedeImprimir.value || !ficha.value) return
  error.value = null
  mensaje.value = null
  loading.value = true
  try {
    a4Prep.value = await prepararImpresionAlbaranCompra(ficha.value, {
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
    error.value = extractApiError(e, 'No se pudo imprimir el albarán de compra')
  } finally {
    a4Imprimiendo.value = false
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
  if (precio > 0 && !linea.precio) linea.precio = precio
  const uds = Number(art.unidadesPaquete)
  if (uds > 0 && (!linea.cantidad || linea.cantidad === 1)) {
    linea.cantidad = uds
  } else if (!linea.cantidad) {
    linea.cantidad = 1
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
  if (form.value.lineas.length <= 1) {
    form.value.lineas = [lineaVacia()]
    return
  }
  form.value.lineas.splice(idx, 1)
}

onMounted(async () => {
  await Promise.all([cargarTiendas(), cargarAlmacenes()])
  await cargar()
})

watch(
  () => [route.params.empresa, route.params.albaran, route.name] as const,
  () => {
    if (!esEstaInstanciaActiva()) return
    void cargar()
  }
)
</script>

<template>
  <section class="compra-detalle">
    <VentaToolbar
      :puede-crear="puedeCrear"
      :puede-editar="puedeEditar && !!ficha && !bloqueado"
      :puede-eliminar="puedeEliminar && !!ficha && !bloqueado"
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
      @borrar="pedirBorrar"
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
              : 'Modificar para editar. Buscar vuelve al listado.'
          }}
        </p>
      </div>
      <p v-if="soloLecturaMotivo" class="badge-bloqueo">Solo lectura — {{ soloLecturaMotivo }}</p>
      <p v-else-if="modoEdicion" class="badge-ok">Editando</p>
      <div class="head-actions">
        <button
          v-if="puedeGenerarEtiquetas"
          type="button"
          class="btn-etiquetas"
          :disabled="loading || generandoEtiquetas || modoEdicion"
          title="Añadir líneas del albarán a la cola de etiquetas"
          @click="onGenerarEtiquetas"
        >
          <ToolIcon name="etiquetas" />
          {{ generandoEtiquetas ? 'Generando…' : 'Generar etiquetas' }}
        </button>
        <button
          v-if="puedeGenerarEtiquetas"
          type="button"
          class="btn-etiquetas-sec"
          :disabled="loading || generandoEtiquetas"
          title="Abrir cola de etiquetas"
          @click="irAColaEtiquetas"
        >
          Ver cola
        </button>
        <button
          v-if="puedeActualizarStock"
          type="button"
          class="btn-stock"
          :disabled="loading"
          title="Aplicar entradas de stock y marcar Actualizado"
          @click="pedirActualizarStock"
        >
          Actualizar stock
        </button>
      </div>
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
            Albarán
            <input :value="esNuevo ? '(nuevo)' : ficha?.albaran" readonly />
          </label>
          <label>
            Fecha
            <input
              v-model="form.fechaAlbaran"
              type="date"
              :readonly="!camposEditables"
            />
          </label>
          <label>
            Su albarán
            <input v-model="form.suAlbaran" :readonly="!camposEditables" />
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
            Forma pago
            <input v-model="form.fpago" maxlength="2" :readonly="!camposEditables" />
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
          <label>
            Serie
            <input v-model="form.serie" :readonly="!camposEditables" />
          </label>
          <label class="check">
            <input
              v-model="form.albaranDevolucion"
              type="checkbox"
              :disabled="!camposEditables || (!esNuevo && !!ficha)"
            />
            Devolución
          </label>
          <label v-if="ficha" class="check">
            <input type="checkbox" :checked="ficha.actualizado" disabled />
            Stock actualizado
          </label>
          <label v-if="ficha" class="check">
            <input type="checkbox" :checked="ficha.trasCtb" disabled />
            Tras. contabilidad
          </label>
          <label v-if="ficha">
            Importe
            <input class="num" :value="fmtNum(ficha.importeAlb)" readonly />
          </label>
          <label v-if="ficha">
            Dtos
            <input class="num" :value="fmtNum(ficha.importeDtos)" readonly />
          </label>
          <label class="span-4">
            Observaciones
            <textarea v-model="form.observaciones" rows="2" :readonly="!camposEditables" />
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
                <th class="num col-q">Cantidad</th>
                <th class="num col-p">Precio</th>
                <th class="num col-d">% Dto</th>
                <th class="col-lote">Lote</th>
                <th v-if="camposEditables" class="col-act" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="(l, idx) in form.lineas" :key="l.nroLin ?? `n-${idx}`">
                <td class="col-n">{{ l.nroLin ?? idx + 1 }}</td>
                <td class="col-art">
                  <div v-if="camposEditables" class="con-lupa">
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
                    v-model="l.cantidad"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.cantidad) }}</span>
                </td>
                <td class="num col-p">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.precio"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.precio) }}</span>
                </td>
                <td class="num col-d">
                  <DecimalInput
                    v-if="camposEditables"
                    v-model="l.pjeDto"
                    :empty-as-null="false"
                  />
                  <span v-else>{{ fmtNum(l.pjeDto) }}</span>
                </td>
                <td class="col-lote">
                  <input v-if="camposEditables" v-model="l.lote" />
                  <span v-else>{{ l.lote || '—' }}</span>
                </td>
                <td v-if="camposEditables" class="col-act">
                  <button type="button" class="btn-del" title="Quitar" @click="quitarLinea(idx)">
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
    <ConfirmDialog
      :open="confirmBorrar"
      title="Borrar albarán"
      message="¿Eliminar este albarán de compra? Solo es posible si no tiene stock actualizado ni TrasCtb."
      confirm-label="Borrar"
      @confirm="onBorrarConfirmado"
      @cancel="confirmBorrar = false"
    />
    <ConfirmDialog
      :open="confirmStock"
      title="Actualizar stock"
      message="Se aplicarán las entradas (o salidas si es devolución) en Stock del mes de la fecha del albarán y el documento quedará no editable. ¿Continuar?"
      confirm-label="Actualizar"
      :danger="false"
      @confirm="onStockConfirmado"
      @cancel="confirmStock = false"
    />
    <VentaImpresionA4Modal
      ref="a4ModalRef"
      :open="a4Open"
      :titulo="a4Prep?.titulo || 'Albarán de compra'"
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
.compra-detalle h2 {
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
.head-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.45rem;
}
.btn-etiquetas {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  font-weight: 600;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
}
.btn-etiquetas:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn-etiquetas :deep(.tool-icon) {
  width: 1rem;
  height: 1rem;
}
.btn-etiquetas-sec {
  padding: 0.4rem 0.65rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  color: #1e40af;
  font-weight: 600;
  cursor: pointer;
  font: inherit;
  font-size: 0.85rem;
}
.btn-etiquetas-sec:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn-stock {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #b45309;
  background: #f59e0b;
  color: #1c1917;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
}
.btn-stock:disabled {
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
.grid-cab .span-4 {
  grid-column: 1 / -1;
}
.grid-cab .check {
  flex-direction: row;
  align-items: center;
  gap: 0.4rem;
  padding-top: 1.1rem;
  font-size: 0.8rem;
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
.col-lote {
  width: 6rem;
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

@media (max-width: 900px) {
  .grid-cab {
    grid-template-columns: 1fr 1fr;
  }
  .grid-cab .span-2,
  .grid-cab .span-4 {
    grid-column: 1 / -1;
  }
}
</style>
