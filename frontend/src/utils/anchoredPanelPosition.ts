export type AnchoredPanelAlign = 'left' | 'right'
export type AnchoredPanelSide = 'bottom' | 'end'

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
    side?: AnchoredPanelSide
  },
): Record<string, string> {
  const margin = options?.margin ?? 8
  const align = options?.align ?? 'left'
  const side = options?.side ?? 'bottom'
  const {width, height} = panelSize
  const vw = viewport.width
  const vh = viewport.height

  let top: number
  let left: number

  if (side === 'end') {
    left = anchorRect.right + margin
    top = anchorRect.top + (anchorRect.height - height) / 2
    if (left + width > vw - margin) {
      left = anchorRect.left - width - margin
    }
    if (left < margin) {
      left = align === 'right' ? anchorRect.right - width : anchorRect.left
      top = anchorRect.bottom + margin
      if (top + height > vh - margin && anchorRect.top - height - margin >= margin) {
        top = anchorRect.top - height - margin
      }
    }
  } else {
    top = anchorRect.bottom + margin
    if (top + height > vh - margin && anchorRect.top - height - margin >= margin) {
      top = anchorRect.top - height - margin
    }
    left = align === 'right' ? anchorRect.right - width : anchorRect.left
  }

  top = Math.min(Math.max(top, margin), Math.max(margin, vh - margin - height))
  if (left + width > vw - margin) left = vw - margin - width
  if (left < margin) left = margin

  return {
    position: 'fixed',
    top: `${Math.round(top)}px`,
    left: `${Math.round(left)}px`,
    visibility: 'visible',
  }
}
