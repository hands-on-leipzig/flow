<script setup lang="ts">
import {computed, onMounted, onUnmounted, ref, watch} from 'vue'
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
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import ScheduleToast from "@/components/atoms/ScheduleToast.vue";
import { useDebouncedSave } from "@/composables/useDebouncedSave";
import { DEBOUNCE_DELAY } from "@/constants/extraBlocks";

const eventStore = useEventStore()
const selectedEvent = computed<FllEvent | null>(() => eventStore.selectedEvent)
const parameters = ref<Parameter[]>([])


const inputName = ref('')
const plans = ref<Array<{ id: number, name: string, is_chosen?: boolean }>>([])
const selectedPlanId = ref<number | null>(null)
const loading = ref(true)
const savingToast = ref()

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

// Note: originalValues is managed by useDebouncedSave composable (single source of truth)

const fetchParams = async (planId: number) => {
  if (!planId) return
  loading.value = true
  try {
    const {data: rawParams} = await axios.get<Parameter[]>(`/plans/${planId}/parameters`)
    const {data: conditions} = await axios.get<ParameterCondition[]>('/parameter/condition')
    // Defensive: backend could send a single object or null
    parameters.value = Array.isArray(rawParams) ? rawParams : []
    displayConditions.value = Array.isArray(conditions) ? conditions : []

    // Set original values in composable (single source of truth)
    setOriginals(Object.fromEntries(
        parameters.value.map(p => [p.name, p.value])
    ))

    // Initial toggle states based on params
    showExplore.value = Number(paramMapByName.value['e_mode']?.value || 0) > 0
    showChallenge.value = Number(paramMapByName.value['c_mode']?.value || 0) > 0

    // Parameters loaded successfully
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

          // Exclude level 3 parameters (they go in Finalparameter section)
          if (p.level === 3) return false

          // Filter based on toggle states
          if (p.first_program === 2 && !showExplore.value) return false // Explore disabled
          if (p.first_program === 3 && !showChallenge.value) return false // Challenge disabled

          return true
        })
        .sort((a: Parameter, b: Parameter) => (a.first_program || 0) - (b.first_program || 0))
)

const finaleInputParams = computed(() =>
    parameters.value
        .filter((p: Parameter) =>
            p.level === 3 &&
            p.context === 'input' &&
            !isSpecial(p) &&
            ((p.first_program === 2 && showExplore.value) ||
                (p.first_program === 3 && showChallenge.value) ||
                (p.first_program !== 2 && p.first_program !== 3))
        )
        .sort((a: Parameter, b: Parameter) => (a.sequence || 0) - (b.sequence || 0))
)

const finaleExpertParams = computed(() =>
    parameters.value
        .filter((p: Parameter) =>
            p.level === 3 &&
            p.context === 'expert' &&
            !isTimeParam(p) &&
            !isSpecial(p) &&
            ((p.first_program === 2 && showExplore.value) ||
                (p.first_program === 3 && showChallenge.value) ||
                (p.first_program !== 2 && p.first_program !== 3))
        )
        .sort((a: Parameter, b: Parameter) => (a.sequence || 0) - (b.sequence || 0))
)

function updateByName(name: string, value: any) {
  const p = paramMapByName.value[name]
  if (!p) return
  p.value = value
  updateParam(p)
}

// Toast notification system (legacy - can be removed)
const showToast = ref(false)
const progress = ref(100)
const progressIntervalId = ref<NodeJS.Timeout | null>(null)

// Generator state (must be declared before useDebouncedSave)
const isGenerating = ref(false)
const generatorError = ref<string | null>(null)
const errorDetails = ref<string | null>(null)

// Countdown state for ScheduleToast
const countdownSeconds = ref<number | null>(null)

