import {nextTick, onUnmounted, watch} from 'vue'
import {useRoute} from 'vue-router'
import {PARAMETER_QUERY_KEY, parameterAnchorId} from '@/utils/parameterAnchor'

const FOCUS_CLASS = 'parameter-focus'
const RETRY_MS = 50
const RETRY_LIMIT = 24
const HIGHLIGHT_MS = 2200

function expandCollapsedAncestors(el: HTMLElement): boolean {
  let expanded = false
  let section = el.closest('.program-section--collapsed') as HTMLElement | null
  while (section) {
    const toggle = section.querySelector<HTMLButtonElement>(':scope > header .program-section__toggle')
    toggle?.click()
    expanded = true
    section = section.parentElement?.closest('.program-section--collapsed') ?? null
  }
  return expanded
}

function clearFocusClass() {
  document.querySelectorAll(`.${FOCUS_CLASS}`).forEach((node) => node.classList.remove(FOCUS_CLASS))
}

export function useScheduleParameterFocus(isLoading: () => boolean) {
  const route = useRoute()
  let highlightTimer: ReturnType<typeof setTimeout> | null = null
  let retryTimer: ReturnType<typeof setTimeout> | null = null
  let token = 0

  function stopTimers() {
    if (highlightTimer != null) {
      clearTimeout(highlightTimer)
      highlightTimer = null
    }
    if (retryTimer != null) {
      clearTimeout(retryTimer)
      retryTimer = null
    }
  }

  async function focusParameter(rawId: string, attempt = 0) {
    const current = token
    const selector = `#${CSS.escape(parameterAnchorId(rawId))}`
    await nextTick()
    if (current !== token) return

    const el = document.querySelector(selector) as HTMLElement | null
    if (!el) {
      if (attempt >= RETRY_LIMIT) return
      retryTimer = setTimeout(() => {
        void focusParameter(rawId, attempt + 1)
      }, RETRY_MS)
      return
    }

    if (expandCollapsedAncestors(el)) {
      await nextTick()
      if (current !== token) return
    }

    clearFocusClass()
    el.scrollIntoView({behavior: 'smooth', block: 'center'})
    el.classList.add(FOCUS_CLASS)
    highlightTimer = setTimeout(() => {
      el.classList.remove(FOCUS_CLASS)
      highlightTimer = null
    }, HIGHLIGHT_MS)
  }

  watch(
    () => [route.query[PARAMETER_QUERY_KEY], route.name, isLoading()] as const,
    ([rawId, name, loading]) => {
      stopTimers()
      token += 1
      if (loading) return
      if (!String(name || '').startsWith('schedule-')) return
      const id = Array.isArray(rawId) ? rawId[0] : rawId
      if (!id) return
      void focusParameter(String(id))
    },
    {immediate: true},
  )

  onUnmounted(() => {
    token += 1
    stopTimers()
    clearFocusClass()
  })
}
