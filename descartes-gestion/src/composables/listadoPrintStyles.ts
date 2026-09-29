export type ListadoAlineacion = 'left' | 'center' | 'right'

export type ListadoColumnaImpresion = {
  alineacion?: ListadoAlineacion
  ancho?: string
}

/**
 * Base común de los listados A4. Los informes especiales pueden añadir reglas,
 * pero mantienen papel, tipografía, tablas, saltos y colores consistentes.
 */
export const ESTILOS_LISTADO_A4 = `
@page { size: A4 portrait; margin: 12mm 11mm 13mm; }
* { box-sizing: border-box; }
html { background: #fff; }
body {
  margin: 0;
  padding: 0;
  color: #172033;
  background: #fff;
  font-family: Arial, Helvetica, sans-serif;
  font-size: 9.5px;
  line-height: 1.25;
  -webkit-print-color-adjust: exact;
  print-color-adjust: exact;
}
.folio { width: 100%; min-height: 0; background: #fff; }
.listado-cabecera {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10mm;
  padding-bottom: 2.5mm;
  margin-bottom: 2.5mm;
  border-bottom: 1.5px solid #7f1d1d;
}
.listado-titulo {
  margin: 0;
  color: #007f7f;
  font-size: 17px;
  font-style: italic;
  line-height: 1.1;
}
.listado-subtitulo { margin: 1.5mm 0 0; color: #475569; font-size: 10px; }
.listado-meta { min-width: 55mm; text-align: right; color: #475569; font-size: 8.5px; }
.listado-meta p { margin: 0 0 1mm; }
.listado-meta strong { color: #172033; }
.listado-tabla {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  margin-top: 2mm;
}
.listado-tabla thead { display: table-header-group; }
.listado-tabla tfoot { display: table-footer-group; }
.listado-tabla tr { break-inside: avoid; page-break-inside: avoid; }
.listado-tabla th {
  padding: 1.4mm 1.5mm;
  color: #fff;
  background: #007f7f;
  border-right: 0.3mm solid #fff;
  font-size: 8.5px;
  font-weight: 700;
  text-align: left;
  white-space: normal;
}
.listado-tabla th:last-child { border-right: 0; }
.listado-tabla td {
  padding: 1.15mm 1.5mm;
  border-bottom: 0.2mm solid #cbd5e1;
  vertical-align: top;
  overflow-wrap: anywhere;
}
.listado-tabla tbody tr:nth-child(even) td { background: #f8fafc; }
.align-right { text-align: right !important; font-variant-numeric: tabular-nums; white-space: nowrap; }
.align-center { text-align: center !important; }
.align-left { text-align: left !important; }
.listado-totales {
  margin: 3mm 0 0;
  padding-top: 2mm;
  border-top: 0.5mm double #334155;
  font-size: 9.5px;
  font-weight: 700;
  text-align: right;
}
.grupo { break-inside: avoid; page-break-inside: avoid; }
.salto-pagina { break-before: page; page-break-before: always; }
.evitar-salto { break-inside: avoid; page-break-inside: avoid; }
@media screen {
  body { background: #e2e8f0; }
  .folio {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 12mm 11mm 13mm;
  }
}
@media print {
  .folio { width: auto; min-height: 0; padding: 0; }
}
`
