<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ejecutarTraspasoContable, listarTraspasoContable } from '@/api/facturacion'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import { usePermisos } from '@/composables/usePermisos'
import { usePuestoContextoStore } from '@/stores/puestoContexto'
import type { FacturaTraspasoContable } from '@/types/facturacion'
import EntidadBuscarModal, {
  type EntidadBuscarResultado,
} from '@/components/common/EntidadBuscarModal.vue'
import ToolIcon from '@/components/common/ToolIcon.vue'

const { puede } = usePermisos()
const puestoContexto = usePuestoContextoStore()
const hoy = new Date().toISOString().slice(0, 10)
const inicioMes = `${hoy.slice(0, 8)}01`

const loading = ref(false)
const traspasando = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const items = ref<FacturaTraspasoContable[]>([])
const seleccion = ref(new Set<string>())
const filtros = ref({
  empresa: '',
  facturaTipo: '',
  fechaDesde: inicioMes,
  fechaHasta: hoy,
})
const tiendas = ref<Array<{ value: string; label: string }>>([])
const buscarEmpresaOpen = ref(false)

const empresaEtiqueta = computed(() => {
  const codigo = filtros.value.empresa.trim()
  if (!codigo) return ''
  const t = tiendas.value.find((x) => x.value === codigo)
  return t ? t.label : ''
})

const clave = (f: FacturaTraspasoContable) => `${f.empresa}|${f.facturaTipo}|${f.factura}`

/** Columnas del grid con su texto filtrable (búsqueda mientras se escribe). */
const COLUMNAS = [
  { key: 'empresa', label: 'Empresa', clase: 'col-empresa' },
  { key: 'facturaTipo', label: 'Tipo', clase: 'col-tipo' },
  { key: 'factura', label: 'Factura', clase: 'col-factura' },
  { key: 'fecha', label: 'Fecha', clase: 'col-fecha' },
  { key: 'cliente', label: 'Cliente', clase: 'col-cliente' },
  { key: 'razonSocial', label: 'Razón social', clase: 'col-razon' },
  { key: 'formaPago', label: 'F. pago', clase: 'col-fpago' },
  { key: 'numEfectos', label: 'Efectos', clase: 'col-efectos', num: true },
  { key: 'importe', label: 'Importe', clase: 'col-importe', num: true },
] as const

type ColumnaKey = (typeof COLUMNAS)[number]['key']

const textoColumna: Record<ColumnaKey, (f: FacturaTraspasoContable) => string> = {
  empresa: (f) => String(f.empresa ?? ''),
  facturaTipo: (f) => String(f.facturaTipo ?? ''),
  factura: (f) => String(f.factura ?? ''),
  fecha: (f) => String(f.fecha ?? ''),
  cliente: (f) => String(f.cliente ?? ''),
  razonSocial: (f) => String(f.razonSocial ?? ''),
  formaPago: (f) => String(f.formaPago ?? ''),
  numEfectos: (f) => String(f.numEfectos ?? ''),
  importe: (f) => Number(f.importe ?? 0).toFixed(2),
}

function filtrosColumnaVacios(): Record<ColumnaKey, string> {
  return {
    empresa: '',
    facturaTipo: '',
    factura: '',
    fecha: '',
    cliente: '',
    razonSocial: '',
    formaPago: '',
    numEfectos: '',
    importe: '',
  }
}

const filtrosColumna = ref<Record<ColumnaKey, string>>(filtrosColumnaVacios())

/** Compara sin acentos ni mayúsculas: "MARIA" encuentra "María". */
function normalizar(texto: string) {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
}

const filtrosColumnaActivos = computed(() =>
  (Object.entries(filtrosColumna.value) as [ColumnaKey, string][])
    .map(([key, valor]) => ({ key, valor: normalizar(valor.trim()) }))
    .filter((f) => f.valor !== '')
)

const hayFiltroColumna = computed(() => filtrosColumnaActivos.value.length > 0)

const itemsFiltrados = computed(() => {
  const activos = filtrosColumnaActivos.value
  if (activos.length === 0) return items.value
  return items.value.filter((f) =>
    activos.every((filtro) => normalizar(textoColumna[filtro.key](f)).includes(filtro.valor))
  )
})

function limpiarFiltrosColumna() {
  filtrosColumna.value = filtrosColumnaVacios()
}

