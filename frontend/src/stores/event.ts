import {defineStore} from 'pinia'
import axios from "axios"
import FllEvent  from "@/models/FllEvent"
import {DrahtService} from "@/services/drahtService"
import {usePlanCacheStore} from '@/stores/planCache'
import {setEventProgramLogos} from '@/utils/images'

interface EventStoreState {
  selectedEvent: FllEvent | null
  selectedEventId?: number
  readiness: any | null
  staleSeasonCleared: boolean
  currentSeasonId: number | null
}

export const useEventStore = defineStore('event', {
    state: (): EventStoreState => ({
        selectedEvent: null,
        readiness: null,
        staleSeasonCleared: false,
        currentSeasonId: null,
    }),

    getters: {
        getSelectedEvent: (state) => state.selectedEvent,
    },

    actions: {
        clearSelectedEvent() {
            this.selectedEvent = null
            this.selectedEventId = undefined
            this.readiness = null
            setEventProgramLogos([])
        },

        async fetchCurrentSeasonId(): Promise<number | null> {
            if (this.currentSeasonId !== null) {
                return this.currentSeasonId
            }

            try {
                const { data } = await axios.get<{ id: number }>('/current-season')
                this.currentSeasonId = data.id
                return data.id
            } catch (error) {
                console.error('Failed to fetch current season', error)
                return null
            }
        },

        isEventFromCurrentSeason(event: FllEvent, currentSeasonId: number): boolean {
            const eventSeasonId = typeof event.season === 'object'
                ? (event.season as { id: number }).id
                : event.season

            return eventSeasonId === currentSeasonId
        },

        async clearStaleSeasonSelection() {
            try {
                await axios.delete('/user/selected-event')
            } catch (error) {
                console.error('Failed to clear stale season selection', error)
            }
            this.clearSelectedEvent()
            this.staleSeasonCleared = true
        },

        async validateSelectedEventSeason(): Promise<boolean> {
            // Past seasons are allowed for viewing/switching.
            return !!this.selectedEvent
        },

        async fetchSelectedEvent() {
            try {
                const response = await axios.get<any>('/user/selected-event')

                if (response.data.cleared_stale_season) {
                    await this.clearStaleSeasonSelection()
                    return
                }

                // Check if there's actually an event selected
                if (response.data.selected_event === null || !response.data.id) {
                    this.clearSelectedEvent()
                    return
                }

                const event = new FllEvent(response.data)

                // Fetch DRAHT team data
                if (event.id) {
                    await this.loadDrahtTeamData(event)
                }

                this.selectedEvent = event
                setEventProgramLogos(event.programs)
                this.staleSeasonCleared = false
            } catch (error) {
                console.error('Failed to fetch selected event', error)
                this.clearSelectedEvent()
            }
        },

        async setSelectedEvent(eventId: number) {
            try {
                await axios.post('/user/selected-event', {event_id: eventId})
                this.selectedEventId = eventId

                // Also load the event object
                const eventResponse = await axios.get<any>(`/api/events/${eventId}`)
                const event = new FllEvent(eventResponse.data)
                
                // Fetch DRAHT team data
                await this.loadDrahtTeamData(event)
                
                this.selectedEvent = event
                setEventProgramLogos(event.programs)
            } catch (error) {
                console.error('Failed to update selected event', error)
            }
        },
        
        async loadDrahtTeamData(event: FllEvent) {
            try {
                // getTeamCounts is in-flight-deduped; draht-data goes through planCache
                const teamCounts = await DrahtService.getTeamCounts(event.id)
                event.drahtTeamsExplore = teamCounts.exploreCount
                event.drahtTeamsChallenge = teamCounts.challengeCount
                event.hasTeamDiscrepancy = teamCounts.hasDiscrepancy
                event.discrepancyByProgram = teamCounts.discrepancyByProgram
                event.drahtCapacityExplore = teamCounts.exploreCapacity
                event.drahtCapacityChallenge = teamCounts.challengeCapacity

                if (this.selectedEvent && this.selectedEvent.id === event.id) {
                    this.$patch({
                        selectedEvent: {
                            ...this.selectedEvent,
                            drahtTeamsExplore: teamCounts.exploreCount,
                            drahtTeamsChallenge: teamCounts.challengeCount,
                            hasTeamDiscrepancy: teamCounts.hasDiscrepancy,
                            discrepancyByProgram: teamCounts.discrepancyByProgram,
                            drahtCapacityExplore: teamCounts.exploreCapacity,
                            drahtCapacityChallenge: teamCounts.challengeCapacity
                        }
                    })
                }
            } catch (error) {
                console.error('Failed to load DRAHT team data:', error)
                event.drahtTeamsExplore = 0
                event.drahtTeamsChallenge = 0
                event.hasTeamDiscrepancy = false
                event.discrepancyByProgram = {}
                event.drahtCapacityExplore = 0
                event.drahtCapacityChallenge = 0

                if (this.selectedEvent && this.selectedEvent.id === event.id) {
                    this.$patch({
                        selectedEvent: {
                            ...this.selectedEvent,
                            drahtTeamsExplore: 0,
                            drahtTeamsChallenge: 0,
                            hasTeamDiscrepancy: false,
                            discrepancyByProgram: {},
                            drahtCapacityExplore: 0,
                            drahtCapacityChallenge: 0
                        }
                    })
                }
            }
        },

        async refreshDrahtTeamData() {
            if (this.selectedEvent) {
                usePlanCacheStore().invalidateDraht()
                await this.loadDrahtTeamData(this.selectedEvent)
            }
        },

        async updateTeamDiscrepancyStatus() {
            if (this.selectedEvent) {
                await this.loadDrahtTeamData(this.selectedEvent)
            }
        },
    
        async refreshReadiness(eventId: number) {
        try {
            const { data } = await axios.get(`/export/ready/${eventId}`)
            this.readiness = data
            return data
        } catch (error) {
            console.error('Failed to refresh readiness:', error)
            this.readiness = null
            return null
        }
        },
    },
})