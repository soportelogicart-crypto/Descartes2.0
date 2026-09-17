<script setup lang="ts">
/**
 * Cola de impresión de etiquetas (005 / T013 / T022 / T024 / US2·US4).
 * Alta, editar copias, borrar; preview e imprimir lote → vaciar OK.
 */
import { computed, nextTick, onActivated, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  actualizarLineaCola,
  crearLineaCola,
  eliminarLineaCola,
  eliminarLineasColaLote,
  listarColaEtiquetas,
} from '@/api/etiquetas'
import { resolverArticulo } from '@/api/articulos'
import type { EtiquetaColaLinea } from '@/types/etiquetas'
import { createBarcodeScanWatcher } from '@/composables/useBarcodeScanWatcher'
import { extractApiError } from '@/composables/extractApiError'
import { guardarGridPageSize, leerGridPageSize } from '@/composables/useGridPageSize'
import {
  cargarOpcionesImpresionEtiqueta,
  imprimirLineasCola,
} from '@/composables/useImpresionEtiquetas'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import ConfirmDialog from '@/components/common/ConfirmDialog.vue'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import EtiquetaPreviewModal from '@/components/etiquetas/EtiquetaPreviewModal.vue'
import ListPagination from '@/components/common/ListPagination.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const puesto = usePuestoContextoStore()
const { puede } = usePermisos()

const puedeCrear = computed(() => puede('etiquetas', 'crear'))
const puedeEditar = computed(() => puede('etiquetas', 'editar'))
const puedeEliminar = computed(() => puede('etiquetas', 'eliminar'))
/** Imprimir requiere editar (FR-006). */
const puedeImprimir = computed(() => puedeEditar.value)

