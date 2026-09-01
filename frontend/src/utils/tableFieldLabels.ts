/** Mirrors backend App\Support\TableFieldLabels for Challenge / Future 8+. */

export const TABLE_FIELD_MAX_LENGTH = 100

export const FIRST_PROGRAM_CHALLENGE = 3
export const FIRST_PROGRAM_FUTURE_8 = 8

export function supportsTableFieldLabels(firstProgramId: number): boolean {
  return firstProgramId === FIRST_PROGRAM_CHALLENGE || firstProgramId === FIRST_PROGRAM_FUTURE_8
}

export function tableFieldNoun(firstProgramId: number): string {
  if (firstProgramId === FIRST_PROGRAM_FUTURE_8) return 'Spielfeld'
  return 'Tisch'
}

export function defaultTableFieldLabel(firstProgramId: number, tableNumber: number): string {
  return `${tableFieldNoun(firstProgramId)} ${tableNumber}`
}

export function effectiveTableFieldLabel(
  firstProgramId: number,
  tableNumber: number,
  custom: string | null | undefined,
): string {
  const trimmed = (custom ?? '').trim()
  if (trimmed !== '') return trimmed
  return defaultTableFieldLabel(firstProgramId, tableNumber)
}

/** Count param name on the plan. */
export function tableCountParamName(firstProgramId: number): string {
  return firstProgramId === FIRST_PROGRAM_FUTURE_8 ? 'f8_fields' : 'r_tables'
}

/**
 * @param customs 0-based array of custom names for slots 1..count
 * @returns duplicate effective labels, or empty if unique
 */
export function duplicateEffectiveTableFieldLabels(
  firstProgramId: number,
  customs: string[],
): string[] {
  const seen = new Map<string, string>()
  const dupes: string[] = []
  for (let i = 0; i < customs.length; i++) {
    const label = effectiveTableFieldLabel(firstProgramId, i + 1, customs[i])
    const key = label.toLowerCase()
    if (seen.has(key)) {
      if (!dupes.includes(label)) dupes.push(label)
    } else {
      seen.set(key, label)
    }
  }
  return dupes
}
