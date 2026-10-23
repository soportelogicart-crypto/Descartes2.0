import { ibanValido } from '@/utils/iban'

export type ProveedorFieldType = 'text' | 'number' | 'checkbox' | 'textarea' | 'select' | 'email'

export type ProveedorFieldLayout = 'inline' | 'checkbox' | 'textarea' | 'dias-pago'

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
  inputWidth?: string
  options?: { value: string; label: string }[]
  optionsSource?: 'formas-pago' | 'cuentas' | 'cuentas-banco'

  lookup?: boolean
}

export type ProveedorSection = {
  title: string
  columns?: 1 | 2 | 3 | 4
  fields: ProveedorField[]
  /** Empareja secciones consecutivas (p.ej. direcciones). */
  pair?: string
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
  return { key, label, type: 'textarea', layout: 'textarea', span: 2, ...extra }
}

export const proveedorTabs: ProveedorTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 3,
        fields: [
          inline('codigo', 'Codigo', { required: true, maxLength: 6, inputWidth: '5rem' }),
          inline('nombre', 'Razon social', {
            span: 2,
            required: true,
            maxLength: 50,
            inputWidth: '100%',
          }),
          inline('nif', 'N.I.F.', { maxLength: 16, inputWidth: '9rem' }),
          inline('swift', 'Swift', { maxLength: 20, inputWidth: '10rem' }),
          inline('iban', 'IBAN', { span: 2, maxLength: 34, inputWidth: '100%' }),
        ],
      },
      {
        title: 'Contacto',
        columns: 2,
        fields: [
          inline('personaContacto', 'Contacto', { span: 2, maxLength: 50, inputWidth: '100%' }),
          inline('codigoCliente', 'Su Cliente', { maxLength: 20, inputWidth: '12rem' }),
          inline('codigoCentral', 'Central', { maxLength: 15, inputWidth: '10rem' }),
        ],
      },
      {
        title: 'Direccion Fiscal',
        columns: 2,
        pair: 'direcciones',
        fields: [
          inline('direccion', 'Direccion', { span: 2, maxLength: 50, inputWidth: '100%' }),
          inline('codigoPostal', 'C.P.', { maxLength: 8, inputWidth: '4.5rem' }),
          inline('poblacion', 'Poblacion', { maxLength: 50, inputWidth: '100%' }),
          inline('provincia', 'Provincia', { span: 2, maxLength: 50, inputWidth: '100%' }),
          inline('pais', 'Pais', { span: 2, maxLength: 30, inputWidth: '100%' }),
        ],
      },
      {
        title: 'Direccion Almacen',
        columns: 2,
        pair: 'direcciones',
        fields: [
          inline('direccionEnvio', 'Direccion', { span: 2, maxLength: 50, inputWidth: '100%' }),
          inline('codigoPostalEnvio', 'C.P.', { maxLength: 8, inputWidth: '4.5rem' }),
          inline('poblacionEnvio', 'Poblacion', { maxLength: 50, inputWidth: '100%' }),
          inline('provinciaEnvio', 'Provincia', { span: 2, maxLength: 50, inputWidth: '100%' }),
          inline('paisEnvio', 'Pais', { span: 2, maxLength: 50, inputWidth: '100%' }),
        ],
      },
      {
        title: 'Comunicacion',
        columns: 3,
        fields: [
          inline('telefono1', 'Telefono 1', { maxLength: 15, inputWidth: '8rem' }),
          inline('telefono2', 'Telefono 2', { maxLength: 15, inputWidth: '8rem' }),
          inline('fax', 'Fax', { maxLength: 15, inputWidth: '8rem' }),
          inline('email', 'E-Mail', { type: 'email', span: 3, maxLength: 50, inputWidth: '100%' }),
          inline('emailComercial', 'E-mail. Com.', {
            type: 'email',
            span: 3,
            maxLength: 200,
            inputWidth: '100%',
          }),
          inline('emailFacturacion', 'E-mail. Fac.', {
            type: 'email',
            span: 3,
            maxLength: 200,
            inputWidth: '100%',
          }),
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
        columns: 3,
        fields: [
          inline('tratamientoFiscal', 'Tratamiento Fiscal', {
            type: 'select',
            span: 2,
            options: [
              { value: 'N', label: 'NACIONAL' },
              { value: 'I', label: 'INTRACOMUNITARIO' },
              { value: 'E', label: 'EXTRANJERO' },
              { value: 'S', label: 'SUJETO PASIVO' },
            ],
            inputWidth: '100%',
            required: true,
          }),
          inline('codigoTransaccionSII', 'Cod. SII', { maxLength: 2, inputWidth: '3rem' }),
          cb('swIva', 'Aplicar IVA'),
          cb('swRec', 'Recargo'),
          cb('ivaAgrario', 'Imp. Agrario'),
        ],
      },
      {
        title: 'Pedidos',
        columns: 3,
        fields: [
          cb('pedidosAutomaticos', 'Ped. Automaticos'),
          cb('asociado', 'Asociado'),
          inline('claveFirma', 'Clave Firma', { maxLength: 20, inputWidth: '9rem' }),
          cb('pedidosWeb', 'Envio a WEB'),
        ],
      },
      {
        // 3 columnas: Forma Pago (2) + Dias Pago en la primera fila y los tres
        // descuentos alineados en la segunda.
        title: 'Pago y descuentos',
        columns: 3,
        fields: [
          inline('formaPago', 'Forma Pago', {
            span: 2,
            maxLength: 3,
            optionsSource: 'formas-pago',
            required: true,
            lookup: true,
            inputWidth: '3.4rem',
          }),
          inline('diaPago1', 'Dias Pago', {
            type: 'number',
            layout: 'dias-pago',
            inputWidth: '5rem',
          }),
          inline('dto', 'Descuento', { type: 'number', step: '0.01', inputWidth: '5rem' }),
          inline('dto2', 'Dto 2', { type: 'number', step: '0.01', inputWidth: '5rem' }),
          inline('dto3', 'Dto 3', { type: 'number', step: '0.01', inputWidth: '5rem' }),
        ],
      },
      {
        title: 'Importes y contabilidad',
        columns: 3,
        fields: [
          inline('acumIva', 'Acumulado Iva', {
            type: 'number',
            step: '0.01',
            inputWidth: '7rem',
            readOnly: true,
          }),
          inline('minSinPortes', 'Minimo Sin Port.', { type: 'number', step: '0.01', inputWidth: '7rem' }),
          inline('coeficienteTransporte', 'Coef. Transporte', {
            type: 'number',
            step: '0.0001',
            inputWidth: '10rem',
          }),
          inline('cuentaCtb', 'Cta. Ctb', {
            span: 2,
            maxLength: 10,
            lookup: true,
            optionsSource: 'cuentas',
            inputWidth: '7rem',
          }),
          inline('cuentaBanco', 'Banco', {
            span: 2,
            type: 'text',
            maxLength: 10,
            lookup: true,
            optionsSource: 'cuentas-banco',
            inputWidth: '7rem',
          }),
          inline('aecoc', 'A.E.C.O.C', { type: 'number', step: '1', inputWidth: '10rem' }),
          inline('pasaporteFitosanitario', 'Pas. Fitosanitario', {
            span: 2,
            maxLength: 20,
            inputWidth: '14rem',
          }),
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
        columns: 1,
        fields: [area('notas', 'Comentarios', { span: 1, inputWidth: '100%' })],
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
    fax: '',
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
    codigoTransaccionSII: '1',
    pedidosWeb: false,
    cuentaBanco: '',
    asociado: false,
    claveFirma: '',
    activo: true,
  }
}

export function validarProveedorObligatorios(ficha: Record<string, unknown>): {
  mensaje: string
  campo: string
  tab: string
} | null {
  if (!String(ficha.codigo ?? '').trim()) {
    return { mensaje: 'El codigo es obligatorio', campo: 'codigo', tab: 'generales' }
  }
  if (!String(ficha.nombre ?? '').trim()) {
    return { mensaje: 'La razon social es obligatoria', campo: 'nombre', tab: 'generales' }
  }
  if (!String(ficha.formaPago ?? '').trim()) {
    return {
      mensaje: 'Debe asignar una forma de pago al proveedor',
      campo: 'formaPago',
      tab: 'parametros',
    }
  }
  return null
}

export type ProveedorValidacionUso = {
  campo: string
  tab: string
  titulo: string
  mensaje: string
}

/** Vacío permitido; si hay valor, formato ISO + MOD-97 (mismo criterio que Clientes). */
export function validarUsoProveedor(ficha: Record<string, unknown>): ProveedorValidacionUso | null {
  if (!ibanValido(ficha.iban)) {
    return {
      campo: 'iban',
      tab: 'generales',
      titulo: 'IBAN incorrecto',
      mensaje: 'El IBAN no es válido. Revise el país, los dígitos de control y la longitud.',
    }
  }
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
