/** Same-origin route to the event one-link (public plan). */
export function publicPlanPath(
    raw: string | null | undefined,
    fallbackSlug: string,
): string | null {
  if (raw) {
    if (/^https?:\/\//i.test(raw)) {
      try {
        return new URL(raw).pathname
      } catch {
        /* ignore */
      }
    }
    const path = raw.replace(/^\//, '').trim()
    if (path) return `/${path}`
  }
  const slug = fallbackSlug.trim()
  return slug ? `/${slug}` : null
}
