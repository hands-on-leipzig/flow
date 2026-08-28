export type AnchoredPanelAlign = 'left' | 'right'

export type AnchoredPanelSize = {
  width: number
  height: number
}

export type AnchoredPanelViewport = {
  width: number
  height: number
}

export const ANCHORED_PANEL_HIDDEN_STYLE: Record<string, string> = {
  position: 'fixed',
  top: '0',
  left: '0',
  visibility: 'hidden',
}

export function computeAnchoredPanelStyle(
  anchorRect: DOMRect,
  panelSize: AnchoredPanelSize,
  viewport: AnchoredPanelViewport,
  options?: {
    margin?: number
    align?: AnchoredPanelAlign
  },
): Record<string, string> {
  const margin = options?.margin ?? 8
  const align = options?.align ?? 'left'
  const {width, height} = panelSize
  const vw = viewport.width
  const vh = viewport.height

  let top = anchorRect.bottom + margin
  if (top + height > vh - margin && anchorRect.top - height - margin >= margin) {
    top = anchorRect.top - height - margin
  }
  top = Math.min(Math.max(top, margin), Math.max(margin, vh - margin - height))

  let left = align === 'right' ? anchorRect.right - width : anchorRect.left
  if (left + width > vw - margin) left = vw - margin - width
  if (left < margin) left = margin

  return {
    position: 'fixed',
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    visibility: 'visible',
  }
}
