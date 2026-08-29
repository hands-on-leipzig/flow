export const SLOT_DURATION_MIN = 5
export const SLOT_DURATION_MAX = 480
export const SLOT_DURATION_STEP = 5

export function normalizeDurationMinutes(d: number): number {
  const n = Math.round(Number(d) / SLOT_DURATION_STEP) * SLOT_DURATION_STEP
  return Math.min(SLOT_DURATION_MAX, Math.max(SLOT_DURATION_MIN, n || SLOT_DURATION_MIN))
}

/** Duration input: arrow keys / tab only (5-minute steps via spinner) */
export function onDurationKeydown(e: KeyboardEvent) {
  const ok = [
    'Tab',
    'ArrowUp',
    'ArrowDown',
    'ArrowLeft',
    'ArrowRight',
    'Home',
    'End',
    'Enter',
  ].includes(e.key)
  if (e.metaKey || e.ctrlKey || e.altKey) return
  if (!ok && e.key.length === 1) e.preventDefault()
}
