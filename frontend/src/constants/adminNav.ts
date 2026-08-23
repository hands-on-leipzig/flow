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

export const ADMIN_SECTIONS: AdminSection[] = [
  {key: 'statistics', label: 'Statistiken', icon: 'bi-bar-chart'},
  {key: 'main-tables', label: 'Main Tables', icon: 'bi-table', devOnly: true, devSuffix: '(nur Dev)'},
  {key: 'system-news', label: 'System News', icon: 'bi-newspaper'},
  {key: 'nowandnext', label: 'Now and Next', icon: 'bi-clock-history'},
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

export function isAdminSectionAvailable(
  item: Pick<AdminSection, 'devOnly' | 'devOrLocalOnly'>,
  isDev: boolean,
  isLocal: boolean,
): boolean {
  if (item.devOrLocalOnly) return isDev || isLocal
  if (item.devOnly) return isDev
  return true
}

export function adminSectionPath(key: string = ADMIN_DEFAULT_SECTION): string {
  return `/plan/admin/${key}`
}
