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

export type ListadoOrientacion = 'vertical' | 'horizontal'

export const ESTILO_LISTADO_APAISADO =
  '@page { size: A4 landscape; } @media screen { .folio { width: 297mm; min-height: 210mm; } }'

/** A4 vertical menos los márgenes de `@page`, con un 5 % de holgura. */
const ANCHO_UTIL_VERTICAL_MM = 188 * 0.95
/** Con más columnas las cabeceras numéricas se parten aunque el contenido quepa. */
const MAX_COLUMNAS_VERTICAL = 10
const MM_POR_CARACTER = 1.4
const MM_RELLENO_CELDA = 3.2
/** El texto con espacios se parte en varias líneas; a partir de aquí no ensancha la columna. */
const MAX_CARACTERES_TEXTO = 28
const MAX_FILAS_MUESTRA = 400

function anchoColumnaMm(cabecera: string, valores: string[]): number {
  const palabraCabecera = Math.max(0, ...cabecera.split(/\s+/).map((p) => p.length))
  let contenido = 0
  for (const v of valores) {
    const t = v.trim()
    const largo = /\s/.test(t) ? Math.min(t.length, MAX_CARACTERES_TEXTO) : t.length
    if (largo > contenido) contenido = largo
  }
  return Math.max(palabraCabecera, contenido, 3) * MM_POR_CARACTER + MM_RELLENO_CELDA
}

/** Apaisado cuando la suma estimada de columnas no cabe en A4 vertical. */
export function orientacionPorContenido(
  thead: string[],
  filas: (string | number)[][],
): ListadoOrientacion {
  if (thead.length > MAX_COLUMNAS_VERTICAL) return 'horizontal'
  const muestra = filas.slice(0, MAX_FILAS_MUESTRA)
  const total = thead.reduce(
    (suma, cab, i) => suma + anchoColumnaMm(cab, muestra.map((f) => String(f[i] ?? ''))),
    0,
  )
  return total > ANCHO_UTIL_VERTICAL_MM ? 'horizontal' : 'vertical'
}

/** Mide la tabla más ancha de un documento HTML ya montado (informes ABC, etc.). */
export function orientacionDocumentoHtml(html: string): ListadoOrientacion {
  if (/size\s*:\s*A4\s+landscape/i.test(html)) return 'horizontal'
  if (typeof DOMParser === 'undefined') return 'vertical'
  const doc = new DOMParser().parseFromString(html, 'text/html')
  for (const tabla of Array.from(doc.querySelectorAll('table'))) {
    const filas = Array.from(tabla.querySelectorAll('tr'))
    const cabecera = tabla.querySelector('thead tr:last-child') ?? filas[0]
    if (!cabecera) continue
    const thead = Array.from(cabecera.children).map((c) => c.textContent ?? '')
    if (thead.length < 2) continue
    const cuerpo = filas
      .filter((tr) => tr !== cabecera && tr.children.length === thead.length)
      .slice(0, MAX_FILAS_MUESTRA)
      .map((tr) => Array.from(tr.children).map((c) => c.textContent ?? ''))
    if (orientacionPorContenido(thead, cuerpo) === 'horizontal') return 'horizontal'
  }
  return 'vertical'
}

export function aplicarApaisadoHtml(html: string): string {
  if (/size\s*:\s*A4\s+landscape/i.test(html)) return html
  const estilo = `<style>${ESTILO_LISTADO_APAISADO}</style>`
  return html.includes('</head>') ? html.replace('</head>', `${estilo}</head>`) : estilo + html
}
