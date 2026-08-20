<script lang="ts" setup>
import {computed, UnwrapRef, watch, watchEffect} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import TeamPlanBar from "@/components/molecules/TeamPlanBar.vue";
import {useEventStore} from '@/stores/event'
import ProgramSection from '@/components/atoms/ProgramSection.vue'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const props = defineProps<{
  parameters: any[]
  lanesIndex?: LanesIndex | UnwrapRef<LanesIndex> | null
  supportedPlanData?: any[] | null
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
}>()

// No need to expose anything - parent handles all batching

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

// Simple parameter update - emit immediately to parent for batching
function updateByName(name: string, value: any) {
  emit('update-param', {name, value})
}

// Inputs
const cTeams = computed(() => Number(paramMapByName.value['c_teams']?.value || 0))
const rTables = computed(() => Number(paramMapByName.value['r_tables']?.value || 0) || 0)

// ---- Allowed TABLE VARIANTS (2 / 4) for the current team count ----
const tableVariantsForTeams = computed<number[]>(() => {
  const idx = props.lanesIndex?.challenge ?? {}
  const t = cTeams.value

  // If no teams selected yet, allow both table options
  if (!t) return [2, 4]

  const variants: number[] = []
  if (idx[`${t}|2`]?.length) variants.push(2)
  if (idx[`${t}|4`]?.length) variants.push(4)
  return variants
})

// ---- Allowed LANES for current selection (merge if tables not chosen yet) ----
const allowedJuryLanes = computed<number[]>(() => {
  const idx = props.lanesIndex?.challenge ?? {}
  const t = cTeams.value

  // If no teams selected yet, allow common jury group options (1-6)
  if (!t) return [1, 2, 3, 4, 5, 6]

  const variants = rTables.value ? [rTables.value] : [2, 4]
  const merged = variants.flatMap(tb => idx[`${t}|${tb}`] || [])
  return Array.from(new Set(merged)).sort((a, b) => a - b)
})

// Proxies
const jLanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['j_lanes']?.value || 0),
  set: (val) => updateByName('j_lanes', val)
})

function ensureJuryLanesForSelection(tablesOverride?: number) {
  const t = cTeams.value
  if (!t || !props.lanesIndex) return

  const tables = tablesOverride ?? rTables.value
  const allowed = tables
      ? (props.lanesIndex.challenge[`${t}|${tables}`] || []).slice().sort((a: number, b: number) => a - b)
      : allowedJuryLanes.value

  if (!allowed.length) return

  const curLane = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (!allowed.includes(curLane)) {
    updateByName('j_lanes', allowed[0])
  }
}

function selectTables(tables: number) {
  updateByName('r_tables', tables)
  ensureJuryLanesForSelection(tables)
}

// ---- Invariant keeper: keep a valid (tables, lanes) combo at all times ----
watchEffect(() => {
  const t = cTeams.value
  if (!t || !props.lanesIndex) return

  const variants = tableVariantsForTeams.value
  // If no variants for this team count, nothing to choose from
  if (variants.length === 0) return

  const currentTables = rTables.value

  // 1) If tables unset and exactly one variant exists -> snap to it
  if (currentTables === 0 && variants.length === 1) {
    updateByName('r_tables', variants[0])
    ensureJuryLanesForSelection(variants[0])
    return
  }

  // 2) If current tables invalid -> move to first valid variant and set a valid lane
  if (currentTables !== 0 && !variants.includes(currentTables)) {
    const nextTables = variants[0]
    updateByName('r_tables', nextTables)
    ensureJuryLanesForSelection(nextTables)
    return
  }

  // 3) Ensure current lane is valid for the (possibly merged) allowed set
  ensureJuryLanesForSelection()
})

// If allowed set changes (due to teams/tables), snap lanes if invalid
watch(allowedJuryLanes, (opts) => {
  const cur = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (opts.length && !opts.includes(cur)) updateByName('j_lanes', opts[0])
})

// When lanes index arrives after Challenge was already enabled, fill missing j_lanes
watch(
    () => props.lanesIndex,
    (idx) => {
      if (!idx || !cTeams.value) return
      ensureJuryLanesForSelection()
    }
)

// Helpers
const isLaneAllowed = (n: number) => allowedJuryLanes.value.includes(n)

