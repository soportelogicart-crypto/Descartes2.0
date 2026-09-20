import {
  ABC_VENTAS_AGRUPACION_ARTICULOS,
  ABC_VENTAS_FORMATO_ARTICULOS,
  abcVentasFormatoArticulosMuestraComision,
} from '@/config/abc-ventas-opciones'
import { svgAbcHorasGraficoHtml } from '@/composables/abcVentasHorasGrafico'
import {
  etiquetaBloqueGrupoAbc,
  etiquetaDimensionAbc,
  etiquetaTotalGrupoAbc,
} from '@/config/abc-ventas-dimensiones'
import type {
  AbcVentasArticulo,
  AbcVentasBloque,
  AbcVentasGrupo,
  AbcVentasResponse,
  AbcVentasTotales,
} from '@/types/ventas'

function esc(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function fmt(n: number | undefined): string {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtQty(n: number | undefined): string {
  return (n ?? 0).toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

/** VentasABC Art — listado plano Crystal (una fila por artículo, sin bloques ARTICULO). */
/** VentasABC Horas — tabla plana legacy (franja + tickets). */
export function abcVentasUsaInformePlanoHorasAbc(d: AbcVentasResponse): boolean {
  if (d.dimension === 'dias-semana' && d.informePlanoDiasSemanaAbc === true) {
    return true
  }
  return (
    d.dimension === 'horas' &&
    d.tipoGestionHoras !== 'ventaHoraria' &&
    (d.informePlanoHorasAbc === true || (d.bloques?.length ?? 0) === 0)
  )
}

export function abcVentasUsaTablaPlanaLegacy(d: AbcVentasResponse): boolean {
  if (d.dimension !== 'articulos') return false
  const fmtArt = d.formatoArticulos ?? 'normal'
  if (fmtArt !== 'normal') return false
  const agr = d.agrupacionArticulos ?? 'sinAgrupacion'
  return agr === 'sinAgrupacion'
}

/** @deprecated alias */
export function abcVentasUsaTablaContinua(d: AbcVentasResponse): boolean {
  return abcVentasUsaTablaPlanaLegacy(d)
}

export function abcVentasOcultaColumnaMAgr(d: AbcVentasResponse): boolean {
  return abcVentasUsaTablaPlanaLegacy(d)
}

function etiquetaBloqueGeo(d: AbcVentasResponse, b: AbcVentasBloque): string {
  const agrArt = d.agrupacionArticulos ?? ''
  if (agrArt && agrArt !== 'sinAgrupacion') {
    const pref =
      ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === agrArt)?.label?.toUpperCase() ??
      'GRUPO'
    return `${pref}: ${b.nombre || b.codigo}`
  }
  const agr = d.agrupacionClientes ?? ''
  if (agr === 'provincia') return `PROVINCIA: ${b.nombre || b.codigo}`
  return `C.P.: ${b.nombre || b.codigo}`
}

function etiquetaTotalBloqueGeo(d: AbcVentasResponse): string {
  const agrArt = d.agrupacionArticulos ?? ''
  if (agrArt && agrArt !== 'sinAgrupacion') {
    const pref =
      ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === agrArt)?.label?.toUpperCase() ??
      'GRUPO'
    return `TOTAL ${pref}`
  }
  const agr = d.agrupacionClientes ?? ''
  return agr === 'provincia' ? 'TOTAL PROVINCIA' : 'TOTAL C.P.'
}

export function abcVentasPjeSobreTotalGrupo(g: AbcVentasGrupo): number {
  const a = g.articulos[0]
  if (a?.pjeSobreTotal != null) return a.pjeSobreTotal
  return g.totales.pjeSobreTotal ?? 0
}

function pjeSobreTotalGrupo(g: AbcVentasGrupo): number {
  return abcVentasPjeSobreTotalGrupo(g)
}

function filaGrupoPlano(g: AbcVentasGrupo): string {
  const t = g.totales
  const pje = pjeSobreTotalGrupo(g)
  return `<tr>
    <td class="col-codigo">${esc(g.codigo || '')}</td>
    <td class="col-articulo">${esc(g.nombre || '')}</td>
    <td class="col-num">${esc(fmtQty(t.unidades))}</td>
    <td class="col-num">${esc(fmt(t.dto))}</td>
    <td class="col-num">${esc(fmt(t.importe))}</td>
    <td class="col-num">${esc(fmt(t.coste))}</td>
    <td class="col-num">${esc(fmt(t.margen))}</td>
    <td class="col-pct">${esc(fmt(t.pjeMargen))}</td>
    <td class="col-pct">${esc(fmt(pje))}</td>
  </tr>`
}

function matrizCrosstabHtml(
  m: NonNullable<AbcVentasResponse['matrizVentaHoraria']>,
  tableClass: string,
): string {
  if (!m.columnas.length) return ''

  const fmtCell = (n: number | undefined) =>
    m.metrica === 'unidades' ? fmtQty(n) : fmt(n)

  const thCols = m.columnas
    .map((c) => `<th class="col-num matriz-col-head">${esc(c.nombre)}</th>`)
    .join('')
  const filas = m.filas
    .map((f) => {
      const label = esc((f.nombre || f.codigo || '').toUpperCase())
      const celdas = m.columnas
        .map((c) => `<td class="col-num">${esc(fmtCell(f.celdas[c.id]))}</td>`)
        .join('')
      return `<tr><th class="matriz-fila-label" scope="row">${label}</th>${celdas}<td class="col-num matriz-total-col">${esc(fmtCell(f.total))}</td></tr>`
    })
    .join('')
  const totCols = m.columnas
    .map((c) => `<td class="col-num">${esc(fmtCell(m.totalesColumna[c.id]))}</td>`)
    .join('')

  return `<table class="abc-table ${tableClass}">
<thead><tr><th class="matriz-fila-head"></th>${thCols}<th class="col-num matriz-col-head matriz-total-col">Total</th></tr></thead>
<tbody>${filas}<tr class="total-row matriz-total-row"><th class="matriz-fila-label" scope="row">Total</th>${totCols}<td class="col-num matriz-total-col">${esc(fmtCell(m.totalGeneral))}</td></tr></tbody>
</table>`
}

function matrizInformeHtml(d: AbcVentasResponse): { html: string; tableClass: string } | null {
  if (d.matrizVentaHoraria?.columnas.length) {
    return {
      html: matrizCrosstabHtml(d.matrizVentaHoraria, 'matriz-venta-horaria'),
      tableClass: 'matriz-venta-horaria',
    }
  }
  if (d.matrizSemanal?.columnas.length) {
    return {
      html: matrizCrosstabHtml(d.matrizSemanal, 'matriz-semanal'),
      tableClass: 'matriz-semanal',
    }
  }
  return null
}

function filaTotalesPlanoLegacy(t: AbcVentasTotales, etiqueta: string, extraClass = ''): string {
  const pje = t.pjeSobreTotal ?? 100
  return `<tr class="total-row ${extraClass}">
    <td class="col-codigo"></td>
    <td class="col-articulo lab-tot"><strong>${esc(etiqueta)}</strong></td>
    <td class="col-num">${esc(fmtQty(t.unidades))}</td>
    <td class="col-num">${esc(fmt(t.dto))}</td>
    <td class="col-num">${esc(fmt(t.importe))}</td>
    <td class="col-num">${esc(fmt(t.coste))}</td>
    <td class="col-num">${esc(fmt(t.margen))}</td>
    <td class="col-pct">${esc(fmt(t.pjeMargen))}</td>
    <td class="col-pct">${esc(fmt(pje))}</td>
  </tr>`
}

function theadPlanoLegacy(): string {
  return `<thead><tr>
    <th class="col-codigo">Codigo</th>
    <th class="col-articulo">Articulo</th>
    <th class="col-num">Unidades</th>
    <th class="col-num">Descuent</th>
    <th class="col-num">Importe</th>
    <th class="col-num">Coste</th>
    <th class="col-num">Margen</th>
    <th class="col-pct">% Margen</th>
    <th class="col-pct">% Sob.Tot</th>
  </tr></thead>`
}

function tablaPlanaLegacyHtml(
  grupos: AbcVentasGrupo[],
  totales: AbcVentasTotales,
  opts: { etiquetaTotal?: string; totalGeneral?: boolean },
): string {
  const filas = grupos.map((g) => filaGrupoPlano(g)).join('')
  let pie = ''
  if (opts.etiquetaTotal) {
    pie += filaTotalesPlanoLegacy(totales, opts.etiquetaTotal, 'total-intermedio')
  }
  if (opts.totalGeneral) {
    pie += filaTotalesPlanoLegacy(
      { ...totales, pjeSobreTotal: 100 },
      'TOTAL GENERAL',
      'total-general',
    )
  }
  return `<table class="abc-table abc-legacy">${theadPlanoLegacy()}<tbody>${filas}${pie}</tbody></table>`
}

function colspanEtiqueta(d: AbcVentasResponse): number {
  return 2 + (d.formatoArticulos === 'extendido' ? 2 : 0)
}

function celdasArticulo(a: AbcVentasArticulo, d: AbcVentasResponse): string {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const extendido = d.formatoArticulos === 'extendido'
  const cols = [
    `<td class="col-codigo">${esc(a.codigo)}</td>`,
    `<td class="col-articulo">${esc(a.descripcion)}</td>`,
  ]
  if (extendido) {
    cols.push(
      `<td class="col-articulo">${esc(`${a.familia ?? ''} ${a.familiaNombre ?? ''}`.trim())}</td>`,
      `<td class="col-articulo">${esc(`${a.proveedor ?? ''} ${a.proveedorNombre ?? ''}`.trim())}</td>`,
    )
  }
  cols.push(
    `<td class="col-num">${esc(fmtQty(a.unidades))}</td>`,
    `<td class="col-num">${esc(fmt(a.dto))}</td>`,
    `<td class="col-num">${esc(fmt(a.importe))}</td>`,
    `<td class="col-num">${esc(fmt(a.coste))}</td>`,
    `<td class="col-num">${esc(fmt(a.margen))}</td>`,
    `<td class="col-pct">${esc(fmt(a.pjeMargen))}</td>`,
  )
  if (muestraComision) {
    cols.push(`<td class="col-num">${esc(a.comision != null ? fmt(a.comision) : '')}</td>`)
  }
  cols.push(
    `<td class="col-pct">${esc(fmt(a.pjeSobreTotal))}</td>`,
    `<td class="col-num">${esc(fmt(a.mAgr))}</td>`,
  )
  return cols.join('')
}

function filaTotales(t: AbcVentasTotales, etiqueta: string, d: AbcVentasResponse): string {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const colspan = colspanEtiqueta(d)
  const celdas = [
    `<td class="col-codigo" colspan="${colspan}"><strong>${esc(etiqueta)}</strong></td>`,
    `<td class="col-num">${esc(fmtQty(t.unidades))}</td>`,
    `<td class="col-num">${esc(fmt(t.dto))}</td>`,
    `<td class="col-num">${esc(fmt(t.importe))}</td>`,
    `<td class="col-num">${esc(fmt(t.coste))}</td>`,
    `<td class="col-num">${esc(fmt(t.margen))}</td>`,
    `<td class="col-pct">${esc(fmt(t.pjeMargen))}</td>`,
  ]
  if (muestraComision) {
    celdas.push(`<td class="col-num">${esc(t.comision != null ? fmt(t.comision) : '')}</td>`)
  }
  celdas.push(
    `<td class="col-pct">${esc(fmt(t.pjeSobreTotal ?? 100))}</td>`,
    `<td class="col-num"></td>`,
  )
  return `<tr class="total-row">${celdas.join('')}</tr>`
}

function theadHtml(d: AbcVentasResponse): string {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const extendido = d.formatoArticulos === 'extendido'
  const colSec = d.formatoArticulos === 'detalleComision' ? 'Vendedor' : 'Articulo'
  const th = [
    '<th class="col-codigo">Codigo</th>',
    `<th class="col-articulo">${esc(colSec)}</th>`,
  ]
  if (extendido) {
    th.push('<th class="col-articulo">Familia</th>', '<th class="col-articulo">Proveedor</th>')
  }
  th.push(
    '<th class="col-num">Unidades</th>',
    '<th class="col-num">Dto.</th>',
    '<th class="col-num">Importe</th>',
    '<th class="col-num">Coste</th>',
    '<th class="col-num">Margen</th>',
    '<th class="col-pct">%Margen</th>',
  )
  if (muestraComision) th.push('<th class="col-num">Comision</th>')
  th.push('<th class="col-pct">%Sob.Tot</th>', '<th class="col-num">M.Agr.</th>')
  return `<thead><tr>${th.join('')}</tr></thead>`
}

function filaHorasAbcPlano(g: AbcVentasGrupo): string {
  const t = g.totales
  const pje = pjeSobreTotalGrupo(g)
  return `<tr>
    <td class="col-codigo">${esc(g.codigo)}</td>
    <td class="col-num">${esc(String(t.tickets ?? 0))}</td>
    <td class="col-num">${esc(fmtQty(t.unidades))}</td>
    <td class="col-num">${esc(fmt(t.dto))}</td>
    <td class="col-num">${esc(fmt(t.importe))}</td>
    <td class="col-num">${esc(fmt(t.coste))}</td>
    <td class="col-num">${esc(fmt(t.margen))}</td>
    <td class="col-pct">${esc(fmt(t.pjeMargen))}</td>
    <td class="col-pct">${esc(fmt(pje))}</td>
  </tr>`
}

function filaTotalesHorasAbcPlano(t: AbcVentasTotales, etiqueta: string): string {
  const pje = t.pjeSobreTotal ?? 100
  return `<tr class="total-row total-general">
    <td class="col-codigo lab-tot"><strong>${esc(etiqueta)}</strong></td>
    <td class="col-num">${esc(String(t.tickets ?? 0))}</td>
    <td class="col-num">${esc(fmtQty(t.unidades))}</td>
    <td class="col-num">${esc(fmt(t.dto))}</td>
    <td class="col-num">${esc(fmt(t.importe))}</td>
    <td class="col-num">${esc(fmt(t.coste))}</td>
    <td class="col-num">${esc(fmt(t.margen))}</td>
    <td class="col-pct">${esc(fmt(t.pjeMargen))}</td>
    <td class="col-pct">${esc(fmt(pje))}</td>
  </tr>`
}

function tablaHorasAbcPlanoHtml(d: AbcVentasResponse): string {
  const filas = d.grupos.map((g) => filaHorasAbcPlano(g)).join('')
  const pie = filaTotalesHorasAbcPlano({ ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL').replace(
    'class="col-codigo lab-tot"',
    'class="col-codigo col-total-horas lab-tot"',
  )
  const thead = `<thead><tr>
    <th class="col-codigo">Codigo</th>
    <th class="col-num">Tickets</th>
    <th class="col-num">Unidades</th>
    <th class="col-num">Descuento</th>
    <th class="col-num">Importe</th>
    <th class="col-num">Coste</th>
    <th class="col-num">Margen</th>
    <th class="col-pct">% Margen</th>
    <th class="col-pct">% Sob. Tot</th>
  </tr></thead>`
  const grafico =
    d.graficoPor === 'unidades' || d.graficoPor === 'importe'
      ? svgAbcHorasGraficoHtml(d.grupos, d.graficoPor)
      : svgAbcHorasGraficoHtml(d.grupos, 'importe')
  return `<table class="abc-table abc-legacy abc-horas-plano">${thead}<tbody>${filas}${pie}</tbody></table>${grafico}`
}

function tablaGrupoHtml(g: AbcVentasGrupo, d: AbcVentasResponse): string {
  const dimLabel = etiquetaBloqueGrupoAbc(d.dimension)
  const filasArt = g.articulos.map((a) => `<tr>${celdasArticulo(a, d)}</tr>`).join('')
  const salto = g.saltoPagina ? ' salto-pagina' : ''
  return `
<section class="grupo${salto}">
  <div class="grupo-tit">
    <div class="grupo-dim">${esc(dimLabel)}</div>
    <div class="grupo-det">${esc(g.codigo || '-')} ${esc(g.nombre || '')}</div>
  </div>
  <table class="abc-table">
    ${theadHtml(d)}
    <tbody>
      ${filasArt}
      ${filaTotales(g.totales, etiquetaTotalGrupoAbc(d.dimension), d)}
    </tbody>
  </table>
</section>`
}

const LEGACY_PRINT_CSS = `
@page { margin: 14mm; }
body { font-family: Arial, Helvetica, sans-serif; font-size: 10px; color: #111; margin: 0; }
.legacy-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; margin-bottom: 4px; }
.legacy-tit { font-size: 22px; font-weight: 700; font-style: italic; color: #006f6f; margin: 0; }
.legacy-tit-line { border: none; border-top: 2px solid #006f6f; margin: 2px 0 6px; }
.legacy-fecha { display: flex; align-items: center; gap: 4px; font-size: 9px; }
.legacy-fecha-lab { background: #006f6f; color: #fff; padding: 2px 6px; font-weight: 700; }
.legacy-fecha-val { background: #004d4d; color: #fff; padding: 2px 6px; }
.legacy-impresion { font-size: 9px; margin-bottom: 6px; }
.legacy-sep { border: none; border-top: 3px solid #6b2d2d; margin: 0 0 8px; }
.abc-legacy { width: 100%; border-collapse: collapse; font-size: 9px; }
.abc-legacy th, .abc-legacy td { border: none; padding: 2px 5px; vertical-align: top; }
.abc-legacy thead th { background: #006f6f; color: #fff; font-weight: 700; text-align: left; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.abc-legacy th.col-num, .abc-legacy td.col-num, .abc-legacy th.col-pct, .abc-legacy td.col-pct { text-align: right; }
.abc-legacy .lab-tot { text-align: right; }
.abc-legacy tr.total-intermedio td { border-top: 1px solid #333; font-weight: 700; }
.abc-legacy tr.total-general td { border-top: 1px solid #333; border-bottom: 3px double #333; font-weight: 700; }
.col-codigo { width: 7%; }
.col-articulo { width: 28%; }
`

/** HTML impresión legacy (ABC Artículos plano). */
export function construirHtmlInformeAbcVentas(
  d: AbcVentasResponse,
  opciones: {
    tituloCabecera: string
    periodo: string
    ivaLabel: string
    filtrosLinea?: string
  },
): string {
  const legacyPlano = abcVentasUsaTablaPlanaLegacy(d)
  const tituloLegacy = `ABC VENTAS ${esc(d.divisa || 'EU')}`
  const impresion = new Date().toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })

  let cuerpo = ''

  const matrizInforme = matrizInformeHtml(d)
  if (matrizInforme) {
    cuerpo = matrizInforme.html
    const titulo = esc(opciones.tituloCabecera)
    const impresion = new Date().toLocaleString('es-ES', {
      day: '2-digit',
      month: '2-digit',
      year: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
    const [desde, hasta] = opciones.periodo.replace(/^Fecha\s*/, '').split(' - ')
    const matrizCss = `
.matriz-venta-horaria { width: 100%; border-collapse: collapse; font-size: 9px; }
.matriz-venta-horaria th, .matriz-venta-horaria td { border: 1px solid #111; padding: 3px 5px; }
.matriz-venta-horaria .matriz-fila-label { text-align: left; font-weight: 700; }
.matriz-venta-horaria .matriz-col-head, .matriz-venta-horaria .col-num { text-align: center; font-weight: 700; }
.matriz-venta-horaria .matriz-total-row th, .matriz-venta-horaria .matriz-total-row td { font-weight: 700; }
`
    return `<!doctype html><html><head><meta charset="utf-8"><title>${titulo}</title>
<style>body{font-family:Arial,sans-serif;font-size:10px;margin:14mm}${matrizCss}</style></head><body>
<h1 style="font-size:14px;margin:0 0 4px">${titulo}</h1>
<p style="margin:0 0 8px">Fecha de Impresión: ${esc(impresion)} · ${esc(desde || '')} – ${esc(hasta || '')}</p>
${cuerpo}
</body></html>`
  }

  if (abcVentasUsaInformePlanoHorasAbc(d)) {
    cuerpo = tablaHorasAbcPlanoHtml(d)
    const titulo = esc(opciones.tituloCabecera)
    const impresion = new Date().toLocaleString('es-ES', {
      day: '2-digit',
      month: '2-digit',
      year: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
    const [desde, hasta] = opciones.periodo.replace(/^Fecha\s*/, '').split(' - ')
    return `<!doctype html><html><head><meta charset="utf-8"><title>${titulo}</title>
<style>${LEGACY_PRINT_CSS}
.abc-table.abc-legacy.abc-horas-plano{width:max-content;max-width:100%;table-layout:auto;font-size:8px}
.abc-table.abc-legacy.abc-horas-plano th,.abc-table.abc-legacy.abc-horas-plano td{border:1px solid #ccc;padding:1px 4px}
.abc-table.abc-legacy.abc-horas-plano .col-codigo{width:auto;padding-right:4px;white-space:nowrap}
.abc-table.abc-legacy.abc-horas-plano .col-total-horas{text-align:right;white-space:nowrap}
.abc-table.abc-legacy.abc-horas-plano .col-num,.abc-table.abc-legacy.abc-horas-plano .col-pct{width:auto;text-align:right}
.abc-horas-grafico{margin-top:12px}
</style></head><body>
<div class="legacy-top">
  <div><h1 class="legacy-tit">${titulo}</h1><hr class="legacy-tit-line" /></div>
  <div class="legacy-fecha">
    <span class="legacy-fecha-lab">Fecha</span>
    <span class="legacy-fecha-val">${esc(desde || '')}</span>
    <span class="legacy-fecha-val">${esc(hasta || '')}</span>
  </div>
</div>
<p class="legacy-impresion">Fecha de Impresión: ${esc(impresion)}</p>
<hr class="legacy-sep" />
${cuerpo}
</body></html>`
  } else if (legacyPlano) {
    if (d.bloques?.length) {
      for (const b of d.bloques) {
        cuerpo += `<h2 class="bloque-geo-tit">${esc(etiquetaBloqueGeo(d, b))}</h2>`
        cuerpo += tablaPlanaLegacyHtml(b.grupos, b.totales, {
          etiquetaTotal: etiquetaTotalBloqueGeo(d),
        })
      }
      cuerpo += tablaPlanaLegacyHtml([], d.totales, { totalGeneral: true })
    } else {
      cuerpo += tablaPlanaLegacyHtml(d.grupos, d.totales, {
        etiquetaTotal: 'TOTAL',
        totalGeneral: true,
      })
    }
  } else if (d.bloques?.length) {
    for (const b of d.bloques) {
      cuerpo += `<section class="bloque-geo"><h2 class="bloque-geo-tit">${esc(etiquetaBloqueGeo(d, b))}</h2>`
      for (const g of b.grupos) cuerpo += tablaGrupoHtml(g, d)
      cuerpo += `<table class="abc-table"><tbody>${filaTotales(b.totales, etiquetaTotalBloqueGeo(d), d)}</tbody></table></section>`
    }
    cuerpo += `<table class="abc-table"><tbody>${filaTotales({ ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', d)}</tbody></table>`
  } else {
    for (const g of d.grupos) cuerpo += tablaGrupoHtml(g, d)
    cuerpo += `<table class="abc-table"><tbody>${filaTotales({ ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', d)}</tbody></table>`
  }

  if (legacyPlano) {
    const [desde, hasta] = opciones.periodo.replace(/^Fecha\s*/, '').split(' - ')
    return `<!doctype html><html><head><meta charset="utf-8"><title>${tituloLegacy}</title>
<style>${LEGACY_PRINT_CSS}</style></head><body>
<div class="legacy-top">
  <div><h1 class="legacy-tit">${tituloLegacy}</h1><hr class="legacy-tit-line" /></div>
  <div class="legacy-fecha">
    <span class="legacy-fecha-lab">Fecha</span>
    <span class="legacy-fecha-val">${esc(desde || '')}</span>
    <span class="legacy-fecha-val">${esc(hasta || '')}</span>
  </div>
</div>
<p class="legacy-impresion">Fecha de Impresión: ${esc(impresion)}</p>
<hr class="legacy-sep" />
${cuerpo}
</body></html>`
  }

  const cabRows = [
    `<div class="cab-row"><strong>${esc(opciones.tituloCabecera)}</strong><span>${esc(d.divisa || 'EU')}</span><span>${esc(opciones.periodo)}</span></div>`,
    `<div class="cab-row"><span>${esc(etiquetaDimensionAbc(d.dimension))}</span><span>${esc(opciones.ivaLabel)}</span><span>Orden: ${esc(d.orden)} · Valor: ${esc(d.valor)}</span></div>`,
  ]
  if (d.agrupacionArticulos) {
    const agrLabel =
      ABC_VENTAS_AGRUPACION_ARTICULOS.find((o) => o.value === d.agrupacionArticulos)?.label ??
      d.agrupacionArticulos
    cabRows.push(`<div class="cab-row"><span>Agrupación: ${esc(agrLabel)}</span></div>`)
  }

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opciones.tituloCabecera)}</title>
<style>body{font-family:Arial,sans-serif;font-size:10px}.cab-row{margin-bottom:3px}.abc-table{width:100%;border-collapse:collapse}.abc-table th,.abc-table td{border:1px solid #ccc;padding:2px 4px}</style>
</head><body>${cabRows.join('')}${cuerpo}</body></html>`
}
