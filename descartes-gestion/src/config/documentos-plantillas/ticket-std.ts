import type { DocumentoPlantilla } from './types'

/**
 * Esqueleto ticket térmico 80 mm.
 * Textos de pie / literales:
 * - Tienda (Datos generales): Pie Fra.*, Pie presupuesto, Pie vale, nº Literales ticket
 * - Puesto: Literal1…Literal9 (se imprimen según LiteralTicket de la tienda)
 */
export const plantillaTicket: DocumentoPlantilla = {
  id: 'ticket-std',
  tipo: 'ticket',
  nombre: 'Ticket estándar 80 mm',
  descripcion:
    'Ticket térmico. Cabecera de tienda, líneas, totales y literales del puesto (Tiendas → Literales ticket).',
  version: 1,
  page: {
    format: 'ticket-80',
    orientation: 'portrait',
    widthMm: 80,
    marginMm: { top: 2, right: 2, bottom: 4, left: 2 },
  },
  blocks: [
    {
      id: 'empresa',
      type: 'empresa-cabecera',
      x: 2,
      y: 2,
      w: 76,
      h: 22,
      bind: [
        'empresa.nombre',
        'empresa.direccion',
        'empresa.cp',
        'empresa.poblacion',
        'empresa.telefono',
        'empresa.nif',
      ],
    },
    {
      id: 'sep1',
      type: 'separador',
      x: 2,
      y: 26,
      w: 76,
      h: 3,
      label: '--------------------------------',
    },
    {
      id: 'titulo',
      type: 'titulo-documento',
      x: 2,
      y: 30,
      w: 76,
      h: 8,
      label: 'TICKET',
      bind: ['documento.numero'],
      props: { centrado: true },
    },
    {
      id: 'meta',
      type: 'bloque-meta',
      x: 2,
      y: 40,
      w: 76,
      h: 14,
      bind: ['documento.fecha', 'documento.terminalSesion', 'documento.atendidoPor'],
    },
    {
      id: 'sep2',
      type: 'separador',
      x: 2,
      y: 56,
      w: 76,
      h: 3,
      label: '--------------------------------',
    },
    {
      id: 'lineas',
      type: 'tabla-lineas',
      x: 2,
      y: 60,
      w: 76,
      h: 40,
      columns: [
        { key: 'descripcion', label: 'Artículo', width: 42 },
        { key: 'unidades', label: 'Ud', width: 12 },
        { key: 'importe', label: 'Imp', width: 22 },
      ],
    },
    {
      id: 'totales',
      type: 'totales-ticket',
      x: 2,
      y: 102,
      w: 76,
      h: 18,
      bind: ['totales.base', 'totales.ivas', 'totales.importe'],
    },
    {
      id: 'sep3',
      type: 'separador',
      x: 2,
      y: 122,
      w: 76,
      h: 3,
      label: '--------------------------------',
    },
    {
      id: 'literales',
      type: 'literales-puesto',
      x: 2,
      y: 126,
      w: 76,
      h: 28,
      bind: ['puesto.literales', 'tienda.literalTicket'],
      label: 'Literales del puesto (1…N según tienda)',
      props: { centrado: true },
    },
  ],
}
