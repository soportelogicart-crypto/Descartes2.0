import {
  etiquetaBloqueGrupoAbcCompras,
  etiquetaDimensionAbcCompras,
  etiquetaTotalGrupoAbcCompras,
} from '@/config/abc-compras-dimensiones'
import type { AbcComprasGrupo, AbcComprasResponse, AbcComprasTotales } from '@/types/compras'

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

export function abcComprasPjeSobreTotalGrupo(g: AbcComprasGrupo): number {
  const a = g.articulos[0]
  if (a?.pjeSobreTotal != null) return a.pjeSobreTotal
  return g.totales.pjeSobreTotal ?? 0
}

function filaArticulo(a: AbcComprasGrupo['articulos'][0], ocultaMAgr: boolean): string {
  return `<tr>
    <td>${esc(a.codigo)}</td>
    <td>${esc(a.descripcion)}</td>
    <td class="num">${esc(fmtQty(a.unidades))}</td>
    <td class="num">${esc(fmt(a.dto))}</td>
    <td class="num">${esc(fmt(a.importe))}</td>
    <td class="num">${esc(fmt(a.coste))}</td>
    <td class="num">${esc(fmt(a.margen))}</td>
    <td class="num">${esc(fmt(a.pjeMargen))}</td>
    <td class="num">${esc(fmt(a.pjeSobreTotal ?? 0))}</td>
    ${ocultaMAgr ? '' : `<td class="num">${esc(fmt(a.mAgr))}</td>`}
  </tr>`
}

function filaTotales(t: AbcComprasTotales, etiqueta: string, ocultaMAgr: boolean): string {
  return `<tr class="total">
    <td colspan="2"><strong>${esc(etiqueta)}</strong></td>
    <td class="num">${esc(fmtQty(t.unidades))}</td>
    <td class="num">${esc(fmt(t.dto))}</td>
    <td class="num">${esc(fmt(t.importe))}</td>
    <td class="num">${esc(fmt(t.coste))}</td>
    <td class="num">${esc(fmt(t.margen))}</td>
    <td class="num">${esc(fmt(t.pjeMargen ?? 0))}</td>
    <td class="num">${esc(fmt(t.pjeSobreTotal ?? 0))}</td>
    ${ocultaMAgr ? '' : '<td></td>'}
  </tr>`
}

function tablaGrupo(g: AbcComprasGrupo, d: AbcComprasResponse, ocultaMAgr: boolean): string {
  const dim = etiquetaBloqueGrupoAbcCompras(d.dimension)
  const headMAgr = ocultaMAgr ? '' : '<th>M.Agr.</th>'
  const extendido =
    (d.dimension === 'subfamilias' || d.dimension === 'familias') &&
    (d.formatoJerarquia ?? 'normal').trim().toLowerCase() === 'extendido'
  let extraJerarquia = ''
  if (extendido) {
    if (d.dimension === 'subfamilias') {
      extraJerarquia += `<p class="abc-meta">Familia ${esc(g.metaFamiliaCodigo ?? '')} ${esc(g.metaFamiliaNombre ?? '')}</p>`
    }
    extraJerarquia += `<p class="abc-meta">MacroFamilia ${esc(g.metaMacroCodigo ?? '')} ${esc(g.metaMacroNombre ?? '')}</p>`
  }
  let body = `<h3>${esc(dim)} ${esc(g.codigo)} ${esc(g.nombre)}</h3>${extraJerarquia}
<table class="abc-table"><thead><tr>
<th>Código</th><th>Artículo</th><th>Unidades</th><th>Dto</th><th>Importe</th><th>Coste</th><th>Margen</th><th>% Marg</th><th>% Sob.Tot</th>${headMAgr}
</tr></thead><tbody>`
  for (const a of g.articulos) body += filaArticulo(a, ocultaMAgr)
  if (d.mostrarTotalGrupo !== false) {
    body += filaTotales(g.totales, etiquetaTotalGrupoAbcCompras(d.dimension), ocultaMAgr)
  }
  body += '</tbody></table>'
  return body
}

function tablaPlana(d: AbcComprasResponse): string {
  const head = `<thead><tr>
    <th>Código</th><th>Artículo</th><th>Unidades</th><th>Dto</th><th>Importe</th><th>Coste</th><th>Margen</th><th>% Marg</th><th>% Sob.Tot</th>
  </tr></thead>`
  let rows = ''
  const extendidoArt =
    d.dimension === 'articulos' &&
    (d.formatoJerarquia ?? 'normal').trim().toLowerCase() === 'extendido'
  for (const g of d.grupos) {
    const t = g.totales
    const pje = abcComprasPjeSobreTotalGrupo(g)
    const nombreCell = extendidoArt
      ? `${esc(g.nombre)}<br/><small>Fam. ${esc(g.metaFamiliaCodigo ?? '')} ${esc(g.metaFamiliaNombre ?? '')}</small><br/><small>Macro ${esc(g.metaMacroCodigo ?? '')} ${esc(g.metaMacroNombre ?? '')}</small>`
      : esc(g.nombre)
    rows += `<tr>
      <td>${esc(g.codigo)}</td><td>${nombreCell}</td>
      <td class="num">${esc(fmtQty(t.unidades))}</td>
      <td class="num">${esc(fmt(t.dto))}</td>
      <td class="num">${esc(fmt(t.importe))}</td>
      <td class="num">${esc(fmt(t.coste))}</td>
      <td class="num">${esc(fmt(t.margen))}</td>
      <td class="num">${esc(fmt(t.pjeMargen ?? 0))}</td>
      <td class="num">${esc(fmt(pje))}</td>
    </tr>`
  }
  rows += filaTotales({ ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', true)
  return `<table class="abc-table">${head}<tbody>${rows}</tbody></table>`
}

export function construirHtmlInformeAbcCompras(
  d: AbcComprasResponse,
  opciones: { tituloCabecera: string; periodo: string; filtrosLinea?: string },
): string {
  const plano = d.dimension === 'articulos'
  const ocultaMAgr = plano
  let cuerpo = plano
    ? tablaPlana(d)
    : d.grupos.map((g) => tablaGrupo(g, d, ocultaMAgr)).join('') +
      `<table class="abc-table"><tbody>${filaTotales({ ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', ocultaMAgr)}</tbody></table>`

  const cab = [
    `<p><strong>${esc(opciones.tituloCabecera)}</strong></p>`,
    `<p>${esc(opciones.periodo)} · ${esc(etiquetaDimensionAbcCompras(d.dimension))} · Orden: ${esc(d.orden)} · Valor: ${esc(d.valor)}</p>`,
  ]
  if (opciones.filtrosLinea) cab.push(`<p>${esc(opciones.filtrosLinea)}</p>`)

  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opciones.tituloCabecera)}</title>
<style>
body{font-family:Arial,sans-serif;font-size:10px;margin:14mm}
.abc-table{width:100%;border-collapse:collapse;margin-bottom:12px}
.abc-table th,.abc-table td{border:1px solid #ccc;padding:2px 4px}
.abc-table th{background:#006f6f;color:#fff}
.num{text-align:right}
.total td{font-weight:700;border-top:2px solid #333}
</style></head><body>${cab.join('')}${cuerpo}</body></html>`
}
