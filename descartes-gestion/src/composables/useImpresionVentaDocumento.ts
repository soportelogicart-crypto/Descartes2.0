import { api } from '@/api/client'
import { marcarVentaImpresa } from '@/api/ventas'
import { textoTicketDesdePlantilla } from '@/config/documentos-plantillas/ticket-texto'
import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import {
  tipoPlantillaDesdeVenta,
  ventaAPreviewDatos,
} from '@/composables/ventaDocumentoPreview'
import {
  cargarPlantillasEmpresa,
  cargarPuesto,
  cargarTienda,
  extrasEmpresaParaDocumento,
  imprimirA4Html,
  literalesDesdePuesto,
  resolverNombreImpresoraDoc,
  resolverPlantilla,
  type PrepImpresionA4,
} from '@/composables/impresionDocumentoA4Shared'
import { comprobanteDesdeXmlRedsys } from '@/composables/comprobanteTarjeta'
import type { VentaDetalle } from '@/types/ventas'

export type { PrepImpresionA4 }

export type PrepImpresionResult =
  | { kind: 'ticket'; message: string }
  | { kind: 'a4'; prep: PrepImpresionA4 }

const META_ALBARAN_A4 = {
  esTicket: false,
  plantillaTipo: 'albaran',
  formatoKey: 'formatoAlbaranes',
  nombreKey: 'impAlbaranes',
  indiceKey: 'impresoraAlbaranes',
  label: 'Albarán',
} as const

/** Sin nombre el ticket imprime el código del cajero; no debe impedir imprimir. */
async function nombreTrabajador(codigo: string): Promise<string> {
  const c = codigo.trim()
  if (!c) return ''
  try {
    const { data } = await api.get(`/api/mantenimiento/trabajadores/${encodeURIComponent(c)}`)
    return String(data?.nombre ?? data?.descripcion ?? '').trim()
  } catch {
    return ''
  }
}

/** A4 al forzar folio: un ticket recuperado se imprime como albarán. */
function metaA4Forzado(venta: VentaDetalle) {
  const meta = tipoPlantillaDesdeVenta(venta)
  if (!meta.esTicket) return meta
  return { ...META_ALBARAN_A4 }
}

/** Construye datos + plantilla / o imprime ticket térmico (sin elegir impresora). */
export async function prepararOImprimirVenta(
  venta: VentaDetalle,
  opciones: {
    puestoCodigo: string
    formato?: 'auto' | 'ticket' | 'a4' | 'albaran'
    /** XML Redsys del último cobro con datáfono (misma venta). */
    receiptDatafono?: string | null
  }
): Promise<PrepImpresionResult> {
  const formato = opciones.formato ?? 'auto'
  const metaAuto = tipoPlantillaDesdeVenta(venta)
  const comoTicket = formato === 'ticket' || (formato === 'auto' && metaAuto.esTicket)
  const meta = formato === 'albaran' ? { ...META_ALBARAN_A4 } : comoTicket ? metaAuto : metaA4Forzado(venta)
  const puestoCodigo =
    String(opciones.puestoCodigo || venta.puesto || '').trim() || ''
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }

  const [puesto, tienda, plantillas, vendedorNombre] = await Promise.all([
    cargarPuesto(puestoCodigo),
    cargarTienda(String(venta.empresa || '').trim()),
    cargarPlantillasEmpresa(String(venta.empresa || '').trim()),
    nombreTrabajador(String(venta.vendedor ?? '')),
  ])

  const extras = await extrasEmpresaParaDocumento(
    tienda,
    puesto,
    String(venta.empresa || '').trim()
  )
  const datos = ventaAPreviewDatos(venta, {
    ...extras,
    literalesPuesto: literalesDesdePuesto(puesto),
    vendedorNombre,
  })
  const receipt = String(opciones.receiptDatafono ?? '').trim()
  if (receipt) {
    datos.tarjeta =
      comprobanteDesdeXmlRedsys(receipt, {
        nombreComercio: extras.empresaNombre,
        ciudad: extras.empresaPoblacion,
        importeEsperado: datos.totales.importe,
      }) ?? undefined
  }

  if (comoTicket) {
    const plantilla = resolverPlantilla(plantillas, '', 'ticket')
    const texto = textoTicketDesdePlantilla(plantilla, datos)
    const res = await imprimirTicketTermica({
      puestoCodigo,
      puesto,
      texto,
      emblemaDataUrl: extras.emblemaUrl || undefined,
      tipo: 'ticket',
      empresa: String(venta.empresa || ''),
      sesion: Number(venta.sesion) || undefined,
    })
    await marcarVentaImpresa(venta.empresa, venta.tipo, venta.albaran)
    return {
      kind: 'ticket',
      message: res.message,
    }
  }

  const formatoNombre =
    meta.formatoKey != null ? String(puesto[meta.formatoKey] ?? '').trim() : ''
  const plantilla = resolverPlantilla(plantillas, formatoNombre, meta.plantillaTipo)
  const imp = await resolverNombreImpresoraDoc(puesto, meta.nombreKey, meta.indiceKey)

  return {
    kind: 'a4',
    prep: {
      plantilla,
      datos,
      impresoraNombre: imp.nombre,
      impresoraId: imp.id,
      titulo: `${meta.label} · Alb. ${venta.albaran}`,
    },
  }
}

export async function imprimirA4Preparado(
  prep: PrepImpresionA4,
  html: string,
  venta: VentaDetalle
): Promise<string> {
  const msg = await imprimirA4Html(prep, html)
  await marcarVentaImpresa(venta.empresa, venta.tipo, venta.albaran)
  return msg
}

/** ¿El puesto tiene ticket automático activado? (por defecto sí). */
export async function puestoTicketAutomatico(puestoCodigo: string): Promise<boolean> {
  if (!puestoCodigo.trim()) return true
  try {
    const p = await cargarPuesto(puestoCodigo)
    if (p.ticketAutomatico === undefined || p.ticketAutomatico === null) return true
    return Boolean(p.ticketAutomatico)
  } catch {
    return true
  }
}
