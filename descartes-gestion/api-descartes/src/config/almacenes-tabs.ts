export type AlmacenFieldType = 'text' | 'number' | 'checkbox'

export type AlmacenField = {
  key: string
  label: string
  type?: AlmacenFieldType
  required?: boolean
  readOnly?: boolean
  span?: 1 | 2 | 3 | 4
  layout?: 'inline' | 'checkbox'
  maxLength?: number
  step?: string
}

export type AlmacenSection = {
  title: string
  columns?: 2 | 3 | 4
  fields: AlmacenField[]
}

export type AlmacenTab = {
  id: string
  label: string
  sections: AlmacenSection[]
}

function inline(key: string, label: string, opts: Partial<AlmacenField> = {}): AlmacenField {
  return { key, label, layout: 'inline', type: 'text', ...opts }
}

export const almacenTabs: AlmacenTab[] = [
  {
    id: 'generales',
    label: 'Datos Generales',
    sections: [
      {
        title: 'Identificacion',
        columns: 4,
        fields: [
          inline('codigo', 'Codigo', { type: 'number', required: true, step: '1' }),
          inline('descripcion', 'Descripcion', { required: true, span: 3, maxLength: 40 }),
        ],
      },
      {
        title: 'Opciones',
        columns: 4,
        fields: [
          { key: 'externo', label: 'Externo', type: 'checkbox', layout: 'checkbox' },
          { key: 'reservaDirecta', label: 'Reserva directa', type: 'checkbox', layout: 'checkbox' },
          { key: 'central', label: 'Central', type: 'checkbox', layout: 'checkbox' },
          { key: 'traspasoAutomatico', label: 'Traspaso automatico', type: 'checkbox', layout: 'checkbox' },
          { key: 'consolidaStockWeb', label: 'Consolida stock web', type: 'checkbox', layout: 'checkbox' },
          { key: 'gastos', label: 'Gastos', type: 'checkbox', layout: 'checkbox' },
          { key: 'activo', label: 'Activo', type: 'checkbox', layout: 'checkbox' },
        ],
      },
      {
        title: 'Contabilidad',
        columns: 4,
        fields: [inline('centroCoste', 'Centro de coste', { maxLength: 10, span: 2 })],
      },
    ],
  },
]

export function almacenFichaVacia(): Record<string, unknown> {
  return {
    codigo: null,
    descripcion: '',
    externo: false,
    reservaDirecta: false,
    central: false,
    traspasoAutomatico: false,
    consolidaStockWeb: false,
    centroCoste: '',
    gastos: false,
    activo: true,
  }
}

export const ALMACEN_CAMPOS_OBLIGATORIOS: { key: string; label: string }[] = [
  { key: 'codigo', label: 'Codigo' },
  { key: 'descripcion', label: 'Descripcion' },
]

export function camposAlmacenObligatoriosVacios(ficha: Record<string, unknown>): string[] {
  const vacios: string[] = []
  if (ficha.codigo == null || ficha.codigo === '' || Number(ficha.codigo) <= 0) {
    vacios.push('codigo')
  }
  if (!String(ficha.descripcion ?? '').trim()) {
    vacios.push('descripcion')
  }
  return vacios
}

export function validarAlmacenFicha(ficha: Record<string, unknown>): string | null {
  const vacios = camposAlmacenObligatoriosVacios(ficha)
  if (vacios.length === 0) return null
  const label = ALMACEN_CAMPOS_OBLIGATORIOS.find((c) => c.key === vacios[0])?.label ?? vacios[0]
  return `El campo "${label}" es obligatorio.`
}
