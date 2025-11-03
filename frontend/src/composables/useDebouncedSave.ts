import { ref, readonly, onUnmounted } from 'vue'

/**
 * Options for debounced save composable
 */
export interface DebouncedSaveOptions {
  /** Delay in milliseconds before saving (default: 2000) */
  delay?: number
  /** Function called when it's time to save accumulated updates */
  onSave: (updates: Record<string, any>) => Promise<void>
  /** Optional: Called when saving starts (to show toast, etc.) */
  onShowToast?: () => void
  /** Optional: Called when saving completes (to hide toast, etc.) */
  onHideToast?: () => void
  /** Optional: Change detection function. Return false to skip update */
  changeDetection?: (key: string, newValue: any, oldValue: any) => boolean
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
  const delay = options.delay ?? 2000

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
    
    // Show toast notification
    options.onShowToast?.()

    // Clear existing timeout and schedule new one
    if (timeoutId.value) {
      clearTimeout(timeoutId.value)
    }
    timeoutId.value = setTimeout(flush, delay)
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

    // Hide toast
    options.onHideToast?.()

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

  // Cleanup on unmount: flush any pending updates
  onUnmounted(() => {
    flush()
  })

  return {
    /** Schedule an update to be saved */
    scheduleUpdate,
    /** Immediately flush all pending updates */
    flush,
    /** Set original value for change detection */
    setOriginal,
    /** Set multiple original values */
    setOriginals,
    /** Clear all pending updates */
    clearPending,
    /** Read-only access to pending updates (for debugging) */
    pendingUpdates: readonly(pendingUpdates)
  }
}

