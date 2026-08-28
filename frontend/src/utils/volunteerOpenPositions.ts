import {type EventProgramRef} from '@/utils/eventPrograms'
import {
  buildStaffingFilterKeys,
  staffingFilterKeyFromScope,
  type StaffingFilterKey,
} from '@/utils/volunteerStaffingFilters'
import {
  compareStaffingTiles,
  staffingSortableFromTile,
  type StaffingSortable,
} from '@/utils/volunteerStaffingSort'
import type {StaffingTile} from '@/volunteers/staffingTypes'

export type OpenPositionEntry = {
  roleId: number
  sortable: StaffingSortable
  name: string
  wanted: number
}

export type OpenPositionScopeGroup = {
  key: StaffingFilterKey
  critical: OpenPositionEntry[]
  nice: OpenPositionEntry[]
}

type RoleAccumulator = {
  sortable: StaffingSortable
  name: string
  critical: number
  nice: number
}

function sortEntries(
  a: OpenPositionEntry,
  b: OpenPositionEntry,
  programs: ReadonlyArray<EventProgramRef>,
) {
  return compareStaffingTiles(a.sortable, b.sortable, programs)
}

function roleName(tile: StaffingTile) {
  return (tile.role.label || '').trim() || 'Unbenannt'
}

function accumulateRole(
  roles: Map<number, RoleAccumulator>,
  tile: StaffingTile,
  section: 'critical' | 'nice',
  amount: number,
) {
  const roleId = tile.role.id
  let acc = roles.get(roleId)
  if (!acc) {
    acc = {
      sortable: staffingSortableFromTile(tile),
      name: roleName(tile),
      critical: 0,
      nice: 0,
    }
    roles.set(roleId, acc)
  }

  if (section === 'critical') acc.critical += amount
  else acc.nice += amount
}

function entriesFromRoles(
  roles: Map<number, RoleAccumulator>,
  section: 'critical' | 'nice',
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionEntry[] {
  return [...roles.values()]
    .map((acc) => ({
      roleId: acc.sortable.role_id,
      sortable: acc.sortable,
      name: acc.name,
      wanted: section === 'critical' ? acc.critical : acc.nice,
    }))
    .filter((entry) => entry.wanted > 0)
    .sort((a, b) => sortEntries(a, b, programs))
}

export function computeOpenPositions(
  tiles: ReadonlyArray<StaffingTile>,
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionScopeGroup[] {
  const byKey = new Map<
    StaffingFilterKey,
    {critical: Map<number, RoleAccumulator>; nice: Map<number, RoleAccumulator>}
  >()

  for (const key of buildStaffingFilterKeys(programs)) {
    byKey.set(key, {critical: new Map(), nice: new Map()})
  }

  for (const tile of tiles) {
    if (tile.group.surplus) continue

    const key = staffingFilterKeyFromScope(tile.role)
    const bucket = byKey.get(key)
    if (!bucket) continue

    const filled = tile.group.filled
    const min = Number(tile.role.min)
    const best = Number(tile.role.best)

    if (filled < min) {
      accumulateRole(bucket.critical, tile, 'critical', min - filled)
    }
    if (filled < best && best > min) {
      accumulateRole(bucket.nice, tile, 'nice', best - min)
    }
  }

  return buildStaffingFilterKeys(programs)
    .map((key) => {
      const bucket = byKey.get(key)!
      const critical = entriesFromRoles(bucket.critical, 'critical', programs)
      const nice = entriesFromRoles(bucket.nice, 'nice', programs)
      return {key, critical, nice}
    })
    .filter((scope) => scope.critical.length > 0 || scope.nice.length > 0)
}

export function openPositionsCriticalCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.critical.length, 0)
}

export function openPositionsNiceCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.nice.length, 0)
}
