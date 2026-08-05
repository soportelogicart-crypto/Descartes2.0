export type ClienteFieldType = 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'date' | 'email'

export type ClienteFieldLayout = 'inline' | 'checkbox' | 'textarea'

export type ClienteField = {
  key: string
  label: string
  type?: ClienteFieldType
  layout?: ClienteFieldLayout
  span?: 1 | 2 | 3 | 4
  readOnly?: boolean
  required?: boolean
  maxLength?: number
  options?: { value: string; label: string }[]
  optionsSource?: 'tiendas' | 'actividades' | 'formas-pago' | 'almacenes' | 'trabajadores'
}

export type ClienteSection = {
  title: string
  columns?: 2 | 3 | 4 | 5
  fields: ClienteField[]
  /** Secciones consecutivas con el mismo `row` se muestran en paralelo. */
  row?: string
}

export type ClienteTab = {
  id: string
  label: string
  sections: ClienteSection[]
}

function cb(key: string, label: string, extra: Partial<ClienteField> = {}): ClienteField {
  return { key, label, type: 'checkbox', layout: 'checkbox', ...extra }
}

function inline(key: string, label: string, extra: Partial<ClienteField> = {}): ClienteField {
  return { key, label, layout: 'inline', ...extra }
}

function area(key: string, label: string, extra: Partial<ClienteField> = {}): ClienteField {
  return { key, label, type: 'textarea', layout: 'textarea', span: 4, ...extra }
}

