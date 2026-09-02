export type TeamSyncEntry = {
  number: number | null
  local: Record<string, unknown> | null
  draht: Record<string, unknown> | null
  status: 'match' | 'conflict' | 'new' | 'missing'
}

export function normalizeTeamNumber(num: unknown): number | null {
  if (num == null || num === '' || num === 0) return null
  const normalized = Number(num)
  return Number.isNaN(normalized) || normalized === 0 ? null : normalized
}

export function mergeTeams(
  localTeams: Record<string, unknown>[],
  remoteTeams: Record<string, unknown>[],
): TeamSyncEntry[] {
  const result: TeamSyncEntry[] = []
  const processedLocalIds = new Set<number>()
  const processedDrahtIds = new Set<unknown>()

  const localMapByNumber = new Map<number, Record<string, unknown>[]>()
  const drahtMapByNumber = new Map<number, Record<string, unknown>[]>()

  localTeams.forEach((t) => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) {
      if (!localMapByNumber.has(num)) localMapByNumber.set(num, [])
      localMapByNumber.get(num)!.push(t)
    }
  })

  remoteTeams.forEach((t) => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) {
      if (!drahtMapByNumber.has(num)) drahtMapByNumber.set(num, [])
      drahtMapByNumber.get(num)!.push(t)
    }
  })

  const allNumbers = new Set<number>()
  localMapByNumber.forEach((_, n) => allNumbers.add(n))
  drahtMapByNumber.forEach((_, n) => allNumbers.add(n))

  allNumbers.forEach((number) => {
    const locals = localMapByNumber.get(number) || []
    const drahts = drahtMapByNumber.get(number) || []
    const maxLen = Math.max(locals.length, drahts.length)

    for (let i = 0; i < maxLen; i++) {
      const local = locals[i] || null
      const draht = drahts[i] || null

      let status: TeamSyncEntry['status'] = 'match'
      if (local && draht) {
        status = local.name !== draht.name ? 'conflict' : 'match'
      } else if (draht && !local) {
        status = 'new'
      } else if (local && !draht) {
        status = 'missing'
      }

      if (local?.id != null) processedLocalIds.add(Number(local.id))
      if (draht?.id != null) processedDrahtIds.add(draht.id)

      result.push({number, local, draht, status})
    }
  })

  const localWithoutNumber = localTeams.filter((t) => {
    const num = normalizeTeamNumber(t.team_number_hot)
    const id = Number(t.id || 0)
    return num == null && id > 0 && !processedLocalIds.has(id)
  })

  const drahtWithoutNumber = remoteTeams.filter((t) => {
    const num = normalizeTeamNumber(t.number)
    return num == null && !processedDrahtIds.has(t.id)
  })

  drahtWithoutNumber.forEach((draht) => {
    const matchingLocal = localWithoutNumber.find(
      (local) =>
        !processedLocalIds.has(Number(local.id)) && local.name === draht.name,
    )
    if (matchingLocal) {
      processedLocalIds.add(Number(matchingLocal.id))
      result.push({
        number: null,
        local: matchingLocal,
        draht,
        status: matchingLocal.name !== draht.name ? 'conflict' : 'match',
      })
    } else {
      result.push({number: null, local: null, draht, status: 'new'})
    }
  })

  localWithoutNumber.forEach((local) => {
    const id = Number(local.id || 0)
    if (id > 0 && !processedLocalIds.has(id)) {
      result.push({number: null, local, draht: null, status: 'missing'})
    }
  })

  return result
}

export function syncActionCounts(merged: TeamSyncEntry[]) {
  let removed = 0
  let added = 0
  let updated = 0
  for (const row of merged) {
    if (row.status === 'missing') removed++
    else if (row.status === 'new') added++
    else if (row.status === 'conflict') updated++
  }
  return {removed, added, updated}
}

export function syncButtonLabel(merged: TeamSyncEntry[]): string {
  const {removed, added, updated} = syncActionCounts(merged)
  const parts: string[] = []
  if (removed > 0) parts.push(`${removed} entfernen`)
  if (added > 0) parts.push(`${added} hinzufügen`)
  if (updated > 0) parts.push(`${updated} aktualisieren`)
  if (parts.length === 0) return ''
  return `Abgleichen: ${parts.join(', ')}`
}

export function hasSyncWork(merged: TeamSyncEntry[]): boolean {
  return merged.some((t) => t.status !== 'match')
}

/** DRAHT teams without ref/number are hidden from UI and excluded from sync. */
export function visibleDrahtTeams(
  remoteTeams: Record<string, unknown>[],
): Record<string, unknown>[] {
  return remoteTeams.filter(
    (t) => normalizeTeamNumber(t.number ?? t.ref) != null,
  )
}
