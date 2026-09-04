import {type EventProgramRef} from '@/utils/eventPrograms'
import {
  buildStaffingFilterKeys,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import {parseStaffingSummaryScopeKey} from '@/utils/volunteerStaffingSummary'
import {compareStaffingTiles, type StaffingSortable} from '@/utils/volunteerStaffingSort'

export type OpenPositionApiEntry = {
  role_id: number
  group_id: number | null
  group_index: number | null
  label: string
  wanted: number
  sequence: number
  first_program: number | null
  is_local: boolean
}

export type OpenPositionApiScope = {
  key: string
  critical: OpenPositionApiEntry[]
  recommended: OpenPositionApiEntry[]
}

export type OpenPositionEntry = {
  roleId: number
  groupId: number | null
  sortable: StaffingSortable
  name: string
  wanted: number
}

export type OpenPositionScopeGroup = {
  key: StaffingFilterKey
  critical: OpenPositionEntry[]
  recommended: OpenPositionEntry[]
}

function sortableFromApi(entry: OpenPositionApiEntry): StaffingSortable {
  return {
    is_local: entry.is_local,
    first_program: entry.first_program,
    sequence: entry.sequence,
    label: entry.label,
    role_id: entry.role_id,
    group_index: entry.group_index ?? 0,
    tile_name: entry.label,
  }
}

function mapEntries(
  entries: ReadonlyArray<OpenPositionApiEntry>,
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionEntry[] {
  return [...entries]
    .map((entry) => ({
      roleId: entry.role_id,
      groupId: entry.group_id ?? null,
      sortable: sortableFromApi(entry),
      name: entry.label,
      wanted: Number(entry.wanted) || 0,
    }))
    .filter((entry) => entry.wanted > 0)
    .sort((a, b) => compareStaffingTiles(a.sortable, b.sortable, programs))
}

export function openPositionsFromApi(
  rows: ReadonlyArray<OpenPositionApiScope> | null | undefined,
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionScopeGroup[] {
  const byKey = new Map<StaffingFilterKey, OpenPositionScopeGroup>()
  for (const row of rows ?? []) {
    const key = parseStaffingSummaryScopeKey(row.key)
    byKey.set(key, {
      key,
      critical: mapEntries(row.critical ?? [], programs),
      recommended: mapEntries(row.recommended ?? [], programs),
    })
  }

  return buildStaffingFilterKeys(programs)
    .map((key) => byKey.get(key) ?? {key, critical: [], recommended: []})
    .filter((scope) => scope.critical.length > 0 || scope.recommended.length > 0)
}

export function openPositionsFromReadiness(
  rows: ReadonlyArray<OpenPositionApiScope> | null | undefined,
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionScopeGroup[] {
  return openPositionsFromApi(rows, programs)
}

export function openPositionsCriticalCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.critical.length, 0)
}

export function openPositionsRecommendedCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.recommended.length, 0)
}

/** @deprecated use openPositionsRecommendedCount */
export const openPositionsNiceCount = openPositionsRecommendedCount
