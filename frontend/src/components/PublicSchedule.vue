<script setup lang="ts">
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from 'vue'
import {useRoute, useRouter} from 'vue-router'
import axios from 'axios'
import {
  formatBerlinTimeOnly,
  parseBerlinWallTime,
  projectClockOntoBerlinDay,
} from '@/utils/dateTimeFormat'
import {imageUrl, programLogoAlt, programLogoSrc} from '@/utils/images'

const props = defineProps<{
  planId: number | string
  embedded?: boolean
}>()

type RoleOption = {
  value: number | null
  label: string
  parameter: string | null
  noshow: boolean
}

type Role = {
  id: number
  name: string
  first_program: number | null
  color_hex: string
  logo_white: string
  differentiation_parameter: string | null
  options: RoleOption[]
}

type Activity = {
  activity_id: number
  start_time: string
  end_time: string
  activity_name: string
  team: number | null
  team_name: string | null
  table_1_team: number | null
  table_1_team_name: string | null
  table_2_team: number | null
  table_2_team_name: string | null
  lane: number | null
  table_1_name: string | null
  table_2_name: string | null
  meta: {
    first_program_id: number | null
    description: string | null
  }
  room: {
    room_name: string | null
    room_type_name: string | null
  }
}

type Group = {
  activity_group_id: number
  group_meta: {
    name: string | null
    first_program_id: number | null
    description: string | null
    activity_type_code?: string | null
    /** punctual | window | info — from m_activity_type_detail.presence */
    presence?: 'punctual' | 'window' | 'info' | string | null
  }
  start_time: string | null
  end_time: string | null
  activities: Activity[]
}

type TimedGroup = {
  group: Group
  startMs: number
  endMs: number
  durationMin: number
  current: boolean
  past: boolean
  parallel: boolean
  room: string | null
}

type CalBlock = TimedGroup & {
  top: number
  height: number
  /** Spalte 0…n-1 bei parallelen Karten */
  overlapCol: number
  /** Anzahl Spalten im Overlap-Cluster */
  overlapCols: number
  /** Langer Hintergrund-Block (Mittagessen etc.) mit Titel in der freien Mitte */
  isBand: boolean
  /** Titel-Position innerhalb des Bands (0–100%), Mitte der größten Lücke */
  labelTopPct: number
}

/** Echter 1:1-Maßstab: 2px pro Minute = 120px pro Stunde */
const PX_PER_MINUTE = 2
const GUTTER = 52

function groupPresence(group: Group): 'punctual' | 'window' | 'info' {
  const p = group.group_meta?.presence
  if (p === 'window' || p === 'info' || p === 'punctual') return p
  return 'punctual'
}

/** Hintergrundspur aus Stammdaten-presence (window/info), nicht mehr nur Dauer */
function isBackgroundBand(group: Group): boolean {
  const presence = groupPresence(group)
  if (presence === 'window' || presence === 'info') return true
  return false
}

const route = useRoute()
const router = useRouter()

const loadingRoles = ref(true)
const loadingSchedule = ref(false)
const error = ref<string | null>(null)
const eventName = ref('')
const roles = ref<Role[]>([])
const groups = ref<Group[]>([])
const openRoleId = ref<number | null>(null)
const nowMs = ref(Date.now())
const roleFilter = ref('')
const selectedBlockId = ref<number | null>(null)

const selectedRole = ref<number | null>(null)
const selectedTeam = ref<number | null>(null)
const selectedLane = ref<number | null>(null)
const selectedTable = ref<number | null>(null)
const includeExpired = ref(false)

let nowTimer: ReturnType<typeof setInterval> | null = null

const numericPlanId = computed(() => Number(props.planId))
const showDetail = computed(() => selectedRole.value != null)

const selectedRoleMeta = computed(() =>
    roles.value.find((r) => r.id === selectedRole.value) || null
)

const roleAccent = computed(() => {
  const hex = selectedRoleMeta.value?.color_hex
  return hex ? `#${hex}` : '#ea580c'
})

type RoleSection = {
  key: 'challenge' | 'explore' | 'general'
  label: string
  accent: string
  roles: Role[]
}

/** Sections for the flat role picker (Challenge → Explore → Allgemein) */
const roleSections = computed((): RoleSection[] => {
  const buckets: Record<RoleSection['key'], RoleSection> = {
    challenge: {key: 'challenge', label: 'Challenge', accent: '#ED1C24', roles: []},
    explore: {key: 'explore', label: 'Explore', accent: '#00A651', roles: []},
    general: {key: 'general', label: 'Allgemein', accent: '#6b7280', roles: []},
  }

  for (const role of roles.value) {
    if (role.first_program === 3) {
      if (role.color_hex) buckets.challenge.accent = `#${role.color_hex}`
      buckets.challenge.roles.push(role)
    } else if (role.first_program === 2) {
      if (role.color_hex) buckets.explore.accent = `#${role.color_hex}`
      buckets.explore.roles.push(role)
    } else {
      buckets.general.roles.push(role)
    }
  }

  return (['challenge', 'explore', 'general'] as const)
      .map((key) => buckets[key])
      .filter((section) => section.roles.length > 0)
})

function roleNeedsPicker(role: Role): boolean {
  return !(role.options.length === 1 && role.options[0].value == null)
}

function roleAccentHex(role: Role): string {
  return role.color_hex ? `#${role.color_hex}` : '#6b7280'
}

const selectionLabel = computed(() => {
  const role = selectedRoleMeta.value
  if (!role) return ''
  const param = role.differentiation_parameter
  let value: number | null = null
  if (param === 'team') value = selectedTeam.value
  if (param === 'lane') value = selectedLane.value
  if (param === 'table') value = selectedTable.value
  if (value == null) return role.name
  const option = role.options.find((o) => o.value === value)
  return option ? `${role.name}: ${option.label}` : `${role.name} ${value}`
})

const pageTitle = computed(() => {
  if (selectionLabel.value) return `${selectionLabel.value} · ${eventName.value || 'Zeitplan'}`
  return eventName.value ? `Zeitplan · ${eventName.value}` : 'Online-Zeitplan'
})

function timeLabel(value: string | null | undefined) {
  return formatBerlinTimeOnly(value)
}

function clockLabel(ms: number = nowMs.value) {
  return formatBerlinTimeOnly(ms)
}

function programLogo(firstProgram: number | null | undefined) {
  if (firstProgram === 2) return programLogoSrc('E')
  if (firstProgram === 3) return programLogoSrc('C')
  return imageUrl('/flow/first+fll_v.png')
}

function durationLabel(minutes: number): string {
  if (minutes < 60) return `${minutes} Min`
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m ? `${h} Std ${m} Min` : `${h} Std`
}

function berlinPartsOf(ms: number) {
  const s = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Berlin',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(ms))
  const map: Record<string, string> = {}
  for (const p of s) if (p.type !== 'literal') map[p.type] = p.value
  return map
}

function floorToHour(ms: number): number {
  const p = berlinPartsOf(ms)
  return parseBerlinWallTime(`${p.year}-${p.month}-${p.day} ${p.hour}:00:00`) ?? ms
}

function ceilToHour(ms: number): number {
  const p = berlinPartsOf(ms)
  const floored = parseBerlinWallTime(`${p.year}-${p.month}-${p.day} ${p.hour}:00:00`) ?? ms
  if (Number(p.minute) === 0 && Number(p.second) === 0) return floored
  return floored + 60 * 60 * 1000
}

function formatHourMark(ms: number): string {
  return formatBerlinTimeOnly(ms)
}

