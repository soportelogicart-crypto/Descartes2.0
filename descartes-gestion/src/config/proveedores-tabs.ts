export type ProveedorFieldType = 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'email'

export type ProveedorFieldLayout = 'inline' | 'checkbox' | 'textarea'

export type ProveedorField = {
  key: string
  label: string
  type?: ProveedorFieldType
  layout?: ProveedorFieldLayout
  span?: 1 | 2 | 3 | 4
  readOnly?: boolean
  required?: boolean
  maxLength?: number
  step?: string
  options?: { value: string; label: string }[]
  optionsSource?: 'formas-pago'
}

export type ProveedorSection = {
  title: string
  columns?: 2 | 3 | 4
  fields: ProveedorField[]
  row?: string
}

export type ProveedorTab = {
  id: string
  label: string
  sections: ProveedorSection[]
}

function cb(key: string, label: string, extra: Partial<ProveedorField> = {}): ProveedorField {
  return { key, label, type: 'checkbox', layout: 'checkbox', ...extra }
}

function inline(key: string, label: string, extra: Partial<ProveedorField> = {}): ProveedorField {
  return { key, label, layout: 'inline', ...extra }
}

function area(key: string, label: string, extra: Partial<ProveedorField> = {}): ProveedorField {
  return { key, label, type: 'textarea', layout: 'textarea', span: 4, ...extra }
}

export const proveedorTabs: ProveedorTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('codigo', 'Codigo', { required: true, maxLength: 6 }),
          inline('nombre', 'Razon social', { span: 3, required: true, maxLength: 50 }),
          inline('nif', 'N.I.F.', { maxLength: 16 }),
          inline('swift', 'Swift', { maxLength: 20 }),
          inline('iban', 'IBAN', { span: 2, maxLength: 34 }),
        ],
      },
      {
        title: 'Relaciones',
        columns: 4,
        fields: [
          inline('personaContacto', 'Contacto', { span: 4, maxLength: 50 }),
          inline('codigoCliente', 'Su Cliente', { span: 2, maxLength: 20 }),
          inline('codigoCentral', 'Central', { span: 2, maxLength: 15 }),
        ],
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
          inline('pais', 'Pais', { maxLength: 30 }),
        ],
      },
      {
        title: 'Direccion almacen',
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
        fields: [
          inline('telefono1', 'Telefono 1', { maxLength: 15 }),
          inline('telefono2', 'Telefono 2', { maxLength: 15 }),
          inline('email', 'E-Mail', { type: 'email', span: 2, maxLength: 50 }),
          inline('emailComercial', 'E-mail. Com.', { type: 'email', span: 2, maxLength: 200 }),
          inline('emailFacturacion', 'E-mail. Fac.', { type: 'email', span: 2, maxLength: 200 }),
        ],
      },
    ],
  },
  {
    id: 'parametros',
    label: 'Parametros',
    sections: [
      {
        title: 'Fiscal',
        columns: 4,
        fields: [
          inline('tratamientoFiscal', 'Tratamiento fiscal', {
            type: 'select',
            options: [
              { value: 'N', label: 'NACIONAL' },
              { value: 'I', label: 'INTRACOMUNITARIO' },
              { value: 'E', label: 'EXTRANJERO' },
              { value: 'S', label: 'SUJETO PASIVO' },
            ],
          }),
          inline('codigoTransaccionSII', 'Cod.Transaccion SII', { maxLength: 2 }),
          cb('swIva', 'Aplicar IVA'),
          cb('swRec', 'Recargo'),
          cb('ivaAgrario', 'Imp.Agrario'),
          cb('pedidosAutomaticos', 'Ped.Automaticos'),
          cb('pedidosWeb', 'Envio a WEB'),
          cb('asociado', 'Asociado'),
          inline('claveFirma', 'Clave Firma', { maxLength: 20 }),
        ],
      },
      {
        title: 'Pago y descuentos',
        columns: 4,
        fields: [
          inline('formaPago', 'Forma Pago', {
            type: 'select',
            optionsSource: 'formas-pago',
            span: 2,
            required: true,
          }),
          inline('diaPago1', 'Dias Pago', { type: 'number' }),
          inline('diaPago2', 'Dias Pago 2', { type: 'number' }),
        ],
      },
      {
        title: 'Descuentos',
        columns: 4,
        fields: [
          inline('dto', 'Descuento', { type: 'number', step: '0.01' }),
          inline('dto2', 'Dto 2', { type: 'number', step: '0.01' }),
          inline('dto3', 'Dto 3', { type: 'number', step: '0.01' }),
        ],
      },
      {
        title: 'Otros importes',
        columns: 4,
        fields: [
          inline('acumIva', 'Acumulado Iva', { type: 'number', step: '0.01' }),
          inline('minSinPortes', 'Minimo Sin Port.', { type: 'number', step: '0.01' }),
          inline('coeficienteTransporte', 'Coef. Transporte', { type: 'number', step: '0.0001' }),
        ],
      },
      {
        title: 'Contabilidad y logistica',
        columns: 4,
        fields: [
          inline('cuentaCtb', 'Cta.Ctb', { type: 'number', step: '1' }),
          inline('cuentaBanco', 'Banco', { type: 'number', step: '1' }),
          inline('aecoc', 'A.E.C.O.C', { type: 'number', step: '1' }),
          inline('pasaporteFitosanitario', 'Pas.Fitosanitario', { span: 2, maxLength: 20 }),
          cb('activo', 'Activo'),
        ],
      },
    ],
  },
  {
    id: 'comentarios',
    label: 'Comentarios',
    sections: [
      {
        title: 'Comentarios',
        columns: 2,
        fields: [area('notas', 'Comentarios', { span: 2 })],
      },
    ],
  },
]

