import type {VolunteerColumnDef} from '@/volunteers/columns/types'

export const ROSTER_TABLE_DEFINITIONS = [
  {key: 'name', label: 'Name', table: true, sortable: true, kind: 'fixed'},
  {key: 'role', label: 'Rolle', table: true, sortable: true, kind: 'fixed'},
  {key: 't_shirt', label: 'T-Shirt Größe', table: true, kind: 'fixed', editor: 't_shirt'},
  {key: 'meal', label: 'Essen', table: true, kind: 'fixed', editor: 'meal'},
  {key: 'notes', label: 'Bemerkungen', table: true, kind: 'fixed', editor: 'text'},
] as const satisfies readonly VolunteerColumnDef[]

export type RosterColumnMeta = VolunteerColumnDef & {
  kind?: 'fixed' | 'custom'
  type?: 'text' | 'number' | 'boolean' | 'select'
  editor?: 't_shirt' | 'meal' | 'text'
  field_key?: string
  options?: Array<{value: string; label: string}>
}

export const ROSTER_TABLE_COLUMNS: RosterColumnMeta[] = ROSTER_TABLE_DEFINITIONS.map((column) => ({
  key: column.key,
  label: column.label,
  sortable: column.sortable ?? false,
  kind: column.kind,
  editor: column.editor,
}))

export function rosterColumnLabel(columns: RosterColumnMeta[], key: string): string {
  return columns.find((column) => column.key === key)?.label ?? key
}

export const ROSTER_BUILTIN_LABELS = [
  'T-Shirt Größe',
  'Essen',
  'Bemerkungen',
] as const
