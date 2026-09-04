export const ADMIN_DEFAULT_SECTION = 'system-news'

export type AdminGroup = 'ops' | 'entwicklung'

export type AdminSection = {
  key: string
  label: string
  icon: string
  path: string
  group: AdminGroup
  /** Hosted Dev or localhost (same gate for every Entwicklung entry). */
  devOrLocalOnly?: boolean
}

const DEV_HOSTS = ['dev.flow.hands-on-technology.org']
const LOCAL_HOSTS = ['localhost', '127.0.0.1']
const BLOCKED_HOSTS = ['test.flow.hands-on-technology.org', 'flow.hands-on-technology.org']

/** Old section keys → current key (bookmarks / deep links). */
const SECTION_ALIASES: Record<string, string> = {
  sync: 'wartung',
  hilfsfunktionen: 'wartung',
  conditions: 'statistics',
  mparameter: 'statistics',
}

function currentHostname(): string {
  return typeof window === 'undefined' ? '' : window.location.hostname
}

export function isHostedDev(): boolean {
  return DEV_HOSTS.includes(currentHostname())
}

export function isLocalHost(): boolean {
  return LOCAL_HOSTS.includes(currentHostname())
}

/** Local and/or hosted Dev — never Test/Production. */
export function isEntwicklungEnvironment(isLocal: boolean): boolean {
  const host = currentHostname()
  if (BLOCKED_HOSTS.includes(host)) return false
  return isHostedDev() || isLocal || isLocalHost()
}

/**
 * Ops tools (all tiers), then Entwicklung (always listed; Local/Dev enable the entries).
 * Full-page UIs stay top-level; push-button actions live under Wartung.
 * Every Entwicklung entry uses the same Local+Dev availability gate.
 */
export const ADMIN_SECTIONS: AdminSection[] = [
  // Ops
  {key: 'system-news', label: 'System News', icon: 'bi-newspaper', group: 'ops'},
  {key: 'statistics', label: 'Statistiken', icon: 'bi-bar-chart', group: 'ops'},
  {key: 'plan-qualitaet', label: 'Plan Qualität', icon: 'bi-clipboard-check', group: 'ops'},
  {key: 'user-regional-partners', label: 'User ↔ Regionen', icon: 'bi-people', group: 'ops'},
  {key: 'calendar', label: 'Kalender-Feeds', icon: 'bi-calendar3', group: 'ops'},
  {key: 'external-api', label: 'External API', icon: 'bi-key', group: 'ops'},
  {key: 'sharepoint', label: 'SharePoint', icon: 'bi-folder', group: 'ops'},
  {key: 'wartung', label: 'Wartung', icon: 'bi-tools', group: 'ops'},
  // Entwicklung (bottom) — listed everywhere; enabled on Local + Dev only
  {
    key: 'nowandnext',
    label: 'Now and Next',
    icon: 'bi-clock-history',
    group: 'entwicklung',
    devOrLocalOnly: true,
  },
  {
    key: 'main-tables',
    label: 'Main Tables',
    icon: 'bi-table',
    group: 'entwicklung',
    devOrLocalOnly: true,
  },
  {
    key: 'match-plans',
    label: 'Matchpläne',
    icon: 'bi-grid-3x3-gap',
    group: 'entwicklung',
    devOrLocalOnly: true,
  },
  {
    key: 'quality',
    label: 'Massentest',
    icon: 'bi-flask',
    group: 'entwicklung',
    devOrLocalOnly: true,
  },
].map((item) => ({
  ...item,
  path: `/plan/admin/${item.key}`,
}))

export const ADMIN_OPS_SECTIONS = ADMIN_SECTIONS.filter((s) => s.group === 'ops')
export const ADMIN_ENTWICKLUNG_SECTIONS = ADMIN_SECTIONS.filter((s) => s.group === 'entwicklung')

export function resolveAdminSection(key: string): string {
  const raw = String(key || '')
  if (SECTION_ALIASES[raw]) return SECTION_ALIASES[raw]
  return raw
}

export function isAdminSection(key: string): boolean {
  return ADMIN_SECTIONS.some((item) => item.key === key)
}

/**
 * Entwicklung (and any `devOrLocalOnly` flag): interactive only on Local or hosted Dev.
 * Test/production hosts still list the entries, but they stay disabled in the nav.
 */
export function isAdminSectionAvailable(
  item: Pick<AdminSection, 'devOrLocalOnly' | 'group'>,
  _isDev: boolean,
  isLocal: boolean,
): boolean {
  if (item.group === 'entwicklung' || item.devOrLocalOnly) {
    return isEntwicklungEnvironment(isLocal)
  }
  return true
}

/** Contao / other Wartung cards that are Local+Dev only. */
export function isDevOrLocalToolAvailable(isLocal: boolean): boolean {
  return isEntwicklungEnvironment(isLocal)
}

export function adminSectionPath(key: string = ADMIN_DEFAULT_SECTION): string {
  return `/plan/admin/${key}`
}
