/** Minimal person shape used across Helfer:innen screens. */
export type VolunteerPersonRef = {
  id: number
  first_name: string
  last_name: string
  email: string
  mobile?: string | null
  organization?: string | null
  updated_at?: string | null
  on_roster?: boolean
}

export function volunteerDisplayName(person: Pick<VolunteerPersonRef, 'first_name' | 'last_name'>) {
  return `${person.first_name} ${person.last_name}`
}

export function volunteerSearchHaystack(person: VolunteerPersonRef) {
  return [
    person.first_name,
    person.last_name,
    person.email,
    person.mobile,
    person.organization,
    person.updated_at?.slice(0, 10),
  ]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
}
