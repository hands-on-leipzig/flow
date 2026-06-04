import axios from 'axios'
import {isPdfFileName, isSharePointHost} from '@/utils/sharePointHost'

interface SharePointFileItem {
  id: string
  drive_id?: string | null
  name: string
  web_url?: string | null
}

interface FileLinkResponse {
  url?: string
  via?: string
  use_stream?: boolean
}

function openExternalUrl(url: string) {
  window.open(url, '_blank', 'noopener,noreferrer')
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

async function openViaStream(driveId: string, itemId: string) {
  const blob = await getFileStreamBlob(driveId, itemId)
  if (!blob) return
  const blobUrl = URL.createObjectURL(blob)
  openExternalUrl(blobUrl)
  setTimeout(() => URL.revokeObjectURL(blobUrl), 60_000)
}

export function useSharePointFileOpen() {
  async function openDocumentFile(file: SharePointFileItem) {
    const driveId = String(file?.drive_id || '').trim()
    const itemId = String(file?.id || '').trim()

    if (driveId && itemId) {
      if (isPdfFileName(file.name)) {
        await openViaStream(driveId, itemId)
        return
      }

      try {
        const {data} = await axios.get<FileLinkResponse>('/sharepoint/documents-file-link', {
          params: {drive_id: driveId, item_id: itemId},
        })
        if (data?.use_stream) {
          await openViaStream(driveId, itemId)
          return
        }
        const guestUrl = String(data?.url || '').trim()
        const via = String(data?.via || '')
        const preferStream =
          data?.use_stream ||
          via === 'stream_proxy' ||
          (guestUrl && /\/:f:\//i.test(guestUrl))
        if (!preferStream && guestUrl) {
          openExternalUrl(guestUrl)
          return
        }
        await openViaStream(driveId, itemId)
        return
      } catch {
        await openViaStream(driveId, itemId)
        return
      }
    }

    const rawUrl = String(file?.web_url || '').trim()
    if (!rawUrl) return
    if (isPdfFileName(file.name) && isSharePointHost(rawUrl)) {
      openExternalUrl(rawUrl)
      return
    }
    openExternalUrl(rawUrl)
  }

  return {openDocumentFile}
}
