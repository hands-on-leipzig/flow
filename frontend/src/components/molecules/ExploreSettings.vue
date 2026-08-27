<script lang="ts" setup>
import {computed, onDeactivated, ref, watch, type UnwrapRef} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import TeamPlanBar from '@/components/molecules/TeamPlanBar.vue'
import TeamSplitBar from '@/components/molecules/TeamSplitBar.vue'
import CapacityOverrideDialog from '@/components/atoms/CapacityOverrideDialog.vue'
import SupportedPlansDialog from '@/components/atoms/SupportedPlansDialog.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import {useEventStore} from '@/stores/event'
import {eventPrograms, programId} from '@/utils/eventPrograms'

const PROGRAM_ID = 2

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

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

function updateByName(name: string, value: any) {
  emit('update-param', {name, value})
}

const eTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const e1Teams = computed(() => Number(paramMapByName.value['e1_teams']?.value || 0))
const e2Teams = computed(() => Number(paramMapByName.value['e2_teams']?.value || 0))
const exploreIndex = computed(() => props.lanesIndex?.explore ?? {})

function setSplit(nextE1: number) {
  const total = eTeams.value
  const left = Math.min(total, Math.max(0, Math.round(nextE1)))
  const right = total - left
  if (left !== e1Teams.value) updateByName('e1_teams', left)
  if (right !== e2Teams.value) updateByName('e2_teams', right)
}

watch(eTeams, (total) => {
  if (e1Teams.value + e2Teams.value === total) return
  if (e1Teams.value + e2Teams.value === 0 && total > 0) {
    setSplit(Math.floor(total / 2))
    return
  }
  if (e1Teams.value === 0) {
    setSplit(0)
    return
  }
  if (e2Teams.value === 0) {
    setSplit(total)
    return
  }
  setSplit(e1Teams.value)
}, {immediate: true})

function allowedLanesFor(teams: number): number[] {
  if (!teams) return [1, 2, 3, 4, 5]
  return (exploreIndex.value[`${teams}`] || []).slice().sort((a: number, b: number) => a - b)
}

function lanePaletteFor(allowed: number[]): number[] {
  const max = Math.max(5, ...allowed)
  return Array.from({length: Math.min(7, max)}, (_, i) => i + 1)
}

function teamsPerGroupHint(teams: number, lanes: number): string {
  const n = lanes || 1
  const lo = Math.floor(teams / n)
  const hi = Math.ceil(teams / n)
  return lo === hi
      ? `${lo} Teams pro Gruppe`
      : `${lo} bis ${hi} Teams pro Gruppe`
}

function matchingPlanFor(teams: number, lanes: number) {
  if (!props.supportedPlanData || !teams || !lanes) return
  return props.supportedPlanData.find((plan: any) =>
      plan.first_program === PROGRAM_ID &&
      plan.teams === teams &&
      plan.lanes === lanes
  )
}

function snapLanes(param: 'e1_lanes' | 'e2_lanes', teams: number) {
  if (!teams) return
  const allowed = allowedLanesFor(teams)
  if (!allowed.length) return
  const cur = Number(paramMapByName.value[param]?.value || 0)
  if (!allowed.includes(cur)) updateByName(param, allowed[0])
}

watch(
    [e1Teams, e2Teams, exploreIndex],
    () => {
      if (e1Teams.value > 0) {
        snapLanes('e1_lanes', e1Teams.value)
      } else if (Number(paramMapByName.value['e1_lanes']?.value || 0) !== 0) {
        updateByName('e1_lanes', 0)
      }

      if (e2Teams.value > 0) {
        snapLanes('e2_lanes', e2Teams.value)
      } else if (Number(paramMapByName.value['e2_lanes']?.value || 0) !== 0) {
        updateByName('e2_lanes', 0)
      }
    },
    {immediate: true}
)