// Debounced save system using composable
const { scheduleUpdate, flush, immediateFlush, setOriginal, setOriginals, freeze, unfreeze } = useDebouncedSave({
  delay: DEBOUNCE_DELAY,
  isGenerating: () => isGenerating.value,
  onShowToast: (countdown, onImmediateSave) => {
    countdownSeconds.value = countdown
  },
  onHideToast: () => {
    countdownSeconds.value = null
  },
  onCountdownUpdate: (seconds) => {
    countdownSeconds.value = seconds
  },
  changeDetection: (key, newValue, oldValue) => {
    // String comparison for stable detection
    const oldVal = String(oldValue ?? '')
    const newVal = String(newValue ?? '')
    return oldVal !== newVal
  },
  onSave: async (updates) => {
    // Convert updates to the format expected by updateParams
    const updateArray = Object.entries(updates).map(([name, value]) => ({name, value}))
    await updateParams(updateArray)
  }
})

// Note: Generator state freeze/unfreeze is handled automatically by useDebouncedSave
// via the isGenerating() callback in startCountdown() - no manual watcher needed

// Handle parameter updates from child components
function handleParamUpdate(param: { name: string, value: any }) {
  const p = paramMapByName.value[param.name]
  if (!p) {
    if (import.meta.env.DEV) {
      console.debug('Parameter not found:', param.name)
    }
    return
  }

  // Update local state immediately for UI responsiveness
  p.value = param.value

  // Schedule update - composable handles change detection
  scheduleUpdate(param.name, param.value)
}

// Ref to InsertBlocks component
const insertBlocksRef = ref<InstanceType<typeof InsertBlocks> | null>(null)

// Handle block updates from InsertBlocks component
function handleBlockUpdates(updates: Array<{ name: string, value: any, triggerGenerator?: boolean }>) {

  // Add all block updates using composable (triggers debounce)
  updates.forEach(update => {
    // Convert "28_buffer_after" to "block_28_buffer_after" for updateParams compatibility
    const prefixedName = update.name.startsWith('block_') ? update.name : `block_${update.name}`
    scheduleUpdate(prefixedName, update.value)
  })
}

// Force immediate update of all pending parameter changes
function flushParamUpdates() {
  flush()
}

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
  let needsRegeneration = false

  // Separate parameter updates from block generator triggers
  const paramUpdates = params.filter(p => !p.name.startsWith('block_'))
  const blockGeneratorTriggers = params.filter(p => p.name.startsWith('block_'))

  // Set generating state early for immediate user feedback (before block saves)
  if (blockGeneratorTriggers.length > 0) {
    isGenerating.value = true
  }

  try {

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

      // Update original values in composable after successful save (single source of truth)
      paramUpdates.forEach(({name, value}) => {
        setOriginal(name, value)
      })
    }

    // 2. Save all enabled blocks to DB (when countdown triggers)
    if (blockGeneratorTriggers.length > 0 && insertBlocksRef.value) {
      await insertBlocksRef.value.saveAllEnabledBlocks()
      needsRegeneration = true
    }
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('Error saving parameters:', error)
    }
    loading.value = false
    return
  }
  loading.value = false

  // 3. Generator starten nur wenn nötig (wiederverwendet runGeneratorOnce)
  if (needsRegeneration || paramUpdates.length > 0) {
    await runGeneratorOnce(afterUpdate)

    // ✅ Nach erfolgreicher Generierung globalen Readiness-Status aktualisieren
    if (eventStore.selectedEvent?.id) {
      await eventStore.refreshReadiness(eventStore.selectedEvent.id)
    }


  } else {
    if (afterUpdate) await afterUpdate()
  }
}

