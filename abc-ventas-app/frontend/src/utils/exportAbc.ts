import type { AbcVentasResponse, AbcVentasTotales } from '@/types/abc-ventas'
import {
  labelIva,
  labelOrden,
  labelValor,
  normalizeIdioma,
  t,
} from '@/i18n/abc-ventas'
import * as XLSX from 'xlsx'
import { jsPDF } from 'jspdf'
import autoTable from 'jspdf-autotable'

function fmtFecha(iso: string) {
  const [y, m, d] = iso.split('-')
  if (!y || !m || !d) return iso
  return `${d}/${m}/${y}`
}

function fileStamp() {
  return new Date().toISOString().slice(0, 19).replace(/[:T]/g, (c) => (c === 'T' ? '_' : ''))
}

function fmtNum(n: number | undefined, digits = 2) {
  return (n ?? 0).toLocaleString('es-ES', {
    minimumFractionDigits: digits,
    maximumFractionDigits: digits,
  })
}

function buildExcelRows(data: AbcVentasResponse) {
  const rows: Array<Record<string, string | number>> = []
  const lang = normalizeIdioma(data.idioma)
  const esClientes = data.dimension === 'clientes'
  const colCodigo = esClientes ? 'ClienteCodigo' : 'VendedorCodigo'
  const colNombre = esClientes ? 'ClienteNombre' : 'VendedorNombre'

  for (const g of data.grupos) {
    rows.push({
      [colCodigo]: g.codigo,
      [colNombre]: g.nombre,
      Codigo: '',
      Articulo: `— ${g.codigo} ${g.nombre} —`,
      Unidades: '',
      Dto: '',
      Importe: '',
      Coste: '',
      Margen: '',
      PctMargen: '',
      PctSobTot: '',
      MAgr: '',
    })

    for (const a of g.articulos) {
      rows.push({
        [colCodigo]: g.codigo,
        [colNombre]: g.nombre,
        Codigo: a.codigo,
        Articulo: a.descripcion,
        Unidades: a.unidades,
        Dto: a.dto,
        Importe: a.importe,
        Coste: a.coste,
        Margen: a.margen,
        PctMargen: a.pjeMargen,
        PctSobTot: a.pjeSobreTotal,
        MAgr: a.mAgr,
      })
    }

    rows.push({
      [colCodigo]: g.codigo,
      [colNombre]: g.nombre,
      Codigo: '',
      Articulo: t(lang, 'total'),
      Unidades: g.totales.unidades,
      Dto: g.totales.dto,
      Importe: g.totales.importe,
      Coste: g.totales.coste,
      Margen: g.totales.margen,
      PctMargen: g.totales.pjeMargen ?? 0,
      PctSobTot: g.totales.pjeSobreTotal ?? 100,
      MAgr: g.totales.mAgr ?? 0,
    })
  }

  rows.push({
    [colCodigo]: '',
    [colNombre]: '',
    Codigo: '',
    Articulo: t(lang, 'totalGeneral'),
    Unidades: data.totales.unidades,
    Dto: data.totales.dto,
    Importe: data.totales.importe,
    Coste: data.totales.coste,
    Margen: data.totales.margen,
    PctMargen: data.totales.pjeMargen ?? 0,
    PctSobTot: 100,
    MAgr: data.totales.mAgr ?? 0,
  })

  return rows
}

export function buildExcelArrayBuffer(data: AbcVentasResponse): ArrayBuffer {
  const lang = normalizeIdioma(data.idioma)
  const wb = XLSX.utils.book_new()
  const meta = [
    [t(lang, data.dimension === 'clientes' ? 'abcClientes' : 'abcVentas')],
    [t(lang, 'divisa'), data.divisa || 'EU'],
    [t(lang, 'fecha'), `${data.fechaDesde} - ${data.fechaHasta}`],
    [t(lang, 'iva'), labelIva(lang, data.iva)],
    [t(lang, 'orden'), labelOrden(lang, data.orden)],
    [t(lang, 'valor'), labelValor(lang, data.valor)],
    [],
  ]
  const metaSheet = XLSX.utils.aoa_to_sheet(meta)
  XLSX.utils.book_append_sheet(wb, metaSheet, 'Cabecera')

  const rows = buildExcelRows(data)
  const dataSheet = XLSX.utils.json_to_sheet(rows)
  XLSX.utils.book_append_sheet(wb, dataSheet, 'Listado')

  return XLSX.write(wb, { bookType: 'xlsx', type: 'array' }) as ArrayBuffer
}

function headCols(lang: ReturnType<typeof normalizeIdioma>) {
  return [
    t(lang, 'codigo'),
    t(lang, 'articulo'),
    t(lang, 'unidades'),
    t(lang, 'dto'),
    t(lang, 'importe'),
    t(lang, 'coste'),
    t(lang, 'margen'),
    t(lang, 'pjeMargen'),
    t(lang, 'pjeSobTot'),
    t(lang, 'mAgr'),
  ]
}

function numRow(
  codigo: string,
  articulo: string,
  tot: AbcVentasTotales,
  sobTot = tot.pjeSobreTotal ?? 100,
) {
  return [
    codigo,
    articulo,
    fmtNum(tot.unidades),
    fmtNum(tot.dto),
    fmtNum(tot.importe),
    fmtNum(tot.coste),
    fmtNum(tot.margen),
    fmtNum(tot.pjeMargen ?? 0),
    fmtNum(sobTot),
    fmtNum(tot.mAgr ?? 0),
  ]
}

