export type RosterColumnMeta = {
  key: string
  label: string
  sortable?: boolean
  kind?: 'fixed' | 'custom'
  type?: 'text' | 'number' | 'boolean' | 'select'
  editor?: 't_shirt' | 'meal' | 'text' | 'photo_consent'
  field_key?: string
  options?: Array<{value: string; label: string}>
  public_form?: boolean
}

export function rosterColumnLabel(columns: RosterColumnMeta[], key: string): string {
  return columns.find((column) => column.key === key)?.label ?? key
}