async function runGeneratorOnce() {
  if (!selectedPlanId.value) return
  
  // Clear previous errors
  generatorError.value = null
  errorDetails.value = null
  
  isGenerating.value = true
  try {
    await axios.post(`/plans/${selectedPlanId.value}/generate`)
    await pollUntilReady(selectedPlanId.value)
  } catch (error: any) {
    if (import.meta.env.DEV) {
      console.error("Error during initial generation:", error)
    }
    
    // Extract error message from response
    let errorMessage = 'Unbekannter Fehler bei der Plan-Generierung'
    let details: string | null = null
    
    if (axios.isAxiosError(error)) {
      const status = error.response?.status
      const errorData = error.response?.data
      
      if (status === 422) {
        errorMessage = errorData?.error || 'Die aktuelle Konfiguration wird nicht unterstützt'
        details = errorData?.details || errorData?.message || 'Ungültige Parameter-Kombination'
      } else if (status === 404) {
        errorMessage = 'Plan nicht gefunden'
        details = errorData?.error || errorData?.details || `Plan ${selectedPlanId.value} existiert nicht`
      } else if (status === 500) {
        errorMessage = errorData?.error || 'Fehler bei der Plan-Generierung'
        details = errorData?.details || errorData?.message || 'Interner Serverfehler'
      } else if (error.message === 'Timeout: Plan generation took too long') {
        errorMessage = 'Zeitüberschreitung'
        details = 'Die Generierung dauert zu lange. Bitte versuche es erneut.'
      } else if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
        errorMessage = 'Verbindungsfehler'
        details = 'Bitte überprüfe deine Internetverbindung.'
      } else {
        errorMessage = errorData?.error || errorData?.message || error.message || errorMessage
      }
    } else if (error instanceof Error) {
      if (error.message.includes('Timeout')) {
        errorMessage = 'Zeitüberschreitung'
        details = 'Die Generierung dauert zu lange. Bitte versuche es erneut.'
      } else {
        errorMessage = error.message
      }
    }
    
    generatorError.value = errorMessage
    errorDetails.value = details
  } finally {
    isGenerating.value = false
    // Unfreeze countdown if there are pending changes
    unfreeze()
  }
}


async function pollUntilReady(planId: number, timeoutMs = 60000, intervalMs = 1000) {
  const start = Date.now()

  while (Date.now() - start < timeoutMs) {
    const res = await axios.get(`/plans/${planId}/status`)
    const status = res.data.status
    
    if (status === 'done') return
    
    // Check for failed status
    if (status === 'failed') {
      generatorError.value = 'Die Generierung ist fehlgeschlagen'
      errorDetails.value = 'Der Plan konnte nicht generiert werden. Bitte überprüfe die Parameter.'
      throw new Error('Generation failed')
    }
    
    await new Promise(resolve => setTimeout(resolve, intervalMs))
  }

  throw new Error('Timeout: Plan generation took too long')
}

// Legacy function - kept for backward compatibility but not used in new batch system
const updateParam = async (param: Parameter) => {
  if (import.meta.env.DEV) {
    console.debug('updateParam called directly - this should not happen in new batch system')
  }
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
  loading.value = true
  openGroup.value = "general"
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  if (!selectedEvent.value) {
    if (import.meta.env.DEV) {
      console.error('No selected event could be loaded.')
    }
    loading.value = false
    return
  }
  await getOrCreatePlan()

  const {data} = await axios.get('/parameter/lanes-options')
  const rows: LaneRow[] = Array.isArray(data?.rows) ? data.rows : data
  lanesIndex.value = buildLanesIndex(rows)
  supportedPlanData.value = rows

  await fetchTableNames()
  loading.value = false
})

const tableNames = ref(['', '', '', ''])

const fetchTableNames = async () => {
  if (!selectedEvent.value?.id) return
  try {
    const response = await axios.get(`/table-names/${selectedEvent.value.id}`)
    const tables = response.data.table_names

    const names = Array(4).fill('')
    tables.forEach(t => {
      if (t.table_number >= 1 && t.table_number <= 4) {
        names[t.table_number - 1] = t.table_name ?? ''
      }
    })
    tableNames.value = names
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Laden der Tischbezeichnungen:', e)
    }
    tableNames.value = Array(4).fill('')
  }
}

const updateTableName = async () => {
  if (!selectedEvent.value?.id) return

  try {
    const payload = {
      table_names: tableNames.value.map((name, i) => ({
        table_number: i + 1,
        table_name: name ?? '',
      })),
    }

    await axios.put(`/table-names/${selectedEvent.value.id}`, payload)
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Speichern der Tischnamen:', e)
    }
  }
}


</script>

