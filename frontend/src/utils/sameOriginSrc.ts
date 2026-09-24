export function sameOriginSrc(url: string): string {
  if (!url || url.startsWith('/') || url.startsWith('data:')) return url
  try {
    const parsed = new URL(url)
    if (
      parsed.hostname === 'localhost'
      || parsed.hostname === '127.0.0.1'
      || parsed.hostname === 'host.docker.internal'
    ) {
      return parsed.pathname + parsed.search
    }
  } catch {
    // keep original
  }
  return url
}

export function rewriteImageSrcs<T>(value: T): T {
  walkImageSrcs(value)
  return value
}

function walkImageSrcs(value: unknown): void {
  if (Array.isArray(value)) {
    value.forEach(walkImageSrcs)
    return
  }
  if (!value || typeof value !== 'object') return

  const record = value as Record<string, unknown>
  if (typeof record.src === 'string') {
    record.src = sameOriginSrc(record.src)
  }
  for (const child of Object.values(record)) {
    if (child && typeof child === 'object') walkImageSrcs(child)
  }
}
