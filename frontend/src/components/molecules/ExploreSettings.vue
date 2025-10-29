<script lang="ts" setup>
import {computed, UnwrapRef, watch} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import SplitBar from '@/components/atoms/SplitBar.vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from "@/components/atoms/InfoPopover.vue"
import {useEventStore} from '@/stores/event'
import {programLogoAlt, programLogoSrc} from '@/utils/images'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const props = defineProps<{
  parameters: any[]
  showExplore: boolean
  showChallenge?: boolean
  lanesIndex?: LanesIndex | UnwrapRef<LanesIndex> | null
  supportedPlanData?: any[] | null
}>()

const emit = defineEmits<{
  (e: 'update-param', param: any): void
  (e: 'toggle-show', value: boolean): void
}>()

// No need to expose anything - parent handles all batching

const paramMapByName = computed<Record<string, any>>(
    () => Object.fromEntries(props.parameters.map((p: any) => [p.name, p]))
)

// Simple parameter update - emit immediately to parent for batching
function updateByName(name: string, value: any) {
  emit('update-param', {name, value})
}

function handleToggleChange(target: HTMLInputElement) {
  const isChecked = target.checked
  emit('toggle-show', isChecked)

  // Update explore mode based on toggle state
  if (isChecked) {
    // Turn on explore - default to appropriate mode based on challenge availability
    if (eMode.value === 0) {
      // Default to integrated AM if challenge is enabled, otherwise separate AM
      setMode(isChallengeEnabled.value ? 1 : 3)
    }

    // Use DRAHT team count as default if available, otherwise use min

    const drahtTeams = eventStore.selectedEvent?.drahtTeamsExplore || 0
    const minTeams = paramMapByName.value['e_teams']?.min || 1
    const defaultTeams = drahtTeams > 0 ? drahtTeams : minTeams

    if (eTeams.value === 0) {
      updateByName('e_teams', defaultTeams)
    }
  } else {
    // Turn off explore - set to mode 0 and clear team counts
    setMode(0)
  }
}

/** Core derived state **/
const eMode = computed<number>({
  get: () => Number(paramMapByName.value['e_mode']?.value || 0),
  set: (v) => updateByName('e_mode', v)
})
const eTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const e1Teams = computed(() => Number(paramMapByName.value['e1_teams']?.value || 0))
const e2Teams = computed(() => Number(paramMapByName.value['e2_teams']?.value || 0))

// Updated mode logic for 0-5 scale:
// 0: No explore
// 1: Integrated with challenge AM
// 2: Integrated with challenge PM  
// 3: Separate AM
// 4: Separate PM
// 5: Separate split between AM/PM
const isIntegratedAM = computed(() => eMode.value === 1 || eMode.value === 6)
const isIntegratedPM = computed(() => eMode.value === 2 || eMode.value === 7)
const isSeparateSplit = computed(() => eMode.value === 5 || eMode.value === 8)

const isIntegrated = computed(() => eMode.value === 1 || eMode.value === 2 || eMode.value === 6 || eMode.value === 7)
const isIndependent = computed(() => eMode.value === 3 || eMode.value === 4 || eMode.value === 5 || eMode.value === 8)
const hasExplore = computed(() => props.showExplore)

// New UI: Timing options (radio buttons)
const timingOptions = [
  { value: 'morning', label: 'Vormittag' },
  { value: 'afternoon', label: 'Nachmittag' },
  { value: 'both', label: 'beides' }
]

// New UI: Timing mode (radio button selection)
const timingMode = computed({
  get: () => {
    // Map current eMode to timing mode
    // For "both" timing, always return "both" regardless of integration state
    if (eMode.value === 5 || eMode.value === 8) return 'both'
    if (eMode.value === 1 || eMode.value === 3 || eMode.value === 6) return 'morning'
    if (eMode.value === 2 || eMode.value === 4 || eMode.value === 7) return 'afternoon'
    return 'morning' // default
  },
  set: (value) => {
    updateTimingMode(value)
  }
})

// Helper functions for new UI
function updateTimingMode(timing: string) {
  // Determine base mode based on timing
  let baseMode: number
  switch (timing) {
    case 'morning': baseMode = 3; break  // Separate AM
    case 'afternoon': baseMode = 4; break  // Separate PM
    case 'both': baseMode = 5; break  // Separate split
    default: baseMode = 3; break
  }
  
  setMode(baseMode)
}

