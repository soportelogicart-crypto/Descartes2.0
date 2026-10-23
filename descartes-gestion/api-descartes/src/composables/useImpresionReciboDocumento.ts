import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'
import {
  cargarPlantillasEmpresa,
  cargarPuesto,
  cargarTienda,
  extrasEmpresaParaDocumento,
  imprimirA4Html,
  resolverNombreImpresoraDoc,
  resolverPlantilla,
  type PuestoDoc,
} from '@/composables/impresionDocumentoA4Shared'
import type {
  FacturaImpresionPreparada,
  PrepImpresionFacturas,
} from '@/composables/useImpresionFacturaDocumento'
import type { ReciboImpresionItem } from '@/types/facturacion'

function reciboAPreviewDatos(
  r: ReciboImpresionItem,
  extras: {
    empresaNombre?: string
    empresaNif?: string
    empresaDireccion?: string
    empresaCp?: string
    empresaPoblacion?: string
    empresaProvincia?: string
    empresaTelefono?: string
    empresaEmail?: string
    emblemaUrl?: string
  }
): DocumentoPreviewDatos {
  return {
    empresa: {
      nombre: extras.empresaNombre || r.empresa,
      razonSocial: extras.empresaNombre || r.empresa,
      nif: extras.empresaNif || '',
      direccion: extras.empresaDireccion || '',
      cp: extras.empresaCp || '',
      poblacion: extras.empresaPoblacion || '',
      provincia: extras.empresaProvincia || '',
      telefono: extras.empresaTelefono || '',
      fax: '',
      email: extras.empresaEmail || '',
      emblemaUrl: extras.emblemaUrl || '',
      banco: '',
      iban: '',
      swift: '',
    },
    cliente: {
      codigo: r.cliente,
      nombre: r.razonSocial,
      direccion: '',
      cp: '',
      poblacion: '',
      provincia: '',
      pais: '',
      cif: r.nif,
      telefono: '',
      cuentaBancaria: '',
      iban: '',
      swift: '',
    },
    documento: {
      numero: String(r.recibo),
      serie: r.facturaTipo,
      fecha: r.fechaFactura,
      suPedido: '',
      albaran: `${r.facturaTipo}/${r.factura}`,
      terminalSesion: '',
      atendidoPor: '',
      fechaEntrega: r.vencimiento,
      transportista: '',
      portes: '',
      observaciones: '',
      formaPago: r.formaPagoDescripcion || r.formaPago,
      codigoBarras: '',
      pagina: '1/1',
    },
    lineas: [],
    totales: { base: Number(r.importe) || 0, ivas: [], importe: Number(r.importe) || 0 },
    vencimientos: [],
    verifactu: { qrPayload: '', url: '' },
    tienda: {
      literalFacturaDiferida: '',
      literalFacturaContado: '',
      literalPresupuesto: '',
      literalVale: '',
      literalTicket: 0,
    },
    puesto: { literales: [] },
  }
}

/** Una página de plantilla por recibo, con el formato de recibos del puesto. */
export async function prepararImpresionRecibos(
  recibos: ReciboImpresionItem[],
  opciones: { puestoCodigo: string }
): Promise<PrepImpresionFacturas> {
  const puestoCodigo = opciones.puestoCodigo.trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  if (recibos.length === 0) {
    throw new Error('Seleccione al menos un recibo')
  }

  let puesto: PuestoDoc = {}
  try {
    puesto = await cargarPuesto(puestoCodigo)
  } catch {
    puesto = {}
  }

  const contextos = new Map<
    string,
    {
      extras: Awaited<ReturnType<typeof extrasEmpresaParaDocumento>>
      plantillas: Awaited<ReturnType<typeof cargarPlantillasEmpresa>>
    }
  >()
  const documentos: FacturaImpresionPreparada[] = []
  let impresora: { nombre: string; id: number | null } | null = null
  const formatoNombre = String(puesto.formatoRecibos ?? '').trim()

  for (const r of recibos) {
    const empresa = String(r.empresa || '').trim()
    let contexto = contextos.get(empresa)
    if (!contexto) {
      const [tienda, plantillas] = await Promise.all([
        cargarTienda(empresa).catch(() => ({}) as Record<string, unknown>),
        cargarPlantillasEmpresa(empresa).catch(() => []),
      ])
      contexto = {
        extras: await extrasEmpresaParaDocumento(tienda, puesto, empresa),
        plantillas,
      }
      contextos.set(empresa, contexto)
    }
    documentos.push({
      clave: { empresa, facturaTipo: r.facturaTipo, factura: r.factura },
      folioId: `${empresa}|${r.facturaTipo}|${r.factura}|${r.recibo}`,
      titulo: `Recibo ${r.recibo} · ${r.facturaTipo}/${r.factura}`,
      plantilla: resolverPlantilla(contexto.plantillas, formatoNombre, 'recibo'),
      datos: reciboAPreviewDatos(r, contexto.extras),
    })
    if (impresora === null) {
      impresora = await resolverNombreImpresoraDoc(puesto, 'impRecibos', 'impresoraRecibos')
    }
  }

  return {
    documentos,
    impresoraNombre: impresora?.nombre ?? '',
    impresoraId: impresora?.id ?? null,
  }
}

export async function imprimirA4Recibos(
  prep: PrepImpresionFacturas,
  html: string
): Promise<string> {
  return imprimirA4Html(
    { impresoraNombre: prep.impresoraNombre, impresoraId: prep.impresoraId },
    html
  )
}
