// frontend/src/utils/dateTimeFormat.ts

/**
 * Uhrzeit (HH:mm) formatieren, 24h.
 * Erwartet:
 *  - UTC-Strings (z.B. "2025-09-04 07:06:46Z" oder ISO) wenn local=false
 *  - lokale Strings (Europe/Berlin, z.B. "2025-09-04T07:06:46+02:00") wenn local=true
 */
export function formatTimeOnly(
  datetime: string | Date | null | undefined,
  local: boolean = false
): string {
  if (!datetime) return ''

  let date: Date | null = null

  if (datetime instanceof Date) {
    date = isNaN(datetime.getTime()) ? null : datetime
  } else {
    const hasTZ = /Z|[+\-]\d{2}:\d{2}$/.test(datetime)
    if (local) {
      date = new Date(hasTZ ? datetime : `${datetime}`)
    } else {
      date = new Date(hasTZ ? datetime : `${datetime}Z`)
    }
  }

  if (!date || isNaN(date.getTime())) return ''

  return new Intl.DateTimeFormat(navigator.language, {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}


/**
 * Datum + Uhrzeit formatieren, 24h.
 * Erwartet:
 *  - UTC-Strings (z.B. "2025-09-04 07:06:46Z" oder ISO) wenn local=false
 *  - lokale Strings (Europe/Berlin, z.B. "2025-09-04T07:06:46+02:00") wenn local=true
 */
export function formatDateTime(
  datetime: string | Date | null | undefined,
  local: boolean = false
): string {
  if (!datetime) return ''

  let date: Date | null = null

  if (datetime instanceof Date) {
    date = isNaN(datetime.getTime()) ? null : datetime
  } else {
    // String-Handling
    const hasTZ = /Z|[+\-]\d{2}:\d{2}$/.test(datetime)
    if (local) {
      // String so interpretieren, wie er kommt (lokale Eingabe mit Offset oder plain)
      date = new Date(hasTZ ? datetime : `${datetime}`)
    } else {
      // Default: als UTC interpretieren
      date = new Date(hasTZ ? datetime : `${datetime}Z`)
    }
  }

  if (!date || isNaN(date.getTime())) return ''

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
 * Erwartet UTC- oder ISO-Strings (z.B. "2026-02-20" oder "2026-02-20T00:00:00Z").
 */
export function formatDateOnly(dateInput: string | Date | null | undefined): string {
  if (!dateInput) return ''
  const date = new Date(dateInput)
  if (isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(date)
}