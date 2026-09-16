import type { DocumentoPlantilla, DocumentoTipo } from './types'
import { plantillaAlbaran } from './albaran-std'
import { plantillaFacturaContado } from './factura-contado-std'
import {
  plantillaFacturaCredito,
  plantillaFacturaCreditoSinVerifactu,
  plantillaFacturaRectificativa,
} from './factura-credito-std'
import { plantillaTicket } from './ticket-std'
import { plantillaEtiqueta } from './etiqueta-std'

export type {
  DocumentoPlantilla,
  DocumentoTipo,
  PlantillaBloque,
  PlantillaBloqueTipo,
  PlantillaPageFormat,
} from './types'
export {
  ETIQUETA_TAMANOS_MM,
  claveTamanoEtiqueta,
  conTamanoEtiqueta,
  esPlantillaEtiqueta,
  esPlantillaTicket,
  pageSizeMm,
  parseClaveTamanoEtiqueta,
} from './types'
export { datosPreviewPorTipo, EMBLEMA_PLACEHOLDER } from './preview-datos'
export { plantillaEtiqueta } from './etiqueta-std'
export {
  DOCUMENTOS_PLANTILLAS_SCOPES,
  perteneceAlScope,
  scopeDesdeRuta,
  tiposDelScope,
} from './scopes'
export type { DocumentosPlantillasScope, DocumentosPlantillasScopeMeta } from './scopes'
export {
  datosEtiquetaDesdeArticulo,
  datosEtiquetaDesdeCola,
  htmlEtiquetaDesdePlantilla,
  prepararRenderEtiqueta,
  svgCodigoBarrasBloque,
} from './etiqueta-html'
export type { DatosArticuloEtiqueta, PrepRenderEtiqueta } from './etiqueta-html'

export const documentosPlantillas: DocumentoPlantilla[] = [
  plantillaAlbaran,
  plantillaFacturaContado,
  plantillaFacturaCredito,
  plantillaFacturaCreditoSinVerifactu,
  plantillaFacturaRectificativa,
  plantillaTicket,
  plantillaEtiqueta,
]

export function plantillasPorTipo(tipo: DocumentoTipo): DocumentoPlantilla[] {
  return documentosPlantillas.filter((p) => p.tipo === tipo)
}

export function plantillaPorId(id: string): DocumentoPlantilla | undefined {
  return documentosPlantillas.find((p) => p.id === id)
}
