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

const STORAGE_KEY = 'flow.noticeToday'

const emptyDots = (): NoticeDots => ({
  schedule: false,
  rooms: false,
  rooms_activities: false,
  rooms_teams: false,
  volunteers_staffing: false,
  teams_by_program: {},
})

function isIsoDate(value: string): boolean {
  return /^\d{4}-\d{2}-\d{2}$/.test(value)
}

function readSimulatedToday(): string | null {
  if (typeof sessionStorage === 'undefined') return null
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY)
    if (!raw || !isIsoDate(raw)) return null
    return raw
  } catch {
    return null
  }
}

function writeSimulatedToday(value: string | null) {
  if (typeof sessionStorage === 'undefined') return
  try {
    if (value) sessionStorage.setItem(STORAGE_KEY, value)
    else sessionStorage.removeItem(STORAGE_KEY)
  } catch {
    /* ignore quota / private mode */
  }
}

export const useNoticeStore = defineStore('notice', {
  state: () => ({
    eventId: null as number | null,
    messages: [] as NoticeMessage[],
    dots: emptyDots() as NoticeDots,
    restore_available: false,
    simulatedToday: readSimulatedToday() as string | null,
    useSimulatedToday: false,
  }),

  actions: {
    setUseSimulatedToday(value: boolean) {
      this.useSimulatedToday = value
    },

    async setSimulatedToday(value: string | null) {
      const next = value && isIsoDate(value) ? value : null
      this.simulatedToday = next
      writeSimulatedToday(next)
      if (this.eventId) await this.refresh(this.eventId)
    },

    async refresh(eventId: number) {
      try {
        const params =
          this.useSimulatedToday && this.simulatedToday
            ? {today: this.simulatedToday}
            : undefined
        const {data} = await axios.get(`/events/${eventId}/notices`, {params})
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
