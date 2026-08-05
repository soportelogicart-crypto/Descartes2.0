export type TiendaFieldType = 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'impuesto'

export type TiendaFieldLayout = 'inline' | 'checkbox' | 'textarea'

export type TiendaField = {
  key: string
  label: string
  type?: TiendaFieldType
  layout?: TiendaFieldLayout
  span?: 1 | 2 | 3 | 4
  readOnly?: boolean
  required?: boolean
  optionsSource?: 'almacenes' | 'impuestos' | 'formas-pago'
}

export type TiendaSection = {
  title: string
  columns?: 2 | 3 | 4 | 5
  fields: TiendaField[]
}

export type TiendaTab = {
  id: string
  label: string
  sections: TiendaSection[]
}

function cb(key: string, label: string, extra: Partial<TiendaField> = {}): TiendaField {
  return { key, label, type: 'checkbox', layout: 'checkbox', ...extra }
}

function inline(key: string, label: string, extra: Partial<TiendaField> = {}): TiendaField {
  return { key, label, layout: 'inline', ...extra }
}

function area(key: string, label: string, extra: Partial<TiendaField> = {}): TiendaField {
  return { key, label, type: 'textarea', layout: 'textarea', span: 4, ...extra }
}

export const tiendaTabs: TiendaTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('codigo', 'Codigo', { readOnly: true }),
          inline('nombre', 'Nombre comercial', { span: 3 }),
          inline('nombreFiscal', 'Nombre fiscal', { span: 4 }),
          inline('codigoExterno', 'Codigo externo'),
          inline('nif', 'N.I.F.'),
        ],
      },
      {
        title: 'Direccion',
        columns: 4,
        fields: [
          inline('direccion', 'Direccion', { span: 4 }),
          inline('codigoPostal', 'C.P.'),
          inline('poblacion', 'Poblacion', { span: 2 }),
          inline('provincia', 'Provincia'),
          inline('pais', 'Pais'),
        ],
      },
      {
        title: 'Contacto',
        columns: 4,
        fields: [
          inline('telefono1', 'Telefono 1'),
          inline('telefono2', 'Telefono 2'),
          inline('email', 'E-Mail', { span: 2 }),
          inline('pasaporteFitosanitario', 'Pas. fitosanitario', { span: 2 }),
          inline('almacenCodigo', 'Almacen', { type: 'select', optionsSource: 'almacenes', span: 2, required: true }),
        ],
      },
      {
        title: 'Literales documentos',
        columns: 4,
        fields: [
          area('literalFacturaDiferida', 'Pie Fra. diferida'),
          area('literalFacturaContado', 'Pie Fra. contado'),
          area('literalPresupuesto', 'Pie presupuesto'),
          inline('literalVale', 'Pie vale', { span: 2 }),
          inline('literalTicket', 'Literales ticket', { type: 'number' }),
        ],
      },
    ],
  },
  {
    id: 'facturacion',
    label: 'Facturacion',
    sections: [
      {
        title: 'Impuestos',
        columns: 3,
        fields: [
          inline('codIvaSuperReducido', 'Iva super reducido', { type: 'impuesto' }),
          inline('codIvaReducido', 'Iva reducido', { type: 'impuesto' }),
          inline('codIvaNormal', 'Iva normal', { type: 'impuesto' }),
          inline('codIvaIncrementado', 'Iva incrementado', { type: 'impuesto' }),
          inline('codIvaEsp5', 'Iva esp. 5', { type: 'impuesto' }),
          inline('codIvaEsp6', 'Iva esp. 6', { type: 'impuesto' }),
        ],
      },
      {
        title: 'Opciones IVA',
        columns: 4,
        fields: [
          cb('swIva', 'Iva incluido'),
          cb('recargo', 'Recargo equivalencia'),
          cb('regimenCanario', 'Regimen canario'),
          cb('intPreciosIvaIncluido', 'Int. precios IVA incl.'),
        ],
      },
      {
        title: 'Divisas y descuentos',
        columns: 4,
        fields: [
          inline('divisa', 'Divisa', { type: 'select', optionsSource: 'formas-pago', span: 2, required: true }),
          inline('divisaAlt', 'Divisa alternativa', { type: 'select', optionsSource: 'formas-pago', span: 2, required: true }),
          inline('dto', 'Descuento', { type: 'number' }),
          cb('sumarDescuento', 'Dto acumulativo'),
          cb('bloqueoFidelizacion', 'Bloqueo fidelizacion'),
          inline('pjeRetIrpf', '% Ret. IRPF', { type: 'number' }),
          inline('ctbRetIrpf', 'Cta. retencion IRPF', { type: 'number' }),
          inline('centroCoste', 'Centro de coste', { span: 2 }),
        ],
      },
      {
        title: 'Series y tarifas',
        columns: 4,
        fields: [
          cb('solicitarPerfil', 'Solicitar vendedor'),
          inline('tarifa', 'Tarifa', { type: 'number', required: true }),
          inline('datos', 'Datos', { type: 'number' }),
          inline('prefijo', 'Prefijo tienda', { type: 'number' }),
          inline('serieFacturas', 'Serie facturas', { span: 2 }),
          cb('facturasRectificativas', 'Fact. rectificativas'),
          inline('serieAbonos', 'Serie fact. rectif.', { span: 2 }),
          inline('minimoFidelizacion', 'Min. fidelizacion', { type: 'number' }),
          inline('valesEmitidos', 'Vales emitidos', { type: 'number' }),
          inline('valesRecibidos', 'Vales recibidos', { type: 'number' }),
        ],
      },
    ],
  },
  {
    id: 'parametros',
    label: 'Parametros',
    sections: [
      {
        title: 'Central',
        columns: 4,
        fields: [
          cb('esCentral', 'Actua como central'),
          cb('facturaLaCentral', 'Central factura'),
          cb('comunicaCentral', 'Central comunica'),
          inline('unidadComunicacion', 'Unidad comunicacion', { span: 2 }),
        ],
      },
      {
        title: 'Stock y venta',
        columns: 4,
        fields: [
          cb('almacenesInternos', 'Stock almacenes internos'),
          cb('conexionOnline', 'Actualizacion stock online'),
          cb('avisoStockCero', 'Aviso stock negativo/min.'),
          cb('dtoSiCambioPrecio', 'Dto al cambiar precio'),
          cb('desglosarBasesTicketIvaInc', 'Desglosar bases ticket', { required: true }),
          cb('imprimirCodigoArticulo', 'Imprimir codigo articulo'),
          cb('obligarDineroEntregado', 'Entrada efectivo oblig.'),
          cb('impSaldoTicket', 'Imprimir saldo ticket'),
          cb('crearReciboCredito', 'Generar recibo credito'),
        ],
      },
      {
        title: 'Generacion codigos',
        columns: 4,
        fields: [
          inline('aecoc', 'A.E.C.O.C.', { type: 'number' }),
          cb('genBarras', 'Generar codigo barras'),
          cb('genDesdeCodigo', '+ Codigo'),
          cb('genClientes', 'Generar clientes'),
          cb('genArticulos', 'Generar articulos'),
          cb('genProveedores', 'Generar proveedores'),
          inline('articuloMantenimiento', 'Articulo mantenimiento', { span: 2 }),
        ],
      },
      {
        title: 'Vales y tarifas escalado',
        columns: 4,
        fields: [
          cb('controlVales', 'Control vales'),
          inline('minimoCambioVales', 'Min. cambio vales', { type: 'number' }),
          inline('importeObligatorioFactura', 'Factura oblig. importe >', { type: 'number', span: 2 }),
          cb('tarifa1Escalado', 'Tarifa 1 escalado'),
          cb('tarifa2Escalado', 'Tarifa 2 escalado'),
          cb('tarifa3Escalado', 'Tarifa 3 escalado'),
          cb('tarifa4Escalado', 'Tarifa 4 escalado'),
          cb('tarifa5Escalado', 'Tarifa 5 escalado'),
          cb('tarifa6Escalado', 'Tarifa 6 escalado'),
          cb('tarifa7Escalado', 'Tarifa 7 escalado'),
          cb('tarifa8Escalado', 'Tarifa 8 escalado'),
          cb('tarifa9Escalado', 'Tarifa 9 escalado'),
        ],
      },
    ],
  },
  {
    id: 'parametros2',
    label: 'Parametros II',
    sections: [
      {
        title: 'Compras y listados',
        columns: 4,
        fields: [
          cb('checkProveedor', 'Check pedido proveedor'),
          cb('cambiaProveedor', 'Cambiar ult. proveedor'),
          cb('maximizarListado', 'Maximizar listados'),
          inline('copiasAlbaranReparto', 'Copias alb. reserva', { type: 'number' }),
          inline('copiasAlbaran', 'Copias albaran', { type: 'number' }),
          inline('copiasFactura', 'Copias factura', { type: 'number' }),
          inline('copiasFacturaReparto', 'Copias fact. reparto', { type: 'number' }),
          inline('copiasSimulacion', 'Copias presupuesto', { type: 'number' }),
        ],
      },
      {
        title: 'Etiquetas',
        columns: 4,
        fields: [
          inline('impEtiquetasSinEans', 'Etiquetas sin EAN', { type: 'number' }),
          cb('etiquetasIvaIncluido', 'Etiquetas con IVA'),
          inline('impEtiquetasSoloEansPropios', 'Solo EAN propios', { type: 'number' }),
          inline('decimalesPrecio', 'Decimales etiqueta', { type: 'number' }),
          cb('desglosarEscandallo', 'Desglosar escandallo'),
          inline('recalculoTarifas', 'Calculo tarifas manual', { span: 2 }),
          inline('literalInvitacion', 'Literal invitacion', { type: 'number' }),
        ],
      },
    ],
  },
  {
    id: 'parametros3',
    label: 'Parametros III',
    sections: [
      {
        title: 'Textos y rutas',
        columns: 4,
        fields: [
          area('litPiePedidoCompra', 'Literal pie pedido compra'),
          inline('pathExcelEstadisticas', 'Ruta excels estadisticas', { span: 4 }),
          cb('appWeb', 'App Web'),
        ],
      },
      {
        title: 'Atributos',
        columns: 2,
        fields: Array.from({ length: 10 }, (_, i) => inline(`atri${i}`, `Atributo ${i}`)),
      },
      {
        title: 'Columnas atributos',
        columns: 5,
        fields: Array.from({ length: 10 }, (_, i) =>
          inline(`colAtri${i}`, `Col. ${i}`, { type: 'number' })
        ),
      },
    ],
  },
  {
    id: 'literales',
    label: 'Literales',
    sections: [
      {
        title: 'Literales adicionales',
        columns: 4,
        fields: [
          cb('centrarLiteralesAdicionales', 'Centrar', { span: 4 }),
          ...Array.from({ length: 20 }, (_, i) =>
            inline(`literal${i + 6}`, `Literal ${i + 6}`, { span: 2 })
          ),
        ],
      },
    ],
  },
]

