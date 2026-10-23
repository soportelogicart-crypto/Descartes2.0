import {
  etiquetaBloqueGrupoAbcCompras,
  etiquetaDimensionAbcCompras,
  etiquetaTotalGrupoAbcCompras,
} from '@/config/abc-compras-dimensiones'
import { ESTILOS_LISTADO_A4 } from '@/composables/listadoPrintStyles'
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

const ESTILO_ABC = `
.abc-grupo { margin-top: 4mm; }
.abc-grupo-tit {
  margin: 0 0 1.5mm;
  color: #0f172a;
  font-size: 11px;
  break-after: avoid;
  page-break-after: avoid;
}
.abc-grupo-tit .dim { color: #007f7f; font-weight: 700; margin-right: 2mm; }
.listado-tabla td.num, .listado-tabla th.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
.listado-tabla tr.total td { background: #ecfeff; font-weight: 700; border-top: 0.4mm solid #0f766e; }
.abc-grafico { margin: 4mm 0 0; break-inside: avoid; page-break-inside: avoid; }
.abc-grafico figcaption { margin: 0 0 1.5mm; color: #475569; font-size: 9px; }
.abc-grafico svg { width: 100%; height: auto; }
`

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

function filaTotales(
  etiqueta: string,
  t: AbcComprasTotales,
  pjeSobre: number,
  ocultaMAgr: boolean,
): string {
  return `<tr class="total">
    <td colspan="2"><strong>${esc(etiqueta)}</strong></td>
    <td class="num">${esc(fmtQty(t.unidades))}</td>
    <td class="num">${esc(fmt(t.dto))}</td>
    <td class="num">${esc(fmt(t.importe))}</td>
    <td class="num">${esc(fmt(t.coste))}</td>
    <td class="num">${esc(fmt(t.margen))}</td>
    <td class="num">${esc(fmt(t.pjeMargen ?? 0))}</td>
    <td class="num">${esc(fmt(pjeSobre))}</td>
    ${ocultaMAgr ? '' : '<td></td>'}
  </tr>`
}

function cabeceraTabla(ocultaMAgr: boolean, etiquetaUnidades: string): string {
  return `<tr>
    <th>Código</th><th>Descripción</th>
    <th class="num">${esc(etiquetaUnidades)}</th><th class="num">Dto</th><th class="num">Importe</th>
    <th class="num">Coste</th><th class="num">Margen</th><th class="num">% Marg</th><th class="num">% Sob.Tot</th>
    ${ocultaMAgr ? '' : '<th class="num">M.Agr.</th>'}
  </tr>`
}

/** Porcentajes: con `table-layout: fixed` y anchos en mm la descripción se quedaba sin sitio. */
function colgroup(ocultaMAgr: boolean): string {
  const nums = ocultaMAgr ? 7 : 8
  const anchoNum = ocultaMAgr ? 9 : 8
  const codigo = 8
  const descripcion = 100 - codigo - nums * anchoNum
  const cols = [codigo, descripcion, ...Array.from({ length: nums }, () => anchoNum)]
  return `<colgroup>${cols.map((w) => `<col style="width:${w}%"/>`).join('')}</colgroup>`
}

function metaJerarquia(g: AbcComprasGrupo, dimension: string, extendido: boolean): string {
  if (!extendido) return ''
  if (dimension === 'subfamilias') {
    return `<span class="dim">Familia</span> ${esc(g.metaFamiliaCodigo || '-')} ${esc(g.metaFamiliaNombre || '')}
      <span class="dim">Macro</span> ${esc(g.metaMacroCodigo || '-')} ${esc(g.metaMacroNombre || '')}`
  }
  if (dimension === 'familias' || dimension === 'articulos') {
    return `<span class="dim">Macro</span> ${esc(g.metaMacroCodigo || '-')} ${esc(g.metaMacroNombre || '')}`
  }
  return ''
}

