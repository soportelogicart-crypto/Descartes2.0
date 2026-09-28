import type { InformeTicketsResumen, InformeTicketsResult } from '@/api/listados'

export type OpcionesPlantillaTickets = {
  logoUrl?: string
}

export const TIPOS_IVA = [0, 4, 7, 10, 21] as const
export const PERFIL = [
  ['efectivo', 'Efectivo'],
  ['cheques', 'Cheques'],
  ['tarjetas', 'Tarjetas'],
  ['creditos', 'Creditos'],
  ['vales', 'Vales'],
  ['otros', 'Otros'],
] as const

export function esc(valor: unknown): string {
  return String(valor ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

export function num(valor: number | null | undefined, decimales = 2): string {
  return Number(valor ?? 0).toLocaleString('es-ES', {
    minimumFractionDigits: decimales,
    maximumFractionDigits: decimales,
  })
}

export function fecha(valor: string | null | undefined, corta = false): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(valor ?? '')
  if (!match) return valor ?? ''
  return `${match[3]}/${match[2]}/${corta ? match[1].slice(-2) : match[1]}`
}

function fechaImpresion(): string {
  const d = new Date()
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${String(d.getFullYear()).slice(-2)} - ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`
}

export function resumenGeneral(data: InformeTicketsResult): InformeTicketsResumen {
  return data.totales as InformeTicketsResumen
}

export function cabecera(
  data: InformeTicketsResult,
  titulo: string,
  opciones: OpcionesPlantillaTickets,
): string {
  const logo = opciones.logoUrl
    ? `<img class="legacy-logo" src="${esc(opciones.logoUrl)}" alt="" />`
    : ''
  return `<header class="legacy-header">
    <div class="legacy-header-top">
      <h1>${esc(titulo)}</h1>
      <div class="legacy-rango">
        <span>Fecha</span><b>${esc(fecha(data.fechaDesde))} 00:00</b><b>${esc(fecha(data.fechaHasta))} 23:59</b>
      </div>
      ${logo}
    </div>
    <div class="legacy-impresion">Fecha de Impresión: ${esc(fechaImpresion())}</div>
  </header>`
}

export function pie(): string {
  return `<div class="legacy-footer"></div>`
}

function desglosePorTipo(r: InformeTicketsResumen, tipo: number) {
  return (r.desgloseIva ?? []).find((fila) => Number(fila.pjeIva) === tipo)
}

export function tablaIva(
  resumen: InformeTicketsResumen,
  clase = '',
): string {
  const filas = TIPOS_IVA.map((tipo) => {
    const fila = desglosePorTipo(resumen, tipo)
    return `<tr>
      <td class="n">${num(fila?.base)}</td>
      <td class="n">${num(tipo)}%</td>
      <td class="n">${num(fila?.iva)}</td>
      <td class="n">${num(fila?.recargo)}</td>
    </tr>`
  }).join('')
  return `<fieldset class="legacy-box iva-box ${clase}">
    <legend>Desglose IVA</legend>
    <table>
      <thead><tr><th>Base</th><th>% Iva</th><th>Iva</th><th>Recargo</th></tr></thead>
      <tbody>${filas}</tbody>
      <tfoot><tr><td class="n">${num(resumen.base)}</td><td></td><td class="n">${num(resumen.iva)}</td><td class="n">${num(resumen.recargo)}</td></tr></tfoot>
    </table>
  </fieldset>`
}

export function tablaIvaDoble(resumen: InformeTicketsResumen): string {
  const grupo = (tipos: readonly number[]) => `<table>
    <thead><tr><th>Base</th><th>% Iva</th><th>Iva</th><th>Recargo</th></tr></thead>
    <tbody>${tipos.map((tipo) => {
      const fila = desglosePorTipo(resumen, tipo)
      return `<tr><td class="n">${num(fila?.base)}</td><td class="n">${num(tipo)}%</td><td class="n">${num(fila?.iva)}</td><td class="n">${num(fila?.recargo)}</td></tr>`
    }).join('')}</tbody>
  </table>`
  return `<fieldset class="legacy-box iva-doble">
    <legend>Desglose IVA</legend>
    <div>${grupo([0, 4, 7])}${grupo([10, 21])}</div>
  </fieldset>`
}

export function tablaPagos(
  resumen: InformeTicketsResumen,
  perfilPorcentajes: InformeTicketsResumen = resumen,
): string {
  const filas = PERFIL.map(([clave, etiqueta]) => {
    const pago = resumen.perfilPago?.[clave]
    const perfil = perfilPorcentajes.perfilPago?.[clave]
    return `<tr>
      <td>${etiqueta}</td>
      <td class="n">${num(pago?.conteo, 1)}</td>
      <td class="n">${num(pago?.importe)}</td>
      <td class="n">${num(perfil?.pje)}%</td>
    </tr>`
  }).join('')
  return `<fieldset class="legacy-box pagos-box">
    <legend>Formas de Pago</legend>
    <table><tbody>${filas}</tbody>
      <tfoot><tr><td></td><td class="n">${num(resumen.tickets, 0)}</td><td class="n">${num(resumen.importe)}</td><td></td></tr></tfoot>
    </table>
  </fieldset>`
}

export function perfilDia(resumen: InformeTicketsResumen): string {
  return `<fieldset class="legacy-box perfil-box perfil-dia">
    <legend>Perfil Ticket</legend>
    <dl>
      <dt>Número Tickets</dt><dd>${num(resumen.tickets, 0)}</dd>
      <dt>Ticket</dt><dd>${num(resumen.ticketsVenta ?? resumen.tickets - resumen.abonos, 0)}</dd>
      <dt>Abonos</dt><dd>${num(resumen.abonos, 0)}</dd>
      <dt>Nº de Artículos</dt><dd>${num(resumen.numeroArticulos, 0)}</dd>
    </dl>
    <dl class="primer-ultimo">
      <dt>Primer Ticket</dt><dd>${esc(resumen.primerTicket)}</dd>
      <dt>Ultimo Ticket</dt><dd>${esc(resumen.ultimoTicket)}</dd>
    </dl>
  </fieldset>`
}

export function perfilCompleto(resumen: InformeTicketsResumen): string {
  return `<fieldset class="legacy-box perfil-box">
    <legend>Perfil Ticket</legend>
    <dl>
      <dt>Número Tickets</dt><dd>${num(resumen.tickets, 0)}</dd>
      <dt>Ticket</dt><dd>${num(resumen.ticketsVenta ?? resumen.tickets - resumen.abonos, 0)}</dd>
      <dt>Abonos</dt><dd>${num(resumen.abonos, 0)}</dd>
      <dt>Importe Medio Ticket</dt><dd>${num(resumen.importeMedio)}</dd>
      <dt>Importe Ticket Mínimo</dt><dd>${num(resumen.importeMinimo)}</dd>
      <dt>Importe Ticket Máximo</dt><dd>${num(resumen.importeMaximo)}</dd>
      <dt>Nº de Artículos</dt><dd>${num(resumen.numeroArticulos, 0)}</dd>
    </dl>
  </fieldset>`
}

export function bloqueCierre(
  resumen: InformeTicketsResumen,
  perfilPorcentajes: InformeTicketsResumen = resumen,
): string {
  return `<div class="legacy-cierre">
    ${tablaPagos(resumen, perfilPorcentajes)}
    ${perfilCompleto(resumen)}
    ${tablaIva(resumen)}
  </div>`
}

export function filaResumen(resumen: InformeTicketsResumen, etiqueta: string): string {
  return `<tr>
    <td class="etiqueta">${esc(etiqueta)}</td>
    <td class="n raya">${num(resumen.base)}</td>
    <td class="n raya">${num(resumen.iva)}</td>
    <td class="n raya">${num(resumen.recargo)}</td>
    <td class="n raya">${num(resumen.importe)}</td>
  </tr>`
}

export const ESTILOS_LEGACY = `
:root{--legacy-verde:#008b87;--legacy-granate:#8b0000}
*{box-sizing:border-box}
.legacy-report{width:100%;border-collapse:collapse;color:#111;font-family:Arial,Helvetica,sans-serif;font-size:8pt;line-height:1.16}
.legacy-report>thead{display:table-header-group}
.legacy-report>tfoot{display:table-footer-group}
.legacy-report>thead>tr>td{padding:10mm 14mm 0;vertical-align:top}
.legacy-report>tbody>tr>td{padding:0 14mm;vertical-align:top}
.legacy-report>tfoot>tr>td{padding:0 14mm 8mm;vertical-align:bottom}
.legacy-header{border-bottom:2px solid var(--legacy-granate);padding-bottom:2mm;margin-bottom:6mm}
.legacy-header-top{display:grid;grid-template-columns:1fr 70mm 25mm;align-items:start;gap:4mm}
.legacy-header h1{font-size:11pt;font-style:italic;color:var(--legacy-verde);margin:2mm 0 0;border-bottom:1px solid var(--legacy-granate);padding-bottom:1mm}
.legacy-rango{display:grid;grid-template-columns:20mm 24mm 24mm;gap:1mm;color:#fff;font-size:7pt;font-style:italic}
.legacy-rango span,.legacy-rango b{background:var(--legacy-verde);padding:1px 3px;font-weight:400;white-space:nowrap}
.legacy-logo{width:25mm;max-height:17mm;object-fit:contain;justify-self:end}
.legacy-impresion{margin-top:3mm;font-size:8pt}
.legacy-footer{border-top:2px solid var(--legacy-granate);height:3mm;margin-top:6mm}
.legacy-tienda{font-size:9pt;font-weight:700;font-style:italic;color:var(--legacy-verde);margin:0 0 2mm}
.legacy-resumen{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:8pt}
.legacy-resumen td{padding:1px 3mm}
.legacy-resumen td:first-child,.legacy-resumen th:first-child{width:43%}
.legacy-resumen td:first-child{font-weight:700}
.legacy-resumen td+td,.legacy-resumen th+th{width:14.25%}
.legacy-resumen .n{text-align:right}
.legacy-resumen .raya{border-top:1px solid #111}
.legacy-resumen th{background:var(--legacy-verde);color:#fff;padding:1px 4px}
.legacy-box{border:1px solid #222;padding:1mm;margin:0;min-width:0}
.legacy-box legend{color:var(--legacy-verde);font-size:8pt;font-weight:700;font-style:italic;padding:0 1mm}
.legacy-box table{width:100%;border-collapse:collapse;font-size:7.4pt}
.legacy-box th{background:var(--legacy-verde);color:#fff;padding:1px 2px}
.legacy-box td{padding:1px 2px;white-space:nowrap}
.legacy-box .n{text-align:right}
.legacy-box tfoot td{border-top:3px double #111}
.legacy-cierre{display:grid;grid-template-columns:36% 25% 38%;gap:1%;margin:3mm 2mm 4mm;break-inside:avoid}
.perfil-box dl{display:grid;grid-template-columns:1fr auto;gap:1px 4px;margin:0}
.perfil-box dt,.perfil-box dd{margin:0;white-space:nowrap}
.perfil-box dd{text-align:right}
.perfil-dia{display:grid;grid-template-columns:1fr auto;gap:2mm;font-size:7pt}
.perfil-dia legend{grid-column:1/-1}
.perfil-dia .primer-ultimo{border-left:1px solid #222;padding-left:2mm}
.perfil-dia dd{font-variant-numeric:tabular-nums}
.iva-doble>div{display:grid;grid-template-columns:1fr 1fr;gap:1mm}
.legacy-dia{break-inside:avoid;border-bottom:3px solid #111;padding-bottom:1mm;margin-bottom:3mm}
.legacy-total{break-inside:avoid;margin:3mm 0}
.legacy-total .etiqueta{text-align:right;font-weight:700}
.n{text-align:right;font-variant-numeric:tabular-nums}
@media print{
  @page{size:A4 portrait;margin:0}
  body{margin:0!important;padding:0!important}
  .legacy-box,.legacy-total{break-inside:avoid}
}
`

/**
 * La cabecera va en `thead` y el pie en `tfoot`: es la única forma de que
 * Chromium (Electron) los repita en todas las páginas del informe.
 */
function pagina(
  data: InformeTicketsResult,
  titulo: string,
  cuerpo: string,
  opciones: OpcionesPlantillaTickets,
): string {
  return `<table class="legacy-report">
    <thead><tr><td>${cabecera(data, titulo, opciones)}</td></tr></thead>
    <tbody><tr><td>${cuerpo}</td></tr></tbody>
    <tfoot><tr><td>${pie()}</td></tr></tfoot>
  </table>`
}

export function documento(
  data: InformeTicketsResult,
  titulo: string,
  cuerpo: string,
  opciones: OpcionesPlantillaTickets,
): string {
  return `<!doctype html><html><head><meta charset="utf-8"><title>${esc(titulo)}</title>
  <style>${ESTILOS_LEGACY}</style></head><body>
  ${pagina(data, titulo, cuerpo, opciones)}
  </body></html>`
}

export function fragmento(
  data: InformeTicketsResult,
  titulo: string,
  cuerpo: string,
  opciones: OpcionesPlantillaTickets,
): string {
  return pagina(data, titulo, cuerpo, opciones)
}
