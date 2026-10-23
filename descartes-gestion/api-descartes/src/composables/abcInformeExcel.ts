import {
  ABC_VENTAS_AGRUPACION_ARTICULOS,
  abcVentasFormatoArticulosMuestraComision,
} from '@/config/abc-ventas-opciones'
import {
  etiquetaBloqueGrupoAbc,
  etiquetaTotalGrupoAbc,
} from '@/config/abc-ventas-dimensiones'
import {
  abcVentasPjeSobreTotalGrupo,
  abcVentasUsaInformePlanoHorasAbc,
  abcVentasUsaTablaPlanaLegacy,
} from '@/composables/abcVentasInformeHtml'
import { abcComprasPjeSobreTotalGrupo } from '@/composables/abcComprasInformeHtml'
import {
  etiquetaBloqueGrupoAbcCompras,
  etiquetaTotalGrupoAbcCompras,
} from '@/config/abc-compras-dimensiones'
import type {
  AbcVentasArticulo,
  AbcVentasBloque,
  AbcVentasGrupo,
  AbcVentasResponse,
  AbcVentasTotales,
} from '@/types/ventas'
import type {
  AbcComprasArticulo,
  AbcComprasGrupo,
  AbcComprasResponse,
  AbcComprasTotales,
} from '@/types/compras'
import { descargarCsv, escCsv, numCsv } from '@/utils/csvExcel'

function line(row: (string | number)[]): string {
  return row.map((c) => escCsv(c)).join(';')
}

function pushPlanoLegacyRow(lines: string[], g: AbcVentasGrupo) {
  const t = g.totales
  const pje = abcVentasPjeSobreTotalGrupo(g)
  lines.push(
    line([
      g.codigo,
      g.nombre,
      numCsv(t.unidades),
      numCsv(t.dto),
      numCsv(t.importe),
      numCsv(t.coste),
      numCsv(t.margen),
      numCsv(t.pjeMargen ?? 0),
      numCsv(pje),
    ]),
  )
}

function pushTotalesPlanoLegacy(lines: string[], t: AbcVentasTotales, etiqueta: string) {
  lines.push(
    line([
      '',
      etiqueta,
      numCsv(t.unidades),
      numCsv(t.dto),
      numCsv(t.importe),
      numCsv(t.coste),
      numCsv(t.margen),
      numCsv(t.pjeMargen ?? 0),
      numCsv(t.pjeSobreTotal ?? 100),
    ]),
  )
}

const CAB_PLANO_LEGACY = [
  'Codigo',
  'Articulo',
  'Unidades',
  'Descuent',
  'Importe',
  'Coste',
  'Margen',
  '% Margen',
  '% Sob.Tot',
]