export function buildPdfArrayBuffer(data: AbcVentasResponse): ArrayBuffer {
  const lang = normalizeIdioma(data.idioma)
  const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' })
  const title = t(lang, data.dimension === 'clientes' ? 'abcClientes' : 'abcVentas')
  const subtitle = [
    `${t(lang, 'fecha')} ${fmtFecha(data.fechaDesde)} 00:00 - ${fmtFecha(data.fechaHasta)} 23:59`,
    labelIva(lang, data.iva),
    `${t(lang, 'orden')}: ${labelOrden(lang, data.orden)}`,
    `${t(lang, 'valor')}: ${labelValor(lang, data.valor)}`,
    data.divisa || 'EU',
  ].join('  |  ')

  doc.setFontSize(14)
  doc.text(title, 10, 12)
  doc.setFontSize(8)
  doc.text(subtitle, 10, 17)

  let startY = 21
  const pageBottom = 190

  const columnStyles = {
    0: { cellWidth: 24, halign: 'left' as const },
    1: { cellWidth: 68, halign: 'left' as const },
    2: { cellWidth: 18, halign: 'right' as const },
    3: { cellWidth: 16, halign: 'right' as const },
    4: { cellWidth: 22, halign: 'right' as const },
    5: { cellWidth: 22, halign: 'right' as const },
    6: { cellWidth: 22, halign: 'right' as const },
    7: { cellWidth: 18, halign: 'right' as const },
    8: { cellWidth: 18, halign: 'right' as const },
    9: { cellWidth: 18, halign: 'right' as const },
  }

  for (const g of data.grupos) {
    if (startY > pageBottom) {
      doc.addPage()
      startY = 12
    }

    doc.setFontSize(10)
    doc.text(`${g.codigo || '-'} ${g.nombre || t(lang, 'sinNombre')}`, 10, startY)
    startY += 2

    const body = g.articulos.map((a) => [
      a.codigo,
      a.descripcion,
      fmtNum(a.unidades),
      fmtNum(a.dto),
      fmtNum(a.importe),
      fmtNum(a.coste),
      fmtNum(a.margen),
      fmtNum(a.pjeMargen),
      fmtNum(a.pjeSobreTotal),
      fmtNum(a.mAgr),
    ])

    body.push(numRow('', t(lang, 'total'), g.totales))

    autoTable(doc, {
      startY,
      margin: { left: 10, right: 10 },
      head: [headCols(lang)],
      body,
      styles: {
        fontSize: 7,
        cellPadding: 1.1,
        overflow: 'ellipsize',
        valign: 'middle',
        font: 'helvetica',
      },
      headStyles: {
        fillColor: [40, 40, 40],
        textColor: 255,
        halign: 'center',
        fontStyle: 'bold',
      },
      columnStyles,
      tableWidth: 'wrap',
      didParseCell: (hook) => {
        if (hook.section === 'body' && hook.row.index === body.length - 1) {
          hook.cell.styles.fontStyle = 'bold'
          hook.cell.styles.fillColor = [235, 235, 235]
        }
        if (hook.section === 'body' && hook.column.index >= 2) {
          hook.cell.styles.halign = 'right'
        }
      },
    })

    const finalY =
      (doc as jsPDF & { lastAutoTable?: { finalY: number } }).lastAutoTable?.finalY ?? startY
    startY = finalY + 7
  }

  if (startY > pageBottom) {
    doc.addPage()
    startY = 12
  }

  autoTable(doc, {
    startY,
    margin: { left: 10, right: 10 },
    head: [headCols(lang)],
    body: [numRow('', t(lang, 'totalGeneral'), data.totales, 100)],
    showHead: false,
    styles: {
      fontSize: 7.5,
      cellPadding: 1.1,
      fontStyle: 'bold',
      fillColor: [220, 220, 220],
      font: 'helvetica',
      overflow: 'ellipsize',
    },
    columnStyles,
    tableWidth: 'wrap',
    didParseCell: (hook) => {
      if (hook.column.index >= 2) {
        hook.cell.styles.halign = 'right'
      }
    },
  })

  return doc.output('arraybuffer')
}

export function defaultExcelName(dimension?: string) {
  const prefix = dimension === 'clientes' ? 'ABC_Clientes' : 'ABC_Ventas'
  return `${prefix}_${fileStamp()}.xlsx`
}

export function defaultPdfName(dimension?: string) {
  const prefix = dimension === 'clientes' ? 'ABC_Clientes' : 'ABC_Ventas'
  return `${prefix}_${fileStamp()}.pdf`
}

async function saveViaElectron(
  data: ArrayBuffer,
  defaultPath: string,
  filters: Array<{ name: string; extensions: string[] }>
) {
  const api = window.abcVentas
  if (!api?.saveExport) return false
  const result = await api.saveExport({
    defaultPath,
    data: Array.from(new Uint8Array(data)),
    filters,
  })
  return result.ok === true
}

function downloadBlob(data: ArrayBuffer, filename: string, mime: string) {
  const blob = new Blob([data], { type: mime })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

export async function exportarExcel(data: AbcVentasResponse) {
  const buf = buildExcelArrayBuffer(data)
  const name = defaultExcelName(data.dimension)
  const ok = await saveViaElectron(buf, name, [{ name: 'Excel', extensions: ['xlsx'] }])
  if (!ok) {
    downloadBlob(
      buf,
      name,
      'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    )
  }
}

export async function exportarPdf(data: AbcVentasResponse) {
  const buf = buildPdfArrayBuffer(data)
  const name = defaultPdfName(data.dimension)
  const ok = await saveViaElectron(buf, name, [{ name: 'PDF', extensions: ['pdf'] }])
  if (!ok) {
    downloadBlob(buf, name, 'application/pdf')
  }
}
