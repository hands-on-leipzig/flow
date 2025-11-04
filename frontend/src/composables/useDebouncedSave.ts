import { ref, readonly, computed, onUnmounted, watch } from 'vue'

/**
 * Options for debounced save composable
 */
export interface DebouncedSaveOptions {
  /** Delay in milliseconds before saving (default: 2000) */
  delay?: number
  /** Function called when it's time to save accumulated updates */
  onSave: (updates: Record<string, any>) => Promise<void>
  /** Optional: Called when saving starts (to show toast, etc.) */
  onShowToast?: (countdown: number, onImmediateSave: () => void) => void
  /** Optional: Called when saving completes (to hide toast, etc.) */
  onHideToast?: () => void
  /** Optional: Called when countdown updates (for display) */
  onCountdownUpdate?: (seconds: number | null) => void
  /** Optional: Change detection function. Return false to skip update */
  changeDetection?: (key: string, newValue: any, oldValue: any) => boolean
  /** Optional: Ref to track if generator is running (freezes countdown) */
  isGenerating?: () => boolean
}

/**
 * Composable for debounced saving with change detection and toast support
 * 
 * @example
 * ```ts
 * const savingToast = ref(null)
 * const { scheduleUpdate, flush, setOriginal } = useDebouncedSave({
 *   delay: 2000,
 *   onShowToast: () => savingToast.value?.show(),
 *   onHideToast: () => savingToast.value?.hide(),
 *   onSave: async (updates) => {
 *     await saveToBackend(updates)
 *   },
 *   changeDetection: (key, newVal, oldVal) => {
 *     return JSON.stringify(newVal) !== JSON.stringify(oldVal)
 *   }
 * })
 * ```
 */