const loading = ref(false)
const adding = ref(false)
const imprimiendo = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<EtiquetaColaLinea[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(leerGridPageSize(100))

/** Por defecto solo la cola de este puesto (cada PC suele tener su puesto). */
const filtroPuesto = ref(true)
const filtroEmpresa = ref(true)
const referencia = ref('')
const copias = ref(1)
const refInput = ref<HTMLInputElement | null>(null)
const buscarOpen = ref(false)

const confirmBorrar = ref(false)
const pendienteBorrar = ref<EtiquetaColaLinea | null>(null)
const confirmBorrarLote = ref(false)
const pendienteBorrarLote = ref<EtiquetaColaLinea[]>([])
const borrando = ref(false)

/** Preview modal (T024) — líneas pendientes de confirmar impresión. */
const previewOpen = ref(false)
const previewLineas = ref<EtiquetaColaLinea[]>([])
const previewConImprimir = ref(true)

/** Selección para imprimir (clave articulo|nroLin). */
const seleccion = ref<Set<string>>(new Set())

/** Plantillas formato (multi-mm) + impresora del puesto. */
const formatoOpciones = ref<
  { id: number; nombre: string; label: string; widthMm: number; heightMm: number; activa: boolean }[]
>([])
const plantillaId = ref<number | null>(null)
const impresoraNombre = ref('')

/** Cantidades en edición local (clave articulo|nroLin). */
const cantidadesEdit = ref<Record<string, number>>({})

function clave(linea: EtiquetaColaLinea): string {
  return `${linea.articulo}|${linea.nroLin}`
}

function syncCantidadesEdit(list: EtiquetaColaLinea[]) {
  const next: Record<string, number> = {}
  for (const l of list) {
    next[clave(l)] = Number(l.cantidad) || 1
  }
  cantidadesEdit.value = next
}

function pruneSeleccion(list: EtiquetaColaLinea[]) {
  const valid = new Set(list.map(clave))
  const next = new Set<string>()
  for (const k of seleccion.value) {
    if (valid.has(k)) next.add(k)
  }
  seleccion.value = next
}

const todasSeleccionadas = computed(
  () => items.value.length > 0 && items.value.every((l) => seleccion.value.has(clave(l)))
)

const numSeleccionadas = computed(() => seleccion.value.size)

function toggleSeleccion(linea: EtiquetaColaLinea) {
  const k = clave(linea)
  const next = new Set(seleccion.value)
  if (next.has(k)) next.delete(k)
  else next.add(k)
  seleccion.value = next
}

function toggleTodas() {
  if (todasSeleccionadas.value) {
    seleccion.value = new Set()
    return
  }
  seleccion.value = new Set(items.value.map(clave))
}

async function cargarOpcionesPrint() {
  try {
    const data = await cargarOpcionesImpresionEtiqueta({
      puestoCodigo: puesto.puestoCodigo,
      empresa: puesto.empresaCodigo,
    })
    formatoOpciones.value = data.opciones
    if (plantillaId.value == null || !data.opciones.some((o) => o.id === plantillaId.value)) {
      plantillaId.value = data.plantillaIdDefault
    }
    impresoraNombre.value = data.impresoraNombre
  } catch {
    formatoOpciones.value = []
    impresoraNombre.value = ''
  }
}

async function cargar(opts?: { keepFocus?: boolean }) {
  const keepFocus = opts?.keepFocus !== false
  const hadFocus =
    keepFocus &&
    typeof document !== 'undefined' &&
    (document.activeElement === refInput.value ||
      document.activeElement === null ||
      document.activeElement === document.body)
  loading.value = true
  error.value = null
  try {
    if (filtroPuesto.value && !String(puesto.puestoCodigo ?? '').trim()) {
      items.value = []
      total.value = 0
      error.value =
        'Este equipo no tiene puesto configurado. Desmarque «Solo este puesto» para ver toda la cola o configure el puesto.'
      return
    }
    const data = await listarColaEtiquetas({
      puesto: filtroPuesto.value ? puesto.puestoCodigo || undefined : undefined,
      empresa: filtroEmpresa.value ? puesto.empresaCodigo || undefined : undefined,
      page: page.value,
      pageSize: pageSize.value,
    })
    items.value = data.items
    total.value = data.total
    syncCantidadesEdit(data.items)
    pruneSeleccion(data.items)
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo cargar la cola')
    items.value = []
    total.value = 0
  } finally {
    loading.value = false
    if (hadFocus || keepFocus) {
      // Tras re-render de la tabla, recuperar foco para el siguiente escaneo
      focusReferencia()
    }
  }
}

async function ejecutarImpresion(lineas: EtiquetaColaLinea[]) {
  if (!puedeImprimir.value) {
    error.value = 'Sin permiso para imprimir'
    return
  }
  if (lineas.length === 0) {
    error.value = 'Seleccione al menos una línea'
    return
  }
  if (!puesto.puestoCodigo) {
    error.value = 'Configure el puesto de este equipo'
    return
  }

  imprimiendo.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await imprimirLineasCola(lineas, {
      puestoCodigo: puesto.puestoCodigo,
      empresa: puesto.empresaCodigo,
      plantillaId: plantillaId.value != null ? Number(plantillaId.value) || null : null,
    })
    mensaje.value = res.message
    seleccion.value = new Set()
    previewOpen.value = false
    previewLineas.value = []
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo imprimir')
  } finally {
    imprimiendo.value = false
  }
}

/** Abre preview; si `conImprimir`, el modal confirma el lote. */
function abrirPreview(lineas: EtiquetaColaLinea[], conImprimir: boolean) {
  if (lineas.length === 0) {
    error.value = 'Seleccione al menos una línea'
    return
  }
  error.value = null
  previewLineas.value = lineas
  previewConImprimir.value = conImprimir && puedeImprimir.value
  previewOpen.value = true
}

function cerrarPreview() {
  if (imprimiendo.value) return
  previewOpen.value = false
  previewLineas.value = []
}

function confirmarImpresionDesdePreview() {
  void ejecutarImpresion([...previewLineas.value])
}

function lineasSeleccionadas(): EtiquetaColaLinea[] {
  const set = seleccion.value
  return items.value.filter((l) => set.has(clave(l)))
}

function imprimirSeleccion() {
  abrirPreview(lineasSeleccionadas(), true)
}

function imprimirTodas() {
  abrirPreview([...items.value], true)
}

