/**
 * Event type with required properties for title generation
 */
import {
  hasChallenge,
  hasExplore,
  hasFuture,
  type EventWithPrograms,
} from '@/utils/eventPrograms'

type EventForTitle = EventWithPrograms

function getCompetitionTypeText(event: EventForTitle | null): string {
  if (!event) return 'Wettbewerb'

  const explore = hasExplore(event)
  const challenge = hasChallenge(event)
  const future = hasFuture(event)
  const level = event.level ?? 0

  if (level === 2) {
    return 'Qualifikationswettbewerb'
  }

  if (level === 3) {
    return 'Finale'
  }

  if (future && !explore && !challenge) {
    return 'Future Wettbewerb'
  }
  if (future && (explore || challenge)) {
    return 'Mixed Wettbewerb'
  }

  if (level === 1) {
    if (explore && challenge) {
      return 'Ausstellung und Regionalwettbewerb'
    }
    if (explore && !challenge) {
      return 'Ausstellung'
    }
    if (challenge && !explore) {
      return 'Regionalwettbewerb'
    }
  }

  return 'Wettbewerb'
}

/**
 * Abbreviate competition type for short format
 * "Regionalwettbewerb" → "Regio", "Qualifikationswettbewerb" → "Quali", etc.
 */
function abbreviateCompetitionType(competitionType: string): string {
  // Replace "Regionalwettbewerb" with "Regio"
  let abbreviated = competitionType.replace(/Regionalwettbewerb/g, 'Regio')
  
  // Replace "Qualifikationswettbewerb" with "Quali"
  abbreviated = abbreviated.replace(/Qualifikationswettbewerb/g, 'Quali')
  
  return abbreviated
}

/**
 * Clean event name by removing redundant prefixes based on level
 * Removes "Qualifikation " for level 2, "Finale " for level 3
 */
export function cleanEventName(event: EventForTitle | null): string {
  if (!event || !event.name) return ''
  
  let eventName = event.name
  const level = event.level ?? 0

  // Remove "Qualifikation " prefix if level is 2
  if (level === 2) {
    eventName = eventName.replace(/^Qualifikation\s+/i, '')
  }

  // Remove "Finale " prefix if level is 3
  if (level === 3) {
    eventName = eventName.replace(/^Finale\s+/i, '')
  }

  return eventName.trim()
}

/**
 * Get long format event title
 * Returns: "FIRST LEGO League Ausstellung und Regionalwettbewerb Aachen"
 * Note: Caller should wrap "FIRST" in <em> tags for UI
 * 
 * @param event Event with programs, level, and name
 * @returns Complete event title in long format
 */
export function getEventTitleLong(event: EventForTitle | null): string {
  if (!event) return ''
  
  const competitionType = getCompetitionTypeText(event)
  const eventName = cleanEventName(event)
  
  return `FIRST LEGO League ${competitionType} ${eventName}`.trim()
}

/**
 * Get short format event title
 * Returns: "Ausstellung und Regio Aachen"
 * 
 * @param event Event with programs, level, and name
 * @returns Complete event title in short format
 */
export function getEventTitleShort(event: EventForTitle | null): string {
  if (!event) return ''
  
  const competitionType = getCompetitionTypeText(event)
  const abbreviatedType = abbreviateCompetitionType(competitionType)
  const eventName = cleanEventName(event)
  
  return `${abbreviatedType} ${eventName}`.trim()
}

/**
 * Get competition type text only (for "Art:" display)
 * Returns: "Ausstellung und Regionalwettbewerb", "Ausstellung", "Regionalwettbewerb", etc.
 */
export function getCompetitionType(event: EventForTitle | null): string {
  return getCompetitionTypeText(event)
}

/**
 * Get competition type for nav label (no location name).
 * Returns: "Quali", "Regio", "Ausstellung und Regio", "Finale", "Ausstellung", etc.
 */
export function getAbbreviatedCompetitionType(event: EventForTitle | null): string {
  if (!event) return ''
  const competitionType = getCompetitionTypeText(event)
  return abbreviateCompetitionType(competitionType)
}