// Check if challenge is enabled (for disabling integrated modes)
const isChallengeEnabled = computed(() => props.showChallenge !== false)

// Watch for challenge being disabled and switch away from integrated modes
watch(isChallengeEnabled, (enabled) => {
  if (!enabled && (eMode.value === 1 || eMode.value === 2 || eMode.value === 6 || eMode.value === 7)) {
    // Challenge disabled while in integrated mode - switch to separate AM
    setMode(3)
  }
})

/** Fancy mode changes **/
function setMode(mode: 0 | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8) {
  eMode.value = mode
  const total = eTeams.value

  // Map hybrid modes to their base modes
  const baseMode = mode === 6 ? 1 : mode === 7 ? 2 : mode === 8 ? 5 : mode

  // Reset team counts and lane counts based on mode
  if (baseMode === 0) {
    // No explore - clear all team and lane counts
    updateByName('e1_teams', 0)
    updateByName('e2_teams', 0)
    updateByName('e1_lanes', 0)
    updateByName('e2_lanes', 0)
  } else if (baseMode === 1) {
    // Integrated AM - all teams in e1_teams, clear PM
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
    updateByName('e2_lanes', 0)
  } else if (baseMode === 2) {
    // Integrated PM - all teams in e2_teams, clear AM
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
    updateByName('e1_lanes', 0)
  } else if (baseMode === 3) {
    // Separate AM - all teams in e1_teams, clear PM
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
    updateByName('e2_lanes', 0)
  } else if (baseMode === 4) {
    // Separate PM - all teams in e2_teams, clear AM
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
    updateByName('e1_lanes', 0)
  } else if (baseMode === 5) {
    // Separate split - create half split immediately
    const half = Math.floor(total / 2)
    updateByName('e1_teams', half)
    updateByName('e2_teams', total - half)
  }
}


/** Keep user split when total changes **/
watch(() => paramMapByName.value['e_teams']?.value, (newTotalRaw) => {
  const total = Number(newTotalRaw || 0)

  // Always update e_teams with the total
  if (total !== eTeams.value) {
    updateByName('e_teams', total)
  }

  // Map hybrid modes to their base modes
  const baseMode = eMode.value === 6 ? 1 : eMode.value === 7 ? 2 : eMode.value === 8 ? 5 : eMode.value

  // Update e1_teams and e2_teams based on current mode
  if (baseMode === 0) {
    // No explore - clear all
    updateByName('e1_teams', 0)
    updateByName('e2_teams', 0)
  } else if (baseMode === 1) {
    // Integrated AM - all in e1_teams
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  } else if (baseMode === 2) {
    // Integrated PM - all in e2_teams
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
  } else if (baseMode === 3) {
    // Separate AM - all in e1_teams
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  } else if (baseMode === 4) {
    // Separate PM - all in e2_teams
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
  } else if (baseMode === 5) {
    // Separate split - always create proper half split
    const half = Math.floor(total / 2)
    updateByName('e1_teams', half)
    updateByName('e2_teams', total - half)
  }
})

/** Lanes – use lanesIndex.explore[teams] **/
const allLaneOptions = [1, 2, 3, 4, 5]

/* Integrated (modes 1/2) use lanes based on total e_teams */
const allowedExploreLanesIntegrated = computed<number[]>(() => {
  if (!props.lanesIndex || !eTeams.value) return []
  const key = `${eTeams.value}`
  return props.lanesIndex.explore[key] ?? []
})

const isExploreLaneAllowedIntegrated = (n: number) => {
  const allowed = allowedExploreLanesIntegrated.value
  // If no allowed lanes computed, allow all lanes (fallback)
  if (!allowed.length) return true
  return allowed.includes(n)
}

// Watch for integrated mode lane changes
watch(allowedExploreLanesIntegrated, (opts) => {
  if (!opts.length) return
  if (isIntegratedAM.value) {
    const cur = Number(paramMapByName.value['e1_lanes']?.value || 0)
    if (!opts.includes(cur)) updateByName('e1_lanes', opts[0])
    // Clear PM lanes since no teams there
    updateByName('e2_lanes', 0)
  } else if (isIntegratedPM.value) {
    const cur = Number(paramMapByName.value['e2_lanes']?.value || 0)
    if (!opts.includes(cur)) updateByName('e2_lanes', opts[0])
    // Clear AM lanes since no teams there
    updateByName('e1_lanes', 0)
  }
})