function vistaPreviaSeleccion() {
  const sel = lineasSeleccionadas()
  abrirPreview(sel.length > 0 ? sel : [...items.value], false)
}

function vistaPreviaLinea(linea: EtiquetaColaLinea) {
  abrirPreview([linea], puedeImprimir.value)
}

function onPage(p: number) {
  page.value = p
  void cargar()
}

function onPageSize(s: number) {
  pageSize.value = s
  guardarGridPageSize(s)
  page.value = 1
  void cargar()
}

function focusReferencia() {
  void nextTick(() => {
    const el = refInput.value
    if (!el || buscarOpen.value || previewOpen.value || confirmBorrar.value || confirmBorrarLote.value)
      return
    el.focus({ preventScroll: true })
  })
}

/** Si el blur no va a un control de la barra de alta, recuperar foco (escáner). */
function onReferenciaBlur(e: FocusEvent) {
  const next = e.relatedTarget as HTMLElement | null
  if (next && next.closest?.('.alta-bar')) return
  if (buscarOpen.value || previewOpen.value || confirmBorrar.value || confirmBorrarLote.value || adding.value)
    return
  // Retraso breve: permite click en lupa / botones sin pelear el foco
  window.setTimeout(() => {
    if (buscarOpen.value || previewOpen.value || confirmBorrar.value || confirmBorrarLote.value) return
    if (document.activeElement?.closest?.('.alta-bar, .grid-wrap, .print-bar')) return
    focusReferencia()
  }, 120)
}

async function encolarDesdeQuery(q: string) {
  const codigo = q.trim()
  if (!codigo) return
  if (!puedeCrear.value) {
    error.value = 'Sin permiso para añadir a la cola'
    return
  }
  if (adding.value) return
  const n = Math.max(1, Math.trunc(Number(copias.value) || 1))
  copias.value = n

  adding.value = true
  error.value = null
  mensaje.value = null
  try {
    const art = await resolverArticulo(codigo)
    await crearLineaCola({
      articulo: art.codigo,
      ean: art.matchPor === 'ean' ? codigo.replace(/\.0+$/, '') : undefined,
      cantidad: n,
      puesto: puesto.puestoCodigo || undefined,
      empresa: puesto.empresaCodigo || undefined,
      descripcion: art.descripcion != null ? String(art.descripcion) : undefined,
    })
    referencia.value = ''
    mensaje.value = `Añadido ${art.codigo}`
    page.value = 1
    await cargar({ keepFocus: true })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo añadir a la cola')
    focusReferencia()
  } finally {
    adding.value = false
    focusReferencia()
  }
}

function onReferenciaKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' || e.key === 'F4') {
    e.preventDefault()
    barcodeWatcher.cancel()
    void encolarDesdeQuery(referencia.value)
  }
}

function onReferenciaInput() {
  error.value = null
  mensaje.value = null
  barcodeWatcher.onInput(referencia.value)
}

const barcodeWatcher = createBarcodeScanWatcher(async (codigo) => {
  referencia.value = codigo
  await encolarDesdeQuery(codigo)
})

async function onArticuloSeleccionado(r: EntidadBuscarResultado) {
  buscarOpen.value = false
  const codigo = String(r.codigo ?? '').trim()
  if (!codigo) return
  referencia.value = codigo
  await encolarDesdeQuery(codigo)
}

async function guardarCantidad(linea: EtiquetaColaLinea) {
  if (!puedeEditar.value) return
  const k = clave(linea)
  const n = Math.max(1, Math.trunc(Number(cantidadesEdit.value[k]) || 1))
  cantidadesEdit.value[k] = n
  if (n === Number(linea.cantidad)) return

  error.value = null
  mensaje.value = null
  try {
    const updated = await actualizarLineaCola(linea.articulo, linea.nroLin, { cantidad: n })
    const idx = items.value.findIndex((x) => x.articulo === linea.articulo && x.nroLin === linea.nroLin)
    if (idx >= 0) {
      items.value[idx] = updated
      cantidadesEdit.value[k] = updated.cantidad
    }
    mensaje.value = `Cantidad actualizada: ${updated.articulo}`
  } catch (e: unknown) {
    cantidadesEdit.value[k] = Number(linea.cantidad) || 1
    error.value = extractApiError(e, 'No se pudo actualizar la cantidad')
  }
}

