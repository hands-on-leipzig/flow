export type VolunteerTableColumn = {
  key: string
  label: string
  sortable?: boolean
}

export type VolunteerColumnDef = VolunteerTableColumn & {
  table?: boolean
  export?: boolean
}

export function tableColumns(definitions: VolunteerColumnDef[]): VolunteerTableColumn[] {
  return definitions
    .filter((column) => column.table !== false)
    .map(({key, label, sortable}) => ({key, label, sortable: sortable ?? false}))
}

export function exportLabels(definitions: VolunteerColumnDef[]): string[] {
  return definitions.filter((column) => column.export).map((column) => column.label)
}

export function columnLabel(definitions: VolunteerColumnDef[], key: string): string {
  return definitions.find((column) => column.key === key)?.label ?? key
}
