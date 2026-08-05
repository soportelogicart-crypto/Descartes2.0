import type { TiendaField } from '@/config/tiendas-tabs'

export type PuestoFieldLayout = 'inline' | 'checkbox'

export type PuestoField = TiendaField & {
  layout?: PuestoFieldLayout
  optionsSource?: 'trabajadores' | 'usuarios' | 'tiendas' | TiendaField['optionsSource']
}

export type PuestoSection = {
  title: string
  columns?: 2 | 3 | 4
  fields: PuestoField[]
}

export type PuestoTab = {
  id: string
  label: string
  sections: PuestoSection[]
}

function impresoraSection(
  title: string,
  indiceKey: string,
  nombreKey: string,
  formatoKey?: string
): PuestoSection {
  const fields: PuestoField[] = [
    { key: indiceKey, label: 'Ind.', type: 'number', layout: 'inline' },
    { key: nombreKey, label: 'Impresora', layout: 'inline', span: 2 },
  ]
  if (formatoKey) {
    fields.push({ key: formatoKey, label: 'Formato', layout: 'inline' })
  }
  return { title, columns: 4, fields }
}

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
          { key: 'impresoraTickets', label: 'Tickets', span: 2, layout: 'inline' },
          { key: 'impresoraTicketsF', label: 'Slip printer', span: 2, layout: 'inline' },
          { key: 'impresoraEtiquetas', label: 'Etiquetas', span: 2, layout: 'inline' },
          { key: 'lineasDeSalto', label: 'Salto final', type: 'number', layout: 'inline' },
          { key: 'caracteresPorLinea', label: 'Car. linea', type: 'number', layout: 'inline' },
          { key: 'copiasTicket', label: 'Copias', type: 'number', layout: 'inline' },
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
      impresoraSection('Listados', 'impresora80', 'imp80'),
      impresoraSection('Fax', 'fax', 'impFax'),
      impresoraSection('Tarjetas', 'impresoraTarjetas', 'impTarjetas'),
      impresoraSection('Albaranes', 'impresoraAlbaranes', 'impAlbaranes', 'formatoAlbaranes'),
      impresoraSection('Presupuestos', 'impresoraPresupuestos', 'impPresupuestos', 'formatoPresupuestos'),
      impresoraSection('Facturas contado', 'impresoraFacturasContado', 'impFacturasContado', 'formatoFacturasContado'),
      impresoraSection('Facturas', 'impresoraFacturas', 'impFacturas', 'formatoFacturas'),
      impresoraSection('Recibos', 'impresoraRecibos', 'impRecibos', 'formatoRecibos'),
      impresoraSection('Pedidos clientes', 'impresoraPedidos', 'impPedidos', 'formatoPedidos'),
      impresoraSection('Albaranes compras', 'impresoraAlbaranCompras', 'impAlbaranCompras', 'formatoAlbaranCompras'),
      impresoraSection('Pedidos compras', 'impresoraPedidoCompras', 'impPedidoCompras', 'formatoPedidoCompras'),
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
