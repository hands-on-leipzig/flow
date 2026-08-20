<script lang="ts" setup>
import {computed, ref, watch, type UnwrapRef} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import TeamPlanBar from '@/components/molecules/TeamPlanBar.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import {useEventStore} from '@/stores/event'

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
const exploreIndex = computed(() => props.lanesIndex?.explore ?? {})

const allowedLanes = computed<number[]>(() => {
  const t = eTeams.value
  if (!t) return [1, 2, 3, 4, 5]
  return (exploreIndex.value[`${t}`] || []).slice().sort((a: number, b: number) => a - b)
})

const e1LanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['e1_lanes']?.value || paramMapByName.value['e2_lanes']?.value || 0),
  set: (val) => updateByName('e1_lanes', val),
})

watch(allowedLanes, (opts) => {
  if (!eTeams.value || !opts.length) return
  const e1 = Number(paramMapByName.value['e1_lanes']?.value || 0)
  if (!e1) return
  if (!opts.includes(e1)) updateByName('e1_lanes', opts[0])
})

const isLaneAllowed = (n: number) => allowedLanes.value.includes(n)

const lanePalette = computed(() => {
  const max = Math.max(5, ...allowedLanes.value)
  return Array.from({length: Math.min(7, max)}, (_, i) => i + 1)
})

const matchingPlan = computed(() => {
  if (!props.supportedPlanData || !eTeams.value || !e1LanesProxy.value) return
  return props.supportedPlanData.find((plan: any) =>
      plan.first_program === PROGRAM_ID &&
      plan.teams === eTeams.value &&
      plan.lanes === e1LanesProxy.value
  )
})

const currentLaneNote = computed<string | undefined>(() => matchingPlan.value?.note)
const currentConfigAlertLevel = computed<number>(() => matchingPlan.value?.alert_level || 0)

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
const capacity = computed(() => Number(event.value?.drahtCapacityExplore || 0))

const teamsPerJuryHint = computed(() => {
  const teams = Number(paramMapByName.value['e_teams']?.value ?? 0)
  const lanes = e1LanesProxy.value || 1
  const lo = Math.floor(teams / lanes)
  const hi = Math.ceil(teams / lanes)
  return lo === hi
      ? `${lo} Teams pro Gruppe`
      : `${lo} bis ${hi} Teams pro Gruppe`
})

function timingFromMode(mode: number): string {
  if (mode === 5 || mode === 8) return 'both'
  if (mode === 1 || mode === 3 || mode === 6) return 'morning'
  if (mode === 2 || mode === 4 || mode === 7) return 'afternoon'
  return 'morning'
}

function integrationFromMode(mode: number): string {
  if (mode === 5 || mode === 8) return mode === 8 ? 'yes' : 'no'
  if (mode === 1 || mode === 3 || mode === 6) return mode === 1 ? 'yes' : 'no'
  if (mode === 2 || mode === 4 || mode === 7) return mode === 2 ? 'yes' : 'no'
  return 'no'
}

const dummyTiming = ref<string | null>(null)
const dummyIntegration = ref<string | null>(null)

const storedMode = computed(() => Number(paramMapByName.value['e_mode']?.value || 0))

const dummyTimingProxy = computed<string>({
  get: () => dummyTiming.value ?? timingFromMode(storedMode.value),
  set: (val) => {
    dummyTiming.value = val
  },
})

const dummyIntegrationProxy = computed<string>({
  get: () => dummyIntegration.value ?? integrationFromMode(storedMode.value),
  set: (val) => {
    dummyIntegration.value = val
  },
})

const timingOptions = [
  {value: 'morning', label: 'Vormittag'},
  {value: 'afternoon', label: 'Nachmittag'},
  {value: 'both', label: 'beides'},
]
</script>

<template>
  <ProgramSection program="explore">
    <TeamPlanBar
        :plan-teams="planTeams"
        :registered-teams="registeredTeams"
        :capacity="capacity"
        :min-teams="teamLimits.min"
        :max-teams="teamLimits.max"
        :on-update="(value) => updateByName('e_teams', value)"
    />

    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-1 min-w-0">
        <span class="glass-settings-label">{{ paramMapByName['e1_lanes']?.ui_label }}</span>
        <InfoPopover :text="paramMapByName['e1_lanes']?.ui_description"/>
      </div>
      <div class="glass-settings-row">
        <RadioGroup v-model="e1LanesProxy" class="flex gap-1.5 flex-wrap shrink-0">
          <RadioGroupOption
              v-for="n in lanePalette"
              :key="'e1_lane_' + n"
              v-slot="{ checked, disabled }"
              :disabled="!isLaneAllowed(n)"
              :value="n"
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
                @click="!disabled && updateByName('e1_lanes', n)"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <span class="glass-settings-hint whitespace-nowrap">
          {{ teamsPerJuryHint }}
        </span>
      </div>
      <p v-if="eTeams && allowedLanes.length === 0" class="glass-settings-hint mt-1.5 !not-italic">
        Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
      </p>
    </div>

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

    <div class="flex flex-wrap items-start gap-x-8 gap-y-3">
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-1 min-w-0">
          <span class="glass-settings-label">Explore im</span>
          <InfoPopover :text="paramMapByName['e_mode']?.ui_description"/>
        </div>
        <div class="glass-settings-row">
          <RadioGroup v-model="dummyTimingProxy" class="flex gap-1.5 flex-wrap">
            <RadioGroupOption
                v-for="option in timingOptions"
                :key="option.value"
                v-slot="{ checked }"
                :value="option.value"
                as="template"
            >
              <button
                  :class="checked ? 'glass-choice--active' : ''"
                  class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                  type="button"
                  @click="dummyTimingProxy = option.value"
              >
                {{ option.label }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
        </div>
      </div>

      <div class="flex flex-col gap-1.5">
        <span class="glass-settings-label">Integration mit Challenge</span>
        <div class="glass-settings-row">
          <RadioGroup v-model="dummyIntegrationProxy" class="flex gap-1.5 flex-wrap">
            <RadioGroupOption
                v-for="opt in [{value: 'yes', label: 'ja'}, {value: 'no', label: 'nein'}]"
                :key="'explore_integration_' + opt.value"
                v-slot="{ checked }"
                :value="opt.value"
                as="template"
            >
              <button
                  :class="checked ? 'glass-choice--active' : ''"
                  class="glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1"
                  type="button"
                  @click="dummyIntegrationProxy = opt.value"
              >
                {{ opt.label }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
        </div>
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
