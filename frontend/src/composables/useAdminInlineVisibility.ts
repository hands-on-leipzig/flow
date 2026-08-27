import { computed, ref } from 'vue'
import { useAuth } from '@/composables/useAuth'

const STORAGE_KEY = 'flow.showAdminInline'

function readPreference(): boolean {
  if (typeof localStorage === 'undefined') return true
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (raw === null) return true
    return JSON.parse(raw) !== false
  } catch {
    return true
  }
}

/** Shared across the app so Admin toggle updates Planner surfaces immediately. */
const adminInlinePreference = ref(readPreference())

function setAdminInlinePreference(value: boolean) {
  adminInlinePreference.value = value
  if (typeof localStorage === 'undefined') return
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(value))
  } catch {
    /* ignore quota / private mode */
  }
}

/**
 * Whether planner-inline admin UI (preview tools, Geschützte Parameter) should show.
 * Requires FLOW admin and the demo preference (default on).
 */
export function useAdminInlineVisibility() {
  const { isAdmin } = useAuth()

  const showAdminInline = computed(() => isAdmin.value && adminInlinePreference.value)

  return {
    isAdmin,
    adminInlinePreference,
    setAdminInlinePreference,
    showAdminInline,
  }
}
