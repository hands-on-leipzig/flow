import {tableColumns, type VolunteerColumnDef} from '@/volunteers/columns/types'

export const PERSON_COLUMN_DEFINITIONS = [
  {key: 'first_name', label: 'Vorname', table: true, export: true, sortable: true},
  {key: 'last_name', label: 'Nachname', table: true, export: true, sortable: true},
  {key: 'nickname', label: 'Spitzname', table: true, export: true},
  {key: 'email', label: 'E-Mail', table: true, export: true},
  {key: 'mobile', label: 'Mobil', table: true, export: true},
  {key: 'updated_at', label: 'Letzte Änderung', table: true, export: true},
] as const satisfies readonly VolunteerColumnDef[]

export const PERSON_TABLE_COLUMNS = tableColumns([...PERSON_COLUMN_DEFINITIONS])

export const PERSON_IMPORT_COLUMN_KEYS = ['first_name', 'last_name', 'nickname', 'email', 'mobile'] as const

export function personImportColumnLabels(): string[] {
  return PERSON_IMPORT_COLUMN_KEYS.map(
    (key) => PERSON_COLUMN_DEFINITIONS.find((column) => column.key === key)?.label ?? key,
  )
}

export function personImportFormatHint(): string {
  return personImportColumnLabels().join(', ')
}
