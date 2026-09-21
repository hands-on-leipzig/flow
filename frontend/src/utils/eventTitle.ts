/**
 * Display titles come from the API (`EventTitleService`). Frontend does not
 * reconstruct competition type from programs or level.
 */
export type EventTitleFields = {
  title_long?: string | null
  title_short?: string | null
  title_place?: string | null
}

function field(event: EventTitleFields | null | undefined, key: keyof EventTitleFields): string {
  return event?.[key]?.trim() || ''
}

export function getEventTitleLong(event: EventTitleFields | null | undefined): string {
  return field(event, 'title_long')
}

export function getEventTitleShort(event: EventTitleFields | null | undefined): string {
  return field(event, 'title_short')
}

export function cleanEventName(event: EventTitleFields | null | undefined): string {
  return field(event, 'title_place')
}