export function proveedorVacio(): Record<string, unknown> {
  return {
    codigo: '',
    nombre: '',
    codigoCliente: '',
    nif: '',
    personaContacto: '',
    direccion: '',
    poblacion: '',
    codigoPostal: '',
    provincia: '',
    pais: '',
    telefono1: '',
    telefono2: '',
    dto: 0,
    dto2: 0,
    dto3: 0,
    swIva: true,
    swRec: false,
    ivaAgrario: false,
    acumIva: 0,
    minSinPortes: 0,
    notas: '',
    cuentaCtb: 0,
    formaPago: '',
    email: '',
    emailComercial: '',
    emailFacturacion: '',
    pedidosAutomaticos: false,
    aecoc: 0,
    codigoCentral: '',
    swift: '',
    iban: '',
    tratamientoFiscal: 'N',
    diaPago1: 0,
    diaPago2: 0,
    coeficienteTransporte: 0,
    pasaporteFitosanitario: '',
    interesesComerciales: '',
    direccionEnvio: '',
    poblacionEnvio: '',
    codigoPostalEnvio: '',
    provinciaEnvio: '',
    paisEnvio: '',
    codigoTransaccionSII: '',
    pedidosWeb: false,
    cuentaBanco: 0,
    asociado: false,
    claveFirma: '',
    activo: true,
  }
}

export function validarProveedorObligatorios(ficha: Record<string, unknown>): string | null {
  if (!String(ficha.codigo ?? '').trim()) return 'El codigo es obligatorio'
  if (!String(ficha.nombre ?? '').trim()) return 'La razon social es obligatoria'
  if (!String(ficha.formaPago ?? '').trim()) return 'Debe asignar una forma de pago al proveedor'
  return null
}

export function parseInteresesComerciales(value: unknown): string[] {
  const raw = String(value ?? '').trim()
  if (!raw) return []
  return raw
    .split(/[,;|]+/)
    .map((s) => s.trim())
    .filter(Boolean)
}

export function serializeInteresesComerciales(codigos: string[]): string {
  return codigos.map((c) => c.trim()).filter(Boolean).join(',')
}
