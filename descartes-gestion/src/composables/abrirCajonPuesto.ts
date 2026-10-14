import { abrirCajonDispositivo } from '@/api/ventas'
import { getDescartesBridge } from '@/bridge/electron'
import {
  cargarPuesto,
  type PuestoDoc,
} from '@/composables/impresionDocumentoA4Shared'

function impresoraTicketsPuesto(puesto: PuestoDoc): string {
  const principal = String(puesto.impresoraTickets ?? '').trim()
  if (principal) return principal
  return String(puesto.impresoraTicketsF ?? '').trim()
}

/** Abre el cajón físico conectado a la impresora de tickets del puesto. */
export async function abrirCajonPuesto(
  puestoCodigo: string,
  puestoCargado?: PuestoDoc
): Promise<string> {
  const codigo = String(puestoCodigo || '').trim()
  if (!codigo) throw new Error('Configure el puesto de este equipo.')

  const puesto = puestoCargado ?? (await cargarPuesto(codigo))
  const impresora = impresoraTicketsPuesto(puesto)
  if (!impresora) {
    throw new Error(
      'Configure la impresora de tickets del puesto (Puestos → Datos Generales → Tickets).'
    )
  }

  const bridge = getDescartesBridge()
  if (bridge?.openCashDrawer) {
    const res = await bridge.openCashDrawer({ impresora })
    if (!res.ok) throw new Error(res.message || 'No se pudo abrir el cajón')
    return res.message || `Cajón abierto mediante «${impresora}»`
  }

  const res = await abrirCajonDispositivo(codigo)
  if (!res.agenteOnline) {
    throw new Error(res.message || 'Agente Electron no disponible para abrir el cajón.')
  }
  if (!res.ok) throw new Error(res.message || 'No se pudo abrir el cajón')
  return res.message || `Cajón abierto mediante «${impresora}»`
}
