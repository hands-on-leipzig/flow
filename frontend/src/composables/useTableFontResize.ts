import { nextTick, onMounted, onUnmounted, type Ref } from 'vue'

export type Size = {
  width: number
  height: number
}

type UseTableFontResizeOptions = {
  wrapperRef: Ref<HTMLElement | null>
  tableRef: Ref<HTMLTableElement | null>
  minFont?: number
  maxFont?: number
  cssVarName?: string
  getAvailableSize?: () => Size
}

export function useTableFontResize(options: UseTableFontResizeOptions) {
  const {
    wrapperRef,
    tableRef,
    minFont = 8,
    maxFont = 32,
    cssVarName = '--table-font-size',
    getAvailableSize
  } = options

  let resizeObserver: ResizeObserver | null = null

  async function adjustFontSize() {
    const wrapper = wrapperRef.value
    const table = tableRef.value

    if (!wrapper || !table) {
      return
    }

    const wrapperRect = wrapper.getBoundingClientRect()
    const fallback = {
      width: Math.max(0, wrapperRect.width),
      height: Math.max(0, wrapperRect.height)
    }

    const available = getAvailableSize ? getAvailableSize() : fallback
    const availableWidth = Math.max(0, available.width)
    const availableHeight = Math.max(0, available.height)

    let low = minFont
    let high = maxFont
    let best = minFont

    while (low <= high) {
      const mid = Math.floor((low + high) / 2)
      wrapper.style.setProperty(cssVarName, `${mid}px`)
      await nextTick()

      const rect = table.getBoundingClientRect()
      const fitsHeight = rect.height <= availableHeight + 1
      const fitsWidth = rect.width <= availableWidth + 1

      if (fitsHeight && fitsWidth) {
        best = mid
        low = mid + 1
      } else {
        high = mid - 1
      }
    }

    wrapper.style.setProperty(cssVarName, `${best}px`)
  }

  onMounted(() => {
    nextTick(adjustFontSize)

    if (window.ResizeObserver && wrapperRef.value) {
      resizeObserver = new ResizeObserver(() => {
        nextTick(adjustFontSize)
      })
      resizeObserver.observe(wrapperRef.value)
    } else {
      window.addEventListener('resize', adjustFontSize)
    }
  })

  onUnmounted(() => {
    if (resizeObserver) {
      resizeObserver.disconnect()
      resizeObserver = null
    } else {
      window.removeEventListener('resize', adjustFontSize)
    }
  })

  return {
    adjustFontSize
  }
}


