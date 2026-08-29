import {type Ref} from 'vue'
import axios from 'axios'
import {parseExtraBlockSaveError} from '@/utils/extraBlockApiErrors'

export async function pollPlanUntilReady(
  planId: number,
  isGenerating: Ref<boolean>,
  generatorError: Ref<string | null>,
  errorDetails: Ref<string | null>,
  timeoutMs = 60000,
  intervalMs = 1000,
): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 200))

  const start = Date.now()

  try {
    while (Date.now() - start < timeoutMs) {
      const res = await axios.get(`/plans/${planId}/status`)
      const status = res.data.status

      if (status === 'done') {
        isGenerating.value = false
        return
      }

      if (status === 'failed') {
        isGenerating.value = false
        generatorError.value = 'Die Generierung ist fehlgeschlagen'
        errorDetails.value =
          'Der Plan konnte nicht generiert werden. Bitte überprüfe die Block-Einstellungen.'
        return
      }

      await new Promise((resolve) => setTimeout(resolve, intervalMs))
    }

    throw new Error('Timeout: Plan generation took too long')
  } catch (error: unknown) {
    isGenerating.value = false

    if (error instanceof Error && error.message.includes('Timeout')) {
      generatorError.value = 'Zeitüberschreitung'
      errorDetails.value = 'Die Generierung dauert zu lange. Bitte versuche es erneut.'
      return
    }

    const parsed = parseExtraBlockSaveError(error, 'Fehler bei der Plan-Generierung')
    generatorError.value = parsed.message
    errorDetails.value = parsed.details
  }
}