export const clienteTabs: ClienteTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('codigo', 'Codigo', { required: true, maxLength: 9 }),
          inline('nombre', 'Razon social', { span: 3, required: true, maxLength: 50 }),
          inline('razonSocial2', 'Razon social 2', { span: 4, maxLength: 60 }),
          inline('nif', 'N.I.F.', { maxLength: 16 }),
          inline('swift', 'Swift', { maxLength: 20 }),
          inline('iban', 'IBAN', { span: 2, maxLength: 34 }),
          inline('referenciaMandato', 'Referencia mandato', { maxLength: 35 }),
          inline('fechaFirmaMandato', 'Fecha aceptacion', { type: 'date' }),
          inline('tiendaCodigo', 'Tienda', { type: 'select', optionsSource: 'tiendas' }),
        ],
      },
      {
        title: 'Contacto',
        columns: 4,
        fields: [inline('personaContacto', 'Contacto', { span: 4, maxLength: 50 })],
      },
      {
        title: 'Direccion fiscal',
        columns: 2,
        row: 'direcciones',
        fields: [
          inline('direccion', 'Direccion', { span: 2, maxLength: 50 }),
          inline('codigoPostal', 'C.P.', { maxLength: 8 }),
          inline('poblacion', 'Poblacion', { maxLength: 50 }),
          inline('provincia', 'Provincia', { maxLength: 50 }),
          inline('pais', 'Pais', { maxLength: 50 }),
        ],
      },
      {
        title: 'Direccion envio',
        columns: 2,
        row: 'direcciones',
        fields: [
          inline('direccionEnvio', 'Direccion', { span: 2, maxLength: 50 }),
          inline('codigoPostalEnvio', 'C.P.', { maxLength: 8 }),
          inline('poblacionEnvio', 'Poblacion', { maxLength: 50 }),
          inline('provinciaEnvio', 'Provincia', { maxLength: 50 }),
          inline('paisEnvio', 'Pais', { maxLength: 50 }),
        ],
      },
      {
        title: 'Comunicacion',
        columns: 2,
        row: 'comobs',
        fields: [
          inline('telefono1', 'Telefono', { maxLength: 15 }),
          inline('telefono2', 'Movil', { maxLength: 15 }),
          inline('fax', 'Fax', { maxLength: 15 }),
          inline('email', 'E-Mail', { type: 'email', span: 2, maxLength: 200 }),
          inline('emailComercial', 'E-mail. Com.', { type: 'email', span: 2, maxLength: 200 }),
          inline('emailFacturacion', 'E-mail. Fac.', { type: 'email', span: 2, maxLength: 200 }),
        ],
      },
      {
        title: 'Observaciones',
        columns: 2,
        row: 'comobs',
        fields: [
          area('observaciones', 'Observacion', { span: 2 }),
          area('observacionesInternas', 'Obs. internas', { span: 2 }),
        ],
      },
    ],
  },
  {
    id: 'marketing',
    label: 'Marketing',
    sections: [
      {
        title: 'Agentes y actividad',
        columns: 2,
        row: 'mkt1',
        fields: [
          inline('agenteOrigen', 'Agente', { type: 'select', optionsSource: 'trabajadores' }),
          inline('vendedor', 'Representante', { type: 'select', optionsSource: 'trabajadores' }),
          inline('actividad', 'Actividad', { type: 'select', optionsSource: 'actividades', span: 2 }),
        ],
      },
      {
        title: 'Logistica',
        columns: 2,
        row: 'mkt1',
        fields: [
          inline('tarifaTrans', 'Tarifa Trans.', { type: 'number' }),
          inline('portes', 'Portes', {
            type: 'select',
            options: [
              { value: '', label: '--' },
              { value: 'D', label: 'Debidos' },
              { value: 'P', label: 'Pagados' },
              { value: 'F', label: 'Franco' },
            ],
          }),
          inline('transportista', 'Transportista', { span: 2, maxLength: 25 }),
          inline('ruta', 'Ruta', { type: 'number' }),
        ],
      },
      {
        title: 'Datos personales',
        columns: 2,
        row: 'mkt2',
        fields: [
          inline('sexo', 'Sexo', {
            type: 'select',
            options: [
              { value: '', label: '--' },
              { value: 'H', label: 'Hombre' },
              { value: 'M', label: 'Mujer' },
            ],
          }),
          inline('fechaNacimiento', 'Fecha nacimiento', { type: 'date' }),
          inline('fechaAlta', 'Fecha alta', { type: 'date' }),
          inline('ultimaCompra', 'Ultima compra', { type: 'date', readOnly: true }),
          cb('activo', 'Activo'),
          cb('profesional', 'Profesional'),
          inline('clasificacionComercial', 'Clas. comercial', { maxLength: 6 }),
          inline('tipologia', 'Tipologia', { maxLength: 6 }),
          inline('carnetManipulador', 'N C. Manipulador', { maxLength: 20 }),
          inline('fechaCaducidadCarnet', 'Caducidad', { type: 'date' }),
        ],
      },
      {
        title: 'Fidelizacion',
        columns: 2,
        row: 'mkt2',
        fields: [
          inline('tarjetaFidelizacion', 'Tarjeta fidelizacion', { span: 2, maxLength: 20 }),
          inline('pjeFidelizacion', '% Fidelizacion', { type: 'number' }),
          inline('acumuladoFidelizacion', 'Acum. fidelizacion', { type: 'number', readOnly: true }),
          inline('acumuladoPuntos', 'Acum. puntos', { type: 'number', readOnly: true }),
        ],
      },
      {
        title: 'Tarifas y descuentos',
        columns: 2,
        row: 'mkt3',
        fields: [
          inline('tarifa', 'Tarifa', { type: 'number' }),
          inline('tipoDescuento', 'Tipo descuento', { maxLength: 6 }),
          inline('tipoDescuentoFidelizacion', 'Tipo Dto. fidelizacion', { maxLength: 6 }),
          inline('dto1', 'Descuento cabecera', { type: 'number' }),
          inline('dto2', 'Descuento linea', { type: 'number' }),
        ],
      },
      {
        title: 'Comunicaciones',
        columns: 2,
        row: 'mkt3',
        fields: [
          cb('propaganda', 'Env. propaganda'),
          cb('facturasEmail', 'Env. facturas por Email'),
          cb('certificarFacturas', 'Certificar'),
          cb('copiaImpresa', 'Copia impresa'),
          cb('facturaE', 'Factura-e'),
          inline('oficinaContable', 'Oficina contable', { maxLength: 10 }),
          inline('organoGestor', 'Organo gestor', { maxLength: 10 }),
          inline('unidadTramitadora', 'Unidad tramitadora', { maxLength: 10 }),
          inline('organoProponente', 'Organo proponente', { maxLength: 10 }),
        ],
      },
    ],
  },
  {
    id: 'facturacion',
    label: 'Facturacion',
    sections: [
      {
        title: 'Datos bancarios y pago',
        columns: 2,
        row: 'fac1',
        fields: [
          inline('cuentaCtb', 'Cuenta contable', { maxLength: 15 }),
          inline('agencia', 'Agencia bancaria', { maxLength: 40 }),
          inline('cuentaBancaria', 'Cuenta bancaria', { span: 2, maxLength: 20 }),
          inline('formaPago', 'Forma pago', {
            type: 'select',
            optionsSource: 'formas-pago',
            required: true,
          }),
          inline('tratamientoFiscal', 'Tratamiento', {
            type: 'select',
            options: [
              { value: 'N', label: 'NACIONAL' },
              { value: 'I', label: 'INTRACOMUNITARIO' },
              { value: 'E', label: 'EXTRANJERO' },
              // Legacy Mid("NIEP"): Canarias = P (no C)
              { value: 'P', label: 'CANARIAS' },
            ],
          }),
          inline('diaPago1', 'Dia pago 1', { type: 'number' }),
          inline('diaPago2', 'Dia pago 2', { type: 'number' }),
        ],
      },
      {
        title: 'Riesgo y acumulados',
        columns: 2,
        row: 'fac1',
        fields: [
          inline('acumIva', 'Acumulado IVA', { type: 'number', readOnly: true }),
          inline('saldo', 'Saldo', { type: 'number', readOnly: true }),
          inline('limiteCredito', 'Riesgo concedido', { type: 'number' }),
          inline('riesgoActualAdonix', 'Riesgo comercial', { type: 'number', readOnly: true }),
          cb('transporteDomicilio', 'Entrega en domicilio'),
          inline('pjeTransporte', '% Inc. Transporte', { type: 'number' }),
          inline('pjeIVATransporte', '% Impuesto', { type: 'number' }),
        ],
      },
      {
        title: 'Opciones facturacion',
        columns: 4,
        fields: [
          cb('swIva', 'Aplicar IVA'),
          inline('codigoTransaccionSII', 'Cod. Transaccion SII', { maxLength: 2 }),
          cb('swRec', 'Recargo'),
          cb('facturacionDesglosada', 'Desg. Fact.'),
          cb('retIrpf', 'Aplicar % Ret. IRPF'),
          cb('preFacturacion', 'Generar Pre-Factura'),
          cb('asegurado', 'Asegurado'),
          cb('cobroContraSaldo', 'Contra saldo'),
          cb('proponerFactura', 'Proponer Factura'),
          cb('agrupacionAgencia', 'Agrupar Mensajes'),
          inline('copiasFactura', 'Copias Factura', { type: 'number' }),
          inline('copiasFContado', 'Copias Contado', { type: 'number' }),
          cb('generarTraspaso', 'Generar Traspaso en Pedidos Cliente', { span: 2 }),
          inline('almacenTraspaso', 'Al Almacen', { type: 'select', optionsSource: 'almacenes' }),
          cb('solicitarImpresionAlbaran', 'Solicitar Impresion Albaran', { span: 2 }),
          cb('bloqueoVenta', 'Bloquear Facturacion Automatica', { span: 2 }),
          inline('empresaFacturacion', 'Codigo Facturacion', { maxLength: 9 }),
        ],
      },
    ],
  },
]

