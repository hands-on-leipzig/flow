<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref} from 'vue'
import axios from 'axios'
import ParameterField from "@/components/molecules/ParameterField.vue"

import {useEventStore} from '@/stores/event'
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue"
import TimeSettings from "@/components/molecules/TimeSettings.vue";
import ExploreSettings from "@/components/molecules/ExploreSettings.vue";
import ChallengeSettings from "@/components/molecules/ChallengeSettings.vue";
import Preview from "@/components/molecules/Preview.vue";
import LoaderFlow from "@/components/atoms/LoaderFlow.vue";
import LoaderText from "@/components/atoms/LoaderText.vue";

const eventStore = useEventStore()
const selectedEvent = computed<FllEvent | null>(() => eventStore.selectedEvent)
const parameters = ref<Parameter[]>([])


const inputName = ref('')
const plans = ref<any[]>([])
const selectedPlanId = ref<number | null>(null)
const loading = ref(true)
import {buildLanesIndex, type LanesIndex, type LaneRow} from '@/utils/lanesIndex'
import ExtraBlocks from "@/components/molecules/ExtraBlocks.vue";
import FllEvent from "@/models/FllEvent";
import {Parameter, ParameterCondition} from "@/models/Parameter"

const SPECIAL_KEYS = new Set([
  'e1_teams', 'e2_teams',
  'c_teams', 'c_tables', 'j_lanes',
  'e_mode',
  'e1_lanes', 'e2_lanes'
])
const isSpecial = (p: any) => SPECIAL_KEYS.has((p.name || '').toLowerCase())

const paramMap = computed<Record<number, Parameter>>(() => {
  const map: Record<number, Parameter> = {}
  for (const p of parameters.value) map[p.id] = p
  return map
})

const paramMapByName = computed<Record<string, Parameter>>(() => {
  const map: Record<string, Parameter> = {}
  for (const p of parameters.value) if (p.name) map[p.name] = p
  return map
})

const displayConditions = ref<ParameterCondition[]>([])

function matchCondition(cond: ParameterCondition, other: Parameter | undefined): boolean {
  if (!other) return false
  const val = other.value

  // numeric ops coerce to numbers
  if (cond.is === '<' || cond.is === '<=' || cond.is === '>' || cond.is === '>=') {
    const a = Number(val)
    const b = Number(cond.value)
    if (!Number.isFinite(a) || !Number.isFinite(b)) return false
    if (cond.is === '<') return a < b
    if (cond.is === '<=') return a <= b
    if (cond.is === '>') return a > b
    if (cond.is === '>=') return a >= b
  }

  // equality ops: loose == for backend string values, but you can switch to strict if you normalize
  if (cond.is === '=') return (val as any) == (cond.value as any)
  if (cond.is === '!=') return (val as any) != (cond.value as any)

  // unknown operator → no match
  return false
}

const visibilityMap = computed<Record<number, boolean>>(() => {
  const map: Record<number, boolean> = {}
  for (const param of parameters.value) {
    const relevant = displayConditions.value.filter(c => c.parameter === param.id)
    const shouldHide = relevant.some(cond => matchCondition(cond, paramMap.value[cond.if_parameter]) && cond.action === 'hide')
    map[param.id] = !shouldHide
  }
  return map
})

const disabledMap = computed<Record<number, boolean>>(() => {
  const map: Record<number, boolean> = {}
  for (const param of parameters.value) {
    const relevant = displayConditions.value.filter(c => c.parameter === param.id)
    map[param.id] = relevant.some(cond => matchCondition(cond, paramMap.value[cond.if_parameter]) && cond.action === 'disable')
  }
  return map
})

const fetchParams = async (planId: number) => {
  if (!planId) return
  loading.value = true
  try {
    const {data: rawParams} = await axios.get<Parameter[]>(`/plans/${planId}/parameters`)
    const {data: conditions} = await axios.get<ParameterCondition[]>('/parameter/condition')
    // Defensive: backend could send a single object or null
    parameters.value = Array.isArray(rawParams) ? rawParams : []
    displayConditions.value = Array.isArray(conditions) ? conditions : []
  } catch (err) {
    console.error("Failed to fetch params or conditions:", err)
    parameters.value = []
    displayConditions.value = []
  } finally {
    loading.value = false
  }
}


const showExplore = ref(true)
const showChallenge = ref(true)

const expertParams = computed(() =>
    parameters.value
        .filter((p: any) => p.context === 'expert')
        .sort((a: any, b: any) => (a.first_program || 0) - (b.first_program || 0))
)

const finaleParams = computed(() =>
    parameters.value.filter((p: any) =>
        p.context === 'finale' &&
        !isTimeParam(p) &&
        !isSpecial(p) &&
        ((p.first_program === 2 && showExplore.value) ||
            (p.first_program === 3 && showChallenge.value) ||
            (p.first_program !== 2 && p.first_program !== 3))
    )
)