export const contadoresSections: TiendaSection[] = [
  {
    title: 'Contadores',
    columns: 4,
    fields: [
      { key: 'ultPedidoCom', label: 'Pedidos', type: 'number', layout: 'inline' },
      { key: 'ultAlbaranCom', label: 'Albaran compra', type: 'number', layout: 'inline' },
      { key: 'ultAlbaranVen', label: 'Albaran ventas', type: 'number', layout: 'inline' },
      { key: 'ultAlbaranTra', label: 'Traspasos', type: 'number', layout: 'inline' },
      { key: 'ultTicket', label: 'Tickets', type: 'number', layout: 'inline' },
      { key: 'ultFactura', label: 'Facturas', type: 'number', layout: 'inline' },
      { key: 'ultFacturaDiferida', label: 'Facturas dif.', type: 'number', layout: 'inline' },
      { key: 'ultPedidoCli', label: 'Pedidos clientes', type: 'number', layout: 'inline' },
      { key: 'ultEnvio', label: 'Envio', type: 'number', layout: 'inline' },
      { key: 'ultEan', label: 'Codigos barras', type: 'number', layout: 'inline' },
      { key: 'ultCliente', label: 'Clientes', type: 'number', layout: 'inline' },
      { key: 'ultAbono', label: 'Fact. rectificativas', type: 'number', layout: 'inline' },
      { key: 'ultAbonoDiferido', label: 'Fact. rectif. dif.', type: 'number', layout: 'inline' },
      { key: 'ultProveedor', label: 'Proveedores', type: 'number', layout: 'inline' },
      { key: 'ultPreFactura', label: 'Pre-facturas', type: 'number', layout: 'inline' },
      { key: 'ultOrdenFabricacion', label: 'Orden fabricacion', type: 'number', layout: 'inline' },
      { key: 'ultTransacCajon', label: 'Ult. transac. cajon', type: 'number', layout: 'inline' },
    ],
  },
]

