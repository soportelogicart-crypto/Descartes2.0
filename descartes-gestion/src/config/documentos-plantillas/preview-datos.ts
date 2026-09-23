import type { DocumentoTipo } from './types'

export type DocumentoPreviewLinea = {
  articulo: string
  descripcion: string
  unidades: number
  precioSinIva?: number
  precio?: number
  dto: number
  pjeIva: number
  importe: number
  pvp?: number
  albaranCabecera?: string
  nota?: string
}

export type DocumentoPreviewDatos = {
  empresa: {
    nombre: string
    razonSocial: string
    nif: string
    direccion: string
    cp: string
    poblacion: string
    provincia: string
    telefono: string
    fax: string
    email: string
    emblemaUrl: string
    banco: string
    iban: string
    swift: string
  }
  cliente: {
    codigo: string
    nombre: string
    direccion: string
    cp: string
    poblacion: string
    provincia: string
    pais: string
    cif: string
    telefono: string
    cuentaBancaria: string
    iban: string
    swift: string
  }
  documento: {
    numero: string
    serie: string
    fecha: string
    suPedido: string
    albaran: string
    terminalSesion: string
    atendidoPor: string
    fechaEntrega: string
    transportista: string
    portes: string
    observaciones: string
    formaPago: string
    codigoBarras: string
    pagina: string
  }
  lineas: DocumentoPreviewLinea[]
  totales: {
    base: number
    ivas: { pje: number; base: number; cuota: number }[]
    importe: number
    pjeRetIrpf?: number
    basRetIrpf?: number
    impRetIrpf?: number
    liquido?: number
  }
  vencimientos: { fecha: string; importe: number }[]
  verifactu: {
    qrPayload: string
    url: string
  }
  /** Textos de Tiendas → Datos generales (pies de documento). */
  tienda: {
    literalFacturaDiferida: string
    literalFacturaContado: string
    literalPresupuesto: string
    literalVale: string
    /** Nº de literales del puesto a imprimir en el ticket. */
    literalTicket: number
  }
  /** Literales 1…9 del puesto (ticket). */
  puesto: {
    literales: string[]
  }
  /**
   * Datos de artículo para plantillas tipo etiqueta (binds articulo.*).
   * Ausente en documentos A4 / ticket.
   */
  articulo?: {
    codigo: string
    descripcion: string
    ean: string
    precio: number
    lote?: string
  }
}

/** Emblema placeholder (SVG data URL). */
export const EMBLEMA_PLACEHOLDER =
  'data:image/svg+xml,' +
  encodeURIComponent(
    `<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120">
      <rect width="120" height="120" rx="8" fill="#e2e8f0"/>
      <text x="60" y="58" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="14" fill="#64748b">LOGO</text>
      <text x="60" y="78" text-anchor="middle" font-family="Segoe UI,sans-serif" font-size="11" fill="#94a3b8">Emblema</text>
    </svg>`
  )

const baseEmpresa = {
  nombre: 'GARDEN & HOME LA RASA, S.L.',
  razonSocial: 'GARDEN CENTER GAIÀ',
  nif: 'B13983507',
  direccion: 'Antiga ctra, N-340, km 1173',
  cp: '43893',
  poblacion: 'Altafulla',
  provincia: 'Tarragona',
  telefono: '977652547',
  fax: '',
  email: 'administracio@gruplarasa.cat',
  emblemaUrl: '',
  banco: 'La Caixa 2100-0106-70-0200347786',
  iban: 'ES10 2100 0106 7002 0034 7786',
  swift: 'CAIXESBBXXX',
}

const baseCliente = {
  codigo: '00100003',
  nombre: 'G.E.P.E.C',
  direccion: 'VILAR, 5',
  cp: '43201',
  poblacion: 'REUS',
  provincia: 'TARRAGONA',
  pais: 'España',
  cif: 'G43301498',
  telefono: '',
  cuentaBancaria: '0081730519000136XXXX',
  iban: 'ES49 0081 7305 1900 0136 XXXX',
  swift: 'BSABESBBXXX',
}

const baseTienda = {
  literalFacturaDiferida: 'Gracias por su confianza. Condiciones según contrato.',
  literalFacturaContado: 'Pagado al contado. Conserve este documento.',
  literalPresupuesto: 'Presupuesto válido 30 días. IVA no incluido salvo indicación.',
  literalVale: 'Vale no reembolsable en metálico.',
  literalTicket: 3,
}

const basePuestoLiterales = [
  '¡Gracias por su compra!',
  'www.gruplarasa.cat',
  'Conservar el ticket',
  '',
  '',
  '',
  '',
  '',
  '',
]

