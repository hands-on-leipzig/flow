<script lang="ts" setup>
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import LoaderFlow from '@/components/atoms/LoaderFlow.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {programNameForId} from '@/utils/eventPrograms'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {
  datetimeLocalToDb,
  eventBaseDateYmd,
  wallTimeHm,
  wallTimeToDatetimeLocal,
} from '@/utils/extraBlockDateTime'

export type TeamRow = {
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

const props = defineProps<{
  planId: number
  blockId: number | null
  blockActive: boolean
  eventDate?: string
}>()

const emit = defineEmits<{
  (e: 'schedule-team-save', payload: TeamSavePayload): void
}>()

const teams = ref<TeamRow[]>([])
const loadingTeams = ref(false)
const eDurationTransfer = ref(0)
const cDurationTransfer = ref(0)
const editingTeamId = ref<string | null>(null)
const editingStartLocal = ref('')
const tooltipOpenKey = ref<string | null>(null)
const tooltipLoadingKey = ref<string | null>(null)
const tooltipActivities = ref<Record<string, {
  slot_start: string | null
  slot_date: string | null
  activities: TeamActivityLine[]
}>>({})

function rowEditKey(row: TeamRow): string {
  return `${row.first_program}:${row.team_number_plan ?? 0}`
}

function defaultStartLocal(): string {
  return `${eventBaseDateYmd(props.eventDate)}T09:00`
}

async function loadTeams() {
  if (!props.blockId) {
    teams.value = []
    return
  }
  loadingTeams.value = true
  try {
    const {data} = await axios.get<{
      teams: TeamRow[]
      e_duration_transfer?: number
      c_duration_transfer?: number
    }>(`/plans/${props.planId}/extra-blocks/slot/${props.blockId}/teams`)
    teams.value = data?.teams ?? []
    eDurationTransfer.value = Number(data?.e_duration_transfer ?? 0) || 0
    cDurationTransfer.value = Number(data?.c_duration_transfer ?? 0) || 0
  } finally {
    loadingTeams.value = false
  }
}

watch(() => props.blockId, () => {
  tooltipActivities.value = {}
  tooltipOpenKey.value = null
  editingTeamId.value = null
  void loadTeams()
}, {immediate: true})

defineExpose({reload: loadTeams})

const {selectedEvent} = useScheduleWorkspace()

function programIcon(fp: number): { src: string; alt: string } {
  const name = programNameForId(selectedEvent.value, fp)
  return {src: programLogoSrc(name), alt: programLogoAlt(name)}
}

function formatPlanTeamNo(n: number | null | undefined): string {
  if (n == null || !Number.isFinite(Number(n))) return '–'
  return `T${String(Math.floor(Number(n))).padStart(2, '0')}`
}

function collisionDotClass(status: TeamRow['collision_status']): string {
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

function beginEditStart(row: TeamRow) {
  if (!props.blockActive) return
  editingTeamId.value = rowEditKey(row)
  editingStartLocal.value = row.start ? wallTimeToDatetimeLocal(row.start) : defaultStartLocal()
  if (!row.start) {
    scheduleTeamSave(row, editingStartLocal.value)
  }
}

function cancelEditStart(row: TeamRow) {
  if (editingTeamId.value === rowEditKey(row)) {
    editingTeamId.value = null
    editingStartLocal.value = ''
  }
}

function scheduleTeamSave(row: TeamRow, value: string) {
  if (!props.blockId || !row.team_number_plan) return
  emit('schedule-team-save', {
    blockId: props.blockId,
    first_program: row.first_program,
    team_number_plan: row.team_number_plan,
    start: value ? datetimeLocalToDb(value) : null,
  })
}

function onTeamStartInput(row: TeamRow, value: string) {
  editingTeamId.value = rowEditKey(row)
  editingStartLocal.value = value
  scheduleTeamSave(row, value)
}

function clearTeamStart(row: TeamRow) {
  scheduleTeamSave(row, '')
  row.start = null
  row.collision_status = null
  row.collision_gap_minutes = null
  cancelEditStart(row)
}

async function openTooltip(row: TeamRow) {
  if (!props.blockId) return
  const key = row.row_key
  tooltipOpenKey.value = key
  if (tooltipActivities.value[key]) return

  tooltipLoadingKey.value = key
  try {
    const {data} = await axios.get<{
      slot_start: string | null
      slot_date: string | null
      activities: TeamActivityLine[]
    }>(
      `/plans/${props.planId}/extra-blocks/slot/${props.blockId}/teams/${row.first_program}/${row.team_number_plan}/activities`,
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
      [key]: {slot_start: null, slot_date: null, activities: []},
    }
  } finally {
    if (tooltipLoadingKey.value === key) tooltipLoadingKey.value = null
  }
}

function closeTooltip(row: TeamRow) {
  if (tooltipOpenKey.value === row.row_key) tooltipOpenKey.value = null
}

function formatTooltipDate(slotDate: string | null): string {
  if (!slotDate) return 'ohne Datum'
  const m = String(slotDate).match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (!m) return String(slotDate)
  return `${m[3]}.${m[2]}.${m[1]}`
}

const hasTeams = computed(() => teams.value.length > 0)
</script>

<template>
  <div class="slot-teams" :class="{'slot-teams--inactive': !blockActive}">
    <div class="slot-teams__legend">
      <span><i class="dot dot--red"/>Konflikt</span>
      <span><i class="dot dot--yellow"/>Transfer &lt; E{{ eDurationTransfer }}/C{{ cDurationTransfer }}</span>
      <span><i class="dot dot--green"/>OK</span>
    </div>

    <div v-if="loadingTeams" class="slot-teams__loading">
      <LoaderFlow class="scale-75"/>
      <span class="text-sm">Lade Teams…</span>
    </div>

    <div v-else-if="!blockId" class="text-sm text-[var(--color-text-subtle)]">
      Oben einen Slot auswählen.
    </div>

    <div v-else-if="!hasTeams" class="text-sm text-[var(--color-text-subtle)] py-4 text-center">
      Keine Teams im Plan für diesen Slot-Typ.
    </div>

    <div v-else class="slot-teams__list">
      <div v-for="row in teams" :key="row.row_key" class="slot-team">
        <div class="slot-team__top">
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
                <div v-if="tooltipOpenKey === row.row_key" class="slot-tooltip glass-dropdown">
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

        <div class="slot-team__time">
          <button
              v-if="!row.start && editingTeamId !== rowEditKey(row)"
              type="button"
              class="glass-btn-secondary w-full !justify-start !text-sm !py-1.5 !text-[var(--color-text-subtle)]"
              :disabled="!blockActive"
              @click="beginEditStart(row)"
          >
            <i class="bi bi-clock" aria-hidden="true"/>
            Start setzen…
          </button>
          <template v-else>
            <input
                type="datetime-local"
                :disabled="!blockActive"
                class="slot-team__datetime glass-input glass-input--sm liquid-surface-control"
                :value="editingTeamId === rowEditKey(row) ? editingStartLocal : wallTimeToDatetimeLocal(row.start)"
                @input="onTeamStartInput(row, ($event.target as HTMLInputElement).value)"
            />
            <button
                v-if="row.start"
                type="button"
                class="slot-team__clear"
                :disabled="!blockActive"
                title="Zuweisung entfernen"
                @click="clearTeamStart(row)"
            >
              <i class="bi bi-trash-fill" aria-hidden="true"/>
            </button>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.slot-teams {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.slot-teams--inactive {
  opacity: 0.6;
}

.slot-teams__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem 1rem;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.slot-teams__loading {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--color-text-subtle);
  padding: 1rem 0;
}

.slot-teams__list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.slot-team {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  padding: 0.55rem 0.65rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  border-radius: 10px;
}

.slot-team__top {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
}

.slot-team__time {
  display: flex;
  align-items: center;
  gap: 0.45rem;
}

.slot-team__datetime {
  flex: 1;
  min-width: 0;
}

.slot-team__clear {
  flex-shrink: 0;
  padding: 0.35rem 0.5rem;
  border: none;
  background: transparent;
  color: var(--color-danger, #dc2626);
  cursor: pointer;
  border-radius: 6px;
}

.slot-team__clear:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.slot-tooltip {
  position: absolute;
  z-index: 30;
  left: 0;
  top: calc(100% + 4px);
  min-width: 14rem;
  max-width: 20rem;
  padding: 0.55rem 0.65rem;
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
</style>
