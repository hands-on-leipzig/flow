import {computed, ref, watch, type Ref} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

/**
 * Shared load/save for event.public_volunteer_data_entry (Veröffentlichung + Helferliste).
 */
export function usePublicVolunteerDataEntry(eventId: Ref<number | null | undefined>) {
  const enabled = ref(false)
  const loading = ref(false)
  const saving = ref(false)

  async function load() {
    const id = eventId.value
    if (!id) {
      enabled.value = false
      return
    }
    loading.value = true
    try {
      const {data} = await axios.get(`/publish/volunteer-data-entry/${id}`)
      enabled.value = !!data.public_volunteer_data_entry
    } catch {
      enabled.value = false
    } finally {
      loading.value = false
    }
  }

  async function setEnabled(next: boolean) {
    const id = eventId.value
    if (!id || saving.value) return
    const prev = enabled.value
    enabled.value = next
    saving.value = true
    try {
      await axios.post(`/publish/volunteer-data-entry/${id}`, {public_volunteer_data_entry: next})
    } catch {
      enabled.value = prev
      showGlassToast('Einstellung konnte nicht gespeichert werden.', 'error')
      throw new Error('volunteer-data-entry save failed')
    } finally {
      saving.value = false
    }
  }

  watch(eventId, () => {
    void load()
  }, {immediate: true})

  return {
    enabled: computed(() => enabled.value),
    loading: computed(() => loading.value),
    saving: computed(() => saving.value),
    load,
    setEnabled,
  }
}
