import type { DocumentoPlantilla } from '@/config/documentos-plantillas'
import {
  datosPreviewPorTipo,
  formatImporte,
  getByPath,
  type DocumentoPreviewDatos,
  type DocumentoPreviewLinea,
} from '@/config/documentos-plantillas/preview-datos'
import {
  ANCHO_TICKET,
  centrarTicket,
  lineasComprobanteTarjeta,
  type ComprobanteTarjetaDatos,
} from '@/composables/comprobanteTarjeta'

function str(datos: DocumentoPreviewDatos, path: string): string {
  const v = getByPath(datos, path)
  return v == null ? '' : String(v)
}

function padLinea(izq: string, der: string, ancho = ANCHO_TICKET): string {
  const l = izq.slice(0, ancho - 1)
  const r = der.slice(0, ancho - 1)
  const esp = Math.max(1, ancho - l.length - r.length)
  return l + ' '.repeat(esp) + r
}

function importeTicket(n: number, decimales: number): string {
  return n.toFixed(decimales).replace('.', ',')
}

const SEPARADOR_TICKET = '-'.repeat(ANCHO_TICKET)

/** Anchos de CANT. | ARTICULO | PVP | DTO | TOTAL; suman ANCHO_TICKET con los espacios. */
const COL = { cant: 5, pvp: 7, dto: 5, total: 8 }
const COL_ARTICULO = ANCHO_TICKET - COL.cant - COL.pvp - COL.dto - COL.total - 4

function filaArticulo(cant: string, articulo: string, pvp: string, dto: string, total: string) {
  return [
    cant.slice(0, COL.cant).padEnd(COL.cant),
    articulo.slice(0, COL_ARTICULO).padEnd(COL_ARTICULO),
    pvp.padStart(COL.pvp),
    dto.padStart(COL.dto),
    total.padStart(COL.total),
  ].join(' ')
}

function cantidadTicket(n: number): string {
  return Number.isInteger(n) ? String(n) : importeTicket(n, 2)
}

/** Cabecera y filas de artículo en 5 columnas: CANT. ARTICULO PVP DTO TOTAL. */
export function lineasTablaArticulos(lineas: DocumentoPreviewLinea[]): string[] {
  const out: string[] = [filaArticulo('CANT.', 'ARTICULO', 'PVP', 'DTO', 'TOTAL')]
  for (const ln of lineas) {
    if (ln.nota) {
      out.push(ln.nota.slice(0, ANCHO_TICKET))
      continue
    }
    if (ln.albaranCabecera) {
      out.push(ln.albaranCabecera.slice(0, ANCHO_TICKET))
      continue
    }
    if (ln.articulo) out.push(' '.repeat(COL.cant + 1) + String(ln.articulo).slice(0, COL_ARTICULO))
    const dto = Number(ln.dto) || 0
    out.push(
      filaArticulo(
        cantidadTicket(Number(ln.unidades) || 0),
        ln.descripcion,
        importeTicket(Number(ln.precio ?? ln.pvp ?? ln.importe) || 0, 3),
        dto ? importeTicket(dto, 2) : '',
        importeTicket(Number(ln.importe) || 0, 2)
      )
    )
  }
  return out
}

/** La plantilla ya suele traer un separador justo antes de la tabla o los totales. */
function pushSeparador(lines: string[]) {
  if (lines[lines.length - 1] !== SEPARADOR_TICKET) lines.push(SEPARADOR_TICKET)
}

/** Venta rápida sin cliente real (legacy ZZZZZZZZZ). */
function esClienteContado(d: DocumentoPreviewDatos): boolean {
  const cod = str(d, 'cliente.codigo').trim().toUpperCase()
  return cod === '' || /^Z+$/.test(cod)
}

export function lineasClienteTicket(d: DocumentoPreviewDatos): string[] {
  if (esClienteContado(d)) return ['CLIENTE DE CONTADO']
  const out = [
    `CLIENTE:${str(d, 'cliente.codigo')} ${str(d, 'cliente.nombre')}`.trim().slice(0, ANCHO_TICKET),
  ]
  if (str(d, 'cliente.cif')) out.push(`NIF: ${str(d, 'cliente.cif')}`)
  return out
}

