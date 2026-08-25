import axios from 'axios'
import {usePlanCacheStore} from '@/stores/planCache'
import {programCompact, programSlug} from '@/utils/eventPrograms'

export interface DrahtTeamData {
  teamsExplore: Array<{id: number, number: string, name: string}>
  teamsChallenge: Array<{id: number, number: string, name: string}>
  hasDiscrepancy: boolean
  capacityExplore: number
  capacityChallenge: number
}

export interface TeamDiscrepancy {
  type: 'conflict' | 'new' | 'missing'
  teamNumber: number
  localName?: string
  drahtName?: string
}

export interface TeamCounts {
  exploreCount: number
  challengeCount: number
  hasDiscrepancy: boolean
  exploreCapacity: number
  challengeCapacity: number
  discrepancyByProgram: Record<string, boolean>
}

type DrahtTeamRow = {id: number, number: string, name: string}

/** In-flight / short-lived dedupe for discrepancy checks (same event, same SPA tick). */
const teamCountsInflight = new Map<number, Promise<TeamCounts>>()

function mapDrahtTeams(teams: any): DrahtTeamRow[] {
  return Object.entries(teams || {}).map(([id, team]: [string, any]) => ({
    id: Number(id),
    number: team.ref || id,
    name: team.name
  }))
}

export class DrahtService {
  static async fetchTeamData(eventId: number): Promise<DrahtTeamData> {
    try {
      const data = await usePlanCacheStore().getDrahtData(eventId)
      const programs = Array.isArray(data.programs) ? data.programs : []
      const byName = (name: string) =>
        programs.find((p: any) => String(p.name || '').toUpperCase() === name) || {}

      const explore = byName('EXPLORE')
      const challenge = byName('CHALLENGE')

      return {
        teamsExplore: mapDrahtTeams(explore.teams),
        teamsChallenge: mapDrahtTeams(challenge.teams),
        hasDiscrepancy: false,
        capacityExplore: explore.capacity || 0,
        capacityChallenge: challenge.capacity || 0
      }
    } catch (error) {
      console.error('Failed to fetch DRAHT team data:', error)
      return {
        teamsExplore: [],
        teamsChallenge: [],
        hasDiscrepancy: false,
        capacityExplore: 0,
        capacityChallenge: 0
      }
    }
  }

  static async fetchLocalTeams(eventId: number, slug: string): Promise<Array<{id: number, team_number_hot: number, name: string}>> {
    try {
      const response = await axios.get(`/events/${eventId}/teams?program=${slug}`)
      const teamsArray = Array.isArray(response.data) ? response.data : (response.data.teams || [])
      return teamsArray
    } catch (error) {
      console.error(`Failed to fetch local ${slug} teams:`, error)
      return []
    }
  }

  static checkDiscrepancy(
    localTeams: Array<{team_number_hot: number, name: string}>,
    drahtTeams: Array<{number: string, name: string}>
  ): {hasDiscrepancy: boolean, discrepancies: TeamDiscrepancy[]} {
    const discrepancies: TeamDiscrepancy[] = []

    const localMap = new Map(localTeams.map(t => [t.team_number_hot, t]))
    const drahtMap = new Map(drahtTeams.map(t => [Number(t.number), t]))

    const allNumbers = new Set([
      ...localTeams.map(t => t.team_number_hot),
      ...drahtTeams.map(t => Number(t.number))
    ])

    allNumbers.forEach(number => {
      const local = localMap.get(number)
      const draht = drahtMap.get(number)

      if (local && draht) {
        if (local.name !== draht.name) {
          discrepancies.push({
            type: 'conflict',
            teamNumber: number,
            localName: local.name,
            drahtName: draht.name
          })
        }
      } else if (draht && !local) {
        discrepancies.push({
          type: 'new',
          teamNumber: number,
          drahtName: draht.name
        })
      } else if (local && !draht) {
        discrepancies.push({
          type: 'missing',
          teamNumber: number,
          localName: local.name
        })
      }
    })

    return {
      hasDiscrepancy: discrepancies.length > 0,
      discrepancies
    }
  }

  static async getTeamCounts(eventId: number): Promise<TeamCounts> {
    const existing = teamCountsInflight.get(eventId)
    if (existing) return existing

    const empty: TeamCounts = {
      exploreCount: 0,
      challengeCount: 0,
      hasDiscrepancy: false,
      exploreCapacity: 0,
      challengeCapacity: 0,
      discrepancyByProgram: {},
    }

    const promise = (async () => {
      const data = await usePlanCacheStore().getDrahtData(eventId)
      const programs = Array.isArray(data.programs) ? data.programs : []

      const perProgram = await Promise.all(programs.map(async (program: any) => {
        const slug = programSlug(program.name)
        const key = programCompact(program.name)
        const drahtTeams = mapDrahtTeams(program.teams)
        const localTeams = await this.fetchLocalTeams(eventId, slug)
        const discrepancy = this.checkDiscrepancy(localTeams, drahtTeams)
        return {
          key,
          slug,
          count: drahtTeams.length,
          capacity: Number(program.capacity || 0),
          hasDiscrepancy: discrepancy.hasDiscrepancy,
        }
      }))

      const discrepancyByProgram: Record<string, boolean> = {}
      let exploreCount = 0
      let challengeCount = 0
      let exploreCapacity = 0
      let challengeCapacity = 0

      for (const row of perProgram) {
        discrepancyByProgram[row.key] = row.hasDiscrepancy
        if (row.key === 'explore') {
          exploreCount = row.count
          exploreCapacity = row.capacity
        }
        if (row.key === 'challenge') {
          challengeCount = row.count
          challengeCapacity = row.capacity
        }
      }

      return {
        exploreCount,
        challengeCount,
        hasDiscrepancy: perProgram.some((row) => row.hasDiscrepancy),
        exploreCapacity,
        challengeCapacity,
        discrepancyByProgram,
      }
    })().catch((error) => {
      console.error('Failed to fetch DRAHT team data:', error)
      return empty
    })

    teamCountsInflight.set(eventId, promise)
    void promise.finally(() => {
      setTimeout(() => {
        if (teamCountsInflight.get(eventId) === promise) {
          teamCountsInflight.delete(eventId)
        }
      }, 2000)
    })

    return promise
  }
}
