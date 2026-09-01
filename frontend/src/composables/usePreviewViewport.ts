import {computed, ref, watch} from 'vue'

export const PREVIEW_VIEWPORT_STORAGE_KEY = 'flow-publish-preview-viewport'
export const PREVIEW_VIEWPORT_STORAGE_KEY_LEGACY = 'flow-publish-preview-device'

export const previewViewports = [
  {id: 'full', label: 'Responsive', width: null, height: null},
  {id: 'galaxy-s24', label: 'Galaxy S24 · 360 × 780', width: 360, height: 780},
  {id: 'iphone-se', label: 'iPhone SE · 375 × 667', width: 375, height: 667},
  {id: 'iphone-15', label: 'iPhone 15 · 390 × 844', width: 390, height: 844},
  {id: 'iphone-15-pro-max', label: 'iPhone 15 Pro Max · 430 × 932', width: 430, height: 932},
  {id: 'pixel-8', label: 'Pixel 8 · 412 × 915', width: 412, height: 915},
  {id: 'ipad-mini', label: 'iPad mini · 1024 × 768 (Querformat)', width: 1024, height: 768},
  {id: 'ipad-pro-11', label: 'iPad Pro 11" · 1194 × 834 (Querformat)', width: 1194, height: 834},
  {id: 'ipad-pro-12', label: 'iPad Pro 12,9" · 1366 × 1024 (Querformat)', width: 1366, height: 1024},
] as const

export type PreviewViewportId = (typeof previewViewports)[number]['id']

const legacyViewportIds: Record<string, PreviewViewportId> = {
  full: 'full',
  '360': 'galaxy-s24',
  '390': 'iphone-15',
  '430': 'iphone-15-pro-max',
  '768': 'ipad-mini',
  '1024': 'ipad-pro-12',
}

function isPreviewViewportId(value: string): value is PreviewViewportId {
  return previewViewports.some((viewport) => viewport.id === value)
}

function readPreviewViewportId(storageKey: string): PreviewViewportId {
  try {
    const stored = localStorage.getItem(storageKey)
    if (stored && isPreviewViewportId(stored)) {
      return stored
    }
    const legacy = localStorage.getItem(PREVIEW_VIEWPORT_STORAGE_KEY_LEGACY)
    if (legacy && legacyViewportIds[legacy]) {
      return legacyViewportIds[legacy]
    }
  } catch {
    /* ignore */
  }
  return 'full'
}

export function usePreviewViewport(storageKey = PREVIEW_VIEWPORT_STORAGE_KEY) {
  const previewViewportId = ref<PreviewViewportId>(readPreviewViewportId(storageKey))

  const activePreviewViewport = computed(
    () => previewViewports.find((viewport) => viewport.id === previewViewportId.value) ?? previewViewports[0],
  )

  const isFixedPreviewViewport = computed(
    () => activePreviewViewport.value.width != null && activePreviewViewport.value.height != null,
  )

  const deviceShellStyle = computed(() => {
    const {width, height} = activePreviewViewport.value
    if (width == null || height == null) {
      return undefined
    }
    return {
      width: `${width}px`,
      height: `${height}px`,
    }
  })

  const previewViewportHint = computed(() => {
    const {width, height} = activePreviewViewport.value
    if (width == null || height == null) {
      return 'Responsive'
    }
    return `${width} × ${height}`
  })

  watch(previewViewportId, (id) => {
    try {
      localStorage.setItem(storageKey, id)
    } catch {
      /* ignore */
    }
  })

  return {
    previewViewports,
    previewViewportId,
    activePreviewViewport,
    isFixedPreviewViewport,
    deviceShellStyle,
    previewViewportHint,
  }
}
