/**
 * Render HTML de etiqueta desde plantilla + datos (005 / T021).
 * Posiciones en mm (absolutas), listo para Electron `printLabel`.
 */
import type { DocumentoPlantilla, PlantillaBloque } from '@/config/documentos-plantillas'
import { pageSizeMm } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  formatImporte,
  getByPath,
  type DocumentoPreviewDatos,
} from '@/config/documentos-plantillas/preview-datos'
import { code39Svg } from '@/config/documentos-plantillas/barcode-code39'
import { eanSvg } from '@/config/documentos-plantillas/barcode-ean'
import type { EtiquetaColaLinea } from '@/types/etiquetas'

export type DatosArticuloEtiqueta = {
  codigo: string
  descripcion?: string | null
  ean?: string | null
  precio?: number | null
  lote?: string | null
}

function basePreview(): DocumentoPreviewDatos {
  return datosPreviewPorTipo('etiqueta')
}

/** Datos de preview a partir de ficha / resolver. */
export function datosEtiquetaDesdeArticulo(a: DatosArticuloEtiqueta): DocumentoPreviewDatos {
  const base = basePreview()
  const codigo = String(a.codigo ?? '').trim()
  const ean = a.ean != null && String(a.ean).trim() !== '' ? String(a.ean).trim() : ''
  const precio = Number(a.precio ?? 0)
  return {
    ...base,
    articulo: {
      codigo,
      descripcion: String(a.descripcion ?? '').trim(),
      ean,
      precio,
      lote: a.lote != null ? String(a.lote) : '',
    },
    documento: {
      ...base.documento,
      codigoBarras: ean || codigo,
    },
    totales: { ...base.totales, importe: precio },
  }
}

/** Datos de preview a partir de línea de cola. */
export function datosEtiquetaDesdeCola(l: EtiquetaColaLinea): DocumentoPreviewDatos {
  return datosEtiquetaDesdeArticulo({
    codigo: l.articulo,
    descripcion: l.descripcion,
    ean: l.ean,
    precio: l.precio,
    lote: l.lote,
  })
}

