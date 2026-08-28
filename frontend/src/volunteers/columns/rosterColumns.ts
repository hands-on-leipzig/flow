import {tableColumns, type VolunteerColumnDef} from '@/volunteers/columns/types'

export const ROSTER_TABLE_DEFINITIONS = [
  {key: 'name', label: 'Name', table: true, sortable: true},
  {key: 'role', label: 'Rolle', table: true, sortable: true},
  {key: 't_shirt', label: 'T-Shirt Größe', table: true},
  {key: 'meal', label: 'Essen', table: true, export: true},
  {key: 'eve_meeting', label: 'Vorabendtreffen', table: true, export: true},
  {key: 'notes', label: 'Bemerkungen', table: true, export: true},
] as const satisfies readonly VolunteerColumnDef[]

export const ROSTER_TABLE_COLUMNS = tableColumns([...ROSTER_TABLE_DEFINITIONS])

export function rosterColumnLabel(key: string): string {
  return ROSTER_TABLE_DEFINITIONS.find((column) => column.key === key)?.label ?? key
}