/* Independent lanes - use appropriate team count based on mode */
const allowedExploreLanesAM = computed<number[]>(() => {
  if (!props.lanesIndex) return []

  // For mode 3 (separate AM) or mode 6 (hybrid AM), use total teams. For mode 5 (split) or mode 8 (hybrid split), use actual e1Teams
  const teamCount = (eMode.value === 3 || eMode.value === 6) ? eTeams.value : e1Teams.value
  if (!teamCount) return []

  const key = `${teamCount}`
  return props.lanesIndex.explore[key] ?? []
})
const allowedExploreLanesPM = computed<number[]>(() => {
  if (!props.lanesIndex) return []

  // For mode 4 (separate PM) or mode 7 (hybrid PM), use total teams. For mode 5 (split) or mode 8 (hybrid split), use actual e2Teams
  const teamCount = (eMode.value === 4 || eMode.value === 7) ? eTeams.value : e2Teams.value
  if (!teamCount) return []

  const key = `${teamCount}`
  return props.lanesIndex.explore[key] ?? []
})

const eLanesAMProxy = computed<number>({
  get: () => Number(paramMapByName.value['e1_lanes']?.value || 0),
  set: (val) => updateByName('e1_lanes', val)
})
const eLanesPMProxy = computed<number>({
  get: () => Number(paramMapByName.value['e2_lanes']?.value || 0),
  set: (val) => updateByName('e2_lanes', val)
})

// Computed property for integrated mode lanes
const integratedLanesProxy = computed<number>({
  get: () => isIntegratedAM.value ? eLanesAMProxy.value : eLanesPMProxy.value,
  set: (val) => {
    if (isIntegratedAM.value) {
      eLanesAMProxy.value = val
    } else {
      eLanesPMProxy.value = val
    }
  }
})

watch(allowedExploreLanesAM, (opts) => {
  if (!opts.length) {
    // No AM teams, clear AM lanes
    updateByName('e1_lanes', 0)
    return
  }
  const cur = Number(paramMapByName.value['e1_lanes']?.value || 0)
  if (!opts.includes(cur)) updateByName('e1_lanes', opts[0])
})
watch(allowedExploreLanesPM, (opts) => {
  if (!opts.length) {
    // No PM teams, clear PM lanes
    updateByName('e2_lanes', 0)
    return
  }
  const cur = Number(paramMapByName.value['e2_lanes']?.value || 0)
  if (!opts.includes(cur)) updateByName('e2_lanes', opts[0])
})

// Watch for manual changes to team counts to update total
watch([e1Teams, e2Teams], ([e1, e2]) => {
  const total = e1 + e2
  if (total !== eTeams.value) {
    updateByName('e_teams', total)
  }
})

const isExploreLaneAllowedAM = (n: number) => {
  const allowed = allowedExploreLanesAM.value
  // If no allowed lanes computed, allow all lanes (fallback)
  if (!allowed.length) return true
  return allowed.includes(n)
}
const isExploreLaneAllowedPM = (n: number) => {
  const allowed = allowedExploreLanesPM.value
  // If no allowed lanes computed, allow all lanes (fallback)
  if (!allowed.length) return true
  return allowed.includes(n)
}


// Get the current note based on the active mode
const currentExploreNote = computed<string>(() => {
  if (eMode.value === 1 || eMode.value === 6) return currentIntegratedNote.value || ''
  if (eMode.value === 2 || eMode.value === 7) return currentAMNote.value || ''
  if (eMode.value === 3) return currentPMNote.value || ''
  return ''
})

// Get the current alert level based on the active mode
const currentExploreAlertLevel = computed<number>(() => {
  if (eMode.value === 1 || eMode.value === 6) return currentConfigAlertLevelIntegrated.value
  if (eMode.value === 2 || eMode.value === 7) return currentConfigAlertLevelAM.value
  if (eMode.value === 3) return currentConfigAlertLevelPM.value
  return 0
})

// Calculate min/max team counts from supported plan data
const exploreTeamLimits = computed(() => {
  if (!props.supportedPlanData) return {min: 1, max: 50}

  const explorePlans = props.supportedPlanData.filter(plan => plan.first_program === 2)
  if (explorePlans.length === 0) return {min: 1, max: 50}

  const teamCounts = explorePlans.map(plan => plan.teams)
  return {
    min: Math.min(...teamCounts),
    max: Math.max(...teamCounts)
  }
})