function normalizeLabel(value: string | null | undefined): string {
  return (value || '').trim().toLowerCase().replace(/\s+/g, ' ')
}

/** „Eröffnung C“ ≈ „Eröffnung Challenge“ — kein zweiter Titel nötig */
function namesRedundant(a: string | null | undefined, b: string | null | undefined): boolean {
  const x = normalizeLabel(a)
  const y = normalizeLabel(b)
  if (!x || !y) return !x && !y
  if (x === y) return true
  if (x.startsWith(y) || y.startsWith(x)) return true
  let i = 0
  while (i < x.length && i < y.length && x[i] === y[i]) i++
  return i >= 8
}

function activityHasLinks(activity: Activity): boolean {
  return !!(activity.team || activity.table_1_team || activity.table_2_team || activity.lane)
}

function activityAddsDetail(group: Group, activity: Activity): boolean {
  if (activityHasLinks(activity)) return true

  if (!namesRedundant(activity.activity_name, group.group_meta?.name)) return true

  const aStart = parseBerlinWallTime(activity.start_time)
  const aEnd = parseBerlinWallTime(activity.end_time)
  const gStart = parseBerlinWallTime(group.start_time)
  const gEnd = parseBerlinWallTime(group.end_time)
  if (aStart != null && gStart != null && Math.abs(aStart - gStart) > 60_000) return true
  if (aEnd != null && gEnd != null && Math.abs(aEnd - gEnd) > 60_000) return true

  const aRoom = activity.room?.room_name || activity.room?.room_type_name
  const gRoom = primaryRoomRaw(group)
  if (aRoom && gRoom && !namesRedundant(aRoom, gRoom) && !namesRedundant(aRoom, group.group_meta?.name)) {
    return true
  }
  return false
}

function primaryRoomRaw(group: Group): string | null {
  for (const a of group.activities) {
    if (a.room?.room_name) return a.room.room_name
    if (a.room?.room_type_name) return a.room.room_type_name
  }
  return null
}

/** Raum nur wenn er nicht schon der Gruppentitel ist */
function displayRoom(group: Group): string | null {
  const room = primaryRoomRaw(group)
  if (!room) return null
  if (namesRedundant(room, group.group_meta?.name)) return null
  return room
}

/**
 * Activities nur zeigen, wenn sie Mehrwert haben (Teams, andere Zeiten, mehrere sinnvolle Einträge).
 * Sonst reicht die Gruppe (+ Beschreibung).
 */
function detailActivities(group: Group): Activity[] {
  const acts = group.activities || []
  if (acts.length === 0) return []

  const useful = acts.filter((a) => activityAddsDetail(group, a))
  if (useful.length > 0) return useful

  // Mehrere Activities mit unterschiedlichen Zeiten → zeigen
  if (acts.length > 1) {
    const keys = new Set(acts.map((a) => `${a.start_time}|${a.end_time}`))
    if (keys.size > 1) return acts
  }
  return []
}

function hasExpandableDetail(group: Group): boolean {
  return detailActivities(group).length > 0
}

/** Alle geparsten Gruppen (ohne Filter), sortiert */
const parsedGroups = computed(() => {
  return groups.value
      .map((group) => {
        const startMs = parseBerlinWallTime(group.start_time)
        const endMs = parseBerlinWallTime(group.end_time)
        if (startMs == null || endMs == null || endMs <= startMs) return null
        const durationMin = Math.max(1, Math.round((endMs - startMs) / 60000))
        return {
          group,
          startMs,
          endMs,
          durationMin,
          room: displayRoom(group),
        }
      })
      .filter((g): g is NonNullable<typeof g> => g != null)
      .sort((a, b) => a.startMs - b.startMs || a.endMs - b.endMs)
})

/**
 * „Jetzt“ relativ zum Veranstaltungstag: aktuelle Uhrzeit auf den Plantag gelegt.
 * So wandert der rote Balken mit der Uhr, auch wenn der Event-Tag ≠ Kalender-heute.
 */
const scheduleNowMs = computed(() => {
  if (!parsedGroups.value.length) return nowMs.value
  return projectClockOntoBerlinDay(nowMs.value, parsedGroups.value[0].startMs)
})

const timedGroups = computed((): TimedGroup[] => {
  const now = scheduleNowMs.value
  let sorted = parsedGroups.value.map((item) => ({
    ...item,
    current: item.startMs <= now && now <= item.endMs,
    past: item.endMs < now,
    parallel: false,
  }))

  if (!includeExpired.value) {
    sorted = sorted.filter((item) => item.endMs >= now)
  }

  let openEnd = -Infinity
  return sorted.map((item) => {
    const parallel = item.startMs < openEnd
    openEnd = Math.max(openEnd, item.endMs)
    return {...item, parallel}
  })
})

const dayRange = computed(() => {
  if (!timedGroups.value.length) return null
  const first = timedGroups.value[0].startMs
  const last = timedGroups.value.reduce((max, g) => Math.max(max, g.endMs), first)
  let start = floorToHour(first)
  let end = ceilToHour(last)
  const now = scheduleNowMs.value
  // Raster soll „Jetzt“ enthalten, wenn es im Tag liegt
  if (now >= first - 60 * 60 * 1000 && now <= last + 60 * 60 * 1000) {
    if (now < start) start = floorToHour(now)
    if (now > end) end = ceilToHour(now)
  }
  if (end <= start) end = start + 60 * 60 * 1000
  return {start, end}
})

const timelineHeight = computed(() => {
  if (!dayRange.value) return 0
  return ((dayRange.value.end - dayRange.value.start) / 60000) * PX_PER_MINUTE
})

const hourMarks = computed(() => {
  if (!dayRange.value) return [] as number[]
  const marks: number[] = []
  for (let t = dayRange.value.start; t <= dayRange.value.end; t += 60 * 60 * 1000) {
    marks.push(t)
  }
  return marks
})

function largestFreeGapCenterPct(
    bandStart: number,
    bandEnd: number,
    blockers: {startMs: number; endMs: number}[]
): number {
  const span = bandEnd - bandStart
  if (span <= 0) return 50

  const clipped = blockers
      .map((b) => ({
        start: Math.max(bandStart, b.startMs),
        end: Math.min(bandEnd, b.endMs),
      }))
      .filter((b) => b.end > b.start)
      .sort((a, b) => a.start - b.start)

  const merged: {start: number; end: number}[] = []
  for (const b of clipped) {
    const last = merged[merged.length - 1]
    if (!last || b.start > last.end) merged.push({...b})
    else last.end = Math.max(last.end, b.end)
  }

  type Gap = {start: number; end: number}
  const gaps: Gap[] = []
  let cursor = bandStart
  for (const m of merged) {
    if (m.start > cursor) gaps.push({start: cursor, end: m.start})
    cursor = Math.max(cursor, m.end)
  }
  if (cursor < bandEnd) gaps.push({start: cursor, end: bandEnd})

  if (!gaps.length) return 50
  const best = gaps.reduce((a, b) => (b.end - b.start > a.end - a.start ? b : a))
  const center = (best.start + best.end) / 2
  return ((center - bandStart) / span) * 100
}

/**
 * Kalender-Spalten für parallele (nicht-Band) Events:
 * Cluster überlappender Karten → greedy Spalten → volle Cluster-Breite teilen.
 */
