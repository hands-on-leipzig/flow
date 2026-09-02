export type QualityEvaluationStatus = 'ok' | 'incomplete' | 'not_evaluable'

export function evaluationStatusLabel(status: string | null | undefined): string | null {
  if (status === 'not_evaluable') return 'Nicht auswertbar'
  if (status === 'incomplete') return 'Plan unvollständig'
  return null
}

export function evaluationStatusChipClass(status: string | null | undefined): string {
  if (status === 'not_evaluable') {
    return 'bg-red-500/20 text-red-700 dark:text-red-300'
  }
  if (status === 'incomplete') {
    return 'bg-amber-500/20 text-amber-700 dark:text-amber-300'
  }
  return ''
}

export function evaluationReasonsTooltip(qPlan: { evaluation_reasons?: string[] | null } | null | undefined): string {
  const reasons = qPlan?.evaluation_reasons
  if (!reasons?.length) return ''
  return reasons.join(' · ')
}
