import axios from 'axios'
import {canViewInApp, isImageFileName, isPdfFileName, isSharePointHost, isUrlShortcutFileName, parseInternetShortcutUrl, readUrlShortcutText} from '@/utils/sharePointHost'

interface SharePointFileItem {
  id: string
  drive_id?: string | null
  name: string
  web_url?: string | null
}

interface SharePointFileOpenOptions {
  openPdfFromBlob?: (blob: Blob, title: string) => Promise<boolean>
  openImageFromBlob?: (blob: Blob, title: string) => Promise<boolean>
  openExternalUrl?: (url: string) => void
}

async function getFileStreamBlob(driveId: string, itemId: string): Promise<Blob | null> {
  try {
    const res = await axios.get('/sharepoint/documents-file-stream', {
      params: {drive_id: driveId, item_id: itemId},
      responseType: 'blob',
    })
    const blob = res?.data as Blob
    return blob && blob.size > 0 ? blob : null
  } catch {
    return null
  }
}

/**
 * SharePoint bytes are loaded via the Flow API proxy, never fetch() to *.sharepoint.com.
 * PDFs and images open in-app; other types open a blob URL in a new tab (no SharePoint login).
 */
export function useSharePointFileOpen(options: SharePointFileOpenOptions = {}) {
  const {
    openPdfFromBlob,
    openImageFromBlob,
    openExternalUrl = (url: string) => window.open(url, '_blank', 'noopener,noreferrer'),
  } = options

  async function openViaStream(driveId: string, itemId: string, file: SharePointFileItem): Promise<boolean> {
    const blob = await getFileStreamBlob(driveId, itemId)
    if (!blob) return false

    const title = String(file?.name || 'Download')

    if (isPdfFileName(file.name) && openPdfFromBlob) {
      if (await openPdfFromBlob(blob, title)) return true
    }

    if (isImageFileName(file.name) && openImageFromBlob) {
      if (await openImageFromBlob(blob, title)) return true
    }

    if (isUrlShortcutFileName(file.name)) {
      const text = await readUrlShortcutText(blob)
      const targetUrl = parseInternetShortcutUrl(text)
      if (targetUrl) {
        openExternalUrl(targetUrl)
        return true
      }
    }

    const blobUrl = URL.createObjectURL(blob)
    openExternalUrl(blobUrl)
    setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
    return true
  }

  async function openDocumentFile(file: SharePointFileItem): Promise<boolean> {
    const driveId = String(file?.drive_id || '').trim()
    const itemId = String(file?.id || '').trim()

    if (driveId && itemId) {
      return openViaStream(driveId, itemId, file)
    }

    const rawUrl = String(file?.web_url || '').trim()
    if (!rawUrl) return false

    if (canViewInApp(file.name) && isSharePointHost(rawUrl)) {
      return false
    }

    openExternalUrl(rawUrl)
    return true
  }

  return {openDocumentFile}
}