function assignOverlapColumns(
    cards: {id: number; startMs: number; endMs: number}[]
): Map<number, {col: number; cols: number}> {
  const sorted = [...cards].sort((a, b) => a.startMs - b.startMs || a.endMs - b.endMs)
  const result = new Map<number, {col: number; cols: number}>()

  let i = 0
  while (i < sorted.length) {
    const cluster = [sorted[i]]
    let clusterEnd = sorted[i].endMs
    let j = i + 1
    while (j < sorted.length && sorted[j].startMs < clusterEnd) {
      cluster.push(sorted[j])
      clusterEnd = Math.max(clusterEnd, sorted[j].endMs)
      j++
    }

    const colEndTimes: number[] = []
    for (const ev of cluster) {
      let col = 0
      while (col < colEndTimes.length && colEndTimes[col] > ev.startMs) col++
      if (col === colEndTimes.length) colEndTimes.push(ev.endMs)
      else colEndTimes[col] = ev.endMs
      result.set(ev.id, {col, cols: 0})
    }
    const cols = Math.max(1, colEndTimes.length)
    for (const ev of cluster) {
      const layout = result.get(ev.id)
      if (layout) layout.cols = cols
    }
    i = j
  }
  return result
}

const calendarBlocks = computed((): CalBlock[] => {
  if (!dayRange.value) return []
  const base = dayRange.value.start
  const items = timedGroups.value

  // Stammdaten-presence: window/info → Hintergrundspur; punctual → Karte
  const bandIds = new Set(
      items.filter((i) => isBackgroundBand(i.group)).map((i) => i.group.activity_group_id)
  )

  const cardLayouts = assignOverlapColumns(
      items
          .filter((i) => !bandIds.has(i.group.activity_group_id))
          .map((i) => ({
            id: i.group.activity_group_id,
            startMs: i.startMs,
            endMs: i.endMs,
          }))
  )

  return items.map((item) => {
    const isBand = bandIds.has(item.group.activity_group_id)
    const layout = cardLayouts.get(item.group.activity_group_id)

    const blockers = isBand
        ? items.filter(
            (other) =>
                other.group.activity_group_id !== item.group.activity_group_id &&
                !bandIds.has(other.group.activity_group_id) &&
                other.startMs < item.endMs &&
                other.endMs > item.startMs
        )
        : []

    return {
      ...item,
      top: ((item.startMs - base) / 60000) * PX_PER_MINUTE,
      height: Math.max(((item.endMs - item.startMs) / 60000) * PX_PER_MINUTE, 2),
      overlapCol: layout?.col ?? 0,
      overlapCols: layout?.cols ?? 1,
      isBand,
      labelTopPct: isBand
          ? largestFreeGapCenterPct(item.startMs, item.endMs, blockers)
          : 50,
    }
  })
})

const nowTop = computed(() => {
  if (!dayRange.value) return null
  const now = scheduleNowMs.value
  if (now < dayRange.value.start || now > dayRange.value.end) return null
  return ((now - dayRange.value.start) / 60000) * PX_PER_MINUTE
})

const dayLabel = computed(() => {
  if (!dayRange.value) return ''
  return `${formatHourMark(dayRange.value.start)} – ${formatHourMark(dayRange.value.end)}`
})

const hasNowInView = computed(() => nowTop.value != null)
const hasCurrentEvent = computed(() => timedGroups.value.some((g) => g.current))

const selectedItem = computed(() =>
    timedGroups.value.find((g) => g.group.activity_group_id === selectedBlockId.value) || null
)

function blockStyle(block: CalBlock) {
  if (block.isBand) {
    return {
      top: `${block.top}px`,
      height: `${block.height}px`,
      left: `${GUTTER}px`,
      right: '0.35rem',
      zIndex: 2,
      '--accent': roleAccent.value,
      '--label-top': `${block.labelTopPct}%`,
    }
  }

  // Parallele Karten teilen die Spur nebeneinander (Kalender-Spalten)
  const cols = Math.max(1, block.overlapCols)
  const col = Math.min(block.overlapCol, cols - 1)
  const gap = 2
  const track = `100% - ${GUTTER}px - 0.35rem`
  return {
    top: `${block.top}px`,
    height: `${block.height}px`,
    left: `calc(${GUTTER}px + (${track}) * ${col} / ${cols} + ${col > 0 ? gap / 2 : 0}px)`,
    width: `calc((${track}) / ${cols} - ${gap}px)`,
    right: 'auto',
    zIndex: 5 + col + (block.current ? 2 : 0),
    '--accent': roleAccent.value,
  }
}

function hourStyle(ms: number) {
  if (!dayRange.value) return {}
  const top = ((ms - dayRange.value.start) / 60000) * PX_PER_MINUTE
  return {top: `${top}px`}
}

const planScrollEl = ref<HTMLElement | null>(null)
const detailBodyEl = ref<HTMLElement | null>(null)

/** Swipe-down to dismiss (touch + mouse / DevTools mobile preview) */
const sheetDragY = ref(0)
const sheetDragging = ref(false)
let sheetDragStartY = 0
let sheetPointerId: number | null = null
let sheetDragFromBody = false

const DISMISS_DISTANCE = 110

function selectBlock(block: CalBlock | TimedGroup) {
  const id = block.group.activity_group_id
  // Detail öffnet als Sheet — kein Page-Scroll
  selectedBlockId.value = selectedBlockId.value === id ? null : id
  sheetDragY.value = 0
  sheetDragging.value = false
}

function closeDetail() {
  sheetDragY.value = 0
  sheetDragging.value = false
  sheetPointerId = null
  selectedBlockId.value = null
}

function onSheetPointerDown(e: PointerEvent, fromBody = false) {
  if (e.pointerType === 'mouse' && e.button !== 0) return
  if (fromBody) {
    const body = detailBodyEl.value
    if (body && body.scrollTop > 2) return
  }
  sheetDragFromBody = fromBody
  sheetDragStartY = e.clientY
  sheetDragging.value = true
  sheetDragY.value = 0
  sheetPointerId = e.pointerId
  ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)
}

function onSheetPointerMove(e: PointerEvent) {
  if (!sheetDragging.value || sheetPointerId !== e.pointerId) return
  const dy = e.clientY - sheetDragStartY
  if (sheetDragFromBody && dy < 0) {
    sheetDragY.value = 0
    return
  }
  sheetDragY.value = Math.max(0, dy)
}

function onSheetPointerUp(e: PointerEvent) {
  if (!sheetDragging.value || sheetPointerId !== e.pointerId) return
  const dy = sheetDragY.value
  sheetDragging.value = false
  sheetPointerId = null
  sheetDragFromBody = false
  try {
    ;(e.currentTarget as HTMLElement).releasePointerCapture?.(e.pointerId)
  } catch {
    /* already released */
  }
  if (dy >= DISMISS_DISTANCE) {
    closeDetail()
    return
  }
  sheetDragY.value = 0
}

const sheetPanelStyle = computed(() => ({
  '--accent': roleAccent.value,
  transform: sheetDragY.value ? `translateY(${sheetDragY.value}px)` : undefined,
  transition: sheetDragging.value ? 'none' : 'transform 0.2s ease-out',
}))

const sheetBackdropStyle = computed(() => {
  const fade = Math.max(0.12, 0.45 * (1 - sheetDragY.value / 280))
  return {background: `rgb(15 23 42 / ${fade})`}
})

async function scrollToNow() {
  await nextTick()
  requestAnimationFrame(() => {
    const container = planScrollEl.value
    const el =
        (container?.querySelector('[data-current-block="true"]') as HTMLElement | null) ||
        (container?.querySelector('[data-now-line="true"]') as HTMLElement | null) ||
        (document.querySelector('[data-current-block="true"]') as HTMLElement | null) ||
        (document.querySelector('[data-now-line="true"]') as HTMLElement | null)
    if (!el) return
    if (container) {
      const cRect = container.getBoundingClientRect()
      const eRect = el.getBoundingClientRect()
      const delta = eRect.top - cRect.top - container.clientHeight / 2 + eRect.height / 2
      container.scrollBy({top: delta, behavior: 'smooth'})
      return
    }
    el.scrollIntoView({behavior: 'smooth', block: 'center'})
  })
}

