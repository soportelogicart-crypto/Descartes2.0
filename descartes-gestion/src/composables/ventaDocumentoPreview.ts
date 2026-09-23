import type {
  DocumentoPreviewDatos,
  DocumentoPreviewLinea,
} from '@/config/documentos-plantillas/preview-datos'
import type { VentaDetalle } from '@/types/ventas'

function fmtFecha(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = String(iso).slice(0, 10)
  const [y, m, day] = d.split('-')
  if (!y || !m || !day) return String(iso)
  return `${day}/${m}/${y}`
}

function redondear2(n: number): number {
  return Math.round((Number(n) || 0) * 100) / 100
}

/** En factura solo se muestran los 16 primeros dígitos de la cuenta del cliente. */
export function cuentaBancariaEnmascarada(valor: string | null | undefined): string {
  const cuenta = String(valor ?? '').replace(/\s+/g, '')
  if (cuenta.length <= 4) return cuenta
  return `${cuenta.slice(0, -4)}XXXX`
}

/** Mapea una venta real a los datos de plantilla (preview / ticket / A4). */
export function ventaAPreviewDatos(
  venta: VentaDetalle,
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
  const ivas = (venta.importesIva ?? []).map((x) => ({
    pje: Number(x.pjeIva) || 0,
    base: redondear2(Number(x.base) || 0),
    cuota: redondear2(Number(x.iva) || 0),
  }))
  const base = ivas.reduce((s, i) => s + i.base, 0) || redondear2(Number(venta.bruto) || 0)

  const numDoc =
    Number(venta.factura) > 0
      ? String(venta.factura)
      : String(venta.albaran || '')

  const nLit = Math.max(0, Math.min(9, Number(extras.literalTicket ?? 3) || 0))
  const lits = (extras.literalesPuesto ?? []).slice(0, nLit)
  const lineas: DocumentoPreviewLinea[] = []
  for (const l of venta.lineas ?? []) {
    const art = String(l.articulo ?? '').trim()
    if (art === '') continue
    if (art.toUpperCase() === 'NO') {
      const nota = String(l.descripcion ?? '').trim()
      if (nota) {
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
          nota,
        })
      }
      continue
    }
    const precio = Number(l.precio) || 0
    const pje = Number(l.pjeIva) || 0
    const factor = 1 + pje / 100
    const conIva = extras.preciosIvaIncluido || pje <= 0
    lineas.push({
      articulo: art,
      descripcion: String(l.descripcion ?? ''),
      unidades: Number(l.cantidad) || 0,
      precio,
      precioSinIva: conIva ? redondear2(precio / factor) : precio,
      dto: Number(l.pjeDto) || 0,
      pjeIva: pje,
      importe: Number(l.importe) || 0,
      pvp: conIva ? precio : redondear2(precio * factor),
    })
  }

  if (Number(venta.factura) > 0 && Number(venta.albaran) > 0 && lineas.length > 0) {
    lineas.unshift({
      articulo: '',
      descripcion: '',
      unidades: 0,
      precio: 0,
      precioSinIva: 0,
      dto: 0,
      pjeIva: 0,
      importe: 0,
      pvp: 0,
      albaranCabecera: `Albarán ${venta.empresa}-${venta.albaran} de Fecha ${fmtFecha(venta.fecha)}`,
    })
  }

  return {
    empresa: {
      nombre: extras.empresaNombre || String(venta.empresa || ''),
      razonSocial: extras.empresaNombre || String(venta.empresa || ''),
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
      codigo: String(venta.cliente ?? ''),
      nombre: String(venta.razonSocial ?? ''),
      direccion: String(venta.direccionEnvio ?? ''),
      cp: String(venta.codigoPostalEnvio ?? ''),
      poblacion: String(venta.poblacionEnvio ?? ''),
      provincia: String(venta.provinciaEnvio ?? ''),
      pais: String(venta.paisEnvio ?? ''),
      cif: String(venta.nif ?? ''),
      telefono: String(venta.telefono ?? ''),
      cuentaBancaria: cuentaBancariaEnmascarada(venta.clienteCuentaBancaria),
      iban: String(venta.clienteIban ?? ''),
      swift: String(venta.clienteSwift ?? ''),
    },
    documento: {
      numero: numDoc,
      serie: String(venta.tipo ?? ''),
      fecha: fmtFecha(venta.fecha),
      suPedido: String(venta.pedido ?? ''),
      albaran: String(venta.albaran ?? ''),
      terminalSesion: [venta.puesto, venta.sesion].filter((x) => x != null && String(x).trim()).join('-'),
      atendidoPor: String(venta.vendedor ?? ''),
      fechaEntrega: fmtFecha(venta.fechaEntrega),
      transportista: String(venta.transporte ?? ''),
      portes: String(venta.portes ?? ''),
      observaciones: String(venta.observaciones ?? '').trim(),
      formaPago: String(venta.formaPagoDescripcion ?? venta.formasPago?.[0]?.codigo ?? ''),
      codigoBarras: `*${venta.empresa || ''}${venta.albaran || ''}*`,
      pagina: '1/1',
    },
    lineas,
    totales: {
      base: redondear2(base),
      ivas,
      importe: redondear2(Number(venta.importeFactura ?? venta.importe) || 0),
      pjeRetIrpf: Number(venta.pjeRetIrpf) || 0,
      basRetIrpf: redondear2(Number(venta.basRetIrpf) || 0),
      impRetIrpf: redondear2(Number(venta.impRetIrpf) || 0),
      liquido: redondear2(
        Number(
          venta.importeLiquido
          ?? venta.importeFactura
          ?? venta.importe
        ) || 0
      ),
    },
    vencimientos: (venta.vencimientos ?? []).map((v) => ({
      fecha: fmtFecha(v.fecha),
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
      literales: lits,
    },
  }
}

/** Tipo de plantilla A4 / ticket según el documento tipificado. */
export function tipoPlantillaDesdeVenta(venta: VentaDetalle): {
  esTicket: boolean
  plantillaTipo: string
  /** Claves en ficha Puestos (Generales II). */
  formatoKey: string | null
  nombreKey: string | null
  indiceKey: string | null
  label: string
} {
  const ft = String(venta.facturaTipo ?? '').trim().toUpperCase()
  const fe = String(venta.facturaEstado ?? '').trim().toUpperCase()
  const factura = Number(venta.factura) || 0

  if (ft === 'T' && factura > 0) {
    return {
      esTicket: true,
      plantillaTipo: 'ticket',
      formatoKey: null,
      nombreKey: null,
      indiceKey: null,
      label: 'Ticket',
    }
  }
  if (ft === 'R') {
    return {
      esTicket: false,
      plantillaTipo: 'albaran',
      formatoKey: 'formatoPresupuestos',
      nombreKey: 'impPresupuestos',
      indiceKey: 'impresoraPresupuestos',
      label: 'Presupuesto',
    }
  }
  if (ft === 'F' && factura > 0) {
    if (fe === 'G' || venta.facturaContadoDiferida) {
      return {
        esTicket: false,
        plantillaTipo: 'factura-credito',
        formatoKey: 'formatoFacturas',
        nombreKey: 'impFacturas',
        indiceKey: 'impresoraFacturas',
        label: 'Factura',
      }
    }
    return {
      esTicket: false,
      plantillaTipo: 'factura-contado',
      formatoKey: 'formatoFacturasContado',
      nombreKey: 'impFacturasContado',
      indiceKey: 'impresoraFacturasContado',
      label: 'Factura contado',
    }
  }
  // Albarán cerrado (finalizado sin tipificar T/F/R)
  return {
    esTicket: false,
    plantillaTipo: 'albaran',
    formatoKey: 'formatoAlbaranes',
    nombreKey: 'impAlbaranes',
    indiceKey: 'impresoraAlbaranes',
    label: 'Albarán',
  }
}

/** Segunda opción del diálogo al reimprimir un documento recuperado. */
export function etiquetaA4ImpresionRecuperado(venta: VentaDetalle): string {
  const ft = String(venta.facturaTipo ?? '').trim().toUpperCase()
  if (ft === 'F' || ft === 'A') return 'Factura'
  if (ft === 'R') return 'Presupuesto'
  return 'Albarán'
}