export function clienteVacio(): Record<string, unknown> {
  return {
    codigo: '',
    nombre: '',
    razonSocial2: '',
    nif: '',
    tiendaCodigo: '',
    activo: true,
    swIva: true,
    swRec: false,
    facturacionDesglosada: false,
    propaganda: false,
    generarTraspaso: false,
    retIrpf: false,
    bloqueoVenta: false,
    solicitarImpresionAlbaran: false,
    preFacturacion: false,
    cobroContraSaldo: false,
    facturasEmail: false,
    facturacionManual: false,
    proponerFactura: false,
    copiaImpresa: false,
    certificarFacturas: false,
    transporteDomicilio: false,
    agrupacionAgencia: false,
    facturaE: false,
    asegurado: false,
    profesional: false,
    tratamientoFiscal: 'N',
    formaPago: '',
    pais: 'Espana',
    paisEnvio: 'Espana',
    portes: '',
    interesesComerciales: '',
    dto1: 0,
    dto2: 0,
    diaPago1: 0,
    diaPago2: 0,
    tarifa: 0,
    ruta: 0,
    pjeFidelizacion: 0,
    copiasFactura: 0,
    copiasFContado: 0,
  }
}

export function validarClienteObligatorios(ficha: Record<string, unknown>): string | null {
  if (!String(ficha.codigo ?? '').trim()) return 'El codigo es obligatorio'
  if (!String(ficha.nombre ?? '').trim()) return 'La razon social es obligatoria'
  if (!String(ficha.formaPago ?? '').trim()) return 'Debe asignar una forma de pago al cliente'
  return null
}

/** Parsea InteresesComerciales (codigos de 2 chars concatenados o separados por coma). */
export function parseInteresesComerciales(value: unknown): string[] {
  const raw = String(value ?? '').trim()
  if (!raw) return []
  if (raw.includes(',') || raw.includes(';') || raw.includes(' ')) {
    return raw
      .split(/[,;\s]+/)
      .map((c) => c.trim())
      .filter(Boolean)
  }
  const codes: string[] = []
  for (let i = 0; i < raw.length; i += 2) {
    const code = raw.slice(i, i + 2).trim()
    if (code) codes.push(code)
  }
  return codes
}

export function serializeInteresesComerciales(codigos: string[]): string {
  return codigos
    .map((c) => c.trim())
    .filter(Boolean)
    .join('')
}

/** Normaliza fechas ISO/SQL a yyyy-MM-dd para inputs date. */
export function fechaParaInput(value: unknown): string {
  if (value == null || value === '') return ''
  const s = String(value)
  if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10)
  const m = s.match(/^(\d{2})\/(\d{2})\/(\d{2,4})/)
  if (m) {
    const year = m[3].length === 2 ? `20${m[3]}` : m[3]
    return `${year}-${m[2]}-${m[1]}`
  }
  return ''
}
