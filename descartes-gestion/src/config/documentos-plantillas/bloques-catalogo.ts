import type { PlantillaBloque, PlantillaBloqueTipo } from './types'

export type BloqueCatalogoGrupo = 'cabecera' | 'cuerpo' | 'pie'

export type BloqueCatalogoItem = {
  type: PlantillaBloqueTipo
  nombre: string
  /** Si true, solo puede haber uno de este tipo en la plantilla. */
  unico?: boolean
  /** Agrupa el botón en el diseñador A4. */
  grupo?: BloqueCatalogoGrupo
}

export const CATALOGO_GRUPOS_A4: { id: BloqueCatalogoGrupo; titulo: string }[] = [
  { id: 'cabecera', titulo: 'Cabecera' },
  { id: 'cuerpo', titulo: 'Cuerpo' },
  { id: 'pie', titulo: 'Pie' },
]

export const CATALOGO_BLOQUES: BloqueCatalogoItem[] = [
  { type: 'emblema', nombre: 'Emblema', unico: true, grupo: 'cabecera' },
  { type: 'qr-verifactu', nombre: 'QR tributario', unico: true, grupo: 'cabecera' },
  { type: 'empresa-cabecera', nombre: 'Empresa', unico: true, grupo: 'cabecera' },
  { type: 'titulo-documento', nombre: 'Título', grupo: 'cabecera' },
  { type: 'bloque-cliente', nombre: 'Cliente', unico: true, grupo: 'cabecera' },
  { type: 'bloque-meta', nombre: 'Datos documento', grupo: 'cabecera' },
  { type: 'codigo-barras', nombre: 'Código de barras', unico: true, grupo: 'cabecera' },
  { type: 'tabla-lineas', nombre: 'Tabla de líneas', unico: true, grupo: 'cuerpo' },
  { type: 'campo', nombre: 'Campo', grupo: 'cuerpo' },
  { type: 'texto', nombre: 'Texto fijo', grupo: 'cuerpo' },
  { type: 'separador', nombre: 'Separador', grupo: 'cuerpo' },
  { type: 'totales-iva', nombre: 'Totales IVA', unico: true, grupo: 'pie' },
  { type: 'vencimientos', nombre: 'Vencimientos', unico: true, grupo: 'pie' },
  { type: 'datos-bancarios', nombre: 'Datos bancarios', unico: true, grupo: 'pie' },
  { type: 'pie', nombre: 'Pie de página', unico: true, grupo: 'pie' },
]

/** Catálogo reducido para ticket térmico 80 mm. */
export const CATALOGO_BLOQUES_TICKET: BloqueCatalogoItem[] = [
  { type: 'empresa-cabecera', nombre: 'Empresa', unico: true },
  { type: 'titulo-documento', nombre: 'Título' },
  { type: 'bloque-meta', nombre: 'Datos ticket' },
  { type: 'tabla-lineas', nombre: 'Líneas', unico: true },
  { type: 'totales-ticket', nombre: 'Totales', unico: true },
  { type: 'literales-puesto', nombre: 'Literales puesto', unico: true },
  { type: 'texto', nombre: 'Texto fijo' },
  { type: 'separador', nombre: 'Separador' },
  { type: 'campo', nombre: 'Campo' },
]

export const CAMPOS_BIND_SUGERIDOS = [
  'empresa.nombre',
  'empresa.razonSocial',
  'empresa.nif',
  'empresa.direccion',
  'empresa.cp',
  'empresa.poblacion',
  'empresa.provincia',
  'empresa.telefono',
  'empresa.email',
  'empresa.emblemaUrl',
  'empresa.banco',
  'empresa.iban',
  'empresa.swift',
  'tienda.literalFacturaDiferida',
  'tienda.literalFacturaContado',
  'tienda.literalPresupuesto',
  'tienda.literalVale',
  'tienda.literalTicket',
  'puesto.literales',
  'cliente.codigo',
  'cliente.nombre',
  'cliente.direccion',
  'cliente.cp',
  'cliente.poblacion',
  'cliente.provincia',
  'cliente.pais',
  'cliente.cif',
  'cliente.telefono',
  'documento.numero',
  'documento.serie',
  'documento.fecha',
  'documento.suPedido',
  'documento.albaran',
  'documento.terminalSesion',
  'documento.atendidoPor',
  'documento.fechaEntrega',
  'documento.transportista',
  'documento.portes',
  'documento.observaciones',
  'documento.codigoBarras',
  'documento.pagina',
  'articulo.codigo',
  'articulo.descripcion',
  'articulo.ean',
  'articulo.precio',
  'articulo.lote',
  'lineas',
  'totales.base',
  'totales.ivas',
  'totales.importe',
  'vencimientos',
  'verifactu.qrPayload',
  'verifactu.url',
]

/** Catálogo reducido para etiquetas de artículo (format label). */
export const CATALOGO_BLOQUES_ETIQUETA: BloqueCatalogoItem[] = [
  { type: 'campo', nombre: 'Campo' },
  { type: 'texto', nombre: 'Texto fijo' },
  { type: 'codigo-barras', nombre: 'Código de barras EAN', unico: true },
  { type: 'separador', nombre: 'Separador' },
]

let seq = 0
function nextId(prefix: string): string {
  seq += 1
  return `${prefix}-${Date.now().toString(36)}-${seq}`
}

