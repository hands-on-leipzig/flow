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
import InsertBlocks from "@/components/molecules/InsertBlocks.vue";
import {buildLanesIndex, type LanesIndex, type LaneRow} from '@/utils/lanesIndex'
import FllEvent from "@/models/FllEvent";
import {Parameter, ParameterCondition} from "@/models/Parameter"
import { programLogoSrc, programLogoAlt } from '@/utils/images'  

const eventStore = useEventStore()
const selectedEvent = computed<FllEvent | null>(() => eventStore.selectedEvent)
const parameters = ref<Parameter[]>([])


const inputName = ref('')
const plans = ref<Array<{ id: number, name: string, is_chosen?: boolean }>>([])
const selectedPlanId = ref<number | null>(null)
const loading = ref(true)

const SPECIAL_KEYS = new Set([
  'e1_teams', 'e2_teams',
  'c_teams', 'c_tables', 'j_lanes',
  'e_mode',
  'e1_lanes', 'e2_lanes'
])
const isSpecial = (p: Parameter) => SPECIAL_KEYS.has((p.name || '').toLowerCase())

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

// zusätzlich zur Parameterliste
const originalValues = ref<Record<string, any>>({})

const fetchParams = async (planId: number) => {
  if (!planId) return
  loading.value = true
  try {
    const {data: rawParams} = await axios.get<Parameter[]>(`/plans/${planId}/parameters`)
    const {data: conditions} = await axios.get<ParameterCondition[]>('/parameter/condition')
    // Defensive: backend could send a single object or null
    parameters.value = Array.isArray(rawParams) ? rawParams : []
    displayConditions.value = Array.isArray(conditions) ? conditions : []

    // Hier Originalwerte ablegen
    originalValues.value = Object.fromEntries(
      parameters.value.map(p => [p.name, p.value])
    )

    // Initial toggle states based on params
    showExplore.value = Number(paramMapByName.value['e_mode']?.value || 0) > 0
    showChallenge.value = Number(paramMapByName.value['c_mode']?.value || 0) > 0

    console.log('Fetched parameters:', parameters.value.length)
    console.log('Expert parameters:', parameters.value.filter(p => p.context === 'expert').length)
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
        .filter((p: Parameter) => {
          if (p.context !== 'expert') return false
          
          // Filter based on toggle states
          if (p.first_program === 2 && !showExplore.value) return false // Explore disabled
          if (p.first_program === 3 && !showChallenge.value) return false // Challenge disabled
          
          return true
        })
        .sort((a: Parameter, b: Parameter) => (a.first_program || 0) - (b.first_program || 0))
)

const finaleParams = computed(() =>
    parameters.value.filter((p: Parameter) =>
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
const PARAM_DEBOUNCE_DELAY = 2000

// Toast notification system
const showToast = ref(false)
const progress = ref(100)
const progressIntervalId = ref<NodeJS.Timeout | null>(null)



// Handle parameter updates from child components
function handleParamUpdate(param: { name: string, value: any }) {
  const p = paramMapByName.value[param.name]
  if (!p) {
    console.warn('Parameter not found:', param.name)
    return
  }

  // Normalisieren für stabilen Vergleich
  const oldVal = String(originalValues.value[param.name] ?? '')
  const newVal = String(param.value ?? '')

  if (oldVal === newVal) {
    console.log(`No change for ${param.name}, skipping update`)
    return
  }

  console.log(`Param change detected → ${param.name}: ${oldVal} → ${newVal}`)

  // Update local state immediately
  p.value = param.value

  // Add to pending updates
  pendingParamUpdates.value[param.name] = param.value

  // Show toast and start progress animation
  showToast.value = true
  startProgressAnimation()

  // Clear existing timeout
  if (paramUpdateTimeoutId.value) {
    clearTimeout(paramUpdateTimeoutId.value)
  }

  // Schedule batch update
  paramUpdateTimeoutId.value = setTimeout(() => {
    flushParamUpdates()
  }, PARAM_DEBOUNCE_DELAY)
}

// Handle block updates from InsertBlocks component
function handleBlockUpdates(updates: Array<{ name: string, value: any }>) {
  console.log('Received block updates:', updates)

  // Add all block updates to pending parameter updates
  updates.forEach(update => {
    pendingParamUpdates.value[update.name] = update.value
  })

  // Show toast and start progress animation
  showToast.value = true
  startProgressAnimation()

  // Clear existing timeout
  if (paramUpdateTimeoutId.value) {
    clearTimeout(paramUpdateTimeoutId.value)
  }

  // Schedule batch update
  paramUpdateTimeoutId.value = setTimeout(() => {
    flushParamUpdates()
  }, PARAM_DEBOUNCE_DELAY)
}

// Start progress animation
function startProgressAnimation() {
  // Reset progress
  progress.value = 100

  // Clear existing interval
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
  }

  // Calculate step size (100 steps over the debounce delay)
  const stepSize = 100 / (PARAM_DEBOUNCE_DELAY / 50) // Update every 50ms

  progressIntervalId.value = setInterval(() => {
    progress.value -= stepSize
    if (progress.value <= 0) {
      progress.value = 0
      clearInterval(progressIntervalId.value!)
      progressIntervalId.value = null
    }
  }, 50)
}

// Force immediate update of all pending parameter changes
function flushParamUpdates() {
  if (paramUpdateTimeoutId.value) {
    clearTimeout(paramUpdateTimeoutId.value)
    paramUpdateTimeoutId.value = null
  }

  // Clear progress animation
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
    progressIntervalId.value = null
  }

  // Hide toast
  showToast.value = false
  progress.value = 100

  if (Object.keys(pendingParamUpdates.value).length > 0) {
    const updates = {...pendingParamUpdates.value}
    pendingParamUpdates.value = {}

    updateParams(Object.entries(updates).map(([name, value]) => ({name, value})))
  }
}

// Cleanup on unmount
onUnmounted(() => {
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
  }
  flushParamUpdates()
})

