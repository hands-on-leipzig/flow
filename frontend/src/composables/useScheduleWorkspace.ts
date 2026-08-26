import { computed, ref } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { usePlanCacheStore } from '@/stores/planCache'
import { useDebouncedSave } from '@/composables/useDebouncedSave'
import { DEBOUNCE_DELAY } from '@/constants/extraBlocks'
import { buildLanesIndex, type LanesIndex, type LaneRow } from '@/utils/lanesIndex'
import FllEvent from '@/models/FllEvent'
import { Parameter, ParameterCondition } from '@/models/Parameter'
import {
  notifyPlanPreviewReload,
  subscribePlanPreviewMessages,
  PLAN_PREVIEW_CHANNEL,
} from '@/utils/planPreviewSync'
import { eventPrograms, programId } from '@/utils/eventPrograms'

const SPECIAL_KEYS = new Set([
  'e1_teams', 'e2_teams',
  'c_teams', 'c_tables', 'j_lanes',
  'f8_teams', 'f8_lanes', 'f8_fields', 'f8_extra_rounds',
  'e_mode',
  'e1_lanes', 'e2_lanes',
])

function eventStore() {
  return useEventStore()
}
function planCache() {
  return usePlanCacheStore()
}

const selectedEvent = computed<FllEvent | null>(() => eventStore().selectedEvent)
const parameters = ref<Parameter[]>([])
const displayConditions = ref<ParameterCondition[]>([])
const plans = ref<Array<{ id: number; name: string; is_chosen?: boolean }>>([])
const selectedPlanId = ref<number | null>(null)
const planLocked = ref(false)
const planLastChange = ref<string | null>(null)
const loading = ref(true)
const bootstrapped = ref(false)

const showExplore = ref(true)
const showChallenge = ref(true)
const showFuture = ref(true)

const attachedPrograms = computed(() => eventPrograms(selectedEvent.value))

const attachedProgramIds = computed(() =>
  new Set(attachedPrograms.value.map(programId).filter((id) => id > 0))
)

const isGenerating = ref(false)
const generatorError = ref<string | null>(null)
const errorDetails = ref<string | null>(null)
const previewReload = ref(0)
const countdownSeconds = ref<number | null>(null)
/** Inline plan is hidden while a dedicated pop-out window is open. */
const planPopoutOpen = ref(false)
let planPopoutWindow: Window | null = null
let popoutWatchTimer: ReturnType<typeof setInterval> | null = null
let unsubscribePopoutPresence: (() => void) | null = null

function stopPopoutWatch() {
  if (popoutWatchTimer) {
    clearInterval(popoutWatchTimer)
    popoutWatchTimer = null
  }
}

function markPopoutClosed() {
  planPopoutOpen.value = false
  planPopoutWindow = null
  stopPopoutWatch()
}

function markPopoutOpen(win?: Window | null) {
  planPopoutOpen.value = true
  if (win && !win.closed) planPopoutWindow = win
  stopPopoutWatch()
  popoutWatchTimer = setInterval(() => {
    if (planPopoutWindow && planPopoutWindow.closed) {
      markPopoutClosed()
    }
  }, 800)
}

function ensurePopoutPresenceListener() {
  if (unsubscribePopoutPresence || typeof window === 'undefined') return
  unsubscribePopoutPresence = subscribePlanPreviewMessages((message) => {
    if (message.type !== 'presence') return
    if (selectedPlanId.value && Number(message.planId) !== Number(selectedPlanId.value)) return
    if (message.status === 'closed') {
      markPopoutClosed()
      return
    }
    if (message.status === 'open' || message.status === 'ping') {
      markPopoutOpen(planPopoutWindow)
    }
  })
}

const lanesIndex = ref<LanesIndex | null>(null)
const supportedPlanData = ref<any[] | null>(null)
const tableNames = ref(['', '', '', ''])

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