// Get current configuration alert level for integrated mode
const currentConfigAlertLevelIntegrated = computed<number>(() => {
  if (!props.supportedPlanData || !eTeams.value || !integratedLanesProxy.value) return 0

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === integratedLanesProxy.value
  )

  return matchingPlan?.alert_level || 0
})


// Get current configuration alert level for AM mode
const currentConfigAlertLevelAM = computed<number>(() => {
  if (!props.supportedPlanData || !eTeams.value || !eLanesAMProxy.value) return 0

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === eLanesAMProxy.value
  )

  return matchingPlan?.alert_level || 0
})


// Get current configuration alert level for PM mode
const currentConfigAlertLevelPM = computed<number>(() => {
  if (!props.supportedPlanData || !eTeams.value || !eLanesPMProxy.value) return 0

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === eLanesPMProxy.value
  )

  return matchingPlan?.alert_level || 0
})

// Get note for current integrated configuration
const currentIntegratedNote = computed<string | undefined>(() => {
  if (!props.supportedPlanData || !eTeams.value || !integratedLanesProxy.value) return

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === integratedLanesProxy.value
  )

  return matchingPlan?.note
})

// Get note for current AM configuration
const currentAMNote = computed<string | undefined>(() => {
  if (!props.supportedPlanData || !eTeams.value || !eLanesAMProxy.value) return

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === eLanesAMProxy.value
  )

  return matchingPlan?.note
})

// Get note for current PM configuration
const currentPMNote = computed<string | undefined>(() => {
  if (!props.supportedPlanData || !eTeams.value || !eLanesPMProxy.value) return

  const matchingPlan = props.supportedPlanData.find(plan =>
      plan.first_program === 2 &&
      plan.teams === eTeams.value &&
      plan.lanes === eLanesPMProxy.value
  )

  return matchingPlan?.note
})

// Alert level styling and messages
const getAlertLevelStyle = (level: number) => {
  switch (level) {
    case 1:
      return 'border-2 border-green-500 ring-2 ring-green-500' // Recommended
    case 2:
      return 'border-2 border-orange-500 ring-2 ring-orange-500' // Risk
    case 3:
      return 'border-2 border-red-500 ring-2 ring-red-500' // High risk
    default:
      return 'ring-1 ring-gray-500 border-gray-500' // OK
  }
}


const getTeamInputStyle = (level: number) => {
  switch (level) {
    case 1:
      return 'border-green-500 focus:border-green-500 focus:ring-green-500'
    case 2:
      return 'border-orange-500 focus:border-orange-500 focus:ring-orange-500'
    case 3:
      return 'border-red-500 focus:border-red-500 focus:ring-red-500'
    default:
      return 'border-gray-300 focus:border-gray-500 focus:ring-gray-500'
  }
}

const planTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const registeredTeams = computed(() => Number(event.value?.drahtTeamsExplore || 0))
const capacity = computed(() => Number(event.value?.drahtCapacityExplore || 0))

const plannedAmountNotMatching = computed(() => {
  if (planTeams.value === registeredTeams.value) {
    return false
  } else if (planTeams.value > capacity.value || planTeams.value < registeredTeams.value) {
    return true
  } else {
    return true
  }
})

const teamsPerJuryHint1 = computed(() => {
  const teams = Number(paramMapByName.value['e1_teams']?.value ?? 0)
  const lanes = Number(paramMapByName.value['e1_lanes']?.value ?? 1) // garantiert >0

  if (teams === 0) {
    return ''
  } else {

    const lo = Math.floor(teams / lanes)
    const hi = Math.ceil(teams / lanes)

    return lo === hi
        ? `${lo} Teams pro Gruppe`
        : `${lo} bis ${hi} Teams pro Gruppe`

  }

})

const teamsPerJuryHint2 = computed(() => {
  const teams = Number(paramMapByName.value['e2_teams']?.value ?? 0)
  const lanes = Number(paramMapByName.value['e2_lanes']?.value ?? 1) // garantiert >0


  if (teams === 0) {
    return ''
  } else {

    const lo = Math.floor(teams / lanes)
    const hi = Math.ceil(teams / lanes)

    return lo === hi
        ? `${lo} Teams pro Gruppe`
        : `${lo} bis ${hi} Teams pro Gruppe`
  }

})

