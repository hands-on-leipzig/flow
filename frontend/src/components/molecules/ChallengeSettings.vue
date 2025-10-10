<script setup lang="ts">
import {computed, UnwrapRef, watch, watchEffect} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import {useEventStore} from '@/stores/event'
import { programLogoSrc, programLogoAlt } from '@/utils/images'  

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const props = defineProps<{
  parameters: any[]
  showChallenge: boolean
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

  // Update c_mode based on toggle state
  updateByName('c_mode', isChecked ? 1 : 0)

  // Update challenge parameters based on toggle state
  if (isChecked) {
    // Use DRAHT team count as default if available, otherwise use min
    const drahtTeams = eventStore.selectedEvent?.drahtTeamsChallenge || 0
    const minTeams = paramMapByName.value['c_teams']?.min || 1
    const defaultTeams = drahtTeams > 0 ? drahtTeams : minTeams

    if (cTeams.value === 0) {
      updateByName('c_teams', defaultTeams)
    }
    
    // Auto-select robot game table
    const currentTables = rTables.value
    if (currentTables === 0) {
      // Check which table options are available for the team count
      const variants = tableVariantsForTeams.value
      if (variants.length === 1) {
        // Only one option available, select it
        updateByName('r_tables', variants[0])
      } else if (variants.length > 1) {
        // Multiple options available, choose 2 (as requested)
        updateByName('r_tables', 2)
      }
    }
  } else {
    // Turn off challenge - clear team count and related parameters
    updateByName('c_teams', 0)
    updateByName('r_tables', 0)
    updateByName('j_lanes', 0)
  }
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
    return
  }

  // 2) If current tables invalid -> move to first valid variant and set a valid lane
  if (currentTables !== 0 && !variants.includes(currentTables)) {
    const nextTables = variants[0]
    updateByName('r_tables', nextTables)
    const allowedForNext = (props.lanesIndex.challenge[`${t}|${nextTables}`] || []).slice().sort((a, b) => a - b)
    if (allowedForNext.length) updateByName('j_lanes', allowedForNext[0])
    return
  }

  // 3) Ensure current lane is valid for the (possibly merged) allowed set
  const curLane = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (allowedJuryLanes.value.length && !allowedJuryLanes.value.includes(curLane)) {
    updateByName('j_lanes', allowedJuryLanes.value[0])
  }
})

// If allowed set changes (due to teams/tables), snap lanes if invalid
watch(allowedJuryLanes, (opts) => {
  const cur = Number(paramMapByName.value['j_lanes']?.value || 0)
  if (opts.length && !opts.includes(cur)) updateByName('j_lanes', opts[0])
})

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
    case 1: return 'border-2 border-green-500 ring-2 ring-green-500' // Recommended
    case 2: return 'border-2 border-orange-500 ring-2 ring-orange-500' // Risk
    case 3: return 'border-2 border-red-500 ring-2 ring-red-500' // High risk
    default: return 'ring-1 ring-gray-500 border-gray-500' // OK
  }
}



const getTeamInputStyle = (level: number) => {
  switch (level) {
    case 1: return 'border-green-500 focus:border-green-500 focus:ring-green-500'
    case 2: return 'border-orange-500 focus:border-orange-500 focus:ring-orange-500'
    case 3: return 'border-red-500 focus:border-red-500 focus:ring-red-500'
    default: return 'border-gray-300 focus:border-gray-500 focus:ring-gray-500'
  }
}

const planTeams = computed(() => Number(paramMapByName.value['c_teams']?.value || 0))
const registeredTeams = computed(() => Number(event.value?.drahtTeamsChallenge || 0))
const capacity = computed(() => Number(event.value?.drahtCapacityChallenge || 0))

