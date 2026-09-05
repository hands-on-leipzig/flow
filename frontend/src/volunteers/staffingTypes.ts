import type {VolunteerPersonRef} from '@/utils/volunteerPerson'

export type StaffingGroup = {
  id: number
  group_index: number
  name?: string
  surplus: boolean
  filled: number
  min: number
  best: number
  max: number
  under_min: boolean
  people: VolunteerPersonRef[]
}

export type StaffingRole = {
  id: number
  m_role: number | null
  is_local: boolean
  label: string
  group_label: string | null
  grouped: boolean
  surplus: boolean
  first_program: number | null
  min: number
  best: number
  max: number
  ui_description: string | null
  sequence: number
  people: VolunteerPersonRef[]
  groups: StaffingGroup[]
}

export type StaffingTile = {
  key: string
  role: StaffingRole
  group: StaffingGroup | null
  name: string
}

export type StaffingGapTone = 'warn' | 'caution' | 'ok' | 'muted'

export function tilePeople(tile: StaffingTile): VolunteerPersonRef[] {
  return tile.group ? tile.group.people : (tile.role.people ?? [])
}

export function tileFilled(tile: StaffingTile): number {
  if (tile.group) return tile.group.filled
  return (tile.role.people ?? []).length
}

export function tileSurplus(tile: StaffingTile): boolean {
  return tile.group ? tile.group.surplus : !!tile.role.surplus
}

export function staffingGap(tile: StaffingTile): {label: string; tone: StaffingGapTone} {
  const filled = tileFilled(tile)
  const min = Number(tile.role.min)
  const best = Number(tile.role.best)

  if (filled < min) {
    const missing = min - filled
    return {
      label: missing === 1 ? '1 fehlt' : `${missing} fehlen`,
      tone: 'warn',
    }
  }
  if (filled < best) {
    return {label: `${best - filled} bis ideal`, tone: 'caution'}
  }
  if (filled === best) {
    return {label: 'Ideal', tone: 'ok'}
  }
  return {label: `${filled - best} mehr als ideal`, tone: 'muted'}
}

export function tileNeedsAttention(tile: StaffingTile) {
  if (tileSurplus(tile)) {
    return tileFilled(tile) > 0
  }
  return tileFilled(tile) < Number(tile.role.min)
}

export function slotPositions(role: StaffingRole) {
  const max = Number(role.max)
  if (!Number.isInteger(max) || max < 1) return []
  return Array.from({length: max}, (_, i) => i + 1)
}

export function boundsValidationError(min: number, best: number, max: number) {
  if (!Number.isInteger(min) || !Number.isInteger(best) || !Number.isInteger(max)) {
    return 'Bitte min, ideal und max eintragen.'
  }
  if (min < 1 || best < 1 || max < 1) {
    return 'min, ideal und max müssen mindestens 1 sein.'
  }
  if (min > best || best > max) {
    return 'Es muss min ≤ ideal ≤ max gelten.'
  }
  return null
}

export function boundsLabel(role: StaffingRole) {
  return `min ${role.min} · ideal ${role.best} · max ${role.max}`
}
