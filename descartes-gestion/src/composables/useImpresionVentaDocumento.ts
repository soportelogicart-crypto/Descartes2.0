import { api } from '@/api/client'
import { marcarVentaImpresa } from '@/api/ventas'
import {
  TICKET_MARCA_BARRAS,
  TICKET_MARCA_DOBLE,
  TICKET_MARCA_EMBLEMA,
  TICKET_MARCA_GRANDE,
  textoTicketDesdePlantilla,
} from '@/config/documentos-plantillas/ticket-texto'
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
import {
  comprobanteDesdeXmlRedsys,
  textoComprobanteEstablecimiento,
  type ComprobanteTarjetaDatos,
} from '@/composables/comprobanteTarjeta'
import { getDescartesBridge } from '@/bridge/electron'
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
  let comprobanteTarjeta: ComprobanteTarjetaDatos | null = null
  if (receipt) {
    comprobanteTarjeta = comprobanteDesdeXmlRedsys(receipt, {
      nombreComercio: extras.empresaNombre,
      ciudad: extras.empresaPoblacion,
      importeEsperado: datos.totales.importe,
    })
    datos.tarjeta = comprobanteTarjeta ?? undefined
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
    let errorCopiaEstablecimiento = ''
    if (comprobanteTarjeta?.aprobada) {
      try {
        await imprimirTicketTermica({
          puestoCodigo,
          puesto,
          texto: textoComprobanteEstablecimiento(comprobanteTarjeta),
          tipo: 'comprobante-tarjeta-establecimiento',
          empresa: String(venta.empresa || ''),
          sesion: Number(venta.sesion) || undefined,
        })
      } catch (error) {
        const motivo = error instanceof Error ? error.message : String(error)
        errorCopiaEstablecimiento =
          `El ticket del cliente se imprimió, pero no la copia del establecimiento: ${motivo}`
      }
    }
    await marcarVentaImpresa(venta.empresa, venta.tipo, venta.albaran)
    if (errorCopiaEstablecimiento) {
      throw new Error(errorCopiaEstablecimiento)
    }
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

function escaparHtml(valor: string): string {
  return valor
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

/** Misma plantilla térmica dentro de un PDF A4 compatible con cualquier visor. */
function htmlTicketParaPdf(texto: string, emblemaUrl: string): string {
  const bloques: string[] = []
  for (const raw of texto.split('\n')) {
    if (raw === TICKET_MARCA_EMBLEMA) {
      if (emblemaUrl) bloques.push(`<img src="${escaparHtml(emblemaUrl)}" alt="" />`)
      continue
    }
    if (raw.startsWith(TICKET_MARCA_BARRAS)) {
      bloques.push(
        `<div class="barras">${escaparHtml(raw.slice(TICKET_MARCA_BARRAS.length))}</div>`
      )
      continue
    }
    let clase = ''
    let linea = raw
    if (raw.startsWith(TICKET_MARCA_DOBLE)) {
      clase = 'doble'
      linea = raw.slice(TICKET_MARCA_DOBLE.length)
    } else if (raw.startsWith(TICKET_MARCA_GRANDE)) {
      clase = 'grande'
      linea = raw.slice(TICKET_MARCA_GRANDE.length)
    }
    bloques.push(`<div class="${clase}">${escaparHtml(linea) || '&nbsp;'}</div>`)
  }
  return `<!doctype html><html><head><meta charset="utf-8"/>
<style>
  @page { size: A4 portrait; margin: 12mm; }
  html, body { margin: 0; padding: 0; background: #fff; color: #000; }
  body {
    width: 74mm;
    margin: 0 auto;
    font-family: "Courier New", Consolas, monospace;
    font-size: 11px;
    line-height: 1.25;
    white-space: pre;
  }
  img { display: block; max-width: 58mm; max-height: 22mm; margin: 0 auto 3mm; }
  .grande { font-size: 14px; font-weight: 700; }
  .doble { font-size: 18px; font-weight: 700; }
  .barras { margin-top: 2mm; text-align: center; letter-spacing: 0.15em; font-weight: 700; }
</style></head><body>${bloques.join('')}</body></html>`
}

/** PDF de la plantilla ya pintada, para adjuntarlo al correo. */
export async function pdfBase64DesdeHtmlPlantilla(
  html: string,
  opciones: { pageWidthMm?: number; pageHeightMm?: number } = {}
): Promise<string> {
  const bridge = getDescartesBridge()
  if (!bridge?.htmlToPdf) {
    throw new Error(
      'Para adjuntar el documento con la plantilla hay que actualizar Descartes (versión de escritorio).'
    )
  }
  if (!html.trim()) {
    throw new Error('No hay plantilla configurada para este documento en el puesto')
  }
  const res = await bridge.htmlToPdf({ html, ...opciones })
  if (!res.ok || !res.pdfBase64) {
    throw new Error(res.message || 'No se pudo generar el PDF de la plantilla')
  }
  return res.pdfBase64
}

/** PDF del ticket con la plantilla del puesto (no imprime). */
export async function pdfBase64TicketVenta(
  venta: VentaDetalle,
  opciones: { puestoCodigo: string; receiptDatafono?: string | null }
): Promise<string> {
  const puestoCodigo = String(opciones.puestoCodigo || venta.puesto || '').trim()
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
  const plantilla = resolverPlantilla(plantillas, '', 'ticket')
  const texto = textoTicketDesdePlantilla(plantilla, datos)
  return pdfBase64DesdeHtmlPlantilla(htmlTicketParaPdf(texto, extras.emblemaUrl || ''))
}

/** Copia térmica del comprobante para el establecimiento, tras un cobro aprobado. */
export async function imprimirCopiaEstablecimientoDatafono(
  venta: VentaDetalle,
  opciones: { puestoCodigo: string; receiptDatafono?: string | null }
): Promise<void> {
  const receipt = String(opciones.receiptDatafono ?? '').trim()
  const puestoCodigo = String(opciones.puestoCodigo || venta.puesto || '').trim()
  if (!receipt || !puestoCodigo) return

  const [puesto, tienda] = await Promise.all([
    cargarPuesto(puestoCodigo),
    cargarTienda(String(venta.empresa || '').trim()),
  ])
  const extras = await extrasEmpresaParaDocumento(
    tienda,
    puesto,
    String(venta.empresa || '').trim()
  )
  const comprobante = comprobanteDesdeXmlRedsys(receipt, {
    nombreComercio: extras.empresaNombre,
    ciudad: extras.empresaPoblacion,
    importeEsperado: Number(venta.importe) || 0,
  })
  if (!comprobante?.aprobada) return

  await imprimirTicketTermica({
    puestoCodigo,
    puesto,
    texto: textoComprobanteEstablecimiento(comprobante),
    tipo: 'comprobante-tarjeta-establecimiento',
    empresa: String(venta.empresa || ''),
    sesion: Number(venta.sesion) || undefined,
  })
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