// For display, you can cap to 1..7; lanesIndex usually dictates the set anyway
const lanePalette = computed(() => {
  // Show a consistent row (e.g. 1..7) and disable those not allowed:
  const max = Math.max(5, ...allowedJuryLanes.value)
  return Array.from({length: Math.min(7, max)}, (_, i) => i + 1)
})

const rTablesProxy = computed<number>({
  get: () => Number(paramMapByName.value['r_tables']?.value || 0),
  set: (val) => updateByName('r_tables', val)
})

// Key helpers for challenge (teams|tables)
const cKey = computed(() => {
  const t = cTeams.value
  const tb = rTables.value || 0
  return t ? `${t}|${tb}` : ''
})

// Is a lane recommended for the current selection?
const isLaneRecommended = (lane: number) => {
  if (!props.lanesIndex || !cKey.value) return false
  // if tables not chosen yet (tb=0), recommendation is ambiguous; treat as false
  if (!rTables.value) return false
  const meta = props.lanesIndex.metaChallenge[cKey.value]
  return !!meta?.[lane]?.recommended
}

// Note for the current EXACT combo from database data
const currentLaneNote = computed<string | undefined>(() => {
  if (!props.supportedPlanData || !cTeams.value || !rTables.value || !jLanesProxy.value) return

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 3 &&
      plan.teams === cTeams.value &&
      plan.tables === rTables.value &&
      plan.lanes === jLanesProxy.value
  )

  return matchingPlan?.note
})

// Get current configuration alert level from database data
const currentConfigAlertLevel = computed<number>(() => {
  if (!props.supportedPlanData || !cTeams.value || !rTables.value || !jLanesProxy.value) return 0

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 3 &&
      plan.teams === cTeams.value &&
      plan.tables === rTables.value &&
      plan.lanes === jLanesProxy.value
  )

  return matchingPlan?.alert_level || 0
})


// Calculate min/max team counts from supported plan data
const challengeTeamLimits = computed(() => {
  if (!props.supportedPlanData) return {min: 1, max: 50}

  const challengePlans = props.supportedPlanData.filter(plan => plan.first_program === 3)
  if (challengePlans.length === 0) return {min: 1, max: 50}

  const teamCounts = challengePlans.map(plan => plan.teams)
  return {
    min: Math.min(...teamCounts),
    max: Math.max(...teamCounts)
  }
})

// Alert level styling and messages
const getAlertLevelStyle = (level: number) => {
  switch (level) {
    case 1:
      return 'glass-choice--active' // recommended → program accent via ProgramSection
    case 2:
      return 'border-amber-400 bg-amber-50 text-amber-900'
    case 3:
      return 'border-red-400 bg-red-50 text-red-900'
    default:
      return 'glass-choice--active'
  }
}


const planTeams = computed(() => Number(paramMapByName.value['c_teams']?.value || 0))
const registeredTeams = computed(() => Number(event.value?.drahtTeamsChallenge || 0))
const capacity = computed(() => Number(event.value?.drahtCapacityChallenge || 0))

const teamsPerJuryHint = computed(() => {
  const teams = Number(paramMapByName.value['c_teams']?.value ?? 0)
  const lanes = Number(paramMapByName.value['j_lanes']?.value ?? 1) // garantiert >0

  const lo = Math.floor(teams / lanes)
  const hi = Math.ceil(teams / lanes)

  return lo === hi
      ? `${lo} Teams pro Gruppe`
      : `${lo} bis ${hi} Teams pro Gruppe`
})

</script>

