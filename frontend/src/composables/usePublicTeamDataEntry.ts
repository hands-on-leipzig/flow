import {computed, ref, watch, type Ref} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

/**
 * Shared load/save for event.public_team_data_entry (Veröffentlichung + Teamdaten).
 */
export function usePublicTeamDataEntry(eventId: Ref<number | null | undefined>) {
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
      const {data} = await axios.get(`/publish/team-data-entry/${id}`)
      enabled.value = !!data.public_team_data_entry
    } catch {
      enabled.value = false
    } finally {
      loading.value = false
    }
  }

  async function setEnabled(next: boolean): Promise<boolean> {
    const id = eventId.value
    if (!id || saving.value) return false
    const prev = enabled.value
    enabled.value = next
    saving.value = true
    try {
      await axios.post(`/publish/team-data-entry/${id}`, {public_team_data_entry: next})
      return true
    } catch {
      enabled.value = prev
      showGlassToast('Einstellung konnte nicht gespeichert werden.', 'error')
      throw new Error('team-data-entry save failed')
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
