/** Datos del cobro con tarjeta (Redsys TPV-PC) para ticket y comprobante. */
export type ComprobanteTarjetaDatos = {
  aprobada: boolean
  titulo: string
  operacion: string
  importe: number
  moneda: string
  nombreComercio: string
  ciudad: string
  tarjeta: string
  marca: string
  aid: string
  comercio: string
  terminal: string
  arc: string
  /** Código respuesta Redsys (OPE en ticket); no es el pedido de devolución. */
  ope: string
  /** Número de pedido Redsys (`<pedido>`); obligatorio para devolver en datáfono. */
  pedidoRedsys: string
  /** Identificador RTS de la operación original (recomendado en devolución). */
  rts: string
  aut: string
  clr: string
  fecha: string
  hora: string
  literales: string[]
  reciboCliente: boolean
  xml: string
}

function xmlTag(xml: string, names: string[]): string {
  for (const name of names) {
    const re = new RegExp(
      `<${name}(?:\\s[^>]*)?>(?:<!\\[CDATA\\[)?([\\s\\S]*?)(?:\\]\\]>)?</${name}>`,
      'i'
    )
    const m = xml.match(re)
    if (m) return m[1].replace(/<[^>]+>/g, '').trim()
  }
  return ''
}

function ultimosDigitosTarjeta(enmascarada: string): string {
  const digits = enmascarada.replace(/\D/g, '')
  return digits.length >= 4 ? digits.slice(-4) : digits
}

function parseFechaOperacion(raw: string): { fecha: string; hora: string } {
  const t = raw.trim()
  const m = t.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/)
  if (m) {
    return {
      fecha: `${m[3]}/${m[2]}/${m[1].slice(-2)}`,
      hora: `${m[4]}:${m[5]}:${m[6]}`,
    }
  }
  return { fecha: t.slice(0, 10), hora: t.slice(11, 19) }
}

/** Campos para tabla Autorizaciones / devolución datáfono. */
export function autorizacionDesdeXmlRedsys(xml: string, importeEsperado?: number) {
  const c = comprobanteDesdeXmlRedsys(xml, { importeEsperado })
  if (!c) return null
  const body = String(xml ?? '')
  const pedido = xmlTag(body, ['pedido', 'pedidoBase'])
  return {
    pedidoRedsys: pedido,
    identificadorRts: xmlTag(body, ['identificadorRTS']),
    // El mismo AUT que se imprime en el ticket: la devolución lo compara con lo que teclea el cajero.
    // codrespauto es el código de respuesta ("00"), no una autorización.
    autorizacion: c.aut || xmlTag(body, ['codigoAutorizacion']),
    clr: c.clr,
    tarjeta: c.tarjeta,
    importe: c.importe,
    comercio: c.comercio,
    tpv: c.terminal,
    aid: c.aid,
    lbl: c.marca,
    arc: c.arc,
    marcaTarjeta: c.marca.slice(0, 3),
    tipoOperacion: c.operacion,
  }
}

/** Convierte el XML de `ResultOper` en campos imprimibles (formato legacy). */
export function comprobanteDesdeXmlRedsys(
  xml: string,
  contexto: { nombreComercio?: string; ciudad?: string; importeEsperado?: number } = {}
): ComprobanteTarjetaDatos | null {
  const body = String(xml ?? '').trim()
  if (!body) return null

  const errBody = xmlTag(body, ['mensaje', 'descripcion'])
  const errCode = xmlTag(body, ['codigo'])
  if (errCode && /TPV|SOAP|AX-/i.test(errCode) && errBody) {
    return {
      aprobada: false,
      titulo: 'DENEGADA',
      operacion: 'PAGO',
      importe: contexto.importeEsperado ?? 0,
      moneda: 'EUR',
      nombreComercio: contexto.nombreComercio ?? '',
      ciudad: contexto.ciudad ?? '',
      tarjeta: '',
      marca: '',
      aid: '',
      comercio: '',
      terminal: '',
      arc: '',
      ope: errCode,
      pedidoRedsys: '',
      rts: '',
      aut: '',
      clr: '',
      fecha: '',
      hora: '',
      literales: [errBody],
      reciboCliente: true,
      xml: body,
    }
  }

  const resultado = xmlTag(body, ['resultado'])
  const aprobada = /autorizad[ao]/i.test(resultado)
  const tarjeta = xmlTag(body, ['tarjetaClienteRecibo', 'tarjetaComercioRecibo', 'Tarjeta'])
  let tipoPago = xmlTag(body, ['tipoPago', 'tipoOperacion']) || 'PAGO'
  if (/devol/i.test(tipoPago)) tipoPago = 'DEVOLUCION'
  const importeRaw = xmlTag(body, ['importe'])
  const importe =
    importeRaw !== '' ? Number(importeRaw.replace(',', '.')) : (contexto.importeEsperado ?? 0)
  const { fecha, hora } = parseFechaOperacion(xmlTag(body, ['fechaOperacion', 'fecha']))

  const literales: string[] = []
  const litBlock = body.match(/<Literales>([\s\S]*?)<\/Literales>/i)
  if (litBlock) {
    for (const m of litBlock[1].matchAll(/<([A-Za-z0-9_]+)>([\s\S]*?)<\/\1>/g)) {
      const txt = m[2].replace(/<[^>]+>/g, '').trim()
      if (txt) literales.push(txt)
    }
  }

  const ope = xmlTag(body, ['codigoRespuesta', 'codigo'])
  const pedidoRedsys = xmlTag(body, ['pedido', 'pedidoBase'])
  const rts = xmlTag(body, ['identificadorRTS'])
  const aut = xmlTag(body, ['conttrans', 'codigoAutorizacion', 'autorizacion'])

  return {
    aprobada,
    titulo: aprobada ? 'ACEPTADA' : 'DENEGADA',
    operacion: tipoPago.toUpperCase(),
    importe: Number.isFinite(importe) ? importe : 0,
    moneda: 'EUR',
    nombreComercio: contexto.nombreComercio ?? xmlTag(body, ['nombreComercio']),
    ciudad: contexto.ciudad ?? '',
    tarjeta,
    marca: xmlTag(body, ['etiquetaApp', 'marcaTarjeta', 'TxtMarca']),
    aid: xmlTag(body, ['idapp', 'AID', 'DDFName']),
    comercio: xmlTag(body, ['comercio', 'comercioReducido']),
    terminal: xmlTag(body, ['terminal']),
    arc: xmlTag(body, ['codrespauto', 'ARC']),
    ope,
    pedidoRedsys,
    rts,
    aut,
    clr: ultimosDigitosTarjeta(tarjeta),
    fecha,
    hora,
    literales,
    reciboCliente: /true/i.test(xmlTag(body, ['ReciboSoloCliente'])),
    xml: body,
  }
}

