export type TeamDataColumn = {
  key: string
  label: string
  kind: 'meal' | 'custom'
  type?: string
  editor: 'meal_counts' | 'text' | 'number' | 'count_set'
  field_key?: string
  options?: Array<{value: string; label: string}>
  boolean_keys?: string[]
}

export type TeamDataRow = {
  id: number
  name: string
  team_number_hot: number | null
  team_number_plan: number | null
  first_program: number | null
  program_label: string
  people_count: number | null
  meals?: Record<string, number>
  custom: Record<string, unknown>
  touched?: {
    meal?: boolean
    custom?: Record<string, boolean>
  }
}

export function sumCountMap(map: Record<string, number> | null | undefined): number {
  if (!map) return 0
  return Object.values(map).reduce((sum, value) => sum + Number(value || 0), 0)
}

function isScalarColumnIncomplete(row: TeamDataRow, column: TeamDataColumn): boolean {
  const fieldKey = column.field_key
  if (!fieldKey) return false

  if (column.editor === 'text') {
    const value = row.custom[fieldKey]
    return typeof value !== 'string' || value.trim() === ''
  }

  if (column.editor === 'number') {
    const value = row.custom[fieldKey]
    return value === null || value === undefined
  }

  return false
}

function isCountSetColumnIncomplete(row: TeamDataRow, column: TeamDataColumn): boolean {
  if (row.people_count === null) {
    return false
  }

  if (column.editor === 'meal_counts') {
    if (!row.touched?.meal) return true
    return sumCountMap(row.meals) !== row.people_count
  }

  const fieldKey = column.field_key
  if (!fieldKey) return false

  if (!row.touched?.custom?.[fieldKey]) return true
  const map = row.custom[fieldKey]
  if (!map || typeof map !== 'object') return true

  return sumCountMap(map as Record<string, number>) !== row.people_count
}

export function isTeamRowIncomplete(row: TeamDataRow, columns: TeamDataColumn[]): boolean {
  for (const column of columns) {
    if (column.editor === 'text' || column.editor === 'number') {
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

  if (column.editor === 'meal_counts') {
    if (!row.touched?.meal) return false
    return sumCountMap(row.meals) !== row.people_count
  }

  const fieldKey = column.field_key
  if (!fieldKey || column.editor !== 'count_set') return false
  if (!row.touched?.custom?.[fieldKey]) return false

  const map = row.custom[fieldKey]
  if (!map || typeof map !== 'object') return false

  return sumCountMap(map as Record<string, number>) !== row.people_count
}
