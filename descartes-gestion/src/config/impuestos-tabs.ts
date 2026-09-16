export type ImpuestoFieldType = 'text' | 'number' | 'checkbox'

export type ImpuestoField = {
  key: string
  label: string
  type?: ImpuestoFieldType
  required?: boolean
  readOnly?: boolean
  span?: 1 | 2 | 3 | 4
  layout?: 'inline' | 'checkbox'
  maxLength?: number
  step?: string
  lookup?: boolean
  optionsSource?: 'cuentas-ultimo-nivel'
}

export type ImpuestoSection = {
  title: string
  columns?: 2 | 3 | 4
  fields: ImpuestoField[]
}

export type ImpuestoTab = {
  id: string
  label: string
  sections: ImpuestoSection[]
}

function inline(
  key: string,
  label: string,
  opts: Partial<ImpuestoField> = {}
): ImpuestoField {
  return { key, label, layout: 'inline', type: 'text', ...opts }
}

export const impuestoTabs: ImpuestoTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('codigo', 'Codigo', { required: true, maxLength: 2 }),
          inline('descripcion', 'Descripcion', { required: true, span: 3, maxLength: 40 }),
        ],
      },
      {
        title: 'Porcentajes',
        columns: 4,
        fields: [
          inline('porcentajeIVA', '% IVA', { type: 'number', required: true, step: '0.01' }),
          inline('porcentajeRec', '% Rec', { type: 'number', step: '0.001' }),
        ],
      },
      {
        title: 'Cuentas contables',
        columns: 4,
        fields: [
          inline('cuentaCtb', 'Cuenta Ctb.', {
            maxLength: 10,
            lookup: true,
            optionsSource: 'cuentas-ultimo-nivel',
          }),
          inline('cuentaCtbSoportadoIntra', 'Intr. Soport.', {
            maxLength: 10,
            lookup: true,
            optionsSource: 'cuentas-ultimo-nivel',
          }),
          inline('cuentaCtbRepercutidoIntra', 'Intr. Reper.', {
            maxLength: 10,
            lookup: true,
            optionsSource: 'cuentas-ultimo-nivel',
          }),
          inline('idWeb', 'Id WEB', { type: 'number', step: '1' }),
        ],
      },
      {
        title: 'Opciones',
        columns: 4,
        fields: [
          {
            key: 'regimenEspecialAGYP',
            label: 'R.E. A.G Y P.',
            type: 'checkbox',
            layout: 'checkbox',
          },
          { key: 'ivaExento', label: 'IVA EX.', type: 'checkbox', layout: 'checkbox' },
          { key: 'activo', label: 'Activo', type: 'checkbox', layout: 'checkbox' },
        ],
      },
    ],
  },
]

export function impuestoVacio(): Record<string, unknown> {
  return {
    codigo: '',
    descripcion: '',
    porcentajeIVA: 0,
    porcentajeRec: 0,
    cuentaCtb: 0,
    cuentaCtbSoportadoIntra: 0,
    cuentaCtbRepercutidoIntra: 0,
    idWeb: 0,
    regimenEspecialAGYP: false,
    ivaExento: false,
    activo: true,
  }
}

export const IMPUESTO_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
  { key: 'porcentajeIVA', label: '% IVA' },
]

export function camposImpuestoObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (!String(ficha.codigo ?? '').trim()) vacios.push('codigo')
  if (!String(ficha.descripcion ?? '').trim()) vacios.push('descripcion')
  if (ficha.porcentajeIVA === null || ficha.porcentajeIVA === undefined || ficha.porcentajeIVA === '') {
    vacios.push('porcentajeIVA')
  }
  return vacios
}

export function validarImpuestoObligatorios(ficha: Record<string, unknown>): string | null {
  const key = camposImpuestoObligatoriosVacios(ficha)[0]
  if (!key) return null
  const label = IMPUESTO_CAMPOS_OBLIGATORIOS.find((c) => c.key === key)?.label ?? key
  return `El campo "${label}" es obligatorio.`
}
