import { api } from './client'

export type ImportacionEntidad =
  | 'impuestos'
  | 'formas-pago'
  | 'macrofamilias'
  | 'familias'
  | 'subfamilias'
  | 'proveedores'
  | 'articulos'
  | 'clientes'

export interface ImportacionColumna {
  clave: string
  cabecera: string
  obligatoria: boolean
  tipo: 'texto' | 'entero' | 'decimal' | 'booleano'
  max: number | null
  ejemplo: string
  ayuda: string
  ref?: string
}

export interface ImportacionHoja {
  entidad: ImportacionEntidad
  hoja: string
  titulo: string
  descripcion: string
  claveCodigo: string
  codigoAutomatico: boolean
  unicos: string[]
  columnas: ImportacionColumna[]
}

export interface ImportacionDefinicion {
  hojas: ImportacionHoja[]
  instrucciones: string[]
}

export interface ImportacionFila {
  /** Número de fila en el Excel (la 1 es la cabecera). */
  fila: number
  datos: Record<string, unknown>
}

export interface ImportacionMensaje {
  hoja: string
  fila: number
  campo: string
  mensaje: string
}

export interface ImportacionResultadoLote {
  entidad: ImportacionEntidad
  hoja: string
  simulacro: boolean
  total: number
  creadas: number
  omitidas: number
  errores: ImportacionMensaje[]
  avisos: ImportacionMensaje[]
}

/** Códigos del propio archivo que el simulacro necesita para validar referencias. */
export interface ImportacionContexto {
  impuestos?: string[]
  'formas-pago'?: string[]
  macrofamilias?: string[]
  familias?: { codigo: string; macroFamiliaCodigo: string }[]
  subfamilias?: string[]
  proveedores?: string[]
}

export async function obtenerDefinicionImportacion(): Promise<ImportacionDefinicion> {
  const { data } = await api.get<ImportacionDefinicion>('/api/importacion/columnas')
  return data
}

export async function importarLote(
  entidad: ImportacionEntidad,
  filas: ImportacionFila[],
  opciones: { simulacro: boolean; contexto?: ImportacionContexto; empresaCodigo?: string }
): Promise<ImportacionResultadoLote> {
  const { data } = await api.post<ImportacionResultadoLote>(
    `/api/importacion/${entidad}`,
    {
      filas,
      simulacro: opciones.simulacro,
      contexto: opciones.contexto ?? {},
      empresaCodigo: opciones.empresaCodigo ?? '',
    },
    { timeout: 120000 }
  )
  return data
}
