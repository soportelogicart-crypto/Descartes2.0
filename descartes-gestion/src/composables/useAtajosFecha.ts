export function hoyIso(): string {
  const d = new Date()
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

export function inicioMesIso(ref = new Date()): string {
  const y = ref.getFullYear()
  const m = String(ref.getMonth() + 1).padStart(2, '0')
  return `${y}-${m}-01`
}

export function inicioAnioIso(ref = new Date()): string {
  return `${ref.getFullYear()}-01-01`
}

export type AtajoFechaId = 'hoy' | 'mes' | 'anio'

export function rangoAtajoFecha(id: AtajoFechaId): { desde: string; hasta: string } {
  const hasta = hoyIso()
  if (id === 'hoy') return { desde: hasta, hasta }
  if (id === 'mes') return { desde: inicioMesIso(), hasta }
  return { desde: inicioAnioIso(), hasta }
}