const planStatusClass = computed(() => {
  if (planTeams.value === registeredTeams.value) {
    return 'bg-green-100 border border-green-300 text-green-700'
  } else if (planTeams.value > capacity.value || planTeams.value < registeredTeams.value) {
    return 'bg-red-100 border border-red-300 text-red-700'
  } else {
    return 'bg-yellow-100 border border-yellow-300 text-yellow-700'
  }
})

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
  <div class="p-4 border rounded shadow relative">
    <div class="flex items-center gap-2 mb-2">
      <img
          :src="programLogoSrc('C')"
          :alt="programLogoAlt('C')"
          class="w-10 h-10 flex-shrink-0"
        />

      <div>
        <h3 class="text-lg font-semibold capitalize">
          <span class="italic">FIRST</span> LEGO League Challenge
        </h3>
        
      <div :class="['flex space-x-4 text-xs px-2 py-1 rounded', planStatusClass]">
        <span>
          Kapazität: {{ capacity }}
        </span>
        <span>
          Angemeldet: {{ registeredTeams }}
        </span>
        <span>
          In diesem Plan: {{ planTeams }}
        </span>
      </div>

      </div>

      
      
      
      <label class="relative inline-flex items-center cursor-pointer">
        <input
            type="checkbox"
            :checked="showChallenge"
            @change="handleToggleChange($event.target as HTMLInputElement)"
            class="sr-only peer"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
      </label>

    </div>

    <template v-if="showChallenge">
      <div class="mb-3 flex items-center gap-2">
        <input
          class="mt-1 w-16 border-2 rounded px-2 py-1 text-center focus:outline-none focus:ring-2"
          :class="getTeamInputStyle(currentConfigAlertLevel)"
          type="number"
          :min="challengeTeamLimits.min"
          :max="challengeTeamLimits.max"
          :value="paramMapByName['c_teams']?.value"
          @input="updateByName('c_teams', Number(($event.target as HTMLInputElement).value || 0))"
        />
        <label class="text-sm font-medium">Teams</label>
        <InfoPopover :text="paramMapByName['c_teams']?.ui_description"/>
      </div>

      <!-- Jury lanes -->
      <div class="mb-1">
        <div class="flex items-center gap-2">
          <RadioGroup v-model="jLanesProxy" class="flex gap-1">
            <RadioGroupOption
                v-for="n in lanePalette"
                :key="'j_lane_' + n"
                :value="n"
                :disabled="!isLaneAllowed(n)"
                v-slot="{ checked, disabled }"
            >
              <button
                  type="button"
                  class="relative px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  :class="[
                    checked ? getAlertLevelStyle(currentConfigAlertLevel) : '',
                    disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400',
                    // highlight recommended
                    (!disabled && isLaneRecommended(n)) ? 'after:absolute after:-top-2 ' +
                     'after:-right-2 after:text-[10px] after:px-1.5 after:py-0.5 after:bg-emerald-100 ' +
                      'after:text-emerald-700 after/rounded after:content-[\'Empfohlen\']' : ''
                  ]"
                  :aria-disabled="disabled"
              >
                {{ n }}
              </button>
            </RadioGroupOption>
          </RadioGroup>

          <span class="text-sm font-medium">Jurygruppe(n)</span>
          <InfoPopover :text="paramMapByName['j_lanes']?.ui_description"/>
          <span class="text-xs text-gray-500 italic">
            {{ teamsPerJuryHint }}
          </span>
        </div>

        <p v-if="cTeams && allowedJuryLanes.length === 0" class="text-xs text-gray-500 mt-1">
          Keine gültigen Spurenzahlen für die aktuelle Teamanzahl.
        </p>

      </div>


      <!-- Robot game tables -->
      <div class="mb-3">
        <div class="flex items-center gap-2">
          <RadioGroup v-model="rTablesProxy" class="flex gap-1">
            <RadioGroupOption
                v-for="tb in [2,4]"
                :key="'tables_' + tb"
                :value="tb"
                :disabled="tableVariantsForTeams.length > 0 && !tableVariantsForTeams.includes(tb)"
                v-slot="{ checked, disabled }"
            >
              <button
                  type="button"
                  class="px-2 py-1 rounded-md border text-sm transition
                       focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                  :class="[
                    checked ? getAlertLevelStyle(currentConfigAlertLevel) : '',
                    disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
                  ]"
                  :aria-disabled="disabled"
                  @click="!disabled && updateByName('r_tables', tb)"
              >
                {{ tb }}
              </button>
            </RadioGroupOption>
          </RadioGroup>
          <span class="text-sm font-medium">Robot-Game Tische</span>
          <InfoPopover :text="paramMapByName['r_tables']?.ui_description"/>
        </div>
      </div>      


    </template>

    <!-- Message when challenge is disabled -->
    <div v-else class="text-center py-8 text-gray-500">
      <div class="text-lg font-medium mb-2">Challenge ist deaktiviert</div>
      <div class="text-sm">Aktiviere den Schalter oben rechts, um Challenge-Einstellungen zu konfigurieren.</div>
    </div>

    <!-- Alert message banner -->
    <div v-if="currentLaneNote && (currentConfigAlertLevel === 2 || currentConfigAlertLevel === 3)" 
         class="mt-3 inline-flex items-center gap-1 px-2 py-1 rounded text-xs"
         :class="{
           'bg-orange-100/60 border border-orange-300/40 text-orange-700': currentConfigAlertLevel === 2,
           'bg-red-100/60 border border-red-300/40 text-red-700': currentConfigAlertLevel === 3
         }">
      <span class="text-xs">⚠</span>
      {{ currentLaneNote }}
    </div>
  </div>
</template>