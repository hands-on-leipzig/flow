import {defineStore} from 'pinia'
import axios from "axios"

export const useEventStore = defineStore('event', {
    state: () => ({
        selectedEventId: null,
        selectedEvent: null,
    }),

    actions: {
        async fetchSelectedEvent() {
            try {
                const response = await axios.get('/user/selected-event')
                this.selectedEventId = response.data
                if (this.selectedEventId) {
                    const eventResponse = await axios.get(`/events/${this.selectedEventId.selected_event}`)
                    this.selectedEvent = eventResponse.data
                }
            } catch (error) {
                console.error('Failed to fetch selected event', error)
            }
        },

        async setSelectedEvent(eventId) {
            try {
                await axios.post('/user/selected-event', {event_id: eventId})
                this.selectedEventId = eventId

                // Also load the event object
                const eventResponse = await axios.get(`/api/events/${eventId}`)
                this.selectedEvent = eventResponse.data
            } catch (error) {
                console.error('Failed to update selected event', error)
            }
        },
    },
})


/*import {defineStore} from 'pinia'
import axios from "axios";

export const useEventStore = defineStore('event', {
    state: () => ({
        selectedEventId: null,
    }),
    actions: {
        async fetchSelectedEvent() {
            try {
                const response = await axios.get('/user/selected-event')
                this.selectedEventId = response.data
            } catch (error) {
                console.error('Failed to fetch selected event', error)
            }
        },
        async setSelectedEvent(eventId) {
            try {
                await axios.post('/user/selected-event', {event_id: eventId})
                this.selectedEventId = eventId
            } catch (error) {
                console.error('Failed to update selected event', error)
            }
        }
    }
})
*/