type RosterDetailLike = {
  t_shirt_cut: string | null
  t_shirt_size: string | null
  meal: string | null
  eve_meeting: boolean | null
  notes: string | null
}

type RosterEntryLike = {
  assignments?: unknown[]
  detail?: RosterDetailLike | null
}

/** Table columns (except Name) that can still show ? or — in the UI. */
export const ROSTER_UNSET_FIELD_KEYS = ['role', 't_shirt', 'meal', 'eve_meeting'] as const

export type RosterUnsetFieldKey = (typeof ROSTER_UNSET_FIELD_KEYS)[number]

function detailOf(entry: RosterEntryLike): RosterDetailLike {
  return entry.detail ?? {
    t_shirt_cut: null,
    t_shirt_size: null,
    meal: null,
    eve_meeting: null,
    notes: null,
  }
}

export function rosterFieldIsUnset(entry: RosterEntryLike, key: RosterUnsetFieldKey): boolean {
  const detail = detailOf(entry)

  switch (key) {
    case 'role':
      return !(entry.assignments?.length)
    case 't_shirt':
      return !detail.t_shirt_cut || !detail.t_shirt_size
    case 'meal':
      return detail.meal === null
    case 'eve_meeting':
      return detail.eve_meeting === null
    default:
      return false
  }
}

export function rosterEntryHasUnsetField(entry: RosterEntryLike): boolean {
  return ROSTER_UNSET_FIELD_KEYS.some((key) => rosterFieldIsUnset(entry, key))
}
