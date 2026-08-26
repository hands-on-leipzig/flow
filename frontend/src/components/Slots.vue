<script setup lang="ts">
import {computed, nextTick, onActivated, onDeactivated, onMounted, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import LoaderText from '@/components/atoms/LoaderText.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import ScheduleToast from '@/components/atoms/ScheduleToast.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {programNameForId} from '@/utils/eventPrograms'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {notifyPlanPreviewReload} from '@/utils/planPreviewSync'

defineOptions({name: 'Slots'})

const {previewReload} = useScheduleWorkspace()

/** 0 = beide, 2 = Explore, 3 = Challenge — wie Freie Blöcke */
type SlotBlock = {
  id: number
  name: string
  description: string
  link: string
  duration: number
  first_program: number
  active: boolean
}

type TeamRow = {
  row_key: string
  team_id: number | null
  team_number_plan: number | null
  team_number_hot: string | null
  team_name: string
  first_program: number
  start: string | null
  collision_status?: 'red' | 'yellow' | 'green' | null
  collision_gap_minutes?: number | null
}

type TeamActivityLine = {
  id: number
  start: string
  end: string
  label: string
  status: 'red' | 'yellow' | 'green' | null
  gap_minutes: number | null
}

type TeamActivityTooltipData = {
  slot_start: string | null
  slot_date: string | null
  activities: TeamActivityLine[]
}

function normalizeDurationMinutes(d: number): number {
  const n = Math.round(Number(d) / 5) * 5
  return Math.min(480, Math.max(5, n || 5))
}

function firstProgramFromFlags(fe: boolean, fc: boolean): number {
  if (fe && fc) return 0
  if (fe) return 2
  if (fc) return 3
  return 0
}

function flagsFromFirstProgram(fp: number): { for_explore: boolean; for_challenge: boolean } {
  const p = Number(fp)
  if (p === 0) return {for_explore: true, for_challenge: true}
  if (p === 2) return {for_explore: true, for_challenge: false}
  if (p === 3) return {for_explore: false, for_challenge: true}
  return {for_explore: true, for_challenge: true}
}

function mapApiToSlot(b: Record<string, unknown>): SlotBlock {
  const fe = !!b.for_explore
  const fc = !!b.for_challenge
  const raw = Number(b.duration)
  const dur =
    Number.isFinite(raw) && raw > 0 ? normalizeDurationMinutes(raw) : 30
  return {
    id: Number(b.id),
    name: String(b.name ?? ''),
    description: (b.description as string) ?? '',
    link: (b.link as string) ?? '',
    duration: dur,
    first_program: firstProgramFromFlags(fe, fc),
    active: b.active !== false,
  }
}

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)
const planId = ref<number | null>(null)
const loading = ref(true)
/** Skip full reload when keep-alive reactivates the same event. */
const loadedForEventId = ref<number | null>(null)
const blocks = ref<SlotBlock[]>([])
const selectedId = ref<number | null>(null)
const teams = ref<TeamRow[]>([])
const loadingTeams = ref(false)
const savingBlockId = ref<number | null>(null)
const blockToDelete = ref<SlotBlock | null>(null)
const errorMsg = ref<string | null>(null)

const newSlotName = ref('')
const newSlotDescription = ref('')
const newSlotLink = ref('')
const newSlotDuration = ref(30)
const newFirstProgram = ref(0)
const newSlotCardRef = ref<HTMLElement | null>(null)
const newSlotInput = ref<HTMLInputElement | null>(null)
const isCreatingSlot = ref(false)
const isSavingNew = ref(false)

const selectedBlock = computed(() => blocks.value.find((b) => b.id === selectedId.value) ?? null)

const applying = ref(false)
const applyError = ref<string | null>(null)
const applyResult = ref<{ removed_activities: number; removed_groups: number; created_groups: number; created_activities: number } | null>(null)
const applyToast = ref<InstanceType<typeof ScheduleToast> | null>(null)
const eDurationTransfer = ref<number>(0)
const cDurationTransfer = ref<number>(0)
const tooltipOpenKey = ref<string | null>(null)
const tooltipLoadingKey = ref<string | null>(null)
const tooltipActivities = ref<Record<string, TeamActivityTooltipData>>({})

async function applySlotsToPlan() {
  if (!planId.value || applying.value) return
  applying.value = true
  applyError.value = null
  applyResult.value = null
  try {
    const {data} = await axios.post(`/plans/${planId.value}/extra-blocks/slot/apply-to-plan`)
    applyResult.value = data
    previewReload.value += 1
    notifyPlanPreviewReload(planId.value)
    // Refresh collision colors after plan write
    await loadTeams()
  } catch (e: any) {
    applyError.value = e?.response?.data?.message || e?.message || 'Übernahme fehlgeschlagen'
  } finally {
    applying.value = false
  }
}

