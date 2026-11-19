import {ref} from 'vue'

export type WiseSync = 'idle' | 'local' | 'syncing' | 'synced' | 'offline'

const sync = ref<WiseSync>('idle')

export function wiseSyncLabel(state: WiseSync) {
  if (state === 'syncing' || state === 'local') return 'wird übertragen'
  if (state === 'offline') return 'offline, auf dem Gerät gesichert'
  if (state === 'synced') return 'übertragen'
  return ''
}

export function useWiseSync() {
  function setSync(next: WiseSync) {
    sync.value = next
  }

  return {sync, setSync}
}