</script>

<template>
  <div class="p-4 border rounded shadow relative">
    <div class="flex items-center gap-2 mb-4 justify-between">
      <img
          :alt="programLogoAlt('E')"
          :src="programLogoSrc('E')"
          class="w-10 h-10 flex-shrink-0"
      />
      <h3 class="text-lg font-semibold capitalize">
        <span class="italic">FIRST</span> LEGO League Explore
      </h3>

      <label class="relative inline-flex items-center cursor-pointer">
        <input
            :checked="showExplore"
            class="sr-only peer"
            type="checkbox"
            @change="handleToggleChange($event.target as HTMLInputElement)"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
      </label>
    </div>

    <!-- DEBUG: Show e_mode value -->
    <div v-if="hasExplore" class="mb-2 p-2 bg-yellow-100 border border-yellow-300 rounded text-sm">
      <strong>DEBUG:</strong> e_mode = {{ eMode }} ({{ 
        eMode === 0 ? 'NONE' :
        eMode === 1 ? 'INTEGRATED_MORNING' :
        eMode === 2 ? 'INTEGRATED_AFTERNOON' :
        eMode === 3 ? 'DECOUPLED_MORNING' :
        eMode === 4 ? 'DECOUPLED_AFTERNOON' :
        eMode === 5 ? 'DECOUPLED_BOTH' :
        eMode === 6 ? 'HYBRID_MORNING' :
        eMode === 7 ? 'HYBRID_AFTERNOON' :
        eMode === 8 ? 'HYBRID_BOTH' :
        'UNKNOWN'
      }})
    </div>

    <div v-if="hasExplore" class="mb-3 flex items-center gap-2">
      <span>Plan für</span>
      <div class="relative">
        <input
            :class="getTeamInputStyle(currentConfigAlertLevelIntegrated)"
            :max="exploreTeamLimits.max"
            :min="exploreTeamLimits.min"
            :value="paramMapByName['e_teams']?.value"
            class="mt-1 w-16 border-2 rounded px-2 py-1 text-center focus:outline-none focus:ring-2"
            type="number"
            @input="updateByName('e_teams', Number(($event.target as HTMLInputElement).value || 0))"
        />
        <div
            v-if="plannedAmountNotMatching"
            class="absolute top-2 right-5 w-2 h-2 bg-red-500 rounded-full"
            title="Geplante Anzahl und angemeldete Anzahl Teams stimmen nicht überein."
        ></div>
      </div>
      <label>Teams</label>
      <span class="relative">bei {{ registeredTeams }}/{{ capacity }} angemeldeten      </span>
      <InfoPopover :text="paramMapByName['e_teams']?.ui_description"/>
    </div>

    <!-- New UI: Two-row approach -->
    <div v-if="hasExplore">
      <div class="space-y-4 mb-4">
        <!-- First row: Timing (Radio buttons) -->
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">Explore im</span>
          <RadioGroup v-model="timingMode" class="flex gap-1">
            <RadioGroupOption
                v-for="option in timingOptions"
                :key="option.value"
                v-slot="{ checked }"
                :value="option.value"
            >
              <button
                  :class="checked ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  class="px-2 py-1 rounded-md border text-sm transition
                       focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                {{ option.label }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
          <InfoPopover :text="paramMapByName['e_mode']?.ui_description"/>
        </div>

      </div>
    </div>

    <!-- Message when explore is disabled -->
    <div v-else class="text-center py-8 text-gray-500">
      <div class="text-lg font-medium mb-2">Explore ist deaktiviert</div>
      <div class="text-sm">Aktiviere den Schalter oben rechts, um Explore-Einstellungen zu konfigurieren.</div>
    </div>

    <!-- INTEGRATED (1/2): inline lane selector bound to e1_lanes (allowed by total e_teams) -->
    <div v-if="hasExplore && isIntegrated" class="mt-4 flex">
      <div class="flex items-start gap-2">
        <!-- Buttons -->
        <RadioGroup v-model="integratedLanesProxy" class="flex gap-1">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_int_' + n"
              v-slot="{ checked, disabled }"
              :disabled="!isExploreLaneAllowedIntegrated(n)"
              :value="n"
          >
            <button
                :aria-disabled="disabled"
                :class="[
                checked ? getAlertLevelStyle(currentConfigAlertLevelIntegrated) : '',
                disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
              ]"
                class="px-2 py-1 rounded-md border text-sm transition
                  focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                type="button"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>

        <!-- Rechte Spalte mit Label + Hint -->
        <div class="flex flex-col">
          <div class="flex items-center gap-2">
            <span class="text-sm font-medium">Gutachter:innen-Gruppen</span>
            <InfoPopover :text="paramMapByName['e1_lanes']?.ui_description"/>
          </div>
          <span class="text-xs text-gray-500 italic">
            {{ teamsPerJuryHint1 }} {{ teamsPerJuryHint2 }}
          </span>
        </div>
      </div>


    </div>

    <!-- SPLIT slider only for mode 5 -->
    <div v-if="hasExplore">
      <SplitBar
          v-if="isSeparateSplit && paramMapByName['e_teams']?.value"
          :e1="Number(paramMapByName['e1_teams']?.value || 0)"
          :e2="Number(paramMapByName['e2_teams']?.value || 0)"
          :total="Number(paramMapByName['e_teams']?.value || 0)"
          class="mt-3"
          @update:e1="(v:number) => updateByName('e1_teams', v)"
          @update:e2="(v:number) => updateByName('e2_teams', v)"
      />
    </div>

    <!-- Two columns when independent (3, 4, or 5) -->
    <div v-if="hasExplore && isIndependent" class="mt-4 grid grid-cols-2 gap-8 text-gray-800">
      <!-- AM -->
      <div :class="(eMode === 4 || eMode === 7 || e1Teams === 0) ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Vormittag
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <RadioGroup v-model="eLanesAMProxy" class="flex gap-1">
            <RadioGroupOption
                v-for="n in allLaneOptions"
                :key="'e_lane_am_' + n"
                v-slot="{ checked, disabled }"
                :disabled="!isExploreLaneAllowedAM(n) || e1Teams === 0"
                :value="n"
            >
              <button
                  :aria-disabled="disabled"
                  :class="[
                  checked ? getAlertLevelStyle(currentConfigAlertLevelAM) : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
                ]"
                  class="px-2 py-1 rounded-md border text-sm transition
                      focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                {{ n }}
              </button>
            </RadioGroupOption>
          </RadioGroup>

          <!-- zweizeiliger Block unter den Buttons -->
          <div class="basis-full mt-1">
            <div class="flex flex-col">
              <span class="text-sm font-medium">Gutacher:innen-Gruppen</span>
              <span class="text-xs text-gray-500 italic">
                {{ teamsPerJuryHint1 }}
              </span>
            </div>
          </div>
        </div>


      </div>

      <!-- PM -->
      <div :class="(eMode === 3 || eMode === 6 || e2Teams === 0) ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Nachmittag
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <RadioGroup v-model="eLanesPMProxy" class="flex gap-1">
            <RadioGroupOption
                v-for="n in allLaneOptions"
                :key="'e_lane_pm_' + n"
                v-slot="{ checked, disabled }"
                :disabled="!isExploreLaneAllowedPM(n) || e2Teams === 0"
                :value="n"
            >
              <button
                  :aria-disabled="disabled"
                  :class="[
                  checked ? getAlertLevelStyle(currentConfigAlertLevelPM) : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
                ]"
                  class="px-2 py-1 rounded-md border text-sm transition
                      focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                {{ n }}
              </button>
            </RadioGroupOption>
          </RadioGroup>

          <!-- zweizeiliger Block unter den Buttons -->
          <div class="basis-full mt-1">
            <div class="flex flex-col">
              <span class="text-sm font-medium">Gutacher:innen-Gruppen</span>
              <span class="text-xs text-gray-500 italic">
                {{ teamsPerJuryHint2 }}
              </span>
            </div>
          </div>
        </div>


      </div>
    </div>

    <!-- Alert message banner -->
    <div v-if="currentExploreNote && (currentExploreAlertLevel === 2 || currentExploreAlertLevel === 3)"
         :class="{
           'bg-orange-100/60 border border-orange-300/40 text-orange-700': currentExploreAlertLevel === 2,
           'bg-red-100/60 border border-red-300/40 text-red-700': currentExploreAlertLevel === 3
         }"
         class="mt-3 inline-flex items-center gap-1 px-2 py-1 rounded text-xs">
      <span class="text-xs">⚠</span>
      {{ currentExploreNote }}
    </div>
  </div>
</template>
