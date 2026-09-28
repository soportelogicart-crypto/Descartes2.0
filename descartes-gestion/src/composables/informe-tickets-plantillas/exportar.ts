import type { InformeTicketsResult } from '@/api/listados'
import {
  documento,
  esc,
  fecha,
  fragmento,
  num,
  resumenGeneral,
  tablaIva,
  type OpcionesPlantillaTickets,
} from './comun'

function filas(data: InformeTicketsResult) {
  if (data.exportar?.length) return data.exportar
  return data.items.flatMap((ticket) => {
    const desgloses = ticket.desgloseIva?.length
      ? ticket.desgloseIva
      : [{
          base: ticket.base ?? ticket.importe,
          pjeIva: 0,
          iva: ticket.iva ?? 0,
          pjeRecargo: 0,
          recargo: ticket.recargo ?? 0,
          importe: ticket.importe,
        }]
    return desgloses.map((iva) => ({
      empresa: ticket.empresa,
      tiendaNombre: ticket.tiendaNombre ?? '',
      fecha: ticket.fecha,
      numeroTicket: ticket.numeroTicket ?? 0,
      tipo: 'Ticket',
      cliente: ticket.cliente,
      razonSocial: ticket.razonSocial,
      nif: ticket.nif ?? '',
      ...iva,
    }))
  })
}

function cuerpo(data: InformeTicketsResult): string {
  const detalle = filas(data).map((fila) => `<tr>
    <td>${esc(fila.tipo || 'Ticket')}</td>
    <td>${esc(fila.numeroTicket)}</td>
    <td>${esc(fecha(fila.fecha, true))}</td>
    <td>${esc(fila.nif)}</td>
    <td class="n">${num(fila.base)}</td>
    <td class="n">${num(fila.pjeIva)}</td>
    <td class="n">${num(fila.iva)}</td>
    <td class="n">${num(fila.recargo)}</td>
    <td class="n">${num(fila.importe)}</td>
  </tr>`).join('')

  return `<table class="exportar-tabla">
    <thead><tr>
      <th>Tipo</th><th></th><th></th><th>NIF</th><th>Base</th><th></th><th>Iva</th><th>Recarg</th><th>Importe</th>
    </tr></thead>
    <tbody>${detalle}</tbody>
  </table>
  <div class="exportar-total-iva">${tablaIva(resumenGeneral(data))}</div>`
}

const ESTILOS = `
.exportar-tabla{width:100%;border-collapse:collapse;font-size:7.3pt}
.exportar-tabla th{background:var(--legacy-verde);color:#fff;padding:1px 3px}
.exportar-tabla th:empty{background:transparent}
.exportar-tabla td{padding:1px 3px;white-space:nowrap}
.exportar-tabla td:nth-child(1){width:8%}
.exportar-tabla td:nth-child(2){width:10%}
.exportar-tabla td:nth-child(3){width:10%}
.exportar-tabla td:nth-child(4){width:27%}
.exportar-tabla td:nth-child(5){width:8%}
.exportar-tabla td:nth-child(6){width:6%}
.exportar-tabla td:nth-child(7){width:8%}
.exportar-tabla td:nth-child(8){width:11%}
.exportar-tabla td:nth-child(9){width:10%}
.exportar-total-iva{width:37%;margin:5mm 0 0 2mm;break-inside:avoid}
.exportar-total-iva .legacy-box{border:0}
`

export function plantillaExportar(
  data: InformeTicketsResult,
  opciones: OpcionesPlantillaTickets = {},
  soloFragmento = false,
): string {
  const titulo = `Diario IVA ${data.divisa || 'EU'}`
  const contenido = `<style>${ESTILOS}</style>${cuerpo(data)}`
  return soloFragmento
    ? fragmento(data, titulo, contenido, opciones)
    : documento(data, titulo, contenido, opciones)
}
