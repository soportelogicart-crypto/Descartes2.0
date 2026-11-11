import type { DocumentoPlantilla } from './types'

/**
 * Plantilla estándar de la etiqueta del sobre de un encargo: una por sobre.
 * Etiqueta 76×40 mm: cliente, teléfono, email, fecha de creación, producto, "sobre n/N"
 * y código de barras (Code 128) con tienda + albarán.
 * Binds: encargo.cliente | encargo.telefono | encargo.email | encargo.fecha |
 *        encargo.producto | encargo.sobre | documento.codigoBarras
 */
export const plantillaEtiquetaEncargo: DocumentoPlantilla = {
  id: 'etiqueta-encargo-std',
  tipo: 'etiqueta-encargo',
  nombre: 'Etiqueta de encargo 76×40',
  descripcion:
    'Sobre de un encargo: cliente, teléfono, email, fecha, producto, sobre n/N y código de barras de tienda + albarán.',
  version: 1,
  page: {
    format: 'label',
    orientation: 'portrait',
    widthMm: 76,
    heightMm: 40,
    marginMm: { top: 1, right: 1, bottom: 1, left: 1 },
  },
  blocks: [
    {
      id: 'cliente',
      type: 'campo',
      x: 2,
      y: 1,
      w: 72,
      h: 5.5,
      label: '',
      bind: ['encargo.cliente'],
      props: { fontSizeMm: 4.4, fontWeight: 'bold' },
    },
    {
      id: 'telefono',
      type: 'campo',
      x: 2,
      y: 6.8,
      w: 34,
      h: 3.5,
      label: 'Tel.',
      bind: ['encargo.telefono'],
      props: { fontSizeMm: 2.6 },
    },
    {
      id: 'fecha',
      type: 'campo',
      x: 38,
      y: 6.8,
      w: 36,
      h: 3.5,
      label: 'Creado',
      bind: ['encargo.fecha'],
      props: { fontSizeMm: 2.6 },
    },
    {
      id: 'email',
      type: 'campo',
      x: 2,
      y: 10.4,
      w: 72,
      h: 3.5,
      label: '',
      bind: ['encargo.email'],
      props: { fontSizeMm: 2.6 },
    },
    {
      id: 'producto',
      type: 'campo',
      x: 2,
      y: 14.4,
      w: 52,
      h: 4,
      label: '',
      bind: ['encargo.producto'],
      props: { fontSizeMm: 3.2, fontWeight: 'bold' },
    },
    {
      id: 'sobre',
      type: 'campo',
      x: 54,
      y: 14.4,
      w: 20,
      h: 4,
      label: '',
      bind: ['encargo.sobre'],
      props: { fontSizeMm: 3.2, fontWeight: 'bold', align: 'right' },
    },
    {
      id: 'barras',
      type: 'codigo-barras',
      x: 2,
      y: 19.6,
      w: 72,
      h: 14,
      label: '',
      bind: ['documento.codigoBarras'],
      props: { simbologia: 'code128', showValue: false },
    },
    {
      id: 'codigo-texto',
      type: 'campo',
      x: 2,
      y: 34.4,
      w: 72,
      h: 3.8,
      label: '',
      bind: ['documento.codigoBarras'],
      props: { fontSizeMm: 2.8, centrado: true },
    },
  ],
}
