import {computed, ref, watch, type Ref} from 'vue'
import axios from 'axios'
import {apiError} from '@/utils/apiError'

export type VolunteerMealOption = {
  id?: number
  value: string
  label: string
  sequence?: number
}

export function useVolunteerMealOptions(eventId: Ref<number | undefined>) {
  const options = ref<VolunteerMealOption[]>([])
  const loading = ref(false)
  const error = ref('')

  async function load() {
    if (!eventId.value) {
      options.value = []
      return
    }
    loading.value = true
    error.value = ''
    try {
      const {data} = await axios.get(`/events/${eventId.value}/volunteer-meal-options`)
      options.value = data.options ?? []
    } catch (e: unknown) {
      error.value = apiError(e, 'Essensoptionen konnten nicht geladen werden.')
      options.value = []
    } finally {
      loading.value = false
    }
  }

  async function replace(nextOptions: Array<{value: string; label: string}>) {
    if (!eventId.value) return false
    error.value = ''
    try {
      const {data} = await axios.put(`/events/${eventId.value}/volunteer-meal-options`, {
        options: nextOptions,
      })
      options.value = data.options ?? []
      return true
    } catch (e: unknown) {
      error.value = apiError(e, 'Essensoptionen konnten nicht gespeichert werden.')
      return false
    }
  }

  function setOptions(next: VolunteerMealOption[]) {
    options.value = next
  }

  watch(eventId, () => void load(), {immediate: true})

  const selectOptions = computed(() =>
    options.value.map((option) => ({value: option.value, label: option.label})),
  )

  return {
    options,
    selectOptions,
    loading,
    error,
    load,
    replace,
    setOptions,
  }
}
