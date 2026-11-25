import type {
  ImportacionColumna,
  ImportacionDefinicion,
  ImportacionEntidad,
  ImportacionFila,
  ImportacionHoja,
  ImportacionMensaje,
} from '@/api/importacion'

/** Filas con formato texto ya preparadas en la plantilla (los códigos conservan los ceros). */
const FILAS_PREFORMATEADAS = 500

const COLOR_OBLIGATORIA = '#FDE68A'
const COLOR_OPCIONAL = '#E2E8F0'

/** Quita tildes, asterisco y mayúsculas para comparar cabeceras ("Razón social *" = "razon social"). */
export function normalizarCabecera(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[*]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase()
}

/** Igual que en la API: sin espacios, sin ceros a la izquierda si es numérico, sin mayúsculas. */
export function normalizarCodigo(codigo: string): string {
  const c = codigo.trim()
  if (c !== '' && /^\d+$/.test(c)) {
    const sin = c.replace(/^0+/, '')
    return sin === '' ? '0' : sin
  }
  return c.toLowerCase()
}

function textoCelda(valor: unknown): string {
  if (valor === null || valor === undefined) return ''
  if (valor instanceof Date) {
    const y = valor.getFullYear()
    const m = String(valor.getMonth() + 1).padStart(2, '0')
    const d = String(valor.getDate()).padStart(2, '0')
    return `${y}-${m}-${d}`
  }
  return String(valor).trim()
}

function cabeceraConMarca(col: ImportacionColumna): string {
  return col.obligatoria ? `${col.cabecera} *` : col.cabecera
}

function anchoColumna(col: ImportacionColumna): number {
  const base = Math.max(col.cabecera.length + 4, Math.min(col.max ?? 18, 40))
  return Math.max(12, Math.min(base, 45))
}

/** Libro .xlsx vacío: hoja "Instrucciones" y una hoja por entidad con las cabeceras. */
export async function generarPlantillaXlsx(def: ImportacionDefinicion): Promise<Blob> {
  const { default: writeExcelFile } = await import('write-excel-file/universal')

  const negrita = { fontWeight: 'bold' } as const

  const instrucciones: unknown[][] = [
    [{ value: 'Importación de datos — Descartes', ...negrita }],
    [],
    ...def.instrucciones.map((t) => [t]),
    [],
    [{ value: 'Columnas de cada hoja', ...negrita }],
    [
      { value: 'Hoja', ...negrita, backgroundColor: COLOR_OPCIONAL },
      { value: 'Columna', ...negrita, backgroundColor: COLOR_OPCIONAL },
      { value: 'Obligatoria', ...negrita, backgroundColor: COLOR_OPCIONAL },
      { value: 'Ejemplo', ...negrita, backgroundColor: COLOR_OPCIONAL },
      { value: 'Ayuda', ...negrita, backgroundColor: COLOR_OPCIONAL },
    ],
  ]
  for (const hoja of def.hojas) {
    for (const col of hoja.columnas) {
      instrucciones.push([
        hoja.hoja,
        col.cabecera,
        col.obligatoria ? 'Sí' : col.clave === hoja.claveCodigo && hoja.codigoAutomatico ? 'Si no hay numeración automática' : 'No',
        { value: col.ejemplo, type: String, format: '@' },
        col.ayuda,
      ])
    }
  }

  const hojas: unknown[] = [
    {
      sheet: 'Instrucciones',
      data: instrucciones,
      columns: [{ width: 16 }, { width: 24 }, { width: 22 }, { width: 28 }, { width: 70 }],
    },
  ]

  for (const hoja of def.hojas) {
    const cabecera = hoja.columnas.map((col) => ({
      value: cabeceraConMarca(col),
      fontWeight: 'bold',
      backgroundColor: col.obligatoria ? COLOR_OBLIGATORIA : COLOR_OPCIONAL,
    }))
    const vacia = hoja.columnas.map(() => ({ value: '', type: String, format: '@' }))
    const data: unknown[][] = [cabecera]
    for (let i = 0; i < FILAS_PREFORMATEADAS; i++) data.push(vacia)
    hojas.push({
      sheet: hoja.hoja,
      data,
      columns: hoja.columnas.map((c) => ({ width: anchoColumna(c) })),
      stickyRowsCount: 1,
    })
  }

  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  return (writeExcelFile as any)(hojas).toBlob() as Promise<Blob>
}

