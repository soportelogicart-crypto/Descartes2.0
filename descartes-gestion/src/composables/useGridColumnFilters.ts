export type FilterOperador =
  | 'sin_filtro'
  | 'contiene'
  | 'no_contiene'
  | 'comienza'
  | 'finaliza'
  | 'igual'
  | 'no_igual'
  | 'mayor'
  | 'menor'
  | 'mayor_igual'
  | 'menor_igual'
  | 'entre'
  | 'no_entre'
  | 'vacio'
  | 'no_vacio'
  | 'nulo'
  | 'no_nulo'

export type ColumnFilter = {
  operador: FilterOperador
  valor: string
  valor2: string
}

export const FILTRO_OPERADORES: { value: FilterOperador; label: string }[] = [
  { value: 'sin_filtro', label: 'Sin Filtro' },
  { value: 'contiene', label: 'Contiene' },
  { value: 'no_contiene', label: 'No Contiene' },
  { value: 'comienza', label: 'Comienza con' },
  { value: 'finaliza', label: 'Finaliza con' },
  { value: 'igual', label: 'Igual a' },
  { value: 'no_igual', label: 'No es igual a' },
  { value: 'mayor', label: 'Es mayor a' },
  { value: 'menor', label: 'Es menor que' },
  { value: 'mayor_igual', label: 'Es mayor o igual a' },
  { value: 'menor_igual', label: 'Es menor o igual a' },
  { value: 'entre', label: 'Entre' },
  { value: 'no_entre', label: 'No Entre' },
  { value: 'vacio', label: 'Esta vacio' },
  { value: 'no_vacio', label: 'No esta vacio' },
  { value: 'nulo', label: 'Es nulo' },
  { value: 'no_nulo', label: 'No es nulo' },
]

export function filtroVacio(): ColumnFilter {
  return { operador: 'sin_filtro', valor: '', valor2: '' }
}

export function filtrosIniciales(keys: string[]): Record<string, ColumnFilter> {
  const map: Record<string, ColumnFilter> = {}
  for (const key of keys) {
    map[key] = filtroVacio()
  }
  return map
}

function celdaTexto(value: unknown): string {
  if (value == null) return ''
  return String(value).trim()
}

/** Normaliza a YYYY-MM-DD para comparar solo la fecha (ignora hora). */
export function normalizarFechaSolo(value: unknown): string {
  if (value == null || value === '') return ''
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    const y = value.getFullYear()
    const m = String(value.getMonth() + 1).padStart(2, '0')
    const d = String(value.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
  }
  const s = String(value).trim()
  if (!s) return ''
  // 2026-07-21 / 2026-07-21T... / 2026-07-21 12:00:00
  const iso = s.match(/^(\d{4}-\d{2}-\d{2})/)
  if (iso) return iso[1]
  // 21/07/2026 or 21-07-2026
  const dmy = s.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/)
  if (dmy) {
    return `${dmy[3]}-${dmy[2].padStart(2, '0')}-${dmy[1].padStart(2, '0')}`
  }
  const ts = Date.parse(s)
  if (!Number.isNaN(ts)) {
    const dt = new Date(ts)
    const y = dt.getFullYear()
    const m = String(dt.getMonth() + 1).padStart(2, '0')
    const d = String(dt.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
  }
  return ''
}

function compararOrden(a: string, b: string): number {
  const na = Number(a)
  const nb = Number(b)
  if (a !== '' && b !== '' && !Number.isNaN(na) && !Number.isNaN(nb)) {
    return na === nb ? 0 : na < nb ? -1 : 1
  }
  return a.localeCompare(b, undefined, { sensitivity: 'base', numeric: true })
}

function compararFechas(a: string, b: string): number {
  if (a === b) return 0
  if (a === '') return -1
  if (b === '') return 1
  return a < b ? -1 : 1
}

export function operadorNecesitaValor(op: FilterOperador): boolean {
  return !['sin_filtro', 'vacio', 'no_vacio', 'nulo', 'no_nulo'].includes(op)
}

export function operadorNecesitaValor2(op: FilterOperador): boolean {
  return op === 'entre' || op === 'no_entre'
}

