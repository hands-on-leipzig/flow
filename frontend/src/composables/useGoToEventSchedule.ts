import axios from 'axios'
import { useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'
import { showGlassToast } from '@/composables/useGlassToast'

export function useGoToEventSchedule() {
  const router = useRouter()
  const eventStore = useEventStore()

  async function goToEventSchedule(eventId: number, regionalPartnerId: number | null | undefined) {
    if (!regionalPartnerId) {
      showGlassToast('Regionalpartner fehlt — Wechsel nicht möglich.', 'error')
      return
    }

    try {
      await axios.post('/user/select-event', {
        event: eventId,
        regional_partner: regionalPartnerId,
      })
      eventStore.staleSeasonCleared = false
      await eventStore.fetchSelectedEvent()
      await router.push('/plan/schedule')
    } catch (error) {
      console.error('Failed to switch event for schedule', error)
      showGlassToast('Wechsel zum Ablauf fehlgeschlagen.', 'error')
    }
  }

  return { goToEventSchedule }
}
