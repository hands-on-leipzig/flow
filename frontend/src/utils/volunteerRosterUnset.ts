import type {RosterColumnMeta} from '@/volunteers/columns/rosterColumns'

type RosterDetailLike = {
  t_shirt_cut: string | null
  t_shirt_size: string | null
  meal: string | null
}

type RosterEntryLike = {
  assignments?: unknown[]
  detail?: RosterDetailLike | null
  custom?: Record<string, string | number | boolean | null> | null
}

export const ROSTER_FIXED_UNSET_KEYS = ['role', 't_shirt', 'meal'] as const

function detailOf(entry: RosterEntryLike): RosterDetailLike {
  return entry.detail ?? {
    t_shirt_cut: null,
    t_shirt_size: null,
    meal: null,
  }
}

function customValue(entry: RosterEntryLike, fieldKey: string) {
  return entry.custom?.[fieldKey] ?? null
}

export function rosterFixedFieldIsUnset(entry: RosterEntryLike, key: (typeof ROSTER_FIXED_UNSET_KEYS)[number]): boolean {
  const detail = detailOf(entry)

  switch (key) {
    case 'role':
      return !(entry.assignments?.length)
    case 't_shirt':
      return !detail.t_shirt_cut || !detail.t_shirt_size
    case 'meal':
      return detail.meal === null
    default:
      return false
  }
}

export function rosterCustomFieldIsUnset(column: RosterColumnMeta, entry: RosterEntryLike): boolean {
  if (column.kind !== 'custom' || !column.field_key) return false

  const value = customValue(entry, column.field_key)
  if (value === null || value === undefined) return true
  if (column.type === 'text' && String(value).trim() === '') return true

  return false
}

export function rosterEntryHasUnsetField(entry: RosterEntryLike, columns: RosterColumnMeta[]): boolean {
  const columnKeys = new Set(columns.map((column) => column.key))
  if (ROSTER_FIXED_UNSET_KEYS.some((key) => columnKeys.has(key) && rosterFixedFieldIsUnset(entry, key))) {
    return true
  }

  return columns.some((column) => rosterCustomFieldIsUnset(column, entry))
}
