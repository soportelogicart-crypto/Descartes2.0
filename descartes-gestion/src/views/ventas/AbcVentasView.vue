<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { obtenerAbcVentas } from '@/api/ventas'
import type { AbcVentasFiltros, AbcVentasResponse, AbcVentasTotales } from '@/types/ventas'
import { extractApiError } from '@/composables/useMantenimiento'
import {
  abcFiltroSoloDigitos,
  abcVentasRangoTienda,
  abcVentasRangosAvanzados,
  type AbcFiltroRango,
} from '@/config/abc-ventas-filtros'
import { hoyIso, rangoAtajoFecha, type AtajoFechaId } from '@/composables/useAtajosFecha'
import { imprimirListadoHtml } from '@/composables/imprimirListadoHtml'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const loading = ref(false)
const error = ref<string | null>(null)
const mensaje = ref<string | null>(null)
const data = ref<AbcVentasResponse | null>(null)

const form = ref<AbcVentasFiltros>({
  dimension: 'vendedores',
  orden: 'margen',
  idioma: 'castellano',
  divisa: 'EU',
  iva: 'incluido',
  imArticulos: true,
  valor: 'precioMedio',
  tipoVenta: 'todos',
  fechaDesde: `${new Date().getFullYear()}-01-01`,
  fechaHasta: hoyIso(),
  macroFamiliaDesde: '',
  macroFamiliaHasta: '',
  familiaDesde: '',
  familiaHasta: '',
  subfamiliaDesde: '',
  subfamiliaHasta: '',
  agrupacionDesde: '',
  agrupacionHasta: '',
  articuloDesde: '',
  articuloHasta: '',
  tiendaDesde: '',
  tiendaHasta: '',
  agenteDesde: '',
  agenteHasta: '',
  representanteDesde: '',
  representanteHasta: '',
  vendedorDesde: '',
  vendedorHasta: '',
  clienteDesde: '',
  clienteHasta: '',
  proveedorDesde: '',
  proveedorHasta: '',
  seccionDesde: '',
  seccionHasta: '',
  subSeccionDesde: '',
  subSeccionHasta: '',
  actividadDesde: '',
  actividadHasta: '',
  tipoDescuentoDesde: '',
  tipoDescuentoHasta: '',
})

const rangoTienda = abcVentasRangoTienda
const rangosAvanzados = abcVentasRangosAvanzados

const ivaLabel = computed(() => (data.value?.iva === 'excluido' ? 'Sin Iva' : 'Con Iva'))

function aplicarAtajoFecha(id: AtajoFechaId) {
  const r = rangoAtajoFecha(id)
  form.value.fechaDesde = r.desde
  form.value.fechaHasta = r.hasta
}

