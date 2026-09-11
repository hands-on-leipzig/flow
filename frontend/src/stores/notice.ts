import {defineStore} from 'pinia'
import axios from 'axios'

export type NoticeMessage = {
  id: string
  notice_id: number
  kind: 'red_dot' | 'time'
  key: string
  condition_key: string | null
  body: string
  screen_key: string | null
  jump_path: string | null
  hideable: boolean
  program: string | null
  scope: string | null
}

export type NoticeDots = {
  schedule: boolean
  rooms: boolean
  rooms_activities: boolean
  rooms_teams: boolean
  volunteers_staffing: boolean
  teams_by_program: Record<string, boolean>
}

const emptyDots = (): NoticeDots => ({
  schedule: false,
  rooms: false,
  rooms_activities: false,
  rooms_teams: false,
  volunteers_staffing: false,
  teams_by_program: {},
})

export const useNoticeStore = defineStore('notice', {
  state: () => ({
    eventId: null as number | null,
    messages: [] as NoticeMessage[],
    dots: emptyDots() as NoticeDots,
    restore_available: false,
  }),

  actions: {
    async refresh(eventId: number) {
      try {
        const {data} = await axios.get(`/events/${eventId}/notices`)
        this.eventId = eventId
        this.messages = Array.isArray(data?.messages) ? data.messages : []
        this.dots = {
          ...emptyDots(),
          ...(data?.dots ?? {}),
          teams_by_program: data?.dots?.teams_by_program ?? {},
        }
        this.restore_available = !!data?.restore_available
      } catch (error) {
        console.error('Failed to refresh notices:', error)
        this.eventId = eventId
        this.messages = []
        this.dots = emptyDots()
        this.restore_available = false
      }
    },

    async hide(noticeId: number) {
      if (!this.eventId) return
      await axios.post(`/events/${this.eventId}/notices/${noticeId}/hide`)
      await this.refresh(this.eventId)
    },

    async restore() {
      if (!this.eventId) return
      await axios.post(`/events/${this.eventId}/notices/restore`)
      await this.refresh(this.eventId)
    },
  },
})
