import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  formatImporte,
  getByPath,
  type DocumentoPreviewDatos,
} from '@/config/documentos-plantillas/preview-datos'

function str(datos: DocumentoPreviewDatos, path: string): string {
  const v = getByPath(datos, path)
  return v == null ? '' : String(v)
}

/** Genera texto plano del ticket (para ESC/POS) a partir de la plantilla + datos de preview/venta. */
export function textoTicketDesdePlantilla(
  plantilla: DocumentoPlantilla,
  datos?: DocumentoPreviewDatos
): string {
  const d = datos ?? datosPreviewPorTipo(plantilla.tipo)
  const blocks = [...plantilla.blocks].sort((a, b) => a.y - b.y || a.x - b.x)
  const lines: string[] = []

  for (const b of blocks) {
    switch (b.type) {
      case 'empresa-cabecera':
        lines.push(str(d, 'empresa.nombre'))
        lines.push(str(d, 'empresa.direccion'))
        lines.push(`${str(d, 'empresa.cp')} ${str(d, 'empresa.poblacion')}`.trim())
        lines.push(`Tel. ${str(d, 'empresa.telefono')}  NIF ${str(d, 'empresa.nif')}`)
        break
      case 'titulo-documento':
        lines.push('')
        lines.push(b.label || 'TICKET')
        lines.push(str(d, 'documento.numero'))
        break
      case 'bloque-meta':
        lines.push(`${str(d, 'documento.fecha')}  ${str(d, 'documento.terminalSesion')}`)
        if (str(d, 'documento.atendidoPor')) {
          lines.push(`Atendido: ${str(d, 'documento.atendidoPor')}`)
        }
        break
      case 'tabla-lineas':
        lines.push('--------------------------------')
        for (const ln of d.lineas) {
          lines.push(ln.descripcion)
          const precio = ln.precio ?? ln.pvp ?? ln.importe
          lines.push(
            `  ${ln.unidades} x ${formatImporte(precio)}`.padEnd(28) + formatImporte(ln.importe)
          )
        }
        break
      case 'totales-ticket':
      case 'totales-iva':
        lines.push('--------------------------------')
        for (const iva of d.totales.ivas) {
          lines.push(`Base ${iva.pje}%`.padEnd(28) + formatImporte(iva.base))
          lines.push(`IVA ${iva.pje}%`.padEnd(28) + formatImporte(iva.cuota))
        }
        lines.push(`TOTAL`.padEnd(28) + formatImporte(d.totales.importe))
        break
      case 'literales-puesto': {
        const lits = Array.isArray(d.puesto?.literales) ? d.puesto.literales : []
        for (const lit of lits) {
          if (String(lit).trim()) lines.push(String(lit).trim())
        }
        break
      }
      case 'separador':
        lines.push(b.label || '--------------------------------')
        break
      case 'texto':
        if (b.label) lines.push(b.label)
        break
      case 'campo':
        lines.push(`${b.label || ''}: ${str(d, (b.bind && b.bind[0]) || '')}`.trim())
        break
      default:
        break
    }
  }

  return lines.join('\n')
}