function updateByName(name: string, value: any) {
  const p = paramMapByName.value[name]
  if (!p) return
  p.value = value
  updateParam(p)
}

// Batch parameter update system
const pendingParamUpdates = ref<Record<string, any>>({})
const paramUpdateTimeoutId = ref<NodeJS.Timeout | null>(null)
const PARAM_DEBOUNCE_DELAY = 5000 // 5 seconds

// Track if there are pending parameter updates
const hasPendingParamUpdates = computed(() => Object.keys(pendingParamUpdates.value).length > 0)

// Handle parameter updates from child components
function handleParamUpdate(param: { name: string, value: any }) {
  const p = paramMapByName.value[param.name]
  if (!p) {
    console.warn('Parameter not found:', param.name)
    return
  }

  // Update local state immediately
  p.value = param.value

  // Add to pending updates
  pendingParamUpdates.value[param.name] = param.value

  // Clear existing timeout
  if (paramUpdateTimeoutId.value) {
    clearTimeout(paramUpdateTimeoutId.value)
  }

  // Schedule batch update
  paramUpdateTimeoutId.value = setTimeout(() => {
    flushParamUpdates()
  }, PARAM_DEBOUNCE_DELAY)
}

// Force immediate update of all pending parameter changes
function flushParamUpdates() {
  if (paramUpdateTimeoutId.value) {
    clearTimeout(paramUpdateTimeoutId.value)
    paramUpdateTimeoutId.value = null
  }

  if (Object.keys(pendingParamUpdates.value).length > 0) {
    const updates = {...pendingParamUpdates.value}
    pendingParamUpdates.value = {}

    updateParams(Object.entries(updates).map(([name, value]) => ({name, value})))
  }
}

// Cleanup on unmount
onUnmounted(() => {
  flushParamUpdates()
})

function normalizeValue(value: any, type: string | undefined) {
  if (type === 'boolean') {
    return value ? 1 : 0
  }
  return value
}

// Unified update function for single or multiple parameters
// Including new "isGenerating" state

const isGenerating = ref(false)

async function updateParams(params: Array<{ name: string, value: any }>, afterUpdate?: () => Promise<void>) {
  if (!selectedPlanId.value) return

  loading.value = true
  isGenerating.value = true

  try {
    await axios.post(`/plans/${selectedPlanId.value}/parameters`, {
      parameters: params.map(({name, value}) => {
        const p = paramMapByName.value[name]
        return {
          id: p?.id,
          value: normalizeValue(value, p?.type)?.toString() ?? ''
        }
      })
    })

    if (afterUpdate) await afterUpdate()
  } catch (error) {
    console.error('Error updating parameters:', error)
  } finally {
    loading.value = false
    isGenerating.value = false
  }
}

// Legacy function - kept for backward compatibility but not used in new batch system
const updateParam = async (param: any) => {
  console.warn('updateParam called directly - this should not happen in new batch system')
  // This function is kept for backward compatibility but should not be used
  // All updates should go through handleParamUpdate for batching
}

const expertParamsGrouped = computed(() => {
  return parameters.value
      .filter((p: any) => p.context === 'expert')
      .sort((a: any, b: any) => (a.sequence ?? 0) - (b.sequence ?? 0))
      .reduce((acc: any, param: any) => {
        const key = param.program_name || 'Unassigned'
        if (!acc[key]) acc[key] = []
        acc[key].push(param)
        return acc
      }, {})
})

async function fetchPlans() {
  if (!selectedEvent.value) return
  const res = await axios.get(`/events/${selectedEvent.value.id}/plans`)
  plans.value = res.data
  if (plans.value.length > 0) {
    selectedPlanId.value = plans.value[0].id
    await fetchParams(selectedPlanId.value as number)

  } else {
    const newPlanId = await createDefaultPlan()
    if (newPlanId) {
      const newPlan = {id: newPlanId, name: 'Standard-Zeitplan', is_chosen: true}
      plans.value.push(newPlan)
      selectedPlanId.value = newPlanId
      await fetchParams(newPlanId)

    }
  }
}

const createDefaultPlan = async () => {
  try {
    const response = await axios.post(`/plans`, {
      event: selectedEvent?.value?.id,
      name: 'Zeitplan'
    })
    return response.data.id
  } catch (e) {
    console.error('Fehler beim Erstellen des Plans', e)
    return null
  }
}

const openGroup = ref<string | null>(null)
const toggle = (id: string) => {
  openGroup.value = openGroup.value === id ? null : id
}

function isTimeParam(param: any) {
  return (
      (param.type === 'time' || (param.name && param.name.toLowerCase().includes('duration'))) &&
      param.context !== 'expert'
  )
}


const lanesIndex = ref<LanesIndex | null>(null)