const laneGroups = computed(() => {
  const both = e1Teams.value > 0 && e2Teams.value > 0
  const groups: Array<{
    key: string
    param: 'e1_lanes' | 'e2_lanes'
    teams: number
    label: string
    description: string | undefined
    value: number
    allowed: number[]
    palette: number[]
    hint: string
    note: string | undefined
    alertLevel: number
  }> = []

  if (e1Teams.value > 0) {
    const allowed = allowedLanesFor(e1Teams.value)
    const value = Number(paramMapByName.value['e1_lanes']?.value || 0)
    const plan = matchingPlanFor(e1Teams.value, value)
    const base = paramMapByName.value['e1_lanes']?.ui_label || ''
    groups.push({
      key: 'am',
      param: 'e1_lanes',
      teams: e1Teams.value,
      label: both ? `${base} - Vormittag` : base,
      description: paramMapByName.value['e1_lanes']?.ui_description,
      value,
      allowed,
      palette: lanePaletteFor(allowed),
      hint: teamsPerGroupHint(e1Teams.value, value),
      note: plan?.note,
      alertLevel: plan?.alert_level || 0,
    })
  }

  if (e2Teams.value > 0) {
    const allowed = allowedLanesFor(e2Teams.value)
    const value = Number(paramMapByName.value['e2_lanes']?.value || 0)
    const plan = matchingPlanFor(e2Teams.value, value)
    const base = paramMapByName.value['e2_lanes']?.ui_label || paramMapByName.value['e1_lanes']?.ui_label || ''
    groups.push({
      key: 'pm',
      param: 'e2_lanes',
      teams: e2Teams.value,
      label: both ? `${base} - Nachmittag` : base,
      description: paramMapByName.value['e2_lanes']?.ui_description,
      value,
      allowed,
      palette: lanePaletteFor(allowed),
      hint: teamsPerGroupHint(e2Teams.value, value),
      note: plan?.note,
      alertLevel: plan?.alert_level || 0,
    })
  }

  return groups
})

const teamLimits = computed(() => {
  const param = paramMapByName.value['e_teams']
  const fromParam = {
    min: Number(param?.min),
    max: Number(param?.max),
  }
  const plans = (props.supportedPlanData || []).filter((plan: any) => plan.first_program === PROGRAM_ID)
  if (plans.length === 0) {
    return {
      min: Number.isFinite(fromParam.min) ? fromParam.min : 1,
      max: Number.isFinite(fromParam.max) ? fromParam.max : 30,
    }
  }
  const teamCounts = plans.map((plan: any) => plan.teams)
  return {min: Math.min(...teamCounts), max: Math.max(...teamCounts)}
})

const programPlans = computed(() =>
    (props.supportedPlanData || []).filter((plan: any) => plan.first_program === PROGRAM_ID)
)

const getAlertLevelStyle = (level: number) => {
  switch (level) {
    case 1:
      return 'glass-choice--active'
    case 2:
      return 'border-amber-400 bg-amber-50 text-amber-900'
    case 3:
      return 'border-red-400 bg-red-50 text-red-900'
    default:
      return 'glass-choice--active'
  }
}

const planTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const registeredTeams = computed(() => Number(event.value?.drahtTeamsExplore || 0))
const drahtCapacity = computed(() => Number(event.value?.drahtCapacityExplore || 0))
const capacityOverride = ref<number | null>(null)
const effectiveCapacity = computed(() => capacityOverride.value ?? drahtCapacity.value)

onDeactivated(() => {
  capacityOverride.value = null
})

const hasOtherPrograms = computed(() =>
    eventPrograms(event.value).some((program) => programId(program) !== PROGRAM_ID)
)

/** Mirrors backend App\Enums\ExploreMode. */
const ExploreMode = {
  NONE: 0,
  INTEGRATED_MORNING: 1,
  INTEGRATED_AFTERNOON: 2,
  DECOUPLED_MORNING: 3,
  DECOUPLED_AFTERNOON: 4,
  DECOUPLED_BOTH: 5,
  HYBRID_BOTH: 8,
} as const

function connectionFromMode(mode: number): 'integrated' | 'independent' {
  if (
      mode === ExploreMode.DECOUPLED_MORNING
      || mode === ExploreMode.DECOUPLED_AFTERNOON
      || mode === ExploreMode.DECOUPLED_BOTH
  ) {
    return 'independent'
  }
  return 'integrated'
}

const connection = ref<'integrated' | 'independent' | null>(null)

const connectionProxy = computed<'integrated' | 'independent'>({
  get: () => connection.value
      ?? connectionFromMode(Number(paramMapByName.value['e_mode']?.value || 0)),
  set: (val) => {
    connection.value = val
  },
})

