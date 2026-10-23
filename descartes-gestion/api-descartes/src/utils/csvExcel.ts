/** CSV UTF-8 con BOM, separador ; y decimales con coma (Excel ES). */

export function escCsv(v: string | number | boolean | null | undefined): string {
  const s = v === true ? 'Sí' : v === false ? 'No' : String(v ?? '')
  if (/[;"\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`
  return s
}

export function numCsv(n: number, decimales = 2): string {
  const f = decimales <= 0 ? Math.round(n).toString() : (Math.round(n * 10 ** decimales) / 10 ** decimales).toFixed(decimales)
  return f.replace('.', ',')
}

export function descargarCsv(filename: string, lineas: string[]): void {
  const blob = new Blob(['\uFEFF' + lineas.join('\r\n')], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 2000)
}
