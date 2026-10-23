import type { InformeTicketsResumen, InformeTicketsResult } from '@/api/listados'
import {
  bloqueCierre,
  documento,
  esc,
  fecha,
  filaResumen,
  fragmento,
  type OpcionesPlantillaTickets,
} from './comun'

function tablaDias(
  dias: InformeTicketsResumen[],
  tienda: InformeTicketsResumen,
): string {
  const filas = dias.map((dia) => filaResumen(dia, fecha(dia.fecha))).join('')
  return `<table class="legacy-resumen super-dias">
    <thead><tr><th></th><th>Base</th><th>Iva</th><th>Recargo</th><th>Importe</th></tr></thead>
    <tbody>${filas}${filaResumen(tienda, `Total Tienda ${tienda.empresa}`)}</tbody>
  </table>`
}

function cuerpo(data: InformeTicketsResult): string {
  const tiendas = data.resumenTiendas ?? []
  const dias = data.resumenDiario ?? []
  let html = ''

  for (const tienda of tiendas) {
    html += `<section class="super-tienda">
      <h2 class="legacy-tienda">Tienda ${esc(tienda.empresa)} ${esc(tienda.tiendaNombre)}</h2>
      ${tablaDias(dias.filter((dia) => dia.empresa === tienda.empresa), tienda)}
      ${bloqueCierre(tienda)}
    </section>`
  }

  html += `<section class="super-general">
    <table class="legacy-resumen"><tbody>${filaResumen(data.totales as InformeTicketsResumen, 'Total General')}</tbody></table>
    ${bloqueCierre(data.totales as InformeTicketsResumen)}
  </section>`
  return html
}

const ESTILOS = `
.super-tienda{break-inside:avoid;margin-bottom:3mm}
.super-dias thead th:first-child{background:transparent}
.super-dias thead th{background:var(--legacy-verde);color:#fff;padding:1px 3px}
.super-dias tbody tr:last-child td{font-weight:700}
.super-general{break-inside:avoid}
.super-tienda .legacy-cierre,.super-general .legacy-cierre{margin-top:3mm}
`

export function plantillaSuperResumido(
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
