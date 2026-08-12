import type { DocumentoPreviewDatos } from '@/config/documentos-plantillas/preview-datos'
import { EMBLEMA_PLACEHOLDER } from '@/config/documentos-plantillas/preview-datos'
import type { MetaImpresionA4Puesto } from '@/composables/impresionDocumentoA4Shared'
import type { AlbaranCompraDetalle, PedidoProveedorDetalle } from '@/types/compras'

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

type ExtrasEmpresa = {
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
}

function basePreview(
  extras: ExtrasEmpresa,
  opts: {
    empresaCodigo: string
    terceroCodigo: string
    terceroNombre: string
    numero: string
    serie: string
    fecha: string | null | undefined
    suRef: string
    observaciones: string
    codigoBarras: string
    lineas: DocumentoPreviewDatos['lineas']
    importe: number
    base: number
    iva: number
  }
): DocumentoPreviewDatos {
  const nLit = Math.max(0, Math.min(9, Number(extras.literalTicket ?? 3) || 0))
  const lits = (extras.literalesPuesto ?? []).slice(0, nLit)
  const base = redondear2(opts.base)
  const cuota = redondear2(opts.iva)

  return {
    empresa: {
      nombre: extras.empresaNombre || opts.empresaCodigo,
      razonSocial: extras.empresaNombre || opts.empresaCodigo,
      nif: extras.empresaNif || '',
      direccion: extras.empresaDireccion || '',
      cp: extras.empresaCp || '',
      poblacion: extras.empresaPoblacion || '',
      provincia: extras.empresaProvincia || '',
      telefono: extras.empresaTelefono || '',
      fax: '',
      email: extras.empresaEmail || '',
      emblemaUrl: EMBLEMA_PLACEHOLDER,
      banco: '',
      iban: '',
      swift: '',
    },
    // Plantillas genéricas usan bloque «cliente»; en compras = proveedor.
    cliente: {
      codigo: opts.terceroCodigo,
      nombre: opts.terceroNombre,
      direccion: '',
      cp: '',
      poblacion: '',
      provincia: '',
      pais: '',
      cif: '',
      telefono: '',
    },
    documento: {
      numero: opts.numero,
      serie: opts.serie,
      fecha: fmtFecha(opts.fecha),
      suPedido: opts.suRef,
      albaran: opts.numero,
      terminalSesion: '',
      atendidoPor: '',
      fechaEntrega: '',
      transportista: '',
      portes: '',
      observaciones: opts.observaciones,
      codigoBarras: opts.codigoBarras,
      pagina: '1/1',
    },
    lineas: opts.lineas,
    totales: {
      base,
      ivas: cuota || base ? [{ pje: 0, base, cuota }] : [],
      importe: redondear2(opts.importe),
    },
    vencimientos: [],
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

/** Meta Generales II: Albaranes compras. */
export function metaImpresionAlbaranCompra(): MetaImpresionA4Puesto {
  return {
    plantillaTipo: 'albaran',
    formatoKey: 'formatoAlbaranCompras',
    nombreKey: 'impAlbaranCompras',
    indiceKey: 'impresoraAlbaranCompras',
    label: 'Albarán compra',
  }
}

/** Meta Generales II: Pedidos compras. */
export function metaImpresionPedidoCompra(): MetaImpresionA4Puesto {
  return {
    plantillaTipo: 'albaran',
    formatoKey: 'formatoPedidoCompras',
    nombreKey: 'impPedidoCompras',
    indiceKey: 'impresoraPedidoCompras',
    label: 'Pedido proveedor',
  }
}

export function albaranCompraAPreviewDatos(
  alb: AlbaranCompraDetalle,
  extras: ExtrasEmpresa = {}
): DocumentoPreviewDatos {
  const lineas = (alb.lineas ?? [])
    .filter((l) => {
      const art = String(l.articulo ?? '').trim()
      return art !== '' && art.toUpperCase() !== 'NO'
    })
    .map((l) => {
      const cant = Number(l.cantidad) || 0
      const precio = Number(l.precio) || 0
      const dto = Number(l.pjeDto) || 0
      const factor = 1 - dto / 100
      return {
        articulo: String(l.articulo ?? ''),
        descripcion: String(l.descripcion ?? ''),
        unidades: cant,
        precio,
        dto,
        pjeIva: 0,
        importe: redondear2(cant * precio * factor),
        pvp: precio,
      }
    })

  return basePreview(extras, {
    empresaCodigo: String(alb.empresa || ''),
    terceroCodigo: String(alb.proveedor ?? ''),
    terceroNombre: String(alb.razonSocial ?? alb.proveedor ?? ''),
    numero: String(alb.albaran ?? ''),
    serie: String(alb.serie ?? ''),
    fecha: alb.fechaAlbaran,
    suRef: String(alb.suAlbaran ?? ''),
    observaciones: String(alb.observaciones ?? ''),
    codigoBarras: `*C${alb.empresa || ''}${alb.albaran || ''}*`,
    lineas,
    importe: Number(alb.importeAlb) || 0,
    base: Number(alb.importeAlb) - Number(alb.importeIva || 0) - Number(alb.importeRec || 0),
    iva: Number(alb.importeIva) || 0,
  })
}

export function pedidoProveedorAPreviewDatos(
  ped: PedidoProveedorDetalle,
  extras: ExtrasEmpresa = {}
): DocumentoPreviewDatos {
  const lineas = (ped.lineas ?? [])
    .filter((l) => {
      const art = String(l.articulo ?? '').trim()
      return art !== '' && art.toUpperCase() !== 'NO'
    })
    .map((l) => {
      const cant = Number(l.cantidadPed) || 0
      const precio = Number(l.precioPed) || 0
      const dto = Number(l.pjeDto) || 0
      const factor = 1 - dto / 100
      const importe =
        l.importe != null ? Number(l.importe) || 0 : redondear2(cant * precio * factor)
      return {
        articulo: String(l.articulo ?? ''),
        descripcion: String(l.descripcion ?? ''),
        unidades: cant,
        precio,
        dto,
        pjeIva: 0,
        importe,
        pvp: precio,
      }
    })

  return basePreview(extras, {
    empresaCodigo: String(ped.empresa || ''),
    terceroCodigo: String(ped.proveedor ?? ''),
    terceroNombre: String(ped.razonSocial ?? ped.proveedor ?? ''),
    numero: String(ped.pedido ?? ''),
    serie: '',
    fecha: ped.fechaPedido,
    suRef: '',
    observaciones: String(ped.observaciones ?? ''),
    codigoBarras: `*P${ped.empresa || ''}${ped.pedido || ''}*`,
    lineas,
    importe: Number(ped.importe) || 0,
    base: Number(ped.importe) || 0,
    iva: 0,
  })
}
