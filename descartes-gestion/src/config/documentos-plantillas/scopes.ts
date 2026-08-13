import type { DocumentoTipo } from './types'

/** Secciones del menú Configuración → confección de plantillas. */
export type DocumentosPlantillasScope = 'albaranes' | 'tickets' | 'etiquetas'

export type DocumentosPlantillasScopeMeta = {
  id: DocumentosPlantillasScope
  titulo: string
  descripcionHub: string
  intro: string
  ruta: string
  tipos: readonly DocumentoTipo[]
  tipoDefault: DocumentoTipo
}

export const DOCUMENTOS_PLANTILLAS_SCOPES: Record<
  DocumentosPlantillasScope,
  DocumentosPlantillasScopeMeta
> = {
  albaranes: {
    id: 'albaranes',
    titulo: 'Albaranes y facturas',
    descripcionHub: 'Plantillas A4: albarán, factura contado, crédito y rectificativa.',
    intro:
      'Diseñador A4 por empresa. Albaranes y facturas. Guardar actualiza la seleccionada; Nueva / Guardar como… crean otra sin sobrescribir.',
    ruta: '/configuracion/albaranes',
    tipos: ['albaran', 'factura-contado', 'factura-credito', 'factura-rectificativa'],
    tipoDefault: 'albaran',
  },
  tickets: {
    id: 'tickets',
    titulo: 'Tickets',
    descripcionHub: 'Plantillas de ticket térmico 80 mm.',
    intro:
      'Diseñador de ticket 80 mm por empresa. Guardar actualiza la seleccionada; Nueva / Guardar como… crean otra sin sobrescribir.',
    ruta: '/configuracion/tickets',
    tipos: ['ticket'],
    tipoDefault: 'ticket',
  },
  etiquetas: {
    id: 'etiquetas',
    titulo: 'Etiquetas',
    descripcionHub: 'Plantillas de etiqueta de artículo (varios tamaños mm).',
    intro:
      'Diseñador de etiquetas por empresa. Elija el tamaño al crear. Guardar actualiza la seleccionada; Nueva / Guardar como… crean otra sin sobrescribir.',
    ruta: '/configuracion/etiquetas',
    tipos: ['etiqueta'],
    tipoDefault: 'etiqueta',
  },
}

export function scopeDesdeRuta(path: string): DocumentosPlantillasScope {
  if (path.includes('/tickets')) return 'tickets'
  if (path.includes('/etiquetas')) return 'etiquetas'
  return 'albaranes'
}

export function tiposDelScope(scope: DocumentosPlantillasScope): readonly DocumentoTipo[] {
  return DOCUMENTOS_PLANTILLAS_SCOPES[scope].tipos
}

export function perteneceAlScope(tipo: string, scope: DocumentosPlantillasScope): boolean {
  return (DOCUMENTOS_PLANTILLAS_SCOPES[scope].tipos as readonly string[]).includes(tipo)
}
