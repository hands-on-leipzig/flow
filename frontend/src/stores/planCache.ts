import {defineStore} from 'pinia'
import axios from 'axios'

type PrefetchStatus = 'idle' | 'running' | 'done'

/**
 * In-memory cache for heavy plan-page APIs within one SPA session.
 * Cleared automatically when the selected event changes.
 */
export const usePlanCacheStore = defineStore('planCache', {
  state: () => ({
    eventId: null as number | null,
    drahtData: null as any | null,
    plan: null as any | null,
    lanesOptions: null as any | null,
    logos: null as any[] | null,
    tableNames: null as any | null,
    insertPointsByLevel: {} as Record<string, any>,
    prefetchStatus: 'idle' as PrefetchStatus,
    drahtPromise: null as Promise<any> | null,
    planPromise: null as Promise<any> | null,
    logosPromise: null as Promise<any[]> | null,
    lanesPromise: null as Promise<any> | null,
  }),

  actions: {
    ensureEvent(eventId: number) {
      if (this.eventId !== eventId) {
        this.$reset()
        this.eventId = eventId
      }
    },

    clear() {
      this.$reset()
    },

    invalidateLogos() {
      this.logos = null
      this.logosPromise = null
    },

    invalidatePlan() {
      this.plan = null
      this.planPromise = null
      this.tableNames = null
    },

    invalidateDraht() {
      this.drahtData = null
      this.drahtPromise = null
    },

    async getDrahtData(eventId: number) {
      this.ensureEvent(eventId)
      if (this.drahtData) return this.drahtData
      if (this.drahtPromise) return this.drahtPromise

      this.drahtPromise = axios
          .get(`/events/${eventId}/draht-data`)
          .then(({data}) => {
            this.drahtData = data
            return data
          })
          .finally(() => {
            this.drahtPromise = null
          })

      return this.drahtPromise
    },

    async getPlan(eventId: number) {
      this.ensureEvent(eventId)
      if (this.plan) return this.plan
      if (this.planPromise) return this.planPromise

      this.planPromise = axios
          .get(`/plans/event/${eventId}`)
          .then(({data}) => {
            this.plan = data
            return data
          })
          .finally(() => {
            this.planPromise = null
          })

      return this.planPromise
    },

    async getLanesOptions() {
      if (this.lanesOptions) return this.lanesOptions
      if (this.lanesPromise) return this.lanesPromise

      this.lanesPromise = axios
          .get('/parameter/lanes-options')
          .then(({data}) => {
            this.lanesOptions = data
            return data
          })
          .finally(() => {
            this.lanesPromise = null
          })

      return this.lanesPromise
    },

    async getLogos() {
      if (this.logos) return this.logos
      if (this.logosPromise) return this.logosPromise

      this.logosPromise = axios
          .get('/logos')
          .then(({data}) => {
            this.logos = data
            return data
          })
          .finally(() => {
            this.logosPromise = null
          })

      return this.logosPromise
    },

    async getTableNames(eventId: number) {
      this.ensureEvent(eventId)
      if (this.tableNames) return this.tableNames

      const {data} = await axios.get(`/table-names/${eventId}`)
      this.tableNames = data
      return data
    },

    async getInsertPoints(level: string | number) {
      const key = String(level)
      if (this.insertPointsByLevel[key]) return this.insertPointsByLevel[key]

      const {data} = await axios.get('/insert-points', {params: {level}})
      this.insertPointsByLevel[key] = data
      return data
    },

    /**
     * Warm data for *other* plan pages. Homepage APIs (draht/plan) are intentionally
     * not re-fetched here — they should already be loaded by Übersicht first.
     */
    async prefetchForEvent(eventId: number) {
      if (!eventId) return
      if (this.prefetchStatus === 'running') return
      if (this.eventId === eventId && this.prefetchStatus === 'done') return

      this.ensureEvent(eventId)
      this.prefetchStatus = 'running'

      try {
        // Secondary APIs only — keep bandwidth free for SharePoint / overview UI
        await Promise.allSettled([
          this.getLanesOptions(),
          this.getLogos(),
          this.getTableNames(eventId),
        ])

        // Chunks one-by-one so we don't spike the network
        const chunkLoaders = [
          () => import('@/components/Schedule.vue'),
          () => import('@/components/Logos.vue'),
          () => import('@/components/Teams.vue'),
          () => import('@/components/Rooms.vue'),
          () => import('@/components/Slots.vue'),
          () => import('@/components/PublishControl.vue'),
        ]
        for (const load of chunkLoaders) {
          try {
            await load()
          } catch {
            // ignore chunk prefetch failures
          }
        }
      } finally {
        this.prefetchStatus = 'done'
      }
    },
  },
})

/** Start background warming only after the homepage has finished its own load. */
export function schedulePlanPrefetch(eventId: number | null | undefined) {
  if (!eventId) return

  const run = () => {
    const cache = usePlanCacheStore()
    void cache.prefetchForEvent(eventId)
  }

  // Wait for a quiet moment; long timeout so SharePoint can finish first
  if (typeof window !== 'undefined' && 'requestIdleCallback' in window) {
    window.requestIdleCallback(() => {
      // Extra beat after idle so overview paint + docs request stay ahead
      setTimeout(run, 400)
    }, {timeout: 10000})
  } else {
    setTimeout(run, 2500)
  }
}
