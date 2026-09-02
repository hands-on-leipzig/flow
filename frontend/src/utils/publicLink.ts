/** Vanity host for shareable event links (QR, DRAHT, Veröffentlichung). */
const DEFAULT_PUBLIC_LINK_BASE = 'https://handson.tools'

export function publicLinkBase(): string {
  const fromEnv = import.meta.env.VITE_PUBLIC_URL || import.meta.env.VITE_APP_URL
  if (fromEnv) return String(fromEnv).replace(/\/$/, '')
  if (typeof window !== 'undefined' && window.location?.origin) {
    return window.location.origin.replace(/\/$/, '')
  }
  return DEFAULT_PUBLIC_LINK_BASE
}

/** Absolute public plan URL; rewrites any stored host to the configured base. */
export function normalizePublicLink(raw: string | null | undefined): string {
  if (!raw) return ''
  const base = publicLinkBase()
  if (/^https?:\/\//i.test(raw)) {
    try {
      const url = new URL(raw)
      return `${base}${url.pathname}${url.search}`
    } catch {
      return raw
    }
  }
  return `${base}/${raw.replace(/^\//, '')}`
}
