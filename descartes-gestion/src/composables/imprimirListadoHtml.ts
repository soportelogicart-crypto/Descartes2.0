/** Vista previa / impresión A4 sencilla: cabecera + tabla HTML. */
export function imprimirListadoHtml(opciones: {
  titulo: string
  subtitulo?: string
  metaLineas?: string[]
  thead: string[]
  filas: (string | number)[][]
  pie?: string[]
  filenameFallback?: string
}): { ok: boolean; message: string } {
  const esc = (s: string) =>
    s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')
  const thead = opciones.thead.map((h) => `<th>${esc(h)}</th>`).join('')
  const body = opciones.filas
    .map(
      (fila) =>
        `<tr>${fila.map((c, i) => `<td class="${i > 0 ? 'num' : ''}">${esc(String(c))}</td>`).join('')}</tr>`
    )
    .join('')
  const meta = (opciones.metaLineas ?? [])
    .map((l) => `<p class="meta">${esc(l)}</p>`)
    .join('')
  const pie =
    opciones.pie && opciones.pie.length
      ? `<p class="tot">${opciones.pie.map(esc).join(' · ')}</p>`
      : ''
  const subt = opciones.subtitulo ? `<p class="sub">${esc(opciones.subtitulo)}</p>` : ''
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(opciones.titulo)}</title>
<style>
@page { margin: 14mm; }
body{font-family:Arial,sans-serif;font-size:11px;color:#111;padding:0;margin:0}
h1{font-size:16px;margin:0 0 4px}
.sub{color:#444;margin:0 0 8px;font-size:12px}
.meta{color:#555;margin:2px 0;font-size:10px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border-bottom:1px solid #ccc;padding:3px 5px;text-align:left}
th{background:#f1f5f9;font-size:10px}
td.num,th.num{text-align:right}
.tot{font-weight:700;margin-top:12px;font-size:11px}
</style></head><body>
<h1>${esc(opciones.titulo)}</h1>${sub}${meta}
<table><thead><tr>${thead}</tr></thead><tbody>${body}</tbody></table>
${pie}
</body></html>`

  const w = window.open('', '_blank', 'width=820,height=900')
  if (!w) {
    const name = opciones.filenameFallback ?? 'listado.html'
    const blob = new Blob([html], { type: 'text/html;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = name
    a.click()
    URL.revokeObjectURL(url)
    return { ok: false, message: 'Popup bloqueado: se descargó HTML para abrir manualmente' }
  }
  w.document.write(html)
  w.document.close()
  w.focus()
  w.print()
  return { ok: true, message: 'Vista previa de impresión abierta' }
}
