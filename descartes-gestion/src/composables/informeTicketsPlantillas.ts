import type { InformeTicketsResult } from '@/api/listados'
import { ESTILOS_LEGACY, type OpcionesPlantillaTickets } from './informe-tickets-plantillas/comun'
import { plantillaDesglosado } from './informe-tickets-plantillas/desglosado'
import { plantillaExportar } from './informe-tickets-plantillas/exportar'
import { plantillaResumido } from './informe-tickets-plantillas/resumido'
import { plantillaSuperResumido } from './informe-tickets-plantillas/superResumido'

function resolver(data: InformeTicketsResult) {
  switch ((data.formato ?? 'desglosado').toLowerCase()) {
    case 'resumido':
      return plantillaResumido
    case 'superresumido':
      return plantillaSuperResumido
    case 'exportar':
      return plantillaExportar
    default:
      return plantillaDesglosado
  }
}

export function construirHtmlInformeTicketsLegacy(
  data: InformeTicketsResult,
  opciones: OpcionesPlantillaTickets = {},
): string {
  return resolver(data)(data, opciones, false)
}

export function construirPreviewInformeTicketsLegacy(
  data: InformeTicketsResult,
  opciones: OpcionesPlantillaTickets = {},
): string {
  return `<style>${ESTILOS_LEGACY}</style>${resolver(data)(data, opciones, true)}`
}

export const estilosInformeTicketsLegacy = ESTILOS_LEGACY
