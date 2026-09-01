<script lang="ts" setup>
import {computed, onDeactivated, onMounted, ref, watch, watchEffect, type UnwrapRef} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from '@/components/atoms/InfoPopover.vue'
import TeamPlanBar from '@/components/molecules/TeamPlanBar.vue'
import CapacityOverrideDialog from '@/components/atoms/CapacityOverrideDialog.vue'
import SupportedPlansDialog from '@/components/atoms/SupportedPlansDialog.vue'
import ProgramSection from '@/components/atoms/ProgramSection.vue'
import {useEventStore} from '@/stores/event'
import {usePlanCacheStore} from '@/stores/planCache'

const PROGRAM_ID = 8

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
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

function intChoices(param: any): number[] {
  const min = Number(param?.min)
  const max = Number(param?.max)
  const step = Number(param?.step) || 1
  if (!Number.isFinite(min) || !Number.isFinite(max) || step <= 0) return []
  const out: number[] = []
  for (let n = min; n <= max; n += step) out.push(n)
  return out
}

const f8Teams = computed(() => Number(paramMapByName.value['f8_teams']?.value || 0))
const f8Fields = computed(() => Number(paramMapByName.value['f8_fields']?.value || 0) || 0)

const future8Index = computed(() => props.lanesIndex?.future8 ?? {})
const hasFuture8Plans = computed(() =>
    (props.supportedPlanData || []).some((plan: any) => plan.first_program === PROGRAM_ID)
)

const fieldChoices = computed(() => intChoices(paramMapByName.value['f8_fields']))

const fieldVariantsForTeams = computed<number[]>(() => {
  if (!hasFuture8Plans.value) return fieldChoices.value
  const idx = future8Index.value
  const t = f8Teams.value
  if (!t) return fieldChoices.value
  return fieldChoices.value.filter((fields) => (idx[`${t}|${fields}`] || []).length > 0)
})

const allowedJuryLanes = computed<number[]>(() => {
  const t = f8Teams.value
  if (!hasFuture8Plans.value) return [1, 2, 3, 4, 5, 6]
  if (!t) return [1, 2, 3, 4, 5, 6]
  const idx = future8Index.value
  const variants = f8Fields.value ? [f8Fields.value] : fieldChoices.value
  const merged = variants.flatMap((fields) => idx[`${t}|${fields}`] || [])
  return Array.from(new Set(merged)).sort((a, b) => a - b)
})

const f8LanesProxy = computed<number>({
  get: () => Number(paramMapByName.value['f8_lanes']?.value || 0),
  set: (val) => updateByName('f8_lanes', val),
})

function ensureLanesForSelection(fieldsOverride?: number) {
  if (!hasFuture8Plans.value) return
  const t = f8Teams.value
  if (!t || !props.lanesIndex) return
  const fields = fieldsOverride ?? f8Fields.value
  const allowed = fields
      ? (future8Index.value[`${t}|${fields}`] || []).slice().sort((a: number, b: number) => a - b)
      : allowedJuryLanes.value
  if (!allowed.length) return
  const curLane = Number(paramMapByName.value['f8_lanes']?.value || 0)
  if (!allowed.includes(curLane)) updateByName('f8_lanes', allowed[0])
}

function selectFields(fields: number) {
  updateByName('f8_fields', fields)
  ensureLanesForSelection(fields)
}

watchEffect(() => {
  if (!hasFuture8Plans.value) return
  const t = f8Teams.value
  if (!t || !props.lanesIndex) return
  const variants = fieldVariantsForTeams.value
  if (variants.length === 0) return
  const currentFields = f8Fields.value
  if (currentFields === 0 && variants.length === 1) {
    updateByName('f8_fields', variants[0])
    ensureLanesForSelection(variants[0])
    return
  }
  if (currentFields !== 0 && !variants.includes(currentFields)) {
    const next = variants[0]
    updateByName('f8_fields', next)
    ensureLanesForSelection(next)
    return
  }
  ensureLanesForSelection()
})

watch(allowedJuryLanes, (opts) => {
  if (!hasFuture8Plans.value) return
  const cur = Number(paramMapByName.value['f8_lanes']?.value || 0)
  if (opts.length && !opts.includes(cur)) updateByName('f8_lanes', opts[0])
})

const isLaneAllowed = (n: number) => allowedJuryLanes.value.includes(n)

const lanePalette = computed(() => {
  const max = Math.max(5, ...allowedJuryLanes.value)
  return Array.from({length: Math.min(7, max)}, (_, i) => i + 1)
})

const f8FieldsProxy = computed<number>({
  get: () => Number(paramMapByName.value['f8_fields']?.value || 0),
  set: (val) => updateByName('f8_fields', val),
})

const matchingPlan = computed(() => {
  if (!props.supportedPlanData || !f8Teams.value || !f8Fields.value || !f8LanesProxy.value) return
  return props.supportedPlanData.find((plan: any) =>
      plan.first_program === PROGRAM_ID &&
      plan.teams === f8Teams.value &&
      plan.tables === f8Fields.value &&
      plan.lanes === f8LanesProxy.value
  )
})

const currentLaneNote = computed<string | undefined>(() => matchingPlan.value?.note)
const currentConfigAlertLevel = computed<number>(() => matchingPlan.value?.alert_level || 0)

