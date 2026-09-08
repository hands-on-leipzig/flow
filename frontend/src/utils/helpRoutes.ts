import {eventPrograms, teamPathFor, type EventWithPrograms} from '@/utils/eventPrograms'

export const TEAMS_PROGRAM_HELP_KEY = 'teams-program'
export const TEAMS_PROGRAM_ROUTE_PATH = '/plan/teams/:program'

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
