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
          inline('cuentaCtb', 'Cuenta Ctb.', { type: 'number', step: '1' }),
          inline('cuentaCtbSoportadoIntra', 'Intr. Soport.', { type: 'number', step: '1' }),
          inline('cuentaCtbRepercutidoIntra', 'Intr. Reper.', { type: 'number', step: '1' }),
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

export function validarImpuestoObligatorios(ficha: Record<string, unknown>): string | null {
  if (!String(ficha.codigo ?? '').trim()) return 'El codigo es obligatorio'
  if (!String(ficha.descripcion ?? '').trim()) return 'La descripcion es obligatoria'
  if (ficha.porcentajeIVA === null || ficha.porcentajeIVA === undefined || ficha.porcentajeIVA === '') {
    return 'El % IVA es obligatorio'
  }
  return null
}
