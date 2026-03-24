import { computed, onUnmounted, ref, watch, type Ref } from 'vue'

export type ArrowDirection = 'left' | 'right'

type UseMultiPageTableOptions<T> = {
  items: Ref<T[]>
  pageSize: Ref<number>
  secondsPerPage: Ref<number>
  isActive?: Ref<boolean>
  isPaused?: Ref<boolean>
  onAutoEnd?: () => void
}

export function useMultiPageTable<T>(options: UseMultiPageTableOptions<T>) {
  const currentIndex = ref(0)
  let autoAdvanceInterval: ReturnType<typeof setInterval> | null = null

  const isActive = options.isActive ?? ref(true)
  const isPaused = options.isPaused ?? ref(false)

  const normalizedPageSize = computed(() => {
    const size = Number(options.pageSize.value)
    return Number.isFinite(size) && size > 0 ? Math.floor(size) : 1
  })

  const normalizedSecondsPerPage = computed(() => {
    const seconds = Number(options.secondsPerPage.value)
    return Number.isFinite(seconds) && seconds > 0 ? seconds : 15
  })

  const paginatedItems = computed(() => {
    const start = currentIndex.value
    return options.items.value.slice(start, start + normalizedPageSize.value)
  })

  const hasPreviousPage = computed(() => currentIndex.value > 0)
  const hasNextPage = computed(() => {
    return currentIndex.value + normalizedPageSize.value < options.items.value.length
  })

  function resetPage() {
    currentIndex.value = 0
  }

  function goToNextPage() {
    if (!hasNextPage.value) {
      return false
    }

    currentIndex.value += normalizedPageSize.value
    return true
  }

  function goToPreviousPage() {
    if (!hasPreviousPage.value) {
      return false
    }

    currentIndex.value = Math.max(currentIndex.value - normalizedPageSize.value, 0)
    return true
  }

  // For timer-based progression: when the last page is reached, trigger next slide and reset.
  function autoAdvance() {
    if (goToNextPage()) {
      return
    }

    options.onAutoEnd?.()
    resetPage()
  }

  function handleArrow(direction: ArrowDirection) {
    if (direction === 'right') {
      return goToNextPage()
    }
    return goToPreviousPage()
  }

  function stopAutoAdvance() {
    if (autoAdvanceInterval) {
      clearInterval(autoAdvanceInterval)
      autoAdvanceInterval = null
    }
  }

  function startAutoAdvance() {
    stopAutoAdvance()

    if (!isActive.value) {
      return
    }

    autoAdvanceInterval = setInterval(() => {
      if (isPaused.value || !isActive.value) {
        return
      }
      autoAdvance()
    }, normalizedSecondsPerPage.value * 1000)
  }

  watch(
    () => isActive.value,
    (active) => {
      if (active) {
        resetPage()
        startAutoAdvance()
        return
      }
      stopAutoAdvance()
    },
    { immediate: true }
  )

  watch(
    () => [normalizedSecondsPerPage.value, normalizedPageSize.value],
    () => {
      if (isActive.value) {
        startAutoAdvance()
      }
    }
  )

  watch(
    () => options.items.value,
    (items) => {
      if (!items.length) {
        resetPage()
        return
      }

      if (currentIndex.value >= items.length) {
        const lastPageStart = Math.max(0, Math.floor((items.length - 1) / normalizedPageSize.value) * normalizedPageSize.value)
        currentIndex.value = lastPageStart
      }
    }
  )

  onUnmounted(() => {
    stopAutoAdvance()
  })

  return {
    currentIndex,
    paginatedItems,
    hasPreviousPage,
    hasNextPage,
    resetPage,
    goToNextPage,
    goToPreviousPage,
    handleArrow,
    startAutoAdvance,
    stopAutoAdvance
  }
}