function matchCondition(cond: ParameterCondition, other: Parameter | undefined): boolean {
  if (!other) return false
  const val = other.value

  if (cond.is === '<' || cond.is === '<=' || cond.is === '>' || cond.is === '>=') {
    const a = Number(val)
    const b = Number(cond.value)
    if (!Number.isFinite(a) || !Number.isFinite(b)) return false
    if (cond.is === '<') return a < b
    if (cond.is === '<=') return a <= b
    if (cond.is === '>') return a > b
    if (cond.is === '>=') return a >= b
  }

  if (cond.is === '=') return (val as any) == (cond.value as any)
  if (cond.is === '!=') return (val as any) != (cond.value as any)
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

function isAttachedProgramParam(param: Parameter): boolean {
  const id = Number(param.first_program || 0)
  if (id <= 0) return true
  return attachedProgramIds.value.has(id)
}

const expertParamsByProgramId = computed(() => {
  const ids = attachedProgramIds.value
  const map: Record<number, Parameter[]> = {}
  for (const param of parameters.value) {
    if (param.context !== 'expert' || param.level === 3) continue
    const id = Number(param.first_program || 0)
    if (!ids.has(id)) continue
    if (!map[id]) map[id] = []
    map[id].push(param)
  }
  for (const list of Object.values(map)) {
    list.sort((a, b) => (a.sequence ?? 0) - (b.sequence ?? 0))
  }
  return map
})

function isTimeParam(param: Parameter) {
  return (
    (param.type === 'time' || (param.name && param.name.toLowerCase().includes('duration'))) &&
    param.context !== 'expert'
  )
}

const finaleInputParams = computed(() =>
  parameters.value
    .filter((p: Parameter) =>
      p.level === 3 &&
      p.context === 'input' &&
      !isSpecial(p) &&
      isAttachedProgramParam(p)
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
      isAttachedProgramParam(p)
    )
    .sort((a: Parameter, b: Parameter) => (a.sequence || 0) - (b.sequence || 0))
)

let saveApi: ReturnType<typeof useDebouncedSave> | null = null

function getSaveApi() {
  if (saveApi) return saveApi
  saveApi = useDebouncedSave({
    delay: DEBOUNCE_DELAY,
    isGenerating: () => isGenerating.value,
    onShowToast: (countdown) => {
      countdownSeconds.value = countdown
    },
    onHideToast: () => {
      countdownSeconds.value = null
    },
    onCountdownUpdate: (seconds) => {
      countdownSeconds.value = seconds
    },
    changeDetection: (_key, newValue, oldValue) => {
      return String(oldValue ?? '') !== String(newValue ?? '')
    },
    onSave: async (updates) => {
      const updateArray = Object.entries(updates).map(([name, value]) => ({ name, value }))
      await updateParams(updateArray)
    },
  })
  return saveApi
}

function normalizeValue(value: any, type: string | undefined) {
  if (type === 'boolean') return value ? 1 : 0
  return value
}

async function pollUntilReady(planId: number, timeoutMs = 60000, intervalMs = 1000) {
  const start = Date.now()
  while (Date.now() - start < timeoutMs) {
    const res = await axios.get(`/plans/${planId}/status`)
    const status = res.data.status
    if (status === 'done') return
    if (status === 'failed') {
      generatorError.value = 'Die Generierung ist fehlgeschlagen'
      errorDetails.value = 'Der Plan konnte nicht generiert werden. Bitte überprüfe die Parameter.'
      throw new Error('Generation failed')
    }
    await new Promise(resolve => setTimeout(resolve, intervalMs))
  }
  throw new Error('Timeout: Plan generation took too long')
}

async function runGeneratorOnce() {
  if (!selectedPlanId.value) return

  generatorError.value = null
  errorDetails.value = null
  isGenerating.value = true
  try {
    await axios.post(`/plans/${selectedPlanId.value}/generate`)
    await pollUntilReady(selectedPlanId.value)
    if (selectedEvent.value?.id) {
      planCache().invalidatePlan()
      const refreshedPlan = await planCache().getPlan(selectedEvent.value.id)
      planLocked.value = Boolean(refreshedPlan?.locked)
      planLastChange.value = refreshedPlan?.last_change ?? null
    }
    previewReload.value += 1
    notifyPlanPreviewReload(selectedPlanId.value)
  } catch (error: any) {
    if (import.meta.env.DEV) console.error('Error during generation:', error)

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
    getSaveApi().unfreeze()
  }
}

async function updateParams(params: Array<{ name: string; value: any }>, afterUpdate?: () => Promise<void>) {
  if (!selectedPlanId.value) return

  loading.value = true
  let needsRegeneration = false

  const paramUpdates = params.filter(p => !p.name.startsWith('block_'))
  const blockGeneratorTriggers = params.filter(p => p.name.startsWith('block_'))

  if (blockGeneratorTriggers.length > 0) {
    isGenerating.value = true
  }

  try {
    if (paramUpdates.length > 0) {
      await axios.post(`/plans/${selectedPlanId.value}/parameters`, {
        parameters: paramUpdates.map(({ name, value }) => {
          const p = paramMapByName.value[name]
          return {
            id: p?.id,
            value: normalizeValue(value, p?.type)?.toString() ?? '',
          }
        }),
      })
      paramUpdates.forEach(({ name, value }) => getSaveApi().setOriginal(name, value))
    }

    if (blockGeneratorTriggers.length > 0) {
      needsRegeneration = true
    }
  } catch (error) {
    if (import.meta.env.DEV) console.error('Error saving parameters:', error)
    loading.value = false
    return
  }
  loading.value = false

  if (needsRegeneration || paramUpdates.length > 0) {
    await runGeneratorOnce()
    if (eventStore().selectedEvent?.id) {
      await eventStore().refreshReadiness(eventStore().selectedEvent!.id)
    }
  } else if (afterUpdate) {
    await afterUpdate()
  }
}

async function fetchParams(planId: number) {
  if (!planId) return
  loading.value = true
  try {
    const { data: rawParams } = await axios.get<Parameter[]>(`/plans/${planId}/parameters`)
    const { data: conditions } = await axios.get<ParameterCondition[]>('/parameter/condition')
    parameters.value = Array.isArray(rawParams) ? rawParams : []
    displayConditions.value = Array.isArray(conditions) ? conditions : []
    getSaveApi().setOriginals(Object.fromEntries(parameters.value.map(p => [p.name, p.value])))
    showExplore.value = Number(paramMapByName.value['e_mode']?.value || 0) > 0
    showChallenge.value = Number(paramMapByName.value['c_mode']?.value || 0) > 0
    showFuture.value = attachedPrograms.value.some(
      (p) => String(p.name || '').toUpperCase() === 'FUTURE_8',
    ) && Number(paramMapByName.value['f8_mode']?.value || 0) === 1
  } catch (err) {
    console.error('Failed to fetch params or conditions:', err)
    parameters.value = []
    displayConditions.value = []
  } finally {
    loading.value = false
  }
}

async function fetchTableNames() {
  if (!selectedEvent.value?.id) return
  try {
    const response = await planCache().getTableNames(selectedEvent.value.id)
    const tables = response.table_names
    const names = Array(4).fill('')
    tables.forEach((t: any) => {
      if (t.table_number >= 1 && t.table_number <= 4) {
        names[t.table_number - 1] = t.table_name ?? ''
      }
    })
    tableNames.value = names
  } catch (e) {
    if (import.meta.env.DEV) console.error('Fehler beim Laden der Tischbezeichnungen:', e)
    tableNames.value = Array(4).fill('')
  }
}

async function updateTableName() {
  if (!selectedEvent.value?.id) return
  try {
    await axios.put(`/table-names/${selectedEvent.value.id}`, {
      table_names: tableNames.value.map((name, i) => ({
        table_number: i + 1,
        table_name: name ?? '',
      })),
    })
  } catch (e) {
    if (import.meta.env.DEV) console.error('Fehler beim Speichern der Tischnamen:', e)
  }
}

async function getOrCreatePlan() {
  if (!selectedEvent.value) return
  const planData = await planCache().getPlan(selectedEvent.value.id)
  plans.value = [planData]
  selectedPlanId.value = planData.id
  planLocked.value = Boolean(planData?.locked)
  planLastChange.value = planData?.last_change ?? null
  await fetchParams(selectedPlanId.value as number)
  if (planData.existing === false) {
    planCache().invalidatePlan()
    await runGeneratorOnce()
  }
}

async function updatePlanLock(locked: boolean) {
  if (!selectedPlanId.value) return
  await axios.patch(`/plans/${selectedPlanId.value}/lock`, { locked })
  planLocked.value = locked
  if (selectedEvent.value?.id) {
    planCache().invalidatePlan()
  }
}

async function ensureLoaded() {
  ensurePopoutPresenceListener()
  if (bootstrapped.value && selectedPlanId.value && selectedEvent.value) {
    return
  }
  loading.value = true
  if (!eventStore().selectedEvent) {
    await eventStore().fetchSelectedEvent()
  }
  if (!selectedEvent.value) {
    loading.value = false
    return
  }
  await getOrCreatePlan()
  const data = await planCache().getLanesOptions()
  const rows: LaneRow[] = Array.isArray(data?.rows) ? data.rows : data
  lanesIndex.value = buildLanesIndex(rows)
  supportedPlanData.value = rows
  await fetchTableNames()
  bootstrapped.value = true
  loading.value = false
}

async function reloadForEventChange() {
  bootstrapped.value = false
  selectedPlanId.value = null
  await ensureLoaded()
}

function handleParamUpdate(param: { name: string; value: any }) {
  const p = paramMapByName.value[param.name]
  if (!p) return
  p.value = param.value
  getSaveApi().scheduleUpdate(param.name, param.value)
}

function handleBlockUpdates(updates: Array<{ name: string; value: any; triggerGenerator?: boolean }>) {
  updates.forEach(update => {
    const prefixedName = update.name.startsWith('block_') ? update.name : `block_${update.name}`
    getSaveApi().scheduleUpdate(prefixedName, update.value)
  })
}

function openPlanPopout() {
  if (!selectedPlanId.value) return
  ensurePopoutPresenceListener()
  // Dedicated lightweight route (no shell, no plan tabs, no event bootstrap).
  const url = `${window.location.origin}/plan/popout/${selectedPlanId.value}`
  // Feature string forces a real popup window (not a browser tab). Browsers
  // still show a minimal title bar; full chrome-less windows are blocked.
  const width = Math.min(1280, Math.max(900, window.screen.availWidth - 80))
  const height = Math.min(900, Math.max(700, window.screen.availHeight - 80))
  const left = Math.max(0, Math.round((window.screen.availWidth - width) / 2))
  const top = Math.max(0, Math.round((window.screen.availHeight - height) / 2))
  const features = [
    'popup=yes',
    `width=${width}`,
    `height=${height}`,
    `left=${left}`,
    `top=${top}`,
    'menubar=no',
    'toolbar=no',
    'location=no',
    'status=no',
    'resizable=yes',
    'scrollbars=yes',
  ].join(',')
  const win = window.open(url, 'flow-plan-preview', features)
  if (!win) {
    markPopoutClosed()
    return
  }
  markPopoutOpen(win)
  win.focus()
}

function focusPlanPopout() {
  if (planPopoutWindow && !planPopoutWindow.closed) {
    planPopoutWindow.focus()
    return
  }
  openPlanPopout()
}

function dockPlanPopout() {
  if (planPopoutWindow && !planPopoutWindow.closed) {
    try {
      planPopoutWindow.close()
    } catch {
      /* ignore */
    }
  }
  markPopoutClosed()
  // Refresh inline preview with whatever the pop-out last showed.
  previewReload.value += 1
}

export function useScheduleWorkspace() {
  const api = getSaveApi()
  return {
    selectedEvent,
    parameters,
    selectedPlanId,
    planLocked,
    planLastChange,
    loading,
    showExplore,
    showChallenge,
    showFuture,
    attachedPrograms,
    isGenerating,
    generatorError,
    errorDetails,
    previewReload,
    countdownSeconds,
    planPopoutOpen,
    lanesIndex,
    supportedPlanData,
    tableNames,
    visibilityMap,
    disabledMap,
    paramMap,
    expertParamsByProgramId,
    finaleInputParams,
    finaleExpertParams,
    PLAN_PREVIEW_CHANNEL,
    ensureLoaded,
    reloadForEventChange,
    handleParamUpdate,
    handleBlockUpdates,
    updatePlanLock,
    updateTableName,
    immediateFlush: api.immediateFlush,
    openPlanPopout,
    focusPlanPopout,
    dockPlanPopout,
  }
}