export interface HojaLeida {
  entidad: ImportacionEntidad
  hoja: string
  filas: ImportacionFila[]
}

export interface LecturaLibro {
  hojas: HojaLeida[]
  /** Problemas del archivo en sí (hoja o columna obligatoria que falta, cabeceras desconocidas). */
  avisos: string[]
}

/** Lee el libro .xlsx y lo traduce a filas por entidad (claves de campo de la API). */
export async function leerLibroXlsx(archivo: File, def: ImportacionDefinicion): Promise<LecturaLibro> {
  const { default: readExcelFile } = await import('read-excel-file/universal')
  const buffer = await archivo.arrayBuffer()
  const libro = (await readExcelFile(buffer)) as { sheet: string; data: unknown[][] }[]

  const avisos: string[] = []
  const hojas: HojaLeida[] = []
  const porNombre = new Map(libro.map((s) => [normalizarCabecera(s.sheet), s]))

  const algunaHoja = def.hojas.some((h) => porNombre.has(normalizarCabecera(h.hoja)))
  if (!algunaHoja) {
    avisos.push(
      'El archivo no tiene ninguna de las hojas esperadas (' +
        def.hojas.map((h) => h.hoja).join(', ') +
        '). Use la plantilla descargada.'
    )
  }

  for (const hoja of def.hojas) {
    const s = porNombre.get(normalizarCabecera(hoja.hoja))
    if (!s) continue
    const lectura = leerHoja(hoja, s.data, avisos)
    if (lectura) hojas.push(lectura)
  }

  return { hojas, avisos }
}

function leerHoja(hoja: ImportacionHoja, data: unknown[][], avisos: string[]): HojaLeida | null {
  if (data.length === 0) return null

  const cabeceras = (data[0] ?? []).map((c) => normalizarCabecera(textoCelda(c)))
  const indicePorClave = new Map<string, number>()
  for (const col of hoja.columnas) {
    const idx = cabeceras.indexOf(normalizarCabecera(col.cabecera))
    if (idx >= 0) indicePorClave.set(col.clave, idx)
  }

  const conocidas = new Set(hoja.columnas.map((c) => normalizarCabecera(c.cabecera)))
  const desconocidas = (data[0] ?? [])
    .map((c) => textoCelda(c))
    .filter((t) => t !== '' && !conocidas.has(normalizarCabecera(t)))
  if (desconocidas.length > 0) {
    avisos.push(`Hoja ${hoja.hoja}: columnas que no se importan (no reconocidas): ${desconocidas.join(', ')}.`)
  }

  const filas: ImportacionFila[] = []
  for (let i = 1; i < data.length; i++) {
    const fila = data[i] ?? []
    const datos: Record<string, unknown> = {}
    let alguno = false
    for (const [clave, idx] of indicePorClave) {
      const t = textoCelda(fila[idx])
      if (t !== '') {
        datos[clave] = t
        alguno = true
      }
    }
    if (alguno) filas.push({ fila: i + 1, datos })
  }

  if (filas.length > 0) {
    const faltan = hoja.columnas.filter((c) => c.obligatoria && !indicePorClave.has(c.clave))
    if (faltan.length > 0) {
      avisos.push(
        `Hoja ${hoja.hoja}: falta la columna obligatoria ${faltan.map((c) => `"${c.cabecera}"`).join(', ')}. ` +
          'Las filas darán error hasta que la añada.'
      )
    }
  }

  return { entidad: hoja.entidad, hoja: hoja.hoja, filas }
}

/** Descarga un Blob como archivo (Chrome, Edge y Electron). */
export function descargarBlob(nombre: string, blob: Blob): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = nombre
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
}

/** Líneas CSV (separador ;) del informe de errores. */
export function lineasInformeErrores(errores: ImportacionMensaje[], avisos: ImportacionMensaje[]): string[] {
  const esc = (v: string | number) => {
    const s = String(v)
    return /[;"\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s
  }
  const lineas = ['Tipo;Hoja;Fila;Campo;Mensaje']
  for (const e of errores) lineas.push(['Error', e.hoja, e.fila, e.campo, e.mensaje].map(esc).join(';'))
  for (const a of avisos) lineas.push(['Aviso', a.hoja, a.fila, a.campo, a.mensaje].map(esc).join(';'))
  return lineas
}