<template>
  <div class="h-screen px-0 py-2 md:p-6 flex flex-col space-y-3 md:space-y-5 overflow-y-auto">
    <div v-if="loading" class="flex items-center justify-center h-full flex-col text-gray-600">
      <LoaderFlow/>
      <LoaderText/>
    </div>
    <template v-else>
    <ScheduleToast
      ref="savingToast" 
      :is-generating="isGenerating"
      :countdown="countdownSeconds"
      :on-immediate-save="immediateFlush"
    />

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


    <div class="bg-white border-b border-x-0 border-t-0 md:border md:rounded-lg rounded-none shadow-sm">
      <button
          class="w-full text-left px-3 md:px-4 py-2 bg-white font-semibold text-black uppercase flex justify-between items-center text-sm md:text-base border-b border-gray-200"
          @click="toggle('general')"
      >
        Allgemein
        <AccordionArrow :opened="openGroup === 'general'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'general'" class="p-3 md:p-4">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-3 md:gap-4 mt-2 md:mt-4">
            <ExploreSettings
                :parameters="parameters"
                :show-explore="showExplore"
                :show-challenge="showChallenge"
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

    <div class="bg-white border-b border-x-0 border-t-0 md:border md:rounded-lg rounded-none shadow-sm">
      <button
          class="w-full text-left px-3 md:px-4 py-2 bg-white font-semibold text-black uppercase flex justify-between items-center text-sm md:text-base border-b border-gray-200"
          @click="toggle('expert')"
      >
        Expertenparameter
        <AccordionArrow :opened="openGroup === 'expert'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'expert'" class="p-3 md:p-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3 md:gap-6 max-h-none md:max-h-[600px] overflow-y-visible md:overflow-y-auto overflow-x-hidden">
            <!-- Left column: Explore or turned off message -->
            <div class="w-full min-w-0">
              <div class="flex items-center gap-2 mb-2">
                <img
                    :src="programLogoSrc('E')"
                    :alt="programLogoAlt('E')"
                    class="w-8 h-8 md:w-10 md:h-10 flex-shrink-0"
                />
                <h3 class="text-base md:text-lg font-semibold capitalize">
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
                <div class="text-sm font-medium mb-1"><span class="italic">FIRST</span> LEGO League Explore ist deaktiviert</div>
                <div class="text-xs">Aktiviere <span class="italic">FIRST</span> LEGO League Explore, um Expertenparameter zu konfigurieren.</div>
              </div>
            </div>

            <!-- Right column: Challenge or turned off message -->
            <div class="w-full min-w-0">
              <div class="flex items-center gap-2 mb-2">
                <img
                    :src="programLogoSrc('C')"
                    :alt="programLogoAlt('C')"
                    class="w-8 h-8 md:w-10 md:h-10 flex-shrink-0"
                />
                <h3 class="text-base md:text-lg font-semibold capitalize">
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


                <!-- Robot-Game-Tische -->
                <div class="p-3 md:p-4 border rounded shadow mt-3 md:mt-4 w-full max-w-lg">
                  <div class="flex items-center mb-2 md:mb-3">
                    <span class="text-sm md:text-base font-medium text-gray-800">Bezeichnung der Robot-Game-Tische<br>(ersetzt nur die Nummer)</span>
                  </div>

                  <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                    <div v-for="(name, i) in tableNames" :key="i">
                      <label class="block text-xs text-gray-600 mb-1">Tisch {{ i + 1 }}</label>
                      <input
                          v-model="tableNames[i]"
                          class="w-full border px-3 py-1 rounded text-sm"
                          :placeholder="`z.B. Alpha (leer lassen für >>Tisch ${i + 1}<<)`"
                          type="text"
                          @blur="updateTableName"
                      />
                    </div>
                  </div>
                </div>

              </div>
              <div v-else class="text-center py-8 text-gray-500">
                <div class="text-sm font-medium mb-1"><span class="italic">FIRST</span> LEGO League Challenge ist deaktiviert</div>
                <div class="text-xs">Aktiviere <span class="italic">FIRST</span> LEGO League Challenge, um Expertenparameter zu konfigurieren.</div>
              </div>

            </div>


          </div>
        </div>
      </transition>
    </div>

    <div class="bg-white border-b border-x-0 border-t-0 md:border md:rounded-lg rounded-none shadow-sm" v-if="selectedEvent?.level === 3">
      <button
          class="w-full text-left px-3 md:px-4 py-2 bg-white font-semibold text-black uppercase flex justify-between items-center text-sm md:text-base border-b border-gray-200"
          @click="toggle('finals')"
      >
        Finalparameter
        <AccordionArrow :opened="openGroup === 'finals'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'finals'" class="p-3 md:p-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 max-h-[400px] md:max-h-[600px] overflow-y-auto">
            <!-- Left column: Input parameters -->
            <div>
              <template v-for="param in finaleInputParams" :key="param.id">
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

            <!-- Right column: Expert parameters -->
            <div>
              <template v-for="param in finaleExpertParams" :key="param.id">
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
        </div>
      </transition>
    </div>

    <div v-if="showChallenge" class="bg-white border-b border-x-0 border-t-0 md:border md:rounded-lg rounded-none shadow-sm">
      <button
          class="w-full text-left px-3 md:px-4 py-2 bg-white font-semibold text-black uppercase flex justify-between items-center text-sm md:text-base border-b border-gray-200"
          @click="toggle('extras')"
      >
        Zusatzblöcke
        <AccordionArrow :opened="openGroup === 'extras'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'extras'" class="p-3 md:p-4">
          <InsertBlocks
              ref="insertBlocksRef"
              :plan-id="selectedPlanId as number"
              :event-level="selectedEvent?.level ?? null"
              :on-update="handleBlockUpdates"
              :show-explore="showExplore"
              :show-challenge="showChallenge"
          />
        </div>
      </transition>
    </div>

    <!-- Error Alert Banner -->
    <div v-if="generatorError" class="bg-red-50 border-l-4 border-red-500 p-3 md:p-4 rounded shadow-lg">
      <div class="flex items-start justify-between gap-2">
        <div class="flex-1 min-w-0">
          <div class="flex items-center">
            <svg class="h-4 w-4 md:h-5 md:w-5 text-red-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <h3 class="text-red-800 font-semibold text-sm md:text-lg break-words">{{ generatorError }}</h3>
          </div>
          <p v-if="errorDetails" class="mt-2 text-red-700 text-xs md:text-sm break-words">{{ errorDetails }}</p>
        </div>
        <button
          @click="generatorError = null; errorDetails = null"
          class="ml-2 text-red-500 hover:text-red-700 focus:outline-none flex-shrink-0"
          aria-label="Fehler schließen"
        >
          <svg class="h-4 w-4 md:h-5 md:w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- Preview Section - Hidden on mobile, shown on tablet+ -->
    <div class="hidden md:flex flex-grow overflow-hidden">
      <div v-if="isGenerating" class="flex items-center justify-center h-full flex-col text-gray-600">
        <LoaderFlow/>
        <LoaderText/>
      </div>
      <Preview
          v-else-if="selectedPlanId"
          :plan-id="selectedPlanId as number"
          initial-view="overview"
      />
    </div>
    </template>
    <!-- Mobile Preview Toggle -->
    <div class="md:hidden bg-white border-b border-x-0 border-t-0 rounded-none shadow-sm">
      <button
          class="w-full text-left px-3 py-2 bg-white font-semibold text-black uppercase flex justify-between items-center text-sm border-b border-gray-200"
          @click="toggle('preview')"
      >
        Vorschau
        <AccordionArrow :opened="openGroup === 'preview'"/>
      </button>
      <transition name="fade">
        <div v-if="openGroup === 'preview'" class="p-3 max-h-[400px] overflow-y-auto">
          <div v-if="isGenerating" class="flex items-center justify-center h-full flex-col text-gray-600 py-8">
            <LoaderFlow/>
            <LoaderText/>
          </div>
          <Preview
              v-else-if="selectedPlanId"
              :plan-id="selectedPlanId as number"
              initial-view="overview"
          />
        </div>
      </transition>
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