function pedirBorrar(linea: EtiquetaColaLinea) {
  if (!puedeEliminar.value) return
  pendienteBorrar.value = linea
  confirmBorrar.value = true
}

async function confirmarBorrar() {
  const linea = pendienteBorrar.value
  confirmBorrar.value = false
  pendienteBorrar.value = null
  if (!linea) return

  borrando.value = true
  error.value = null
  mensaje.value = null
  try {
    await eliminarLineaCola(linea.articulo, linea.nroLin)
    mensaje.value = `Eliminado ${linea.articulo}`
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo eliminar la línea')
  } finally {
    borrando.value = false
  }
}

function pedirBorrarSeleccion() {
  if (!puedeEliminar.value) return
  const lineas = lineasSeleccionadas()
  if (lineas.length === 0) {
    error.value = 'Marque las líneas a quitar de la cola'
    return
  }
  pendienteBorrarLote.value = lineas
  confirmBorrarLote.value = true
}

function pedirBorrarPagina() {
  if (!puedeEliminar.value || items.value.length === 0) return
  pendienteBorrarLote.value = [...items.value]
  confirmBorrarLote.value = true
}

async function confirmarBorrarLote() {
  const lineas = pendienteBorrarLote.value
  confirmBorrarLote.value = false
  pendienteBorrarLote.value = []
  if (!lineas.length) return

  borrando.value = true
  error.value = null
  mensaje.value = null
  try {
    const res = await eliminarLineasColaLote(
      lineas.map((l) => ({ articulo: l.articulo, nroLin: l.nroLin }))
    )
    mensaje.value = `Eliminadas ${res.eliminadas} línea(s) de la cola`
    seleccion.value = new Set()
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron eliminar las líneas')
  } finally {
    borrando.value = false
  }
}

function onFiltroColaChange() {
  page.value = 1
  void cargar()
}

function fmtPrecio(n: number | null | undefined): string {
  return Number(n ?? 0).toFixed(2)
}

onMounted(async () => {
  await Promise.all([cargar({ keepFocus: true }), cargarOpcionesPrint()])
  focusReferencia()
})

onActivated(async () => {
  await Promise.all([cargar({ keepFocus: true }), cargarOpcionesPrint()])
  focusReferencia()
})

watch(
  () => [puesto.puestoCodigo, puesto.empresaCodigo] as const,
  () => {
    void cargarOpcionesPrint()
    page.value = 1
    void cargar()
  }
)

onUnmounted(() => {
  barcodeWatcher.cancel()
})
</script>

