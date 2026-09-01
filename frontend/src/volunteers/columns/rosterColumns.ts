import type {VolunteerColumnDef} from '@/volunteers/columns/types'

export const ROSTER_TABLE_DEFINITIONS = [
  {key: 'name', label: 'Name', table: true, sortable: true, kind: 'fixed'},
  {key: 'role', label: 'Rolle', table: true, sortable: true, kind: 'fixed'},
  {key: 'photo_consent', label: 'Foto Erlaubnis', table: true, kind: 'fixed', editor: 'photo_consent', public_form: false},
  {key: 't_shirt', label: 'T-Shirt Größe', table: true, kind: 'fixed', editor: 't_shirt'},
  {key: 'meal', label: 'Essen', table: true, kind: 'fixed', editor: 'meal'},
] as const satisfies readonly VolunteerColumnDef[]

export type RosterColumnMeta = VolunteerColumnDef & {
  kind?: 'fixed' | 'custom'
  type?: 'text' | 'number' | 'boolean' | 'select'
  editor?: 't_shirt' | 'meal' | 'text' | 'photo_consent'
  field_key?: string
  options?: Array<{value: string; label: string}>
  public_form?: boolean
}

export const ROSTER_TABLE_COLUMNS: RosterColumnMeta[] = ROSTER_TABLE_DEFINITIONS.map((column) => ({
  key: column.key,
  label: column.label,
  sortable: column.sortable ?? false,
  kind: column.kind,
  editor: column.editor,
  public_form: 'public_form' in column ? column.public_form : undefined,
}))

export function rosterColumnLabel(columns: RosterColumnMeta[], key: string): string {
  return columns.find((column) => column.key === key)?.label ?? key
}

export const ROSTER_BUILTIN_LABELS = [
  'T-Shirt Größe',
  'Essen',
] as const
