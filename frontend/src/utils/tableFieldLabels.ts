/** Challenge / Future 8+ table and mat labels from GET /table-field-labels. */

import { ref } from 'vue'
import axios from 'axios'

export const TABLE_FIELD_MAX_LENGTH = 100

export const FIRST_PROGRAM_CHALLENGE = 3
export const FIRST_PROGRAM_FUTURE_8 = 8

type ProgramLabels = {
  noun: string
  plural: string
  abbrev: string
  defaults_by_count: Record<string, string[]>
}

type LabelsPayload = {
  plural_slash: string
  [programId: string]: ProgramLabels | string
}

const cache = ref<LabelsPayload | null>(null)
let loadPromise: Promise<void> | null = null

export function ensureTableFieldLabels(): Promise<void> {
  if (cache.value) {
    return Promise.resolve()
  }
  if (!loadPromise) {
    loadPromise = axios
      .get<LabelsPayload>('/table-field-labels')
      .then((response) => {
        cache.value = response.data
      })
      .catch(() => {
        loadPromise = null
      })
      .then(() => undefined)
  }

  return loadPromise
}

function programEntry(firstProgramId: number): ProgramLabels | null {
  void ensureTableFieldLabels()
  const raw = cache.value?.[String(firstProgramId)]
  if (!raw || typeof raw === 'string') {
    return null
  }

  return raw
}

export function supportsTableFieldLabels(firstProgramId: number): boolean {
  return firstProgramId === FIRST_PROGRAM_CHALLENGE || firstProgramId === FIRST_PROGRAM_FUTURE_8
}

export function tableCountParamName(firstProgramId: number): string {
  return firstProgramId === FIRST_PROGRAM_FUTURE_8 ? 'f8_fields' : 'r_tables'
}

export function tableFieldNoun(firstProgramId: number): string {
  return programEntry(firstProgramId)?.noun ?? ''
}

export function tableFieldPlural(firstProgramId: number): string {
  return programEntry(firstProgramId)?.plural ?? ''
}

export function tableFieldAbbrev(firstProgramId: number): string {
  return programEntry(firstProgramId)?.abbrev ?? ''
}

export function tableFieldPluralSlash(): string {
  void ensureTableFieldLabels()
  return typeof cache.value?.plural_slash === 'string' ? cache.value.plural_slash : ''
}

export function defaultTableFieldLabel(
  firstProgramId: number,
  tableNumber: number,
  tableCount = 0,
): string {
  const program = programEntry(firstProgramId)
  if (!program) {
    return ''
  }
  const mapKey = tableCount === 4 || tableNumber >= 3 ? '4' : '2'
  return program.defaults_by_count[mapKey]?.[tableNumber - 1] ?? ''
}

export function effectiveTableFieldLabel(
  firstProgramId: number,
  tableNumber: number,
  custom: string | null | undefined,
  tableCount = 0,
): string {
  const trimmed = (custom ?? '').trim()
  if (trimmed !== '') {
    return trimmed
  }

  return defaultTableFieldLabel(firstProgramId, tableNumber, tableCount)
}

/**
 * @param customs 0-based array of custom names for slots 1..count
 * @returns duplicate effective labels, or empty if unique
 */
export function duplicateEffectiveTableFieldLabels(
  firstProgramId: number,
  customs: string[],
  tableCount = customs.length,
): string[] {
  const seen = new Map<string, string>()
  const dupes: string[] = []
  for (let i = 0; i < customs.length; i++) {
    const label = effectiveTableFieldLabel(firstProgramId, i + 1, customs[i], tableCount)
    const key = label.toLowerCase()
    if (seen.has(key)) {
      if (!dupes.includes(label)) {
        dupes.push(label)
      }
    } else {
      seen.set(key, label)
    }
  }

  return dupes
}
