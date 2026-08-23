import {ref} from 'vue'
import axios from 'axios'

const isDevEnvironment = ref(false)
const loaded = ref(false)
let loadPromise: Promise<void> | null = null

const isLocal =
  typeof window !== 'undefined' &&
  (window.location?.hostname === 'localhost' || window.location?.hostname === '127.0.0.1')

async function ensureLoaded(): Promise<void> {
  if (loaded.value) return
  if (loadPromise) return loadPromise

  loadPromise = axios
    .get('/environment')
    .then((response) => {
      isDevEnvironment.value = response.data.is_dev || false
    })
    .catch((error) => {
      console.error('Failed to fetch environment:', error)
      isDevEnvironment.value = false
    })
    .finally(() => {
      loaded.value = true
    })

  return loadPromise
}

export function useAdminEnvironment() {
  return {
    isDevEnvironment,
    isLocal,
    ensureLoaded,
  }
}
