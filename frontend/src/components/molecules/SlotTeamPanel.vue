<script lang="ts" setup>
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {programDisplayName, programNameForId} from '@/utils/eventPrograms'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {
  combineDateTime,
  datetimeLocalToDb,
  eventBaseDateYmd,
  wallTimeHm,
  wallTimeToDatetimeLocal,
} from '@/utils/extraBlockDateTime'

export type TeamRow = {
  row_key: string
  team_number_plan: number | null
  first_program: number
  start: string | null
  collision_status?: 'red' | 'yellow' | 'green' | null
  collision_gap_minutes?: number | null
}

export type TeamHoverData = {
  team_number_hot: string | null
  team_name: string | null
  slot_start: string | null
  slot_date: string | null
  activities: TeamActivityLine[]
}

export type TeamActivityLine = {
  id: number
  start: string
  end: string
  label: string
  status: 'red' | 'yellow' | 'green' | null
  gap_minutes: number | null
}

export type TeamSavePayload = {
  blockId: number
  first_program: number
  team_number_plan: number
  start: string | null
}

const JOINT_FP = 0

const props = withDefaults(defineProps<{
  planId: number
  blockId: number | null
  blockFirstProgram: number
  blockActive: boolean
  eventDate?: string
  eventDays?: number
  saving?: boolean
  /** Render inside slot block tile (no outer collapse toggle). */
  embedded?: boolean
}>(), {
  embedded: false,
})

const emit = defineEmits<{
  (e: 'save-assignments', payloads: TeamSavePayload[]): void
  (e: 'draft-changed', dirty: boolean): void
}>()

const teams = ref<TeamRow[]>([])
const loadingTeams = ref(false)
const loadError = ref<string | null>(null)
let loadTeamsSeq = 0
const eDurationTransfer = ref<number | null>(null)
const cDurationTransfer = ref<number | null>(null)
const expanded = ref(!props.embedded)
const chronoSorted = ref(false)
const draftStarts = ref<Record<string, string | null>>({})
const editingTeamId = ref<string | null>(null)
const editingStartLocal = ref('')
const tooltipHover = ref<Record<string, TeamHoverData | 'loading'>>({})

const {selectedEvent} = useScheduleWorkspace()

const isSingleDayEvent = computed(() => (props.eventDays ?? 1) <= 1)

function rowEditKey(row: TeamRow): string {
  return `${row.first_program}:${row.team_number_plan ?? 0}`
}

function defaultStartLocal(): string {
  return isSingleDayEvent.value ? '09:00' : `${eventBaseDateYmd(props.eventDate)}T09:00`
}

function localInputToDb(value: string): string | null {
  const v = value?.trim()
  if (!v) return null
  if (isSingleDayEvent.value) {
    if (v.includes('T')) return datetimeLocalToDb(v)
    return combineDateTime(eventBaseDateYmd(props.eventDate), v)
  }
  return datetimeLocalToDb(v)
}

function dbToLocalInput(start: string | null): string {
  if (!start) return ''
  return isSingleDayEvent.value ? wallTimeHm(start) : wallTimeToDatetimeLocal(start)
}

function resetDrafts() {
  draftStarts.value = {}
  chronoSorted.value = false
  editingTeamId.value = null
  editingStartLocal.value = ''
}

async function loadTeams() {
  if (!props.blockId) {
    teams.value = []
    loadError.value = null
    resetDrafts()
    return
  }
  const seq = ++loadTeamsSeq
  loadingTeams.value = true
  loadError.value = null
  try {
    const {data} = await axios.get<{
      teams: TeamRow[]
      e_duration_transfer?: number | null
      c_duration_transfer?: number | null
    }>(`/plans/${props.planId}/extra-blocks/slot/${props.blockId}/teams`)
    if (seq !== loadTeamsSeq) return
    teams.value = data?.teams ?? []
    eDurationTransfer.value = data?.e_duration_transfer ?? null
    cDurationTransfer.value = data?.c_duration_transfer ?? null
    resetDrafts()
  } catch {
    if (seq !== loadTeamsSeq) return
    teams.value = []
    loadError.value = 'Teams konnten nicht geladen werden.'
  } finally {
    if (seq === loadTeamsSeq) loadingTeams.value = false
  }
}

watch(() => props.blockId, () => {
  tooltipHover.value = {}
  void loadTeams()
}, {immediate: true})

defineExpose({
  reload: loadTeams,
  hasUnsavedDrafts: () => hasUnsavedChanges.value,
})

