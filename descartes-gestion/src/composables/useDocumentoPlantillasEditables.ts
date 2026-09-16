import { ref } from 'vue'
import { api } from '@/api/client'
import { extractApiError } from '@/composables/useMantenimiento'
import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import { documentosPlantillas } from '@/config/documentos-plantillas'

const LEGACY_STORAGE_KEY = 'descartes.documentos.plantillas.v1'

export type DocumentoPlantillaServidor = {
  id: number
  empresaCodigo: string
  tipo: string
  nombre: string
  descripcion: string | null
  version: number
  activa: boolean
  definicion: DocumentoPlantilla
  creado?: string | null
  actualizado?: string | null
}

function clonePlantilla(p: DocumentoPlantilla): DocumentoPlantilla {
  return JSON.parse(JSON.stringify(p)) as DocumentoPlantilla
}

function asDefinicion(raw: unknown, fallbackTipo: string, fallbackNombre: string): DocumentoPlantilla {
  if (raw && typeof raw === 'object' && Array.isArray((raw as DocumentoPlantilla).blocks)) {
    return clonePlantilla(raw as DocumentoPlantilla)
  }
  return {
    id: `plantilla-${fallbackTipo}`,
    tipo: fallbackTipo as DocumentoPlantilla['tipo'],
    nombre: fallbackNombre,
    version: 1,
    page: {
      format: 'A4',
      orientation: 'portrait',
      marginMm: { top: 10, right: 10, bottom: 12, left: 10 },
    },
    blocks: [],
  }
}

function mapItem(raw: Record<string, unknown>): DocumentoPlantillaServidor {
  const tipo = String(raw.tipo ?? '')
  const nombre = String(raw.nombre ?? 'Plantilla')
  return {
    id: Number(raw.id),
    empresaCodigo: String(raw.empresaCodigo ?? '').trim(),
    tipo,
    nombre,
    descripcion: raw.descripcion != null ? String(raw.descripcion) : null,
    version: Number(raw.version ?? 1),
    activa: Boolean(raw.activa),
    definicion: asDefinicion(raw.definicion, tipo, nombre),
    creado: raw.creado != null ? String(raw.creado) : null,
    actualizado: raw.actualizado != null ? String(raw.actualizado) : null,
  }
}

/** Limpia el guardado local antiguo (no debe usarse: las plantillas viven en el servidor). */
function limpiarLegacyLocal(): void {
  try {
    localStorage.removeItem(LEGACY_STORAGE_KEY)
  } catch {
    /* ignore */
  }
}

const items = ref<DocumentoPlantillaServidor[]>([])
const cargando = ref(false)
const guardando = ref(false)