function programIcon(fp: number): { src: string; alt: string } {
  const name = programNameForId(event.value, fp)
  return {src: programLogoSrc(name), alt: programLogoAlt(name)}
}

const editingTeamId = ref<string | null>(null)
const editingStartLocal = ref<string>('') // YYYY-MM-DDTHH:mm

function rowEditKey(row: TeamRow): string {
  return `${row.first_program}:${row.team_number_plan ?? 0}`
}

function compareTeamRows(a: TeamRow, b: TeamRow): number {
  if (!a.start && !b.start) {
    if ((a.first_program ?? 0) !== (b.first_program ?? 0)) {
      return (a.first_program ?? 0) - (b.first_program ?? 0)
    }
    return (a.team_number_plan ?? 0) - (b.team_number_plan ?? 0)
  }
  if (!a.start) return 1
  if (!b.start) return -1

  const timeCmp = wallTimeSortKey(a.start).localeCompare(wallTimeSortKey(b.start))
  if (timeCmp !== 0) return timeCmp
  if ((a.first_program ?? 0) !== (b.first_program ?? 0)) {
    return (a.first_program ?? 0) - (b.first_program ?? 0)
  }
  return (a.team_number_plan ?? 0) - (b.team_number_plan ?? 0)
}

function eventBaseDateYmd(): string {
  const d = (event.value as {date?: string} | undefined)?.date
  if (d == null || d === '') {
    const t = new Date()
    const y = t.getFullYear()
    const m = String(t.getMonth() + 1).padStart(2, '0')
    const day = String(t.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }
  const m = String(d).match(/(\d{4}-\d{2}-\d{2})/)
  return m ? m[1] : String(d).slice(0, 10)
}

/** API / DB "YYYY-MM-DD HH:mm:ss" or similar → datetime-local value (no TZ math) */
function wallTimeToDatetimeLocal(s: string | null): string {
  if (!s || typeof s !== 'string') return ''
  const m = s.trim().match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[1]}T${m[2]}:${m[3]}` : ''
}

/** datetime-local → DB string */
function datetimeLocalToDb(value: string): string | null {
  const v = value?.trim()
  if (!v) return null
  const m = v.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?/)
  if (!m) return null
  return `${m[1]} ${m[2]}:${m[3]}:${m[4] ?? '00'}`
}

function wallTimeSortKey(s: string | null): string {
  if (!s) return ''
  const m = String(s).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?/)
  if (!m) return s
  return `${m[1]}${m[2]}${m[3]}${m[4] ?? '00'}`
}

function defaultStartLocal(): string {
  return `${eventBaseDateYmd()}T09:00`
}

function beginEditStart(row: TeamRow) {
  if (!selectedBlock.value?.active) return
  // If not assigned yet: immediately create assignment with event date @ 09:00
  // (requirement: initial set must be persisted right away).
  if (!row.start) {
    const initial = defaultStartLocal()
    editingTeamId.value = rowEditKey(row)
    editingStartLocal.value = initial
    onTeamStartChange(row, initial)
    return
  }
  editingTeamId.value = rowEditKey(row)
  editingStartLocal.value = wallTimeToDatetimeLocal(row.start)
}

function cancelEditStart(row: TeamRow) {
  if (editingTeamId.value === rowEditKey(row)) {
    editingTeamId.value = null
    editingStartLocal.value = ''
  }
}

async function loadPlan() {
  if (!event.value?.id) return
  const data = await planCache.getPlan(event.value.id)
  planId.value = data?.id ?? null
}

async function loadBlocks() {
  if (!planId.value) return
  errorMsg.value = null
  const {data} = await axios.get<Record<string, unknown>[]>(
    `/plans/${planId.value}/extra-blocks/slot`
  )
  const rows = Array.isArray(data) ? data : []
  blocks.value = rows.map((row) => mapApiToSlot(row))
  await Promise.all(
    rows.map(async (row, i) => {
      const desired = blocks.value[i]?.duration
      if (desired == null || Number(row.duration) === desired) return
      try {
        await axios.put(`/plans/${planId.value}/extra-blocks/slot/${row.id}`, {
          duration: desired,
        })
      } catch {
        /* ignore */
      }
    })
  )
  if (selectedId.value && !blocks.value.some((b) => b.id === selectedId.value)) {
    selectedId.value = blocks.value[0]?.id ?? null
  } else if (!selectedId.value && blocks.value.length) {
    selectedId.value = blocks.value[0].id
  }
}

async function loadTeams() {
  if (!planId.value || !selectedId.value) {
    teams.value = []
    return
  }
  loadingTeams.value = true
  try {
    const {data} = await axios.get<{ teams: TeamRow[]; e_duration_transfer?: number; c_duration_transfer?: number }>(
      `/plans/${planId.value}/extra-blocks/slot/${selectedId.value}/teams`
    )
    teams.value = data?.teams ?? []
    eDurationTransfer.value = Number(data?.e_duration_transfer ?? 0) || 0
    cDurationTransfer.value = Number(data?.c_duration_transfer ?? 0) || 0
  } finally {
    loadingTeams.value = false
  }
}

/** Nur Pfeiltasten / Tab — Dauer nur in 5-Min-Schritten per Spinner */
function onDurationKeydown(e: KeyboardEvent) {
  const ok = [
    'Tab',
    'ArrowUp',
    'ArrowDown',
    'ArrowLeft',
    'ArrowRight',
    'Home',
    'End',
    'Enter',
  ].includes(e.key)
  if (e.metaKey || e.ctrlKey || e.altKey) return
  if (!ok && e.key.length === 1) e.preventDefault()
}

function onDurationInputBlock(block: SlotBlock, el: HTMLInputElement) {
  const v = normalizeDurationMinutes(Number(el.value) || 5)
  if (v === block.duration) return
  block.duration = v
  patchBlock(block, {duration: v})
}

function onNewDurationChange(el: HTMLInputElement) {
  newSlotDuration.value = normalizeDurationMinutes(Number(el.value) || 5)
  el.value = String(newSlotDuration.value)
}

/** Gleiche Logik wie FreeBlocks.vue toggleProgram */
function toggleProgramBlock(block: SlotBlock, program: 2 | 3) {
  if (!block.active) return
  let fp = block.first_program
  if (program === 2) {
    if (fp === 2) fp = 3
    else if (fp === 3) fp = 0
    else if (fp === 0) fp = 3
    else fp = 2
  } else {
    if (fp === 3) fp = 2
    else if (fp === 2) fp = 0
    else if (fp === 0) fp = 2
    else fp = 3
  }
  block.first_program = fp
  const {for_explore, for_challenge} = flagsFromFirstProgram(fp)
  patchBlock(block, {for_explore, for_challenge})
}

function toggleProgramNew(program: 2 | 3) {
  let fp = newFirstProgram.value
  if (program === 2) {
    if (fp === 2) fp = 3
    else if (fp === 3) fp = 0
    else if (fp === 0) fp = 3
    else fp = 2
  } else {
    if (fp === 3) fp = 2
    else if (fp === 2) fp = 0
    else if (fp === 0) fp = 2
    else fp = 3
  }
  newFirstProgram.value = fp
}

function toggleActiveBlock(block: SlotBlock, active: boolean) {
  block.active = active
  patchBlock(block, {active})
}

function canCreateNewSlot() {
  return newSlotName.value.trim().length > 0
}

async function createNewSlotBlock() {
  if (!planId.value || isCreatingSlot.value) return
  if (!canCreateNewSlot()) return

  const {for_explore, for_challenge} = flagsFromFirstProgram(newFirstProgram.value)
  isCreatingSlot.value = true
  isSavingNew.value = true
  errorMsg.value = null
  try {
    const {data} = await axios.post<Record<string, unknown>>(
      `/plans/${planId.value}/extra-blocks/slot`,
      {
        name: newSlotName.value.trim(),
        description: newSlotDescription.value.trim() || null,
        link: newSlotLink.value.trim() || null,
        duration: normalizeDurationMinutes(newSlotDuration.value),
        for_explore,
        for_challenge,
        active: true,
      }
    )
    newSlotName.value = ''
    newSlotDescription.value = ''
    newSlotLink.value = ''
    newSlotDuration.value = 30
    newFirstProgram.value = 0
    await loadBlocks()
    selectedId.value = Number(data.id)
    await nextTick()
    newSlotInput.value?.focus()
  } catch (e: any) {
    const d = e?.response?.data
    errorMsg.value =
      (d?.errors && Object.values(d.errors).flat().join(' ')) ||
      d?.message ||
      e?.message ||
      'Anlegen fehlgeschlagen'
  } finally {
    isCreatingSlot.value = false
    isSavingNew.value = false
  }
}

function handleClickOutside(e: MouseEvent) {
  const el = newSlotCardRef.value
  if (el && !el.contains(e.target as Node)) {
    if (newSlotName.value.trim()) createNewSlotBlock()
  }
}

async function ensureSlotsLoaded(force = false) {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const eventId = event.value?.id ?? null
  if (!eventId) {
    loading.value = false
    return
  }
  if (!force && loadedForEventId.value === eventId && planId.value) {
    return
  }

  loading.value = true
  try {
    await loadPlan()
    if (planId.value) await loadBlocks()
    else teams.value = []
    // teams load via selectedId watch after blocks select a slot
    loadedForEventId.value = eventId
  } finally {
    loading.value = false
  }
}

function bindOutsideClick() {
  document.addEventListener('click', handleClickOutside)
}

function unbindOutsideClick() {
  document.removeEventListener('click', handleClickOutside)
}

onMounted(() => {
  void ensureSlotsLoaded()
  bindOutsideClick()
})

onActivated(() => {
  void ensureSlotsLoaded()
  bindOutsideClick()
})

onDeactivated(unbindOutsideClick)
onUnmounted(unbindOutsideClick)

watch(
  () => event.value?.id,
  (id, prev) => {
    if (id && id !== prev) {
      loadedForEventId.value = null
      selectedId.value = null
      blocks.value = []
      teams.value = []
      void ensureSlotsLoaded(true)
    }
  }
)

watch(selectedId, () => {
  tooltipActivities.value = {}
  tooltipOpenKey.value = null
  tooltipLoadingKey.value = null
  void loadTeams()
})

async function patchBlock(block: SlotBlock, patch: Record<string, unknown>) {
  if (!planId.value) return
  savingBlockId.value = block.id
  errorMsg.value = null
  try {
    const {data} = await axios.put<Record<string, unknown>>(
      `/plans/${planId.value}/extra-blocks/slot/${block.id}`,
      patch
    )
    const mapped = mapApiToSlot(data)
    const i = blocks.value.findIndex((b) => b.id === block.id)
    if (i >= 0) {
      blocks.value[i] = {...blocks.value[i], ...mapped}
    }
    blocks.value = [...blocks.value].sort((a, b) =>
      (a.name || '').localeCompare(b.name || '', 'de', {sensitivity: 'base'})
    )
    if (patch.for_explore !== undefined || patch.for_challenge !== undefined) {
      await loadTeams()
    }
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message || e?.message || 'Speichern fehlgeschlagen'
  } finally {
    savingBlockId.value = null
  }
}

async function confirmDelete() {
  const b = blockToDelete.value
  if (!b || !planId.value) return
  try {
    await axios.delete(`/plans/${planId.value}/extra-blocks/slot/${b.id}`)
    blockToDelete.value = null
    if (selectedId.value === b.id) selectedId.value = null
    await loadBlocks()
    await loadTeams()
  } catch (e: any) {
    errorMsg.value = e?.response?.data?.message || 'Löschen fehlgeschlagen'
  }
}

/** Plan team number as T01, T09, … (min. two digits) */
function formatPlanTeamNo(n: number | null | undefined): string {
  if (n == null || n === undefined || !Number.isFinite(Number(n))) return '–'
  return `T${String(Math.floor(Number(n))).padStart(2, '0')}`
}

function collisionDotClass(status: TeamRow['collision_status']): string {
  if (status === 'red') return 'dot--red'
  if (status === 'yellow') return 'dot--yellow'
  if (status === 'green') return 'dot--green'
  return ''
}

function programLogoClass(block: SlotBlock, program: 2 | 3): string[] {
  const on =
      block.first_program === program || block.first_program === 0
  return [
    'slots-block__logo',
    !block.active ? 'is-off' : on ? 'is-on' : 'is-off',
  ]
}

function lineStatusClass(status: TeamActivityLine['status']): string {
  if (status === 'red') return 'text-red-700'
  if (status === 'yellow') return 'text-yellow-700'
  if (status === 'green') return 'text-green-700'
  return 'text-[var(--color-text-muted)]'
}

function wallTimeHm(s: string | null): string {
  if (!s) return '--:--'
  const m = String(s).match(/^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/)
  return m ? `${m[1]}:${m[2]}` : String(s).slice(11, 16)
}

async function openTooltip(row: TeamRow) {
  if (!planId.value || !selectedId.value) return
  const key = row.row_key
  tooltipOpenKey.value = key
  if (tooltipActivities.value[key]) return

  tooltipLoadingKey.value = key
  try {
    const {data} = await axios.get<TeamActivityTooltipData>(
      `/plans/${planId.value}/extra-blocks/slot/${selectedId.value}/teams/${row.first_program}/${row.team_number_plan}/activities`
    )
    tooltipActivities.value = {
      ...tooltipActivities.value,
      [key]: {
        slot_start: data?.slot_start ?? null,
        slot_date: data?.slot_date ?? null,
        activities: data?.activities ?? [],
      },
    }
  } catch {
    tooltipActivities.value = {
      ...tooltipActivities.value,
      [key]: {
        slot_start: null,
        slot_date: null,
        activities: [],
      },
    }
  } finally {
    if (tooltipLoadingKey.value === key) {
      tooltipLoadingKey.value = null
    }
  }
}

function formatTooltipDate(slotDate: string | null): string {
  if (!slotDate) return 'ohne Datum'
  const m = String(slotDate).match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return String(slotDate)
  return `${m[3]}.${m[2]}.${m[1]}`
}

function closeTooltip(row: TeamRow) {
  if (tooltipOpenKey.value === row.row_key) {
    tooltipOpenKey.value = null
  }
}

function collisionTitle(row: TeamRow): string {
  if (!row.start) return 'Keine Startzeit zugewiesen'
  if (row.collision_status === 'red') return 'Kollision: Überschneidung mit anderer Aktivität'
  if (row.collision_status === 'yellow') {
    if (row.collision_gap_minutes != null) {
      return `Knapp: Abstand nur ${row.collision_gap_minutes} Min (unter Transferzeit)`
    }
    return 'Knapp: Abstand unter Transferzeit'
  }
  if (row.collision_status === 'green') return 'OK: Keine Kollision, Transferzeit eingehalten'
  return 'Prüfung ausstehend'
}

async function onTeamStartChange(row: TeamRow, value: string) {
  if (!planId.value || !selectedId.value) return
  const key = row.row_key
  const start = value ? datetimeLocalToDb(value) : null
  try {
    const {data} = await axios.patch(
      `/plans/${planId.value}/extra-blocks/slot/${selectedId.value}/teams/${row.first_program}/${row.team_number_plan}`,
      {start}
    )
    row.start = data.start
    row.collision_status = data.collision_status ?? null
    row.collision_gap_minutes = data.collision_gap_minutes ?? null
    cancelEditStart(row)
    // Recalculate tooltip line colors for this row against the updated slot time.
    tooltipActivities.value = Object.fromEntries(
      Object.entries(tooltipActivities.value).filter(([k]) => k !== key)
    )
    if (tooltipOpenKey.value === key) {
      await openTooltip(row)
    }
    teams.value = [...teams.value].sort(compareTeamRows)
  } catch {
    await loadTeams()
  }
}

const inputUnderline =
  'border-b border-[var(--color-border)] w-full focus:outline-none focus:border-blue-500'
const inputTitle =
  'text-sm md:text-md font-semibold border-b border-[var(--color-border)] flex-1 focus:outline-none focus:border-blue-500'
</script>

<template>
  <div class="slots-panel">
    <div v-if="loading" class="flex items-center justify-start flex-col text-[var(--color-text-muted)] py-16">
      <LoaderFlow/>
      <LoaderText/>
    </div>
    <div v-else-if="!planId" class="text-[var(--color-text-muted)]">Kein Plan für diese Veranstaltung.</div>

    <div v-else class="slots-panel__stack">
      <section class="slots-panel__apply">
        <button
            type="button"
            class="glass-btn-accent w-full !text-sm !py-2.5 !px-3 disabled:opacity-60 disabled:cursor-not-allowed"
            :disabled="applying"
            @click="applySlotsToPlan"
        >
          <span v-if="!applying">Zuordnungen in den Plan übernehmen</span>
          <span v-else>Übernehme…</span>
        </button>
        <p class="text-xs text-[var(--color-text-muted)] leading-snug">
          Ersetzt bisherige Slot-Zuordnungen im Plan. Konflikte werden dabei nicht geprüft.
        </p>
        <p v-if="applyError" class="glass-alert-error text-xs !py-2 !px-2.5">{{ applyError }}</p>
        <p
            v-else-if="applyResult"
            class="text-xs rounded-[var(--radius)] border border-[var(--color-border)] px-2.5 py-2 text-[var(--color-text)]"
            style="background: color-mix(in srgb, #16a34a 10%, var(--color-bg-muted));"
        >
          OK: −{{ applyResult.removed_groups }}/{{ applyResult.removed_activities }} ·
          +{{ applyResult.created_groups }}/{{ applyResult.created_activities }}
        </p>
      </section>

      <section class="slots-panel__section">
        <h2 class="slots-panel__heading">Aktivitäten</h2>
        <p v-if="errorMsg" class="text-sm text-red-600 mb-2">{{ errorMsg }}</p>

        <div class="slots-panel__list">
          <div
              v-for="block in blocks"
              :key="block.id"
              class="slots-block"
              :class="{
                'slots-block--selected': selectedId === block.id,
                'slots-block--off': !block.active,
              }"
              @click="selectedId = block.id"
          >
            <div class="slots-block__side" @click.stop>
              <ToggleSwitch
                  :model-value="block.active"
                  @update:model-value="toggleActiveBlock(block, $event)"
              />
              <button
                  type="button"
                  class="slots-block__trash"
                  title="Slot-Block löschen"
                  @click="blockToDelete = block"
              >
                <i class="bi bi-trash-fill" aria-hidden="true"/>
              </button>
            </div>
            <div class="slots-block__body">
              <input
                  v-model="block.name"
                  :disabled="!block.active"
                  :class="[inputTitle, 'w-full', !block.active ? 'text-[var(--color-text-subtle)]' : '']"
                  placeholder="Name"
                  @click.stop
                  @blur="block.active && patchBlock(block, { name: block.name.trim() })"
              />
              <input
                  v-model="block.description"
                  type="text"
                  :disabled="!block.active"
                  :class="[inputUnderline, 'text-xs text-[var(--color-text-muted)]', !block.active ? 'cursor-not-allowed' : '']"
                  placeholder="Beschreibung"
                  @click.stop
                  @blur="block.active && patchBlock(block, { description: block.description || null })"
              />
              <input
                  v-model="block.link"
                  type="url"
                  :disabled="!block.active"
                  :class="[inputUnderline, 'text-xs text-[var(--color-text-muted)]', !block.active ? 'cursor-not-allowed' : '']"
                  placeholder="Link (URL)"
                  @click.stop
                  @blur="block.active && patchBlock(block, { link: block.link || null })"
              />
              <div class="slots-block__meta">
                <label class="slots-block__duration">
                  <span>Min.</span>
                  <input
                      type="number"
                      :value="block.duration"
                      min="5"
                      max="480"
                      step="5"
                      :disabled="!block.active"
                      inputmode="none"
                      title="Nur mit Pfeiltasten ändern (5-Min-Schritte)"
                      @click.stop
                      @keydown="onDurationKeydown"
                      @paste.prevent
                      @input="onDurationInputBlock(block, $event.target as HTMLInputElement)"
                  />
                </label>
                <div class="slots-block__programs">
                  <img
                      :src="programLogoSrc('EXPLORE')"
                      :alt="programLogoAlt('EXPLORE')"
                      :class="programLogoClass(block, 2)"
                      title="Explore"
                      @click.stop="toggleProgramBlock(block, 2)"
                  />
                  <img
                      :src="programLogoSrc('CHALLENGE')"
                      :alt="programLogoAlt('CHALLENGE')"
                      :class="programLogoClass(block, 3)"
                      title="Challenge"
                      @click.stop="toggleProgramBlock(block, 3)"
                  />
                </div>
              </div>
              <div v-if="savingBlockId === block.id" class="text-xs text-[var(--color-text-subtle)]">Speichern…</div>
            </div>
          </div>

          <div ref="newSlotCardRef" class="slots-block slots-block--new" @click.stop>
            <input
                ref="newSlotInput"
                v-model="newSlotName"
                :disabled="isSavingNew"
                :class="inputTitle + ' w-full'"
                placeholder="Neue Aktivität…"
                @keyup.enter="createNewSlotBlock"
            />
            <p v-if="!newSlotName.trim()" class="text-xs text-[var(--color-text-subtle)] mt-1">
              z.B. Führung, Mittagessen, Interview
            </p>
            <transition name="fade">
              <div v-if="newSlotName.trim().length > 0" class="slots-block__body !p-0 mt-2 space-y-2">
                <input
                    v-model="newSlotDescription"
                    :disabled="isSavingNew"
                    type="text"
                    :class="inputUnderline + ' text-xs text-[var(--color-text-muted)]'"
                    placeholder="Beschreibung"
                    @keyup.enter="createNewSlotBlock"
                />
                <input
                    v-model="newSlotLink"
                    :disabled="isSavingNew"
                    type="url"
                    :class="inputUnderline + ' text-xs text-[var(--color-text-muted)]'"
                    placeholder="Link (URL)"
                    @keyup.enter="createNewSlotBlock"
                />
                <div class="slots-block__meta">
                  <label class="slots-block__duration">
                    <span>Min.</span>
                    <input
                        type="number"
                        :value="newSlotDuration"
                        min="5"
                        max="480"
                        step="5"
                        :disabled="isSavingNew"
                        inputmode="none"
                        @keydown="onDurationKeydown"
                        @paste.prevent
                        @input="onNewDurationChange($event.target as HTMLInputElement)"
                    />
                  </label>
                  <div class="slots-block__programs">
                    <img
                        :src="programLogoSrc('EXPLORE')"
                        :alt="programLogoAlt('EXPLORE')"
                        :class="[
                          'slots-block__logo',
                          newFirstProgram === 2 || newFirstProgram === 0 ? 'is-on' : 'is-off',
                        ]"
                        title="Explore"
                        @click="toggleProgramNew(2)"
                    />
                    <img
                        :src="programLogoSrc('CHALLENGE')"
                        :alt="programLogoAlt('CHALLENGE')"
                        :class="[
                          'slots-block__logo',
                          newFirstProgram === 3 || newFirstProgram === 0 ? 'is-on' : 'is-off',
                        ]"
                        title="Challenge"
                        @click="toggleProgramNew(3)"
                    />
                  </div>
                </div>
                <p class="text-xs text-[var(--color-text-subtle)]">Enter oder Klick außerhalb legt an.</p>
              </div>
            </transition>
          </div>
        </div>
      </section>

      <section
          class="slots-panel__section"
          :class="selectedBlock && !selectedBlock.active ? 'opacity-60' : ''"
      >
        <h2 class="slots-panel__heading">
          {{ selectedBlock ? `Teams · ${selectedBlock.name}` : 'Teams' }}
        </h2>

        <template v-if="selectedBlock">
          <div class="slots-legend">
            <span><i class="dot dot--red"/>Konflikt</span>
            <span><i class="dot dot--yellow"/>Transfer &lt; E{{ eDurationTransfer }}/C{{ cDurationTransfer }}</span>
            <span><i class="dot dot--green"/>OK</span>
          </div>

          <div v-if="loadingTeams" class="flex items-center gap-2 text-[var(--color-text-subtle)] py-6">
            <LoaderFlow class="scale-75"/>
            <span class="text-sm">Lade Teams…</span>
          </div>

          <div v-else class="slots-teams">
            <div
                v-for="row in teams"
                :key="row.row_key"
                class="slots-team"
            >
              <div class="slots-team__top">
                <img
                    :src="programIcon(row.first_program).src"
                    :alt="programIcon(row.first_program).alt"
                    class="w-6 h-6 flex-shrink-0"
                />
                <div class="min-w-0 flex-1">
                  <div class="flex items-baseline gap-2 min-w-0">
                    <div
                        class="relative"
                        @mouseenter="openTooltip(row)"
                        @mouseleave="closeTooltip(row)"
                    >
                      <button
                          type="button"
                          class="font-semibold tabular-nums underline decoration-dotted underline-offset-2 hover:text-[var(--color-accent)]"
                          @focus="openTooltip(row)"
                          @blur="closeTooltip(row)"
                      >
                        {{ formatPlanTeamNo(row.team_number_plan) }}
                      </button>
                      <div
                          v-if="tooltipOpenKey === row.row_key"
                          class="slots-tooltip glass-dropdown"
                      >
                        <p class="text-xs font-semibold mb-2">
                          Aktivitäten · {{ formatTooltipDate(tooltipActivities[row.row_key]?.slot_date ?? null) }}
                        </p>
                        <p v-if="tooltipLoadingKey === row.row_key" class="text-xs text-[var(--color-text-subtle)]">Lade…</p>
                        <p
                            v-else-if="!(tooltipActivities[row.row_key]?.activities?.length)"
                            class="text-xs text-[var(--color-text-subtle)]"
                        >
                          Keine team-spezifischen Aktivitäten.
                        </p>
                        <ul v-else class="space-y-1">
                          <li
                              v-for="act in tooltipActivities[row.row_key].activities"
                              :key="act.id"
                              class="text-xs"
                              :class="lineStatusClass(act.status)"
                          >
                            <span class="font-mono">{{ wallTimeHm(act.start) }}-{{ wallTimeHm(act.end) }}</span>
                            · {{ act.label }}
                          </li>
                        </ul>
                      </div>
                    </div>
                    <span class="text-xs text-[var(--color-text-muted)] tabular-nums">
                      {{ row.team_number_hot ?? '–' }}
                    </span>
                  </div>
                  <p class="text-sm truncate">{{ row.team_name }}</p>
                </div>
                <span
                    v-if="row.start"
                    class="dot"
                    :class="collisionDotClass(row.collision_status ?? null)"
                    :title="collisionTitle(row)"
                />
              </div>

              <div class="slots-team__time">
                <button
                    v-if="!row.start && editingTeamId !== rowEditKey(row)"
                    type="button"
                    class="glass-btn-secondary w-full !justify-start !text-sm !py-1.5 !text-[var(--color-text-subtle)]"
                    :disabled="!selectedBlock.active"
                    @click="beginEditStart(row)"
                >
                  <i class="bi bi-clock" aria-hidden="true"/>
                  Start setzen…
                </button>
                <template v-else>
                  <input
                      type="datetime-local"
                      :disabled="!selectedBlock.active"
                      class="slots-team__datetime"
                      :value="editingTeamId === rowEditKey(row) ? editingStartLocal : wallTimeToDatetimeLocal(row.start)"
                      @change="onTeamStartChange(row, ($event.target as HTMLInputElement).value)"
                      @blur="!row.start && cancelEditStart(row)"
                  />
                  <button
                      v-if="row.start"
                      type="button"
                      class="slots-block__trash"
                      :disabled="!selectedBlock.active"
                      title="Zuweisung entfernen"
                      @click="onTeamStartChange(row, '')"
                  >
                    <i class="bi bi-trash-fill" aria-hidden="true"/>
                  </button>
                </template>
              </div>
            </div>

            <p v-if="!teams.length" class="py-6 text-sm text-[var(--color-text-subtle)] text-center">
              Keine Teams im Plan für diesen Slot-Typ.
            </p>
          </div>
        </template>
        <p v-else class="text-sm text-[var(--color-text-subtle)]">Oben eine Aktivität auswählen.</p>
      </section>
    </div>

    <ConfirmationModal
        :show="!!blockToDelete"
        type="danger"
        title="Slot-Block löschen?"
        :message="blockToDelete ? `„${blockToDelete.name}“ und alle Team-Zeiten dazu werden gelöscht.` : ''"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="confirmDelete"
        @cancel="blockToDelete = null"
    />

    <ScheduleToast
        ref="applyToast"
        action="update"
        :is-generating="applying"
        :countdown="null"
        message="Plan wird aktualisiert"
    />
  </div>
</template>

<style scoped>
.slots-panel__stack {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.slots-panel__apply {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.slots-panel__heading {
  margin: 0 0 0.65rem;
  font-size: 0.95rem;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.slots-panel__list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.slots-block {
  display: flex;
  gap: 0.65rem;
  padding: 0.7rem 0.75rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  border-radius: 12px;
  cursor: pointer;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}

.slots-block--selected {
  border-color: color-mix(in srgb, var(--color-accent) 70%, var(--color-border));
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 35%, transparent);
}

.slots-block--off {
  opacity: 0.62;
}

.slots-block--new {
  display: block;
  border-style: dashed;
  cursor: default;
}

.slots-block__side {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
  padding-top: 0.1rem;
}

.slots-block__trash {
  color: var(--color-text-subtle);
  font-size: 1rem;
  line-height: 1;
  padding: 0.15rem;
}

.slots-block__trash:hover:not(:disabled) {
  color: #b91c1c;
}

.slots-block__trash:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

.slots-block__body {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.slots-block__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 0.75rem;
  margin-top: 0.15rem;
}

.slots-block__duration {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.slots-block__duration input {
  width: 3.75rem;
  text-align: center;
  font-size: 0.875rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 0.15rem 0.25rem;
  background: transparent;
}

.slots-block__programs {
  display: flex;
  gap: 0.25rem;
}

.slots-block__logo {
  width: 1.75rem;
  height: 1.75rem;
  cursor: pointer;
  transition: opacity 0.12s ease, transform 0.12s ease, filter 0.12s ease;
}

.slots-block__logo.is-on {
  opacity: 1;
}

.slots-block__logo.is-off {
  opacity: 0.3;
  filter: grayscale(1);
}

.slots-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem 0.85rem;
  margin-bottom: 0.65rem;
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.slots-legend span {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.dot {
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 999px;
  display: inline-block;
  flex-shrink: 0;
  background: #94a3b8;
}

.dot--red {
  background: #ef4444;
}

.dot--yellow {
  background: #facc15;
}

.dot--green {
  background: #22c55e;
}

.slots-teams {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.slots-team {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding: 0.65rem 0.7rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 32%, transparent);
  border-radius: 10px;
}

.slots-team__top {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
}

.slots-team__time {
  display: flex;
  align-items: center;
  gap: 0.4rem;
}

.slots-team__datetime {
  flex: 1 1 auto;
  min-width: 0;
  font-size: 0.875rem;
  border: 1px solid var(--color-border);
  border-radius: 6px;
  padding: 0.3rem 0.4rem;
  background: transparent;
}

.slots-tooltip {
  position: absolute;
  z-index: 30;
  top: calc(100% + 0.35rem);
  left: 0;
  width: min(20rem, 78vw);
  padding: 0.65rem 0.75rem;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
