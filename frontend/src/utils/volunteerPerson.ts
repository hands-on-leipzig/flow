/** Minimal person shape used across Helfer:innen screens. */
export type VolunteerPersonRef = {
  id: number
  first_name: string
  last_name: string
  nickname: string | null
  email: string
  mobile?: string | null
  updated_at?: string | null
  on_roster?: boolean
}

export function volunteerDisplayName(person: Pick<VolunteerPersonRef, 'first_name' | 'last_name' | 'nickname'>) {
  if (person.nickname?.trim()) {
    return `${person.first_name} „${person.nickname}“ ${person.last_name}`
  }
  return `${person.first_name} ${person.last_name}`
}

export function volunteerSearchHaystack(person: VolunteerPersonRef) {
  return [
    person.first_name,
    person.last_name,
    person.nickname,
    person.email,
    person.mobile,
    person.updated_at?.slice(0, 10),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}
