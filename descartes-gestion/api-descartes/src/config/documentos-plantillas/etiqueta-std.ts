import type { DocumentoPlantilla } from './types'

/**
 * Plantilla estándar de etiqueta de artículo (005 / T016).
 * Formato `label` 50×30 mm: descripción, código, EAN (barras), precio.
 * Binds: articulo.codigo | articulo.descripcion | articulo.ean | articulo.precio
 */
export const plantillaEtiqueta: DocumentoPlantilla = {
  id: 'etiqueta-std',
  tipo: 'etiqueta',
  nombre: 'Etiqueta estándar 50×30',
  descripcion:
    'Etiqueta de artículo: descripción, código, código de barras EAN y precio. Tamaño 50×30 mm.',
  version: 1,
  page: {
    format: 'label',
    orientation: 'portrait',
    widthMm: 50,
    heightMm: 30,
    marginMm: { top: 1, right: 1, bottom: 1, left: 1 },
  },
  blocks: [
    {
      id: 'desc',
      type: 'campo',
      x: 1.5,
      y: 1.5,
      w: 47,
      h: 5,
      label: '',
      bind: ['articulo.descripcion'],
      props: { fontSizeMm: 3.5, fontWeight: 'bold' },
    },
    {
      id: 'codigo',
      type: 'campo',
      x: 1.5,
      y: 6.5,
      w: 28,
      h: 3.5,
      label: '',
      bind: ['articulo.codigo'],
      props: { fontSizeMm: 2.5 },
    },
    {
      id: 'ean-barras',
      type: 'codigo-barras',
      x: 1.5,
      y: 10.5,
      w: 32,
      h: 10,
      label: 'EAN',
      bind: ['articulo.ean'],
      props: { showValue: true, moduleWidth: 1.2 },
    },
    {
      id: 'ean-texto',
      type: 'campo',
      x: 1.5,
      y: 20.5,
      w: 32,
      h: 3,
      label: '',
      bind: ['articulo.ean'],
      props: { fontSizeMm: 2.2, centrado: true },
    },
    {
      id: 'precio',
      type: 'campo',
      x: 28,
      y: 21,
      w: 20.5,
      h: 7,
      label: '',
      bind: ['articulo.precio'],
      props: { fontSizeMm: 5, fontWeight: 'bold', format: 'importe', align: 'right' },
    },
  ],
}
