// frontend/src/utils/dateTimeFormat.ts

const BERLIN_TZ = 'Europe/Berlin'

function berlinParts(ms: number) {
  const dtf = new Intl.DateTimeFormat('en-US', {
    timeZone: BERLIN_TZ,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  })
  const map: Record<string, string> = {}
  for (const part of dtf.formatToParts(new Date(ms))) {
    if (part.type !== 'literal') map[part.type] = part.value
  }
  return {
    year: Number(map.year),
    month: Number(map.month),
    day: Number(map.day),
    hour: Number(map.hour),
    minute: Number(map.minute),
    second: Number(map.second),
  }
}

/**
 * FLOW speichert Aktivitätszeiten als naive Europe/Berlin-Wanduhr
 * ("YYYY-MM-DD HH:mm:ss" ohne Offset). Safari parst das sonst als UTC.
 */
export function parseBerlinWallTime(value: string | null | undefined): number | null {
  if (!value) return null
  const trimmed = String(value).trim()

  if (/Z|[+\-]\d{2}:?\d{2}$/.test(trimmed)) {
    const ms = Date.parse(trimmed)
    return Number.isNaN(ms) ? null : ms
  }

  const m = trimmed.match(
      /^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?/
  )
  if (!m) {
    const ms = Date.parse(trimmed)
    return Number.isNaN(ms) ? null : ms
  }

  const year = Number(m[1])
  const month = Number(m[2])
  const day = Number(m[3])
  const hour = Number(m[4] ?? 0)
  const minute = Number(m[5] ?? 0)
  const second = Number(m[6] ?? 0)
  const wanted = Date.UTC(year, month - 1, day, hour, minute, second)

  // Iterativ: Wandzeit Berlin → UTC-Instant (inkl. DST)
  let utc = wanted
  for (let i = 0; i < 3; i++) {
    const parts = berlinParts(utc)
    const asUtc = Date.UTC(
        parts.year,
        parts.month - 1,
        parts.day,
        parts.hour,
        parts.minute,
        parts.second
    )
    const diff = wanted - asUtc
    if (diff === 0) break
    utc += diff
  }
  return utc
}

/**
 * Aktuelle Uhrzeit (aus clockMs) auf den Kalendertag von dayFromMs legen.
 * Damit wandert die „Jetzt“-Marke im Tagesplan auch dann korrekt,
 * wenn der Veranstaltungstag nicht exakt „heute“ ist (Vorschau/Test).
 */
export function projectClockOntoBerlinDay(clockMs: number, dayFromMs: number): number {
  const day = berlinParts(dayFromMs)
  const clock = berlinParts(clockMs)
  const pad = (n: number) => String(n).padStart(2, '0')
  return (
      parseBerlinWallTime(
          `${day.year}-${pad(day.month)}-${pad(day.day)} ${pad(clock.hour)}:${pad(clock.minute)}:${pad(clock.second)}`
      ) ?? clockMs
  )
}

/** Uhrzeit einer Berlin-Wandzeit / Instant in Europe/Berlin */
export function formatBerlinTimeOnly(value: string | Date | number | null | undefined): string {
  if (value == null || value === '') return ''
  let ms: number | null
  if (value instanceof Date) ms = value.getTime()
  else if (typeof value === 'number') ms = value
  else ms = parseBerlinWallTime(value)
  if (ms == null || Number.isNaN(ms)) return ''

  return new Intl.DateTimeFormat('de-DE', {
    timeZone: BERLIN_TZ,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(new Date(ms))
}

/** plan.last_change etc.: UTC in DB → Anzeige Europe/Berlin (wie Überblick-PDF). */
export function formatBerlinDateTimeFromUtc(
  value: string | Date | number | null | undefined
): string {
  if (value == null || value === '') return ''
  let ms: number
  if (value instanceof Date) {
    ms = value.getTime()
  } else if (typeof value === 'number') {
    ms = value
  } else {
    const trimmed = String(value).trim()
    const hasTZ = /Z|[+\-]\d{2}:?\d{2}$/.test(trimmed)
    ms = Date.parse(hasTZ ? trimmed : `${trimmed.replace(' ', 'T')}Z`)
  }
  if (Number.isNaN(ms)) return ''
  const p = berlinParts(ms)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${pad(p.day)}.${pad(p.month)}.${p.year} ${pad(p.hour)}:${pad(p.minute)}`
}

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

  // Naive DB-Zeiten ohne Offset immer als Berlin behandeln
  if (typeof datetime === 'string' && local && !/Z|[+\-]\d{2}:?\d{2}$/.test(datetime.trim())) {
    return formatBerlinTimeOnly(datetime)
  }

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