export function useDebouncedSave(options: DebouncedSaveOptions) {
  const pendingUpdates = ref<Record<string, any>>({})
  const originalValues = ref<Record<string, any>>({})
  const timeoutId = ref<NodeJS.Timeout | null>(null)
  const countdownId = ref<NodeJS.Timeout | null>(null)
  const delay = options.delay ?? 2000
  const isFrozen = ref(false)
  const countdownSeconds = ref<number | null>(null)
  const startTime = ref<number | null>(null)

  // Pending count for display
  const pendingCount = computed(() => Object.keys(pendingUpdates.value).length)

  /**
   * Schedule an update to be saved after the debounce delay
   * @param key Unique key for this update (e.g., 'param_name' or 'block_123_field')
   * @param value The new value to save
   */
  function scheduleUpdate(key: string, value: any) {
    // Change detection: skip if no real change
    if (options.changeDetection) {
      const oldValue = originalValues.value[key]
      if (!options.changeDetection(key, value, oldValue)) {
        // No change detected, remove from pending if it exists
        if (pendingUpdates.value[key] !== undefined) {
          delete pendingUpdates.value[key]
        }
        return
      }
    }

    // Add to pending updates
    pendingUpdates.value[key] = value
    
    // Start countdown and show toast
    startCountdown()

    // Clear existing timeout and schedule new one
    // Only set timeout if generator is not running (otherwise it will be set in unfreeze)
    if (timeoutId.value) {
      clearTimeout(timeoutId.value)
    }
    if (!options.isGenerating?.()) {
      timeoutId.value = setTimeout(flush, delay)
    }
    // If generator is running, timeout will be set in unfreeze() when generation completes
  }

  /**
   * Start countdown timer for visual feedback
   */
  function startCountdown() {
    // Stop existing countdown
    stopCountdown()
    
    // Check if we should freeze due to generator running
    if (options.isGenerating?.()) {
      isFrozen.value = true
      return
    }

    isFrozen.value = false
    const seconds = Math.ceil(delay / 1000)
    countdownSeconds.value = seconds
    startTime.value = Date.now()

    // Update toast with countdown and immediate save callback
    options.onShowToast?.(seconds, immediateFlush)

    // Notify initial countdown
    options.onCountdownUpdate?.(seconds)

    // Start countdown interval
    countdownId.value = setInterval(() => {
      // Check if frozen (generator running)
      if (options.isGenerating?.()) {
        isFrozen.value = true
        if (countdownId.value) {
          clearInterval(countdownId.value)
          countdownId.value = null
        }
        return
      }

      if (countdownSeconds.value === null || startTime.value === null) {
        stopCountdown()
        return
      }

      // Calculate remaining seconds based on elapsed time
      const elapsed = Date.now() - startTime.value
      const remaining = Math.max(0, Math.ceil((delay - elapsed) / 1000))
      countdownSeconds.value = remaining

      // Notify of countdown update
      options.onCountdownUpdate?.(remaining > 0 ? remaining : null)

      if (remaining <= 0) {
        stopCountdown()
      }
    }, 100) // Update every 100ms for smooth countdown
  }

  /**
   * Stop countdown timer
   */
  function stopCountdown() {
    if (countdownId.value) {
      clearInterval(countdownId.value)
      countdownId.value = null
    }
    countdownSeconds.value = null
    startTime.value = null
    isFrozen.value = false
  }

  /**
   * Immediate flush (called when user clicks countdown)
   */
  async function immediateFlush() {
    stopCountdown()
    await flush()
  }

  /**
   * Immediately flush all pending updates to the save function
   */
  async function flush() {
    // Clear timeout
    if (timeoutId.value) {
      clearTimeout(timeoutId.value)
      timeoutId.value = null
    }

    // Stop countdown
    stopCountdown()

    // Hide toast
    options.onHideToast?.()
    options.onCountdownUpdate?.(null)

    // Check if there are any updates
    if (Object.keys(pendingUpdates.value).length === 0) {
      return
    }

    // Copy and clear pending updates
    const updates = { ...pendingUpdates.value }
    pendingUpdates.value = {}

    // Save to backend
    await options.onSave(updates)
    
    // Update original values after successful save
    Object.keys(updates).forEach(key => {
      originalValues.value[key] = updates[key]
    })
  }

  /**
   * Freeze countdown (e.g., when generator is running)
   */
  function freeze() {
    isFrozen.value = true
    if (countdownId.value) {
      clearInterval(countdownId.value)
      countdownId.value = null
    }
  }

  /**
   * Unfreeze countdown and resume from where it left off
   */
  function unfreeze() {
    if (pendingCount.value > 0) {
      // Resume countdown if we have pending updates
      isFrozen.value = false
      
      // Check if timeout expired while frozen - if so, restart it
      if (!timeoutId.value) {
        // No active timeout, restart it for pending updates
        timeoutId.value = setTimeout(flush, delay)
      }
      
      // Start countdown from beginning (simpler and more predictable)
      if (countdownSeconds.value !== null && countdownSeconds.value > 0) {
        // Resume from where we were
        startTime.value = Date.now() - ((delay / 1000 - countdownSeconds.value) * 1000)
        // Restart interval
        countdownId.value = setInterval(() => {
          if (options.isGenerating?.()) {
            isFrozen.value = true
            if (countdownId.value) {
              clearInterval(countdownId.value)
              countdownId.value = null
            }
            return
          }

          if (countdownSeconds.value === null || startTime.value === null) {
            stopCountdown()
            return
          }

          const elapsed = Date.now() - startTime.value
          const remaining = Math.max(0, Math.ceil((delay - elapsed) / 1000))
          countdownSeconds.value = remaining
          options.onCountdownUpdate?.(remaining > 0 ? remaining : null)

          if (remaining <= 0) {
            stopCountdown()
          }
        }, 100)
      } else {
        // Start fresh countdown
        startCountdown()
      }
    }
  }

  /**
   * Set the original value for a key (used for change detection)
   * @param key The key to set
   * @param value The original value
   */
  function setOriginal(key: string, value: any) {
    originalValues.value[key] = value
  }

  /**
   * Set multiple original values at once
   * @param values Object with keys and original values
   */
  function setOriginals(values: Record<string, any>) {
    Object.assign(originalValues.value, values)
  }

  /**
   * Clear all pending updates without saving
   */
  function clearPending() {
    if (timeoutId.value) {
      clearTimeout(timeoutId.value)
      timeoutId.value = null
    }
    pendingUpdates.value = {}
    options.onHideToast?.()
  }

  // Watch generator state to auto-freeze/unfreeze (if function provided)
  // Note: Components should call freeze/unfreeze manually or pass a computed ref
  // We'll handle this via the startCountdown check instead

  // Cleanup on unmount: flush any pending updates
  onUnmounted(() => {
    stopCountdown()
    if (timeoutId.value) {
      clearTimeout(timeoutId.value)
    }
    flush()
  })

  return {
    /** Schedule an update to be saved */
    scheduleUpdate,
    /** Immediately flush all pending updates */
    flush,
    /** Immediate flush (for user-triggered saves) */
    immediateFlush,
    /** Set original value for change detection */
    setOriginal,
    /** Set multiple original values */
    setOriginals,
    /** Clear all pending updates */
    clearPending,
    /** Freeze countdown (e.g., during generation) */
    freeze,
    /** Unfreeze countdown and resume */
    unfreeze,
    /** Current countdown seconds (null if not counting) */
    countdownSeconds: readonly(countdownSeconds),
    /** Number of pending updates */
    pendingCount,
    /** Read-only access to pending updates (for debugging) */
    pendingUpdates: readonly(pendingUpdates)
  }
}

