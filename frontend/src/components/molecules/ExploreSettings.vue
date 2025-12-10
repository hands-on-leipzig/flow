<script lang="ts" setup>
import {computed, UnwrapRef, watch} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import SplitBar from '@/components/atoms/SplitBar.vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from "@/components/atoms/InfoPopover.vue"
import TeamSelectionCard from "@/components/molecules/TeamSelectionCard.vue"
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
  {value: 'morning', label: 'Vormittag'},
  {value: 'afternoon', label: 'Nachmittag'},
  {value: 'both', label: 'beides'}
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

// Simple integration enabled/disabled
const integrationEnabled = computed({
  get: () => {
    const timing = timingMode.value

    if (timing === 'morning') {
      // Morning: Ja = mode 1 (integrated), Nein = mode 3 (decoupled)
      return eMode.value === 1 ? 'yes' : 'no'
    } else if (timing === 'afternoon') {
      // Afternoon: Ja = mode 2 (integrated), Nein = mode 4 (decoupled)
      return eMode.value === 2 ? 'yes' : 'no'
    } else if (timing === 'both') {
      // Both: Ja = mode 8 (hybrid both), Nein = mode 5 (decoupled both)
      return eMode.value === 8 ? 'yes' : 'no'
    }

    return 'no'
  },
  set: (value) => {
    const timing = timingMode.value

    if (timing === 'morning') {
      // Morning: Ja = integrated (1), Nein = decoupled (3)
      setMode(value === 'yes' ? 1 : 3)
    } else if (timing === 'afternoon') {
      // Afternoon: Ja = integrated (2), Nein = decoupled (4)
      setMode(value === 'yes' ? 2 : 4)
    } else if (timing === 'both') {
      // Both: Ja = hybrid both (8), Nein = decoupled both (5)
      setMode(value === 'yes' ? 8 : 5)
    }
  }
})

function updateTimingMode(timing: string) {
  // When switching timing, reset to decoupled mode for that timing
  // User will need to set integration again for the new timing
  let baseMode: number
  switch (timing) {
    case 'morning':
      baseMode = 3; // Decoupled morning
      break
    case 'afternoon':
      baseMode = 4; // Decoupled afternoon
      break
    case 'both':
      baseMode = 5; // Decoupled both
      break
    default:
      baseMode = 3;
      break
  }

  setMode(baseMode)
}

// Check if challenge is enabled (check c_mode parameter directly)
const cMode = computed(() => Number(paramMapByName.value['c_mode']?.value || 0))
const isChallengeEnabled = computed(() => cMode.value > 0)

// Watch for challenge being disabled and ensure integration is set to 'no'
watch(cMode, (newMode) => {
  if (newMode === 0) {
    // Challenge disabled - ensure integration is set to 'no'
    // Map current mode to decoupled equivalent
    if (eMode.value === 1 || eMode.value === 6) {
      // Was integrated morning -> switch to decoupled morning
      setMode(3)
    } else if (eMode.value === 2 || eMode.value === 7) {
      // Was integrated afternoon -> switch to decoupled afternoon
      setMode(4)
    } else if (eMode.value === 8) {
      // Was hybrid both -> switch to decoupled both
      setMode(5)
    }
    // When mode is already decoupled (3, 4, or 5), integrationEnabled will show 'no'
    // The setMode() calls above ensure the mode is decoupled, which means integrationEnabled is 'no'
  }
}, { immediate: true })

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


const planTeams = computed(() => Number(paramMapByName.value['e_teams']?.value || 0))
const registeredTeams = computed(() => Number(event.value?.drahtTeamsExplore || 0))
const capacity = computed(() => Number(event.value?.drahtCapacityExplore || 0))

