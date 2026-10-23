/**
 * Detecta lectura de pistola (ráfaga rápida de caracteres) y dispara
 * al acabar, sin exigir tecla Intro al final.
 *
 * Escritura manual lenta no dispara: sigue haciendo falta Intro / F4.
 */

export type BarcodeScanWatcher = {
  onInput: (value: string) => void
  cancel: () => void
}

export function createBarcodeScanWatcher(
  onScan: (code: string) => void | Promise<void>,
  options?: {
    /** Máximo ms entre teclas para considerarlo ráfaga de escáner. */
    charMs?: number
    /** Pausa tras el último carácter para dar por terminado el escaneo. */
    idleMs?: number
    /** Teclas rápidas consecutivas antes de activar modo escáner. */
    minStreak?: number
    /** Longitud mínima del valor para resolver. */
    minLen?: number
  }
): BarcodeScanWatcher {
  const charMs = options?.charMs ?? 55
  const idleMs = options?.idleMs ?? 120
  const minStreak = options?.minStreak ?? 3
  const minLen = options?.minLen ?? 4

  let lastTs = 0
  let rapidStreak = 0
  let isBurst = false
  let idleTimer: ReturnType<typeof setTimeout> | null = null
  let pending = ''
  let inflight = false

  function clearIdle() {
    if (idleTimer) {
      clearTimeout(idleTimer)
      idleTimer = null
    }
  }

  function resetBurst() {
    isBurst = false
    rapidStreak = 0
    lastTs = 0
  }

  function schedule(code: string) {
    clearIdle()
    idleTimer = setTimeout(() => {
      idleTimer = null
      const c = code.trim()
      if (!isBurst || inflight || c.length < minLen) {
        resetBurst()
        return
      }
      // EAN / UPC / códigos numéricos; también alfanuméricos cortos de pistola
      if (!/^[0-9A-Za-z\-]{4,32}$/.test(c)) {
        resetBurst()
        return
      }
      resetBurst()
      inflight = true
      Promise.resolve(onScan(c)).finally(() => {
        inflight = false
      })
    }, idleMs)
  }

  function onInput(value: string) {
    const now = performance.now()
    const gap = lastTs ? now - lastTs : 999
    lastTs = now
    pending = String(value ?? '')

    if (gap <= charMs) {
      rapidStreak += 1
      if (rapidStreak >= minStreak) isBurst = true
    } else if (gap > 180) {
      rapidStreak = 1
      isBurst = false
      clearIdle()
    }

    if (isBurst) schedule(pending)
  }

  function cancel() {
    clearIdle()
    resetBurst()
  }

  return { onInput, cancel }
}