function normalizeValue(value: any, type: string | undefined) {
  if (type === 'boolean') {
    return value ? 1 : 0
  }
  return value
}

// Unified update function for single or multiple parameters

async function updateParams(params: Array<{ name: string, value: any }>, afterUpdate?: () => Promise<void>) {
  if (!selectedPlanId.value) return

  loading.value = true
  try {
    // Separate parameter updates from block updates
    const paramUpdates = params.filter(p => !p.name.startsWith('block_'))
    const blockUpdates = params.filter(p => p.name.startsWith('block_'))

    // 1. Save parameters
    if (paramUpdates.length > 0) {
      await axios.post(`/plans/${selectedPlanId.value}/parameters`, {
        parameters: paramUpdates.map(({name, value}) => {
          const p = paramMapByName.value[name]
          return {
            id: p?.id,
            value: normalizeValue(value, p?.type)?.toString() ?? ''
          }
        })
      })

      // Nach erfolgreichem Speichern: originalValues anpassen
      params.forEach(({ name, value }) => {
        originalValues.value[name] = value
      })
    }

    // 2. Save block updates
    let needsRegeneration = false
    if (blockUpdates.length > 0) {
      // Group block updates by block ID
      const updatesByBlock: Record<string, Record<string, any>> = {}
      blockUpdates.forEach(({name, value}) => {
        const [, blockId, field] = name.split('_', 3)
        if (!updatesByBlock[blockId]) updatesByBlock[blockId] = {}
        updatesByBlock[blockId][field] = value
      })

      // Save each block with regeneration optimization
      for (const [blockId, updates] of Object.entries(updatesByBlock)) {
        const block = {id: parseInt(blockId), ...updates}
        
        // Check if only non-timing fields changed
        const timingFields = ['start', 'end', 'buffer_before', 'duration', 'buffer_after', 'insert_point', 'first_program']
        const hasTimingChanges = Object.keys(updates).some(field => timingFields.includes(field))
        
        // Track if any block needs regeneration
        if (hasTimingChanges) {
          needsRegeneration = true
        }
        
        // Add skip_regeneration flag if only non-timing fields changed
        if (!hasTimingChanges) {
          block.skip_regeneration = true
        }
        
        await axios.post(`/plans/${selectedPlanId.value}/extra-blocks`, block)
      }
    }
  } catch (error) {
    console.error('Error saving parameters/blocks:', error)
    loading.value = false
    return
  }
  loading.value = false

  // 3. Generator starten nur wenn nötig (wiederverwendet runGeneratorOnce)
  if (needsRegeneration || paramUpdates.length > 0) {
    await runGeneratorOnce(afterUpdate)
  } else {
    console.log('Skipping regeneration - only non-timing extra block fields changed')
    if (afterUpdate) await afterUpdate()
  }
}

