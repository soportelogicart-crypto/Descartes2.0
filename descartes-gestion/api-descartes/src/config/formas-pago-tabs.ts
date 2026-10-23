export type FormaPagoFieldType = 'text' | 'number' | 'checkbox' | 'select'

export type FormaPagoFieldOption = { value: string | number; label: string }

export type FormaPagoField = {
  key: string
  label: string
  type?: FormaPagoFieldType
  required?: boolean
  readOnly?: boolean
  span?: 1 | 2 | 3 | 4
  layout?: 'inline' | 'checkbox'
  maxLength?: number
  step?: string
  options?: FormaPagoFieldOption[]
  lookup?: boolean
  optionsSource?: 'cuentas-ultimo-nivel'
}

export type FormaPagoSection = {
  title: string
  columns?: 2 | 3 | 4
  fields: FormaPagoField[]
  /** Secciones con el mismo row se muestran en paralelo. */
  row?: string
}

export type FormaPagoTab = {
  id: string
  label: string
  sections: FormaPagoSection[]
}

function inline(
  key: string,
  label: string,
  opts: Partial<FormaPagoField> = {}
): FormaPagoField {
  return { key, label, layout: 'inline', type: 'text', ...opts }
}

function check(key: string, label: string): FormaPagoField {
  return { key, label, type: 'checkbox', layout: 'checkbox' }
}

/** Agrupacion es smallint en FormasPago (no tabla Agrupaciones de articulos). */
export const AGRUPACION_OPTIONS: FormaPagoFieldOption[] = [
  { value: 0, label: '--' },
  { value: 1, label: 'EFECTIVO' },
  { value: 2, label: 'TARJETA' },
  { value: 3, label: 'CHEQUE' },
  { value: 4, label: 'TRANSFERENCIA' },
  { value: 5, label: 'VALE' },
  { value: 6, label: 'OTROS' },
]

/** Tipo documento cobro: FormasPago.Tipo (nchar/nvarchar 1). No hay tabla catalogo. */
export const TIPO_OPTIONS: FormaPagoFieldOption[] = [
  { value: '', label: '--' },
  { value: 'R', label: 'Recibo' },
  { value: 'L', label: 'Letra' },
  { value: 'A', label: 'Letra aceptada' },
  { value: 'T', label: 'Talon' },
  { value: 'X', label: 'Transferencia' },
  { value: 'P', label: 'Pagare' },
  { value: 'V', label: 'Varios' },
]

export const COBRO_PAGO_OPTIONS: FormaPagoFieldOption[] = [
  { value: 'C', label: 'COBRO' },
  { value: 'P', label: 'PAGO' },
  { value: 'A', label: 'AMBOS' },
]

export const formaPagoTabs: FormaPagoTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Parametros',
        columns: 2,
        row: 'main',
        fields: [
          inline('abreviacion', 'Abreviacion', { maxLength: 3 }),
          inline('cambio', 'Cambio', { type: 'number', step: 'any' }),
          inline('decimales', 'N. de Decimales', { type: 'number', step: '1' }),
          inline('numVtos', 'N. Vencimientos', { type: 'number', step: '1' }),
          inline('dias1erVto', 'Dias Primer Vto.', { type: 'number', step: '1' }),
          inline('diasEntreVtos', 'Dias Entre Vtos', { type: 'number', step: '1' }),
          inline('copiasTicket', 'Copias Ticket', { type: 'number', step: '1' }),
          inline('orden', 'Orden', { type: 'number', step: '1' }),
        ],
      },
      {
        title: 'Opciones',
        columns: 2,
        row: 'main',
        fields: [
          check('cobroDeArqueo', 'Cobro de Arqueo'),
          check('abrirCajon', 'Abrir Cajon'),
          check('vales', 'Vales'),
          check('chipAcumuladoMenu', 'Chip/Acumulado Menu'),
          check('tarjetaMonedero', 'Tarjeta Monedero'),
          check('datafono', 'Datafono'),
          check('emv', 'EMV'),
          check('cobroEnTienda', 'Cobro en Tienda'),
          check('cajonElectronico', 'Cajon electronico'),
          check('facturacionDirecta', 'Facturacion directa'),
          check('controlPagare', 'Control Pagare'),
          check('fPagoActivaConPermiso', 'Forma Pago Activa Con Permiso'),
        ],
      },
      {
        title: 'Clasificacion',
        columns: 4,
        fields: [
          inline('tipo', 'Tipo', {
            type: 'select',
            options: TIPO_OPTIONS,
          }),
          inline('agrupacion', 'Agrupacion', {
            type: 'select',
            options: AGRUPACION_OPTIONS,
          }),
          inline('cobroPago', 'Cobro o Pago', {
            type: 'select',
            options: COBRO_PAGO_OPTIONS,
          }),
          inline('cuentaCtb', 'Cuenta Contable', {
            lookup: true,
            optionsSource: 'cuentas-ultimo-nivel',
            maxLength: 10,
          }),
          inline('nota', 'Nota', { span: 4, maxLength: 100 }),
          check('activo', 'Activo'),
        ],
      },
    ],
  },
  {
    id: 'factura-e',
    label: 'Factura-e',
    sections: [
      {
        title: 'Factura electronica',
        columns: 2,
        fields: [
          inline('facteFpCodigo', 'Forma Pago', { type: 'number', step: '1' }),
          check('facteTransferencia', 'Transferencia'),
          check('facteReciboDom', 'Recibo'),
          inline('facteIban', 'IBAN', { span: 2, maxLength: 34 }),
          inline('facteBanco', 'Banco', { maxLength: 10 }),
          inline('facteSucursal', 'Sucursal', { maxLength: 10 }),
          inline('facteBanDir', 'Direccion', { span: 2, maxLength: 80 }),
          inline('facteBanCodPos', 'C.P.', { maxLength: 5 }),
          inline('facteBanPob', 'Poblacion', { maxLength: 50 }),
          inline('facteBanPrv', 'Provincia', { maxLength: 20 }),
          inline('facteBanPai', 'Pais', { maxLength: 5 }),
        ],
      },
    ],
  },
]