function tablaGrupo(
  g: AbcComprasGrupo,
  dimension: string,
  mostrarTotal: boolean,
  extendido: boolean,
  salto: boolean,
  etiquetaUnidades: string,
): string {
  const ocultaMAgr = dimension === 'articulos'
  const filas = g.articulos.map((a) => filaArticulo(a, ocultaMAgr)).join('')
  const total = mostrarTotal
    ? filaTotales(
        etiquetaTotalGrupoAbcCompras(dimension),
        g.totales,
        g.totales.pjeSobreTotal ?? 0,
        ocultaMAgr,
      )
    : ''
  const meta = metaJerarquia(g, dimension, extendido)
  const clase = salto ? 'abc-grupo salto-pagina' : 'abc-grupo'
  return `<section class="${clase}">
    <h2 class="abc-grupo-tit">
      <span class="dim">${esc(etiquetaBloqueGrupoAbcCompras(dimension))}</span>
      ${esc(g.codigo || '-')} ${esc(g.nombre || '')}
      ${meta}
    </h2>
    <table class="listado-tabla">${colgroup(ocultaMAgr)}
      <thead>${cabeceraTabla(ocultaMAgr, etiquetaUnidades)}</thead>
      <tbody>${filas}${total}</tbody>
    </table>
  </section>`
}

function tablaPlana(
  grupos: AbcComprasGrupo[],
  dimension: string,
  totales: AbcComprasTotales,
  extendido: boolean,
  etiquetaUnidades: string,
): string {
  const filas = grupos
    .map((g) => {
      const meta =
        extendido && dimension === 'articulos'
          ? `<div>Fam. ${esc(g.metaFamiliaCodigo || '-')} ${esc(g.metaFamiliaNombre || '')}</div>
             <div>Macro ${esc(g.metaMacroCodigo || '-')} ${esc(g.metaMacroNombre || '')}</div>`
          : ''
      return `<tr>
        <td>${esc(g.codigo)}</td>
        <td>${esc(g.nombre)}${meta}</td>
        <td class="num">${esc(fmtQty(g.totales.unidades))}</td>
        <td class="num">${esc(fmt(g.totales.dto))}</td>
        <td class="num">${esc(fmt(g.totales.importe))}</td>
        <td class="num">${esc(fmt(g.totales.coste))}</td>
        <td class="num">${esc(fmt(g.totales.margen))}</td>
        <td class="num">${esc(fmt(g.totales.pjeMargen))}</td>
        <td class="num">${esc(fmt(abcComprasPjeSobreTotalGrupo(g)))}</td>
      </tr>`
    })
    .join('')
  const total = filaTotales('TOTAL GENERAL', totales, 100, true)
  return `<table class="listado-tabla">${colgroup(true)}
    <thead><tr>
      <th>Código</th><th>${esc(etiquetaDimensionAbcCompras(dimension))}</th>
      <th class="num">${esc(etiquetaUnidades)}</th><th class="num">Dto</th><th class="num">Importe</th>
      <th class="num">Coste</th><th class="num">Margen</th><th class="num">% Marg</th><th class="num">% Sob.Tot</th>
    </tr></thead>
    <tbody>${filas}${total}</tbody>
  </table>`
}

function etiquetaCorta(texto: string): string {
  const t = texto.trim()
  return t.length > 10 ? `${t.slice(0, 10)}…` : t
}

function techoEscala(max: number): number {
  if (max <= 0) return 1
  const pot = 10 ** Math.floor(Math.log10(max))
  const norm = max / pot
  const mult = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10
  return mult * pot
}

