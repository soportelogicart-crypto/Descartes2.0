import { aplicarTema, esTemaId, TEMA_DEFECTO, type TemaId } from '@/config/temas'
import { i18n, type IdiomaUi } from '@/i18n'

export type PreferenciasUi = {
  idioma?: string | null
  colorTema?: string | null
  ayudasMenu?: boolean | number | null
}

export function normalizarIdioma(valor: string | null | undefined): IdiomaUi {
  return valor === 'ca' || valor === 'en' || valor === 'es' ? valor : 'es'
}

export function normalizarTema(valor: string | null | undefined): TemaId {
  return valor && esTemaId(valor) ? valor : TEMA_DEFECTO
}

export function normalizarAyudas(valor: boolean | number | null | undefined): boolean {
  if (valor === false || valor === 0) return false
  return true
}

/** Idioma y color de la sesión, en pantalla al momento. */
export function aplicarPreferencias(prefs: PreferenciasUi | null | undefined): void {
  const idioma = normalizarIdioma(prefs?.idioma)
  const tema = normalizarTema(prefs?.colorTema)
  i18n.global.locale.value = idioma
  aplicarTema(tema)
}
