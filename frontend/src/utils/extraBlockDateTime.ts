export type Maybe<T> = T | null | undefined

export function extractDate(dt: Maybe<string>): string {
  if (!dt) return ''
  return dt.replace('T', ' ').split(' ')[0]
}

export function extractTime(dt: Maybe<string>): string {
  if (!dt) return ''
  const timePart = dt.replace('T', ' ').split(' ')[1]
  if (!timePart) return ''
  return timePart.slice(0, 5)
}

export function combineDateTime(date: string, time: string): string | null {
  if (!date || !time) return null
  return `${date} ${time}:00`
}

export function timeToMinutes(timeString: string): number {
  if (!timeString || typeof timeString !== 'string') return 0
  const [hours, minutes] = timeString.split(':').map(Number)
  return (hours || 0) * 60 + (minutes || 0)
}

export function normalizeTime(time: string): string {
  if (!time || typeof time !== 'string' || !time.includes(':')) return '00:05'

  const [hours, minutes] = time.split(':').map(Number)
  if (isNaN(hours) || isNaN(minutes)) return '00:05'

  const roundedMinutes = Math.round(minutes / 5) * 5
  let totalMinutes = hours * 60 + roundedMinutes

  const minMinutes = 5
  const maxMinutes = 23 * 60 + 55

  if (totalMinutes < minMinutes) totalMinutes = minMinutes
  if (totalMinutes > maxMinutes) totalMinutes = maxMinutes

  const finalHours = Math.floor(totalMinutes / 60)
  const finalMinutes = totalMinutes % 60

  return `${String(finalHours).padStart(2, '0')}:${String(finalMinutes).padStart(2, '0')}`
}

export function wallTimeToDatetimeLocal(s: string | null): string {
  if (!s || typeof s !== 'string') return ''
  const m = s.trim().match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[1]}T${m[2]}:${m[3]}` : ''
}

export function datetimeLocalToDb(value: string): string | null {
  const v = value?.trim()
  if (!v) return null
  const m = v.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/)
  if (!m) return null
  return `${m[1]} ${m[2]}:${m[3]}:${m[4] ?? '00'}`
}

export function wallTimeHm(s: string | null): string {
  if (!s) return '--:--'
  const m = String(s).match(/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/)
  return m ? `${m[1]}:${m[2]}` : String(s).slice(11, 16)
}

export function eventBaseDateYmd(eventDate?: string): string {
  if (eventDate) {
    const m = String(eventDate).match(/(\d{4}-\d{2}-\d{2})/)
    return m ? m[1] : String(eventDate).slice(0, 10)
  }
  const t = new Date()
  const y = t.getFullYear()
  const mo = String(t.getMonth() + 1).padStart(2, '0')
  const day = String(t.getDate()).padStart(2, '0')
  return `${y}-${mo}-${day}`
}