function filteredOptions(role: Role): RoleOption[] {
  const q = roleFilter.value.trim().toLowerCase()
  if (!q || openRoleId.value !== role.id) return role.options
  return role.options.filter((o) => o.label.toLowerCase().includes(q))
}

function showRoleSearch(role: Role): boolean {
  return openRoleId.value === role.id && role.options.length > 8
}

function syncFromQuery() {
  const q = route.query
  selectedRole.value = q.role != null && q.role !== '' ? Number(q.role) : null
  selectedTeam.value = q.team != null && q.team !== '' ? Number(q.team) : null
  selectedLane.value = q.lane != null && q.lane !== '' ? Number(q.lane) : null
  selectedTable.value = q.table != null && q.table !== '' ? Number(q.table) : null
  includeExpired.value = q.expired === 'yes'
  if (selectedRole.value) openRoleId.value = selectedRole.value
}

async function pushQuery(next: Record<string, string | null>) {
  const query: Record<string, string> = {}
  const merged = {
    role: selectedRole.value != null ? String(selectedRole.value) : null,
    team: selectedTeam.value != null ? String(selectedTeam.value) : null,
    lane: selectedLane.value != null ? String(selectedLane.value) : null,
    table: selectedTable.value != null ? String(selectedTable.value) : null,
    expired: includeExpired.value ? 'yes' : 'no',
    ...next,
  }
  for (const [key, value] of Object.entries(merged)) {
    if (value != null && value !== '') query[key] = value
  }
  await router.replace({query})
}

async function loadRoles() {
  if (!numericPlanId.value) return
  loadingRoles.value = true
  error.value = null
  try {
    const {data} = await axios.get(`/plans/${numericPlanId.value}/visitor/roles`)
    roles.value = data.roles || []
    eventName.value = data.event_name || ''
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Rollen konnten nicht geladen werden.'
  } finally {
    loadingRoles.value = false
  }
}

async function loadSchedule() {
  if (!numericPlanId.value || selectedRole.value == null) {
    groups.value = []
    selectedBlockId.value = null
    return
  }
  loadingSchedule.value = true
  error.value = null
  nowMs.value = Date.now()
  selectedBlockId.value = null
  try {
    const params: Record<string, string | number> = {
      role: selectedRole.value,
      expired: includeExpired.value ? 'yes' : 'no',
    }
    if (selectedTeam.value != null) params.team = selectedTeam.value
    if (selectedLane.value != null) params.lane = selectedLane.value
    if (selectedTable.value != null) params.table = selectedTable.value

    const {data} = await axios.get(`/plans/${numericPlanId.value}/visitor/schedule`, {params})
    groups.value = data.groups || []
    // Same "now" the API used for expired filtering (Europe/Berlin)
    const apiNow = parseBerlinWallTime(data.now)
    if (apiNow != null) nowMs.value = apiNow
    else nowMs.value = Date.now()
    await nextTick()

    const current = timedGroups.value.find((b) => b.current)
    if (current) selectedBlockId.value = current.group.activity_group_id
    await scrollToNow()
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Zeitplan konnte nicht geladen werden.'
    groups.value = []
  } finally {
    loadingSchedule.value = false
  }
}

async function selectOption(role: Role, option: RoleOption) {
  selectedRole.value = role.id
  selectedTeam.value = null
  selectedLane.value = null
  selectedTable.value = null
  roleFilter.value = ''

  const parameter = option.parameter || role.differentiation_parameter
  if (parameter === 'team' && option.value != null) selectedTeam.value = Number(option.value)
  if (parameter === 'lane' && option.value != null) selectedLane.value = Number(option.value)
  if (parameter === 'table' && option.value != null) selectedTable.value = Number(option.value)

  await pushQuery({
    role: String(role.id),
    team: selectedTeam.value != null ? String(selectedTeam.value) : null,
    lane: selectedLane.value != null ? String(selectedLane.value) : null,
    table: selectedTable.value != null ? String(selectedTable.value) : null,
  })
}

async function onRoleClick(role: Role) {
  if (role.options.length === 1 && role.options[0].value == null) {
    await selectOption(role, role.options[0])
    return
  }
  openRoleId.value = openRoleId.value === role.id ? null : role.id
  roleFilter.value = ''
}

async function backToRoles() {
  selectedRole.value = null
  selectedTeam.value = null
  selectedLane.value = null
  selectedTable.value = null
  groups.value = []
  selectedBlockId.value = null
  roleFilter.value = ''
  await pushQuery({
    role: null,
    team: null,
    lane: null,
    table: null,
  })
}

async function toggleExpired() {
  includeExpired.value = !includeExpired.value
  await pushQuery({expired: includeExpired.value ? 'yes' : 'no'})
}

async function openTeamPlan(teamNumber: number | null | undefined, firstProgram: number | null | undefined) {
  if (!teamNumber) return
  const roleId = firstProgram === 2 ? 8 : 3
  selectedRole.value = roleId
  selectedTeam.value = teamNumber
  selectedLane.value = null
  selectedTable.value = null
  await pushQuery({
    role: String(roleId),
    team: String(teamNumber),
    lane: null,
    table: null,
  })
}

watch(pageTitle, (title) => {
  if (typeof document !== 'undefined') document.title = title
}, {immediate: true})

onMounted(async () => {
  syncFromQuery()
  if (route.query.expired == null && route.query.role == null) {
    await pushQuery({expired: 'no'})
  }
  await loadRoles()
  if (selectedRole.value != null) await loadSchedule()
  nowTimer = setInterval(() => {
    nowMs.value = Date.now()
  }, 30000)
})

onUnmounted(() => {
  if (nowTimer) clearInterval(nowTimer)
  if (typeof document !== 'undefined') {
    document.documentElement.style.overflow = ''
    document.body.style.overflow = ''
  }
})

watch(
    () => [
      route.query.role,
      route.query.team,
      route.query.lane,
      route.query.table,
      route.query.expired,
    ],
    async (next, prev) => {
      if (prev && next.every((v, i) => v === prev[i])) return
      syncFromQuery()
      if (selectedRole.value != null) await loadSchedule()
      else groups.value = []
    }
)

watch(
    () => props.planId,
    async () => {
      await loadRoles()
      if (selectedRole.value != null) await loadSchedule()
    }
)

// Prevent document scroll while the plan chrome is fixed
watch(
    () => showDetail.value,
    (locked) => {
      if (typeof document === 'undefined') return
      document.documentElement.style.overflow = locked ? 'hidden' : ''
      document.body.style.overflow = locked ? 'hidden' : ''
    },
    {immediate: true}
)
</script>