<template>
  <section class="etiquetas-cola-view">
    <div class="head">
      <div>
        <h2>Cola de etiquetas</h2>
        <p class="hint">
          Añade por código, EAN o escáner. Edita
          <strong>cantidad</strong> (copias) e imprime más adelante.
          La cola es común en la base de datos: cada línea guarda el
          <strong>puesto</strong> que la generó (p. ej. desde albarán de compra).
          <span v-if="puesto.puestoCodigo" class="puesto-activa">
            Este equipo: puesto {{ puesto.puestoCodigo }}
            <template v-if="puesto.empresaCodigo"> · tienda {{ puesto.empresaCodigo }}</template>
          </span>
        </p>
      </div>
      <button type="button" class="btn-sec" :disabled="loading" @click="cargar">
        Actualizar
      </button>
    </div>

    <form v-if="puedeCrear" class="alta-bar" @submit.prevent="encolarDesdeQuery(referencia)">
      <label class="campo-ref">
        Artículo / EAN
        <div class="ref-row">
          <input
            ref="refInput"
            v-model="referencia"
            type="text"
            autocomplete="off"
            placeholder="Código, Alternativo o EAN"
            @input="onReferenciaInput"
            @keydown="onReferenciaKeydown"
            @blur="onReferenciaBlur"
          />
          <button
            type="button"
            class="btn-lupa"
            title="Buscar artículo"
            :disabled="adding"
            @mousedown.prevent
            @click="buscarOpen = true"
          >
            <ToolIcon name="buscar" />
          </button>
        </div>
      </label>
      <label class="campo-copias">
        Copias
        <input
          v-model.number="copias"
          type="number"
          min="1"
          max="32767"
          step="1"
          :disabled="adding"
        />
      </label>
      <button type="submit" class="btn-primary" :disabled="adding || !referencia.trim()">
        {{ adding ? 'Añadiendo…' : 'Añadir' }}
      </button>
      <label class="filtro-puesto">
        <input v-model="filtroPuesto" type="checkbox" @change="onFiltroColaChange" />
        Solo puesto
        {{ puesto.puestoCodigo || '—' }}
      </label>
      <label class="filtro-puesto">
        <input v-model="filtroEmpresa" type="checkbox" @change="onFiltroColaChange" />
        Solo tienda {{ puesto.empresaCodigo || '—' }}
      </label>
    </form>

    <p v-else class="hint">Sin permiso para añadir líneas a la cola.</p>

    <div class="print-bar">
      <label class="campo-formato">
        Formato
        <select v-model="plantillaId" :disabled="imprimiendo || formatoOpciones.length === 0">
          <option v-if="formatoOpciones.length === 0" :value="null">Sin plantillas (esqueleto)</option>
          <option v-for="o in formatoOpciones" :key="o.id" :value="o.id">{{ o.label }}</option>
        </select>
      </label>
      <span class="imp-info" :title="impresoraNombre || 'Sin impresora de puesto'">
        Impresora: {{ impresoraNombre || '—' }}
      </span>
      <button
        type="button"
        class="btn-sec"
        :disabled="imprimiendo || (numSeleccionadas === 0 && items.length === 0)"
        title="Vista previa (selección o página)"
        @click="vistaPreviaSeleccion"
      >
        Vista previa
      </button>
      <template v-if="puedeImprimir">
        <button
          type="button"
          class="btn-primary"
          :disabled="imprimiendo || numSeleccionadas === 0"
          @click="imprimirSeleccion"
        >
          {{ imprimiendo ? 'Imprimiendo…' : `Imprimir selección (${numSeleccionadas})` }}
        </button>
        <button
          type="button"
          class="btn-sec"
          :disabled="imprimiendo || items.length === 0"
          @click="imprimirTodas"
        >
          Imprimir todas (página)
        </button>
      </template>
      <template v-if="puedeEliminar">
        <button
          type="button"
          class="btn-del-bar"
          :disabled="borrando || imprimiendo || numSeleccionadas === 0"
          @click="pedirBorrarSeleccion"
        >
          Quitar selección ({{ numSeleccionadas }})
        </button>
        <button
          type="button"
          class="btn-del-bar btn-del-sec"
          :disabled="borrando || imprimiendo || items.length === 0"
          title="Quitar todas las líneas visibles en esta página"
          @click="pedirBorrarPagina"
        >
          Quitar página ({{ items.length }})
        </button>
      </template>
    </div>

    <p v-if="error" class="error">{{ error }}</p>
    <p v-if="mensaje" class="ok">{{ mensaje }}</p>
    <p v-if="loading" class="msg">Cargando…</p>

    <div class="grid-wrap">
      <table>
        <thead>
          <tr>
            <th v-if="puedeImprimir" class="col-check">
              <input
                type="checkbox"
                :checked="todasSeleccionadas"
                :disabled="items.length === 0 || imprimiendo"
                title="Seleccionar todas"
                @change="toggleTodas"
              />
            </th>
            <th class="col-art">Artículo</th>
            <th>Descripción</th>
            <th class="col-ean">EAN</th>
            <th class="num col-precio">Precio</th>
            <th class="num col-cant">Cantidad</th>
            <th class="col-puesto">Puesto</th>
            <th class="col-acc"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="l in items" :key="clave(l)" :class="{ selected: seleccion.has(clave(l)) }">
            <td v-if="puedeImprimir" class="col-check">
              <input
                type="checkbox"
                :checked="seleccion.has(clave(l))"
                :disabled="imprimiendo"
                @change="toggleSeleccion(l)"
              />
            </td>
            <td class="col-art">{{ l.articulo }}</td>
            <td class="col-desc">{{ l.descripcion || '—' }}</td>
            <td class="col-ean">{{ l.ean || '—' }}</td>
            <td class="num col-precio">{{ fmtPrecio(l.precio) }}</td>
            <td class="num col-cant">
              <input
                v-if="puedeEditar"
                v-model.number="cantidadesEdit[clave(l)]"
                type="number"
                min="1"
                max="32767"
                step="1"
                class="input-cant"
                title="Copias a imprimir"
                :disabled="imprimiendo"
                @change="guardarCantidad(l)"
                @keydown.enter.prevent="guardarCantidad(l)"
              />
              <span v-else>{{ l.cantidad }}</span>
            </td>
            <td class="col-puesto">{{ l.puesto || '—' }}</td>
            <td class="col-acc">
              <button
                type="button"
                class="btn-prev"
                title="Vista previa"
                :disabled="imprimiendo"
                @click="vistaPreviaLinea(l)"
              >
                Preview
              </button>
              <button
                v-if="puedeEliminar"
                type="button"
                class="btn-del"
                title="Quitar de la cola"
                :disabled="borrando || imprimiendo"
                @click="pedirBorrar(l)"
              >
                Quitar
              </button>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0">
            <td :colspan="puedeImprimir ? 8 : 7">Cola vacía</td>
          </tr>
        </tbody>
      </table>
    </div>

    <ListPagination
      :page="page"
      :page-size="pageSize"
      :total="total"
      :loading="loading"
      @update:page="onPage"
      @update:page-size="onPageSize"
    />

    <EntidadBuscarModal
      :open="buscarOpen"
      entidad="articulos"
      titulo="Buscar artículo"
      :busqueda-inicial="referencia"
      @seleccionar="onArticuloSeleccionado"
      @cerrar="buscarOpen = false"
    />

    <EtiquetaPreviewModal
      :open="previewOpen"
      :lineas="previewLineas"
      :plantilla-id="plantillaId != null ? Number(plantillaId) || null : null"
      :empresa="puesto.empresaCodigo"
      :impresora-nombre="impresoraNombre"
      :imprimiendo="imprimiendo"
      :permitir-imprimir="previewConImprimir"
      @cerrar="cerrarPreview"
      @imprimir="confirmarImpresionDesdePreview"
    />

    <ConfirmDialog
      :open="confirmBorrar"
      title="Quitar de la cola"
      :message="
        pendienteBorrar
          ? `¿Eliminar ${pendienteBorrar.articulo} (línea ${pendienteBorrar.nroLin})?`
          : ''
      "
      confirm-label="Eliminar"
      @confirm="confirmarBorrar"
      @cancel="confirmBorrar = false; pendienteBorrar = null"
    />

    <ConfirmDialog
      :open="confirmBorrarLote"
      title="Quitar varias de la cola"
      :message="
        pendienteBorrarLote.length
          ? `¿Eliminar ${pendienteBorrarLote.length} línea(s) de la cola?`
          : ''
      "
      confirm-label="Eliminar"
      @confirm="confirmarBorrarLote"
      @cancel="confirmBorrarLote = false; pendienteBorrarLote = []"
    />
  </section>
