import type { TiendaField } from '@/config/tiendas-tabs'

export type PuestoFieldLayout = 'inline' | 'checkbox'

export type PuestoField = TiendaField & {
  layout?: PuestoFieldLayout
  optionsSource?: 'trabajadores' | 'usuarios' | 'tiendas' | 'impresoras-sistema' | TiendaField['optionsSource']
}

/** Asignacion documento → impresora + plantilla (nombre en Formato*). */
export type PuestoImpresoraDoc = {
  label: string
  /**
   * Índice legacy (smallint). Opcional: p.ej. Tickets solo guarda el nombre Windows.
   */
  indiceKey?: string
  nombreKey: string
  /** Longitud max. columna Imp* / ImpresoraTickets en SQL. */
  nombreMax?: number
  /** Columna Formato* = nombre de plantilla del diseñador. */
  formatoKey?: string
  formatoMax?: number
  /** Tipo(s) en DocumentoPlantillas para el desplegable. */
  plantillaTipo?: string | string[]
  /** Clave estable para selección de fila (por defecto indiceKey o nombreKey). */
  rowKey?: string
}

export type PuestoSection = {
  title: string
  columns?: 2 | 3 | 4
  fields?: PuestoField[]
  kind?: 'fields' | 'impresoras'
  impresoras?: PuestoImpresoraDoc[]
}

export type PuestoTab = {
  id: string
  label: string
  sections: PuestoSection[]
}

/** Tipos de plantilla del diseñador (Confeccionar documentos). */
const PLANTILLAS_DOC = [
  'albaran',
  'factura-contado',
  'factura-credito',
  'factura-rectificativa',
  'ticket',
] as const

/** Documentos de Generales II: impresora + plantilla creada en el diseñador. */
export const puestoImpresorasDocumento: PuestoImpresoraDoc[] = [
  { label: 'Listados', indiceKey: 'impresora80', nombreKey: 'imp80', nombreMax: 100 },
  { label: 'Fax', indiceKey: 'fax', nombreKey: 'impFax', nombreMax: 100 },
  { label: 'Tarjetas', indiceKey: 'impresoraTarjetas', nombreKey: 'impTarjetas', nombreMax: 100 },
  {
    label: 'Albaranes',
    indiceKey: 'impresoraAlbaranes',
    nombreKey: 'impAlbaranes',
    nombreMax: 100,
    formatoKey: 'formatoAlbaranes',
    formatoMax: 100,
    plantillaTipo: 'albaran',
  },
  {
    label: 'Presupuestos',
    indiceKey: 'impresoraPresupuestos',
    nombreKey: 'impPresupuestos',
    nombreMax: 100,
    formatoKey: 'formatoPresupuestos',
    formatoMax: 100,
    plantillaTipo: [...PLANTILLAS_DOC],
  },
  {
    label: 'Facturas contado',
    indiceKey: 'impresoraFacturasContado',
    nombreKey: 'impFacturasContado',
    nombreMax: 100,
    formatoKey: 'formatoFacturasContado',
    formatoMax: 100,
    plantillaTipo: 'factura-contado',
  },
  {
    label: 'Facturas',
    indiceKey: 'impresoraFacturas',
    nombreKey: 'impFacturas',
    nombreMax: 100,
    formatoKey: 'formatoFacturas',
    formatoMax: 100,
    plantillaTipo: ['factura-credito', 'factura-rectificativa'],
  },
  {
    label: 'Recibos',
    indiceKey: 'impresoraRecibos',
    nombreKey: 'impRecibos',
    nombreMax: 100,
    formatoKey: 'formatoRecibos',
    formatoMax: 100,
    plantillaTipo: [...PLANTILLAS_DOC],
  },
  {
    label: 'Pedidos clientes',
    indiceKey: 'impresoraPedidos',
    nombreKey: 'impPedidos',
    nombreMax: 100,
    formatoKey: 'formatoPedidos',
    formatoMax: 100,
    plantillaTipo: [...PLANTILLAS_DOC],
  },
  {
    label: 'Albaranes compras',
    indiceKey: 'impresoraAlbaranCompras',
    nombreKey: 'impAlbaranCompras',
    nombreMax: 100,
    formatoKey: 'formatoAlbaranCompras',
    formatoMax: 100,
    plantillaTipo: [...PLANTILLAS_DOC],
  },
  {
    label: 'Pedidos compras',
    indiceKey: 'impresoraPedidoCompras',
    nombreKey: 'impPedidoCompras',
    nombreMax: 100,
    formatoKey: 'formatoPedidoCompras',
    formatoMax: 100,
    plantillaTipo: [...PLANTILLAS_DOC],
  },
]