function computeEMode(): number {
  const e1 = e1Teams.value
  const e2 = e2Teams.value
  if (eTeams.value <= 0 || (e1 <= 0 && e2 <= 0)) return ExploreMode.NONE

  const both = e1 > 0 && e2 > 0
  const morning = e1 > 0 && e2 <= 0
  const integrated = hasOtherPrograms.value && connectionProxy.value === 'integrated'

  if (integrated) {
    if (both) return ExploreMode.HYBRID_BOTH
    if (morning) return ExploreMode.INTEGRATED_MORNING
    return ExploreMode.INTEGRATED_AFTERNOON
  }

  if (both) return ExploreMode.DECOUPLED_BOTH
  if (morning) return ExploreMode.DECOUPLED_MORNING
  return ExploreMode.DECOUPLED_AFTERNOON
}

watch(
    [eTeams, e1Teams, e2Teams, hasOtherPrograms, connectionProxy],
    () => {
      if (!paramMapByName.value['e_mode']) return
      const next = computeEMode()
      if (next !== Number(paramMapByName.value['e_mode']?.value || 0)) {
        updateByName('e_mode', next)
      }
    },
    {immediate: true}
)
</script>

<template>
  <ProgramSection program="explore">
    <template #actions>
      <CapacityOverrideDialog
          :capacity="effectiveCapacity"
          :min="teamLimits.min"
          :max="teamLimits.max"
          @apply="capacityOverride = $event"
      />
    </template>
    <TeamPlanBar
        :plan-teams="planTeams"
        :registered-teams="registeredTeams"
        :capacity="effectiveCapacity"
        :min-teams="teamLimits.min"
        :max-teams="teamLimits.max"
        :on-update="(value) => updateByName('e_teams', value)"
    />

    <TeamSplitBar
        :total="eTeams"
        :left-teams="e1Teams"
        :on-update="setSplit"
    />

    <div v-for="(group, groupIndex) in laneGroups" :key="group.key" class="flex flex-col gap-1.5">
      <div class="flex items-center gap-1 min-w-0">
        <span class="glass-settings-label">{{ group.label }}</span>
        <InfoPopover :text="group.description"/>
      </div>
      <div class="glass-settings-row">
        <RadioGroup :model-value="group.value" class="flex gap-1.5 flex-wrap shrink-0">
          <RadioGroupOption
              v-for="n in group.palette"
              :key="group.key + '_lane_' + n"
              v-slot="{ checked, disabled }"
              :disabled="!group.allowed.includes(n)"
              :value="n"
              as="template"
          >
            <button
                :aria-disabled="disabled"
                :class="[
                  'glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1',
                  checked ? (getAlertLevelStyle(group.alertLevel) || 'glass-choice--active') : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : '',
                ]"
                type="button"
                @click="!disabled && updateByName(group.param, n)"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <span class="glass-settings-hint whitespace-nowrap">
          {{ group.hint }}
        </span>
        <SupportedPlansDialog
            v-if="groupIndex === laneGroups.length - 1 && !hasOtherPrograms"
            class="ml-auto"
            :plans="programPlans"
        />
      </div>
      <p v-if="group.teams && group.allowed.length === 0" class="glass-settings-hint mt-1.5 !not-italic">
        Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
      </p>
      <div
          v-if="group.note && group.alertLevel >= 1"
          class="program-note"
          :class="{
            'program-note--ok': group.alertLevel === 1,
            'program-note--warn': group.alertLevel === 2,
            'program-note--error': group.alertLevel === 3,
          }"
      >
        <i
            class="bi"
            :class="group.alertLevel === 1 ? 'bi-check-circle' : 'bi-exclamation-triangle'"
            aria-hidden="true"
        />
        <span>{{ group.note }}</span>
      </div>
    </div>

    <div v-if="hasOtherPrograms" class="flex flex-col gap-1.5">
      <span class="glass-settings-label">Verbindung mit anderen Programmen</span>
      <div class="glass-settings-row">
        <RadioGroup v-model="connectionProxy" class="flex gap-1.5 flex-wrap">
          <RadioGroupOption
              v-for="opt in [{value: 'integrated', label: 'Integriert'}, {value: 'independent', label: 'unabhängig'}]"
              :key="'explore_connection_' + opt.value"
              v-slot="{ checked }"
              :value="opt.value"
              as="template"
          >
            <button
                :class="checked ? 'glass-choice--active' : ''"
                class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                type="button"
                @click="connectionProxy = opt.value"
            >
              {{ opt.label }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <SupportedPlansDialog class="ml-auto" :plans="programPlans"/>
      </div>
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
</style>