onMounted(async () => {
  openGroup.value = "general"
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  if (!selectedEvent.value) {
    console.error('No selected event could be loaded.')
    return
  }
  await fetchPlans()

  const {data} = await axios.get('/parameter/lanes-options')
  const rows: LaneRow[] = Array.isArray(data?.rows) ? data.rows : data
  lanesIndex.value = buildLanesIndex(rows)
})
</script>

<template>
  <div class="h-screen p-6 flex flex-col space-y-5">
    <!-- Pending parameter updates indicator -->
    <div v-if="hasPendingParamUpdates"
         class="flex items-center gap-2 text-orange-600 text-sm bg-orange-50 border border-orange-200 rounded-lg px-4 py-2">
      <div class="w-3 h-3 bg-orange-400 rounded-full animate-pulse"></div>
      <span>Parameter-Änderungen werden in Kürze gespeichert...</span>
    </div>

    <div v-if="false" class="flex items-center space-x-4">
      <label for="plan-select" class="text-sm font-medium">Plan auswählen:</label>
      <select id="plan-select" v-model="selectedPlanId" class="border rounded px-2 py-1 text-sm">
        <option v-for="plan in plans" :key="plan.id" :value="plan.id">
          {{ plan.name }}
        </option>
      </select>

      <label for="name" class="block text-sm font-medium text-gray-700 whitespace-nowrap">Planname</label>
      <input v-model="inputName" id="name" class="border border-gray-300 rounded px-5 py-2 focus:outline-none"
             type="text"/>
    </div>


    <div class="bg-white border rounded-lg shadow">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('general')"
      >
        Allgemein
        <AccordionArrow :opened="openGroup === 'general'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'general'" class="p-4">
          <div class="grid grid-cols-3 gap-4 mt-4">
            <ChallengeSettings
                :parameters="parameters"
                :show-challenge="showChallenge"
                :lanes-index="lanesIndex"
                @toggle-show="(v) => showChallenge = v"
                @update-param="handleParamUpdate"
            />
            <ExploreSettings
                :parameters="parameters"
                :show-explore="showExplore"
                @toggle-show="(v) => showExplore = v"
                :lanes-index="lanesIndex"
                @update-param="handleParamUpdate"
            />
            <TimeSettings
                :parameters="parameters"
                :visibilityMap="visibilityMap"
                :disabledMap="disabledMap"
                @update-param="handleParamUpdate"
            />
          </div>
        </div>
      </transition>
    </div>

    <div class="bg-white border rounded-lg shadow">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('expert')"
      >
        Expertenparameter
        <AccordionArrow :opened="openGroup === 'expert'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'expert'" class="p-4">
          <div class="grid grid-cols-2 gap-6 max-h-[600px] overflow-y-auto">
            <div v-for="(group, programName) in expertParamsGrouped" :key="programName">
              <h4 class="text-md font-semibold mb-2">{{ programName }}</h4>
              <template v-for="param in group" :key="param.id">
                <ParameterField
                    v-if="visibilityMap[param.id]"
                    :param="param"
                    :disabled="disabledMap[param.id]"
                    :with-label="true"
                    :horizontal="true"
                    @update="(param: any) => handleParamUpdate({name: param.name, value: param.value})"
                />
              </template>
            </div>
          </div>
        </div>
      </transition>
    </div>

    <div class="bg-white border rounded-lg shadow" v-if="selectedEvent?.level === 3">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('finals')"
      >
        Finalparameter
        <AccordionArrow :opened="openGroup === 'finals'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'finals'" class="p-4">
          <div class="grid grid-cols-2 gap-6 max-h-[600px] overflow-y-auto">
            <template v-for="param in finaleParams" :key="param.id">
              <ParameterField
                  v-if="visibilityMap[param.id]"
                  :param="param"
                  :disabled="disabledMap[param.id]"
                  :with-label="true"
                  :horizontal="true"
                  @update="(param: any) => handleParamUpdate({name: param.name, value: param.value})"
              />
            </template>
          </div>
        </div>
      </transition>
    </div>

    <div class="bg-white border rounded-lg shadow">
      <button
          class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
          @click="toggle('extras')"
      >
        Zusatzblöcke
        <AccordionArrow :opened="openGroup === 'extras'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'extras'" class="p-4">
          <ExtraBlocks
              :plan-id="selectedPlanId as number"
              :event-level="selectedEvent?.level ?? null"
          />
        </div>
      </transition>
    </div>

    <div class="flex-grow overflow-hidden">
      <div v-if="isGenerating" class="flex items-center justify-center h-full flex-col text-gray-600">
        <LoaderFlow/>
        <LoaderText/>
      </div>
      <Preview
          v-else-if="selectedPlanId"
          :plan-id="selectedPlanId as number"
          initial-view="roles"
      />
    </div>

  </div>
</template>

<style scoped>
details[open] summary::after {
  content: '▲';
  float: right;
}

summary::after {
  content: '▼';
  float: right;
}

.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}
</style>