export type ValidacionTienda = {
  mensaje: string | null
  campos: string[]
}

function campoTiendaVacio(ficha: Record<string, unknown>, field: TiendaField): boolean {
  const v = ficha[field.key]
  if (v == null || v === '') return true
  if (typeof v === 'string' && !v.trim()) return true
  if (field.key === 'almacenCodigo' && Number(v) <= 0) return true
  return false
}

export function validarTiendaObligatorios(ficha: Record<string, unknown>): ValidacionTienda {
  const campos: string[] = []
  const mensajes: string[] = []

  const codigo = String(ficha.codigo ?? '').trim()
  if (!codigo) {
    campos.push('codigo')
    mensajes.push('El codigo de tienda es obligatorio')
  } else if (codigo.length > 3) {
    campos.push('codigo')
    mensajes.push('El codigo de tienda admite como maximo 3 caracteres')
  }

  for (const tab of tiendaTabs) {
    for (const section of tab.sections) {
      for (const field of section.fields) {
        if (!field.required || field.type === 'checkbox') continue
        if (campos.includes(field.key)) continue
        if (!campoTiendaVacio(ficha, field)) continue
        campos.push(field.key)
        mensajes.push(`${field.label} es obligatorio`)
      }
    }
  }

  return {
    mensaje: mensajes[0] ?? null,
    campos,
  }
}

/** Tab donde esta un campo (para saltar al guardar con errores). */
export function tabDeCampoTienda(campo: string): string | null {
  if (campo === 'codigo' || campo === 'nombre') return 'generales'
  for (const tab of tiendaTabs) {
    if (tab.sections.some((s) => s.fields.some((f) => f.key === campo))) {
      return tab.id
    }
  }
  return null
}

export function tiendaVacia(): Record<string, unknown> {
  return {
    activo: true,
    esCentral: false,
    facturaLaCentral: false,
    comunicaCentral: false,
    swIva: false,
    recargo: false,
    sumarDescuento: true,
    desglosarBasesTicketIvaInc: false,
  }
}
