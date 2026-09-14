/** Plan-slot labels for planner UI (Txx + missing enrollment). */

export const MISSING_TEAM_NAME = 'Fehlendes Team'

const PLACEHOLDER_MARKERS = [
  'Platzhalter, weil nicht genügend Teams angemeldet sind',
  'Noch nicht angemeldet',
  MISSING_TEAM_NAME,
]

export function formatPlanTeamNo(n: number | string | null | undefined): string {
  if (n == null || n === '') return ''
  const num = Number(n)
  if (!Number.isFinite(num)) return ''
  if (num === 0) return '–'
  return `T${String(Math.floor(num)).padStart(2, '0')}`
}

export function isMissingPlanTeamName(name: string | null | undefined): boolean {
  const trimmed = (name ?? '').trim()
  if (trimmed === '') return true
  return PLACEHOLDER_MARKERS.some((marker) => trimmed.includes(marker))
}

export function planTeamName(name: string | null | undefined): string {
  if (isMissingPlanTeamName(name)) return MISSING_TEAM_NAME
  return (name ?? '').trim()
}
