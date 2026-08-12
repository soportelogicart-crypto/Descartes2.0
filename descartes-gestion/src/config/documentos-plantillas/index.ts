import type { DocumentoPlantilla, DocumentoTipo } from './types'
import { plantillaAlbaran } from './albaran-std'
import { plantillaFacturaContado } from './factura-contado-std'
import {
  plantillaFacturaCredito,
  plantillaFacturaRectificativa,
} from './factura-credito-std'
import { plantillaTicket } from './ticket-std'

export type {
  DocumentoPlantilla,
  DocumentoTipo,
  PlantillaBloque,
  PlantillaBloqueTipo,
  PlantillaPageFormat,
} from './types'
export { esPlantillaTicket } from './types'
export { datosPreviewPorTipo, EMBLEMA_PLACEHOLDER } from './preview-datos'

export const documentosPlantillas: DocumentoPlantilla[] = [
  plantillaAlbaran,
  plantillaFacturaContado,
  plantillaFacturaCredito,
  plantillaFacturaRectificativa,
  plantillaTicket,
]

export function plantillasPorTipo(tipo: DocumentoTipo): DocumentoPlantilla[] {
  return documentosPlantillas.filter((p) => p.tipo === tipo)
}

export function plantillaPorId(id: string): DocumentoPlantilla | undefined {
  return documentosPlantillas.find((p) => p.id === id)
}
