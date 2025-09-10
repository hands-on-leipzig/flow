import { ref } from 'vue'

// Global state for managing which info popover is open
const openPopoverId = ref<string | null>(null)

export function useInfoPopover() {
  const open = (id: string) => {
    openPopoverId.value = id
  }

  const close = () => {
    openPopoverId.value = null
  }

  const isOpen = (id: string) => {
    return openPopoverId.value === id
  }

  const toggle = (id: string) => {
    if (openPopoverId.value === id) {
      openPopoverId.value = null
    } else {
      openPopoverId.value = id
    }
  }

  return {
    open,
    close,
    isOpen,
    toggle,
    openPopoverId: openPopoverId.value
  }
}
