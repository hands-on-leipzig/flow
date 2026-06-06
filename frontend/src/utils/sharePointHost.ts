/**
 * SharePoint / OneDrive block cross-origin fetch from the browser (CORS).
 * Use the backend stream proxy instead of fetch() to *.sharepoint.com.
 */
export function isSharePointHost(urlOrHostname: string): boolean {
  const raw = String(urlOrHostname || '').trim()
  if (!raw) return false
  let host = raw
  if (raw.includes('://')) {
    try {
      host = new URL(raw).hostname
    } catch {
      return false
    }
  }
  const h = host.toLowerCase()
  return h.endsWith('sharepoint.com') || h.endsWith('onedrive.live.com')
}

export function isPdfFileName(name: string): boolean {
  return /\.pdf$/i.test(String(name || '').trim())
}

export function isImageFileName(name: string): boolean {
  return /\.(png|jpe?g|gif|webp|svg|avif|ico)$/i.test(String(name || '').trim())
}

export function canViewInApp(name: string): boolean {
  return isPdfFileName(name) || isImageFileName(name)
}

export function isUrlShortcutFileName(name: string): boolean {
  return /\.url$/i.test(String(name || '').trim())
}

/** Parse Windows Internet Shortcut (.url) file content. */
export function parseInternetShortcutUrl(content: string): string | null {
  for (const line of String(content || '').split(/\r?\n/)) {
    const trimmed = line.trim()
    const match = /^URL=(.+)$/i.exec(trimmed)
    if (!match) continue
    const url = match[1].trim().replace(/^["']|["']$/g, '')
    if (/^https?:\/\//i.test(url)) {
      return url
    }
  }
  return null
}

/** Read .url shortcut text (UTF-8 or UTF-16 LE as created by Windows). */
export async function readUrlShortcutText(blob: Blob): Promise<string> {
  const buffer = await blob.arrayBuffer()
  const bytes = new Uint8Array(buffer)
  if (bytes.length >= 2 && bytes[0] === 0xff && bytes[1] === 0xfe) {
    return new TextDecoder('utf-16le').decode(buffer)
  }
  const utf8 = new TextDecoder('utf-8').decode(buffer)
  if (parseInternetShortcutUrl(utf8)) {
    return utf8
  }
  if (bytes.some((b, i) => b === 0 && i % 2 === 1)) {
    const utf16 = new TextDecoder('utf-16le').decode(buffer)
    if (parseInternetShortcutUrl(utf16)) {
      return utf16
    }
  }
  return utf8
}
