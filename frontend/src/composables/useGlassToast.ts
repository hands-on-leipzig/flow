import {readonly, ref} from 'vue'

export type GlassToastType = 'success' | 'error' | 'info'

const visible = ref(false)
const message = ref('')
const type = ref<GlassToastType>('info')
let timeoutId: ReturnType<typeof setTimeout> | undefined

function hide() {
  visible.value = false
  if (timeoutId) {
    clearTimeout(timeoutId)
    timeoutId = undefined
  }
}

/**
 * Global glass toast — mount <GlassToast /> once (e.g. in App.vue),
 * then call showGlassToast() from anywhere instead of alert().
 */
export function showGlassToast(
  msg: string,
  toastType: GlassToastType = 'info',
  durationMs = 3800,
) {
  message.value = msg
  type.value = toastType
  visible.value = true
  if (timeoutId) clearTimeout(timeoutId)
  timeoutId = setTimeout(hide, durationMs)
}

export function useGlassToast() {
  return {
    visible: readonly(visible),
    message: readonly(message),
    type: readonly(type),
    show: showGlassToast,
    hide,
  }
}
