export function useQualityMetrics() {
  function ampelfarbeQ1Q4(ok: number | null | undefined, teams: number | null | undefined): string {
    return ok === teams ? '🟢' : '🔴'
  }

  function ampelfarbeQ2(count1: number | null | undefined, count2: number | null | undefined, rTables: number | null | undefined): string {
    if (rTables === 2) {
      if ((count1 ?? 0) > 0) return '🔴'
      return '🟢'
    }

    if (rTables === 4) {
      if ((count1 ?? 0) > 0) return '🔴'
      if ((count2 ?? 0) > 0) return '🟡'
      return '🟢'
    }

    return '⚪'
  }

  function ampelfarbeQ3(count1: number | null | undefined, count2: number | null | undefined): string {
    if ((count1 ?? 0) > 0) return '🔴'
    if ((count2 ?? 0) > 0) return '🟡'
    return '🟢'
  }

  function formatDistribution(
    count1: number | null | undefined,
    count2: number | null | undefined,
    count3: number | null | undefined,
    scoreAvg: number | null | undefined,
  ): string {
    const count3Val = count3 ?? 0
    const count2Val = count2 ?? 0
    const count1Val = count1 ?? 0
    const distStr = `${count3Val}-${count2Val}-${count1Val}`
    const scoreStr = scoreAvg != null ? `(${scoreAvg.toFixed(0)}%)` : ''
    return `${distStr} ${scoreStr}`.trim()
  }

  function farbeQ5Idle(avg: number | null | undefined, teams: number | null | undefined): string {
    const max = ((teams ?? 1) - 1) / 2
    const ratio = Math.min(Math.max((avg ?? 0) / max, 0), 1)
    const r = Math.round(255 * (1 - ratio))
    const g = Math.round(255 * ratio)
    return `rgb(${r},${g},0)`
  }

  function farbeQ5Stddev(stddev: number | null | undefined): string {
    const ratio = Math.min((stddev ?? 0) / 2.0, 1)
    const r = Math.round(255 * ratio)
    const g = Math.round(255 * (1 - ratio))
    return `rgb(${r},${g},0)`
  }

  function formatDuration(minutes: number | null | undefined): string {
    if (minutes == null) return '–'
    const hours = Math.floor(minutes / 60)
    const mins = minutes % 60
    return `${hours}:${String(mins).padStart(2, '0')}`
  }

  return {
    ampelfarbeQ1Q4,
    ampelfarbeQ2,
    ampelfarbeQ3,
    formatDistribution,
    farbeQ5Idle,
    farbeQ5Stddev,
    formatDuration,
  }
}