/** Nombre y datos fiscales de la tienda, centrados como en legacy. */
export function lineasCabeceraEmpresa(d: DocumentoPreviewDatos): string[] {
  const provincia = str(d, 'empresa.provincia').trim()
  const localidad = `${str(d, 'empresa.cp')} ${str(d, 'empresa.poblacion')}`.trim()
  return [
    str(d, 'empresa.nombre'),
    str(d, 'empresa.nif') ? `NIF : ${str(d, 'empresa.nif')}` : '',
    str(d, 'empresa.direccion'),
    provincia ? `${localidad} ( ${provincia} )` : localidad,
  ]
    .filter((l) => l.trim())
    .map((l) => centrarTicket(l))
}

/** En ticket térmico el documento es siempre Factura Simplificada. */
export function lineasTituloTicket(d: DocumentoPreviewDatos, label?: string): string[] {
  const etiqueta = !label || /^ticket$/i.test(label.trim()) ? 'FACTURA SIMPLIFICADA' : label
  return [`${etiqueta} ${str(d, 'documento.numero')}`.trim()]
}

/** ALBARAN n FECHA dd/mm/aaaa hh:mm en una sola línea, luego cajero y cliente. */
export function lineasMetaTicket(d: DocumentoPreviewDatos): string[] {
  const fecha = str(d, 'documento.fecha')
  const hora = fecha.includes(':') ? '' : str(d, 'documento.hora')
  const albaran = str(d, 'documento.albaran')
  const prefijo = albaran && albaran !== str(d, 'documento.numero') ? `ALBARAN ${albaran}  ` : ''
  const out = [`${prefijo}FECHA ${fecha} ${hora}`.trim()]
  if (str(d, 'documento.atendidoPor')) out.push(`CAJERO ${str(d, 'documento.atendidoPor')}`)
  return [...out, ...lineasClienteTicket(d)]
}

function subtotalLineas(d: DocumentoPreviewDatos): number {
  return d.lineas.reduce((s, ln) => s + (Number(ln.importe) || 0), 0)
}

/** Marca interna: la impresora térmica imprime el logo antes del texto si hay URL. */
export const TICKET_MARCA_EMBLEMA = '@@EMBLEMA@@'
/** Prefijos de línea que Electron (escpos-encode.js) convierte en fuente grande / Code 128. */
export const TICKET_MARCA_GRANDE = '@@GRANDE@@'
export const TICKET_MARCA_DOBLE = '@@DOBLE@@'
export const TICKET_MARCA_BARRAS = '@@BARRAS@@'
/** Columnas en Font A (grande, 80 mm); la línea TOTAL va a doble alto con el mismo ancho. */
export const ANCHO_TICKET_GRANDE = 42

function importeCorto(n: number): string {
  return formatImporte(n).replace(/\s/g, '')
}

/** Apartado de totales en fuente grande; la línea TOTAL, a doble alto. */
export function lineasTotalesTicket(
  d: DocumentoPreviewDatos
): { tipo: 'grande' | 'doble'; texto: string }[] {
  const g = (izq: string, der: string) => ({
    tipo: 'grande' as const,
    texto: padLinea(izq, der, ANCHO_TICKET_GRANDE),
  })
  const out: { tipo: 'grande' | 'doble'; texto: string }[] = []
  const sub = subtotalLineas(d)
  if (sub > 0) out.push(g('SUBTOTAL', importeCorto(sub)))
  for (const iva of d.totales.ivas) {
    out.push(g('BASE IVA', importeCorto(iva.base)))
    out.push(
      g(`IVA ${iva.pje.toFixed(2).replace('.', ',')} (${importeCorto(iva.base)})`, importeCorto(iva.cuota))
    )
  }
  if (Number(d.totales.descuentoFidelizacion) > 0) {
    out.push(g('Dto. fidelizacion', `-${importeCorto(Number(d.totales.descuentoFidelizacion))}`))
  }
  out.push({
    tipo: 'doble',
    texto: padLinea('TOTAL', importeCorto(d.totales.importe), ANCHO_TICKET_GRANDE),
  })
  const pagos = (d.documento.pagos ?? []).filter((p) => Math.abs(p.importe) > 0.004)
  if (pagos.length > 1) {
    for (const p of pagos) {
      out.push(g(`ENTREGA ${p.codigo}`, importeCorto(p.importe)))
    }
  } else {
    const fp = str(d, 'documento.formaPago')
    if (fp) {
      const importe = pagos.length === 1 ? pagos[0].importe : d.totales.importe
      out.push(g(fp.toUpperCase().startsWith('ENTREGA') ? fp : `ENTREGA ${fp}`, importeCorto(importe)))
    }
  }
  if (d.fidelizacion) {
    out.push({ tipo: 'grande', texto: '' })
    out.push(g('Puntos de esta compra', importeCorto(d.fidelizacion.puntosCompra)))
    out.push(g('Puntos acumulados', importeCorto(d.fidelizacion.puntosAcumulados)))
  }
  return out
}

