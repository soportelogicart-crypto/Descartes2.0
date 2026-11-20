/** Paleta cerrada. Los ids coinciden con la API (Usuarios_Ges.ColorTema). */
export const TEMA_DEFECTO = 'azul'

export const TEMAS = [
  { id: 'azul', muestra: '#00309c' },
  { id: 'verde', muestra: '#0f6b45' },
  { id: 'granate', muestra: '#8f1d3a' },
  { id: 'violeta', muestra: '#5b21b6' },
  { id: 'ambar', muestra: '#b45309' },
  { id: 'grafito', muestra: '#3f3f46' },
] as const

export type TemaId = (typeof TEMAS)[number]['id']

export function esTemaId(valor: string): valor is TemaId {
  return TEMAS.some((tema) => tema.id === valor)
}

/** Aplica el tema al documento. Un id desconocido vuelve al azul. */
export function aplicarTema(valor: string | null | undefined): TemaId {
  const id = valor && esTemaId(valor) ? valor : TEMA_DEFECTO
  document.documentElement.dataset.tema = id
  return id
}