export function formaPagoVacia(): Record<string, unknown> {
  return {
    codigo: '',
    descripcion: '',
    abreviacion: '',
    cambio: 1,
    decimales: 2,
    numVtos: 0,
    dias1erVto: 0,
    diasEntreVtos: 0,
    copiasTicket: 0,
    orden: 0,
    cobroDeArqueo: false,
    abrirCajon: false,
    vales: false,
    chipAcumuladoMenu: false,
    tarjetaMonedero: false,
    datafono: false,
    emv: false,
    cobroEnTienda: false,
    cajonElectronico: false,
    facturacionDirecta: false,
    controlPagare: false,
    fPagoActivaConPermiso: false,
    tipo: '',
    agrupacion: 0,
    cobroPago: 'A',
    cuentaCtb: 0,
    nota: '',
    facteFpCodigo: 0,
    facteTransferencia: false,
    facteReciboDom: false,
    facteIban: '',
    facteBanco: '',
    facteSucursal: '',
    facteBanDir: '',
    facteBanCodPos: '',
    facteBanPob: '',
    facteBanPrv: '',
    facteBanPai: '',
    activo: true,
  }
}

export const FORMA_PAGO_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposFormaPagoObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  return vacios
}

export function validarFormaPagoObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposFormaPagoObligatoriosVacios(ficha)[0]
  if (!key) {
    if (String(ficha.codigo ?? '').trim().length > 2) return 'El codigo tiene maximo 2 caracteres'
    return null
  }
  const label = FORMA_PAGO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}

export function payloadFormaPago(ficha: Record<string, unknown>): Record<string, unknown> {
  const out = { ...ficha }
  if (out.agrupacion === '' || out.agrupacion === null || out.agrupacion === undefined) {
    out.agrupacion = 0
  } else {
    out.agrupacion = Number(out.agrupacion)
  }
  if (out.cobroPago != null) {
    out.cobroPago = String(out.cobroPago).trim().toUpperCase().slice(0, 1)
  }
  if (out.tipo != null) {
    out.tipo = String(out.tipo).trim().toUpperCase().slice(0, 1)
  }
  // Legacy guarda cadenas vacias (no NULL) en varios campos de texto.
  for (const key of [
    'abreviacion',
    'tipo',
    'nota',
    'facteIban',
    'facteBanco',
    'facteSucursal',
    'facteBanDir',
    'facteBanCodPos',
    'facteBanPob',
    'facteBanPrv',
    'facteBanPai',
  ] as const) {
    if (out[key] == null) out[key] = ''
    else out[key] = String(out[key]).trim()
  }
  if (out.facteFpCodigo === '' || out.facteFpCodigo == null) {
    out.facteFpCodigo = 0
  }
  return out
}
