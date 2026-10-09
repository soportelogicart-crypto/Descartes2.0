import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import { TICKET_MARCA_BARRAS, TICKET_MARCA_GRANDE } from '@/config/documentos-plantillas/ticket-texto'

export type ValePapel = {
  codigo: number
  importe: number
  cliente?: string | null
}

export function textoComprobanteVale(vale: ValePapel): string {
  const importe = Number(vale.importe).toFixed(2).replace('.', ',')
  const cliente = String(vale.cliente ?? '').trim()
  const lineas = [TICKET_MARCA_GRANDE + 'VALE', `Nº ${vale.codigo}`]
  if (cliente) lineas.push(`Cliente ${cliente}`)
  lineas.push(TICKET_MARCA_GRANDE + `${importe} EUR`)
  lineas.push('')
  lineas.push(TICKET_MARCA_BARRAS + String(vale.codigo))
  return lineas.join('\n')
}

export async function imprimirComprobanteVale(puestoCodigo: string, vale: ValePapel): Promise<void> {
  await imprimirTicketTermica({
    puestoCodigo,
    texto: textoComprobanteVale(vale),
    tipo: 'vale',
  })
}

/** Imprime el vale nuevo de una devolución y, si queda resto, el vale de la diferencia. */
export async function imprimirValesDeCierre(
  puestoCodigo: string,
  venta: {
    valeEmitido?: ValePapel | null
    valeAplicado?: { valeResto?: ValePapel | null } | null
  }
): Promise<void> {
  const papeles: ValePapel[] = []
  if (venta.valeEmitido && Number(venta.valeEmitido.codigo) > 0) {
    papeles.push(venta.valeEmitido)
  }
  const resto = venta.valeAplicado?.valeResto
  if (resto && Number(resto.codigo) > 0) {
    papeles.push(resto)
  }
  for (const papel of papeles) {
    await imprimirComprobanteVale(puestoCodigo, papel)
  }
}
