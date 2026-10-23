/** IBAN: quita espacios y pasa a mayúsculas. */
export function normalizarIban(value: unknown): string {
  return String(value ?? '')
    .replace(/\s+/g, '')
    .toUpperCase()
}

/**
 * Vacío = válido (el campo no es obligatorio).
 * Si hay valor, exige formato ISO y checksum MOD-97.
 */
export function ibanValido(value: unknown): boolean {
  const iban = normalizarIban(value)
  if (!iban) return true
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(iban)) return false

  const reordenado = iban.slice(4) + iban.slice(0, 4)
  let resto = 0
  for (const char of reordenado) {
    const bloque = /\d/.test(char) ? char : String(char.charCodeAt(0) - 55)
    for (const digito of bloque) resto = (resto * 10 + Number(digito)) % 97
  }
  return resto === 1
}
