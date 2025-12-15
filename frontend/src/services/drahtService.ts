import axios from 'axios'

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

export class DrahtService {
  static async fetchTeamData(eventId: number): Promise<DrahtTeamData> {
    try {
      const response = await axios.get(`/events/${eventId}/draht-data`)
      const data = response.data
      
      const teamsExplore = Object.entries(data.teams_explore || {}).map(([id, team]: [string, any]) => ({
        id: Number(id),
        number: team.ref || id, // Use ref field from DRAHT API, fallback to id
        name: team.name
      }))
      
      const teamsChallenge = Object.entries(data.teams_challenge || {}).map(([id, team]: [string, any]) => ({
        id: Number(id),
        number: team.ref || id, // Use ref field from DRAHT API, fallback to id
        name: team.name
      }))
      
      return {
        teamsExplore,
        teamsChallenge,
        hasDiscrepancy: false, // Will be calculated by checkDiscrepancy
        capacityExplore: data.capacity_explore || 0,
        capacityChallenge: data.capacity_challenge || 0
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
  
  static async fetchLocalTeams(eventId: number, program: 'explore' | 'challenge'): Promise<Array<{id: number, team_number_hot: number, name: string}>> {
    try {
      const response = await axios.get(`/events/${eventId}/teams?program=${program}`)
      // Handle both array format and object format (for Explore teams with metadata)
      const teamsArray = Array.isArray(response.data) ? response.data : (response.data.teams || [])
      return teamsArray
    } catch (error) {
      console.error(`Failed to fetch local ${program} teams:`, error)
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
  
  static async getTeamCounts(eventId: number): Promise<{
    exploreCount: number
    challengeCount: number
    hasDiscrepancy: boolean
    exploreCapacity: number
    challengeCapacity: number
  }> {
    const [drahtData, localExplore, localChallenge] = await Promise.all([
      this.fetchTeamData(eventId),
      this.fetchLocalTeams(eventId, 'explore'),
      this.fetchLocalTeams(eventId, 'challenge')
    ])
    
    const exploreDiscrepancy = this.checkDiscrepancy(localExplore, drahtData.teamsExplore)
    const challengeDiscrepancy = this.checkDiscrepancy(localChallenge, drahtData.teamsChallenge)
    
    return {
      exploreCount: drahtData.teamsExplore.length,
      challengeCount: drahtData.teamsChallenge.length,
      hasDiscrepancy: exploreDiscrepancy.hasDiscrepancy || challengeDiscrepancy.hasDiscrepancy,
      exploreCapacity: drahtData.capacityExplore,
      challengeCapacity: drahtData.capacityChallenge
    }
  }
}
