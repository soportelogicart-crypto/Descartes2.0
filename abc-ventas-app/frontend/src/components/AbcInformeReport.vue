<script setup lang="ts">
import { computed } from 'vue'
import type { AbcVentasResponse, AbcVentasTotales } from '@/types/abc-ventas'
import { labelIva, labelOrden, labelValor, t } from '@/i18n/abc-ventas'

const props = defineProps<{
  data: AbcVentasResponse
  titulo: string
  vendedorLabel: string
}>()

const idioma = computed(() => props.data.idioma || 'castellano')
const tr = (key: string) => t(idioma.value, key)
const ivaLabel = computed(() => labelIva(idioma.value, props.data.iva))

function fmtFecha(iso: string) {
  const [y, m, d] = iso.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y.slice(2)}`
}

function fmt(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(n: number | undefined) {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function rowTotales(tot: AbcVentasTotales) {
  return {
    unidades: fmtQty(tot.unidades),
    dto: fmt(tot.dto),
    importe: fmt(tot.importe),
    coste: fmt(tot.coste),
    margen: fmt(tot.margen),
    pjeMargen: fmt(tot.pjeMargen),
    pjeSobreTotal: fmt(tot.pjeSobreTotal ?? 100),
    mAgr: fmt(tot.mAgr),
  }
}
</script>

<template>
  <div class="informe">
    <header class="informe-cab">
      <div class="cab-row">
        <strong>{{ titulo }}</strong>
        <span>{{ data.divisa || 'EU' }}</span>
        <span>
          {{ tr('fecha') }} {{ fmtFecha(data.fechaDesde) }} 00:00 - {{ fmtFecha(data.fechaHasta) }} 23:59
        </span>
      </div>
      <div class="cab-row">
        <span>{{ tr('vendedor') }}: {{ vendedorLabel || data.grupos[0]?.codigo || '-' }}</span>
        <span>{{ ivaLabel }}</span>
        <span>
          {{ tr('orden') }}: {{ labelOrden(idioma, data.orden) }} ·
          {{ tr('valor') }}: {{ labelValor(idioma, data.valor) }}
        </span>
      </div>
    </header>

    <section v-for="g in data.grupos" :key="g.codigo || '(sin)'" class="grupo">
      <h3 class="grupo-tit">{{ g.codigo || '-' }} {{ g.nombre || tr('sinNombre') }}</h3>

      <div class="table-scroll">
        <table class="abc-table">
          <thead>
            <tr>
              <th class="col-codigo">{{ tr('codigo') }}</th>
              <th class="col-articulo">{{ tr('articulo') }}</th>
              <th class="col-num">{{ tr('unidades') }}</th>
              <th class="col-num">{{ tr('dto') }}</th>
              <th class="col-num">{{ tr('importe') }}</th>
              <th class="col-num">{{ tr('coste') }}</th>
              <th class="col-num">{{ tr('margen') }}</th>
              <th class="col-pct">{{ tr('pjeMargen') }}</th>
              <th class="col-pct">{{ tr('pjeSobTot') }}</th>
              <th class="col-num">{{ tr('mAgr') }}</th>
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
              <td class="col-codigo" colspan="2"><strong>{{ tr('total') }}</strong></td>
              <td class="col-num">{{ rowTotales(g.totales).unidades }}</td>
              <td class="col-num">{{ rowTotales(g.totales).dto }}</td>
              <td class="col-num">{{ rowTotales(g.totales).importe }}</td>
              <td class="col-num">{{ rowTotales(g.totales).coste }}</td>
              <td class="col-num">{{ rowTotales(g.totales).margen }}</td>
              <td class="col-pct">{{ rowTotales(g.totales).pjeMargen }}</td>
              <td class="col-pct">{{ rowTotales(g.totales).pjeSobreTotal }}</td>
              <td class="col-num">{{ rowTotales(g.totales).mAgr }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <div class="table-scroll">
      <table class="abc-table total-general">
        <thead class="sr-only">
          <tr>
            <th class="col-codigo">{{ tr('codigo') }}</th>
            <th class="col-articulo">{{ tr('articulo') }}</th>
            <th class="col-num">{{ tr('unidades') }}</th>
            <th class="col-num">{{ tr('dto') }}</th>
            <th class="col-num">{{ tr('importe') }}</th>
            <th class="col-num">{{ tr('coste') }}</th>
            <th class="col-num">{{ tr('margen') }}</th>
            <th class="col-pct">{{ tr('pjeMargen') }}</th>
            <th class="col-pct">{{ tr('pjeSobTot') }}</th>
            <th class="col-num">{{ tr('mAgr') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr class="total-row">
            <td class="col-codigo" colspan="2"><strong>{{ tr('totalGeneral') }}</strong></td>
            <td class="col-num">{{ rowTotales(data.totales).unidades }}</td>
            <td class="col-num">{{ rowTotales(data.totales).dto }}</td>
            <td class="col-num">{{ rowTotales(data.totales).importe }}</td>
            <td class="col-num">{{ rowTotales(data.totales).coste }}</td>
            <td class="col-num">{{ rowTotales(data.totales).margen }}</td>
            <td class="col-pct">{{ rowTotales(data.totales).pjeMargen }}</td>
            <td class="col-pct">100,00</td>
            <td class="col-num">{{ rowTotales(data.totales).mAgr }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.informe {
  background: #fff;
  border: 1px solid var(--line);
  padding: 1rem 1.05rem 1.4rem;
  color: var(--ink);
  box-shadow: 0 10px 28px rgba(15, 36, 48, 0.08);
}

.informe-cab {
  margin-bottom: 0.95rem;
  padding-bottom: 0.7rem;
  border-bottom: 1px solid var(--line);
  font-size: 0.85rem;
}

.cab-row {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 0.25rem;
  color: var(--ink-soft);
}

.cab-row strong {
  color: var(--ink);
  font-family: var(--font-display);
  font-size: 1rem;
  letter-spacing: -0.01em;
}

.grupo {
  margin-bottom: 1.35rem;
}

.grupo-tit {
  margin: 0 0 0.45rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--ink);
}

.table-scroll {
  overflow-x: auto;
  border: 1px solid var(--line);
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
  border-right: 1px solid #ebe6dc;
  border-bottom: 1px solid #ebe6dc;
  padding: 0.32rem 0.45rem;
  vertical-align: middle;
}

.abc-table th:last-child,
.abc-table td:last-child {
  border-right: none;
}

.abc-table th {
  background: #e8e3d8;
  text-align: left;
  font-weight: 600;
  white-space: nowrap;
  color: var(--ink-soft);
}

.abc-table tbody tr:nth-child(even):not(.total-row) {
  background: #f7f4ee;
}

.col-codigo {
  width: 5.5rem;
  font-family: var(--font-mono);
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
  font-family: var(--font-mono);
}

.col-pct {
  width: 4.8rem;
}

.abc-table th.col-num,
.abc-table th.col-pct {
  text-align: right;
}

.total-row td {
  border-top: 2px solid var(--ink-soft);
  font-weight: 700;
  background: #e4ebe9;
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
  .informe {
    border: none;
    box-shadow: none;
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

  .abc-table th,
  .total-row td {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }

  .grupo {
    page-break-inside: avoid;
  }
}
</style>
