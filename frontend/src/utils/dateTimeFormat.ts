

// Show dates in the user's locale in a consistent format

export function formatDateTime(datetimeString) {
  if (!datetimeString) return ''
  const date = new Date(datetimeString + 'Z')
  if (isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}

export function formatDateOnly(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  if (isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}
