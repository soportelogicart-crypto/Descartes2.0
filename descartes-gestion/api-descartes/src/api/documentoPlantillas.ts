import { api } from '@/api/client'
import type { PaqueteExportacion } from '@/composables/documentoPlantillaPortabilidad'

export type ImportarPlantillasRespuesta = {
  items: Record<string, unknown>[]
  creadas: number
  errores?: { indice: number; nombre?: string | null; error: string }[]
}

export async function importarPlantillasDocumento(
  empresaCodigo: string,
  paquete: PaqueteExportacion,
  opts?: { activar?: boolean }
): Promise<ImportarPlantillasRespuesta> {
  const { data } = await api.post<ImportarPlantillasRespuesta>(
    '/api/mantenimiento/documento-plantillas/importar',
    {
      empresaCodigo: empresaCodigo.trim().toUpperCase(),
      paquete,
      activar: Boolean(opts?.activar),
    }
  )
  return data
}
