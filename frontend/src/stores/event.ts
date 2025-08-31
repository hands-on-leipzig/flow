import {defineStore} from 'pinia'
import axios from "axios"
import FllEvent from "@/models/FllEvent"

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
                this.selectedEvent = new FllEvent(response.data)
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
                this.selectedEvent = new FllEvent(eventResponse.data)
            } catch (error) {
                console.error('Failed to update selected event', error)
            }
        },
    },
})