/** Age counts on January 1st of the year the season starts in (from DRAHT). */
export type PlayerAgeRule = {
  minAge: number
  maxAge: number
  referenceDate: string
}

export type TeamPlayer = {
  firstname: string
  name: string
  gender: string
  birthday: string | null
}

export const PLAYER_GENDER_OPTIONS = [
  {value: '', label: '—'},
  {value: 'F', label: 'Weiblich'},
  {value: 'M', label: 'Männlich'},
  {value: 'D', label: 'Divers'},
] as const

export function playerAgeOn(birthday: string | null, referenceDate: string): number | null {
  const birth = /^(\d{4})-(\d{2})-(\d{2})$/.exec(birthday ?? '')
  const ref = /^(\d{4})-(\d{2})-(\d{2})$/.exec(referenceDate)
  if (!birth || !ref) return null
  const [by, bm, bd] = birth.slice(1).map(Number)
  const [ry, rm, rd] = ref.slice(1).map(Number)
  let age = ry - by
  if (rm < bm || (rm === bm && rd < bd)) age -= 1
  return age
}

/** Age on the reference date when outside the rule; null when allowed or not checkable. */
export function playerAgeOutOfRange(birthday: string | null, rule: PlayerAgeRule | null): number | null {
  if (!rule) return null
  const age = playerAgeOn(birthday, rule.referenceDate)
  if (age === null) return null
  return age < rule.minAge || age > rule.maxAge ? age : null
}

export function formatReferenceDate(rule: PlayerAgeRule): string {
  const [y, m, d] = rule.referenceDate.split('-')
  return `${d}.${m}.${y}`
}