export function etiquetaTipoBloque(type: PlantillaBloqueTipo): string {
  return (
    CATALOGO_BLOQUES.find((c) => c.type === type)?.nombre ??
    CATALOGO_BLOQUES_TICKET.find((c) => c.type === type)?.nombre ??
    CATALOGO_BLOQUES_ETIQUETA.find((c) => c.type === type)?.nombre ??
    type
  )
}

export function crearBloquePorTipo(type: PlantillaBloqueTipo): PlantillaBloque {
  switch (type) {
    case 'emblema':
      return {
        id: 'emblema',
        type,
        x: 10,
        y: 10,
        w: 28,
        h: 28,
        label: 'Emblema',
        bind: ['empresa.emblemaUrl'],
        props: { fit: 'contain' },
      }
    case 'empresa-cabecera':
      return {
        id: 'empresa',
        type,
        x: 42,
        y: 10,
        w: 70,
        h: 32,
        bind: [
          'empresa.nombre',
          'empresa.direccion',
          'empresa.cp',
          'empresa.poblacion',
          'empresa.provincia',
          'empresa.telefono',
          'empresa.email',
        ],
      }
    case 'titulo-documento':
      return {
        id: nextId('titulo'),
        type,
        x: 120,
        y: 12,
        w: 75,
        h: 14,
        label: 'DOCUMENTO',
        bind: ['documento.numero', 'documento.serie'],
      }
    case 'bloque-cliente':
      return {
        id: 'cliente',
        type,
        x: 10,
        y: 48,
        w: 95,
        h: 42,
        bind: [
          'cliente.codigo',
          'cliente.nombre',
          'cliente.direccion',
          'cliente.cp',
          'cliente.poblacion',
          'cliente.provincia',
          'cliente.pais',
          'cliente.cif',
        ],
      }
    case 'bloque-meta':
      return {
        id: nextId('meta'),
        type,
        x: 120,
        y: 30,
        w: 75,
        h: 30,
        bind: ['documento.fecha', 'documento.albaran', 'documento.atendidoPor', 'documento.terminalSesion'],
      }
    case 'campo':
      return {
        id: nextId('campo'),
        type,
        x: 10,
        y: 100,
        w: 80,
        h: 14,
        label: 'Campo',
        bind: ['documento.observaciones'],
      }
    case 'texto':
      return {
        id: nextId('texto'),
        type,
        x: 10,
        y: 100,
        w: 60,
        h: 12,
        label: 'Texto',
      }
    case 'codigo-barras':
      return {
        id: 'barcode',
        type,
        x: 10,
        y: 104,
        w: 70,
        h: 14,
        bind: ['documento.codigoBarras'],
      }
    case 'tabla-lineas':
      return {
        id: 'lineas',
        type,
        x: 10,
        y: 120,
        w: 190,
        h: 90,
        bind: ['lineas'],
        columns: [
          { key: 'articulo', label: 'Artículo', width: 28 },
          { key: 'descripcion', label: 'Descripción', width: 52 },
          { key: 'unidades', label: 'Unidades', width: 18 },
          { key: 'precioSinIva', label: 'Precio', width: 24 },
          { key: 'dto', label: 'Dto', width: 14 },
          { key: 'pjeIva', label: '%IVA', width: 14 },
          { key: 'importe', label: 'Importe', width: 22 },
        ],
      }
    case 'totales-iva':
      return {
        id: 'totales',
        type,
        x: 110,
        y: 230,
        w: 90,
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
        props: { etiquetaTotal: 'IMPORTE EU' },
      }
    case 'datos-bancarios':
      return {
        id: 'banco',
        type,
        x: 10,
        y: 90,
        w: 185,
        h: 16,
        bind: ['empresa.banco', 'empresa.iban', 'empresa.swift'],
      }
    case 'vencimientos':
      return {
        id: 'vencimientos',
        type,
        x: 50,
        y: 230,
        w: 55,
        h: 28,
        bind: ['vencimientos'],
      }
    case 'qr-verifactu':
      return {
        id: 'qr-tributario',
        type,
        x: 10,
        y: 10,
        w: 28,
        h: 28,
        label: 'QR tributario',
        bind: ['verifactu.qrPayload', 'verifactu.url'],
        props: { quietZoneMm: 1, errorCorrection: 'M', rotulo: 'VERI*FACTU' },
      }
    case 'pie':
      return {
        id: 'pie',
        type,
        x: 10,
        y: 275,
        w: 190,
        h: 10,
        bind: ['empresa.razonSocial', 'empresa.nif', 'documento.pagina'],
        props: {
          plantilla: '{{empresa.razonSocial}} N.I.F.{{empresa.nif}}  Página {{documento.pagina}}',
        },
      }
    case 'totales-ticket':
      return {
        id: 'totales-ticket',
        type,
        x: 2,
        y: 100,
        w: 76,
        h: 18,
        bind: ['totales.base', 'totales.ivas', 'totales.importe'],
      }
    case 'literales-puesto':
      return {
        id: 'literales-puesto',
        type,
        x: 2,
        y: 120,
        w: 76,
        h: 28,
        label: 'Literales del puesto',
        bind: ['puesto.literales', 'tienda.literalTicket'],
        props: { centrado: true },
      }
    case 'separador':
      return {
        id: nextId('sep'),
        type,
        x: 2,
        y: 50,
        w: 76,
        h: 3,
        label: '--------------------------------',
      }
    default:
      return {
        id: nextId('bloque'),
        type,
        x: 10,
        y: 10,
        w: 40,
        h: 12,
        label: type,
      }
  }
}