/** Valor a codificar en Code 128 (sin los asteriscos de Code 39). */
export function valorCodigoBarrasTicket(d: DocumentoPreviewDatos): string {
  return str(d, 'documento.codigoBarras').replace(/\*/g, '').trim()
}

/** Genera texto plano del ticket (para ESC/POS) a partir de la plantilla + datos de preview/venta. */
export function textoTicketDesdePlantilla(
  plantilla: DocumentoPlantilla,
  datos?: DocumentoPreviewDatos
): string {
  const d = datos ?? datosPreviewPorTipo(plantilla.tipo)
  const blocks = [...plantilla.blocks].sort((a, b) => a.y - b.y || a.x - b.x)
  const lines: string[] = []
  const tieneEmblema = blocks.some((b) => b.type === 'emblema')
  const urlEmblema = str(d, 'empresa.emblemaUrl').trim()

  for (const b of blocks) {
    switch (b.type) {
      case 'emblema':
        if (tieneEmblema && urlEmblema) {
          lines.push(TICKET_MARCA_EMBLEMA)
        }
        break
      case 'empresa-cabecera':
        lines.push(...lineasCabeceraEmpresa(d))
        break
      case 'titulo-documento':
        lines.push(...lineasTituloTicket(d, b.label))
        break
      case 'bloque-meta':
        lines.push(...lineasMetaTicket(d))
        break
      case 'tabla-lineas':
        pushSeparador(lines)
        lines.push(...lineasTablaArticulos(d.lineas))
        break
      case 'totales-ticket':
      case 'totales-iva':
        pushSeparador(lines)
        for (const t of lineasTotalesTicket(d)) {
          lines.push((t.tipo === 'doble' ? TICKET_MARCA_DOBLE : TICKET_MARCA_GRANDE) + t.texto)
        }
        break
      case 'literales-puesto': {
        const lits = Array.isArray(d.puesto?.literales) ? d.puesto.literales : []
        for (const lit of lits) {
          if (String(lit).trim()) lines.push(centrarTicket(String(lit)))
        }
        break
      }
      case 'codigo-barras': {
        const code = valorCodigoBarrasTicket(d)
        if (code) {
          lines.push('')
          lines.push(TICKET_MARCA_BARRAS + code)
        }
        break
      }
      case 'comprobante-tarjeta': {
        const t = d.tarjeta as ComprobanteTarjetaDatos | null | undefined
        if (t) {
          pushSeparador(lines)
          lines.push(...lineasComprobanteTarjeta(t))
        }
        break
      }
      case 'separador':
        if (!b.label || /^-+$/.test(b.label.trim())) pushSeparador(lines)
        else lines.push(b.label)
        break
      case 'texto':
        if (b.label) lines.push(b.label)
        break
      case 'campo':
        lines.push(`${b.label || ''}: ${str(d, (b.bind && b.bind[0]) || '')}`.trim())
        break
      default:
        break
    }
  }

  return lines.join('\n')
}
