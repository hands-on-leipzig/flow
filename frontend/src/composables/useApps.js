import {computed, ref} from 'vue'
import axios from 'axios'

const canFlow = ref(false)
const canScor = ref(false)
const loaded = ref(false)
let pending = null

async function load() {
  try {
    const {data} = await axios.get('/user/me', {skipAppRedirect: true})
    canFlow.value = Boolean(data?.user?.can_flow)
    canScor.value = Boolean(data?.user?.can_scor)
    loaded.value = true
    return
  } catch (error) {
    const denied = error?.response?.status === 403 && error?.response?.data?.code === 'app_access_denied'
    if (!denied || error?.response?.data?.app !== 'flow') {
      loaded.value = true
      return
    }
  }

  try {
    const {data} = await axios.get('/scor/access', {skipAppRedirect: true})
    canFlow.value = Boolean(data?.can_flow)
    canScor.value = Boolean(data?.can_scor)
  } catch {
    canFlow.value = false
    canScor.value = false
  }
  loaded.value = true
}

export function useApps() {
  async function ensure() {
    if (loaded.value) return
    if (!pending) {
      pending = load().finally(() => {
        pending = null
      })
    }
    await pending
  }

  return {
    canFlow: computed(() => canFlow.value),
    canScor: computed(() => canScor.value),
    loaded: computed(() => loaded.value),
    showSwitch: computed(() => canFlow.value && canScor.value),
    ensure,
  }
}