/** Font B en papel de 80 mm: caben las 4 columnas de artículo y el recibo a dos columnas. */
export const ANCHO_TICKET = 56

export function centrarTicket(texto: string, ancho = ANCHO_TICKET): string {
  const t = texto.trim().slice(0, ancho)
  const pad = Math.max(0, Math.floor((ancho - t.length) / 2))
  return ' '.repeat(pad) + t
}

function padLinea(izq: string, der: string, ancho = ANCHO_TICKET): string {
  const r = der.slice(0, ancho - 1)
  const l = izq.slice(0, Math.max(0, ancho - r.length - 1))
  const esp = Math.max(1, ancho - l.length - r.length)
  return l + ' '.repeat(esp) + r
}

/** Dos campos en la misma línea, como el recibo legacy (OP/AID, LBL/Trj…). */
function dosColumnas(izq: string, der: string, ancho = ANCHO_TICKET): string {
  const derecha = der.trim()
  const izquierda = izq.trim()
  if (!derecha) return izquierda.slice(0, ancho)
  if (!izquierda) return derecha.slice(0, ancho)
  if (izquierda.length + 1 + derecha.length > ancho) {
    return `${izquierda.slice(0, ancho)}\n${derecha.slice(0, ancho)}`
  }
  return padLinea(izquierda, derecha, ancho)
}

function pushPar(lines: string[], izq: string, der: string) {
  const fila = dosColumnas(izq, der)
  if (!fila.trim()) return
  for (const parte of fila.split('\n')) {
    if (parte.trim()) lines.push(parte)
  }
}

/** Texto del recibo de tarjeta (bloque inferior del ticket legacy). */
export function lineasComprobanteTarjeta(c: ComprobanteTarjetaDatos): string[] {
  const imp = c.importe.toFixed(2).replace('.', ',')
  const lines: string[] = ['', centrarTicket(c.titulo)]
  if (c.nombreComercio) lines.push(`NOMBRE:${c.nombreComercio}`.slice(0, ANCHO_TICKET))
  if (c.ciudad) lines.push(`CIUDAD:${c.ciudad}`.slice(0, ANCHO_TICKET))
  pushPar(lines, `OP.:${c.operacion}`, c.aid ? `AID:${c.aid}` : '')
  pushPar(lines, c.marca ? `LBL:${c.marca.toUpperCase()}` : '', c.tarjeta ? `Trj:${c.tarjeta}` : '')
  pushPar(lines, c.comercio ? `COM:${c.comercio}` : '', c.terminal ? `TRM:${c.terminal}` : '')
  pushPar(lines, c.arc ? `ARC:${c.arc}` : '', c.ope ? `OPE:${c.ope}` : '')
  pushPar(lines, c.aut ? `AUT:${c.aut}` : '', c.clr ? `CLR:${c.clr}` : '')
  pushPar(lines, c.fecha ? `FEC:${c.fecha}` : '', c.hora ? `HOR:${c.hora}` : '')
  pushPar(lines, c.pedidoRedsys ? `PED:${c.pedidoRedsys}` : '', c.rts ? `RTS:${c.rts}` : '')
  lines.push(padLinea('', `${imp} Eur`))
  for (const lit of c.literales) {
    if (lit.trim()) lines.push(lit.trim().slice(0, ANCHO_TICKET))
  }
  if (c.reciboCliente) lines.push(centrarTicket('** RECIBO PARA EL CLIENTE **'))
  return lines
}

/** Justificante independiente que se imprime después del ticket del cliente. */
export function textoComprobanteEstablecimiento(c: ComprobanteTarjetaDatos): string {
  const lineas = lineasComprobanteTarjeta({ ...c, reciboCliente: false })
  lineas.push('', centrarTicket('** COPIA PARA EL ESTABLECIMIENTO **'))
  return lineas.join('\n')
}
