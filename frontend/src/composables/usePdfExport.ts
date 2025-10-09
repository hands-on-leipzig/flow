// src/composables/usePdfExport.ts
import { ref, computed } from 'vue'
import axios from 'axios'

interface DownloadState {
  [key: string]: boolean
}

/**
 * Composable für beliebige PDF-Downloads mit Ladezustand & automatischem Download.
 *
 * Beispiel:
 * const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()
 * await downloadPdf('rooms', `/export/pdf_download/rooms/${eventId}`)
 */
export function usePdfExport() {
  const isDownloading = ref<DownloadState>({})
  const anyDownloading = computed(() => Object.values(isDownloading.value).some(Boolean))

  async function downloadPdf(key: string, url: string, filenameHint?: string) {
    if (isDownloading.value[key]) return

    isDownloading.value[key] = true
    try {
      const response = await axios.get(url, { responseType: 'blob' })

      // Dateiname aus Header oder Fallback
      const filename =
        response.headers['x-filename'] ||
        filenameHint ||
        decodeURIComponent(url.split('/').pop() || 'download.pdf')

      const blob = new Blob([response.data], { type: 'application/pdf' })
      const link = document.createElement('a')
      link.href = window.URL.createObjectURL(blob)
      link.download = filename
      link.click()
      window.URL.revokeObjectURL(link.href)
    } catch (error) {
      console.error(`Fehler beim PDF-Download (${key}):`, error)
    } finally {
      isDownloading.value[key] = false
    }
  }

  return { isDownloading, anyDownloading, downloadPdf }
}