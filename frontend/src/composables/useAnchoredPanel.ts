import {
  type MaybeRefOrGetter,
  nextTick,
  onBeforeUnmount,
  onMounted,
  ref,
  toValue,
  watch,
} from 'vue'
import {
  ANCHORED_PANEL_HIDDEN_STYLE,
  type AnchoredPanelAlign,
  computeAnchoredPanelStyle,
} from '@/utils/anchoredPanelPosition'

export type UseAnchoredPanelOptions = {
  isOpen: MaybeRefOrGetter<boolean>
  anchor: MaybeRefOrGetter<HTMLElement | null>
  align?: AnchoredPanelAlign
  margin?: number
  fallbackWidth?: number
  fallbackHeight?: number
  closeOn?: 'click' | 'mousedown'
  onClose?: () => void
}

export function useAnchoredPanel(options: UseAnchoredPanelOptions) {
  const panelRef = ref<HTMLElement | null>(null)
  const panelStyle = ref<Record<string, string>>({...ANCHORED_PANEL_HIDDEN_STYLE})

  function place() {
    const anchor = toValue(options.anchor)
    const panel = panelRef.value
    if (!anchor || !panel) return

    panelStyle.value = computeAnchoredPanelStyle(
      anchor.getBoundingClientRect(),
      {
        width: panel.offsetWidth || options.fallbackWidth || 256,
        height: panel.offsetHeight || options.fallbackHeight || 80,
      },
      {width: window.innerWidth, height: window.innerHeight},
      {margin: options.margin, align: options.align},
    )
  }

  watch(
    () => toValue(options.isOpen),
    async (open) => {
      if (!open) {
        panelStyle.value = {...ANCHORED_PANEL_HIDDEN_STYLE}
        return
      }
      panelStyle.value = {...ANCHORED_PANEL_HIDDEN_STYLE}
      await nextTick()
      place()
    },
  )

  function handleOutside(event: MouseEvent) {
    if (!toValue(options.isOpen)) return
    const target = event.target
    if (!(target instanceof Node)) return
    if (panelRef.value?.contains(target)) return
    const anchor = toValue(options.anchor)
    if (anchor?.contains(target)) return
    options.onClose?.()
  }

  function onReposition() {
    if (toValue(options.isOpen)) place()
  }

  const closeEvent = options.closeOn === 'mousedown' ? 'mousedown' : 'click'

  onMounted(() => {
    document.addEventListener(closeEvent, handleOutside)
    window.addEventListener('resize', onReposition)
    window.addEventListener('scroll', onReposition, true)
  })

  onBeforeUnmount(() => {
    document.removeEventListener(closeEvent, handleOutside)
    window.removeEventListener('resize', onReposition)
    window.removeEventListener('scroll', onReposition, true)
    if (toValue(options.isOpen)) options.onClose?.()
  })

  return {
    panelRef,
    panelStyle,
    place,
  }
}