// Solo se traspasa lo que se ve: los filtros de columna acotan la selección.
const todasSeleccionadas = computed(
  () =>
    itemsFiltrados.value.length > 0 &&
    itemsFiltrados.value.every((f) => seleccion.value.has(clave(f)))
)
const seleccionadas = computed(() =>
  itemsFiltrados.value.filter((f) => seleccion.value.has(clave(f)))
)
const importeSeleccionado = computed(() =>
  seleccionadas.value.reduce((total, f) => total + Number(f.importe || 0), 0)
)

function abrirBuscarEmpresa() {
  buscarEmpresaOpen.value = true
}

function onEmpresaSeleccionada(sel: EntidadBuscarResultado) {
  buscarEmpresaOpen.value = false
  filtros.value.empresa = sel.codigo
}

async function cargarOpciones() {
  try {
    const { data } = await api.get('/api/mantenimiento/tiendas', { params: { pageSize: 500 } })
    tiendas.value = (data.items ?? []).map((t: { codigo: string; nombre: string }) => {
      const codigo = String(t.codigo ?? '').trim()
      return { value: codigo, label: String(t.nombre ?? '').trim() }
    })
    const emp = String(puestoContexto.empresaCodigo ?? '').trim()
    if (!filtros.value.empresa && emp) filtros.value.empresa = emp
  } catch {
    tiendas.value = []
  }
}

async function cargar() {
  if (!puede('facturacion-contabilidad', 'ver')) return
  loading.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await listarTraspasoContable({
      empresa: filtros.value.empresa || undefined,
      facturaTipo: filtros.value.facturaTipo || undefined,
      fechaDesde: filtros.value.fechaDesde || undefined,
      fechaHasta: filtros.value.fechaHasta || undefined,
    })
    items.value = data.items
    seleccion.value = new Set()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudieron consultar las facturas pendientes')
  } finally {
    loading.value = false
  }
}

function toggle(f: FacturaTraspasoContable) {
  const nueva = new Set(seleccion.value)
  const k = clave(f)
  if (nueva.has(k)) nueva.delete(k)
  else nueva.add(k)
  seleccion.value = nueva
}

function toggleTodas() {
  const nueva = new Set(seleccion.value)
  if (todasSeleccionadas.value) {
    for (const f of itemsFiltrados.value) nueva.delete(clave(f))
  } else {
    for (const f of itemsFiltrados.value) nueva.add(clave(f))
  }
  seleccion.value = nueva
}

async function traspasar() {
  if (!puede('facturacion-contabilidad', 'crear')) {
    error.value = 'No tiene permiso para realizar el traspaso contable'
    return
  }
  if (seleccionadas.value.length === 0) {
    error.value = 'Seleccione al menos una factura'
    return
  }
  const texto =
    `Se crearán el libro de emitidas, los asientos y los efectos de cobro de ` +
    `${seleccionadas.value.length} factura(s). ¿Continuar?`
  if (!window.confirm(texto)) return

  traspasando.value = true
  error.value = null
  mensaje.value = null
  try {
    const data = await ejecutarTraspasoContable(
      seleccionadas.value.map((f) => ({
        empresa: f.empresa,
        facturaTipo: f.facturaTipo,
        factura: f.factura,
      }))
    )
    mensaje.value =
      `Traspasadas ${data.totales.facturas} factura(s): ` +
      `${data.totales.asientos} asiento(s) y ${data.totales.efectos} efecto(s).`
    await cargar()
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo completar el traspaso contable')
  } finally {
    traspasando.value = false
  }
}

