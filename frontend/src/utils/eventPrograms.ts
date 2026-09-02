export type EventProgramRef = {
  first_program?: number
  id?: number
  name?: string | null
  display_name?: string | null
  letter?: string | null
  draht_id?: number | null
  contao_id?: number | null
  color_hex?: string | null
  logo_stem?: string | null
  logo_white?: string | null
  sequence?: number | null
}

export type ProgramIdentityRef = EventProgramRef | string | number | null | undefined

let identityCatalog: EventProgramRef[] = []

export function setProgramIdentityCatalog(rows: EventProgramRef[]) {
  identityCatalog = Array.isArray(rows) ? rows : []
}

export function findCatalogRow(program: ProgramIdentityRef): EventProgramRef | undefined {
  if (program == null || program === '') return undefined

  if (typeof program === 'object') {
    const id = programId(program)
    if (id > 0) {
      const byId = identityCatalog.find((row) => programId(row) === id)
      if (byId) return {...byId, ...program}
    }
    if (program.name) {
      const byName = findCatalogRow(program.name)
      if (byName) return {...byName, ...program}
    }
    if (program.letter) {
      const byLetter = identityCatalog.find(
        (row) => String(row.letter || '').toUpperCase() === String(program.letter).toUpperCase()
      )
      if (byLetter) return {...byLetter, ...program}
    }
    return program
  }

  const raw = String(program).trim()
  if (!raw) return undefined
  const compactKey = programCompact(raw)
  const upper = raw.toUpperCase()

  return identityCatalog.find((row) => {
    if (programId(row) > 0 && String(programId(row)) === raw) return true
    if (row.name && (row.name === raw || programCompact(row.name) === compactKey)) return true
    if (row.letter && String(row.letter).toUpperCase() === upper) return true
    return false
  })
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

export function hasAfternoon(
  event: EventWithPrograms | null | undefined,
  afternoonFirstPrograms: Iterable<number>
): boolean {
  const ids = new Set(Array.from(afternoonFirstPrograms, Number).filter((id) => id > 0))
  if (ids.size === 0) return false
  return eventPrograms(event).some((row) => ids.has(programId(row)))
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

export function programDisplayName(program: ProgramIdentityRef): string {
  if (program && typeof program === 'object' && program.display_name) {
    return program.display_name
  }
  const row = findCatalogRow(program)
  if (row?.display_name) return row.display_name
  if (program && typeof program === 'object') return String(program.name || '')
  return String(program ?? '')
}

/** Map volunteer/label codes (E/C/D) to catalog names for programLogoSrc. */
export function catalogNameFromCode(code: string | null | undefined): string {
  const c = String(code || '').trim().toUpperCase()
  if (!c) return c
  const byLetter = identityCatalog.find(
    (row) => String(row.letter || '').toUpperCase() === c
  )
  if (byLetter?.name) return String(byLetter.name)
  const compact = c.replace(/[_-]/g, '')
  const byName = identityCatalog.find((row) => programCompact(row.name) === compact.toLowerCase())
  if (byName?.name) return String(byName.name)
  if (c === 'F8' || compact === 'F8') return 'FUTURE_8'
  if (c === 'F5' || compact === 'F5') return 'FUTURE_5'
  return c
}

export function findProgram(
  event: EventWithPrograms | null | undefined,
  slugOrName: string
): EventProgramRef | undefined {
  return eventPrograms(event).find((row) => programMatchesSlug(row.name, slugOrName))
}

export function programNameForId(
  event: EventWithPrograms | null | undefined,
  firstProgramId: number | string | null | undefined
): string | null {
  const id = Number(firstProgramId)
  if (!id) return null
  return eventPrograms(event).find((row) => Number(row.first_program) === id)?.name ?? null
}

export type ProgramLogoInput =
  | ProgramIdentityRef
  | {
      first_program?: number | null
      id?: number
      name?: string | null
      display_name?: string | null
      program_name?: string | null
      logo_stem?: string | null
    }

/** Normalize event/catalog rows, ids, and names for programLogoSrc. */
export function resolveProgramRef(
  event: EventWithPrograms | null | undefined,
  program: ProgramLogoInput
): EventProgramRef | null {
  if (program == null || program === '') return null

  if (typeof program === 'number') {
    const id = program
    if (id <= 0) return null
    const fromEvent = eventPrograms(event).find((row) => programId(row) === id)
    if (fromEvent) return fromEvent
    const fromCatalog = findCatalogRow(id)
    return fromCatalog ?? {first_program: id, name: programNameForId(event, id) ?? undefined}
  }

  if (typeof program === 'string') {
    const trimmed = program.trim()
    if (!trimmed) return null
    const fromEvent = eventPrograms(event).find(
      (row) => programMatchesSlug(row.name, trimmed) || String(row.name) === trimmed
    )
    if (fromEvent) return fromEvent
    const fromCatalog = findCatalogRow(trimmed)
    return fromCatalog ?? {name: trimmed}
  }

  const row = program as EventProgramRef & {program_name?: string | null}
  const id = programId(row)
  const name = row.program_name ?? row.name ?? programNameForId(event, id)
  const fromEvent = id ? eventPrograms(event).find((p) => programId(p) === id) : undefined
  const fromCatalog = findCatalogRow({...row, name: name ?? undefined, first_program: id || undefined})

  const merged: EventProgramRef = {
    ...(fromCatalog ?? {}),
    ...(fromEvent ?? {}),
    ...row,
    first_program: id || row.first_program,
    name: name ?? row.name,
  }
  return merged.name || merged.first_program ? merged : null
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

export function firstTeamsPath(_event?: EventWithPrograms | null): string {
  return '/plan/teams/data'
}