export function useDocumentoPlantillasEditables() {
  async function cargarEmpresa(empresaCodigo: string): Promise<{ ok: boolean; message: string }> {
    const empresa = empresaCodigo.trim().toUpperCase()
    if (!empresa) {
      return { ok: false, message: 'No hay empresa en el puesto. Configure el equipo primero.' }
    }
    limpiarLegacyLocal()
    cargando.value = true
    try {
      const { data } = await api.get('/api/mantenimiento/documento-plantillas', {
        params: { empresa },
      })
      let lista = ((data?.items ?? []) as Record<string, unknown>[]).map(mapItem)
      if (lista.length === 0) {
        const { data: seeded } = await api.post('/api/mantenimiento/documento-plantillas/sembrar', {
          empresaCodigo: empresa,
          skeletons: documentosPlantillas.map((p) => clonePlantilla(p)),
        })
        lista = ((seeded?.items ?? []) as Record<string, unknown>[]).map(mapItem)
      }
      items.value = lista
      return { ok: true, message: lista.length ? 'Plantillas cargadas del servidor.' : 'Sin plantillas.' }
    } catch (e: unknown) {
      return { ok: false, message: extractApiError(e, 'No se pudieron cargar las plantillas') }
    } finally {
      cargando.value = false
    }
  }

  function obtener(id: number): DocumentoPlantillaServidor | undefined {
    const found = items.value.find((p) => p.id === id)
    if (!found) return undefined
    return {
      ...found,
      definicion: clonePlantilla(found.definicion),
    }
  }

  async function guardar(
    id: number,
    plantilla: DocumentoPlantilla,
    opts?: { activar?: boolean; nombre?: string; descripcion?: string | null }
  ): Promise<{ ok: boolean; message: string; item?: DocumentoPlantillaServidor }> {
    guardando.value = true
    try {
      const payload: Record<string, unknown> = {
        nombre: opts?.nombre ?? plantilla.nombre,
        descripcion: opts?.descripcion ?? plantilla.descripcion ?? null,
        definicion: {
          ...clonePlantilla(plantilla),
          nombre: opts?.nombre ?? plantilla.nombre,
          descripcion: opts?.descripcion ?? plantilla.descripcion,
        },
      }
      if (opts?.activar) payload.activa = true
      const { data } = await api.put(`/api/mantenimiento/documento-plantillas/${id}`, payload)
      const item = mapItem(data as Record<string, unknown>)
      items.value = items.value.map((p) => (p.id === id ? item : p))
      return { ok: true, message: 'Plantilla guardada en el servidor.', item }
    } catch (e: unknown) {
      return { ok: false, message: extractApiError(e, 'No se pudo guardar la plantilla') }
    } finally {
      guardando.value = false
    }
  }

  async function crear(
    empresaCodigo: string,
    plantilla: DocumentoPlantilla,
    opts?: { activar?: boolean; nombre?: string }
  ): Promise<{ ok: boolean; message: string; item?: DocumentoPlantillaServidor }> {
    const empresa = empresaCodigo.trim().toUpperCase()
    if (!empresa) {
      return { ok: false, message: 'No hay empresa en el puesto.' }
    }
    guardando.value = true
    try {
      const nombre = opts?.nombre ?? plantilla.nombre
      const { data } = await api.post('/api/mantenimiento/documento-plantillas', {
        empresaCodigo: empresa,
        tipo: plantilla.tipo,
        nombre,
        descripcion: plantilla.descripcion ?? null,
        activa: opts?.activar ?? false,
        definicion: { ...clonePlantilla(plantilla), nombre },
      })
      const item = mapItem(data as Record<string, unknown>)
      items.value = [...items.value, item]
      return { ok: true, message: 'Plantilla creada en el servidor.', item }
    } catch (e: unknown) {
      return { ok: false, message: extractApiError(e, 'No se pudo crear la plantilla') }
    } finally {
      guardando.value = false
    }
  }

  async function activar(id: number): Promise<{ ok: boolean; message: string }> {
    try {
      const { data } = await api.post(`/api/mantenimiento/documento-plantillas/${id}/activar`)
      const item = mapItem(data as Record<string, unknown>)
      items.value = items.value.map((p) =>
        p.tipo === item.tipo ? { ...p, activa: p.id === id } : p
      )
      const idx = items.value.findIndex((p) => p.id === id)
      if (idx >= 0) items.value[idx] = { ...items.value[idx], ...item, activa: true }
      return { ok: true, message: 'Plantilla marcada como activa para impresión.' }
    } catch (e: unknown) {
      return { ok: false, message: extractApiError(e, 'No se pudo activar') }
    }
  }

  async function eliminar(id: number): Promise<{ ok: boolean; message: string }> {
    try {
      await api.delete(`/api/mantenimiento/documento-plantillas/${id}`)
      items.value = items.value.filter((p) => p.id !== id)
      return { ok: true, message: 'Plantilla eliminada.' }
    } catch (e: unknown) {
      return { ok: false, message: extractApiError(e, 'No se pudo eliminar') }
    }
  }

  /** Sustituye la definición por el esqueleto base del mismo tipo (sigue en servidor al guardar). */
  function esqueletoPorTipo(tipo: string, plantillaId?: string): DocumentoPlantilla | undefined {
    if (plantillaId) {
      const byId = documentosPlantillas.find((p) => p.id === plantillaId)
      if (byId) return clonePlantilla(byId)
    }
    const base = documentosPlantillas.find((p) => p.tipo === tipo)
    return base ? clonePlantilla(base) : undefined
  }

  return {
    items,
    cargando,
    guardando,
    cargarEmpresa,
    obtener,
    guardar,
    crear,
    activar,
    eliminar,
    esqueletoPorTipo,
  }
}
