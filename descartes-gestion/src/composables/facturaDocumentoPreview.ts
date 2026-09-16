import type {
  DocumentoPreviewDatos,
  DocumentoPreviewLinea,
} from '@/config/documentos-plantillas/preview-datos'
import { cuentaBancariaEnmascarada } from '@/composables/ventaDocumentoPreview'
import type { FacturaDocumento } from '@/types/facturacion'

function redondear2(n: number): number {
  return Math.round((Number(n) || 0) * 100) / 100
}

/** Tipo de plantilla del diseñador según el cobro de la factura. */
export function tipoPlantillaDesdeFactura(doc: FacturaDocumento): {
  plantillaTipo: string
  formatoKey: string
  nombreKey: string
  indiceKey: string
  label: string
} {
  if (doc.tipoCobro === 'contado') {
    return {
      plantillaTipo: 'factura-contado',
      formatoKey: 'formatoFacturasContado',
      nombreKey: 'impFacturasContado',
      indiceKey: 'impresoraFacturasContado',
      label: 'Factura contado',
    }
  }
  const abono = doc.facturaTipo.toUpperCase() === 'A'
  return {
    plantillaTipo: abono ? 'factura-rectificativa' : 'factura-credito',
    formatoKey: 'formatoFacturas',
    nombreKey: 'impFacturas',
    indiceKey: 'impresoraFacturas',
    label: abono ? 'Factura rectificativa' : 'Factura',
  }
}

/**
 * Mapea una factura a los datos de plantilla A4. Las líneas se agrupan por
 * albarán, con la cabecera del albarán como en el documento legacy.
 */
export function facturaAPreviewDatos(
  doc: FacturaDocumento,
  extras: {
    empresaNombre?: string
    empresaNif?: string
    empresaDireccion?: string
    empresaCp?: string
    empresaPoblacion?: string
    empresaProvincia?: string
    empresaTelefono?: string
    empresaEmail?: string
    literalesPuesto?: string[]
    literalTicket?: number
    literalFacturaDiferida?: string
    literalFacturaContado?: string
    literalPresupuesto?: string
    literalVale?: string
    /** Empresas.SW_IVA: los precios de línea ya llevan IVA. */
    preciosIvaIncluido?: boolean
    /** Data URL del logo de la carpeta `logos`. Vacío = no se imprime. */
    emblemaUrl?: string
  } = {}
): DocumentoPreviewDatos {
  const nLit = Math.max(0, Math.min(9, Number(extras.literalTicket ?? 3) || 0))

  const lineas: DocumentoPreviewLinea[] = []
  let albaranActual: number | null = null
  for (const l of doc.lineas) {
    if (l.albaran > 0 && l.albaran !== albaranActual) {
      albaranActual = l.albaran
      lineas.push({
        articulo: '',
        descripcion: '',
        unidades: 0,
        precio: 0,
        precioSinIva: 0,
        dto: 0,
        pjeIva: 0,
        importe: 0,
        pvp: 0,
        albaranCabecera: `Albarán ${doc.empresa}-${l.albaran} de Fecha ${l.albaranFecha}`,
      })
    }
    const pje = Number(l.pjeIva) || 0
    const factor = 1 + pje / 100
    // Como en ventas: el precio de línea lleva IVA o no según Empresas.SW_IVA.
    const conIva = extras.preciosIvaIncluido || pje <= 0
    lineas.push({
      articulo: l.articulo,
      descripcion: l.descripcion,
      unidades: Number(l.unidades) || 0,
      precio: Number(l.precio) || 0,
      precioSinIva: conIva ? redondear2(l.precio / factor) : l.precio,
      dto: Number(l.dto) || 0,
      pjeIva: pje,
      importe: Number(l.importe) || 0,
      pvp: conIva ? l.precio : redondear2(l.precio * factor),
    })
  }

  return {
    empresa: {
      nombre: extras.empresaNombre || '',
      razonSocial: extras.empresaNombre || '',
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
      codigo: doc.cliente.codigo,
      nombre: doc.cliente.razonSocial,
      direccion: doc.cliente.direccion,
      cp: doc.cliente.codigoPostal,
      poblacion: doc.cliente.poblacion,
      provincia: doc.cliente.provincia,
      pais: doc.cliente.pais,
      cif: doc.cliente.nif,
      telefono: doc.cliente.telefono,
      cuentaBancaria: cuentaBancariaEnmascarada(doc.cliente.cuentaBancaria),
      iban: doc.cliente.iban,
      swift: doc.cliente.swift,
    },
    documento: {
      numero: String(doc.factura),
      serie: doc.facturaTipo,
      fecha: doc.fecha,
      suPedido: '',
      albaran: '',
      terminalSesion: '',
      atendidoPor: '',
      fechaEntrega: '',
      transportista: '',
      portes: '',
      observaciones: '',
      formaPago: doc.formaPago.descripcion || doc.formaPago.codigo,
      codigoBarras: `*${doc.empresa}${doc.factura}*`,
      pagina: '1/1',
    },
    lineas,
    totales: {
      base: redondear2(doc.totales.base),
      ivas: doc.totales.ivas.map((i) => ({
        pje: Number(i.pje) || 0,
        base: redondear2(i.base),
        cuota: redondear2(i.cuota),
      })),
      importe: redondear2(doc.totales.importe),
    },
    vencimientos: doc.vencimientos.map((v) => ({
      fecha: v.fecha,
      importe: redondear2(v.importe),
    })),
    verifactu: { qrPayload: '', url: '' },
    tienda: {
      literalFacturaDiferida: extras.literalFacturaDiferida || '',
      literalFacturaContado: extras.literalFacturaContado || '',
      literalPresupuesto: extras.literalPresupuesto || '',
      literalVale: extras.literalVale || '',
      literalTicket: nLit,
    },
    puesto: {
      literales: (extras.literalesPuesto ?? []).slice(0, nLit),
    },
  }
}
