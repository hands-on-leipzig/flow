import axios from 'axios'

export type SurfaceKind = 'form_volunteer' | 'form_team' | 'app_check_in' | 'app_cockpit'

export function isPlannerPreview(): boolean {
  const q = new URLSearchParams(window.location.search)
  if (q.has('preview') || q.has('_pv')) return true
  try {
    return window.self !== window.top
      && window.top !== null
      && window.location.hostname === window.top.location.hostname
  } catch {
    return false
  }
}

export function withPreviewQuery(url: string): string {
  if (!url) return url
  return url.includes('?') ? `${url}&preview=1` : `${url}?preview=1`
}

export function usageClientFields(eventId: number, source?: string) {
  let resolved = source ?? 'unknown'
  if (resolved !== 'qr') {
    if (document.referrer) resolved = 'referrer'
    else resolved = 'direct'
  }

  return {
    event_id: eventId,
    source: resolved,
    screen_width: window.screen.width,
    screen_height: window.screen.height,
    viewport_width: window.innerWidth,
    viewport_height: window.innerHeight,
    device_pixel_ratio: window.devicePixelRatio || 1,
    touch_support: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
    connection_type: (navigator as Navigator & {connection?: {effectiveType?: string; type?: string}}).connection?.effectiveType
      || (navigator as Navigator & {connection?: {effectiveType?: string; type?: string}}).connection?.type
      || null,
  }
}

export function logOneLinkAccess(eventId: number, source?: string): void {
  if (isPlannerPreview()) return
  axios.post('/one-link-access', usageClientFields(eventId, source)).catch((err) => {
    console.error('Failed to log access:', err)
  })
}

export function logSurfaceAccess(eventId: number, kind: SurfaceKind, source?: string): void {
  if (isPlannerPreview()) return
  axios.post('/surface-access', {...usageClientFields(eventId, source), kind}).catch((err) => {
    console.error('Failed to log access:', err)
  })
}

export function displayDeviceId(): string {
  const key = 'flow:display-device'
  try {
    const existing = localStorage.getItem(key)
    if (existing && /^[0-9a-fA-F-]{36}$/.test(existing)) return existing
    const id = crypto.randomUUID()
    localStorage.setItem(key, id)
    return id
  } catch {
    return crypto.randomUUID()
  }
}

export function beatDisplay(eventId: number): void {
  if (isPlannerPreview()) return
  if (document.visibilityState === 'hidden') return
  axios.post('/display-heartbeat', {
    event_id: eventId,
    device_id: displayDeviceId(),
  }).catch((err) => {
    console.error('Failed to log access:', err)
  })
}
