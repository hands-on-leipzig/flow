export type SlotActivity = {start: string; end: string; label: string}

export type ProgramSlotSchedule = {
  first_program: number
  transfer_minutes: number
  slots: Record<string, SlotActivity[]>
}

/** Where one of a coach's teams sits: program and plan slot. */
export type TeamPlacement = {programId: number; slot: number | null; name: string}

export type CoachConflict = {
  status: 'red' | 'yellow'
  /** Minutes between the two activities; 0 when they overlap. */
  gapMinutes: number
  transferMinutes: number
  first: {team: TeamPlacement; activity: SlotActivity}
  second: {team: TeamPlacement; activity: SlotActivity}
}

function minutes(value: string): number {
  return Date.parse(value.replace(' ', 'T')) / 60000
}

export function timeOf(value: string): string {
  return value.slice(11, 16)
}

/**
 * Worst clash between two teams of the same coach: overlapping activities (red), else a gap shorter
 * than the transfer time of either program (yellow). Null when the schedule leaves enough room.
 */
export function findCoachConflict(
  a: TeamPlacement,
  b: TeamPlacement,
  schedules: Map<number, ProgramSlotSchedule>,
): CoachConflict | null {
  if (!a.slot || !b.slot) return null
  const scheduleA = schedules.get(a.programId)
  const scheduleB = schedules.get(b.programId)
  const activitiesA = scheduleA?.slots[String(a.slot)] ?? []
  const activitiesB = scheduleB?.slots[String(b.slot)] ?? []
  const transfer = Math.max(scheduleA?.transfer_minutes ?? 0, scheduleB?.transfer_minutes ?? 0)

  let closest: CoachConflict | null = null
  for (const first of activitiesA) {
    for (const second of activitiesB) {
      const startA = minutes(first.start)
      const endA = minutes(first.end)
      const startB = minutes(second.start)
      const endB = minutes(second.end)
      const [earlier, later] = startA <= startB
        ? [{team: a, activity: first}, {team: b, activity: second}]
        : [{team: b, activity: second}, {team: a, activity: first}]
      if (startA < endB && startB < endA) {
        return {status: 'red', gapMinutes: 0, transferMinutes: transfer, first: earlier, second: later}
      }
      const gap = Math.round(startA < startB ? startB - endA : startA - endB)
      if (gap < transfer && (!closest || gap < closest.gapMinutes)) {
        closest = {status: 'yellow', gapMinutes: gap, transferMinutes: transfer, first: earlier, second: later}
      }
    }
  }
  return closest
}

function describeSide(side: CoachConflict['first']): string {
  const {team, activity} = side
  return `${team.name}: ${activity.label} ${timeOf(activity.start)}–${timeOf(activity.end)}`
}

export function describeCoachConflict(conflict: CoachConflict): string {
  const left = describeSide(conflict.first)
  const right = describeSide(conflict.second)
  if (conflict.status === 'red') return `Gleichzeitig – ${left} / ${right}`
  return `Nur ${conflict.gapMinutes} Min Wechsel (Transfer ${conflict.transferMinutes} Min) – ${left} / ${right}`
}