function dinero(value: number) {
  return Number(value || 0).toLocaleString('es-ES', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

onMounted(async () => {
  await cargarOpciones()
  await cargar()
})
</script>

<template>
  <section class="page">
    <div class="toolbar">
      <div class="toolbar-title">
        <h2>Traspaso contable</h2>
        <p class="hint">Genera libro de facturas emitidas, asiento contable y efectos de cobro.</p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn" :disabled="loading" @click="cargar">
          {{ loading ? 'Buscando…' : 'Buscar' }}
        </button>
        <button
          type="button"
          class="btn primary"
          :disabled="traspasando || seleccionadas.length === 0"
          @click="traspasar"
        >
          {{ traspasando ? 'Traspasando…' : `Traspasar (${seleccionadas.length})` }}
        </button>
      </div>
    </div>

    <div class="layout">
      <aside class="sidebar">
        <form class="panel" @submit.prevent="cargar">
          <fieldset class="opciones">
            <legend>Opciones</legend>
            <label>
              <span>Empresa</span>
              <div class="con-lupa">
                <input
                  v-model.trim="filtros.empresa"
                  type="text"
                  maxlength="12"
                  :title="empresaEtiqueta || 'Código de tienda'"
                />
                <button
                  type="button"
                  class="btn-lupa"
                  title="Buscar empresa"
                  @click="abrirBuscarEmpresa"
                >
                  <ToolIcon name="buscar" />
                </button>
              </div>
            </label>
            <p v-if="empresaEtiqueta" class="empresa-nombre">{{ empresaEtiqueta }}</p>
            <label>
              <span>Tipo</span>
              <select v-model="filtros.facturaTipo">
                <option value="">Todos</option>
                <option value="F">Facturas</option>
                <option value="A">Abonos</option>
              </select>
            </label>
          </fieldset>

          <fieldset class="rangos">
            <legend>Intervalos</legend>
            <label>
              <span>Desde</span>
              <input v-model="filtros.fechaDesde" type="date" />
            </label>
            <label>
              <span>Hasta</span>
              <input v-model="filtros.fechaHasta" type="date" />
            </label>
          </fieldset>

          <div class="resumen">
            <div>
              <span>Sel.</span>
              <strong :class="{ sel: seleccionadas.length > 0 }">{{ seleccionadas.length }}</strong>
            </div>
            <div>
              <span>Listadas</span>
              <strong>{{ itemsFiltrados.length }}</strong>
            </div>
            <div class="importe">
              <span>Importe seleccionado</span>
              <strong>{{ dinero(importeSeleccionado) }} €</strong>
            </div>
          </div>
        </form>
      </aside>

      <div class="resultado">
        <p v-if="error" class="error">{{ error }}</p>
        <p v-if="mensaje && !error" class="ok">{{ mensaje }}</p>
        <p v-if="loading" class="hint">Cargando…</p>

        <div v-if="items.length" class="grid-wrap">
          <table>
            <thead>
              <tr>
                <th class="sel">
                  <input type="checkbox" :checked="todasSeleccionadas" @change="toggleTodas" />
                </th>
                <th v-for="c in COLUMNAS" :key="c.key" :class="c.clase">
                  <span class="th-titulo" :class="{ num: c.num }">{{ c.label }}</span>
                  <input
                    v-model="filtrosColumna[c.key]"
                    type="search"
                    class="filtro-col"
                    :title="`Filtrar por ${c.label}`"
                    :aria-label="`Filtrar por ${c.label}`"
                  />
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="f in itemsFiltrados"
                :key="clave(f)"
                :class="{ checked: seleccion.has(clave(f)) }"
                @click="toggle(f)"
              >
                <td class="sel" @click.stop>
                  <input type="checkbox" :checked="seleccion.has(clave(f))" @change="toggle(f)" />
                </td>
                <td>{{ f.empresa }}</td>
                <td>{{ f.facturaTipo }}</td>
                <td>{{ f.factura }}</td>
                <td>{{ f.fecha }}</td>
                <td>{{ f.cliente }}</td>
                <td class="clip">{{ f.razonSocial }}</td>
                <td>{{ f.formaPago }}</td>
                <td class="num">{{ f.numEfectos }}</td>
                <td class="num">{{ dinero(f.importe) }} €</td>
              </tr>
              <tr v-if="!itemsFiltrados.length">
                <td :colspan="COLUMNAS.length + 1" class="vacio">
                  Ninguna factura con esos filtros
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="!loading && !error" class="vacio">
          No hay facturas pendientes con esos filtros.
        </p>

        <div v-if="hayFiltroColumna" class="pie-grid">
          <span class="listadas">{{ itemsFiltrados.length }} de {{ items.length }}</span>
          <button type="button" class="btn" @click="limpiarFiltrosColumna">Limpiar filtros</button>
        </div>
      </div>
    </div>

    <EntidadBuscarModal
      :open="buscarEmpresaOpen"
      entidad="tiendas"
      titulo="Buscar empresa"
      :busqueda-inicial="filtros.empresa"
      :codigo-actual="filtros.empresa"
      @seleccionar="onEmpresaSeleccionada"
      @cerrar="buscarEmpresaOpen = false"
    />
  </section>
</template>

<style scoped>
.page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-height: 0;
  height: 100%;
  box-sizing: border-box;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  flex-shrink: 0;
}
.toolbar-title h2 {
  margin: 0;
  font-size: 1.15rem;
}
.hint {
  margin: 0.2rem 0 0;
  color: #64748b;
  font-size: 0.82rem;
}
.toolbar-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.btn {
  border: 1px solid #94a3b8;
  background: #fff;
  border-radius: 4px;
  padding: 0.4rem 0.85rem;
  font: inherit;
  font-size: 0.875rem;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.btn.primary {
  background: #0f172a;
  color: #fff;
  border-color: #0f172a;
}
.layout {
  display: grid;
  grid-template-columns: 21rem minmax(0, 1fr);
  gap: 0.85rem;
  align-items: stretch;
  min-height: 0;
  flex: 1;
}
@media (max-width: 960px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.sidebar {
  width: 21rem;
  max-width: 100%;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.panel {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.65rem;
  background: #f8fafc;
  width: 100%;
  height: 100%;
  box-sizing: border-box;
  overflow: auto;
  min-height: 0;
}
fieldset {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  margin: 0;
  padding: 0.55rem 0.55rem 0.65rem;
  background: #fff;
  width: 100%;
  box-sizing: border-box;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}
legend {
  padding: 0 0.3rem;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
}
.panel label {
  display: grid;
  grid-template-columns: 6.2rem minmax(0, 1fr);
  align-items: center;
  gap: 0.3rem;
  font-size: 0.75rem;
  color: #334155;
  min-width: 0;
}
.panel input,
.panel select {
  padding: 0.2rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  height: 1.65rem;
  font: inherit;
  font-size: 0.78rem;
  background: #fff;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}
.con-lupa {
  display: flex;
  align-items: center;
  gap: 0.15rem;
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
  width: 1.65rem;
  height: 1.65rem;
  padding: 0;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  cursor: pointer;
  color: #334155;
  flex-shrink: 0;
}
.btn-lupa:hover {
  background: #e0f2fe;
  border-color: #38bdf8;
}
.btn-lupa :deep(.tool-icon) {
  width: 0.95rem;
  height: 0.95rem;
}
.empresa-nombre {
  margin: 0;
  padding-left: 6.5rem;
  font-size: 0.72rem;
  color: #64748b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.resumen {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.4rem;
  padding: 0.45rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  background: #fff;
  font-size: 0.78rem;
  box-sizing: border-box;
  margin-top: auto;
}
.resumen strong {
  display: block;
  color: #94a3b8;
  font-size: 1rem;
}
.resumen strong.sel {
  color: #b91c1c;
}
.resumen .importe {
  grid-column: 1 / -1;
}
.resumen .importe strong {
  color: #0f172a;
}
.resultado {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 0.65rem;
  background: #fff;
  box-sizing: border-box;
}
.error {
  color: #b91c1c;
  margin: 0;
}
.ok {
  color: #047857;
  margin: 0;
}
.grid-wrap {
  overflow: auto;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  min-height: 0;
  flex: 1;
}
table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}
th,
td {
  padding: 0.3rem 0.4rem;
  border-bottom: 1px solid #f1f5f9;
  text-align: left;
  white-space: nowrap;
}
th {
  background: #f1f5f9;
  font-weight: 600;
  position: sticky;
  top: 0;
  z-index: 1;
  vertical-align: top;
}
th.sel,
td.sel {
  width: 2rem;
  text-align: center;
}
.th-titulo {
  display: block;
  padding: 0 0.1rem 0.15rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
.th-titulo.num {
  text-align: right;
}
.filtro-col {
  width: 100%;
  box-sizing: border-box;
  padding: 0.15rem 0.3rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  background: #fff;
  font: inherit;
  font-size: 0.76rem;
  font-weight: 400;
}
.filtro-col:focus {
  outline: 2px solid #2563eb;
  outline-offset: -1px;
}
.col-empresa,
.col-tipo,
.col-efectos {
  width: 4.5rem;
}
.col-factura,
.col-fpago {
  width: 5.5rem;
}
.col-fecha,
.col-cliente {
  width: 6.5rem;
}
.col-importe {
  width: 7rem;
}
td.num,
th.num {
  text-align: right;
  font-variant-numeric: tabular-nums;
}
td.clip {
  max-width: 16rem;
  overflow: hidden;
  text-overflow: ellipsis;
}
tbody tr {
  cursor: pointer;
}
tbody tr:hover {
  background: #f8fafc;
}
tbody tr.checked {
  background: #eff6ff;
}
.vacio {
  color: #64748b;
  text-align: center;
  padding: 1rem;
}
.pie-grid {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.75rem;
  flex-shrink: 0;
}
.listadas {
  font-size: 0.78rem;
  color: #64748b;
}
</style>
