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
  sortable: StaffingSortable
  name: string
  wanted: number
}

export type OpenPositionScopeGroup = {
  key: StaffingFilterKey
  critical: OpenPositionEntry[]
  nice: OpenPositionEntry[]
}

function sortEntries(
  a: OpenPositionEntry,
  b: OpenPositionEntry,
  programs: ReadonlyArray<EventProgramRef>,
) {
  return compareStaffingTiles(a.sortable, b.sortable, programs)
}

export function computeOpenPositions(
  tiles: ReadonlyArray<StaffingTile>,
  programs: ReadonlyArray<EventProgramRef>,
): OpenPositionScopeGroup[] {
  const byKey = new Map<StaffingFilterKey, {critical: OpenPositionEntry[]; nice: OpenPositionEntry[]}>()

  for (const key of buildStaffingFilterKeys(programs)) {
    byKey.set(key, {critical: [], nice: []})
  }

  for (const tile of tiles) {
    if (tile.group.surplus) continue

    const key = staffingFilterKeyFromScope(tile.role)
    const bucket = byKey.get(key)
    if (!bucket) continue

    const filled = tile.group.filled
    const min = Number(tile.role.min)
    const best = Number(tile.role.best)
    const sortable = staffingSortableFromTile(tile)

    if (filled < min) {
      bucket.critical.push({sortable, name: tile.name, wanted: min - filled})
    }
    if (filled < best && best > min) {
      bucket.nice.push({sortable, name: tile.name, wanted: best - min})
    }
  }

  return buildStaffingFilterKeys(programs)
    .map((key) => {
      const bucket = byKey.get(key)!
      return {
        key,
        critical: [...bucket.critical].sort((a, b) => sortEntries(a, b, programs)),
        nice: [...bucket.nice].sort((a, b) => sortEntries(a, b, programs)),
      }
    })
    .filter((scope) => scope.critical.length > 0 || scope.nice.length > 0)
}

export function openPositionsCriticalCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.critical.length, 0)
}

export function openPositionsNiceCount(scopes: ReadonlyArray<OpenPositionScopeGroup>) {
  return scopes.reduce((sum, scope) => sum + scope.nice.length, 0)
}
