/**
 * Shared download naming: FLOW_{Name}_({dd.mm.yy}).{ext}
 */

export function flowFilename(
  name: string,
  extension: string,
  date?: string | Date | null
): string {
  const safeName = sanitizeFlowFilenameName(name)
  const formattedDate = formatFlowFilenameDate(date)
  const ext = extension.replace(/^\./, '')
  return `FLOW_${safeName}_(${formattedDate}).${ext}`
}

/** dd.mm.yy — from ISO date string, Date, already-formatted value, or today. */
export function formatFlowFilenameDate(date?: string | Date | null): string {
  if (typeof date === 'string' && /^\d{2}\.\d{2}\.\d{2}$/.test(date)) {
    return date
  }

  let d: Date
  if (!date) {
    d = new Date()
  } else if (date instanceof Date) {
    d = date
  } else if (/^\d{4}-\d{2}-\d{2}/.test(date)) {
    const [y, m, day] = date.slice(0, 10).split('-').map(Number)
    d = new Date(y, m - 1, day)
  } else {
    d = new Date(date)
  }

  const dd = String(d.getDate()).padStart(2, '0')
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const yy = String(d.getFullYear()).slice(-2)
  return `${dd}.${mm}.${yy}`
}

export function sanitizeFlowFilenameName(name: string): string {
  return name
    .replace(/ä/g, 'ae')
    .replace(/ö/g, 'oe')
    .replace(/ü/g, 'ue')
    .replace(/Ä/g, 'Ae')
    .replace(/Ö/g, 'Oe')
    .replace(/Ü/g, 'Ue')
    .replace(/ß/g, 'ss')
    .replace(/\s+/g, '_')
    .replace(/[^a-zA-Z0-9_-]/g, '')
}