<template>
  <div
      class="public-schedule"
      :class="{
        'public-schedule--embedded': embedded,
        'public-schedule--plan-view': showDetail,
        'public-schedule--sheet-open': showDetail && !!selectedItem,
      }"
      :style="{'--accent': roleAccent}"
  >
    <div class="public-schedule__inner">
      <div v-if="loadingRoles" class="public-schedule__card public-schedule__card--center" role="status">
        Rollen werden geladen…
      </div>

      <div v-else-if="error" class="public-schedule__card public-schedule__card--error" role="alert">
        {{ error }}
      </div>

      <template v-else>
        <div v-if="!showDetail" class="public-schedule__picker">
          <header class="public-schedule__picker-header">
            <p class="public-schedule__eyebrow">Online-Zeitplan</p>
            <h1 class="public-schedule__title">
              {{ eventName || 'Veranstaltungsplan' }}
            </h1>
            <p class="public-schedule__lead">
              Wer bist du? Tippe auf deine Rolle.
            </p>
          </header>

          <div class="public-schedule__role-list">
            <section
                v-for="section in roleSections"
                :key="section.key"
                class="public-schedule__role-section"
            >
              <h2 class="public-schedule__role-section-label">
                <span
                    class="public-schedule__role-section-dot"
                    :style="{background: section.accent}"
                    aria-hidden="true"
                />
                {{ section.label }}
              </h2>

              <div
                  v-for="role in section.roles"
                  :key="role.id"
                  class="public-schedule__role-item"
                  :class="{'public-schedule__role-item--open': openRoleId === role.id}"
              >
                <button
                    type="button"
                    class="public-schedule__role-btn"
                    :aria-expanded="roleNeedsPicker(role) ? openRoleId === role.id : undefined"
                    @click="onRoleClick(role)"
                >
                  <span
                      class="public-schedule__role-accent"
                      :style="{background: roleAccentHex(role)}"
                      aria-hidden="true"
                  />
                  <img
                      :src="programLogo(role.first_program)"
                      :alt="programLogoAlt(role.first_program || '')"
                      class="public-schedule__role-logo"
                  />
                  <span class="public-schedule__role-name">{{ role.name }}</span>
                  <i
                      class="bi public-schedule__role-chevron"
                      :class="roleNeedsPicker(role)
                        ? (openRoleId === role.id ? 'bi-chevron-up' : 'bi-chevron-down')
                        : 'bi-chevron-right'"
                      aria-hidden="true"
                  />
                </button>

                <div v-if="openRoleId === role.id && roleNeedsPicker(role)" class="public-schedule__options">
                  <div v-if="showRoleSearch(role)" class="public-schedule__search-wrap">
                    <input
                        v-model="roleFilter"
                        type="search"
                        enterkeyhint="search"
                        autocomplete="off"
                        class="public-schedule__search"
                        placeholder="Suchen…"
                        :aria-label="`${role.name} suchen`"
                    />
                  </div>
                  <button
                      v-for="(option, idx) in filteredOptions(role)"
                      :key="`${role.id}-${idx}-${option.value}`"
                      type="button"
                      class="public-schedule__option-btn"
                      @click="selectOption(role, option)"
                  >
                    <span :class="{'line-through opacity-50': option.noshow}">
                      {{ option.label }}
                    </span>
                    <i class="bi bi-chevron-right opacity-40" aria-hidden="true"/>
                  </button>
                  <p v-if="filteredOptions(role).length === 0" class="public-schedule__empty-filter">
                    Keine Treffer.
                  </p>
                </div>
              </div>
            </section>
          </div>
        </div>

        <!-- Plan view: fixed chrome + scrollable calendar only -->
        <div v-else class="public-schedule__plan">
          <header class="public-schedule__chrome">
            <div class="public-schedule__toolbar">
              <button type="button" class="public-schedule__back" @click="backToRoles">
                <i class="bi bi-arrow-left" aria-hidden="true"/>
                <span>Rollen</span>
              </button>

              <div class="public-schedule__toolbar-center">
                <p v-if="eventName" class="public-schedule__toolbar-event">{{ eventName }}</p>
                <h2 class="public-schedule__selection">{{ selectionLabel }}</h2>
              </div>

              <label class="public-schedule__toggle">
                <input
                    type="checkbox"
                    class="public-schedule__toggle-input"
                    :checked="!includeExpired"
                    @change="toggleExpired"
                />
                <span>Nur noch Kommende</span>
              </label>
            </div>

            <div v-if="!loadingSchedule && timedGroups.length" class="public-schedule__daymeta">
              <div>
                <p class="public-schedule__daymeta-label">Tagesverlauf</p>
                <p class="public-schedule__daymeta-range">{{ dayLabel }}</p>
              </div>
              <button
                  v-if="hasNowInView || hasCurrentEvent"
                  type="button"
                  class="public-schedule__jump-now"
                  @click="scrollToNow"
              >
                <i class="bi bi-record-circle" aria-hidden="true"/>
                Jetzt {{ clockLabel() }}
              </button>
            </div>
          </header>

          <div ref="planScrollEl" class="public-schedule__plan-scroll">
            <div v-if="loadingSchedule" class="public-schedule__card public-schedule__card--center" role="status">
              Zeitplan wird geladen…
            </div>

            <div
                v-else-if="!timedGroups.length"
                class="public-schedule__card public-schedule__card--center"
            >
              Keine Einträge für diese Auswahl.
              <button
                  v-if="!includeExpired"
                  type="button"
                  class="public-schedule__text-action"
                  @click="toggleExpired"
              >
                Vergangene auch anzeigen
              </button>
            </div>

            <div
                v-else
                class="public-schedule__calendar"
                aria-label="Tageskalender im Zeitmaßstab"
            >
              <div
                  class="public-schedule__timeline"
                  :style="{
                    height: `${timelineHeight}px`,
                    '--gutter': `${GUTTER}px`,
                    '--ppm': `${PX_PER_MINUTE}px`,
                  }"
              >
                <div
                    v-for="hour in hourMarks"
                    :key="hour"
                    class="public-schedule__hour"
                    :style="hourStyle(hour)"
                >
                  <span class="public-schedule__hour-label">{{ formatHourMark(hour) }}</span>
                  <span class="public-schedule__hour-line" aria-hidden="true"/>
                </div>

                <div
                    v-if="nowTop != null"
                    class="public-schedule__now"
                    :style="{top: `${nowTop}px`}"
                    data-now-line="true"
                >
                  <span class="public-schedule__now-dot"/>
                  <span class="public-schedule__now-line"/>
                  <span class="public-schedule__now-label">{{ clockLabel() }}</span>
                </div>

                <button
                    v-for="block in calendarBlocks"
                    :key="block.group.activity_group_id"
                    type="button"
                    class="public-schedule__block"
                    :class="{
                      'public-schedule__block--band': block.isBand,
                      'public-schedule__block--current': block.current,
                      'public-schedule__block--past': block.past,
                      'public-schedule__block--selected': selectedBlockId === block.group.activity_group_id,
                      'public-schedule__block--compact': !block.isBand && block.height < 44,
                      'public-schedule__block--narrow': !block.isBand && block.overlapCols >= 3,
                    }"
                    :style="blockStyle(block)"
                    :data-current-block="block.current ? 'true' : undefined"
                    :data-event-id="block.group.activity_group_id"
                    @click="selectBlock(block)"
                >
                  <template v-if="block.isBand">
                    <div class="public-schedule__band-label">
                      <span class="public-schedule__band-title">
                        {{ block.group.group_meta?.name || 'Programmpunkt' }}
                      </span>
                      <span class="public-schedule__band-meta">
                        {{ timeLabel(block.group.start_time) }}–{{ timeLabel(block.group.end_time) }}
                        · {{ durationLabel(block.durationMin) }}
                      </span>
                    </div>
                  </template>
                  <template v-else>
                    <div class="public-schedule__block-accent" aria-hidden="true"/>
                    <div class="public-schedule__block-body">
                      <div class="public-schedule__block-meta">
                        <span>{{ timeLabel(block.group.start_time) }}–{{ timeLabel(block.group.end_time) }}</span>
                        <span v-if="block.height >= 36" class="public-schedule__block-dur">
                          {{ durationLabel(block.durationMin) }}
                        </span>
                      </div>
                      <div class="public-schedule__block-title">
                        {{ block.group.group_meta?.name || 'Programmpunkt' }}
                      </div>
                      <div v-if="block.height >= 56 && block.room" class="public-schedule__block-room">
                        {{ block.room }}
                      </div>
                    </div>
                  </template>
                </button>
              </div>
            </div>
          </div>

        </div>
      </template>
    </div>

    <!-- Sheet teleported to body: always viewport-wide, ignores parent frames -->
    <Teleport to="body">
      <div
          v-if="showDetail && selectedItem"
          class="public-schedule__sheet"
          role="dialog"
          aria-modal="true"
          :aria-label="selectedItem.group.group_meta?.name || 'Programmpunkt'"
      >
        <button
            type="button"
            class="public-schedule__sheet-backdrop"
            aria-label="Details schließen"
            :style="sheetBackdropStyle"
            @click="closeDetail"
        />
        <section
            class="public-schedule__detail-panel"
            :class="{
              'public-schedule__detail-panel--current': selectedItem.current,
              'public-schedule__detail-panel--dragging': sheetDragging,
            }"
            :style="sheetPanelStyle"
        >
          <div
              class="public-schedule__detail-head"
              @pointerdown="onSheetPointerDown($event, false)"
              @pointermove="onSheetPointerMove"
              @pointerup="onSheetPointerUp"
              @pointercancel="onSheetPointerUp"
          >
            <div class="public-schedule__sheet-handle" aria-hidden="true"/>
            <div class="public-schedule__detail-head-row">
              <div>
                <p v-if="selectedItem.current" class="public-schedule__detail-kicker">Läuft gerade</p>
                <h3 class="public-schedule__detail-title">
                  {{ selectedItem.group.group_meta?.name || 'Programmpunkt' }}
                </h3>
                <p class="public-schedule__detail-time">
                  {{ timeLabel(selectedItem.group.start_time) }}
                  – {{ timeLabel(selectedItem.group.end_time) }}
                  <span class="opacity-70"> · {{ durationLabel(selectedItem.durationMin) }}</span>
                </p>
              </div>
              <button
                  type="button"
                  class="public-schedule__detail-close"
                  aria-label="Schließen"
                  @pointerdown.stop
                  @click="closeDetail"
              >
                <i class="bi bi-x-lg" aria-hidden="true"/>
              </button>
            </div>
          </div>

          <div
              ref="detailBodyEl"
              class="public-schedule__detail-body"
              @pointerdown="onSheetPointerDown($event, true)"
              @pointermove="onSheetPointerMove"
              @pointerup="onSheetPointerUp"
              @pointercancel="onSheetPointerUp"
          >
            <p
                v-if="selectedItem.group.group_meta?.description"
                class="public-schedule__detail-desc"
            >
              {{ selectedItem.group.group_meta.description }}
            </p>

            <p v-if="selectedItem.room" class="public-schedule__detail-room">
              <i class="bi bi-geo" aria-hidden="true"/>
              {{ selectedItem.room }}
            </p>

            <ul
                v-if="hasExpandableDetail(selectedItem.group)"
                class="public-schedule__activities"
            >
              <li
                  v-for="activity in detailActivities(selectedItem.group)"
                  :key="activity.activity_id"
                  class="public-schedule__activity"
              >
                <div class="public-schedule__activity-top">
                  <div class="public-schedule__activity-name">
                    {{ activity.activity_name || 'Aktivität' }}
                  </div>
                  <div class="public-schedule__activity-time">
                    {{ timeLabel(activity.start_time) }}
                    <template v-if="activity.end_time">–{{ timeLabel(activity.end_time) }}</template>
                  </div>
                </div>
                <div
                    v-if="activity.team_name || activity.table_1_team_name || activity.table_2_team_name || activity.lane"
                    class="public-schedule__chips"
                >
                  <button
                      v-if="activity.team_name && activity.team"
                      type="button"
                      class="public-schedule__chip public-schedule__chip--action"
                      @click="openTeamPlan(activity.team, activity.meta?.first_program_id)"
                  >
                    {{ activity.team_name }}
                  </button>
                  <button
                      v-if="activity.table_1_team_name && activity.table_1_team"
                      type="button"
                      class="public-schedule__chip public-schedule__chip--action"
                      @click="openTeamPlan(activity.table_1_team, activity.meta?.first_program_id)"
                  >
                    <span v-if="activity.table_1_name" class="opacity-70">{{ activity.table_1_name }} · </span>
                    {{ activity.table_1_team_name }}
                  </button>
                  <button
                      v-if="activity.table_2_team_name && activity.table_2_team"
                      type="button"
                      class="public-schedule__chip public-schedule__chip--action"
                      @click="openTeamPlan(activity.table_2_team, activity.meta?.first_program_id)"
                  >
                    <span v-if="activity.table_2_name" class="opacity-70">{{ activity.table_2_name }} · </span>
                    {{ activity.table_2_team_name }}
                  </button>
                  <span v-if="activity.lane" class="public-schedule__chip">Bahn {{ activity.lane }}</span>
                </div>
              </li>
            </ul>
          </div>
        </section>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.public-schedule {
  --accent: #ea580c;
  min-height: 100dvh;
  width: 100%;
  background: #f8fafc;
  color: #111827;
  overflow-x: hidden;
  -webkit-tap-highlight-color: transparent;
  padding:
      env(safe-area-inset-top, 0px)
      env(safe-area-inset-right, 0px)
      env(safe-area-inset-bottom, 0px)
      env(safe-area-inset-left, 0px);
}

