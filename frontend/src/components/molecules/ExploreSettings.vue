<script setup lang="ts">
import {computed, ref, UnwrapRef, watch} from 'vue'
import {RadioGroup, RadioGroupOption} from '@headlessui/vue'
import SplitBar from '@/components/atoms/SplitBar.vue'
import type {LanesIndex} from '@/utils/lanesIndex'
import InfoPopover from "@/components/atoms/InfoPopover.vue"
import {useEventStore} from '@/stores/event'

const props = defineProps<{
  parameters: any[]
  showExplore: boolean
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
    // Turn on explore - default to mode 1 (integrated AM) if currently off
    if (eMode.value === 0) {
      setMode(1)
    }

    // Use DRAHT team count as default if available, otherwise use min
    const eventStore = useEventStore()
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
const isIntegratedAM = computed(() => eMode.value === 1)
const isIntegratedPM = computed(() => eMode.value === 2)
const isSeparateAM = computed(() => eMode.value === 3)
const isSeparatePM = computed(() => eMode.value === 4)
const isSeparateSplit = computed(() => eMode.value === 5)

const isIntegrated = computed(() => eMode.value === 1 || eMode.value === 2)
const isIndependent = computed(() => eMode.value === 3 || eMode.value === 4 || eMode.value === 5)
const hasExplore = computed(() => eMode.value > 0)

/** Fancy mode changes **/
function setMode(mode: 0 | 1 | 2 | 3 | 4 | 5) {
  eMode.value = mode
  const total = eTeams.value

  // Reset team counts and lane counts based on mode
  if (mode === 0) {
    // No explore - clear all team and lane counts
    updateByName('e1_teams', 0)
    updateByName('e2_teams', 0)
    updateByName('e1_lanes', 0)
    updateByName('e2_lanes', 0)
  } else if (mode === 1) {
    // Integrated AM - all teams in e1_teams, clear PM
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
    updateByName('e2_lanes', 0)
  } else if (mode === 2) {
    // Integrated PM - all teams in e2_teams, clear AM
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
    updateByName('e1_lanes', 0)
  } else if (mode === 3) {
    // Separate AM - all teams in e1_teams, clear PM
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
    updateByName('e2_lanes', 0)
  } else if (mode === 4) {
    // Separate PM - all teams in e2_teams, clear AM
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
    updateByName('e1_lanes', 0)
  } else if (mode === 5) {
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

  // Update e1_teams and e2_teams based on current mode
  if (eMode.value === 0) {
    // No explore - clear all
    updateByName('e1_teams', 0)
    updateByName('e2_teams', 0)
  } else if (eMode.value === 1) {
    // Integrated AM - all in e1_teams
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  } else if (eMode.value === 2) {
    // Integrated PM - all in e2_teams
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
  } else if (eMode.value === 3) {
    // Separate AM - all in e1_teams
    updateByName('e1_teams', total)
    updateByName('e2_teams', 0)
  } else if (eMode.value === 4) {
    // Separate PM - all in e2_teams
    updateByName('e1_teams', 0)
    updateByName('e2_teams', total)
  } else if (eMode.value === 5) {
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

  // For mode 3 (separate AM), use total teams. For mode 5 (split), use actual e1Teams
  const teamCount = (eMode.value === 3) ? eTeams.value : e1Teams.value
  if (!teamCount) return []

  const key = `${teamCount}`
  return props.lanesIndex.explore[key] ?? []
})
const allowedExploreLanesPM = computed<number[]>(() => {
  if (!props.lanesIndex) return []

  // For mode 4 (separate PM), use total teams. For mode 5 (split), use actual e2Teams
  const teamCount = (eMode.value === 4) ? eTeams.value : e2Teams.value
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

/** SplitBar updates **/
const onUpdateE1 = (val: number) => {
  updateByName('e1_teams', val)
  // Ensure total is always the sum
  const total = val + e2Teams.value
  if (total !== eTeams.value) {
    updateByName('e_teams', total)
  }
}
const onUpdateE2 = (val: number) => {
  updateByName('e2_teams', val)
  // Ensure total is always the sum
  const total = e1Teams.value + val
  if (total !== eTeams.value) {
    updateByName('e_teams', total)
  }
}

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
</script>

<template>
  <div class="p-4 border rounded shadow">
    <div class="flex items-center justify-between mb-2">
      <div class="flex items-center gap-2">
        <h2 class="text-lg font-semibold">Explore Einstellungen</h2>

      </div>
      <label class="relative inline-flex items-center cursor-pointer">
        <input
            type="checkbox"
            :checked="showExplore"
            @change="handleToggleChange($event.target as HTMLInputElement)"
            class="sr-only peer"
        >
        <div class="w-11 h-6 bg-gray-300 rounded-full peer-checked:bg-blue-600 transition-colors"></div>
        <div
            class="absolute left-0.5 top-0.5 bg-white w-5 h-5 rounded-full shadow transform peer-checked:translate-x-full transition-transform"></div>
      </label>
    </div>

    <div v-if="hasExplore" class="mb-3">
      <label class="text-sm font-medium">Anzahl Teams</label> &nbsp;
      <input
          class="mt-1 w-32 border rounded px-2 py-1"
          type="number"
          :min="exploreTeamLimits.min"
          :max="exploreTeamLimits.max"
          :value="paramMapByName['e_teams']?.value"
          @input="updateByName('e_teams', Number(($event.target as HTMLInputElement).value || 0))"
      />
      <InfoPopover :text="paramMapByName['e_teams']?.ui_description"/>
    </div>

    <!-- Fancy e_mode selector -->
    <div v-if="hasExplore">
      <div class="space-y-2 mb-4">
        <!-- Integrated with Challenge -->
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">Integriert in Challenge</span>
          <button
              type="button"
              class="px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
              :class="eMode === 1 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
              @click="setMode(1)"
          >
            Vormittags
          </button>
          <button
              type="button"
              class="px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
              :class="eMode === 2 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
              @click="setMode(2)"
          >
            Nachmittags
          </button>
          <InfoPopover :text="paramMapByName['e_mode']?.ui_description"/>
        </div>

        <!-- Independent from Challenge -->
        <div class="flex items-center gap-2">
          <span class="text-sm font-medium">Getrennt von Challenge</span>
          <button
              type="button"
              class="px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
              :class="eMode === 3 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
              @click="setMode(3)"
          >
            Vormittags
          </button>
          <button
              type="button"
              class="px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
              :class="eMode === 4 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
              @click="setMode(4)"
          >
            Nachmittags
          </button>
          <button
              type="button"
              class="px-2 py-1 rounded-md border text-sm transition
                   focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
              :class="eMode === 5 ? 'ring-1 ring-gray-500 bg-gray-100' : 'hover:border-gray-400'"
              @click="setMode(5)"
          >
            Geteilt
          </button>
        </div>
      </div>
    </div>

    <!-- Message when explore is disabled -->
    <div v-else class="text-center py-8 text-gray-500">
      <div class="text-lg font-medium mb-2">Explore ist deaktiviert</div>
      <div class="text-sm">Aktivieren Sie den Schalter oben rechts, um Explore-Einstellungen zu konfigurieren.</div>
    </div>

    <!-- INTEGRATED (1/2): inline lane selector bound to e1_lanes (allowed by total e_teams) -->
    <div v-if="hasExplore && isIntegrated" class="mt-4 flex">
      <div class="flex items-center gap-2">
        <span class="text-sm font-medium">Jury</span>
        <RadioGroup v-model="integratedLanesProxy" class="flex gap-1">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_int_' + n"
              :value="n"
              :disabled="!isExploreLaneAllowedIntegrated(n)"
              v-slot="{ checked, disabled }"
          >
            <button
                type="button"
                class="px-2 py-1 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="[
                  checked ? 'ring-1 ring-gray-500' : '',
                  disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
                ]"
                :aria-disabled="disabled"
            >
              {{ n }}
            </button>
          </RadioGroupOption>
        </RadioGroup>
        <span class="text-sm font-medium">-spurig</span>
        <InfoPopover :text="paramMapByName['e1_lanes']?.ui_description"/>
      </div>
    </div>

    <!-- SPLIT slider only for mode 5 -->
    <div v-if="hasExplore">
      <SplitBar
          v-if="isSeparateSplit && paramMapByName['e_teams']?.value"
          :e1="Number(paramMapByName['e1_teams']?.value || 0)"
          :e2="Number(paramMapByName['e2_teams']?.value || 0)"
          :total="Number(paramMapByName['e_teams']?.value || 0)"
          @update:e1="(v:number) => updateByName('e1_teams', v)"
          @update:e2="(v:number) => updateByName('e2_teams', v)"
          class="mt-3"
      />
    </div>

    <!-- Two columns when independent (3, 4, or 5) -->
    <div v-if="hasExplore && isIndependent" class="mt-4 grid grid-cols-2 gap-8 text-gray-800">
      <!-- AM -->
      <div :class="(eMode === 4 || e1Teams === 0) ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Vormittag
        </div>
        <RadioGroup v-model="eLanesAMProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_am_' + n"
              :value="n"
              :disabled="!isExploreLaneAllowedAM(n) || e1Teams === 0"
              v-slot="{ checked, disabled }"
          >
            <button
                type="button"
                class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="[
                checked ? 'ring-1 ring-gray-500' : '',
                disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
              ]"
                :aria-disabled="disabled"
            >
              {{ n }}-spurig
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>

      <!-- PM -->
      <div :class="(eMode === 3 || e2Teams === 0) ? 'opacity-40 pointer-events-none' : ''">
        <div class="text-sm font-medium mb-1">
          Nachmittag
        </div>
        <RadioGroup v-model="eLanesPMProxy" class="flex flex-wrap gap-2">
          <RadioGroupOption
              v-for="n in allLaneOptions"
              :key="'e_lane_pm_' + n"
              :value="n"
              :disabled="!isExploreLaneAllowedPM(n) || e2Teams === 0"
              v-slot="{ checked, disabled }"
          >
            <button
                type="button"
                class="px-3 py-1.5 rounded-md border text-sm transition
                     focus:outline-none focus:ring-2 focus:ring-offset-1 border-gray-300"
                :class="[
                checked ? 'ring-1 ring-gray-500' : '',
                disabled ? 'opacity-40 cursor-not-allowed' : 'hover:border-gray-400'
              ]"
                :aria-disabled="disabled"
            >
              {{ n }}-spurig
            </button>
          </RadioGroupOption>
        </RadioGroup>
      </div>
    </div>
  </div>
</template>
