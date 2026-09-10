import { obtenerFacturaDocumento } from '@/api/facturacion'
import { imprimirTicketTermica } from '@/composables/impresionTicketTermica'
import {
  facturaAPreviewDatos,
  tipoPlantillaDesdeFactura,
} from '@/composables/facturaDocumentoPreview'
import {
  cargarPlantillasEmpresa,
  cargarPuesto,
  cargarTienda,
  extrasEmpresaDesdeTienda,
  imprimirA4Html,
  resolverNombreImpresoraDoc,
  resolverPlantilla,
  type PrepImpresionA4,
  type PuestoDoc,
} from '@/composables/impresionDocumentoA4Shared'
import { textoTicketDesdePlantilla } from '@/config/documentos-plantillas/ticket-texto'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'

export type FacturaClave = { empresa: string; facturaTipo: string; factura: number }

export type FacturaImpresionPreparada = {
  clave: FacturaClave
  titulo: string
  plantilla: DocumentoPlantilla
  datos: DocumentoPreviewDatos
}

export type PrepImpresionFacturas = {
  documentos: FacturaImpresionPreparada[]
  impresoraNombre: string
  impresoraId: number | null
}

type ContextoEmpresa = {
  extras: ReturnType<typeof extrasEmpresaDesdeTienda>
  plantillas: Awaited<ReturnType<typeof cargarPlantillasEmpresa>>
}

/**
 * Prepara la previsualización A4 de las facturas seleccionadas con la plantilla
 * activa del diseñador (o la indicada en Generales II del puesto).
 */
export async function prepararImpresionFacturas(
  facturas: FacturaClave[],
  opciones: { puestoCodigo: string }
): Promise<PrepImpresionFacturas> {
  const puestoCodigo = opciones.puestoCodigo.trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  if (facturas.length === 0) {
    throw new Error('Seleccione al menos una factura')
  }

  const puesto: PuestoDoc = await cargarPuesto(puestoCodigo)
  const contextos = new Map<string, ContextoEmpresa>()
  const documentos: FacturaImpresionPreparada[] = []
  let impresora: { nombre: string; id: number | null } | null = null

  for (const clave of facturas) {
    const doc = await obtenerFacturaDocumento(clave.empresa, clave.facturaTipo, clave.factura)
    const empresa = doc.empresa.trim()

    let contexto = contextos.get(empresa)
    if (!contexto) {
      const [tienda, plantillas] = await Promise.all([
        cargarTienda(empresa),
        cargarPlantillasEmpresa(empresa),
      ])
      contexto = { extras: extrasEmpresaDesdeTienda(tienda, puesto), plantillas }
      contextos.set(empresa, contexto)
    }

    const meta = tipoPlantillaDesdeFactura(doc)
    const formatoNombre = String(puesto[meta.formatoKey] ?? '').trim()
    documentos.push({
      clave,
      titulo: `${meta.label} ${doc.facturaTipo}-${doc.factura}`,
      plantilla: resolverPlantilla(contexto.plantillas, formatoNombre, meta.plantillaTipo),
      datos: facturaAPreviewDatos(doc, contexto.extras),
    })

    // La impresora es la del primer documento: el lote va a una sola bandeja.
    if (impresora === null) {
      impresora = await resolverNombreImpresoraDoc(puesto, meta.nombreKey, meta.indiceKey)
    }
  }

  return {
    documentos,
    impresoraNombre: impresora?.nombre ?? '',
    impresoraId: impresora?.id ?? null,
  }
}

/** Imprime las facturas en térmica con la plantilla de ticket del diseñador. */
export async function imprimirFacturasTicket(
  facturas: FacturaClave[],
  opciones: { puestoCodigo: string }
): Promise<string> {
  const puestoCodigo = opciones.puestoCodigo.trim()
  if (!puestoCodigo) {
    throw new Error('Configure el puesto de este equipo para imprimir.')
  }
  if (facturas.length === 0) {
    throw new Error('Seleccione al menos una factura')
  }

  const puesto: PuestoDoc = await cargarPuesto(puestoCodigo)
  const contextos = new Map<string, ContextoEmpresa>()
  let ultimoMensaje = ''

  for (const clave of facturas) {
    const doc = await obtenerFacturaDocumento(clave.empresa, clave.facturaTipo, clave.factura)
    const empresa = doc.empresa.trim()

    let contexto = contextos.get(empresa)
    if (!contexto) {
      const [tienda, plantillas] = await Promise.all([
        cargarTienda(empresa),
        cargarPlantillasEmpresa(empresa),
      ])
      contexto = { extras: extrasEmpresaDesdeTienda(tienda, puesto), plantillas }
      contextos.set(empresa, contexto)
    }

    const plantilla = resolverPlantilla(contexto.plantillas, '', 'ticket')
    const texto = textoTicketDesdePlantilla(plantilla, facturaAPreviewDatos(doc, contexto.extras))
    const res = await imprimirTicketTermica({
      puestoCodigo,
      puesto,
      texto,
      tipo: 'ticket',
      empresa,
    })
    ultimoMensaje = res.message
  }

  if (facturas.length === 1) return ultimoMensaje
  return `${facturas.length} tickets enviados a la térmica`
}

export async function imprimirFacturasPreparadas(
  prep: PrepImpresionFacturas,
  html: string
): Promise<string> {
  const a4: PrepImpresionA4 = {
    plantilla: prep.documentos[0].plantilla,
    datos: prep.documentos[0].datos,
    impresoraNombre: prep.impresoraNombre,
    impresoraId: prep.impresoraId,
    titulo: `Facturas (${prep.documentos.length})`,
  }
  return imprimirA4Html(a4, html)
}