/* Plan view: fill the available viewport, no side frames */
.public-schedule--plan-view,
.public-schedule--embedded.public-schedule--plan-view {
  height: 100%;
  max-height: 100dvh;
  min-height: 100%;
  width: 100%;
  max-width: none;
  margin: 0;
  padding: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: #fff;
}

.public-schedule--plan-view .public-schedule__inner {
  flex: 1;
  min-height: 0;
  max-width: none;
  width: 100%;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
}

.public-schedule--embedded {
  min-height: 100%;
  height: 100%;
  padding: 0;
}

.public-schedule__inner {
  width: 100%;
  max-width: none;
  margin: 0;
  padding: 0;
}

.public-schedule__picker {
  min-height: 100dvh;
  background: #fff;
  display: flex;
  flex-direction: column;
}

.public-schedule--embedded .public-schedule__picker {
  min-height: 100%;
}

.public-schedule__picker-header {
  padding: 1rem 1rem 0.85rem;
  border-bottom: 1px solid #e5e7eb;
  background: #fff;
}

.public-schedule__role-list {
  flex: 1;
  padding-bottom: max(1.5rem, env(safe-area-inset-bottom, 0px));
}

.public-schedule__role-section {
  padding-top: 0.85rem;
}

.public-schedule__role-section + .public-schedule__role-section {
  border-top: 1px solid #f3f4f6;
  margin-top: 0.35rem;
}

.public-schedule__role-section-label {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  padding: 0.35rem 1rem 0.45rem;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #6b7280;
}

.public-schedule__role-section-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  flex-shrink: 0;
}

.public-schedule__role-item {
  border-bottom: 1px solid #f3f4f6;
}

.public-schedule__role-item--open {
  background: #fafafa;
}

.public-schedule__plan {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  position: relative;
}

.public-schedule__chrome {
  flex-shrink: 0;
  z-index: 30;
  background: #fff;
  border-bottom: 1px solid #e5e7eb;
}

.public-schedule__plan-scroll {
  flex: 1;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  background: #fff;
}

