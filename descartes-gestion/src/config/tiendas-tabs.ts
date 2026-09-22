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
  maxLength?: number
  lookup?: boolean
  optionsSource?: 'almacenes' | 'impuestos' | 'formas-pago' | 'tipos-calculo-fidelizacion'
}

export type TiendaSection = {
  title: string
  columns?: 2 | 3 | 4 | 5
  /** Layout especial: atributos (Atri|Col compacto) o copias (filas densas) */
  variant?: 'default' | 'atributos' | 'copias'
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
          inline('almacenCodigo', 'Almacen', {
            lookup: true,
            optionsSource: 'almacenes',
            span: 2,
            required: true,
            maxLength: 3,
          }),
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
          inline('literalTicket', 'Literales ticket (nº líneas del puesto)', {
            type: 'number',
            span: 2,
          }),
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
          inline('codIvaSuperReducido', 'Iva super reducido', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
          inline('codIvaReducido', 'Iva reducido', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
          inline('codIvaNormal', 'Iva normal', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
          inline('codIvaIncrementado', 'Iva incrementado', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
          inline('codIvaEsp5', 'Iva esp. 5', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
          inline('codIvaEsp6', 'Iva esp. 6', {
            lookup: true,
            optionsSource: 'impuestos',
            maxLength: 2,
          }),
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
          inline('divisa', 'Divisa', {
            lookup: true,
            optionsSource: 'formas-pago',
            span: 2,
            required: true,
            maxLength: 2,
          }),
          inline('divisaAlt', 'Divisa alternativa', {
            lookup: true,
            optionsSource: 'formas-pago',
            span: 2,
            required: true,
            maxLength: 2,
          }),
          inline('dto', 'Descuento', { type: 'number' }),
          cb('sumarDescuento', 'Dto acumulativo'),
          inline('tipoCalculoFidelizacion', 'Tipo calculo fidelizacion', {
            lookup: true,
            optionsSource: 'tipos-calculo-fidelizacion',
            span: 2,
            maxLength: 20,
          }),
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
          inline('prefijo', 'Prefijo tienda', { type: 'text', maxLength: 3, inputWidth: '4rem' }),
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
          cb('esCentral', 'Esta tienda actua de central'),
          cb('facturaLaCentral', 'Factura la central'),
          cb('comunicaCentral', 'Comunica la central'),
          inline('unidadComunicacion', 'Unidad de comunicacion', { span: 2 }),
        ],
      },
      {
        title: 'Copias',
        variant: 'copias',
        columns: 2,
        fields: [
          inline('copiasAlbaranReparto', 'Copias albaran reserva', { type: 'number' }),
          inline('copiasAlbaran', 'Copias albaran', { type: 'number' }),
          inline('copiasFactura', 'Copias factura', { type: 'number' }),
          inline('copiasFacturaReparto', 'Copias factura reparto', { type: 'number' }),
          inline('copiasSimulacion', 'Copias presupuesto', { type: 'number' }),
        ],
      },
      {
        title: 'Compras y listados',
        columns: 4,
        fields: [
          cb('checkProveedor', 'Compr. en pedido proveedor'),
          cb('impAlternativo', 'Im.codigo alternativo pedido'),
          cb('cambiaProveedor', 'Cambiar ult. proveedor albaranes'),
          cb('maximizarListado', 'Maximizar listados'),
        ],
      },
      {
        title: 'Stock y venta',
        columns: 4,
        fields: [
          cb('almacenesInternos', 'Mostrar stock almacenes int.'),
          cb('conexionOnline', 'Actualizacion stock online'),
          cb('avisoStockCero', 'Aviso stock negativo/min.'),
          cb('dtoSiCambioPrecio', 'Dto si cambio de precio'),
          cb('impTicketInc', 'Impresion ticket IVA incluido'),
          cb('desglosarBasesTicketIvaInc', 'Desglosar bases ticket IVA inc.', { required: true }),
          cb('imprimirCodigoArticulo', 'Imprimir cod. articulo ticket'),
          cb('obligarDineroEntregado', 'Oblig.entrar dinero entregado'),
          cb('impSaldoTicket', 'Impresion saldo ticket'),
          cb('crearReciboCredito', 'Generar recibo tickets credito'),
        ],
      },
      {
        title: 'Generacion codigos',
        columns: 4,
        fields: [
          cb('genBarras', 'Generar codigo de barras'),
          inline('aecoc', 'A.E.C.O.C.', { type: 'text', maxLength: 4, inputWidth: '5rem' }),
          cb('genDesdeCodigo', '+ Codigo'),
          cb('genClientes', 'Generar clientes'),
          cb('genArticulos', 'Generar articulos'),
          cb('genProveedores', 'Generar proveedores'),
          inline('articuloMantenimiento', 'Articulo mantenimiento', { span: 2 }),
        ],
      },
      {
        title: 'Etiquetas y venta',
        columns: 4,
        fields: [
          cb('impEtiquetasSinEans', 'Imp. etiquetas sin EAN'),
          cb('etiquetasIvaIncluido', 'Imp. etiq. albaranes IVA incluido'),
          cb('impEtiquetasSoloEansPropios', 'Imp. solo etiquetas EANs propios'),
          inline('decimalesPrecio', 'N. decimales etiq. precio', { type: 'number' }),
          cb('solicitarPerfilParam', 'Solicitar perfil venta'),
          cb('desglosarEscandallo', 'Desglosar escandallo ticket'),
          inline('recalculoTarifas', 'Calculo tarifas manual', { span: 2 }),
          inline('literalInvitacion', 'Literal invitacion', { type: 'number' }),
        ],
      },
      {
        title: 'Vales y tarifas escalado',
        columns: 4,
        fields: [
          cb('controlVales', 'Control de vales'),
          inline('minimoCambioVales', 'Min. para dar cambio en vales', { type: 'number' }),
          inline('importeObligatorioFactura', 'Obligacion factura import. sup.', { type: 'number', span: 2 }),
          cb('tarifa1Escalado', '1'),
          cb('tarifa2Escalado', '2'),
          cb('tarifa3Escalado', '3'),
          cb('tarifa4Escalado', '4'),
          cb('tarifa5Escalado', '5'),
          cb('tarifa6Escalado', '6'),
          cb('tarifa7Escalado', '7'),
          cb('tarifa8Escalado', '8'),
          cb('tarifa9Escalado', '9'),
        ],
      },
      {
        title: 'Atributos / Col grid',
        variant: 'atributos',
        columns: 2,
        fields: [
          ...Array.from({ length: 10 }, (_, i) => inline(`atri${i}`, `${i}`)),
          ...Array.from({ length: 10 }, (_, i) =>
            inline(`colAtri${i}`, `${i}`, { type: 'number' })
          ),
          cb('appWeb', 'App Web'),
        ],
      },
    ],
  },
  {
    id: 'parametros2',
    label: 'Parametros II',
    sections: [
      {
        title: 'Textos y rutas',
        columns: 4,
        fields: [
          area('litPiePedidoCompra', 'Literal pie pedido compra'),
          inline('pathExcelEstadisticas', 'Ruta excels estadisticas', { span: 4 }),
        ],
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

export type ContadorField = {
  key: string
  label: string
  /** Visible pero no editable en modal (p. ej. serie gestionada solo por dominio). */
  readOnly?: boolean
}

export const contadoresFieldsIzq: ContadorField[] = [
  { key: 'ultPedidoCom', label: 'Pedidos' },
  { key: 'ultAlbaranCom', label: 'Albaran Compra' },
  { key: 'ultAlbaranDevCom', label: 'Albaran Dev. Compra', readOnly: true },
  { key: 'ultAlbaranVen', label: 'Albaran Ventas' },
  { key: 'ultAlbaranTra', label: 'Traspasos' },
  { key: 'ultTicket', label: 'Tickets' },
  { key: 'ultFactura', label: 'Facturas' },
  { key: 'ultFacturaDiferida', label: 'Facturas Dif.' },
  { key: 'ultPedidoCli', label: 'Pedidos Clientes' },
  { key: 'ultEnvio', label: 'Envio' },
  { key: 'ultEan', label: 'Codigos Barras' },
  { key: 'ultCliente', label: 'Clientes' },
  { key: 'ultAbono', label: 'Fact. Rectificativas' },
  { key: 'ultAbonoDiferido', label: 'Fact. Rectificativas Dif.' },
]

export const contadoresFieldsDer: ContadorField[] = [
  { key: 'ultFicheroRecepcion', label: 'Com. Central' },
  { key: 'ultFicheroRecepcionC', label: 'Com. Tienda' },
  { key: 'ultProveedor', label: 'Proveedores' },
  { key: 'ultPreFactura', label: 'Pre-Facturas' },
  { key: 'ultOrdenFabricacion', label: 'Orden de Fabricacion' },
  { key: 'ultTransacCajon', label: 'Ultima Transaccion Cajon' },
]

export const contadoresSections: TiendaSection[] = [
  {
    title: 'Contadores',
    columns: 2,
    fields: [...contadoresFieldsIzq, ...contadoresFieldsDer].map((f) => ({
      key: f.key,
      label: f.label,
      type: 'number' as const,
      layout: 'inline' as const,
      readOnly: f.readOnly,
    })),
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
        if (!field.required) continue
        if (campos.includes(field.key)) continue
        if (field.type === 'checkbox') {
          if (!ficha[field.key]) {
            campos.push(field.key)
            mensajes.push(`Debe marcar "${field.label}"`)
          }
          continue
        }
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
  if (campo === 'desglosarBasesTicketIvaInc') return 'parametros'
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
    sumarDescuento: false,
    impTicketInc: false,
    desglosarBasesTicketIvaInc: false,
    impAlternativo: false,
    appWeb: false,
    // Defaults alineados con alta legacy (0 / '' / MA; evita NULL en BD)
    prefijo: 0,
    valesEmitidos: 0,
    valesRecibidos: 0,
    decimalesPrecio: 0,
    aecoc: 0,
    copiasAlbaranReparto: 0,
    copiasAlbaran: 0,
    copiasFactura: 0,
    copiasFacturaReparto: 0,
    copiasSimulacion: 0,
    impEtiquetasSinEans: 0,
    impEtiquetasSoloEansPropios: 0,
    minimoFidelizacion: 0,
    tipoCalculoFidelizacion: '',
    minimoCambioVales: 0,
    importeObligatorioFactura: 0,
    literalInvitacion: 0,
    recalculoTarifas: 'MA',
    fax: '',
    modem: '',
    codigoExterno: '',
    unidadComunicacion: '',
    centroCoste: '',
    articuloMantenimiento: '',
    pathExcelEstadisticas: '',
    serieFacturas: '',
    serieAbonos: '',
    literalFacturaDiferida: '',
    literalFacturaContado: '',
    literalPresupuesto: '',
    literalVale: '',
    atri0: '',
    atri1: '',
    atri2: '',
    atri3: '',
    atri4: '',
    atri5: '',
    atri6: '',
    atri7: '',
    atri8: '',
    atri9: '',
    colAtri0: 0,
    colAtri1: 0,
    colAtri2: 0,
    colAtri3: 0,
    colAtri4: 0,
    colAtri5: 0,
    colAtri6: 0,
    colAtri7: 0,
    colAtri8: 0,
    colAtri9: 0,
  }
}
