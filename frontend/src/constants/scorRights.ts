export type ScorRightKey =
  | 'can_scor_referee'
  | 'can_scor_head_referee'
  | 'can_scor_jury'
  | 'can_scor_head_judge'
  | 'can_scor_admin'

export type ScorRights = Partial<Record<ScorRightKey, boolean | null>>

/** WISE rights per event. Veranstaltung-Admin is the RP and includes everything. */
export const SCOR_RIGHTS: {key: ScorRightKey, label: string}[] = [
  {key: 'can_scor_referee', label: 'Schiri'},
  {key: 'can_scor_head_referee', label: 'Head Ref'},
  {key: 'can_scor_jury', label: 'Jury'},
  {key: 'can_scor_head_judge', label: 'Head Judge'},
  {key: 'can_scor_admin', label: 'Veranstaltung-Admin'},
]

export function hasScorRight(rights: ScorRights): boolean {
  return SCOR_RIGHTS.some(({key}) => Boolean(rights[key]))
}

export function scorRightLabels(rights: ScorRights): string[] {
  return SCOR_RIGHTS.filter(({key}) => Boolean(rights[key])).map(({label}) => label)
}

export function noScorRights(): Record<ScorRightKey, boolean> {
  return Object.fromEntries(SCOR_RIGHTS.map(({key}) => [key, false])) as Record<ScorRightKey, boolean>
}