const teamLimits = computed(() => {
  const param = paramMapByName.value['f8_teams']
  const fromParam = {
    min: Number(param?.min),
    max: Number(param?.max),
  }
  if (!props.supportedPlanData) {
    return {
      min: Number.isFinite(fromParam.min) ? fromParam.min : 4,
      max: Number.isFinite(fromParam.max) ? fromParam.max : 30,
    }
  }
  const plans = props.supportedPlanData.filter((plan: any) => plan.first_program === PROGRAM_ID)
  if (plans.length === 0) {
    return {
      min: Number.isFinite(fromParam.min) ? fromParam.min : 4,
      max: Number.isFinite(fromParam.max) ? fromParam.max : 30,
    }
  }
  const teamCounts = plans.map((plan: any) => plan.teams)
  return {min: Math.min(...teamCounts), max: Math.max(...teamCounts)}
})

const programPlans = computed(() =>
    (props.supportedPlanData || []).filter((plan: any) => plan.first_program === PROGRAM_ID)
)

const planTeams = computed(() => Number(paramMapByName.value['f8_teams']?.value || 0))
const registeredTeams = ref(0)
const drahtCapacity = ref(0)
const capacityOverride = ref<number | null>(null)
const effectiveCapacity = computed(() => capacityOverride.value ?? drahtCapacity.value)
/** Slider ceiling: catalog max, capped by effective (DRAHT or override) capacity. */
const planMax = computed(() => {
  const maxT = teamLimits.value.max
  const cap = Number(effectiveCapacity.value)
  if (Number.isFinite(cap) && cap > 0) return Math.min(maxT, Math.round(cap))
  return maxT
})

function applyCapacityOverride(value: number) {
  capacityOverride.value = value
}

onDeactivated(() => {
  capacityOverride.value = null
})

async function loadDrahtCounts() {
  const eventId = event.value?.id
  if (!eventId) {
    registeredTeams.value = 0
    drahtCapacity.value = 0
    return
  }
  const data = await planCache.getDrahtData(eventId)
  const programs = Array.isArray(data?.programs) ? data.programs : []
  const row = programs.find((p: any) =>
      Number(p.first_program) === PROGRAM_ID
      || String(p.name || '').toUpperCase() === 'FUTURE_8'
  )
  registeredTeams.value = row?.teams ? Object.keys(row.teams).length : 0
  drahtCapacity.value = Number(row?.capacity || 0)
}

onMounted(() => {
  void loadDrahtCounts()
})

watch(
    () => event.value?.id,
    (id, prev) => {
      if (id && id !== prev) void loadDrahtCounts()
    }
)

const teamsPerJuryHint = computed(() => {
  const teams = Number(paramMapByName.value['f8_teams']?.value ?? 0)
  const lanes = Number(paramMapByName.value['f8_lanes']?.value ?? 1) || 1
  const lo = Math.floor(teams / lanes)
  const hi = Math.ceil(teams / lanes)
  return lo === hi
      ? `${lo} Teams pro Gruppe`
      : `${lo} bis ${hi} Teams pro Gruppe`
})
</script>

<template>
  <ProgramSection program="future8">
    <template #actions>
      <CapacityOverrideDialog
          :capacity="effectiveCapacity"
          :min="teamLimits.min"
          :max="teamLimits.max"
          @apply="applyCapacityOverride"
      />
    </template>
    <TeamPlanBar
        :plan-teams="planTeams"
        :registered-teams="registeredTeams"
        :capacity="effectiveCapacity"
        :min-teams="teamLimits.min"
        :max-teams="planMax"
        :on-update="(value) => updateByName('f8_teams', value)"
    />

    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-1 min-w-0">
        <span class="glass-settings-label">{{ paramMapByName['f8_lanes']?.ui_label }}</span>
        <InfoPopover :text="paramMapByName['f8_lanes']?.ui_description"/>
      </div>
      <div class="glass-settings-row">
        <RadioGroup v-model="f8LanesProxy" class="flex gap-1.5 flex-wrap shrink-0">
          <RadioGroupOption
              v-for="n in lanePalette"
              :key="'f8_lane_' + n"
              v-slot="{ checked, disabled }"
              :disabled="!isLaneAllowed(n)"
              :value="n"
              as="template"
          >
            <button
                :aria-disabled="disabled"
                :class="[
                  'glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1',
                  checked ? 'glass-choice--active' : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : '',
                ]"
                type="button"
                @click="!disabled && updateByName('f8_lanes', n)"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <span class="glass-settings-hint whitespace-nowrap">
          {{ teamsPerJuryHint }}
        </span>
      </div>
      <p v-if="f8Teams && allowedJuryLanes.length === 0" class="glass-settings-hint mt-1.5 !not-italic">
        Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
      </p>
    </div>

    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-1 min-w-0">
        <span class="glass-settings-label">{{ paramMapByName['f8_fields']?.ui_label }}</span>
        <InfoPopover :text="paramMapByName['f8_fields']?.ui_description"/>
      </div>
      <div class="glass-settings-row">
        <RadioGroup v-model="f8FieldsProxy" class="flex gap-1.5 flex-wrap">
          <RadioGroupOption
              v-for="n in fieldChoices"
              :key="'f8_fields_' + n"
              v-slot="{ checked, disabled }"
              :disabled="fieldVariantsForTeams.length > 0 && !fieldVariantsForTeams.includes(n)"
              :value="n"
              as="template"
          >
            <button
                :aria-disabled="disabled"
                :class="[
                  'glass-choice whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-offset-1',
                  checked ? 'glass-choice--active' : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : '',
                ]"
                type="button"
                @click="!disabled && selectFields(n)"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <SupportedPlansDialog class="ml-auto" program="future8" :plans="programPlans"/>
      </div>
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
