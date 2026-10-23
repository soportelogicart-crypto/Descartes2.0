import type { InformeTicketsFila, InformeTicketsResumen, InformeTicketsResult } from '@/api/listados'
import {
  bloqueCierre,
  documento,
  esc,
  fecha,
  filaResumen,
  fragmento,
  num,
  perfilDia,
  tablaIvaDoble,
  tablaPagos,
  type OpcionesPlantillaTickets,
} from './comun'

function filasTicket(ticket: InformeTicketsFila): string {
  const pago1 = Number(ticket.impFpago1 ?? 0)
  const pago2 = Number(ticket.impFpago2 ?? 0)
  const importePago1 =
    (ticket.fpago1 || ticket.formaPago) && !ticket.fpago2 && pago1 === 0 && pago2 === 0
      ? ticket.importe
      : pago1
  const desgloses = ticket.desgloseIva?.length
    ? ticket.desgloseIva
    : [{
        base: ticket.base ?? ticket.importe,
        pjeIva: 0,
        iva: ticket.iva ?? 0,
        pjeRecargo: 0,
        recargo: ticket.recargo ?? 0,
      }]
  return desgloses.map((iva, indice) => `<tr>
    <td>${indice === 0 ? esc(ticket.numeroTicket) : ''}</td>
    <td>${indice === 0 ? esc(fecha(ticket.fecha)) : ''}</td>
    <td>${indice === 0 ? esc(ticket.fpago1 || ticket.formaPago) : ''}</td>
    <td class="n">${indice === 0 ? num(importePago1) : ''}</td>
    <td>${indice === 0 ? esc(ticket.fpago2) : ''}</td>
    <td class="n">${indice === 0 ? num(ticket.impFpago2) : ''}</td>
    <td class="n">${num(iva.base)}</td>
    <td class="n">${num(iva.pjeIva)}</td>
    <td class="n">${num(iva.iva)}</td>
    <td class="n">${num(iva.pjeRecargo)}</td>
    <td class="n">${num(iva.recargo)}</td>
    <td class="n">${indice === 0 ? num(ticket.importe) : ''}</td>
  </tr>`).join('')
}

function cabeceraTabla(): string {
  return `<table class="desglose-tabla">
    <colgroup>
      <col style="width:11%"/><col style="width:12%"/>
      <col style="width:6%"/><col style="width:8%"/>
      <col style="width:6%"/><col style="width:8%"/>
      <col style="width:9%"/><col style="width:6%"/>
      <col style="width:8%"/><col style="width:7%"/>
      <col style="width:8%"/><col style="width:11%"/>
    </colgroup>
    <thead><tr>
      <th>Ticket</th><th>Fecha</th><th colspan="2">F.Pago 1</th><th colspan="2">F.Pago 2</th>
      <th>Base</th><th>% Iva</th><th>Iva</th><th>% Recar</th><th>Recargo</th><th>Importe</th>
    </tr></thead><tbody>`
}

/** Fila de cierre del día alineada con las columnas de los tickets. */
function filaTotalDia(resumen: InformeTicketsResumen): string {
  return `<tr class="total-dia">
    <td colspan="6">Total Día ${esc(fecha(resumen.fecha))}</td>
    <td class="n raya">${num(resumen.base)}</td><td></td>
    <td class="n raya">${num(resumen.iva)}</td><td></td>
    <td class="n raya">${num(resumen.recargo)}</td>
    <td class="n raya">${num(resumen.importe)}</td>
  </tr>`
}

function cajasDia(
  resumen: InformeTicketsResumen,
  perfilTienda: InformeTicketsResumen,
): string {
  return `<div class="desglose-dia-cajas legacy-dia">
    <div>${tablaIvaDoble(resumen)}${tablaPagos(resumen, perfilTienda, true)}</div>
    ${perfilDia(resumen)}
  </div>`
}

function cuerpo(data: InformeTicketsResult): string {
  const tiendas = data.resumenTiendas ?? []
  const dias = data.resumenDiario ?? []
  let html = ''

  for (const tienda of tiendas) {
    html += `<section class="desglose-tienda"><h2 class="legacy-tienda">Tienda ${esc(tienda.empresa)} ${esc(tienda.tiendaNombre)}</h2>`
    const diasTienda = dias.filter((dia) => dia.empresa === tienda.empresa)
    for (const dia of diasTienda) {
      const tickets = data.items.filter(
        (ticket) => ticket.empresa === tienda.empresa && ticket.fecha === dia.fecha,
      )
      html += cabeceraTabla()
      for (const ticket of tickets) html += filasTicket(ticket)
      html += `${filaTotalDia(dia)}</tbody></table>${cajasDia(dia, tienda)}`
    }
    html += `<div class="legacy-total"><table class="legacy-resumen"><tbody>${filaResumen(tienda, `Total Tienda ${tienda.empresa}`)}</tbody></table>${bloqueCierre(tienda)}</div></section>`
  }

  html += `<div class="legacy-total"><table class="legacy-resumen"><tbody>${filaResumen(data.totales as InformeTicketsResumen, 'Total General')}</tbody></table>${bloqueCierre(data.totales as InformeTicketsResumen)}</div>`
  return html
}

const ESTILOS = `
.desglose-tabla{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7pt;margin-bottom:2mm}
.desglose-tabla th{background:var(--legacy-verde);color:#fff;padding:1px 2px;white-space:nowrap;overflow:hidden}
.desglose-tabla td{padding:1.2mm 2px 0;white-space:nowrap;overflow:hidden;vertical-align:top}
.desglose-dia-cajas{display:grid;grid-template-columns:minmax(0,1.85fr) minmax(0,1fr);gap:1.5mm;margin:1mm 0 0}
.desglose-dia-cajas>div>.legacy-box{border-right:0}
.desglose-dia-cajas .iva-doble{border-bottom:0}
.desglose-tabla .total-dia td{font-weight:700;padding-top:2mm}
.desglose-tabla .total-dia .raya{border-top:1px solid #111}
`

export function plantillaDesglosado(
  data: InformeTicketsResult,
  opciones: OpcionesPlantillaTickets = {},
  soloFragmento = false,
): string {
  const titulo = `Informe de Tickets ${data.divisa || 'EU'}`
  const contenido = `<style>${ESTILOS}</style>${cuerpo(data)}`
  return soloFragmento
    ? fragmento(data, titulo, contenido, opciones)
    : documento(data, titulo, contenido, opciones)
}
