import { createI18n } from 'vue-i18n'
import { ca } from './ca'
import { en } from './en'
import { es } from './es'

export type IdiomaUi = 'ca' | 'es' | 'en'

export const IDIOMAS: { id: IdiomaUi; etiqueta: string }[] = [
  { id: 'ca', etiqueta: 'Català' },
  { id: 'es', etiqueta: 'Castellano' },
  { id: 'en', etiqueta: 'English' },
]

export const i18n = createI18n({
  legacy: false,
  locale: 'es',
  fallbackLocale: 'es',
  messages: { ca, es, en },
})