export const puestoTabs: PuestoTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Impresion',
        columns: 4,
        fields: [
          { key: 'codigo', label: 'Codigo', readOnly: true, layout: 'inline' },
          { key: 'descripcion', label: 'Descripcion', span: 3, readOnly: true, layout: 'inline' },
          {
            key: 'tiendaCodigo',
            label: 'Tienda arqueo',
            type: 'select',
            optionsSource: 'tiendas',
            span: 2,
            layout: 'inline',
          },
          { key: 'impresoraTickets', label: 'Tickets (impresora Windows)', span: 2, layout: 'inline', type: 'select', optionsSource: 'impresoras-sistema' },
          {
            key: 'impresoraTicketsF',
            label: 'Slip / 2ª impresora',
            span: 2,
            layout: 'inline',
            type: 'select',
            optionsSource: 'impresoras-sistema',
          },
          { key: 'impresoraEtiquetas', label: 'Etiquetas', span: 2, layout: 'inline', type: 'select', optionsSource: 'impresoras-sistema' },
          { key: 'lineasDeSalto', label: 'Salto final', type: 'number', layout: 'inline' },
          { key: 'caracteresPorLinea', label: 'Car. linea', type: 'number', layout: 'inline' },
          { key: 'copiasTicket', label: 'Copias ticket', type: 'number', layout: 'inline' },
        ],
      },
      {
        title: 'Opciones ticket',
        columns: 4,
        fields: [
          { key: 'ticketAperturaInicio', label: 'Abrir impresora inicio', type: 'checkbox', layout: 'checkbox' },
          { key: 'ticketAutomatico', label: 'Ticket automatico', type: 'checkbox', layout: 'checkbox' },
          { key: 'impTicketIvaR', label: 'Iva incluido', type: 'checkbox', layout: 'checkbox' },
          { key: 'calculoIvaR', label: 'Calculo IVA', type: 'checkbox', layout: 'checkbox' },
          { key: 'impresionComprobanteR', label: 'Comprobante', type: 'checkbox', layout: 'checkbox' },
          { key: 'impresionB', label: 'Impresion especial', type: 'checkbox', layout: 'checkbox' },
          { key: 'repeticionFacturaLiquidar', label: 'Rep. fac. liquidar', type: 'checkbox', layout: 'checkbox' },
          { key: 'cajon', label: 'Cajon activo', type: 'checkbox', layout: 'checkbox' },
        ],
      },
      {
        title: 'Perifericos',
        columns: 4,
        fields: [
          { key: 'display', label: 'Visor cliente', span: 2, layout: 'inline' },
          { key: 'dispositivoCajon', label: 'Cajon portamonedas', span: 2, layout: 'inline' },
          { key: 'balanza', label: 'Puerto balanza', span: 2, layout: 'inline' },
          { key: 'chip', label: 'Chip puerto', span: 2, layout: 'inline' },
          { key: 'lectorTarjetas', label: 'Lector tarjetas', span: 2, layout: 'inline' },
          { key: 'radio', label: 'Puerto radio', span: 2, layout: 'inline' },
          { key: 'scaner', label: 'Scanner puerto', span: 2, layout: 'inline' },
          { key: 'scanerVelocidad', label: 'Scanner velocidad', span: 2, layout: 'inline' },
          { key: 'scanerPortatil', label: 'Scanner portatil', span: 2, layout: 'inline' },
          { key: 'cajonElectronico', label: 'Cajon electronico', span: 2, layout: 'inline' },
        ],
      },
      {
        title: 'Asignacion',
        columns: 4,
        fields: [
          { key: 'tarifa', label: 'Tarifa', type: 'number', layout: 'inline' },
          { key: 'teclado', label: 'Teclado', type: 'number', layout: 'inline' },
          { key: 'trabajadorCodigo', label: 'Vendedor', type: 'select', optionsSource: 'trabajadores', span: 2, layout: 'inline' },
          { key: 'usuarioCodigo', label: 'Usuario', type: 'select', optionsSource: 'usuarios', span: 2, layout: 'inline' },
        ],
      },
      {
        title: 'Literales ticket',
        columns: 3,
        fields: [
          { key: 'literal1', label: 'Literal 1', layout: 'inline' },
          { key: 'literal2', label: 'Literal 2', layout: 'inline' },
          { key: 'literal3', label: 'Literal 3', layout: 'inline' },
          { key: 'literal4', label: 'Literal 4', layout: 'inline' },
          { key: 'literal5', label: 'Literal 5', layout: 'inline' },
          { key: 'literal6', label: 'Literal 6', layout: 'inline' },
          { key: 'literal7', label: 'Literal 7', layout: 'inline' },
          { key: 'literal8', label: 'Literal 8', layout: 'inline' },
          { key: 'literal9', label: 'Literal 9', layout: 'inline' },
        ],
      },
    ],
  },
  {
    id: 'generales2',
    label: 'Datos Generales II',
    sections: [
      {
        title: 'Asignacion de impresoras',
        kind: 'impresoras',
        impresoras: puestoImpresorasDocumento,
      },
      {
        title: 'Opciones',
        columns: 4,
        fields: [
          { key: 'regresoaCajero', label: 'Regreso a cajero', type: 'checkbox', layout: 'checkbox' },
          { key: 'cierreAutomatico', label: 'Cierre automatico', type: 'checkbox', layout: 'checkbox' },
          { key: 'terminalPDA', label: 'Terminal PDA', type: 'checkbox', layout: 'checkbox' },
          { key: 'raton', label: 'Raton en venta', type: 'checkbox', layout: 'checkbox' },
          { key: 'saltoAutomatico', label: 'Salto linea EAN', type: 'checkbox', layout: 'checkbox' },
          { key: 'paroMostrarCambio', label: 'Paro mostrar cambio', type: 'checkbox', layout: 'checkbox' },
          { key: 'modoTablet', label: 'Modo tableta', type: 'checkbox', layout: 'checkbox' },
        ],
      },
      {
        title: 'Datos adicionales',
        columns: 4,
        fields: [
          { key: 'nvLogo', label: 'NV Logo', type: 'number', layout: 'inline' },
          { key: 'numerodeLineas', label: 'N. lineas', type: 'number', layout: 'inline' },
          { key: 'spaceSlip', label: 'Margen izq.', type: 'number', layout: 'inline' },
          { key: 'terminalDatafono', label: 'N. terminal', type: 'number', layout: 'inline' },
          { key: 'resolucionPda', label: 'Resol. PDA', type: 'number', layout: 'inline' },
          { key: 'datafono', label: 'Datafono', span: 2, layout: 'inline' },
          { key: 'seccion', label: 'Seccion', span: 2, layout: 'inline' },
          { key: 'urlQR', label: 'Url QR', span: 4, layout: 'inline' },
        ],
      },
    ],
  },
]

export function puestoVacio(): Record<string, unknown> {
  return {
    codigo: '',
    descripcion: '',
    tiendaCodigo: '',
    activo: true,
    cajon: true,
    ticketAutomatico: true,
    ticketAperturaInicio: true,
    calculoIvaR: true,
    copiasTicket: 1,
    caracteresPorLinea: 56,
    lineasDeSalto: 0,
    tarifa: 0,
    teclado: 1,
  }
}

export function validarPuestoObligatorios(ficha: Record<string, unknown>): string | null {
  if (!String(ficha.codigo ?? '').trim()) return 'El codigo es obligatorio'
  if (!String(ficha.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  return null
}
