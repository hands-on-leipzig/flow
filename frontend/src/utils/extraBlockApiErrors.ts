import axios from 'axios'

export function parseExtraBlockSaveError(
  error: unknown,
  fallbackMessage: string,
): {message: string; details: string | null} {
  let errorMessage = fallbackMessage
  let details: string | null = null

  if (axios.isAxiosError(error)) {
    const status = error.response?.status
    const errorData = error.response?.data as Record<string, unknown> | undefined

    if (status === 422) {
      errorMessage = String(errorData?.error || 'Die aktuelle Konfiguration wird nicht unterstützt')
      details = String(errorData?.details || errorData?.message || 'Ungültige Block-Kombination')
    } else if (status === 404) {
      errorMessage = 'Block oder Plan nicht gefunden'
      details = String(errorData?.error || errorData?.details || 'Ressource nicht gefunden')
    } else if (status === 500) {
      errorMessage = String(errorData?.error || 'Fehler bei der Block-Speicherung')
      details = String(errorData?.details || errorData?.message || 'Interner Serverfehler')
    } else if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
      errorMessage = 'Verbindungsfehler'
      details = 'Bitte überprüfe deine Internetverbindung.'
    } else {
      errorMessage = String(errorData?.error || errorData?.message || error.message || errorMessage)
    }
  } else if (error instanceof Error) {
    errorMessage = error.message
  }

  return {message: errorMessage, details}
}
