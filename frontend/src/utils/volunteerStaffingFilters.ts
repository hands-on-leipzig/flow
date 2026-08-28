import {programId} from '@/utils/eventPrograms'

export type StaffingFilterKey = 'cross' | 'local' | `program:${number}`

type ProgramRef = {first_program?: number; id?: number; name?: string | null}

type StaffingScope = {
  is_local: boolean
  first_program: number | null
}

export function buildStaffingFilterKeys(programs: ReadonlyArray<ProgramRef>): StaffingFilterKey[] {
  const keys: StaffingFilterKey[] = ['cross', 'local']
  for (const program of programs) {
    const id = programId(program)
    if (id > 0) keys.push(`program:${id}`)
  }
  return keys
}

export function syncStaffingFilters(
  active: ReadonlySet<StaffingFilterKey>,
  keys: readonly StaffingFilterKey[],
): Set<StaffingFilterKey> {
  const kept = keys.filter((key) => active.has(key))
  return kept.length > 0 ? new Set(kept) : new Set(keys)
}

export function toggleStaffingFilter(
  active: ReadonlySet<StaffingFilterKey>,
  key: StaffingFilterKey,
): Set<StaffingFilterKey> {
  const next = new Set(active)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  return next
}

export function isStaffingFilterActive(
  active: ReadonlySet<StaffingFilterKey>,
  key: StaffingFilterKey,
): boolean {
  return active.has(key)
}

export function staffingFilterKeyFromScope(scope: StaffingScope): StaffingFilterKey {
  if (scope.is_local) return 'local'
  if (scope.first_program == null) return 'cross'
  return `program:${scope.first_program}`
}