</template>

<style scoped>
.etiquetas-cola-view h2 {
  margin: 0 0 0.35rem;
}

.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.hint {
  margin: 0;
  color: #64748b;
  font-size: 0.85rem;
}

.puesto-activa {
  color: #1d4ed8;
  background: #dbeafe;
  padding: 0.1rem 0.4rem;
  border-radius: 4px;
  margin-left: 0.35rem;
}

.alta-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.65rem 0.85rem;
  margin-bottom: 0.75rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #f8fafc;
}

.print-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.65rem 0.85rem;
  margin-bottom: 0.75rem;
  padding: 0.65rem 0.75rem;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  background: #eff6ff;
}

.print-bar label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #475569;
}

.campo-formato {
  flex: 1 1 16rem;
  min-width: 12rem;
}

.campo-formato select {
  padding: 0.4rem 0.5rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font-size: 0.9rem;
  background: #fff;
}

.imp-info {
  font-size: 0.8rem;
  color: #334155;
  align-self: center;
  max-width: 18rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.col-check {
  width: 2.2rem;
  text-align: center;
}

tr.selected {
  background: #f0f9ff;
}

.alta-bar label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #475569;
}

.campo-ref {
  flex: 1 1 14rem;
  min-width: 12rem;
}

.ref-row {
  display: flex;
  gap: 0.35rem;
}

