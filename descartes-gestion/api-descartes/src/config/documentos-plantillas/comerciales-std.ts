import type { DocumentoPlantilla, PlantillaBloque } from './types'

const pageA4: DocumentoPlantilla['page'] = {
  format: 'A4',
  orientation: 'portrait',
  marginMm: { top: 10, right: 10, bottom: 12, left: 10 },
}

const columnasVenta: NonNullable<PlantillaBloque['columns']> = [
  { key: 'articulo', label: 'Artículo', width: 14 },
  { key: 'descripcion', label: 'Descripción', width: 38 },
  { key: 'unidades', label: 'Unidades', width: 10, align: 'right' },
  { key: 'precio', label: 'Precio', width: 12, align: 'right' },
  { key: 'dto', label: 'Dto', width: 7, align: 'right' },
  { key: 'pjeIva', label: '%IVA', width: 7, align: 'right' },
  { key: 'importe', label: 'Importe', width: 12, align: 'right' },
]

const columnasPresupuesto: NonNullable<PlantillaBloque['columns']> = [
  { key: 'articulo', label: 'Artículo', width: 12 },
  { key: 'descripcion', label: 'Descripción', width: 30 },
  { key: 'unidades', label: 'Unidades', width: 10, align: 'right' },
  { key: 'precio', label: 'Precio', width: 11, align: 'right' },
  { key: 'dto', label: 'Dto', width: 7, align: 'right' },
  { key: 'pjeIva', label: '%IVA', width: 7, align: 'right' },
  { key: 'importe', label: 'Importe', width: 11, align: 'right' },
  { key: 'pvp', label: 'P.V.P.', width: 12, align: 'right' },
]

const columnasCompra: NonNullable<PlantillaBloque['columns']> = [
  { key: 'articulo', label: 'Artículo', width: 16 },
  { key: 'descripcion', label: 'Descripción', width: 46 },
  { key: 'unidades', label: 'Unidades', width: 12, align: 'right' },
  { key: 'precio', label: 'Precio', width: 13, align: 'right' },
  { key: 'importe', label: 'Importe', width: 13, align: 'right' },
]

