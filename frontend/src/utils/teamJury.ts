import {programMatchesSlug} from '@/utils/eventPrograms'

export type JuryLaneByPlanSlot = Record<number, number>

/** Build slot → lane map from loaded teams (lane follows plan slot, not team id). */
export function buildJuryLaneByPlanSlot(
  teams: Array<{team_number_plan?: number | null; jury_lane?: number | null}>,
): JuryLaneByPlanSlot {
  const map: JuryLaneByPlanSlot = {}
  for (const team of teams) {
    const slot = Number(team.team_number_plan ?? 0)
    const lane = team.jury_lane
    if (slot > 0 && lane != null && lane >= 1) {
      map[slot] = lane
    }
  }
  return map
}

/** Re-attach jury_lane after reorder using each row's current team_number_plan. */
export function applyJuryLanesForPlanSlots<T extends {team_number_plan?: number | null; jury_lane?: number | null}>(
  teams: T[],
  slotMap: JuryLaneByPlanSlot,
): T[] {
  return teams.map((team) => {
    const slot = Number(team.team_number_plan ?? 0)
    const lane = slot > 0 ? slotMap[slot] : undefined
    return {
      ...team,
      jury_lane: lane ?? null,
    }
  })
}

export function formatJuryCell(program: string, juryLane: number | null | undefined): string {
  if (juryLane == null || juryLane < 1) return '–'
  const letter = programMatchesSlug(program, 'explore') ? 'G' : 'J'
  return `${letter}${juryLane}`
}

export function juryCellAriaLabel(
  program: string,
  juryLane: number | null | undefined,
): string | undefined {
  if (juryLane == null || juryLane < 1) return undefined
  const role = programMatchesSlug(program, 'explore') ? 'Gutachtergruppe' : 'Jurygruppe'
  return `${role} ${juryLane}`
}
