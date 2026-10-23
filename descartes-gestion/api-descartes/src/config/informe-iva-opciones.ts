/** Combos del informe de IVA (legacy frmListado, sin idioma). */

export type InformeIvaEstado =
  | 'todos'
  | 'contabilizados'
  | 'no_contabilizados'
  | 'menos'
  | 'mas'
  | 'no_enviadas_lrod'

export type InformeIvaFormato = 'normal' | 'extendido' | 'cliente' | 'extendido_ctb'

export const INFORME_IVA_ESTADOS: { value: InformeIvaEstado; label: string }[] = [
  { value: 'todos', label: 'Todos' },
  { value: 'contabilizados', label: 'Contabilizados' },
  { value: 'no_contabilizados', label: 'No contabilizados' },
  { value: 'menos', label: '-' },
  { value: 'mas', label: '+' },
  { value: 'no_enviadas_lrod', label: 'No enviadas LROD' },
]

export const INFORME_IVA_FORMATOS: { value: InformeIvaFormato; label: string }[] = [
  { value: 'normal', label: 'Normal' },
  { value: 'extendido', label: 'Extendido' },
  { value: 'cliente', label: 'Cliente' },
  { value: 'extendido_ctb', label: 'Extendido ctb.' },
]
