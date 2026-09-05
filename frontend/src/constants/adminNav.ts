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
  zahlen: 'statistics',
  schnittstellen: 'user-regional-partners',
  anmeldungen: 'enrollments',
}

/** Sidebar folder (not a page). Children are `AdminSection` keys. */
export type AdminNavFolder = {
  kind: 'folder'
  key: string
  label: string
  icon: string
  children: string[]
}

export type AdminNavNode =
  | {kind: 'section'; key: string}
  | AdminNavFolder

/** @deprecated Use AdminNavFolder */
export type AdminOpsNavFolder = AdminNavFolder
/** @deprecated Use AdminNavNode */
export type AdminOpsNavNode = AdminNavNode

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
 * Sidebar grouping lives in ADMIN_OPS_NAV / ADMIN_ENTWICKLUNG_NAV.
 * Every Entwicklung entry uses the same Local+Dev availability gate.
 */
export const ADMIN_SECTIONS: AdminSection[] = [
  // Ops
  {key: 'system-news', label: 'System News', icon: 'bi-newspaper', group: 'ops'},
  {key: 'statistics', label: 'Statistiken', icon: 'bi-bar-chart', group: 'ops'},
  {key: 'plan-qualitaet', label: 'Plan Qualität', icon: 'bi-clipboard-check', group: 'ops'},
  {key: 'enrollments', label: 'Anmeldungen', icon: 'bi-person-plus', group: 'ops'},
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
    label: 'alle m-Tabellen',
    icon: 'bi-hammer',
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
    key: 'parameters',
    label: 'Parameters',
    icon: 'bi-sliders',
    group: 'entwicklung',
    devOrLocalOnly: true,
  },
  {
    key: 'roles',
    label: 'Roles',
    icon: 'bi-people',
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

/**
 * Ops sidebar order. Folders nest existing sections; leftover items stay top-level.
 */
export const ADMIN_OPS_NAV: AdminNavNode[] = [
  {kind: 'section', key: 'system-news'},
  {
    kind: 'folder',
    key: 'zahlen',
    label: 'Zahlen',
    icon: 'bi-123',
    children: ['statistics', 'plan-qualitaet', 'enrollments'],
  },
  {kind: 'section', key: 'calendar'},
  {
    kind: 'folder',
    key: 'schnittstellen',
    label: 'Schnittstellen',
    icon: 'bi-plugin',
    children: ['user-regional-partners', 'external-api', 'sharepoint'],
  },
  {kind: 'section', key: 'wartung'},
]

/**
 * Entwicklung sidebar order. Main Tables is a folder; Now and Next / Massentest stay top-level.
 */
export const ADMIN_ENTWICKLUNG_NAV: AdminNavNode[] = [
  {kind: 'section', key: 'nowandnext'},
  {
    kind: 'folder',
    key: 'main-tables-folder',
    label: 'Main Tables',
    icon: 'bi-table',
    children: ['main-tables', 'match-plans', 'parameters', 'roles'],
  },
  {kind: 'section', key: 'quality'},
]

/** Technical m_* table → special-editor section key. */
export const SPECIAL_M_TABLE_EDITORS: Record<string, string> = {
  m_match: 'match-plans',
  m_parameter: 'parameters',
  m_visibility: 'roles',
}

export function specialEditorForTable(table: string): AdminSection | undefined {
  const key = SPECIAL_M_TABLE_EDITORS[table]
  if (!key) return undefined
  return ADMIN_SECTIONS.find((item) => item.key === key)
}

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

export const ADMIN_FILL_SECTIONS = new Set(['main-tables', 'parameters', 'roles'])