.public-schedule__plan-scroll > .public-schedule__card {
  margin: 1rem 0.75rem;
  border-radius: 0.75rem;
  box-shadow: none;
  border: 1px solid #e5e7eb;
}

.public-schedule__eyebrow {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #c2410c;
}

.public-schedule__title {
  margin-top: 0.15rem;
  font-size: clamp(1.35rem, 5.5vw, 1.75rem);
  font-weight: 800;
  line-height: 1.2;
  letter-spacing: -0.02em;
}

.public-schedule__lead {
  margin-top: 0.35rem;
  font-size: 0.9375rem;
  line-height: 1.4;
  color: #4b5563;
}

.public-schedule__stack {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.public-schedule__card {
  margin: 1rem 0.75rem;
  border-radius: 0.9rem;
  background: #fff;
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.06);
  padding: 1.1rem 0.95rem;
}

.public-schedule__card--center {
  text-align: center;
  color: #4b5563;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}

.public-schedule__card--error {
  color: #b91c1c;
  border: 1px solid #fecaca;
}

.public-schedule__text-action {
  color: #c2410c;
  font-weight: 700;
  font-size: 0.9rem;
  min-height: 2.75rem;
  padding: 0.5rem 0.75rem;
}

.public-schedule__role-btn {
  width: 100%;
  min-height: 3.25rem;
  display: flex;
  align-items: center;
  gap: 0.7rem;
  padding: 0.7rem 1rem 0.7rem 0.75rem;
  text-align: left;
  color: #111827;
  background: transparent;
}

.public-schedule__role-btn:active {
  background: #f3f4f6;
}

.public-schedule__role-accent {
  width: 3px;
  align-self: stretch;
  min-height: 1.5rem;
  margin: 0.15rem 0;
  border-radius: 999px;
  flex-shrink: 0;
}

.public-schedule__role-logo {
  width: 1.75rem;
  height: 1.75rem;
  flex-shrink: 0;
  border-radius: 0.3rem;
  object-fit: contain;
}

.public-schedule__role-name {
  flex: 1;
  font-weight: 700;
  font-size: 1.02rem;
  line-height: 1.25;
}

.public-schedule__role-chevron {
  font-size: 1rem;
  color: #9ca3af;
  flex-shrink: 0;
}

.public-schedule__options {
  background: #f8fafc;
  border-top: 1px solid #eef2f7;
}

.public-schedule__search-wrap {
  padding: 0.55rem 1rem 0.25rem 1.35rem;
}

.public-schedule__search {
  width: 100%;
  min-height: 2.6rem;
  border: 1px solid #e5e7eb;
  border-radius: 0.55rem;
  padding: 0.5rem 0.7rem;
  font-size: 1rem;
  background: #fff;
}

.public-schedule__option-btn {
  width: 100%;
  min-height: 2.9rem;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.7rem 1rem 0.7rem 1.35rem;
  text-align: left;
  font-size: 0.98rem;
  border-top: 1px solid #eef2f7;
  line-height: 1.3;
  color: #1f2937;
}

.public-schedule__option-btn:active { background: #fff7ed; }

.public-schedule__empty-filter {
  padding: 0.75rem 1rem 0.75rem 1.35rem;
  font-size: 0.875rem;
  color: #6b7280;
  border-top: 1px solid #eef2f7;
}

.public-schedule__toolbar {
  display: grid;
  grid-template-columns: auto 1fr;
  grid-template-areas:
    "back center"
    "toggle toggle";
  gap: 0.35rem 0.65rem;
  padding: 0.55rem 0.75rem;
  background: #fff;
}

.public-schedule__back {
  grid-area: back;
  align-self: center;
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  min-height: 2.75rem;
  padding: 0.35rem 0.55rem;
  margin-left: -0.25rem;
  border-radius: 0.65rem;
  font-size: 0.9rem;
  font-weight: 700;
  color: #c2410c;
}

.public-schedule__back:active { background: #fff7ed; }

.public-schedule__toolbar-center {
  grid-area: center;
  min-width: 0;
  align-self: center;
}

.public-schedule__toolbar-event {
  font-size: 0.7rem;
  font-weight: 600;
  color: #9a3412;
  opacity: 0.85;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.public-schedule__selection {
  font-size: 1rem;
  font-weight: 800;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.public-schedule__toggle {
  grid-area: toggle;
  display: inline-flex;
  align-items: center;
  gap: 0.55rem;
  min-height: 2.75rem;
  padding: 0.2rem 0.1rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: #374151;
  cursor: pointer;
  user-select: none;
}

.public-schedule__toggle-input {
  width: 1.2rem;
  height: 1.2rem;
  accent-color: #ea580c;
  flex-shrink: 0;
}

.public-schedule__daymeta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.35rem 0.75rem 0.55rem;
  border-top: 1px solid #f3f4f6;
}

.public-schedule__daymeta-label {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: #9a3412;
}

.public-schedule__daymeta-range {
  font-size: 0.95rem;
  font-weight: 750;
  font-variant-numeric: tabular-nums;
  color: #1f2937;
}

.public-schedule__jump-now {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  min-height: 2.25rem;
  padding: 0.35rem 0.7rem;
  border-radius: 0.5rem;
  background: #fff7ed;
  border: 1px solid #fdba74;
  color: #9a3412;
  font-weight: 750;
  font-size: 0.85rem;
  flex-shrink: 0;
}

.public-schedule__jump-now:active { background: #ffedd5; }

/* ─── 1:1 calendar grid (edge-to-edge, flat) ─── */
.public-schedule__calendar {
  background: #fff;
  width: 100%;
}

.public-schedule__timeline {
  position: relative;
  background:
      repeating-linear-gradient(
          to bottom,
          transparent 0,
          transparent calc(30 * var(--ppm, 2px) - 1px),
          #f8fafc calc(30 * var(--ppm, 2px) - 1px),
          #f8fafc calc(30 * var(--ppm, 2px))
      );
}

.public-schedule__hour {
  position: absolute;
  left: 0;
  right: 0;
  height: 0;
  z-index: 1;
  pointer-events: none;
}

.public-schedule__hour-label {
  position: absolute;
  left: 0.15rem;
  top: -0.55rem;
  width: calc(var(--gutter) - 6px);
  text-align: right;
  font-size: 0.7rem;
  font-weight: 750;
  font-variant-numeric: tabular-nums;
  color: #6b7280;
  background: #fff;
  padding: 0.05rem 0;
}

.public-schedule__hour-line {
  position: absolute;
  left: var(--gutter);
  right: 0;
  top: 0;
  border-top: 1px solid #e5e7eb;
}

.public-schedule__now {
  position: absolute;
  left: 0.15rem;
  right: 0;
  z-index: 12;
  display: flex;
  align-items: center;
  pointer-events: none;
  scroll-margin-top: calc(6.5rem + env(safe-area-inset-top, 0px));
}

.public-schedule__now-dot {
  width: 0.6rem;
  height: 0.6rem;
  border-radius: 999px;
  background: #ef4444;
  box-shadow: 0 0 0 3px rgb(239 68 68 / 0.2);
  flex-shrink: 0;
}

.public-schedule__now-line {
  flex: 1;
  height: 2px;
  background: #ef4444;
}

.public-schedule__now-label {
  margin: 0 0.4rem 0 0.3rem;
  font-size: 0.68rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #ef4444;
}

.public-schedule__block {
  position: absolute;
  display: flex;
  align-items: stretch;
  padding: 0;
  border: 1px solid rgb(234 88 12 / 0.22);
  border-radius: 0.35rem;
  background: #fff7ed;
  box-shadow: none;
  overflow: hidden;
  text-align: left;
  color: inherit;
}

.public-schedule__block--band {
  display: block;
  border: 1px dashed #d1d5db;
  background: repeating-linear-gradient(
      -12deg,
      #f8fafc,
      #f8fafc 8px,
      #f1f5f9 8px,
      #f1f5f9 16px
  );
  box-shadow: none;
}

.public-schedule__block--band.public-schedule__block--current {
  border-color: #fdba74;
  background: repeating-linear-gradient(
      -12deg,
      #fff7ed,
      #fff7ed 8px,
      #ffedd5 8px,
      #ffedd5 16px
  );
}

.public-schedule__band-label {
  position: absolute;
  left: 0.5rem;
  right: 0.5rem;
  top: var(--label-top, 50%);
  transform: translateY(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.15rem;
  text-align: center;
  pointer-events: none;
}

.public-schedule__band-title {
  font-size: 0.95rem;
  font-weight: 800;
  color: #64748b;
  letter-spacing: 0.01em;
}

.public-schedule__band-meta {
  font-size: 0.72rem;
  font-weight: 650;
  font-variant-numeric: tabular-nums;
  color: #94a3b8;
}

.public-schedule__block--narrow .public-schedule__block-title {
  font-size: 0.72rem;
  -webkit-line-clamp: 2;
}

.public-schedule__block--narrow .public-schedule__block-meta {
  font-size: 0.62rem;
}

.public-schedule__block--past:not(.public-schedule__block--band) {
  opacity: 0.45;
  filter: grayscale(0.2);
}

.public-schedule__block--band.public-schedule__block--past {
  opacity: 0.55;
}

.public-schedule__block--current:not(.public-schedule__block--band) {
  background: #ffedd5;
  border-color: #f97316;
  box-shadow: 0 0 0 2px rgb(249 115 22 / 0.25);
}

.public-schedule__block--selected {
  border-color: var(--accent);
  box-shadow: 0 0 0 2px var(--accent);
  z-index: 15 !important;
}

.public-schedule__block-accent {
  width: 3px;
  flex-shrink: 0;
  background: var(--accent);
}

.public-schedule__block--current .public-schedule__block-accent {
  background: #ef4444;
}

.public-schedule__block-body {
  flex: 1;
  min-width: 0;
  padding: 0.25rem 0.45rem;
  display: flex;
  flex-direction: column;
  justify-content: flex-start;
  gap: 0.05rem;
}

.public-schedule__block-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem 0.4rem;
  font-size: 0.68rem;
  font-weight: 750;
  font-variant-numeric: tabular-nums;
  color: #4b5563;
  line-height: 1.15;
}

.public-schedule__block-dur {
  color: #9a3412;
}

.public-schedule__block-title {
  font-size: 0.86rem;
  font-weight: 800;
  line-height: 1.2;
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
}

.public-schedule__block--compact .public-schedule__block-title {
  font-size: 0.78rem;
  -webkit-line-clamp: 1;
}

.public-schedule__block-room {
  font-size: 0.68rem;
  color: #6b7280;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Fixed to viewport (teleported) — ignores iframe/parent max-width frames */
.public-schedule__sheet {
  position: fixed;
  inset: 0;
  z-index: 2000;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  pointer-events: none;
  width: 100vw;
  max-width: 100vw;
}

.public-schedule__sheet-backdrop {
  position: absolute;
  inset: 0;
  border: none;
  padding: 0;
  margin: 0;
  background: rgb(15 23 42 / 0.45);
  pointer-events: auto;
  cursor: pointer;
}

.public-schedule__detail-panel {
  position: relative;
  z-index: 1;
  pointer-events: auto;
  width: 100%;
  max-width: 100%;
  max-height: min(75dvh, 36rem);
  display: flex;
  flex-direction: column;
  border-radius: 0.75rem 0.75rem 0 0;
  background: #fff;
  border: none;
  border-top: 1px solid #e5e7eb;
  box-shadow: 0 -4px 24px rgb(15 23 42 / 0.18);
  overflow: hidden;
  animation: public-schedule-sheet-in 0.18s ease-out;
}

@keyframes public-schedule-sheet-in {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}

.public-schedule__sheet-handle {
  width: 2.75rem;
  height: 0.25rem;
  border-radius: 999px;
  background: rgb(255 255 255 / 0.35);
  margin: 0 auto 0.55rem;
  flex-shrink: 0;
}

.public-schedule__detail-panel--current .public-schedule__detail-head {
  background: #1f2937;
}

.public-schedule__detail-head {
  display: flex;
  flex-direction: column;
  padding: 0.45rem 0.85rem 0.75rem;
  background: #111827;
  color: #fff;
  flex-shrink: 0;
  border-radius: 0.75rem 0.75rem 0 0;
  touch-action: none;
  cursor: grab;
  user-select: none;
}

.public-schedule__detail-panel--dragging .public-schedule__detail-head {
  cursor: grabbing;
}

.public-schedule__detail-head-row {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
}

.public-schedule__detail-body {
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  overscroll-behavior: contain;
  touch-action: pan-y;
  flex: 1;
  min-height: 0;
  padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px));
}

.public-schedule__detail-kicker {
  display: inline-block;
  margin-bottom: 0.25rem;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  background: #ef4444;
  font-size: 0.65rem;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
}

.public-schedule__detail-title {
  font-size: 1.05rem;
  font-weight: 800;
  line-height: 1.25;
}

.public-schedule__detail-time {
  margin-top: 0.25rem;
  font-size: 0.9rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: rgb(255 255 255 / 0.85);
}

.public-schedule__detail-close {
  width: 2.5rem;
  height: 2.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.65rem;
  color: #fff;
  flex-shrink: 0;
}

.public-schedule__detail-close:active {
  background: rgb(255 255 255 / 0.12);
}

.public-schedule__detail-desc {
  padding: 0.75rem 0.9rem 0;
  margin: 0;
  font-size: 0.85rem;
  color: #4b5563;
  line-height: 1.4;
}

.public-schedule__detail-room {
  padding: 0.45rem 0.9rem 0;
  margin: 0;
  font-size: 0.85rem;
  color: #6b7280;
  display: flex;
  align-items: center;
  gap: 0.3rem;
}

.public-schedule__activities {
  list-style: none;
  margin: 0;
  padding: 0.35rem 0 0.4rem;
}

.public-schedule__activity {
  padding: 0.75rem 0.9rem;
  border-top: 1px solid #f3f4f6;
}

.public-schedule__activity-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
}

.public-schedule__activity-name {
  font-weight: 700;
  font-size: 0.95rem;
  line-height: 1.3;
  min-width: 0;
}

.public-schedule__activity-time {
  flex-shrink: 0;
  font-size: 0.85rem;
  font-weight: 750;
  font-variant-numeric: tabular-nums;
  color: #374151;
}

.public-schedule__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  margin-top: 0.55rem;
}

.public-schedule__chip {
  display: inline-flex;
  align-items: center;
  min-height: 2.4rem;
  padding: 0.35rem 0.65rem;
  border-radius: 0.65rem;
  background: #f3f4f6;
  color: #374151;
  font-size: 0.85rem;
}

.public-schedule__chip--action {
  background: #fff7ed;
  color: #9a3412;
  font-weight: 700;
  text-align: left;
}

.public-schedule__chip--action:active { background: #ffedd5; }

@media (min-width: 480px) {
  .public-schedule__toolbar {
    grid-template-columns: auto 1fr auto;
    grid-template-areas: "back center toggle";
    align-items: center;
  }
}
</style>
