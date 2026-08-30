import {computed, ref, watch, type Ref} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

/**
 * Shared load/save for event.public_helper_search (Veröffentlichung + Zuordnung).
 */
export function usePublicHelperSearch(eventId: Ref<number | null | undefined>) {
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
      const {data} = await axios.get(`/publish/helper-search/${id}`)
      enabled.value = !!data.public_helper_search
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
      await axios.post(`/publish/helper-search/${id}`, {public_helper_search: next})
    } catch {
      enabled.value = prev
      showGlassToast('Einstellung konnte nicht gespeichert werden.', 'error')
      throw new Error('helper-search save failed')
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
