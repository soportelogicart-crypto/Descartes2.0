import type { DocumentoPlantilla } from '@/config/documentos-plantillas'

export const DOCUMENTO_PLANTILLA_FORMATO = 'descartes-documento-plantilla' as const
export const DOCUMENTO_PLANTILLA_VERSION = 1

export type PlantillaExportItem = {
  tipo: string
  nombre: string
  descripcion: string | null
  definicion: DocumentoPlantilla
}

export type PaqueteExportacion = {
  formato: typeof DOCUMENTO_PLANTILLA_FORMATO
  version: typeof DOCUMENTO_PLANTILLA_VERSION
  exportadoEn: string
  origenEmpresa: string
  plantillas: PlantillaExportItem[]
}

export function buildPaqueteExportacion(
  plantillas: PlantillaExportItem[],
  origenEmpresa: string
): PaqueteExportacion {
  return {
    formato: DOCUMENTO_PLANTILLA_FORMATO,
    version: DOCUMENTO_PLANTILLA_VERSION,
    exportadoEn: new Date().toISOString(),
    origenEmpresa: origenEmpresa.trim().toUpperCase(),
    plantillas: plantillas.map((p) => ({
      tipo: p.tipo,
      nombre: p.nombre,
      descripcion: p.descripcion,
      definicion: JSON.parse(JSON.stringify(p.definicion)) as DocumentoPlantilla,
    })),
  }
}

function validarPlantillaItem(raw: unknown, indice: number): PlantillaExportItem | string {
  if (!raw || typeof raw !== 'object') {
    return `Plantilla ${indice + 1}: entrada invalida`
  }
  const o = raw as Record<string, unknown>
  const def = o.definicion
  if (!def || typeof def !== 'object' || !Array.isArray((def as DocumentoPlantilla).blocks)) {
    return `Plantilla ${indice + 1}: falta definicion.blocks`
  }
  const tipo = String(o.tipo ?? (def as DocumentoPlantilla).tipo ?? '').trim()
  const nombre = String(o.nombre ?? (def as DocumentoPlantilla).nombre ?? '').trim()
  if (!tipo || !nombre) {
    return `Plantilla ${indice + 1}: tipo y nombre son obligatorios`
  }
  const descripcion =
    o.descripcion == null || o.descripcion === ''
      ? null
      : String(o.descripcion)
  return {
    tipo,
    nombre,
    descripcion,
    definicion: def as DocumentoPlantilla,
  }
}

export function parsePaqueteImportacion(
  text: string
):
  | { ok: true; paquete: PaqueteExportacion; plantillas: PlantillaExportItem[] }
  | { ok: false; error: string } {
  let parsed: unknown
  try {
    parsed = JSON.parse(text)
  } catch {
    return { ok: false, error: 'El fichero no es JSON valido.' }
  }
  if (!parsed || typeof parsed !== 'object') {
    return { ok: false, error: 'Formato de fichero no reconocido.' }
  }
  const root = parsed as Record<string, unknown>
  const formato = String(root.formato ?? '').trim()
  if (formato !== DOCUMENTO_PLANTILLA_FORMATO) {
    return { ok: false, error: 'Formato de fichero no reconocido (se esperaba exportacion Descartes).' }
  }
  const version = Number(root.version ?? 0)
  if (version !== DOCUMENTO_PLANTILLA_VERSION) {
    return { ok: false, error: `Version ${version} no soportada (se esperaba ${DOCUMENTO_PLANTILLA_VERSION}).` }
  }
  const rawList = root.plantillas
  if (!Array.isArray(rawList) || rawList.length === 0) {
    return { ok: false, error: 'El paquete no contiene plantillas.' }
  }
  const plantillas: PlantillaExportItem[] = []
  for (let i = 0; i < rawList.length; i++) {
    const v = validarPlantillaItem(rawList[i], i)
    if (typeof v === 'string') {
      return { ok: false, error: v }
    }
    plantillas.push(v)
  }
  const paquete: PaqueteExportacion = {
    formato: DOCUMENTO_PLANTILLA_FORMATO,
    version: DOCUMENTO_PLANTILLA_VERSION,
    exportadoEn: String(root.exportadoEn ?? new Date().toISOString()),
    origenEmpresa: String(root.origenEmpresa ?? '').trim(),
    plantillas,
  }
  return { ok: true, paquete, plantillas }
}

export function nombreFicheroExportacion(tipo: string, nombre: string): string {
  const slug = nombre
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9]+/g, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 40)
  return `plantilla-${tipo}-${slug || 'export'}.json`
}

export function descargarPaquete(paquete: PaqueteExportacion, filename: string): void {
  const blob = new Blob([JSON.stringify(paquete, null, 2)], { type: 'application/json;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