function str(datos: DocumentoPreviewDatos, path: string): string {
  const v = getByPath(datos, path)
  return v == null ? '' : String(v)
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function valorCampo(datos: DocumentoPreviewDatos, b: PlantillaBloque): string {
  const paths = b.bind ?? []
  const parts = paths.map((p) => {
    const raw = str(datos, p)
    if (b.props?.format === 'importe') {
      return formatImporte(Number(raw || 0))
    }
    return raw
  })
  return parts.filter(Boolean).join(' ')
}

function styleBloque(b: PlantillaBloque): string {
  const fontSize = Number(b.props?.fontSizeMm)
  const weight = b.props?.fontWeight === 'bold' ? 'font-weight:700;' : ''
  const align =
    b.props?.align === 'right' || b.props?.centrado
      ? b.props?.align === 'right'
        ? 'text-align:right;'
        : 'text-align:center;'
      : ''
  const fs = Number.isFinite(fontSize) && fontSize > 0 ? `font-size:${fontSize}mm;line-height:1.15;` : 'font-size:2.2mm;line-height:1.15;'
  return [
    `left:${b.x}mm`,
    `top:${b.y}mm`,
    `width:${b.w}mm`,
    `height:${b.h}mm`,
    fs,
    weight,
    align,
  ]
    .filter(Boolean)
    .join(';')
}

function optsBarras(b: PlantillaBloque): { height: number; showText: boolean; moduleWidth: number } {
  const showText = b.props?.showValue !== false
  const mwRaw = Number(b.props?.moduleWidth)
  const moduleWidth =
    Number.isFinite(mwRaw) && mwRaw >= 0.6 && mwRaw <= 3 ? Math.round(mwRaw * 10) / 10 : 1.2
  // Alto SVG ~4 px por mm de bloque (mejor nitidez al imprimir)
  const height = Math.max(20, Math.round((Number(b.h) || 10) * 4))
  return { height, showText, moduleWidth }
}

/** SVG EAN-13/8 o Code39 según el valor y props del bloque. */
export function svgCodigoBarrasBloque(code: string, b: PlantillaBloque): string {
  const opts = optsBarras(b)
  return eanSvg(code, opts) ?? code39Svg(code, opts)
}

function htmlBloque(datos: DocumentoPreviewDatos, b: PlantillaBloque): string {
  const box = `class="block" style="${styleBloque(b)}"`
  switch (b.type) {
    case 'texto':
      return `<div ${box}>${escapeHtml(b.label || '')}</div>`
    case 'campo': {
      const label = b.label?.trim() ? `<span class="lbl">${escapeHtml(b.label)}</span> ` : ''
      return `<div ${box}>${label}${escapeHtml(valorCampo(datos, b))}</div>`
    }
    case 'codigo-barras': {
      const path = (b.bind && b.bind[0]) || 'articulo.ean'
      const code = str(datos, path) || str(datos, 'documento.codigoBarras')
      const svg = svgCodigoBarrasBloque(code, b)
      return `<div ${box}><div class="barcode">${svg}</div></div>`
    }
    case 'separador':
      return `<div ${box} style="${styleBloque(b)};border-top:0.2mm solid #000;height:0;"></div>`
    case 'titulo-documento':
      return `<div ${box}><strong>${escapeHtml(b.label || 'ETIQUETA')}</strong></div>`
    default:
      // Bloques A4 no típicos en etiqueta: intentar como campo
      if (b.bind?.length) {
        return `<div ${box}>${escapeHtml(valorCampo(datos, b))}</div>`
      }
      if (b.label) {
        return `<div ${box}>${escapeHtml(b.label)}</div>`
      }
      return ''
  }
}

/**
 * Documento HTML completo listo para `printLabel` / preview iframe.
 */
export function htmlEtiquetaDesdePlantilla(
  plantilla: DocumentoPlantilla,
  datos?: DocumentoPreviewDatos | null
): string {
  const d = datos ?? datosPreviewPorTipo(plantilla.tipo === 'etiqueta' ? 'etiqueta' : plantilla.tipo)
  const { widthMm, heightMm } = pageSizeMm(plantilla)
  const blocksHtml = plantilla.blocks.map((b) => htmlBloque(d, b)).join('\n')

  return `<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8"/>
<title>${escapeHtml(plantilla.nombre || 'Etiqueta')}</title>
<style>
  @page { size: ${widthMm}mm ${heightMm}mm; margin: 0; }
  * { box-sizing: border-box; }
  html, body {
    margin: 0;
    padding: 0;
    width: ${widthMm}mm;
    height: ${heightMm}mm;
    background: #fff;
    color: #000;
    font-family: Arial, Helvetica, sans-serif;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .page {
    position: relative;
    width: ${widthMm}mm;
    height: ${heightMm}mm;
    overflow: hidden;
  }
  .block {
    position: absolute;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
  }
  .block .lbl { opacity: 0.65; font-weight: 400; }
  .barcode { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
  .barcode svg { display: block; width: 100%; height: 100%; max-height: 100%; }
</style>
</head>
<body>
  <div class="page">
${blocksHtml}
  </div>
</body>
</html>`
}

export type PrepRenderEtiqueta = {
  plantilla: DocumentoPlantilla
  datos: DocumentoPreviewDatos
  html: string
  pageWidthMm: number
  pageHeightMm: number
}

export function prepararRenderEtiqueta(
  plantilla: DocumentoPlantilla,
  datos: DocumentoPreviewDatos
): PrepRenderEtiqueta {
  const size = pageSizeMm(plantilla)
  return {
    plantilla,
    datos,
    html: htmlEtiquetaDesdePlantilla(plantilla, datos),
    pageWidthMm: size.widthMm,
    pageHeightMm: size.heightMm,
  }
}
