import type { AbcVentasGrupo } from '@/types/ventas'

const PALETA = ['#2563eb', '#ca8a04', '#16a34a', '#0891b2', '#9333ea', '#ea580c', '#db2777', '#0d9488']

function techoEscala(max: number): number {
  if (max <= 0) return 1
  const pot = 10 ** Math.floor(Math.log10(max))
  const norm = max / pot
  const mult = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10
  return mult * pot
}

function etiquetaEjeY(valor: number): string {
  if (valor >= 1_000_000) return `${(valor / 1_000_000).toLocaleString('es-ES', { maximumFractionDigits: 1 })}M`
  if (valor >= 1000) return `${(valor / 1000).toLocaleString('es-ES', { maximumFractionDigits: 0 })}K`
  return valor.toLocaleString('es-ES', { maximumFractionDigits: 0 })
}

function etiquetaFranjaCorta(codigo: string): string {
  const m = /^(\d{2}):(\d{2})/.exec(codigo.trim())
  if (m) return `${m[1]}:${m[2]}`
  return codigo.length > 8 ? `${codigo.slice(0, 8)}…` : codigo
}

export function computeAbcHorasGrafico(
  grupos: AbcVentasGrupo[],
  graficoPor: 'importe' | 'unidades' = 'importe',
) {
  const G = { ancho: 520, alto: 220, izquierda: 48, derecha: 12, arriba: 12, abajo: 36 }
  const valores = grupos.map((g) =>
    graficoPor === 'unidades' ? (g.totales.unidades ?? 0) : (g.totales.importe ?? 0),
  )
  const maxVal = Math.max(0, ...valores)
  const techo = techoEscala(maxVal)
  const x0 = G.izquierda
  const y0 = G.alto - G.abajo
  const anchoUtil = G.ancho - G.izquierda - G.derecha
  const altoUtil = y0 - G.arriba
  const n = Math.max(grupos.length, 1)
  const paso = anchoUtil / n
  const anchoBarra = Math.min(28, paso * 0.72)

  const altura = (v: number) => (v <= 0 || techo <= 0 ? 0 : (v / techo) * altoUtil)

  return {
    ancho: G.ancho,
    alto: G.alto,
    x0,
    y0,
    xFin: G.ancho - G.derecha,
    hayDatos: maxVal > 0,
    marcas: [0, 0.25, 0.5, 0.75, 1].map((r) => ({
      y: y0 - r * altoUtil,
      etiqueta: etiquetaEjeY(techo * r),
    })),
    barras: grupos.map((g, i) => {
      const valor = valores[i] ?? 0
      const h = altura(valor)
      const centro = x0 + paso * i + paso / 2
      return {
        codigo: g.codigo,
        etiqueta: etiquetaFranjaCorta(g.codigo),
        valor,
        color: PALETA[i % PALETA.length],
        x: centro - anchoBarra / 2,
        y: y0 - h,
        ancho: anchoBarra,
        alto: h,
        etiquetaX: centro,
      }
    }),
  }
}

export function svgAbcHorasGraficoHtml(
  grupos: AbcVentasGrupo[],
  graficoPor: 'importe' | 'unidades',
): string {
  const g = computeAbcHorasGrafico(grupos, graficoPor)
  if (!g.hayDatos) return ''
  const ejes = g.marcas
    .map(
      (m) =>
        `<line x1="${g.x0}" y1="${m.y}" x2="${g.xFin}" y2="${m.y}" stroke="#cbd5e1" stroke-width="1"/>
<text x="${g.x0 - 4}" y="${m.y + 3}" text-anchor="end" font-size="9" fill="#475569">${m.etiqueta}</text>`,
    )
    .join('')
  const barras = g.barras
    .map(
      (b) =>
        `<rect x="${b.x}" y="${b.y}" width="${b.ancho}" height="${b.alto}" fill="${b.color}"/>
<text x="${b.etiquetaX}" y="${g.y0 + 14}" text-anchor="middle" font-size="8" fill="#334155">${b.etiqueta}</text>`,
    )
    .join('')
  return `<figure class="abc-horas-grafico"><svg viewBox="0 0 ${g.ancho} ${g.alto}" xmlns="http://www.w3.org/2000/svg">${ejes}${barras}</svg></figure>`
}
