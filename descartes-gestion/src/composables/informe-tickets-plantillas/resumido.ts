import type { InformeTicketsResumen, InformeTicketsResult } from '@/api/listados'
import {
  bloqueCierre,
  documento,
  esc,
  fecha,
  filaResumen,
  fragmento,
  perfilDia,
  tablaIvaDoble,
  tablaPagos,
  type OpcionesPlantillaTickets,
} from './comun'

function bloqueDia(
  dia: InformeTicketsResumen,
  tienda: InformeTicketsResumen,
): string {
  return `<section class="resumido-dia legacy-dia">
    <table class="legacy-resumen"><tbody>${filaResumen(dia, fecha(dia.fecha))}</tbody></table>
    <div class="resumido-dia-cajas">
      <div>${tablaIvaDoble(dia)}${tablaPagos(dia, tienda)}</div>
      ${perfilDia(dia)}
    </div>
  </section>`
}

function cuerpo(data: InformeTicketsResult): string {
  const tiendas = data.resumenTiendas ?? []
  const dias = data.resumenDiario ?? []
  let html = ''

  for (const tienda of tiendas) {
    html += `<section class="resumido-tienda">
      <h2 class="legacy-tienda">Tienda ${esc(tienda.empresa)} ${esc(tienda.tiendaNombre)}</h2>
      <table class="legacy-resumen resumido-cabeceras"><thead><tr>
        <th></th><th>Base</th><th>Iva</th><th>Recargo</th><th>Importe</th>
      </tr></thead></table>`
    for (const dia of dias.filter((fila) => fila.empresa === tienda.empresa)) {
      html += bloqueDia(dia, tienda)
    }
    html += `<div class="legacy-total">
      <table class="legacy-resumen"><tbody>${filaResumen(tienda, `Total Tienda ${tienda.empresa}`)}</tbody></table>
      ${bloqueCierre(tienda)}
    </div></section>`
  }

  html += `<div class="legacy-total total-general">
    <table class="legacy-resumen"><tbody>${filaResumen(data.totales as InformeTicketsResumen, 'Total General')}</tbody></table>
    ${bloqueCierre(data.totales as InformeTicketsResumen)}
  </div>`
  return html
}

const ESTILOS = `
.resumido-cabeceras{margin-bottom:1mm}
.resumido-cabeceras th:first-child{background:transparent}
.resumido-dia-cajas{display:grid;grid-template-columns:70% 30%;gap:0;margin:1mm 1mm 0}
.resumido-dia-cajas>div>.legacy-box{border-right:0}
.resumido-dia-cajas .iva-doble{border-bottom:0}
.resumido-dia .legacy-resumen td:first-child{font-size:8pt}
.resumido-tienda{break-before:auto}
`

export function plantillaResumido(
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
