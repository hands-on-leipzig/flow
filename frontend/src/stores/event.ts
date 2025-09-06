import {defineStore} from 'pinia'
import axios from "axios"
import FllEvent from "@/models/FllEvent"
import {DrahtService} from "@/services/drahtService"

interface EventStoreState {
  selectedEvent: FllEvent | null
  selectedEventId?: number
}

export const useEventStore = defineStore('event', {
    state: (): EventStoreState => ({
        selectedEvent: null,
    }),

    actions: {
        async fetchSelectedEvent() {
            try {
                const response = await axios.get<any>('/user/selected-event')
                const event = new FllEvent(response.data)
                
                // Fetch DRAHT team data
                if (event.id) {
                    await this.loadDrahtTeamData(event)
                }
                
                this.selectedEvent = event
            } catch (error) {
                console.error('Failed to fetch selected event', error)
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
            } catch (error) {
                console.error('Failed to update selected event', error)
            }
        },
        
        async loadDrahtTeamData(event: FllEvent) {
            try {
                const teamCounts = await DrahtService.getTeamCounts(event.id)
                event.drahtTeamsExplore = teamCounts.exploreCount
                event.drahtTeamsChallenge = teamCounts.challengeCount
                event.hasTeamDiscrepancy = teamCounts.hasDiscrepancy
                event.drahtCapacityExplore = teamCounts.exploreCapacity
                event.drahtCapacityChallenge = teamCounts.challengeCapacity
                
                // Update store state using $patch for proper reactivity
                if (this.selectedEvent && this.selectedEvent.id === event.id) {
                    this.$patch({
                        selectedEvent: {
                            ...this.selectedEvent,
                            drahtTeamsExplore: teamCounts.exploreCount,
                            drahtTeamsChallenge: teamCounts.challengeCount,
                            hasTeamDiscrepancy: teamCounts.hasDiscrepancy,
                            drahtCapacityExplore: teamCounts.exploreCapacity,
                            drahtCapacityChallenge: teamCounts.challengeCapacity
                        }
                    })
                }
            } catch (error) {
                console.error('Failed to load DRAHT team data:', error)
                // Set defaults on error
                event.drahtTeamsExplore = 0
                event.drahtTeamsChallenge = 0
                event.hasTeamDiscrepancy = false
                event.drahtCapacityExplore = 0
                event.drahtCapacityChallenge = 0
                
                // Update store state using $patch for proper reactivity
                if (this.selectedEvent && this.selectedEvent.id === event.id) {
                    this.$patch({
                        selectedEvent: {
                            ...this.selectedEvent,
                            drahtTeamsExplore: 0,
                            drahtTeamsChallenge: 0,
                            hasTeamDiscrepancy: false,
                            drahtCapacityExplore: 0,
                            drahtCapacityChallenge: 0
                        }
                    })
                }
            }
        },
        
        async refreshDrahtTeamData() {
            if (this.selectedEvent) {
                await this.loadDrahtTeamData(this.selectedEvent)
            }
        },
        
        async updateTeamDiscrepancyStatus() {
            if (this.selectedEvent) {
                await this.loadDrahtTeamData(this.selectedEvent)
            }
        }
    },
})