<template>
  <ProgramSection program="challenge">
    <TeamPlanBar
        :plan-teams="planTeams"
        :registered-teams="registeredTeams"
        :capacity="capacity"
        :min-teams="challengeTeamLimits.min"
        :max-teams="challengeTeamLimits.max"
        :on-update="(value) => updateByName('c_teams', value)"
    />

      <!-- Jury lanes -->
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-1 min-w-0">
          <span class="glass-settings-label">{{ paramMapByName['j_lanes']?.ui_label }}</span>
          <InfoPopover :text="paramMapByName['j_lanes']?.ui_description"/>
        </div>
        <div class="glass-settings-row">
          <RadioGroup v-model="jLanesProxy" class="flex gap-1.5 flex-wrap shrink-0">
            <RadioGroupOption
                v-for="n in lanePalette"
                :key="'j_lane_' + n"
                v-slot="{ checked, disabled }"
                :disabled="!isLaneAllowed(n)"
                :value="n"
                as="template"
            >
              <button
                  :aria-disabled="disabled"
                  :class="[
                    'glass-choice relative whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1',
                    checked ? (getAlertLevelStyle(currentConfigAlertLevel) || 'glass-choice--active') : '',
                    disabled ? 'opacity-40 cursor-not-allowed' : '',
                    (!disabled && isLaneRecommended(n)) ? 'choice-recommended' : ''
                  ]"
                  type="button"
                  @click="!disabled && updateByName('j_lanes', n)"
              >
                {{ n }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
          <span class="glass-settings-hint whitespace-nowrap">
            {{ teamsPerJuryHint }}
          </span>
        </div>

        <p v-if="cTeams && allowedJuryLanes.length === 0" class="glass-settings-hint mt-1.5 !not-italic">
          Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
        </p>
      </div>


      <!-- Robot game tables -->
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-1 min-w-0">
          <span class="glass-settings-label">{{ paramMapByName['r_tables']?.ui_label }}</span>
          <InfoPopover :text="paramMapByName['r_tables']?.ui_description"/>
        </div>
        <div class="glass-settings-row">
          <RadioGroup v-model="rTablesProxy" class="flex gap-1.5 flex-wrap">
            <RadioGroupOption
                v-for="tb in [2,4]"
                :key="'tables_' + tb"
                v-slot="{ checked, disabled }"
                :disabled="tableVariantsForTeams.length > 0 && !tableVariantsForTeams.includes(tb)"
                :value="tb"
                as="template"
            >
              <button
                  :aria-disabled="disabled"
                  :class="[
                    'glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1',
                    checked ? (getAlertLevelStyle(currentConfigAlertLevel) || 'glass-choice--active') : '',
                    disabled ? 'opacity-40 cursor-not-allowed' : '',
                  ]"
                  type="button"
                  @click="!disabled && selectTables(tb)"
              >
                {{ tb }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
        </div>
      </div>

      <!-- Alert message banner -->
      <div
          v-if="currentLaneNote && currentConfigAlertLevel >= 1"
          class="program-note"
          :class="{
            'program-note--ok': currentConfigAlertLevel === 1,
            'program-note--warn': currentConfigAlertLevel === 2,
            'program-note--error': currentConfigAlertLevel === 3,
          }"
      >
        <i
            class="bi"
            :class="currentConfigAlertLevel === 1 ? 'bi-check-circle' : 'bi-exclamation-triangle'"
            aria-hidden="true"
        />
        <span>{{ currentLaneNote }}</span>
      </div>
  </ProgramSection>
</template>

<style scoped>
.program-note {
  display: flex;
  align-items: flex-start;
  gap: 0.55rem;
  margin-top: 0.15rem;
  padding: 0.65rem 0.8rem;
  border-radius: 10px;
  font-size: 0.875rem;
  font-weight: 550;
  line-height: 1.35;
}

.program-note .bi {
  margin-top: 0.1rem;
  flex-shrink: 0;
}

.program-note--ok {
  color: #166534;
  background: color-mix(in srgb, #22c55e 12%, transparent);
  border: 1px solid color-mix(in srgb, #22c55e 28%, transparent);
}

.program-note--warn {
  color: #9a3412;
  background: color-mix(in srgb, #f59e0b 14%, transparent);
  border: 1px solid color-mix(in srgb, #f59e0b 30%, transparent);
}

.program-note--error {
  color: #991b1b;
  background: color-mix(in srgb, #ef4444 12%, transparent);
  border: 1px solid color-mix(in srgb, #ef4444 28%, transparent);
}

.choice-recommended {
  position: relative;
}

.choice-recommended::after {
  content: 'Empfohlen';
  position: absolute;
  top: -0.55rem;
  right: -0.35rem;
  padding: 0.1rem 0.4rem;
  border-radius: 999px;
  font-size: 0.62rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: #166534;
  background: #dcfce7;
  border: 1px solid color-mix(in srgb, #22c55e 35%, transparent);
  pointer-events: none;
  white-space: nowrap;
}
</style>