/** Barras de los 12 grupos con más importe. Compras no tiene franjas horarias. */
function graficoGruposHtml(grupos: AbcComprasGrupo[], dimension: string): string {
  const top = [...grupos]
    .sort((a, b) => (b.totales.importe ?? 0) - (a.totales.importe ?? 0))
    .slice(0, 12)
  const valores = top.map((g) => g.totales.importe ?? 0)
  const maxVal = Math.max(0, ...valores)
  if (top.length < 2 || maxVal <= 0) return ''
  const techo = techoEscala(maxVal)
  const ancho = 720
  const alto = 200
  const izquierda = 52
  const derecha = 8
  const arriba = 8
  const abajo = 36
  const y0 = alto - abajo
  const altoUtil = y0 - arriba
  const paso = (ancho - izquierda - derecha) / top.length
  const anchoBarra = Math.min(36, paso * 0.7)
  const paleta = ['#0f766e', '#0369a1', '#b45309', '#7c3aed', '#be123c', '#15803d']
  const marcas = [0, 0.5, 1]
    .map((r) => {
      const y = y0 - r * altoUtil
      const et = (techo * r).toLocaleString('es-ES', { maximumFractionDigits: 0 })
      return `<line x1="${izquierda}" y1="${y}" x2="${ancho - derecha}" y2="${y}" stroke="#cbd5e1"/>
        <text x="${izquierda - 4}" y="${y + 3}" text-anchor="end" font-size="9" fill="#475569">${et}</text>`
    })
    .join('')
  const barras = top
    .map((g, i) => {
      const h = ((valores[i] ?? 0) / techo) * altoUtil
      const x = izquierda + paso * i + (paso - anchoBarra) / 2
      const etiqueta = etiquetaCorta(g.codigo || g.nombre || '')
      return `<rect x="${x}" y="${y0 - h}" width="${anchoBarra}" height="${h}" fill="${paleta[i % paleta.length]}"/>
        <text x="${x + anchoBarra / 2}" y="${y0 + 14}" text-anchor="middle" font-size="8" fill="#334155">${esc(etiqueta)}</text>`
    })
    .join('')
  return `<figure class="abc-grafico">
    <figcaption>Importe por ${esc(etiquetaDimensionAbcCompras(dimension).toLowerCase())} (12 mayores)</figcaption>
    <svg viewBox="0 0 ${ancho} ${alto}" xmlns="http://www.w3.org/2000/svg">${marcas}${barras}</svg>
  </figure>`
}

export function construirHtmlInformeAbcCompras(
  data: AbcComprasResponse,
  opts: { tituloCabecera: string; periodo: string; etiquetaUnidades?: string },
): string {
  const dimension = data.dimension
  const plano = dimension === 'articulos'
  const extendido = data.formatoJerarquia === 'extendido'
  const mostrarTotal = data.mostrarTotalGrupo !== false
  const etiquetaUnidades = opts.etiquetaUnidades?.trim() || 'Unidades'
  const desglosado = data.imArticulos === 'desglosado'
  const cuerpo = plano
    ? tablaPlana(data.grupos, dimension, data.totales, extendido, etiquetaUnidades)
    : data.grupos
        .map((g, i) =>
          tablaGrupo(g, dimension, mostrarTotal, extendido, desglosado && i > 0, etiquetaUnidades),
        )
        .join('') +
      `<table class="listado-tabla">${colgroup(dimension === 'articulos')}
        <tbody>${filaTotales('TOTAL GENERAL', data.totales, 100, dimension === 'articulos')}</tbody>
      </table>`
  const grafico = graficoGruposHtml(data.grupos, dimension)
  const fechaImpresion = new Date().toLocaleString('es-ES')
  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opts.tituloCabecera)}</title>
<style>${ESTILOS_LISTADO_A4}${ESTILO_ABC}</style></head><body><main class="folio">
<header class="listado-cabecera">
  <div>
    <h1 class="listado-titulo">${esc(opts.tituloCabecera)}</h1>
    <p class="listado-subtitulo">${esc(opts.periodo)}</p>
  </div>
  <div class="listado-meta">
    <p><strong>Dimensión:</strong> ${esc(etiquetaDimensionAbcCompras(dimension))}</p>
    <p><strong>Impresión:</strong> ${esc(fechaImpresion)}</p>
  </div>
</header>
${desglosado ? grafico : ''}
${cuerpo}
${desglosado ? '' : grafico}
</main></body></html>`
}