const showWarningOnSwitch = computed(() => {
  return !props.showExplore && registeredTeams.value > 0
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
  <div class="p-4 border rounded shadow relative min-w-0">
    <div class="flex items-center gap-2 mb-4 justify-between flex-wrap">
      <div class="flex items-center gap-2 min-w-0 flex-1">
        <img
            :alt="programLogoAlt('E')"
            :src="programLogoSrc('E')"
            class="w-10 h-10 flex-shrink-0"
        />
        <h3 class="text-lg font-semibold capitalize break-words min-w-0">
          <span class="italic">FIRST</span> LEGO League Explore
        </h3>
      </div>

      <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
        <input
            :checked="showExplore"
            class="sr-only peer"
            type="checkbox"
            @change="handleToggleChange($event.target as HTMLInputElement)"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
        <span v-if="showWarningOnSwitch" class="ml-2 w-2 h-2 bg-red-500 rounded-full"></span>
      </label>
    </div>

    <!-- DEBUG: Show e_mode value -->
    <!--
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
    -->

    <div v-if="hasExplore" class="mb-3">
      <TeamSelectionCard
          :plan-teams="planTeams"
          :registered-teams="registeredTeams"
          :capacity="capacity"
          :min-teams="exploreTeamLimits.min"
          :max-teams="exploreTeamLimits.max"
          :on-update="(value) => updateByName('e_teams', value)"
      />
    </div>

    <!-- New UI: Two-row approach -->
    <div v-if="hasExplore">
      <div class="space-y-4 mb-4">
        <!-- First row: Timing (Radio buttons) -->
        <div class="flex flex-wrap items-center gap-2">
          <span class="text-sm font-medium whitespace-nowrap">Explore im</span>
          <RadioGroup v-model="timingMode" class="flex gap-1 flex-wrap">
            <RadioGroupOption
                v-for="option in timingOptions"
                :key="option.value"
                v-slot="{ checked }"
                :value="option.value"
            >
              <button
                  :class="checked ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  class="px-2 py-1 rounded-md border text-sm transition whitespace-nowrap
                       focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                {{ option.label }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
          <InfoPopover :text="paramMapByName['e_mode']?.ui_description"/>
        </div>

        <!-- Second row: Integration (Simple Ja/Nein) - Only show if Challenge is enabled -->
        <div v-if="isChallengeEnabled" class="flex flex-wrap items-center gap-2">
          <span class="text-sm font-medium whitespace-nowrap">Integration mit Challenge</span>
          <RadioGroup v-model="integrationEnabled" class="flex gap-1 flex-wrap">
            <RadioGroupOption
                value="yes"
                v-slot="{ checked }"
            >
              <button
                  :class="checked ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  class="px-2 py-1 rounded-md border text-sm transition
                       focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                ja
              </button>
            </RadioGroupOption>
            <RadioGroupOption
                value="no"
                v-slot="{ checked }"
            >
              <button
                  :class="checked ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
                  class="px-2 py-1 rounded-md border text-sm transition
                       focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  type="button"
              >
                nein
              </button>
            </RadioGroupOption>
          </RadioGroup>
        </div>

      </div>
    </div>

    <!-- Message when explore is disabled -->
    <div v-else class="text-center py-8 text-gray-500">
      <div class="text-lg font-medium mb-2"><span class="italic">FIRST</span> LEGO League Explore ist deaktiviert</div>
      <div class="text-sm">Aktiviere den Schalter oben rechts, um <span class="italic">FIRST</span> LEGO League Explore-Einstellungen zu konfigurieren.</div>
    </div>

    <!-- Gutachter:innen-Gruppen selection - Based on timing mode only -->

    <!-- AM timing: Show AM lanes selection -->
    <div v-if="hasExplore && timingMode === 'morning'" class="mt-4">
      <div class="flex flex-wrap items-start gap-2">
        <RadioGroup v-model="eLanesAMProxy" class="flex gap-1 flex-wrap">
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
                class="px-2 py-1 rounded-md border text-sm transition whitespace-nowrap
                      focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                type="button"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <div class="flex flex-col min-w-0 flex-1">
          <span class="text-sm font-medium break-words">Gutachter:innen-Gruppen</span>
          <span class="text-xs text-gray-500 italic break-words">{{ teamsPerJuryHint1 }}</span>
        </div>
      </div>
    </div>

    <!-- PM timing: Show PM lanes selection -->
    <div v-if="hasExplore && timingMode === 'afternoon'" class="mt-4">
      <div class="flex flex-wrap items-start gap-2">
        <RadioGroup v-model="eLanesPMProxy" class="flex gap-1 flex-wrap">
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
                class="px-2 py-1 rounded-md border text-sm transition whitespace-nowrap
                      focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                type="button"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <div class="flex flex-col min-w-0 flex-1">
          <span class="text-sm font-medium break-words">Gutachter:innen-Gruppen</span>
          <span class="text-xs text-gray-500 italic break-words">{{ teamsPerJuryHint2 }}</span>
        </div>
      </div>
    </div>

    <!-- Both timing: Show splitter and both AM/PM lanes -->
    <template v-if="hasExplore && timingMode === 'both'">
      <!-- Splitter -->
      <div v-if="paramMapByName['e_teams']?.value" class="mt-4">
        <SplitBar
            :e1="Number(paramMapByName['e1_teams']?.value || 0)"
            :e2="Number(paramMapByName['e2_teams']?.value || 0)"
            :total="Number(paramMapByName['e_teams']?.value || 0)"
            class="mb-4"
            @update:e1="(v:number) => updateByName('e1_teams', v)"
            @update:e2="(v:number) => updateByName('e2_teams', v)"
        />
      </div>

      <!-- Two columns for AM and PM -->
      <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-8 text-gray-800">
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
                <span class="text-sm font-medium break-words">Gutacher:innen-Gruppen</span>
                <span class="text-xs text-gray-500 italic break-words">
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
                <span class="text-sm font-medium break-words">Gutacher:innen-Gruppen</span>
                <span class="text-xs text-gray-500 italic break-words">
                {{ teamsPerJuryHint2 }}
              </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

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
