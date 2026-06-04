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