export function cumpleFiltroColumna(
  value: unknown,
  filtro: ColumnFilter,
  opciones?: { date?: boolean; extraTexto?: string }
): boolean {
  const op = filtro.operador
  if (op === 'sin_filtro') return true

  const esFecha = opciones?.date === true
  const texto = esFecha ? normalizarFechaSolo(value) : celdaTexto(value)
  const extra = esFecha ? '' : celdaTexto(opciones?.extraTexto)
  const esNulo = value == null
  const esVacio = !esNulo && texto === ''
  const criterio = esFecha ? normalizarFechaSolo(filtro.valor) : filtro.valor.trim()
  const criterio2 = esFecha ? normalizarFechaSolo(filtro.valor2) : filtro.valor2.trim()
  const textoLower = texto.toLowerCase()
  const extraLower = extra.toLowerCase()
  const criterioLower = criterio.toLowerCase()
  const cmp = esFecha ? compararFechas : compararOrden

  function matchTexto(opMatch: 'contiene' | 'no_contiene' | 'comienza' | 'finaliza' | 'igual' | 'no_igual'): boolean {
    if (criterio === '' && opMatch !== 'igual' && opMatch !== 'no_igual') return true
    const haystack = extraLower ? `${textoLower} ${extraLower}` : textoLower
    switch (opMatch) {
      case 'contiene':
        return haystack.includes(criterioLower)
      case 'no_contiene':
        return !haystack.includes(criterioLower)
      case 'comienza':
        return textoLower.startsWith(criterioLower) || extraLower.startsWith(criterioLower)
      case 'finaliza':
        return textoLower.endsWith(criterioLower) || extraLower.endsWith(criterioLower)
      case 'igual':
        return (
          cmp(esFecha ? texto : textoLower, esFecha ? criterio : criterioLower) === 0 ||
          (!esFecha && extraLower !== '' && extraLower === criterioLower)
        )
      case 'no_igual':
        return (
          cmp(esFecha ? texto : textoLower, esFecha ? criterio : criterioLower) !== 0 &&
          (esFecha || extraLower === '' || extraLower !== criterioLower)
        )
    }
  }

  switch (op) {
    case 'nulo':
      return esNulo
    case 'no_nulo':
      return !esNulo
    case 'vacio':
      return esVacio || esNulo
    case 'no_vacio':
      return !esNulo && !esVacio
    case 'contiene':
      return matchTexto('contiene')
    case 'no_contiene':
      return matchTexto('no_contiene')
    case 'comienza':
      return matchTexto('comienza')
    case 'finaliza':
      return matchTexto('finaliza')
    case 'igual':
      return matchTexto('igual')
    case 'no_igual':
      return matchTexto('no_igual')
    case 'mayor':
      return criterio === '' ? true : cmp(texto, criterio) > 0
    case 'menor':
      return criterio === '' ? true : cmp(texto, criterio) < 0
    case 'mayor_igual':
      return criterio === '' ? true : cmp(texto, criterio) >= 0
    case 'menor_igual':
      return criterio === '' ? true : cmp(texto, criterio) <= 0
    case 'entre': {
      if (criterio === '' && criterio2 === '') return true
      const ge = criterio === '' || cmp(texto, criterio) >= 0
      const le = criterio2 === '' || cmp(texto, criterio2) <= 0
      return ge && le
    }
    case 'no_entre': {
      if (criterio === '' && criterio2 === '') return true
      const ge = criterio === '' || cmp(texto, criterio) >= 0
      const le = criterio2 === '' || cmp(texto, criterio2) <= 0
      return !(ge && le)
    }
    default:
      return true
  }
}

export function aplicarFiltrosColumnas<T extends Record<string, unknown>>(
  filas: T[],
  filtros: Record<string, ColumnFilter>,
  opciones?: {
    dateKeys?: string[]
    /** Texto adicional por columna (p.ej. descripcion de un codigo FK). */
    extraTexto?: Partial<Record<string, (fila: T) => string>>
  }
): T[] {
  const dateSet = new Set(opciones?.dateKeys ?? [])
  const activos = Object.entries(filtros).filter(([, f]) => f.operador !== 'sin_filtro')
  if (activos.length === 0) return filas

  return filas.filter((fila) =>
    activos.every(([key, filtro]) =>
      cumpleFiltroColumna(fila[key], filtro, {
        date: dateSet.has(key),
        extraTexto: opciones?.extraTexto?.[key]?.(fila) ?? '',
      })
    )
  )
}

export type OrdenColumna = { key: string; dir: 'asc' | 'desc' }

export function alternarOrdenColumna(actual: OrdenColumna | null, key: string): OrdenColumna {
  if (actual?.key === key) {
    return { key, dir: actual.dir === 'asc' ? 'desc' : 'asc' }
  }
  return { key, dir: 'asc' }
}

export function aplicarOrdenColumnas<T extends object>(
  filas: T[],
  orden: OrdenColumna | null | undefined,
  opciones?: {
    dateKeys?: string[]
    getValue?: (fila: T, key: string) => unknown
    nuevoAlFinal?: boolean
  }
): T[] {
  if (!orden) return filas
  const dateSet = new Set(opciones?.dateKeys ?? [])
  const get =
    opciones?.getValue ??
    ((fila: T, key: string) => (fila as Record<string, unknown>)[key])
  const nuevoAlFinal = opciones?.nuevoAlFinal === true
  const datos = nuevoAlFinal
    ? filas.filter((f) => !(f as { _nuevo?: boolean })._nuevo)
    : filas.slice()
  const nuevas = nuevoAlFinal
    ? filas.filter((f) => (f as { _nuevo?: boolean })._nuevo)
    : []
  const signo = orden.dir === 'asc' ? 1 : -1
  const esFecha = dateSet.has(orden.key)
  datos.sort((a, b) => {
    const va = get(a, orden.key)
    const vb = get(b, orden.key)
    if (esFecha) {
      return signo * compararFechas(normalizarFechaSolo(va), normalizarFechaSolo(vb))
    }
    return signo * compararOrden(celdaTexto(va), celdaTexto(vb))
  })
  return nuevas.length ? [...datos, ...nuevas] : datos
}
