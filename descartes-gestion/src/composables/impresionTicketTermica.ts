import { imprimirTermicaDispositivo } from '@/api/ventas'
import { getDescartesBridge } from '@/bridge/electron'
import { cargarPuesto, type PuestoDoc } from '@/composables/impresionDocumentoA4Shared'

function impresoraTicketsPuesto(puesto: PuestoDoc): string {
  const principal = String(puesto.impresoraTickets ?? '').trim()
  if (principal) return principal
  return String(puesto.impresoraTicketsF ?? '').trim()
}

/**
 * Ticket ESC/POS RAW (TICKETU/TICKETW son Epson TM, datatype RAW).
 * En Electron va por IPC; si no, la API reenvía al agente local.
 */
export async function imprimirTicketTermica(opciones: {
  puestoCodigo: string
  texto: string
  tipo?: string
  empresa?: string
  sesion?: number
  impresora?: string
  puesto?: PuestoDoc
  abrirCajon?: boolean
}): Promise<{ message: string; stub: boolean; impresora: string }> {
  const texto = opciones.texto.trim()
  if (!texto) {
    throw new Error('El ticket no tiene contenido para imprimir.')
  }

  const puestoCodigo = opciones.puestoCodigo.trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }

  const puesto = opciones.puesto ?? (await cargarPuesto(puestoCodigo))
  const impresora = String(opciones.impresora ?? '').trim() || impresoraTicketsPuesto(puesto)
  if (!impresora) {
    throw new Error(
      'Configure la impresora de tickets del puesto (Puestos → Datos Generales → Tickets).'
    )
  }

  const payload = {
    texto,
    tipo: opciones.tipo ?? 'ticket',
    impresora,
    abrirCajon: Boolean(opciones.abrirCajon),
    cortar: true,
  }

  const bridge = getDescartesBridge()
  if (bridge?.printTicket) {
    const res = await bridge.printTicket(payload)
    if (!res.ok) {
      throw new Error(res.message || 'No se pudo imprimir el ticket')
    }
    return {
      message: res.message || `Ticket enviado a «${impresora}»`,
      stub: Boolean(res.stub),
      impresora,
    }
  }

  const res = await imprimirTermicaDispositivo(puestoCodigo, {
    ...payload,
    empresa: opciones.empresa,
    sesion: opciones.sesion,
  })
  if (!res.agenteOnline) {
    throw new Error(res.message || 'Agente Electron no disponible para imprimir el ticket.')
  }
  if (!res.ok) {
    throw new Error(res.message || 'No se pudo imprimir el ticket')
  }
  return {
    message: res.stub ? `Stub: ${res.message}` : res.message || `Ticket enviado a «${impresora}»`,
    stub: Boolean(res.stub),
    impresora: String(res.impresora ?? impresora),
  }
}
