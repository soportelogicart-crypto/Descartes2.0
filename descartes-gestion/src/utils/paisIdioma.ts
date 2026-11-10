/** 0 español, 1 francés, 2 inglés. El país propone el idioma y se puede cambiar. */
export type CodigoIdioma = 0 | 1 | 2
export type IdiomaDocumento = 'es' | 'fr' | 'en'

export function idiomaDocumento(codigo: number | null | undefined): IdiomaDocumento {
  if (Number(codigo) === 1) return 'fr'
  if (Number(codigo) === 2) return 'en'
  return 'es'
}

export function codigoIdioma(idioma: string | null | undefined): CodigoIdioma {
  const t = String(idioma ?? '').trim().toLowerCase()
  if (t === 'fr' || t === '1') return 1
  if (t === 'en' || t === '2') return 2
  return 0
}

/** Solo cuando el formato del código postal no se confunde con otro país. */
export function paisDesdeCodigoPostal(cp: string): { pais: string; idioma: CodigoIdioma } | null {
  const t = String(cp ?? '').trim().toUpperCase()
  if (/^\d{4}-\d{3}$/.test(t)) return { pais: 'Portugal', idioma: 0 }
  if (/^\d{4}\s?[A-Z]{2}$/.test(t)) return { pais: 'Países Bajos', idioma: 2 }
  if (/^[A-Z]{1,2}\d[A-Z\d]?\s*\d[A-Z]{2}$/.test(t)) return { pais: 'Reino Unido', idioma: 2 }
  if (/^[AC-Y]\d{2}\s?[A-Z0-9]{4}$/.test(t)) return { pais: 'Irlanda', idioma: 2 }
  return null
}

export function idiomaDePais(pais: string): CodigoIdioma {
  const p = String(pais ?? '')
    .trim()
    .toUpperCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
  if (['FR', 'FRANCIA', 'FRANCE', 'BE', 'BELGICA', 'BELGIQUE', 'LU', 'LUXEMBURGO', 'LUXEMBOURG'].includes(p)) {
    return 1
  }
  if (
    [
      'GB', 'UK', 'REINO UNIDO', 'UNITED KINGDOM', 'IE', 'IRLANDA', 'IRELAND',
      'US', 'USA', 'ESTADOS UNIDOS', 'UNITED STATES',
      'NL', 'PAISES BAJOS', 'NETHERLANDS', 'HOLANDA',
    ].includes(p)
  ) {
    return 2
  }
  return 0
}