const pie: PlantillaBloque = {
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

/** Cabecera A4 sin QR: empresa, título, número, fecha y tercero. */
function cabecera(titulo: string, rotuloTercero: string): PlantillaBloque[] {
  return [
    {
      id: 'empresa',
      type: 'empresa-cabecera',
      x: 10,
      y: 10,
      w: 106,
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
      label: titulo,
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
      label: titulo,
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
      id: 'tercero',
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
      props: { rotulo: rotuloTercero },
    },
  ]
}

function observaciones(): PlantillaBloque[] {
  return [
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
  ]
}

function tabla(
  columns: NonNullable<PlantillaBloque['columns']>,
  extra?: PlantillaBloque['props']
): PlantillaBloque {
  return {
    id: 'lineas',
    type: 'tabla-lineas',
    x: 10,
    y: 110,
    w: 190,
    h: 124,
    columns,
    bind: ['lineas'],
    props: extra,
  }
}

function totales(etiqueta: string): PlantillaBloque {
  return {
    id: 'totales',
    type: 'totales-iva',
    x: 118,
    y: 238,
    w: 82,
    h: 32,
    bind: ['totales.base', 'totales.ivas', 'totales.importe'],
    props: { etiquetaTotal: etiqueta },
  }
}

/** Presupuesto: como el albarán, sin QR ni vencimientos, con PVP. */
export const plantillaPresupuesto: DocumentoPlantilla = {
  id: 'presupuesto-std',
  tipo: 'presupuesto',
  nombre: 'Presupuesto',
  descripcion: 'Presupuesto A4 sin QR, con su pedido, atendido por y PVP',
  version: 1,
  page: pageA4,
  blocks: [
    ...cabecera('PRESUPUESTO', 'Cliente'),
    {
      id: 'su-pedido',
      type: 'campo',
      x: 12,
      y: 57,
      w: 90,
      h: 5,
      label: 'Su pedido',
      bind: ['documento.suPedido'],
      props: { inline: true, ocultarVacio: true },
    },
    {
      id: 'atendido',
      type: 'campo',
      x: 12,
      y: 63,
      w: 90,
      h: 5,
      label: 'Atendido por',
      bind: ['documento.atendidoPor'],
      props: { inline: true, ocultarVacio: true },
    },
    ...observaciones(),
    tabla(columnasPresupuesto),
    {
      id: 'literal',
      type: 'campo',
      x: 10,
      y: 238,
      w: 100,
      h: 32,
      bind: ['tienda.literalPresupuesto'],
      props: { fontSizeMm: 2.2, ocultarVacio: true },
    },
    totales('IMPORTE PRESUPUESTO EU'),
    pie,
  ],
}

/** Pedido a proveedor. */
export const plantillaPedidoCompra: DocumentoPlantilla = {
  id: 'pedido-compra-std',
  tipo: 'pedido-compra',
  nombre: 'Pedido de compras',
  descripcion: 'Pedido a proveedor, sin QR, con líneas de compra y totales',
  version: 1,
  page: pageA4,
  blocks: [
    ...cabecera('PEDIDO DE COMPRA', 'Proveedor'),
    ...observaciones(),
    tabla(columnasCompra),
    {
      id: 'literal',
      type: 'campo',
      x: 10,
      y: 238,
      w: 100,
      h: 32,
      bind: ['tienda.literalPedidoCompra'],
      props: { fontSizeMm: 2.2, ocultarVacio: true },
    },
    totales('IMPORTE PEDIDO EU'),
    pie,
  ],
}

/**
 * Misma cabecera que el albarán de venta: el nombre queda junto a la empresa
 * y el logo, a la derecha. No usa el título largo a la derecha.
 */
function cabeceraAlbaran(rotuloTercero: string): PlantillaBloque[] {
  return [
    {
      id: 'titulo',
      type: 'titulo-documento',
      x: 10,
      y: 12,
      w: 90,
      h: 12,
      label: 'ALBARAN',
      bind: [],
      props: { fontSizeMm: 5.2, fontWeight: 'bold', align: 'left' },
    },
    {
      id: 'empresa',
      type: 'empresa-cabecera',
      x: 108,
      y: 10,
      w: 92,
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
      props: { logoDerecha: true },
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
      label: 'ALBARAN',
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
      id: 'tercero',
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
      props: { rotulo: rotuloTercero },
    },
  ]
}

/** Albarán de proveedor: el pedido de compra más el albarán del proveedor. */
export const plantillaAlbaranCompra: DocumentoPlantilla = {
  id: 'albaran-compra-std',
  tipo: 'albaran-compra',
  nombre: 'Albarán de compras',
  descripcion: 'Albarán de proveedor, con su albarán, líneas de compra y totales',
  version: 3,
  page: pageA4,
  blocks: [
    ...cabeceraAlbaran('Proveedor'),
    {
      id: 'su-albaran',
      type: 'campo',
      x: 12,
      y: 57,
      w: 90,
      h: 5,
      label: 'Albarán proveedor',
      bind: ['documento.suPedido'],
      props: { inline: true, ocultarVacio: true },
    },
    ...observaciones(),
    tabla(columnasCompra),
    totales('IMPORTE ALBARÁN EU'),
    pie,
  ],
}

/** Pedido de cliente. */
export const plantillaPedidoCliente: DocumentoPlantilla = {
  id: 'pedido-cliente-std',
  tipo: 'pedido-cliente',
  nombre: 'Pedido de cliente',
  descripcion: 'Pedido de cliente con su pedido, líneas y totales',
  version: 1,
  page: pageA4,
  blocks: [
    ...cabecera('PEDIDO', 'Cliente'),
    {
      id: 'su-pedido',
      type: 'campo',
      x: 12,
      y: 57,
      w: 90,
      h: 5,
      label: 'Su pedido',
      bind: ['documento.suPedido'],
      props: { inline: true, ocultarVacio: true },
    },
    ...observaciones(),
    tabla(columnasVenta),
    totales('IMPORTE PEDIDO EU'),
    pie,
  ],
}

/** Un recibo por página: sin tabla de artículos. */
export const plantillaRecibo: DocumentoPlantilla = {
  id: 'recibo-std',
  tipo: 'recibo',
  nombre: 'Recibo',
  descripcion: 'Recibo de factura a crédito: número, factura, vencimiento e importe',
  version: 1,
  page: pageA4,
  blocks: [
    ...cabecera('RECIBO', 'Cliente'),
    {
      id: 'factura',
      type: 'campo',
      x: 12,
      y: 57,
      w: 90,
      h: 5,
      label: 'Factura',
      bind: ['documento.albaran'],
      props: { inline: true },
    },
    {
      id: 'forma-pago',
      type: 'campo',
      x: 12,
      y: 63,
      w: 90,
      h: 5,
      label: 'Forma de pago',
      bind: ['documento.formaPago'],
      props: { inline: true, ocultarVacio: true },
    },
    {
      id: 'vencimiento',
      type: 'campo',
      x: 12,
      y: 69,
      w: 90,
      h: 5,
      label: 'Vencimiento',
      bind: ['documento.fechaEntrega'],
      props: { inline: true },
    },
    {
      id: 'sep-importe',
      type: 'separador',
      x: 10,
      y: 98,
      w: 190,
      h: 2,
    },
    {
      id: 'importe',
      type: 'campo',
      x: 10,
      y: 112,
      w: 190,
      h: 16,
      label: 'IMPORTE',
      bind: ['totales.importe'],
      props: { format: 'importe', fontSizeMm: 6, fontWeight: 'bold', align: 'right' },
    },
    pie,
  ],
}
