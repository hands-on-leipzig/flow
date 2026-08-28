/** Laravel JSON error payload → user-facing string. */
export function apiError(e: unknown, fallback: string): string {
  const data = (e as {response?: {data?: Record<string, unknown>}})?.response?.data
  if (!data) return fallback
  if (typeof data.error === 'string' && data.error) return data.error
  if (data.errors && typeof data.errors === 'object') {
    return Object.values(data.errors as Record<string, string[]>).flat().join(' ')
  }
  if (
    typeof data.message === 'string'
    && data.message
    && data.message !== 'The given data was invalid.'
  ) {
    return data.message
  }
  return fallback
}
