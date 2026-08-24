export const ADMIN_DEFAULT_SECTION = 'statistics'

export type AdminSection = {
  key: string
  label: string
  icon: string
  path: string
  devOnly?: boolean
  devOrLocalOnly?: boolean
  devSuffix?: string
}

const DEV_HOSTS = ['dev.flow.hands-on-technology.org']
const LOCAL_HOSTS = ['localhost', '127.0.0.1']
const BLOCKED_HOSTS = ['test.flow.hands-on-technology.org', 'flow.hands-on-technology.org']

function currentHostname(): string {
  return typeof window === 'undefined' ? '' : window.location.hostname
}

export function isHostedDev(): boolean {
  return DEV_HOSTS.includes(currentHostname())
}

export const ADMIN_SECTIONS: AdminSection[] = [
  {key: 'statistics', label: 'Statistiken', icon: 'bi-bar-chart'},
  {key: 'main-tables', label: 'Main Tables', icon: 'bi-table', devOnly: true, devSuffix: '(nur Dev)'},
  {key: 'system-news', label: 'System News', icon: 'bi-newspaper'},
  {key: 'nowandnext', label: 'Now and Next', icon: 'bi-clock-history'},
  {key: 'calendar', label: 'Kalender-Feeds', icon: 'bi-calendar3'},
  {key: 'quality', label: 'Massentest', icon: 'bi-flask', devOrLocalOnly: true, devSuffix: '(Dev oder lokal)'},
  {key: 'conditions', label: 'Parameter-Anzeige', icon: 'bi-sliders'},
  {key: 'user-regional-partners', label: 'User ↔ Regionen (Zugang)', icon: 'bi-people'},
  {key: 'sync', label: 'Draht Sync', icon: 'bi-arrow-repeat'},
  {key: 'external-api', label: 'External API', icon: 'bi-key'},
  {key: 'sharepoint', label: 'SharePoint', icon: 'bi-folder'},
  {key: 'hilfsfunktionen', label: 'Hilfsfunktionen', icon: 'bi-tools'},
].map((item) => ({
  ...item,
  path: `/plan/admin/${item.key}`,
}))

export function isAdminSection(key: string): boolean {
  return ADMIN_SECTIONS.some((item) => item.key === key)
}

/**
 * Main Tables: only the hosted Dev site (not local APP_ENV=local).
 * Massentest: hosted Dev or localhost.
 * Test/production hosts never get either.
 */
export function isAdminSectionAvailable(
  item: Pick<AdminSection, 'devOnly' | 'devOrLocalOnly'>,
  _isDev: boolean,
  isLocal: boolean,
): boolean {
  const host = currentHostname()
  if (BLOCKED_HOSTS.includes(host)) {
    return !item.devOnly && !item.devOrLocalOnly
  }
  if (item.devOnly) return isHostedDev()
  if (item.devOrLocalOnly) return isHostedDev() || isLocal || LOCAL_HOSTS.includes(host)
  return true
}

export function adminSectionPath(key: string = ADMIN_DEFAULT_SECTION): string {
  return `/plan/admin/${key}`
}