function fmtFecha(iso: string) {
  const [y, m, d] = iso.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y.slice(2)}`
}

function valorFiltro(key: string): string {
  return String((form.value as Record<string, unknown>)[key] ?? '')
}

function onFiltroInput(r: AbcFiltroRango, lado: 'desde' | 'hasta', raw: string) {
  let v = raw
  if (abcFiltroSoloDigitos(r.formato)) {
    v = v.replace(/\D+/g, '')
  }
  if (r.maxLength > 0 && v.length > r.maxLength) {
    v = v.slice(0, r.maxLength)
  }
  const key = lado === 'desde' ? r.desde : r.hasta
  ;(form.value as Record<string, unknown>)[key] = v
}

async function generar() {
  loading.value = true
  error.value = null
  try {
    data.value = await obtenerAbcVentas({ ...form.value })
  } catch (e: unknown) {
    error.value = extractApiError(e, 'No se pudo generar el listado ABC')
    data.value = null
  } finally {
    loading.value = false
  }
}

function imprimir() {
  const d = data.value
  if (!d) return
  const thead = [
    'Codigo',
    'Articulo',
    'Unidades',
    'Dto.',
    'Importe',
    'Coste',
    'Margen',
    '%Margen',
    '%Sob.Tot',
    'M.Agr.',
  ]
  const filas: string[][] = []
  for (const g of d.grupos) {
    filas.push([`${g.codigo || '-'} — ${g.nombre || '(sin nombre)'}`, '', '', '', '', '', '', '', '', ''])
    for (const a of g.articulos) {
      filas.push([
        a.codigo,
        a.descripcion,
        fmtQty(a.unidades),
        fmt(a.dto),
        fmt(a.importe),
        fmt(a.coste),
        fmt(a.margen),
        fmt(a.pjeMargen),
        fmt(a.pjeSobreTotal),
        fmt(a.mAgr),
      ])
    }
    const t = rowTotales(g.totales)
    filas.push(['TOTAL', '', t.unidades, t.dto, t.importe, t.coste, t.margen, t.pjeMargen, t.pjeSobreTotal, ''])
  }
  const tg = rowTotales(d.totales)
  filas.push([
    'TOTAL GENERAL',
    '',
    tg.unidades,
    tg.dto,
    tg.importe,
    tg.coste,
    tg.margen,
    tg.pjeMargen,
    '100,00',
    '',
  ])
  const meta = [
    `Periodo: ${d.fechaDesde} — ${d.fechaHasta}`,
    `${ivaLabel.value} · Orden: ${d.orden} · Dimensión: ${d.dimension}`,
    `Divisa: ${d.divisa || 'EU'}`,
  ]
  const u = auth.usuario?.nombre
  if (u) meta.push(`Usuario: ${u}`)
  meta.push(`Generado: ${new Date().toLocaleString('es-ES')}`)
  const res = imprimirListadoHtml({
    titulo: 'ABC de ventas',
    metaLineas: meta,
    thead,
    filas,
    pie: [
      `Importe total: ${fmt(d.totales.importe)}`,
      `Margen total: ${fmt(d.totales.margen)}`,
    ],
    filenameFallback: 'abc-ventas.html',
  })
  mensaje.value = res.message
}

function fmt(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function rowTotales(t: AbcVentasTotales) {
  return {
    unidades: fmtQty(t.unidades),
    dto: fmt(t.dto),
    importe: fmt(t.importe),
    coste: fmt(t.coste),
    margen: fmt(t.margen),
    pjeMargen: fmt(t.pjeMargen),
    pjeSobreTotal: fmt(t.pjeSobreTotal ?? 100),
  }
}
</script>

<template>
  <section class="abc-page">
    <div class="toolbar no-print">
      <div>
        <RouterLink to="/listados" class="volver-listados">← Listados</RouterLink>
        <h2>ABC de ventas</h2>
        <p class="toolbar-hint">Periodo y tienda arriba; el resto de rangos legacy en «Más filtros».</p>
      </div>
      <div class="toolbar-actions">
        <button type="button" class="btn primary" :disabled="loading" @click="generar">
          {{ loading ? 'Generando...' : 'Generar' }}
        </button>
        <button type="button" class="btn" :disabled="!data" @click="imprimir">Imprimir</button>
      </div>
    </div>

    <form class="panel no-print" @submit.prevent="generar">
      <fieldset class="principales">
        <legend>Filtros principales</legend>
        <div class="principales-grid">
          <div class="bloque-fechas">
            <span class="bloque-titulo">Fechas</span>
            <div class="fechas-inputs">
              <label>
                Desde
                <input v-model="form.fechaDesde" type="date" required class="filtro-fecha" />
              </label>
              <label>
                Hasta
                <input v-model="form.fechaHasta" type="date" required class="filtro-fecha" />
              </label>
            </div>
            <div class="atajos">
              <button type="button" @click="aplicarAtajoFecha('hoy')">Hoy</button>
              <button type="button" @click="aplicarAtajoFecha('mes')">Mes</button>
              <button type="button" @click="aplicarAtajoFecha('anio')">Año</button>
            </div>
          </div>

          <div class="bloque-rango">
            <span class="bloque-titulo">{{ rangoTienda.label }} (desde / hasta)</span>
            <div class="rango-par">
              <input
                class="filtro-input"
                type="text"
                :value="valorFiltro(rangoTienda.desde)"
                :maxlength="rangoTienda.maxLength"
                placeholder="Desde"
                autocomplete="off"
                @input="onFiltroInput(rangoTienda, 'desde', ($event.target as HTMLInputElement).value)"
              />
              <input
                class="filtro-input"
                type="text"
                :value="valorFiltro(rangoTienda.hasta)"
                :maxlength="rangoTienda.maxLength"
                placeholder="Hasta"
                autocomplete="off"
                @input="onFiltroInput(rangoTienda, 'hasta', ($event.target as HTMLInputElement).value)"
              />
            </div>
          </div>

          <label>
            Orden
            <select v-model="form.orden">
              <option value="margen">Margen</option>
              <option value="importe">Importe</option>
              <option value="cantidad">Cantidad</option>
            </select>
          </label>
          <label>
            IVA
            <select v-model="form.iva">
              <option value="incluido">Incluido</option>
              <option value="excluido">Excluido</option>
            </select>
          </label>
          <label>
            Tipo venta
            <select v-model="form.tipoVenta">
              <option value="todos">Todos</option>
              <option value="T">Ticket (T)</option>
              <option value="A">Albarán (A)</option>
              <option value="P">Presupuesto (P)</option>
              <option value="F">Factura (F)</option>
            </select>
          </label>
          <label>
            Agrupar por
            <select v-model="form.dimension">
              <option value="vendedores">Vendedor</option>
            </select>
          </label>
        </div>
      </fieldset>

      <details class="mas-filtros">
        <summary>Más filtros y opciones</summary>
        <div class="mas-filtros-body">
          <fieldset class="opciones-avanzadas">
            <legend>Opciones del informe</legend>
            <div class="opciones-grid">
              <label>
                Idioma
                <select v-model="form.idioma">
                  <option value="castellano">Castellano</option>
                </select>
              </label>
              <label>
                Divisa
                <select v-model="form.divisa">
                  <option value="EU">EU</option>
                </select>
              </label>
              <label>
                Imprimir artículos
                <select v-model="form.imArticulos">
                  <option :value="true">Sí</option>
                  <option :value="false">No</option>
                </select>
              </label>
              <label>
                Valor coste
                <select v-model="form.valor">
                  <option value="precioMedio">Precio medio</option>
                  <option value="precioUltimo">Precio último</option>
                </select>
              </label>
            </div>
          </fieldset>

          <fieldset class="rangos">
            <legend>Rangos desde / hasta</legend>
            <p class="rangos-hint">Vacío = sin límite. Misma semántica que el informe legacy.</p>
            <div v-for="r in rangosAvanzados" :key="r.label" class="rango-row">
              <span class="rango-label" :title="r.formato ? `Formato: ${r.formato}` : undefined">
                {{ r.label }}
              </span>
              <input
                class="filtro-input"
                :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                type="text"
                :value="valorFiltro(r.desde)"
                :maxlength="r.maxLength"
                :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                :placeholder="r.formato || 'Desde'"
                autocomplete="off"
                spellcheck="false"
                @input="onFiltroInput(r, 'desde', ($event.target as HTMLInputElement).value)"
              />
              <input
                class="filtro-input"
                :class="{ numerico: abcFiltroSoloDigitos(r.formato) }"
                type="text"
                :value="valorFiltro(r.hasta)"
                :maxlength="r.maxLength"
                :inputmode="abcFiltroSoloDigitos(r.formato) ? 'numeric' : 'text'"
                :placeholder="r.formato || 'Hasta'"
                autocomplete="off"
                spellcheck="false"
                @input="onFiltroInput(r, 'hasta', ($event.target as HTMLInputElement).value)"
              />
            </div>
          </fieldset>
        </div>
      </details>
    </form>

    <p v-if="error" class="error no-print">{{ error }}</p>
    <p v-if="mensaje" class="hint ok no-print">{{ mensaje }}</p>
    <p v-if="loading" class="hint no-print">Generando listado...</p>

    <template v-if="data">
      <div class="informe">
        <header class="informe-cab">
          <div class="cab-row">
            <strong>ABC VENTAS</strong>
            <span>{{ data.divisa || 'EU' }}</span>
            <span>
              Fecha {{ fmtFecha(data.fechaDesde) }} 00:00 - {{ fmtFecha(data.fechaHasta) }} 23:59
            </span>
          </div>
          <div class="cab-row">
            <span>Vendedor</span>
            <span>{{ ivaLabel }}</span>
            <span>Orden: {{ data.orden }} · Valor: {{ data.valor }}</span>
          </div>
        </header>

        <section v-for="g in data.grupos" :key="g.codigo || '(sin)'" class="grupo">
          <h3 class="grupo-tit">{{ g.codigo || '-' }} {{ g.nombre || '(sin nombre)' }}</h3>

          <div class="table-scroll">
            <table class="abc-table">
              <thead>
                <tr>
                  <th class="col-codigo">Codigo</th>
                  <th class="col-articulo">Articulo</th>
                  <th class="col-num">Unidades</th>
                  <th class="col-num">Dto.</th>
                  <th class="col-num">Importe</th>
                  <th class="col-num">Coste</th>
                  <th class="col-num">Margen</th>
                  <th class="col-pct">%Margen</th>
                  <th class="col-pct">%Sob.Tot</th>
                  <th class="col-num">M.Agr.</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in g.articulos" :key="a.codigo">
                  <td class="col-codigo">{{ a.codigo }}</td>
                  <td class="col-articulo" :title="a.descripcion">{{ a.descripcion }}</td>
                  <td class="col-num">{{ fmtQty(a.unidades) }}</td>
                  <td class="col-num">{{ fmt(a.dto) }}</td>
                  <td class="col-num">{{ fmt(a.importe) }}</td>
                  <td class="col-num">{{ fmt(a.coste) }}</td>
                  <td class="col-num">{{ fmt(a.margen) }}</td>
                  <td class="col-pct">{{ fmt(a.pjeMargen) }}</td>
                  <td class="col-pct">{{ fmt(a.pjeSobreTotal) }}</td>
                  <td class="col-num">{{ fmt(a.mAgr) }}</td>
                </tr>
                <tr class="total-row">
                  <td class="col-codigo" colspan="2"><strong>TOTAL</strong></td>
                  <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
                  <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
                  <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
                  <td class="col-pct">{{ rowTotales(g.totales).pjeSobreTotal }}</td>
                  <td class="col-num"></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <div class="table-scroll">
          <table class="abc-table total-general">
            <thead class="sr-only">
              <tr>
                <th class="col-codigo">Codigo</th>
                <th class="col-articulo">Articulo</th>
                <th class="col-num">Unidades</th>
                <th class="col-num">Dto.</th>
                <th class="col-num">Importe</th>
                <th class="col-num">Coste</th>
                <th class="col-num">Margen</th>
                <th class="col-pct">%Margen</th>
                <th class="col-pct">%Sob.Tot</th>
                <th class="col-num">M.Agr.</th>
              </tr>
            </thead>
            <tbody>
              <tr class="total-row">
                <td class="col-codigo" colspan="2"><strong>TOTAL GENERAL</strong></td>
                <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
                <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
                <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
                <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
                <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
                <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
                <td class="col-pct">100,00</td>
                <td class="col-num"></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.abc-page {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.toolbar h2 {
  margin: 0;
  font-size: 1.15rem;
}

.toolbar-actions {
  display: flex;
  gap: 0.4rem;
}

.btn {
  padding: 0.4rem 0.85rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  background: #fff;
  cursor: pointer;
  font: inherit;
}

.btn.primary {
  background: #0f172a;
  border-color: #0f172a;
  color: #fff;
}

.volver-listados {
  display: inline-block;
  font-size: 0.82rem;
  color: #475569;
  text-decoration: none;
  margin-bottom: 0.25rem;
}

.volver-listados:hover {
  color: #0f172a;
}

.toolbar-hint {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: #64748b;
  max-width: 28rem;
}

.principales-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr));
  gap: 0.75rem 1rem;
  align-items: end;
}

.principales-grid label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.75rem;
  color: #475569;
}

.principales-grid select {
  padding: 0.3rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}

.bloque-fechas {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 1.25rem;
  align-items: flex-end;
}

.bloque-titulo {
  display: block;
  width: 100%;
  font-size: 0.75rem;
  font-weight: 600;
  color: #475569;
}

.fechas-inputs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.75rem;
}

.fechas-inputs label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: #475569;
}

.atajos {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  align-items: center;
}

.atajos button {
  padding: 0.25rem 0.55rem;
  font-size: 0.78rem;
  border: 1px solid #cbd5e1;
  border-radius: 999px;
  background: #fff;
  cursor: pointer;
}

.atajos button:hover {
  border-color: #64748b;
}

.bloque-rango {
  min-width: 12rem;
}

.rango-par {
  display: flex;
  gap: 0.4rem;
}

.mas-filtros {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 0.45rem 0.65rem;
  background: #fff;
}

.mas-filtros summary {
  cursor: pointer;
  font-size: 0.88rem;
  font-weight: 600;
  color: #334155;
  user-select: none;
}

.mas-filtros-body {
  margin-top: 0.65rem;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.opciones-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(9.5rem, 1fr));
  gap: 0.55rem 0.75rem;
}

.opciones-grid label {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  font-size: 0.75rem;
  color: #475569;
}

.opciones-grid select {
  padding: 0.3rem 0.4rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  font: inherit;
  background: #fff;
}

.rangos-hint {
  margin: 0 0 0.5rem;
  font-size: 0.78rem;
  color: #64748b;
}

.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.panel {
  display: grid;
  gap: 0.75rem;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.75rem;
  background: #f8fafc;
}

fieldset {
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  margin: 0;
  padding: 0.6rem 0.75rem 0.75rem;
  background: #fff;
}

legend {
  padding: 0 0.35rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #334155;
}

.rangos {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.rango-row {
  display: grid;
  grid-template-columns: 7.5rem 9rem 9rem;
  gap: 0.35rem 0.5rem;
  align-items: center;
  justify-content: start;
}

.rango-label {
  font-size: 0.78rem;
  color: #334155;
  white-space: nowrap;
}

.filtro-input,
.filtro-fecha {
  width: 100%;
  box-sizing: border-box;
  padding: 0.22rem 0.35rem;
  border: 1px solid #94a3b8;
  border-radius: 3px;
  font: inherit;
  font-size: 0.8rem;
  background: #fff;
}

.filtro-input.numerico {
  font-variant-numeric: tabular-nums;
  font-family: ui-monospace, Consolas, monospace;
  text-align: right;
}

.error {
  color: #b91c1c;
  margin: 0;
}

.hint {
  margin: 0;
  color: #475569;
  font-size: 0.85rem;
}

.informe {
  background: #fff;
  border: 1px solid #94a3b8;
  border-radius: 4px;
  padding: 0.75rem;
  color: #0f172a;
}

.informe-cab {
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
}

.cab-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 0.2rem;
}

.grupo {
  margin-bottom: 1.25rem;
}

.grupo-tit {
  margin: 0 0 0.4rem;
  font-size: 0.95rem;
  font-weight: 700;
}

.table-scroll {
  overflow-x: auto;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
}

.abc-table {
  width: max-content;
  min-width: 0;
  max-width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.78rem;
}

.abc-table th,
.abc-table td {
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  padding: 0.28rem 0.4rem;
  vertical-align: middle;
}

.abc-table th:last-child,
.abc-table td:last-child {
  border-right: none;
}

.abc-table th {
  background: #e2e8f0;
  text-align: left;
  font-weight: 700;
  white-space: nowrap;
  position: sticky;
  top: 0;
  z-index: 1;
}

.abc-table tbody tr:nth-child(even):not(.total-row) {
  background: #f8fafc;
}

.col-codigo {
  width: 5.5rem;
  font-family: ui-monospace, Consolas, monospace;
}

.col-articulo {
  width: 14rem;
  max-width: 14rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.col-num,
.col-pct {
  width: 5.6rem;
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  font-family: ui-monospace, Consolas, monospace;
}

.col-pct {
  width: 4.8rem;
}

.abc-table th.col-num,
.abc-table th.col-pct {
  text-align: right;
}

.total-row td {
  border-top: 2px solid #475569;
  font-weight: 700;
  background: #f1f5f9;
}

.total-general {
  margin-top: 0.75rem;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}

@media print {
  .no-print {
    display: none !important;
  }

  .abc-page {
    gap: 0;
  }

  .informe {
    border: none;
    padding: 0;
  }

  .table-scroll {
    overflow: visible;
    border: none;
  }

  .abc-table {
    min-width: 0;
    font-size: 0.7rem;
  }

  .abc-table th {
    background: #ddd !important;
    position: static;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .grupo {
    page-break-inside: avoid;
  }
}
</style>
