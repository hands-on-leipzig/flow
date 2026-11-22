/**
 * SCORE runs inside FLOW under /score and alone on its own host
 * (score.hands-on-technology.org, score.handson.tools, dev./test. in front).
 * On that host SCORE owns the root and FLOW lives on the matching flow host.
 */
const SCORE_HOST = /^(?:(?:dev|test)\.)?score\./

export function isScoreHost(hostname: string = window.location.hostname): boolean {
  return SCORE_HOST.test(hostname.toLowerCase())
}

export const scoreBase = isScoreHost() ? '' : '/score'

/** `/12/jury/5` → `/score/12/jury/5` in FLOW, unchanged on the SCORE host. */
export function scorePath(sub = ''): string {
  return `${scoreBase}${sub}` || '/'
}

export function isScorePath(path: string): boolean {
  if (isScoreHost()) return true
  return path === '/score' || path.startsWith('/score/')
}

/** score.x → flow.x, dev.score.x → dev.flow.x */
export function flowHost(hostname: string): string {
  return hostname.replace(/(^|\.)score\./i, '$1flow.')
}

export function flowUrl(path: string): string {
  const {protocol, hostname, port} = window.location
  return `${protocol}//${flowHost(hostname)}${port ? `:${port}` : ''}${path}`
}
