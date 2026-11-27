import { cargarEmblemaEmpresa } from '@/composables/cargarEmblemaEmpresa'
import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import { TICKET_MARCA_BARRAS, TICKET_MARCA_GRANDE } from '@/config/documentos-plantillas/ticket-texto'

export type ValePapel = {
  codigo: number
  importe: number
  cliente?: string | null
  /** Fecha de caducidad (ISO o dd/mm/aaaa); si viene, se imprime. */
  caducidad?: string | null
  /** Reimpresión desde Liquidación de vales: se marca como duplicado. */
  duplicado?: boolean
}

function fechaTicket(valor: string): string {
  const m = valor.match(/^(\d{4})-(\d{2})-(\d{2})/)
  return m ? `${m[3]}/${m[2]}/${m[1]}` : valor
}

export function textoComprobanteVale(vale: ValePapel): string {
  const importe = Number(vale.importe).toFixed(2).replace('.', ',')
  const cliente = String(vale.cliente ?? '').trim()
  const caducidad = String(vale.caducidad ?? '').trim()
  const lineas = [TICKET_MARCA_GRANDE + 'VALE', `No. ${vale.codigo}`]
  if (vale.duplicado) lineas.push('DUPLICADO')
  if (cliente) lineas.push(`Cliente ${cliente}`)
  lineas.push(TICKET_MARCA_GRANDE + `${importe} EUR`)
  if (caducidad) lineas.push(`Caduca ${fechaTicket(caducidad)}`)
  lineas.push('')
  lineas.push(TICKET_MARCA_BARRAS + String(vale.codigo))
  return lineas.join('\n')
}

export async function imprimirComprobanteVale(
  puestoCodigo: string,
  vale: ValePapel,
  opts?: { emblemaDataUrl?: string }
): Promise<void> {
  await imprimirTicketTermica({
    puestoCodigo,
    texto: textoComprobanteVale(vale),
    tipo: 'vale',
    emblemaDataUrl: opts?.emblemaDataUrl || undefined,
  })
}

/** Reimprime vales existentes (Liquidación de vales). Devuelve cuántos se enviaron a la impresora. */
export async function reimprimirVales(
  puestoCodigo: string,
  vales: (ValePapel & { empresa: string })[]
): Promise<number> {
  const emblemas = new Map<string, string | undefined>()
  let impresos = 0
  for (const vale of vales) {
    const empresa = String(vale.empresa ?? '').trim()
    if (empresa && !emblemas.has(empresa)) {
      emblemas.set(empresa, (await cargarEmblemaEmpresa(empresa)) || undefined)
    }
    await imprimirComprobanteVale(
      puestoCodigo,
      { ...vale, duplicado: true },
      { emblemaDataUrl: emblemas.get(empresa) }
    )
    impresos++
  }
  return impresos
}

/** Imprime el vale nuevo de una devolución y, si queda resto, el vale de la diferencia. */
export async function imprimirValesDeCierre(
  puestoCodigo: string,
  venta: {
    valeEmitido?: ValePapel | null
    valeAplicado?: { valeResto?: ValePapel | null } | null
  },
  empresaCodigo?: string
): Promise<void> {
  const papeles: ValePapel[] = []
  if (venta.valeEmitido && Number(venta.valeEmitido.codigo) > 0) {
    papeles.push(venta.valeEmitido)
  }
  const resto = venta.valeAplicado?.valeResto
  if (resto && Number(resto.codigo) > 0) {
    papeles.push(resto)
  }
  const empresa = String(empresaCodigo ?? '').trim()
  const emblemaDataUrl = empresa ? (await cargarEmblemaEmpresa(empresa)) || undefined : undefined
  for (const papel of papeles) {
    await imprimirComprobanteVale(puestoCodigo, papel, { emblemaDataUrl })
  }
}
