import type {VolunteerPersonRef} from '@/utils/volunteerPerson'

export type RosterDetail = {
  t_shirt_cut: string | null
  t_shirt_size: string | null
  meal: string | null
  photo_consent: boolean | null
  updated_at: string | null
}

export type RosterAssignment = {
  tile_name: string
  label: string
  role_id: number
  first_program: number | null
  is_local: boolean
  sequence: number
  group_index: number | null
}

export type RosterEntry = {
  id: number
  has_assignment: boolean
  assignments?: RosterAssignment[]
  detail?: RosterDetail
  custom?: Record<string, string | number | boolean | null>
  created_at: string | null
  person: VolunteerPersonRef
}

export function defaultRosterDetail(): RosterDetail {
  return {
    t_shirt_cut: null,
    t_shirt_size: null,
    meal: null,
    photo_consent: null,
    updated_at: null,
  }
}
