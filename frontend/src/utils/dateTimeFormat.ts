// frontend/src/utils/dateTimeFormat.ts

// Kleinere Hilfsfunktion: String -> Date (sicher) 
function toDate(value: string | Date | null | undefined): Date | null {
  if (!value) return null
  if (value instanceof Date) return isNaN(value.getTime()) ? null : value

  // Falls der String schon eine Zeitzone hat (Z oder ±hh:mm), nichts anhängen
  const hasTZ = /Z|[+\-]\d{2}:\d{2}$/.test(value)
  const iso = hasTZ ? value : `${value}Z`

  const d = new Date(iso)
  return isNaN(d.getTime()) ? null : d
}

/**
 * Uhrzeit (HH:mm) in Browser-Lokale formatieren, 24h
 * Erwartet UTC-Strings (z.B. "2025-09-04 07:06:46" oder ISO).
 */
export function formatTimeOnly(datetime: string | Date | null | undefined): string {
  const date = toDate(datetime)
  if (!date) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}


/**
 * Datum + Uhrzeit in Browser-Lokale formatieren, 24h
 * Erwartet UTC-Strings (z.B. "2025-09-04 07:06:46" oder ISO).
 */
export function formatDateTime(datetime: string | Date | null | undefined): string {
  const date = toDate(datetime)
  if (!date) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}

/**
 * Nur Datum in Browser-Lokale formatieren.
 * Erwartet UTC-Strings (z.B. "2026-02-20" oder ISO).
 */
export function formatDateOnly(dateInput: string | Date | null | undefined): string {
  // Bei reinen YYYY-MM-DD Strings anhängen wir kein 'Z', da das sonst auf Vortag kippen kann.
  const isYmdOnly = typeof dateInput === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dateInput)
  const date = isYmdOnly
    ? ((): Date | null => {
        // Als lokale Mitternacht interpretieren
        const parts = (dateInput as string).split('-').map(Number)
        const d = new Date(parts[0], parts[1] - 1, parts[2])
        return isNaN(d.getTime()) ? null : d
      })()
    : toDate(dateInput)

  if (!date) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}