export type TeamDataColumn = {
  key: string
  label: string
  kind: 'meal' | 'custom' | 'photo'
  type?: string
  editor: 'meal_counts' | 'text' | 'number' | 'count_set' | 'boolean' | 'select'
  field_key?: string
  options?: Array<{value: string; label: string}>
  boolean_keys?: string[]
}

export type TeamDataRow = {
  id: number
  name: string
  organization: string | null
  team_number_hot: number | null
  team_number_plan: number | null
  first_program: number | null
  program_label: string
  people_count: number | null
  photo_consent?: Record<string, number>
  meals?: Record<string, number>
  custom: Record<string, unknown>
  touched?: {
    meal?: boolean
    photo?: boolean
    custom?: Record<string, boolean>
  }
}

export function sumCountMap(map: Record<string, number> | null | undefined): number {
  if (!map) return 0
  return Object.values(map).reduce((sum, value) => sum + Number(value || 0), 0)
}

function countMapForColumn(row: TeamDataRow, column: TeamDataColumn): Record<string, number> | null | undefined {
  if (column.key === 'photo_consent' || column.kind === 'photo') {
    return row.photo_consent
  }
  if (column.editor === 'meal_counts') {
    return row.meals
  }

  return null
}

function isTouchedForColumn(row: TeamDataRow, column: TeamDataColumn): boolean {
  if (column.key === 'photo_consent' || column.kind === 'photo') {
    return !!row.touched?.photo
  }
  if (column.editor === 'meal_counts') {
    return !!row.touched?.meal
  }

  return false
}

function customFieldValue(row: TeamDataRow, column: TeamDataColumn): unknown {
  const fieldKey = column.field_key
  if (!fieldKey) return null
  return row.custom[fieldKey] ?? null
}

function isScalarColumnIncomplete(row: TeamDataRow, column: TeamDataColumn): boolean {
  const value = customFieldValue(row, column)

  if (column.editor === 'text') {
    return typeof value !== 'string' || value.trim() === ''
  }

  if (column.editor === 'number') {
    return value === null || value === undefined
  }

  if (column.editor === 'boolean') {
    return value === null || value === undefined
  }

  if (column.editor === 'select') {
    return value === null || value === undefined || (typeof value === 'string' && value.trim() === '')
  }

  return false
}

function isCountSetColumnIncomplete(row: TeamDataRow, column: TeamDataColumn): boolean {
  if (row.people_count === null) {
    return false
  }

  if (!isTouchedForColumn(row, column)) return true
  return sumCountMap(countMapForColumn(row, column)) !== row.people_count
}

export function isTeamRowIncomplete(row: TeamDataRow, columns: TeamDataColumn[]): boolean {
  for (const column of columns) {
    if (column.editor === 'text' || column.editor === 'number' || column.editor === 'boolean' || column.editor === 'select') {
      if (isScalarColumnIncomplete(row, column)) return true
      continue
    }
    if (column.editor === 'meal_counts' || column.editor === 'count_set') {
      if (isCountSetColumnIncomplete(row, column)) return true
    }
  }

  return false
}

export function countSetCellMismatch(row: TeamDataRow, column: TeamDataColumn): boolean {
  if (row.people_count === null) return false
  if (!isTouchedForColumn(row, column)) return false

  return sumCountMap(countMapForColumn(row, column)) !== row.people_count
}

export function countSetTotal(row: TeamDataRow, column: TeamDataColumn): number {
  return sumCountMap(countMapForColumn(row, column))
}
