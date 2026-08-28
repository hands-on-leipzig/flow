import {type EventProgramRef, programId} from '@/utils/eventPrograms'

export type StaffingSortable = {
  is_local: boolean
  first_program: number | null
  sequence: number
  label: string
  role_id: number
  group_index: number
  tile_name: string
}

export function firstProgramSequence(
  program: number | null,
  programs: EventProgramRef[] | null | undefined,
): number {
  if (program == null) return -1
  const row = programs?.find((p) => programId(p) === program)
  return row?.sequence ?? Number.MAX_SAFE_INTEGER
}

/** Same tile order as Zuordnung role cards. */
export function compareStaffingTiles(
  a: StaffingSortable,
  b: StaffingSortable,
  programs: EventProgramRef[] | null | undefined,
): number {
  if (a.is_local !== b.is_local) {
    return a.is_local ? 1 : -1
  }

  if (a.is_local) {
    const byName = a.tile_name.localeCompare(b.tile_name, 'de')
    if (byName !== 0) return byName
    return a.group_index - b.group_index
  }

  const aNoProgram = a.first_program == null
  const bNoProgram = b.first_program == null
  if (aNoProgram !== bNoProgram) return aNoProgram ? -1 : 1

  const byProgram =
    firstProgramSequence(a.first_program, programs) - firstProgramSequence(b.first_program, programs)
  if (byProgram !== 0) return byProgram

  if (a.sequence !== b.sequence) return a.sequence - b.sequence

  const byLabel = a.label.localeCompare(b.label, 'de')
  if (byLabel !== 0) return byLabel

  if (a.role_id !== b.role_id) return a.role_id - b.role_id

  return a.group_index - b.group_index
}

export function staffingSortableFromTile(tile: {
  name: string
  role: {
    id: number
    is_local: boolean
    label: string
    first_program: number | null
    sequence: number
  }
  group: {group_index: number}
}): StaffingSortable {
  return {
    is_local: tile.role.is_local,
    first_program: tile.role.first_program,
    sequence: tile.role.sequence,
    label: tile.role.label,
    role_id: tile.role.id,
    group_index: tile.group.group_index,
    tile_name: tile.name,
  }
}

export function staffingSortableFromAssignment(assignment: {
  tile_name: string
  first_program: number | null
  is_local: boolean
  sequence: number
  group_index: number
  label: string
  role_id: number
}): StaffingSortable {
  return {
    is_local: assignment.is_local,
    first_program: assignment.first_program,
    sequence: assignment.sequence,
    label: assignment.label,
    role_id: assignment.role_id,
    group_index: assignment.group_index,
    tile_name: assignment.tile_name,
  }
}

export function primaryStaffingSortable(
  items: StaffingSortable[],
  programs: EventProgramRef[] | null | undefined,
): StaffingSortable | null {
  if (!items.length) return null
  return [...items].sort((a, b) => compareStaffingTiles(a, b, programs))[0] ?? null
}

export function compareRosterEntriesByStaffingRole(
  a: {assignments?: Array<Parameters<typeof staffingSortableFromAssignment>[0]>},
  b: {assignments?: Array<Parameters<typeof staffingSortableFromAssignment>[0]>},
  programs: EventProgramRef[] | null | undefined,
): number {
  const aPrimary = primaryStaffingSortable(
    (a.assignments ?? []).map(staffingSortableFromAssignment),
    programs,
  )
  const bPrimary = primaryStaffingSortable(
    (b.assignments ?? []).map(staffingSortableFromAssignment),
    programs,
  )

  if (!aPrimary && !bPrimary) return 0
  if (!aPrimary) return 1
  if (!bPrimary) return -1

  return compareStaffingTiles(aPrimary, bPrimary, programs)
}