.ref-row input {
  flex: 1;
  min-width: 0;
  padding: 0.4rem 0.5rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  font-size: 0.9rem;
  font-weight: 400;
}

.campo-copias input {
  width: 5rem;
  padding: 0.4rem 0.5rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  font-size: 0.9rem;
  font-weight: 400;
}

.filtro-puesto {
  flex-direction: row !important;
  align-items: center;
  gap: 0.35rem !important;
  font-weight: 500 !important;
  padding-bottom: 0.35rem;
}

.btn-lupa {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.2rem;
  border: 1px solid #94a3b8;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}

.btn-primary {
  padding: 0.45rem 0.9rem;
  border-radius: 6px;
  border: 1px solid #1d4ed8;
  background: #2563eb;
  color: #fff;
  font-size: 0.85rem;
  cursor: pointer;
}

.btn-primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn-sec {
  padding: 0.4rem 0.75rem;
  border-radius: 6px;
  border: 1px solid #94a3b8;
  background: #fff;
  font-size: 0.85rem;
  cursor: pointer;
}

.error {
  color: #b91c1c;
  margin: 0 0 0.5rem;
}

.ok {
  color: #15803d;
  margin: 0 0 0.5rem;
}

.msg {
  color: #64748b;
  margin: 0 0 0.5rem;
}

.grid-wrap {
  overflow: auto;
  border: 1px solid #c5cdd8;
  border-radius: 6px;
  background: #fff;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}

th,
td {
  padding: 0.4rem 0.5rem;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  vertical-align: middle;
}

th {
  background: #f1f5f9;
  font-weight: 600;
  color: #334155;
  white-space: nowrap;
}

.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}

.col-art {
  width: 7rem;
  white-space: nowrap;
}

.col-ean {
  width: 8rem;
  font-variant-numeric: tabular-nums;
}

.col-precio {
  width: 5.5rem;
}

.col-cant {
  width: 5.5rem;
}

.col-puesto {
  width: 4rem;
}

.col-acc {
  width: 8.5rem;
  text-align: right;
  white-space: nowrap;
}

.col-desc {
  max-width: 18rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.input-cant {
  width: 4.5rem;
  padding: 0.25rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  text-align: right;
  font-size: 0.85rem;
}

.btn-prev {
  margin-right: 0.25rem;
  padding: 0.2rem 0.45rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  color: #1e40af;
  font-size: 0.75rem;
  cursor: pointer;
}

.btn-prev:hover:not(:disabled) {
  background: #eff6ff;
}

.btn-prev:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-del {
  padding: 0.2rem 0.45rem;
  border: 1px solid #fca5a5;
  border-radius: 4px;
  background: #fff;
  color: #b91c1c;
  font-size: 0.75rem;
  cursor: pointer;
}

.btn-del:hover:not(:disabled) {
  background: #fef2f2;
}

.btn-del:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-del-bar {
  padding: 0.4rem 0.65rem;
  border: 1px solid #fca5a5;
  border-radius: 4px;
  background: #fff;
  color: #b91c1c;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-del-bar.btn-del-sec {
  border-color: #94a3b8;
  color: #475569;
}

.btn-del-bar:hover:not(:disabled) {
  background: #fef2f2;
}

.btn-del-bar:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
