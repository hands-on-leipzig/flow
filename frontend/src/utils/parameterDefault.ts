/** Match ParameterField: treat 1 / true / '1' as yes. */
function normalizeBoolean(val: unknown): boolean {
  return val === 1 || val === true || val === '1'
}

function normalizeTimeFormat(timeString: unknown): unknown {
  if (!timeString || typeof timeString !== 'string') return timeString
  const [hours, minutes] = timeString.split(':')
  if (!hours || !minutes) return timeString
  return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}`
}

export type ParameterDefaultComparable = {
  name?: string | null
  type?: string | null
  value?: unknown
  default_value?: unknown
}

/**
 * Same rules as ParameterField: no default → not changed; names containing "team" ignored.
 */
export function isParameterChangedFromDefault(param: ParameterDefaultComparable): boolean {
  if (param.default_value === null || param.default_value === undefined) return false
  if (param.name && param.name.toLowerCase().includes('team')) return false

  switch (param.type) {
    case 'boolean':
      return normalizeBoolean(param.value) !== normalizeBoolean(param.default_value)
    case 'integer':
    case 'decimal':
      return Number(param.value) !== Number(param.default_value)
    case 'time':
      return normalizeTimeFormat(param.value) !== normalizeTimeFormat(param.default_value)
    default:
      return param.value !== param.default_value
  }
}

export function countParametersChangedFromDefault(
  params: ParameterDefaultComparable[],
): number {
  return params.reduce((n, param) => n + (isParameterChangedFromDefault(param) ? 1 : 0), 0)
}

export function changedFromDefaultSuffix(count: number): string {
  return count > 0 ? ` (${count} verändert)` : ''
}
