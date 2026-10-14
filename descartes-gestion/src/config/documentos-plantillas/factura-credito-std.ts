import type { DocumentoPlantilla, PlantillaBloque } from './types'

const pageFacturaCredito: DocumentoPlantilla['page'] = {
  format: 'A4',
  orientation: 'portrait',
  marginMm: { top: 10, right: 10, bottom: 12, left: 10 },
}

/** Cabecera de crédito sin QR tributario. */
function bloquesFacturaCredito(_conVerifactu: boolean): PlantillaBloque[] {
  const empresaX = 10
  const empresaW = 106

  const blocks: PlantillaBloque[] = []

  blocks.push(
    {
      id: 'empresa',
      type: 'empresa-cabecera',
      x: empresaX,
      y: 10,
      w: empresaW,
      h: 28,
      bind: [
        'empresa.nombre',
        'empresa.direccion',
        'empresa.cp',
        'empresa.poblacion',
        'empresa.provincia',
        'empresa.telefono',
        'empresa.fax',
        'empresa.email',
      ],
    },
    {
      id: 'titulo',
      type: 'titulo-documento',
      x: 118,
      y: 12,
      w: 92,
      h: 12,
      label: 'FACTURA DE CRÉDITO',
      bind: [],
      props: { fontSizeMm: 5.2, fontWeight: 'bold' },
    },
    {
      id: 'sep-cabecera',
      type: 'separador',
      x: 10,
      y: 40,
      w: 190,
      h: 2,
    },
    {
      id: 'numero',
      type: 'campo',
      x: 12,
      y: 44,
      w: 90,
      h: 6,
      label: 'FACTURA',
      bind: ['documento.numero'],
      props: { inline: true },
    },
    {
      id: 'fecha',
      type: 'campo',
      x: 12,
      y: 51,
      w: 90,
      h: 5,
      label: 'Fecha',
      bind: ['documento.fecha'],
      props: { inline: true, etiquetaPlana: true, fontWeight: 'bold' },
    },
    {
      id: 'forma-pago',
      type: 'campo',
      x: 12,
      y: 57,
      w: 90,
      h: 5,
      bind: ['documento.formaPago'],
      props: { fontSizeMm: 2.4, fontWeight: 'bold' },
    },
    {
      id: 'cliente',
      type: 'bloque-cliente',
      x: 106,
      y: 44,
      w: 94,
      h: 40,
      bind: [
        'cliente.codigo',
        'cliente.nombre',
        'cliente.direccion',
        'cliente.cp',
        'cliente.poblacion',
        'cliente.provincia',
        'cliente.pais',
        'cliente.telefono',
        'cliente.cif',
      ],
    },
    {
      id: 'banco',
      type: 'datos-bancarios',
      x: 10,
      y: 86,
      w: 190,
      h: 10,
      bind: ['empresa.banco', 'empresa.iban', 'empresa.swift'],
    },
    {
      id: 'sep-observaciones',
      type: 'separador',
      x: 10,
      y: 98,
      w: 190,
      h: 2,
    },
    {
      id: 'obs',
      type: 'campo',
      x: 10,
      y: 100,
      w: 190,
      h: 8,
      label: 'Observaciones',
      bind: ['documento.observaciones'],
    },
    {
      id: 'lineas',
      type: 'tabla-lineas',
      x: 10,
      y: 110,
      w: 190,
      h: 124,
      props: { agruparPorAlbaran: true, mostrarCabeceraAlbaran: true, permitirNotasLinea: true },
      columns: [
        { key: 'articulo', label: 'Artículo', width: 14 },
        { key: 'descripcion', label: 'Descripción', width: 38 },
        { key: 'unidades', label: 'Unidades', width: 10, align: 'right' },
        { key: 'precio', label: 'Precio', width: 12, align: 'right' },
        { key: 'dto', label: 'Dto', width: 7, align: 'right' },
        { key: 'pjeIva', label: '%IVA', width: 7, align: 'right' },
        { key: 'importe', label: 'Importe', width: 12, align: 'right' },
      ],
      bind: ['lineas'],
    },
    {
      id: 'vencimientos',
      type: 'vencimientos',
      x: 10,
      y: 238,
      w: 62,
      h: 32,
      bind: ['vencimientos'],
      props: { columnas: ['fecha', 'importe'] },
    },
    {
      id: 'totales',
      type: 'totales-iva',
      x: 118,
      y: 238,
      w: 82,
      h: 32,
      bind: [
        'totales.base',
        'totales.ivas',
        'totales.importe',
        'totales.pjeRetIrpf',
        'totales.basRetIrpf',
        'totales.impRetIrpf',
        'totales.liquido',
      ],
      props: { etiquetaTotal: 'IMPORTE FACTURA EU' },
    },
    {
      id: 'pie',
      type: 'pie',
      x: 10,
      y: 284,
      w: 190,
      h: 6,
      bind: ['empresa.razonSocial', 'empresa.nif', 'documento.pagina'],
      props: {
        plantilla: '{{empresa.razonSocial}} N.I.F.{{empresa.nif}}  Página {{documento.pagina}}',
      },
    }
  )

  return blocks
}

/** Factura de crédito. Esqueleto por defecto del tipo, sin QR. */
export const plantillaFacturaCredito: DocumentoPlantilla = {
  id: 'factura-credito-std',
  tipo: 'factura-credito',
  nombre: 'Factura crédito',
  descripcion: 'Crédito / diferida, sin QR tributario',
  version: 8,
  page: pageFacturaCredito,
  blocks: bloquesFacturaCredito(true),
}

/** Misma composición sin bloque QR Verifactu. */
export const plantillaFacturaCreditoSinVerifactu: DocumentoPlantilla = {
  id: 'factura-credito-sin-verifactu',
  tipo: 'factura-credito',
  nombre: 'Factura crédito (sin Verifactu)',
  descripcion: 'Crédito / diferida sin QR tributario',
  version: 1,
  page: pageFacturaCredito,
  blocks: bloquesFacturaCredito(false),
}

/** Rectificativa con la composición de crédito sin QR. */
function bloquesFacturaRectificativa(): PlantillaBloque[] {
  const bloques = bloquesFacturaCredito(false).map((b) => ({
    ...b,
    bind: b.bind ? [...b.bind] : undefined,
    props: b.props ? { ...b.props } : undefined,
    columns: b.columns?.map((c) => ({ ...c })),
  }))
  const titulo = bloques.find((b) => b.id === 'titulo')
  if (titulo) titulo.label = 'FACTURA RECTIFICATIVA'
  const forma = bloques.findIndex((b) => b.id === 'forma-pago')
  bloques.splice(forma + 1, 0, {
    id: 'origen',
    type: 'campo',
    x: 12,
    y: 63,
    w: 90,
    h: 5,
    label: 'Factura origen',
    bind: ['documento.facturaOrigen'],
    props: { inline: true, ocultarVacio: true, fontSizeMm: 2.4 },
  })
  return bloques
}

export const plantillaFacturaRectificativa: DocumentoPlantilla = {
  id: 'factura-rectificativa-std',
  tipo: 'factura-rectificativa',
  nombre: 'Factura rectificativa',
  descripcion: 'Rectificativa sin QR, con la factura de origen si el documento la trae',
  version: 8,
  page: pageFacturaCredito,
  blocks: bloquesFacturaRectificativa(),
}