function effectiveStart(row: TeamRow): string | null {
  if (row.row_key in draftStarts.value) {
    return draftStarts.value[row.row_key]
  }
  return row.start
}

function isRowDirty(row: TeamRow): boolean {
  if (!(row.row_key in draftStarts.value)) return false
  const draft = draftStarts.value[row.row_key] ?? null
  const saved = row.start ?? null
  return draft !== saved
}

const hasUnsavedChanges = computed(() => teams.value.some(isRowDirty))

watch(hasUnsavedChanges, (dirty) => {
  emit('draft-changed', dirty)
}, {immediate: true})

function wallTimeSortKey(s: string | null): string {
  if (!s) return 'z'
  const m = String(s).match(/^(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/)
  return m ? `${m[1]}${m[2]}${m[3]}` : s
}

function compareRows(a: TeamRow, b: TeamRow): number {
  const aStart = effectiveStart(a)
  const bStart = effectiveStart(b)
  if (!aStart && !bStart) {
    if (a.first_program !== b.first_program) return a.first_program - b.first_program
    return (a.team_number_plan ?? 0) - (b.team_number_plan ?? 0)
  }
  if (!aStart) return 1
  if (!bStart) return -1
  const byTime = wallTimeSortKey(aStart).localeCompare(wallTimeSortKey(bStart))
  if (byTime !== 0) return byTime
  if (a.first_program !== b.first_program) return a.first_program - b.first_program
  return (a.team_number_plan ?? 0) - (b.team_number_plan ?? 0)
}

const displayRows = computed(() => {
  const rows = teams.value.slice()
  if (chronoSorted.value) {
    return rows.sort(compareRows)
  }
  return rows
})

const groupedRows = computed(() => {
  const rows = displayRows.value
  if (Number(props.blockFirstProgram) !== JOINT_FP) {
    return [{programId: Number(props.blockFirstProgram), label: groupLabel(Number(props.blockFirstProgram)), rows}]
  }
  const byProgram = new Map<number, TeamRow[]>()
  for (const row of rows) {
    const fp = row.first_program
    if (!byProgram.has(fp)) byProgram.set(fp, [])
    byProgram.get(fp)!.push(row)
  }
  return [...byProgram.entries()]
    .sort(([a], [b]) => a - b)
    .map(([programId, groupRows]) => ({
      programId,
      label: groupLabel(programId),
      rows: groupRows,
    }))
})

function groupLabel(programId: number): string {
  const name = programNameForId(selectedEvent.value, programId)
  return programDisplayName({first_program: programId, name: name ?? undefined}) || `Programm ${programId}`
}

function programIcon(fp: number): { src: string; alt: string } {
  const name = programNameForId(selectedEvent.value, fp)
  return {src: programLogoSrc(name), alt: programLogoAlt(name)}
}

function formatPlanTeamNo(n: number | null | undefined): string {
  if (n == null || !Number.isFinite(Number(n))) return '–'
  return `T${String(Math.floor(Number(n))).padStart(2, '0')}`
}

function collisionDotClass(row: TeamRow): string {
  if (isRowDirty(row)) return 'dot--draft'
  const status = row.collision_status
  if (status === 'red') return 'dot--red'
  if (status === 'yellow') return 'dot--yellow'
  if (status === 'green') return 'dot--green'
  return ''
}

function lineStatusClass(status: TeamActivityLine['status']): string {
  if (status === 'red') return 'text-red-700'
  if (status === 'yellow') return 'text-yellow-700'
  if (status === 'green') return 'text-green-700'
  return 'text-[var(--color-text-muted)]'
}

function collisionTitle(row: TeamRow): string {
  if (isRowDirty(row)) return 'Entwurf — speichern für Konfliktprüfung'
  if (!effectiveStart(row)) return 'Keine Startzeit zugewiesen'
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

function setDraft(row: TeamRow, localValue: string) {
  draftStarts.value = {
    ...draftStarts.value,
    [row.row_key]: localValue ? localInputToDb(localValue) : null,
  }
}

function beginEditStart(row: TeamRow) {
  if (!props.blockActive) return
  editingTeamId.value = rowEditKey(row)
  const current = effectiveStart(row)
  editingStartLocal.value = current ? dbToLocalInput(current) : defaultStartLocal()
  if (!current) {
    setDraft(row, editingStartLocal.value)
  }
}

function cancelEditStart(row: TeamRow) {
  if (editingTeamId.value === rowEditKey(row)) {
    editingTeamId.value = null
    editingStartLocal.value = ''
  }
}

function onTeamStartInput(row: TeamRow, value: string) {
  editingTeamId.value = rowEditKey(row)
  editingStartLocal.value = value
  setDraft(row, value)
}

function clearTeamStart(row: TeamRow) {
  setDraft(row, '')
  cancelEditStart(row)
}

function inputValue(row: TeamRow): string {
  if (editingTeamId.value === rowEditKey(row)) {
    return editingStartLocal.value
  }
  return dbToLocalInput(effectiveStart(row))
}

function showTimeInput(row: TeamRow): boolean {
  return !!effectiveStart(row) || editingTeamId.value === rowEditKey(row)
}

function saveAssignments() {
  if (!props.blockId || !hasUnsavedChanges.value) return
  const payloads: TeamSavePayload[] = teams.value
    .filter(isRowDirty)
    .map((row) => ({
      blockId: props.blockId!,
      first_program: row.first_program,
      team_number_plan: row.team_number_plan!,
      start: draftStarts.value[row.row_key] ?? null,
    }))
  emit('save-assignments', payloads)
}

async function openTeamHover(row: TeamRow) {
  if (!props.blockId || !row.team_number_plan) return
  const key = row.row_key
  if (tooltipHover.value[key] && tooltipHover.value[key] !== 'loading') return

  tooltipHover.value = {...tooltipHover.value, [key]: 'loading'}
  try {
    const {data} = await axios.get<{
      team_number_hot: string | null
      team_name: string | null
      slot_start: string | null
      slot_date: string | null
      activities: TeamActivityLine[]
    }>(
      `/plans/${props.planId}/extra-blocks/slot/${props.blockId}/teams/${row.first_program}/${row.team_number_plan}/activities`,
    )
    tooltipHover.value = {
      ...tooltipHover.value,
      [key]: {
        team_number_hot: data?.team_number_hot ?? null,
        team_name: data?.team_name ?? null,
        slot_start: data?.slot_start ?? null,
        slot_date: data?.slot_date ?? null,
        activities: data?.activities ?? [],
      },
    }
  } catch {
    tooltipHover.value = {
      ...tooltipHover.value,
      [key]: {
        team_number_hot: null,
        team_name: null,
        slot_start: null,
        slot_date: null,
        activities: [],
      },
    }
  }
}

function hoverData(row: TeamRow): TeamHoverData | null {
  const v = tooltipHover.value[row.row_key]
  return v && v !== 'loading' ? v : null
}

function isHoverLoading(row: TeamRow): boolean {
  return tooltipHover.value[row.row_key] === 'loading'
}

function formatTooltipDate(slotDate: string | null): string {
  if (!slotDate) return 'ohne Datum'
  const m = String(slotDate).match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return String(slotDate)
  return `${m[3]}.${m[2]}.${m[1]}`
}
</script>

<template>
  <div
      class="slot-teams"
      :class="{
        'slot-teams--inactive': !blockActive,
        'slot-teams--embedded': embedded,
      }"
  >
    <div v-if="!embedded" class="slot-teams__toolbar">
      <button
          type="button"
          class="slot-teams__toggle glass-btn-secondary !text-xs !py-1.5 !px-2.5"
          :disabled="!blockId"
          @click="expanded = !expanded"
      >
        <i class="bi" :class="expanded ? 'bi-chevron-down' : 'bi-chevron-right'" aria-hidden="true"/>
        Team-Zuordnungen
        <span v-if="hasUnsavedChanges" class="slot-teams__dirty">· Entwurf</span>
      </button>
      <div class="slot-teams__actions">
        <button
            type="button"
            class="glass-btn-secondary !text-xs !py-1.5 !px-2.5"
            :disabled="!blockId || !teams.length"
            @click="chronoSorted = !chronoSorted"
        >
          <i class="bi bi-sort-down" aria-hidden="true"/>
          {{ chronoSorted ? 'Plan-Reihenfolge' : 'Chronologisch' }}
        </button>
        <button
            type="button"
            class="glass-btn-accent !text-xs !py-1.5 !px-2.5 disabled:opacity-60"
            :disabled="!blockId || !hasUnsavedChanges || saving || !blockActive"
            @click="saveAssignments"
        >
          {{ saving ? 'Speichere…' : 'Zuordnungen speichern' }}
        </button>
      </div>
    </div>

    <div v-if="embedded || expanded" class="slot-teams__body">
      <div v-if="embedded" class="slot-teams__toolbar slot-teams__toolbar--embedded">
        <div class="slot-teams__actions">
          <button
              type="button"
              class="glass-btn-secondary !text-xs !py-1.5 !px-2.5"
              :disabled="!blockId || !teams.length"
              @click="chronoSorted = !chronoSorted"
          >
            <i class="bi bi-sort-down" aria-hidden="true"/>
            {{ chronoSorted ? 'Plan-Reihenfolge' : 'Chronologisch' }}
          </button>
          <button
              type="button"
              class="glass-btn-accent !text-xs !py-1.5 !px-2.5 disabled:opacity-60"
              :disabled="!blockId || !hasUnsavedChanges || saving || !blockActive"
              @click="saveAssignments"
          >
            {{ saving ? 'Speichere…' : 'Zuordnungen speichern' }}
          </button>
        </div>
      </div>

      <div class="slot-teams__legend">
        <span><i class="dot dot--red"/>Konflikt</span>
        <span
            v-if="eDurationTransfer != null || cDurationTransfer != null"
        ><i class="dot dot--yellow"/>Transfer &lt;<template v-if="eDurationTransfer != null"> E{{ eDurationTransfer }}</template><template v-if="eDurationTransfer != null && cDurationTransfer != null">/</template><template v-if="cDurationTransfer != null"> C{{ cDurationTransfer }}</template></span>
        <span><i class="dot dot--green"/>OK</span>
        <span><i class="dot dot--draft"/>Entwurf</span>
      </div>

      <div v-if="loadingTeams" class="slot-teams__loading">
        <LoaderFlow class="scale-75"/>
        <span class="text-sm">Lade Teams…</span>
      </div>

      <div v-else-if="loadError" class="text-sm text-red-700 py-2">
        {{ loadError }}
      </div>

      <div v-else-if="!blockId" class="text-sm text-[var(--color-text-subtle)]">
        Slot zuerst speichern, dann Teams zuordnen.
      </div>

      <div v-else-if="!teams.length" class="text-sm text-[var(--color-text-subtle)] py-4 text-center">
        Keine Teams im Plan für diesen Slot-Typ.
      </div>

      <template v-else>
        <section
            v-for="group in groupedRows"
            :key="group.programId"
            class="slot-teams__group"
        >
          <h3
              v-if="groupedRows.length > 1"
              class="slot-teams__group-heading"
          >
            <img
                :src="programIcon(group.programId).src"
                :alt="programIcon(group.programId).alt"
                class="w-5 h-5"
            />
            {{ group.label }}
          </h3>

          <div class="slot-teams__list">
            <div v-for="row in group.rows" :key="row.row_key" class="slot-team slot-team--compact">
              <div
                  class="slot-team__label-wrap"
                  @mouseenter="openTeamHover(row)"
              >
                <span class="slot-team__plan-no tabular-nums">
                  {{ formatPlanTeamNo(row.team_number_plan) }}
                </span>
                <div class="slot-team__hover glass-dropdown">
                  <p v-if="isHoverLoading(row)" class="text-xs text-[var(--color-text-subtle)]">Lade…</p>
                  <template v-else-if="hoverData(row)">
                    <p class="slot-team__hover-name">{{ hoverData(row)!.team_name || '–' }}</p>
                    <p v-if="hoverData(row)!.team_number_hot" class="slot-team__hover-hot tabular-nums">
                      DRAHT {{ hoverData(row)!.team_number_hot }}
                    </p>
                    <hr class="slot-team__hover-divider">
                    <p class="text-xs font-semibold mb-1">
                      <template v-if="isSingleDayEvent">Aktivitäten</template>
                      <template v-else>
                        Aktivitäten · {{ formatTooltipDate(hoverData(row)!.slot_date) }}
                      </template>
                    </p>
                    <p
                        v-if="!hoverData(row)!.activities.length"
                        class="text-xs text-[var(--color-text-subtle)]"
                    >
                      Keine team-spezifischen Aktivitäten.
                    </p>
                    <ul v-else class="slot-team__hover-acts">
                      <li
                          v-for="act in hoverData(row)!.activities"
                          :key="act.id"
                          class="text-xs"
                          :class="lineStatusClass(act.status)"
                      >
                        <span class="font-mono">{{ wallTimeHm(act.start) }}-{{ wallTimeHm(act.end) }}</span>
                        · {{ act.label }}
                      </li>
                    </ul>
                  </template>
                </div>
              </div>

              <span
                  class="dot slot-team__dot"
                  :class="[
                    collisionDotClass(row),
                    {'dot--empty': !effectiveStart(row) && !isRowDirty(row)},
                  ]"
                  :title="effectiveStart(row) || isRowDirty(row) ? collisionTitle(row) : ''"
              />

              <div class="slot-team__time">
                <button
                    v-if="!showTimeInput(row)"
                    type="button"
                    class="slot-team__set glass-btn-secondary !text-xs !py-1 !px-2"
                    :disabled="!blockActive"
                    @click="beginEditStart(row)"
                >
                  <i class="bi bi-clock" aria-hidden="true"/>
                </button>
                <template v-else>
                  <input
                      :type="isSingleDayEvent ? 'time' : 'datetime-local'"
                      :disabled="!blockActive"
                      class="slot-team__datetime glass-input glass-input--sm liquid-surface-control"
                      :class="{'slot-team__datetime--dirty': isRowDirty(row)}"
                      :value="inputValue(row)"
                      min="00:05"
                      max="23:55"
                      step="300"
                      @input="onTeamStartInput(row, ($event.target as HTMLInputElement).value)"
                  />
                  <IconDangerButton
                      v-if="effectiveStart(row)"
                      label="Zuweisung entfernen"
                      :disabled="!blockActive"
                      @click="clearTeamStart(row)"
                  />
                </template>
              </div>
            </div>
          </div>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.slot-teams {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.slot-teams--embedded {
  gap: 0.45rem;
}

.slot-teams__toolbar--embedded {
  justify-content: flex-end;
  margin-bottom: 0.15rem;
}

.slot-teams__toolbar--embedded .slot-teams__actions {
  width: 100%;
  justify-content: flex-end;
}

.slot-teams--inactive {
  opacity: 0.6;
}

.slot-teams__toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.slot-teams__toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.slot-teams__dirty {
  color: var(--color-accent);
  font-weight: 600;
}

.slot-teams__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.slot-teams__body {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.slot-teams__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 1rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.slot-teams__legend > span {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}

.slot-teams__loading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-text-subtle);
  padding: 1rem 0;
}