const isGenerating = ref(false)

async function runGeneratorOnce() {
  if (!selectedPlanId.value) return
  isGenerating.value = true
  try {
    await axios.post(`/plans/${selectedPlanId.value}/generate`)
    await pollUntilReady(selectedPlanId.value)
  } catch (error) {
    console.error("Error during initial generation:", error)
  } finally {
    isGenerating.value = false
  }
}


async function pollUntilReady(planId: number, timeoutMs = 60000, intervalMs = 1000) {
  const start = Date.now()

  while (Date.now() - start < timeoutMs) {
    const res = await axios.get(`/plans/${planId}/status`)
    if (res.data.status === 'done') return
    await new Promise(resolve => setTimeout(resolve, intervalMs))
  }

  throw new Error('Timeout: Plan generation took too long')
}

// Legacy function - kept for backward compatibility but not used in new batch system
const updateParam = async (param: Parameter) => {
  console.warn('updateParam called directly - this should not happen in new batch system')
  // This function is kept for backward compatibility but should not be used
  // All updates should go through handleParamUpdate for batching
}

const expertParamsGrouped = computed(() => {
  // Use the filtered expertParams instead of filtering again
  return expertParams.value
      .sort((a: Parameter, b: Parameter) => (a.sequence ?? 0) - (b.sequence ?? 0))
      .reduce((acc: Record<string, Parameter[]>, param: Parameter) => {
        const key = param.program_name || 'Unassigned'
        if (!acc[key]) acc[key] = []
        acc[key].push(param)
        return acc
      }, {} as Record<string, Parameter[]>)
})

async function getOrCreatePlan() {
  if (!selectedEvent.value) return

  const res = await axios.get(`/plans/event/${selectedEvent.value.id}`)
  const planData = res.data

  plans.value = [planData]
  selectedPlanId.value = planData.id

  await fetchParams(selectedPlanId.value as number)

  // Generator nur starten, wenn Plan neu ist
  if (planData.existing === false) {
    console.log("New plan detected → run generator once")
    await runGeneratorOnce()
  }
}

const openGroup = ref<string | null>(null)
const toggle = (id: string) => {
  openGroup.value = openGroup.value === id ? null : id
}

function isTimeParam(param: Parameter) {
  return (
      (param.type === 'time' || (param.name && param.name.toLowerCase().includes('duration'))) &&
      param.context !== 'expert'
  )
}


const lanesIndex = ref<LanesIndex | null>(null)
const supportedPlanData = ref<any[] | null>(null)

onMounted(async () => {
  openGroup.value = "general"
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  if (!selectedEvent.value) {
    console.error('No selected event could be loaded.')
    return
  }
  await getOrCreatePlan()

  const {data} = await axios.get('/parameter/lanes-options')
  const rows: LaneRow[] = Array.isArray(data?.rows) ? data.rows : data
  lanesIndex.value = buildLanesIndex(rows)
  supportedPlanData.value = rows
})
</script>