export function datosPreviewPorTipo(tipo: DocumentoTipo): DocumentoPreviewDatos {
  const comunes: DocumentoPreviewDatos = {
    empresa: { ...baseEmpresa },
    cliente: { ...baseCliente },
    documento: {
      numero: '1-26021571',
      serie: '1',
      fecha: '05/08/2026',
      suPedido: '',
      albaran: '26021571',
      terminalSesion: '21-6',
      atendidoPor: '',
      fechaEntrega: '',
      transportista: '',
      portes: '',
      observaciones: 'hola 3',
      formaPago: 'RECIBO DOMICILIADO',
      codigoBarras: '*$60001026021571*',
      pagina: '1/1',
    },
    lineas: [
      {
        articulo: '0010031961',
        descripcion: 'PEONIA 3L',
        unidades: 1,
        precioSinIva: 16.68,
        dto: 0,
        pjeIva: 10,
        importe: 16.68,
        pvp: 18.35,
      },
    ],
    totales: {
      base: 16.68,
      ivas: [{ pje: 10, base: 16.68, cuota: 1.67 }],
      importe: 18.35,
    },
    vencimientos: [],
    verifactu: {
      qrPayload: 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B13983507&numserie=1-26021571&fecha=05-08-2026&importe=18.35',
      url: 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR',
    },
    tienda: { ...baseTienda },
    puesto: {
      literales: basePuestoLiterales.slice(0, Math.max(0, baseTienda.literalTicket)),
    },
  }

  if (tipo === 'ticket') {
    return {
      ...comunes,
      documento: {
        ...comunes.documento,
        numero: 'T-26021571',
        fecha: '12/08/2026 10:42',
        terminalSesion: '02-14',
        atendidoPor: 'Maria',
        observaciones: '',
      },
      lineas: [
        {
          articulo: '0010031961',
          descripcion: 'PEONIA 3L',
          unidades: 1,
          precio: 18.35,
          dto: 0,
          pjeIva: 10,
          importe: 18.35,
        },
        {
          articulo: '001000055',
          descripcion: 'SUSTRATO 20L',
          unidades: 2,
          precio: 6.5,
          dto: 0,
          pjeIva: 21,
          importe: 13.0,
        },
      ],
      totales: {
        base: 28.35,
        ivas: [
          { pje: 10, base: 16.68, cuota: 1.67 },
          { pje: 21, base: 10.74, cuota: 2.26 },
        ],
        importe: 31.35,
      },
      puesto: {
        literales: basePuestoLiterales.slice(0, baseTienda.literalTicket),
      },
    }
  }

  if (tipo === 'etiqueta') {
    return {
      ...comunes,
      articulo: {
        codigo: '0010031961',
        descripcion: 'PEONIA 3L',
        ean: '8437001234567',
        precio: 18.35,
        lote: '',
      },
      documento: {
        ...comunes.documento,
        numero: '',
        observaciones: '',
        codigoBarras: '8437001234567',
      },
      lineas: [],
      totales: { base: 0, ivas: [], importe: 18.35 },
    }
  }

  if (tipo === 'factura-contado') {
    return {
      ...comunes,
      cliente: {
        ...baseCliente,
        codigo: '00101934',
        nombre: 'OKIWOK S.L',
        direccion: 'Av/ Velgica 22',
        cp: '43883',
        poblacion: 'Roda de Vera',
        cif: 'B55537336',
      },
      documento: {
        ...comunes.documento,
        numero: '1-26000001',
        fecha: '15/01/2026',
        albaran: '26000580',
        terminalSesion: '01-2',
        atendidoPor: 'Helena',
        observaciones: '',
        codigoBarras: '*$60001026000580*',
      },
      lineas: [
        {
          articulo: '',
          descripcion: 'Albarán 1-26.000.580 de Fecha 15/01/2026',
          unidades: 0,
          dto: 0,
          pjeIva: 0,
          importe: 0,
          albaranCabecera: 'Albarán 1-26.000.580 de Fecha 15/01/2026',
        },
        {
          articulo: '454',
          descripcion: 'TULIPA bara',
          unidades: 5,
          precioSinIva: 2.27,
          dto: 10,
          pjeIva: 10,
          importe: 10.23,
          pvp: 2.5,
        },
        {
          articulo: '56019',
          descripcion: 'RANUNCULO C13',
          unidades: 7,
          precioSinIva: 3.73,
          dto: 0,
          pjeIva: 10,
          importe: 26.09,
          pvp: 4.1,
        },
        {
          articulo: '59997',
          descripcion: 'JARDINERA VENEZIA RA 60 MA ANTRACITA',
          unidades: 2,
          precioSinIva: 9.05,
          dto: 0,
          pjeIva: 21,
          importe: 18.1,
          pvp: 10.95,
        },
      ],
      totales: {
        base: 54.42,
        ivas: [
          { pje: 10, base: 36.32, cuota: 3.63 },
          { pje: 21, base: 18.1, cuota: 3.8 },
        ],
        importe: 61.85,
        pjeRetIrpf: 15,
        basRetIrpf: 36.32,
        impRetIrpf: 5.45,
        liquido: 56.4,
      },
      verifactu: {
        qrPayload: 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B13983507&numserie=1-26000001&fecha=15-01-2026&importe=61.85',
        url: 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR',
      },
    }
  }

  if (tipo === 'factura-credito' || tipo === 'factura-rectificativa') {
    const rect = tipo === 'factura-rectificativa'
    return {
      ...comunes,
      cliente: {
        ...baseCliente,
        codigo: '00100002',
        nombre: 'JUDIT MATEU COLL',
        direccion: 'AV. CATALUNYA 6 2-3',
        cp: '43764',
        poblacion: 'EL CATLLAR',
        cif: '39688827G',
      },
      documento: {
        ...comunes.documento,
        numero: rect ? '1-260000004' : '1-26000436',
        fecha: rect ? '07/08/2026' : '04/08/2026',
        albaran: '',
        terminalSesion: '',
        atendidoPor: '',
        observaciones: '',
        codigoBarras: '',
        formaPago: 'CREDITO',
      },
      lineas: rect
        ? [
            {
              articulo: '',
              descripcion: 'Albarán 1-26.021.583 de Fecha 06/08/2026',
              unidades: 0,
              dto: 0,
              pjeIva: 0,
              importe: 0,
              albaranCabecera: 'Albarán 1-26.021.583 de Fecha 06/08/2026',
            },
            {
              articulo: '0010031957',
              descripcion: 'KOKEDAMA',
              unidades: -1,
              precio: 16.36,
              dto: 0,
              pjeIva: 10,
              importe: -16.36,
            },
            {
              articulo: '0010031975',
              descripcion: 'RAMA RUSCUS. 70CM. VERDE',
              unidades: -1,
              precio: 4.55,
              dto: 0,
              pjeIva: 21,
              importe: -4.55,
            },
          ]
        : [
            {
              articulo: '',
              descripcion: 'Albarán 1-26.021.560 de Fecha 04/08/2026',
              unidades: 0,
              dto: 0,
              pjeIva: 0,
              importe: 0,
              albaranCabecera: 'Albarán 1-26.021.560 de Fecha 04/08/2026',
            },
            {
              articulo: '0010031948',
              descripcion: 'SOL LIMONCIDE TRAT.TOTAL 250ML',
              unidades: 1,
              precio: 20.12,
              dto: 0,
              pjeIva: 21,
              importe: 20.12,
            },
            {
              articulo: '',
              descripcion: 'Albarán 1-26.021.561 de Fecha 04/08/2026',
              unidades: 0,
              dto: 0,
              pjeIva: 0,
              importe: 0,
              albaranCabecera: 'Albarán 1-26.021.561 de Fecha 04/08/2026',
            },
            {
              articulo: '0010031972',
              descripcion: 'PELLET 15 KL',
              unidades: 1,
              precio: 12.36,
              dto: 0,
              pjeIva: 10,
              importe: 12.36,
            },
            {
              articulo: '0010031992',
              descripcion: 'BRONTE 36X31',
              unidades: 1,
              precio: 69.42,
              dto: 0,
              pjeIva: 21,
              importe: 69.42,
            },
          ],
      totales: rect
        ? {
            base: -23.5,
            ivas: [
              { pje: 10, base: -18, cuota: -1.8 },
              { pje: 21, base: -5.5, cuota: -1.16 },
            ],
            importe: -26.46,
          }
        : {
            base: 141.63,
            ivas: [
              { pje: 21, base: 89.54, cuota: 18.81 },
              { pje: 10, base: 52.09, cuota: 5.21 },
            ],
            importe: 165.65,
            pjeRetIrpf: 15,
            basRetIrpf: 89.54,
            impRetIrpf: 13.43,
            liquido: 152.22,
          },
      vencimientos: rect ? [] : [{ fecha: '04/09/2026', importe: 165.65 }],
      verifactu: {
        qrPayload: rect
          ? 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B13983507&numserie=1-260000004&fecha=07-08-2026&importe=-26.46'
          : 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR?nif=B13983507&numserie=1-26000436&fecha=04-08-2026&importe=165.65',
        url: 'https://prewww2.aeat.es/wlpl/TIKE-CONT/ValidarQR',
      },
    }
  }

  return comunes
}

export function getByPath(data: DocumentoPreviewDatos, path: string): unknown {
  const parts = path.split('.')
  let cur: unknown = data
  for (const p of parts) {
    if (cur == null || typeof cur !== 'object') return undefined
    cur = (cur as Record<string, unknown>)[p]
  }
  return cur
}

export function formatImporte(n: number | undefined | null): string {
  if (n == null || Number.isNaN(n)) return ''
  return n.toLocaleString('es-ES', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}
