import {programId, type EventProgramRef} from '@/utils/eventPrograms'
import {
  buildStaffingFilterKeys,
  staffingFilterKeyFromScope,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import type {StaffingRole} from '@/volunteers/staffingTypes'

export type StaffingScopeSummary = {
  key: StaffingFilterKey
  assigned: number
  missing_min: number
  roles?: number
}

type SummaryScope = {
  assigned: number
  missing_min: number
  roles: number
}

function emptyBuckets(programs: ReadonlyArray<EventProgramRef>): Map<StaffingFilterKey, SummaryScope> {
  const buckets = new Map<StaffingFilterKey, SummaryScope>()
  for (const key of buildStaffingFilterKeys(programs)) {
    buckets.set(key, {assigned: 0, missing_min: 0, roles: 0})
  }
  return buckets
}

export function emptyStaffingSummary(programs: ReadonlyArray<EventProgramRef>): StaffingScopeSummary[] {
  return buildStaffingFilterKeys(programs).map((key) => ({
    key,
    assigned: 0,
    missing_min: 0,
    roles: 0,
  }))
}

export function computeStaffingSummary(
  roles: ReadonlyArray<StaffingRole>,
  programs: ReadonlyArray<EventProgramRef>,
): StaffingScopeSummary[] {
  const buckets = emptyBuckets(programs)

  for (const role of roles) {
    const key = staffingFilterKeyFromScope(role)
    const bucket = buckets.get(key)
    if (!bucket) continue

    bucket.roles += 1

    const min = Number(role.min)
    if (role.grouped) {
      for (const group of role.groups ?? []) {
        bucket.assigned += group.filled
        if (!group.surplus && group.filled < min) {
          bucket.missing_min += min - group.filled
        }
      }
    } else {
      const filled = (role.people ?? []).length
      bucket.assigned += filled
      if (!role.surplus && filled < min) {
        bucket.missing_min += min - filled
      }
    }
  }

  return buildStaffingFilterKeys(programs).map((key) => ({
    key,
    assigned: buckets.get(key)?.assigned ?? 0,
    missing_min: buckets.get(key)?.missing_min ?? 0,
    roles: buckets.get(key)?.roles ?? 0,
  }))
}

export function parseStaffingSummaryScopeKey(key: string): StaffingFilterKey {
  if (key === 'cross' || key === 'local') return key
  if (key.startsWith('program:')) {
    const id = Number(key.slice('program:'.length))
    if (Number.isInteger(id) && id > 0) return `program:${id}`
  }
  return 'cross'
}

export function staffingSummaryFromReadiness(
  rows: ReadonlyArray<{key: string; assigned: number; missing_min: number; roles?: number}> | null | undefined,
  programs: ReadonlyArray<EventProgramRef>,
): StaffingScopeSummary[] {
  const byKey = new Map<StaffingFilterKey, StaffingScopeSummary>()
  for (const row of rows ?? []) {
    const key = parseStaffingSummaryScopeKey(row.key)
    byKey.set(key, {
      key,
      assigned: Number(row.assigned) || 0,
      missing_min: Number(row.missing_min) || 0,
      roles: Number(row.roles) || 0,
    })
  }

  return buildStaffingFilterKeys(programs).map((key) => byKey.get(key) ?? {key, assigned: 0, missing_min: 0, roles: 0})
}

export function programIdFromSummaryKey(key: StaffingFilterKey): number | null {
  if (!key.startsWith('program:')) return null
  return programId({first_program: Number(key.slice('program:'.length))})
}
