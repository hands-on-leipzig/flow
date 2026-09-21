import {eventPrograms, teamPathFor, type EventWithPrograms} from '@/utils/eventPrograms'

export const TEAMS_PROGRAM_HELP_KEY = 'teams-program'
export const TEAMS_PROGRAM_ROUTE_PATH = '/plan/teams/:program'

export const HELP_SCREEN_KEY_BY_PATH: Record<string, string> = {
  '/plan/publish': 'publish-distribution',
  '/plan/publish/logos': 'publish-logos',
  '/plan/publish/analog': 'publish-analog',
  '/plan/publish/namensschilder': 'publish-namensschilder',
  '/plan/teams/data': 'teams-data',
  '/plan/rooms': 'rooms',
  '/plan/volunteers': 'volunteers-people',
  '/plan/volunteers/roster': 'volunteers-roster',
  '/plan/volunteers/staffing': 'volunteers-staffing',
  '/plan/live/check-in': 'live-check-in',
  '/plan/live/cockpit': 'live-cockpit',
  '/plan/schedule': 'schedule-general',
  '/plan/schedule/integration': 'schedule-integration',
  '/plan/schedule/times': 'schedule-times',
  '/plan/schedule/afternoon': 'schedule-afternoon',
  '/plan/schedule/expert': 'schedule-expert',
  '/plan/schedule/protected': 'schedule-protected',
  '/plan/schedule/free': 'schedule-free',
  '/plan/schedule/slots': 'schedule-slots',
}

const SCHEDULE_NOTICE_SCREEN_KEYS = Object.values(HELP_SCREEN_KEY_BY_PATH)
  .filter((key) => key.startsWith('schedule-'))

/** Extra notice screens mirrored onto another page (not that page’s own help). */
export const NOTICE_MIRROR_SCREEN_KEYS_BY_PATH: Record<string, string[]> = {
  '/plan/publish/analog': [TEAMS_PROGRAM_HELP_KEY, 'rooms', ...SCHEDULE_NOTICE_SCREEN_KEYS],
  '/plan/publish/namensschilder': [TEAMS_PROGRAM_HELP_KEY, 'volunteers-staffing', ...SCHEDULE_NOTICE_SCREEN_KEYS],
}

export function noticeMirrorScreenKeysForPath(path: string): string[] {
  return NOTICE_MIRROR_SCREEN_KEYS_BY_PATH[path] ?? []
}

export type HelpScreenRef = {
  id?: number
  key?: string
  route_path?: string
}

export function isTeamsProgramHelp(screen: HelpScreenRef): boolean {
  return screen.key === TEAMS_PROGRAM_HELP_KEY
    || screen.route_path === TEAMS_PROGRAM_ROUTE_PATH
}

export function helpJumpPath(
  screen: HelpScreenRef,
  event?: EventWithPrograms | null,
): string | null {
  if (isTeamsProgramHelp(screen)) {
    const programs = eventPrograms(event)
    if (!programs.length) return null
    return teamPathFor(programs[0])
  }
  const path = (screen.route_path || '').trim()
  return path || null
}

export function isHelpScreenCurrent(
  screen: HelpScreenRef,
  route: {name?: unknown; path?: string},
  currentArticleId?: number | null,
): boolean {
  if (currentArticleId != null && screen.id === currentArticleId) return true
  if (isTeamsProgramHelp(screen) && route.name === 'teams-program') return true
  const currentPath = (route.path || '').replace(/\/$/, '') || '/'
  const stored = (screen.route_path || '').replace(/\/$/, '') || '/'
  return stored === currentPath
}
