export type EventProgramRef = {
  first_program?: number
  id?: number
  name?: string | null
  draht_id?: number | null
  contao_id?: number | null
  color_hex?: string | null
  logo_white?: string | null
  sequence?: number | null
}

export type EventWithPrograms = {
  programs?: EventProgramRef[] | null
  level?: number | null
  name?: string | null
}

export function programId(row: EventProgramRef): number {
  return Number(row.first_program ?? row.id ?? 0)
}

function compareByProgramSequence(a: EventProgramRef, b: EventProgramRef): number {
  const seqA = a.sequence ?? Number.POSITIVE_INFINITY
  const seqB = b.sequence ?? Number.POSITIVE_INFINITY
  if (seqA !== seqB) return seqA - seqB
  return programId(a) - programId(b)
}

/**
 * Event programs in m_first_program.sequence order.
 * This is the only frontend sort — map/v-for the result, do not sort again.
 */
export function eventPrograms(event: EventWithPrograms | null | undefined): EventProgramRef[] {
  const rows = Array.isArray(event?.programs) ? event.programs : []
  return [...rows].sort(compareByProgramSequence)
}

export function hasProgramName(event: EventWithPrograms | null | undefined, name: string): boolean {
  return eventPrograms(event).some(
    (row) => String(row.name || '').toUpperCase() === name.toUpperCase()
  )
}

export function isFutureName(name: string | null | undefined): boolean {
  return String(name || '').toUpperCase().startsWith('FUTURE_')
}

export function hasExplore(event: EventWithPrograms | null | undefined): boolean {
  return hasProgramName(event, 'EXPLORE')
}

export function hasChallenge(event: EventWithPrograms | null | undefined): boolean {
  return hasProgramName(event, 'CHALLENGE')
}

export function hasFuture(event: EventWithPrograms | null | undefined): boolean {
  return eventPrograms(event).some((row) => isFutureName(row.name))
}

export function programSlug(name: string | null | undefined): string {
  return String(name || '').toLowerCase().replace(/-/g, '_')
}

export function programCompact(name: string | null | undefined): string {
  return programSlug(name).replace(/_/g, '')
}

export function programMatchesSlug(name: string | null | undefined, slug: string | null | undefined): boolean {
  return programCompact(name) === programCompact(slug)
}

export function programDisplayName(name: string | null | undefined): string {
  const n = String(name || '').toUpperCase()
  if (n === 'EXPLORE') return 'Explore'
  if (n === 'CHALLENGE') return 'Challenge'
  if (n === 'FUTURE_5') return 'Future 5+'
  if (n === 'FUTURE_8') return 'Future 8+'
  if (n === 'DISCOVER') return 'Discover'
  return String(name || '')
}

export function findProgram(
  event: EventWithPrograms | null | undefined,
  slugOrName: string
): EventProgramRef | undefined {
  return eventPrograms(event).find((row) => programMatchesSlug(row.name, slugOrName))
}

export function drahtIdFor(
  event: EventWithPrograms | null | undefined,
  name: string
): number | null {
  return findProgram(event, name)?.draht_id ?? null
}

export function teamPathFor(row: EventProgramRef): string {
  return `/plan/teams/${programSlug(row.name)}`
}

export function firstTeamsPath(event: EventWithPrograms | null | undefined): string {
  const first = eventPrograms(event)[0]
  return first ? teamPathFor(first) : '/plan/overview'
}