<template>
  <div class="h-screen p-6 flex flex-col space-y-5">

    <!-- Toast notification for pending parameter updates -->
    <div v-if="showToast"
         class="fixed top-4 right-4 z-50 bg-green-50 border border-green-200 rounded-lg shadow-lg p-4 min-w-80 max-w-md">
      <div class="flex items-center gap-3">
        <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
        <span class="text-green-800 font-medium">Parameter-Änderungen werden gespeichert...</span>
      </div>
      <!--<div class="mt-3 bg-green-200 rounded-full h-2 overflow-hidden">
        <div class="bg-green-500 h-full transition-all duration-75 ease-linear"
             :style="{ width: progress + '%' }"></div>
      </div>-->
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
            <ExploreSettings
                :parameters="parameters"
                :show-explore="showExplore"
                @toggle-show="(v) => showExplore = v"
                :lanes-index="lanesIndex"
                :supported-plan-data="supportedPlanData"
                @update-param="handleParamUpdate"
            />
            <ChallengeSettings
                :parameters="parameters"
                :show-challenge="showChallenge"
                :lanes-index="lanesIndex"
                :supported-plan-data="supportedPlanData"
                @toggle-show="(v) => showChallenge = v"
                @update-param="handleParamUpdate"
            />

            <TimeSettings
                :parameters="parameters"
                :visibilityMap="visibilityMap"
                :disabledMap="disabledMap"
                :show-explore="showExplore"
                :show-challenge="showChallenge"
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
            <!-- Left column: Explore or turned off message -->
            <div>
                  <div class="flex items-center gap-2 mb-2">
                    <img
                        :src="programLogoSrc('E')"
                        :alt="programLogoAlt('E')"
                        class="w-10 h-10 flex-shrink-0"
                      />
                    <h3 class="text-lg font-semibold capitalize">
                      <span class="italic">FIRST</span> LEGO League Explore
                    </h3>
                  </div>
              <div v-if="showExplore">
                <template v-for="(group, programName) in expertParamsGrouped" :key="programName">
                  <template v-if="programName.toLowerCase().includes('explore')">
                    <template v-for="param in group" :key="param.id">
                      <ParameterField
                          v-if="visibilityMap[param.id]"
                          :param="param"
                          :disabled="disabledMap[param.id]"
                          :with-label="true"
                          :horizontal="true"
                          @update="(param: Parameter) => handleParamUpdate({name: param.name, value: param.value})"
                      />
                    </template>
                  </template>
                </template>
              </div>
              <div v-else class="text-center py-8 text-gray-500">
                <div class="text-sm font-medium mb-1">Explore ist deaktiviert</div>
                <div class="text-xs">Aktiviere Explore, um Expertenparameter zu konfigurieren.</div>
              </div>
            </div>

            <!-- Right column: Challenge or turned off message -->
            <div>
                  <div class="flex items-center gap-2 mb-2">
                    <img
                        :src="programLogoSrc('C')"
                        :alt="programLogoAlt('C')"
                        class="w-10 h-10 flex-shrink-0"
                      />
                    <h3 class="text-lg font-semibold capitalize">
                      <span class="italic">FIRST</span> LEGO League Challenge
                    </h3>
                  </div>
              <div v-if="showChallenge">
                <template v-for="(group, programName) in expertParamsGrouped" :key="programName">
                  <template v-if="programName.toLowerCase().includes('challenge')">
                    <template v-for="param in group" :key="param.id">
                      <ParameterField
                          v-if="visibilityMap[param.id]"
                          :param="param"
                          :disabled="disabledMap[param.id]"
                          :with-label="true"
                          :horizontal="true"
                          @update="(param: Parameter) => handleParamUpdate({name: param.name, value: param.value})"
                      />
                    </template>
                  </template>
                </template>
              </div>
              <div v-else class="text-center py-8 text-gray-500">
                <div class="text-sm font-medium mb-1">Challenge ist deaktiviert</div>
                <div class="text-xs">Aktiviere Challenge, um Expertenparameter zu konfigurieren.</div>
              </div>
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
                  @update="(param: Parameter) => handleParamUpdate({name: param.name, value: param.value})"
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
          <InsertBlocks
              :plan-id="selectedPlanId as number"
              :event-level="selectedEvent?.level ?? null"
              :on-update="handleBlockUpdates"
              :show-explore="showExplore"
              :show-challenge="showChallenge"
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