.slot-teams__group {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.slot-teams__group-heading {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  margin: 0;
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.slot-teams__list {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
}

.slot-team--compact {
  display: grid;
  grid-template-columns: 3.25rem auto 1fr;
  align-items: center;
  gap: 0.45rem;
  padding: 0.3rem 0.45rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  border-radius: 8px;
}

.slot-team__label-wrap {
  position: relative;
  justify-self: start;
}

.slot-team__plan-no {
  font-size: 0.82rem;
  font-weight: 700;
  color: var(--color-text);
  text-decoration: underline;
  text-decoration-style: dotted;
  text-underline-offset: 2px;
  cursor: default;
}

.slot-team__label-wrap:hover .slot-team__plan-no {
  color: var(--color-accent);
}

.slot-team__hover {
  display: none;
  position: absolute;
  z-index: 30;
  left: 0;
  top: calc(100% + 4px);
  min-width: 14rem;
  max-width: 20rem;
  padding: 0.55rem 0.65rem;
}

.slot-team__label-wrap:hover .slot-team__hover {
  display: block;
}

.slot-team__hover-name {
  margin: 0 0 0.15rem;
  font-size: 0.82rem;
  font-weight: 600;
  line-height: 1.3;
}

.slot-team__hover-hot {
  margin: 0;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.slot-team__hover-divider {
  margin: 0.45rem 0;
  border: none;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
}

.slot-team__hover-acts {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}

.slot-team__dot {
  justify-self: center;
}

.slot-team__time {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
}

.slot-team__set {
  min-width: 2rem;
  justify-content: center;
}

.slot-team__datetime {
  flex: 1;
  min-width: 0;
}

.slot-team__datetime--dirty {
  border-color: color-mix(in srgb, var(--color-accent) 55%, var(--color-border));
}

.dot {
  display: inline-block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  flex-shrink: 0;
}

.dot--red { background: #dc2626; }
.dot--yellow { background: #ca8a04; }
.dot--green { background: #16a34a; }
.dot--draft {
  background: transparent;
  border: 2px solid var(--color-accent);
}

.dot--empty {
  visibility: hidden;
}
</style>