function etiquetaBloqueGeoVentas(d: AbcVentasResponse, b: AbcVentasBloque): string {
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

function etiquetaTotalBloqueGeoVentas(d: AbcVentasResponse): string {
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

function cabVentasDetalle(d: AbcVentasResponse): string[] {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const extendido = d.formatoArticulos === 'extendido'
  const colSec = d.formatoArticulos === 'detalleComision' ? 'Vendedor' : 'Articulo'
  const cab = ['Codigo grupo', 'Nombre grupo', 'Codigo', colSec]
  if (extendido) cab.push('Familia', 'Proveedor')
  cab.push('Unidades', 'Dto.', 'Importe', 'Coste', 'Margen', '%Margen')
  if (muestraComision) cab.push('Comision')
  cab.push('%Sob.Tot', 'M.Agr.')
  return cab
}

function filaArticuloVentas(
  d: AbcVentasResponse,
  g: AbcVentasGrupo,
  a: AbcVentasArticulo,
): (string | number)[] {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const extendido = d.formatoArticulos === 'extendido'
  const row: (string | number)[] = [g.codigo, g.nombre, a.codigo, a.descripcion]
  if (extendido) {
    row.push(`${a.familia ?? ''} ${a.familiaNombre ?? ''}`.trim())
    row.push(`${a.proveedor ?? ''} ${a.proveedorNombre ?? ''}`.trim())
  }
  row.push(
    numCsv(a.unidades),
    numCsv(a.dto),
    numCsv(a.importe),
    numCsv(a.coste),
    numCsv(a.margen),
    numCsv(a.pjeMargen),
  )
  if (muestraComision) row.push(a.comision != null ? numCsv(a.comision) : '')
  row.push(numCsv(a.pjeSobreTotal), numCsv(a.mAgr))
  return row
}

function filaTotalesVentasDetalle(
  d: AbcVentasResponse,
  g: AbcVentasGrupo | null,
  t: AbcVentasTotales,
  etiqueta: string,
): (string | number)[] {
  const muestraComision = abcVentasFormatoArticulosMuestraComision(d.formatoArticulos)
  const extendido = d.formatoArticulos === 'extendido'
  const row: (string | number)[] = [
    g?.codigo ?? '',
    g?.nombre ?? etiqueta,
    '',
    etiqueta,
  ]
  if (extendido) row.push('', '')
  row.push(
    numCsv(t.unidades),
    numCsv(t.dto),
    numCsv(t.importe),
    numCsv(t.coste),
    numCsv(t.margen),
    numCsv(t.pjeMargen ?? 0),
  )
  if (muestraComision) row.push(t.comision != null ? numCsv(t.comision) : '')
  row.push(numCsv(t.pjeSobreTotal ?? 100), '')
  return row
}

function exportMatrizVentas(d: AbcVentasResponse, lines: string[]) {
  const m = d.matrizVentaHoraria ?? d.matrizSemanal
  if (!m?.columnas.length) return false
  const cab = ['', ...m.columnas.map((c) => c.nombre), 'Total']
  lines.push(line(cab))
  for (const f of m.filas) {
    const vals = m.columnas.map((c) =>
      m.metrica === 'unidades' ? numCsv(f.celdas[c.id] ?? 0, 2) : numCsv(f.celdas[c.id] ?? 0),
    )
    lines.push(line([(f.nombre || f.codigo || '').toUpperCase(), ...vals, numCsv(f.total)]))
  }
  const totCols = m.columnas.map((c) => numCsv(m.totalesColumna[c.id] ?? 0))
  lines.push(line(['Total', ...totCols, numCsv(m.totalGeneral)]))
  return true
}

function exportHorasPlanoVentas(d: AbcVentasResponse, lines: string[]) {
  lines.push(
    line([
      'Codigo',
      'Tickets',
      'Unidades',
      'Descuento',
      'Importe',
      'Coste',
      'Margen',
      '% Margen',
      '% Sob. Tot',
    ]),
  )
  for (const g of d.grupos) {
    const t = g.totales
    const pje = abcVentasPjeSobreTotalGrupo(g)
    lines.push(
      line([
        g.codigo,
        t.tickets ?? 0,
        numCsv(t.unidades),
        numCsv(t.dto),
        numCsv(t.importe),
        numCsv(t.coste),
        numCsv(t.margen),
        numCsv(t.pjeMargen ?? 0),
        numCsv(pje),
      ]),
    )
  }
  const tg = d.totales
  lines.push(
    line([
      'TOTAL GENERAL',
      tg.tickets ?? 0,
      numCsv(tg.unidades),
      numCsv(tg.dto),
      numCsv(tg.importe),
      numCsv(tg.coste),
      numCsv(tg.margen),
      numCsv(tg.pjeMargen ?? 0),
      numCsv(100),
    ]),
  )
}

function exportPlanoLegacyVentas(d: AbcVentasResponse, lines: string[]) {
  lines.push(line(CAB_PLANO_LEGACY))
  const appendBlock = (grupos: AbcVentasGrupo[], totales: AbcVentasTotales, etiquetaTotal?: string) => {
    for (const g of grupos) pushPlanoLegacyRow(lines, g)
    if (etiquetaTotal) pushTotalesPlanoLegacy(lines, totales, etiquetaTotal)
  }
  if (d.bloques?.length) {
    for (const b of d.bloques) {
      lines.push(line([etiquetaBloqueGeoVentas(d, b)]))
      appendBlock(b.grupos, b.totales, etiquetaTotalBloqueGeoVentas(d))
    }
    pushTotalesPlanoLegacy(lines, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL')
  } else {
    appendBlock(d.grupos, d.totales, 'TOTAL')
    pushTotalesPlanoLegacy(lines, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL')
  }
}

function exportDetalleVentas(d: AbcVentasResponse, lines: string[]) {
  const cab = cabVentasDetalle(d)
  lines.push(line(cab))
  const dimLabel = etiquetaBloqueGrupoAbc(d.dimension)

  const emitGrupo = (g: AbcVentasGrupo) => {
    for (const a of g.articulos) lines.push(line(filaArticuloVentas(d, g, a)))
    if (d.mostrarTotalGrupo !== false) {
      lines.push(
        line(filaTotalesVentasDetalle(d, g, g.totales, etiquetaTotalGrupoAbc(d.dimension))),
      )
    }
  }

  if (d.bloques?.length) {
    for (const b of d.bloques) {
      lines.push(line([etiquetaBloqueGeoVentas(d, b)]))
      for (const g of b.grupos) emitGrupo(g)
      lines.push(
        line(filaTotalesVentasDetalle(d, null, b.totales, etiquetaTotalBloqueGeoVentas(d))),
      )
    }
    lines.push(
      line(
        filaTotalesVentasDetalle(d, null, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL'),
      ),
    )
  } else {
    for (const g of d.grupos) {
      if (!g.articulos.length && g.codigo) {
        lines.push(line([dimLabel, g.codigo, g.nombre]))
      }
      emitGrupo(g)
    }
    lines.push(
      line(
        filaTotalesVentasDetalle(d, null, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL'),
      ),
    )
  }
}

/** CSV UTF-8 (Excel ES) del informe ABC ventas, alineado con la impresión. */
export function exportarAbcVentasExcel(d: AbcVentasResponse, nombreArchivo: string): void {
  const lines: string[] = []
  if (exportMatrizVentas(d, lines)) {
    descargarCsv(nombreArchivo, lines)
    return
  }
  if (abcVentasUsaInformePlanoHorasAbc(d)) {
    exportHorasPlanoVentas(d, lines)
  } else if (abcVentasUsaTablaPlanaLegacy(d)) {
    exportPlanoLegacyVentas(d, lines)
  } else {
    exportDetalleVentas(d, lines)
  }
  descargarCsv(nombreArchivo, lines)
}

function pushFilaComprasPlano(lines: string[], g: AbcComprasGrupo) {
  const t = g.totales
  const pje = abcComprasPjeSobreTotalGrupo(g)
  lines.push(
    line([
      g.codigo,
      g.nombre,
      numCsv(t.unidades),
      numCsv(t.dto),
      numCsv(t.importe),
      numCsv(t.coste),
      numCsv(t.margen),
      numCsv(t.pjeMargen ?? 0),
      numCsv(pje),
    ]),
  )
}

function pushTotalesCompras(
  lines: string[],
  t: AbcComprasTotales,
  etiqueta: string,
  ocultaMAgr: boolean,
) {
  const row: (string | number)[] = ['', etiqueta, numCsv(t.unidades), numCsv(t.dto), numCsv(t.importe)]
  row.push(numCsv(t.coste), numCsv(t.margen), numCsv(t.pjeMargen ?? 0), numCsv(t.pjeSobreTotal ?? 100))
  if (!ocultaMAgr) row.push('')
  lines.push(line(row))
}

const CAB_COMPRAS = [
  'Codigo',
  'Articulo',
  'Unidades',
  'Dto',
  'Importe',
  'Coste',
  'Margen',
  '% Marg',
  '% Sob.Tot',
]

function cabComprasDetalle(ocultaMAgr: boolean): string[] {
  const cab = ['Codigo grupo', 'Nombre grupo', ...CAB_COMPRAS]
  if (!ocultaMAgr) cab.push('M.Agr.')
  return cab
}

function filaArticuloCompras(
  g: AbcComprasGrupo,
  a: AbcComprasArticulo,
  ocultaMAgr: boolean,
): (string | number)[] {
  const row: (string | number)[] = [
    g.codigo,
    g.nombre,
    a.codigo,
    a.descripcion,
    numCsv(a.unidades),
    numCsv(a.dto),
    numCsv(a.importe),
    numCsv(a.coste),
    numCsv(a.margen),
    numCsv(a.pjeMargen),
    numCsv(a.pjeSobreTotal ?? 0),
  ]
  if (!ocultaMAgr) row.push(numCsv(a.mAgr))
  return row
}

/** CSV UTF-8 (Excel ES) del informe ABC compras. */
export function exportarAbcComprasExcel(d: AbcComprasResponse, nombreArchivo: string): void {
  const lines: string[] = []
  const plano = d.dimension === 'articulos'
  const ocultaMAgr = plano
  const extendido =
    (d.dimension === 'subfamilias' || d.dimension === 'familias') &&
    (d.formatoJerarquia ?? 'normal').trim().toLowerCase() === 'extendido'

  if (plano) {
    lines.push(line(CAB_COMPRAS))
    for (const g of d.grupos) pushFilaComprasPlano(lines, g)
    pushTotalesCompras(lines, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', true)
  } else {
    lines.push(line(cabComprasDetalle(ocultaMAgr)))
    const dim = etiquetaBloqueGrupoAbcCompras(d.dimension)
    for (const g of d.grupos) {
      if (extendido && (g.metaFamiliaCodigo || g.metaMacroCodigo)) {
        if (d.dimension === 'subfamilias') {
          lines.push(
            line([
              `${dim} ${g.codigo} ${g.nombre}`,
              `Familia ${g.metaFamiliaCodigo ?? ''} ${g.metaFamiliaNombre ?? ''}`.trim(),
            ]),
          )
        } else {
          lines.push(line([`${dim} ${g.codigo} ${g.nombre}`]))
        }
        lines.push(
          line([
            '',
            `MacroFamilia ${g.metaMacroCodigo ?? ''} ${g.metaMacroNombre ?? ''}`.trim(),
          ]),
        )
      } else {
        lines.push(line([`${dim} ${g.codigo} ${g.nombre}`]))
      }
      for (const a of g.articulos) lines.push(line(filaArticuloCompras(g, a, ocultaMAgr)))
      if (d.mostrarTotalGrupo !== false) {
        const t = g.totales
        const totRow: (string | number)[] = [
          g.codigo,
          etiquetaTotalGrupoAbcCompras(d.dimension),
          '',
          '',
          numCsv(t.unidades),
          numCsv(t.dto),
          numCsv(t.importe),
          numCsv(t.coste),
          numCsv(t.margen),
          numCsv(t.pjeMargen ?? 0),
          numCsv(t.pjeSobreTotal ?? 0),
        ]
        if (!ocultaMAgr) totRow.push('')
        lines.push(line(totRow))
      }
    }
    pushTotalesCompras(lines, { ...d.totales, pjeSobreTotal: 100 }, 'TOTAL GENERAL', ocultaMAgr)
  }
  descargarCsv(nombreArchivo, lines)
}
