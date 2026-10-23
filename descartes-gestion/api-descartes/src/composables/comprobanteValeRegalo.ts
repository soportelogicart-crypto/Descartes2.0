import { cargarEmblemaEmpresa } from '@/composables/cargarEmblemaEmpresa'
import { ANCHO_TICKET, centrarTicket } from '@/composables/comprobanteTarjeta'
import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import {
  TICKET_MARCA_BARRAS,
  TICKET_MARCA_DOBLE,
} from '@/config/documentos-plantillas/ticket-texto'
import type { ArqueoResponse } from '@/types/ventas'

function lineaTicket(izq: string, der: string, ancho = ANCHO_TICKET): string {
  const derecha = der.trim()
  const izquierda = izq.trim()
  if (!derecha) return izquierda.slice(0, ancho)
  const hueco = Math.max(0, ancho - derecha.length - 1)
  return `${izquierda.slice(0, hueco).padEnd(hueco)} ${derecha}`.slice(0, ancho)
}

function eurosTicket(n: number): string {
  return `${n.toFixed(2).replace('.', ',')} EUR`
}

export function cabeceraComprobanteMovimientoCaja(
  res: ArqueoResponse,
  cajeroLabel: string
): string[] {
  const ahora = new Date()
  const fecha = ahora.toLocaleString('es-ES', {
    day: '2-digit',
    month: '2-digit',
    year: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  })
  const cajero = cajeroLabel.trim() || '-'
  return [
    lineaTicket('Empresa', res.empresa),
    lineaTicket('Puesto', res.puesto),
    lineaTicket('Sesion', String(res.sesion)),
    lineaTicket('Fecha', fecha),
    lineaTicket('Cajero', cajero),
  ]
}

/** Ticket de emisión de vale regalo (entrada de caja subtipo R). */
export function textoComprobanteValeRegalo(
  res: ArqueoResponse,
  cajeroLabel: string
): string {
  const mov = res.ultimoMovimiento
  const forma = String(mov?.formaPago ?? '').trim()
  const linea = res.lineas.find((l) => l.formaPago === forma)
  const desc = String(linea?.descripcion ?? '').trim()
  const sep = ''.padEnd(ANCHO_TICKET, '-')
  const importe = Number(mov?.importe) || 0
  const codVale = mov?.valeCodigo != null ? Number(mov.valeCodigo) : 0

  const lines = [
    TICKET_MARCA_DOBLE + centrarTicket('VALE REGALO', 42),
    '',
    centrarTicket('Comprobante de emision', ANCHO_TICKET),
    '',
    ...cabeceraComprobanteMovimientoCaja(res, cajeroLabel),
    sep,
    lineaTicket('Documento', 'Vale regalo (Tipo R)'),
  ]
  if (codVale > 0) lines.push(lineaTicket('No. vale', String(codVale)))
  lines.push(lineaTicket('Importe', eurosTicket(importe)))
  if (desc || forma) lines.push(lineaTicket('Forma pago', desc ? `${forma} ${desc}` : forma))
  const concepto = String(mov?.concepto ?? '').trim()
  if (concepto) lines.push(lineaTicket('Concepto', concepto))
  lines.push(sep)
  lines.push(centrarTicket('Pendiente de liquidacion', ANCHO_TICKET))
  if (codVale > 0) {
    lines.push('')
    lines.push(TICKET_MARCA_BARRAS + String(codVale))
  }
  lines.push(sep)
  return lines.join('\n')
}

export async function imprimirComprobanteValeRegalo(opts: {
  puestoCodigo: string
  res: ArqueoResponse
  cajeroLabel: string
  abrirCajon?: boolean
}): Promise<{ stub?: boolean; message?: string; impresora?: string }> {
  const emblemaDataUrl = (await cargarEmblemaEmpresa(opts.res.empresa)) || undefined
  return imprimirTicketTermica({
    puestoCodigo: opts.puestoCodigo,
    texto: textoComprobanteValeRegalo(opts.res, opts.cajeroLabel),
    tipo: 'vale-regalo',
    empresa: opts.res.empresa,
    sesion: opts.res.sesion,
    abrirCajon: Boolean(opts.abrirCajon),
    emblemaDataUrl,
  })
}
