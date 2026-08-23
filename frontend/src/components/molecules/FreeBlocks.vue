<script lang="ts" setup>
import {computed, nextTick, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {useDebouncedSave} from "@/composables/useDebouncedSave";
import {TIMING_FIELDS, DEBOUNCE_DELAY} from "@/constants/extraBlocks";
import ScheduleToast from "@/components/atoms/ScheduleToast.vue";

type Maybe<T> = T | null | undefined

type ExtraBlock = {
  id?: number
  plan: number
  first_program: number | null | 0
  name: string
  description: string
  link?: string | null
  active?: boolean
  start?: string | null
  end?: string | null
  room?: number | null
}

const props = defineProps<{
  planId: number | null
  showExplore?: boolean
  showChallenge?: boolean
  eventDate?: string
  eventDays?: number
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

// --- State ---
const blocks = ref<ExtraBlock[]>([])
const blockToDelete = ref<ExtraBlock | null>(null)
const saving = ref(false)

// Generator state (must be declared before useDebouncedSave)
const isGenerating = ref(false)
const generatorError = ref<string | null>(null)
const errorDetails = ref<string | null>(null)

// --- Debounced Saving ---
const savingToast = ref(null)
const countdownSeconds = ref<number | null>(null)

const {scheduleUpdate, flush, immediateFlush} = useDebouncedSave({
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
  onSave: async (updates) => {
    await flushUpdates(updates)
  }
})

// --- Batch save system (save on countdown) ---
// Note: Block changes trigger debounce immediately, blocks are saved to DB when countdown reaches 0 or is clicked

// --- Computed ---
const customBlocks = computed(() => blocks.value)

const visibleCustomBlocks = computed(() => {
  return customBlocks.value.filter(block => {
    if (props.showExplore === false && props.showChallenge === false) return true
    if (props.showExplore === false && (block.first_program === 2 || block.first_program === 0)) return false
    if (props.showChallenge === false && (block.first_program === 3 || block.first_program === 0)) return false
    return true
  })
})

// --- Lifecycle ---
onMounted(() => {
  if (props.planId != null) loadBlocks()
})
watch(() => props.planId, v => {
  if (v != null) loadBlocks()
}, {immediate: true})

// Cleanup handled by composable

// --- Load blocks ---
async function loadBlocks() {
  const pid = props.planId
  if (!pid) return
  const {data} = await axios.get<ExtraBlock[]>(`/plans/${pid}/extra-blocks`, {
    params: {type: 'free'},
  })
  const loadedBlocks = Array.isArray(data) ? data : []

  // Sort by date first, then start time (ascending - earliest first)
  blocks.value = loadedBlocks.sort((a, b) => {
    // Extract dates for comparison
    const dateA = extractDate(a.start || a.end || '')
    const dateB = extractDate(b.start || b.end || '')

    // Compare dates first
    if (dateA && dateB) {
      const dateCompare = dateA.localeCompare(dateB)
      if (dateCompare !== 0) return dateCompare
    } else if (dateA) return -1 // A has date, B doesn't - A comes first
    else if (dateB) return 1 // B has date, A doesn't - B comes first

    // If dates are equal or both missing, compare start times
    const timeA = extractTime(a.start || '')
    const timeB = extractTime(b.start || '')

    if (timeA && timeB) {
      return timeA.localeCompare(timeB)
    } else if (timeA) return -1
    else if (timeB) return 1

    return 0 // Both missing, keep order
  })
}

// Save all enabled blocks to DB (called when countdown triggers)
async function saveAllEnabledBlocks() {
  if (!props.planId) return

  // Get all enabled blocks (including newly created ones without ID)
  const enabledBlocks = blocks.value.filter(b => b.active !== false && b.plan === props.planId)
  if (enabledBlocks.length === 0) return

  try {
    saving.value = true

    // Save all enabled blocks
    for (const block of enabledBlocks) {
      const blockData: any = {
        plan: block.plan,
        first_program: block.first_program,
        name: block.name,
        description: block.description,
        link: block.link,
        start: block.start,
        end: block.end,
        room: block.room,
        active: block.active
      }

      // Only include id if block already exists in DB
      if (block.id) {
        blockData.id = block.id
      }

      // Check if this block has timing changes (start/end times)
      const hasTimingChanges = block.start || block.end

      const response = await axios.post(`/plans/${props.planId}/extra-blocks`, {
        ...blockData,
        skip_regeneration: !hasTimingChanges
      })
      const saved = response.data?.block || response.data

      // Check if response contains error from generateLite
      if (response.data?.error) {
        generatorError.value = response.data.error
        errorDetails.value = response.data.details || null
        isGenerating.value = false
        await loadBlocks() // Still reload blocks even on error
        throw new Error(response.data.error) // Re-throw so caller can handle it
      }

      if (saved?.id) {
        const index = blocks.value.findIndex(b =>
            (b.id && b.id === saved.id) ||
            (!b.id && !saved.id && b.plan === saved.plan &&
                b.start === saved.start && b.end === saved.end)
        )
        if (index !== -1) {
          blocks.value[index] = saved
        } else {
          blocks.value.push(saved)
        }
      }
    }

    emit('changed')
  } catch (error) {
    console.error('Failed to save blocks:', error)
    throw error // Re-throw so caller can handle it
  } finally {
    saving.value = false
  }
}

// Expose functions to parent
defineExpose({
  saveAllEnabledBlocks
})

// --- Central Flush Logic ---
async function flushUpdates(updates: Record<string, any>) {
  if (!props.planId) return

  // Clear previous errors
  generatorError.value = null
  errorDetails.value = null

  // Determine if regeneration is needed before making API calls
  let needsRegeneration = false
  for (const [name, value] of Object.entries(updates)) {
    if (name === 'extra_block_update' && value) {
      const hasTimingChanges = Object.keys(value).some(f => TIMING_FIELDS.includes(f) || f === 'start' || f === 'end')
      if (hasTimingChanges) {
        needsRegeneration = true
        break
      }
    }
    if (name === 'extra_block_delete' || name === 'extra_block_add') {
      needsRegeneration = true
      break
    }
  }

  // Set generating state immediately if regeneration will be needed
  // This ensures the UI shows "Plan wird generiert" right away
  if (needsRegeneration) {
    isGenerating.value = true
  }

  try {
    for (const [name, value] of Object.entries(updates)) {
      if (name === 'extra_block_update' && value) {
        const hasTimingChanges = Object.keys(value).some(f => TIMING_FIELDS.includes(f) || f === 'start' || f === 'end')
        const blockData = {...value}
        if (!hasTimingChanges) {
          blockData.skip_regeneration = true
        }
        const response = await axios.post(`/plans/${props.planId}/extra-blocks`, blockData)

        // Check if response contains error from generateLite
        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks() // Still reload blocks even on error
          return // Stop processing further updates
        }
      }
      if (name === 'extra_block_delete' && value?.id) {
        const deleteResponse = await axios.delete(`/extra-blocks/${value.id}`)

        // Check if response contains error from generateLite
        if (deleteResponse.data?.error) {
          generatorError.value = deleteResponse.data.error
          errorDetails.value = deleteResponse.data.details || null
          isGenerating.value = false
          await loadBlocks() // Still reload blocks even on error
          return // Stop processing further updates
        }
      }
      if (name === 'extra_block_add' && value) {
        const response = await axios.post(`/plans/${props.planId}/extra-blocks`, value)

        // Check if response contains error from generateLite
        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks() // Still reload blocks even on error
          return // Stop processing further updates
        }
      }
    }
    await loadBlocks()

    // Poll for generator status if regeneration was triggered
    if (needsRegeneration) {
      await pollUntilReady(props.planId)
    } else {
      // No regeneration needed, ensure generating state is off
      isGenerating.value = false
    }
    emit('changed')
  } catch (error: any) {
    console.error('Error flushing updates:', error)
    isGenerating.value = false

    // Extract error message from response
    let errorMessage = 'Fehler beim Speichern der Blöcke'
    let details: string | null = null

    if (axios.isAxiosError(error)) {
      const status = error.response?.status
      const errorData = error.response?.data

      if (status === 422) {
        errorMessage = errorData?.error || 'Die aktuelle Konfiguration wird nicht unterstützt'
        details = errorData?.details || errorData?.message || 'Ungültige Block-Kombination'
      } else if (status === 404) {
        errorMessage = 'Block oder Plan nicht gefunden'
        details = errorData?.error || errorData?.details || `Plan ${props.planId} existiert nicht`
      } else if (status === 500) {
        errorMessage = errorData?.error || 'Fehler bei der Block-Speicherung'
        details = errorData?.details || errorData?.message || 'Interner Serverfehler'
      } else if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
        errorMessage = 'Verbindungsfehler'
        details = 'Bitte überprüfe deine Internetverbindung.'
      } else {
        errorMessage = errorData?.error || errorData?.message || error.message || errorMessage
      }
    } else if (error instanceof Error) {
      errorMessage = error.message
    }

    generatorError.value = errorMessage
    errorDetails.value = details
  }
}

// Poll for generator status until ready
async function pollUntilReady(planId: number, timeoutMs = 60000, intervalMs = 1000) {
  // Give backend a moment to set status to RUNNING
  await new Promise(resolve => setTimeout(resolve, 200))

  // isGenerating is already set to true in flushUpdates
  const start = Date.now()

  try {
    while (Date.now() - start < timeoutMs) {
      const res = await axios.get(`/plans/${planId}/status`)
      const status = res.data.status

      if (status === 'done') {
        isGenerating.value = false
        return
      }

      // Check for failed status
      if (status === 'failed') {
        isGenerating.value = false
        generatorError.value = 'Die Generierung ist fehlgeschlagen'
        errorDetails.value = 'Der Plan konnte nicht generiert werden. Bitte überprüfe die Block-Einstellungen.'
        return
      }

      // Keep polling if still running
      await new Promise(resolve => setTimeout(resolve, intervalMs))
    }

    throw new Error('Timeout: Plan generation took too long')
  } catch (error: any) {
    isGenerating.value = false

    if (error instanceof Error && error.message.includes('Timeout')) {
      generatorError.value = 'Zeitüberschreitung'
      errorDetails.value = 'Die Generierung dauert zu lange. Bitte versuche es erneut.'
    } else if (axios.isAxiosError(error)) {
      if (error.code === 'ECONNABORTED' || error.code === 'ERR_NETWORK') {
        generatorError.value = 'Verbindungsfehler'
        errorDetails.value = 'Bitte überprüfe deine Internetverbindung.'
      } else {
        generatorError.value = 'Fehler beim Abrufen des Generator-Status'
        errorDetails.value = error.message || 'Unbekannter Fehler'
      }
    } else {
      generatorError.value = 'Fehler bei der Plan-Generierung'
      errorDetails.value = error?.message || 'Unbekannter Fehler'
    }
  }
}

// --- Helpers ---
// Extract date (YYYY-MM-DD) from datetime string
function extractDate(dt: Maybe<string>): string {
  if (!dt) return ''
  // Handle formats: "YYYY-MM-DD HH:mm:ss" or "YYYY-MM-DDTHH:mm:ss"
  const datePart = dt.replace('T', ' ').split(' ')[0]
  return datePart
}

// Extract time (HH:mm) from datetime string
function extractTime(dt: Maybe<string>): string {
  if (!dt) return ''
  // Handle formats: "YYYY-MM-DD HH:mm:ss" or "YYYY-MM-DDTHH:mm:ss"
  const timePart = dt.replace('T', ' ').split(' ')[1]
  if (!timePart) return ''
  return timePart.slice(0, 5) // Get HH:mm
}

// Combine date and time back to datetime string format
function combineDateTime(date: string, time: string): string | null {
  if (!date || !time) return null
  // Ensure date is in YYYY-MM-DD format and time is in HH:mm format
  return `${date} ${time}:00`
}

const newBlockName = ref('')
const newBlockDescription = ref('')
const newBlockLink = ref('')
const newBlockDate = ref('')
const newBlockStart = ref('06:00')
const newBlockEnd = ref('07:00')
const newFirstProgram = ref(0)
const composerRef = ref<{ focusTitle?: () => void } | null>(null)
const isCreating = ref(false)

function defaultBlockDate(): string {
  if (props.eventDate) return dayjs(props.eventDate).format('YYYY-MM-DD')
  return dayjs().format('YYYY-MM-DD')
}

function resetComposer() {
  newBlockName.value = ''
  newBlockDescription.value = ''
  newBlockLink.value = ''
  newBlockDate.value = defaultBlockDate()
  newBlockStart.value = '06:00'
  newBlockEnd.value = '07:00'
  newFirstProgram.value = 0
}

watch(() => props.eventDate, () => {
  if (!newBlockName.value.trim()) {
    newBlockDate.value = defaultBlockDate()
  }
}, {immediate: true})

function cycleFirstProgram(current: number | null | undefined, program: 2 | 3): number {
  const value = current ?? 0
  if (program === 2) {
    if (value === 2) return 3
    if (value === 3) return 0
    if (value === 0) return 3
    return 2
  }
  if (value === 3) return 2
  if (value === 2) return 0
  if (value === 0) return 2
  return 3
}

// --- Actions ---
async function createCustom() {
  if (isCreating.value || !props.planId) return
  const name = newBlockName.value.trim()
  if (!name) return

  isCreating.value = true
  isGenerating.value = true
  const dateStr = newBlockDate.value || defaultBlockDate()
  const start = combineDateTime(dateStr, newBlockStart.value || '06:00') || `${dateStr} 06:00:00`
  const end = combineDateTime(dateStr, newBlockEnd.value || '07:00') || `${dateStr} 07:00:00`

  try {
    const response = await axios.post(`/plans/${props.planId}/extra-blocks`, {
      plan: props.planId,
      first_program: newFirstProgram.value,
      name,
      description: newBlockDescription.value,
      link: newBlockLink.value.trim() || null,
      active: true,
      start,
      end,
    })

    if (response.data?.error) {
      generatorError.value = response.data.error
      errorDetails.value = response.data.details || null
      isGenerating.value = false
      return
    }

    resetComposer()
    await loadBlocks()
    await pollUntilReady(props.planId)
    emit('changed')
    await nextTick()
    composerRef.value?.focusTitle?.()
  } catch (error: any) {
    console.error('Failed to create block:', error)
    generatorError.value = 'Fehler beim Erstellen des Blocks'
    errorDetails.value = error.message || 'Unbekannter Fehler'
    isGenerating.value = false
  } finally {
    isCreating.value = false
  }
}

function confirmDeleteBlock(block: ExtraBlock) {
  blockToDelete.value = block
}

function cancelDeleteBlock() {
  blockToDelete.value = null
}

async function deleteBlock() {
  if (!blockToDelete.value?.id) return
  scheduleUpdate('extra_block_delete', blockToDelete.value)
  blockToDelete.value = null
  // Immediately flush to delete the block and refresh the list
  await immediateFlush()
}

// Update local state and trigger debounce (no DB save until countdown)
function saveBlock(block: ExtraBlock) {
  // Only save blocks that already exist in the database (have an ID)
  // New blocks are saved immediately on creation, so this should only be called for existing blocks
  if (!block.id) {
    console.warn('Attempted to save block without ID - this should not happen')
    return
  }

  // Create a new object copy to avoid reference issues during countdown
  // This ensures each update captures the current state independently
  // Note: DB save will happen when countdown reaches 0 or is clicked
  scheduleUpdate('extra_block_update', {...block})
}

function toggleActive(block: ExtraBlock, active: boolean) {
  if (!block.id) return
  block.active = active
  // Ensure toggle change is caught by debouncer with countdown
  scheduleUpdate('extra_block_update', {...block, active})
}

function toggleProgram(block: ExtraBlock, program: 2 | 3) {
  block.first_program = cycleFirstProgram(block.first_program, program)
  saveBlock(block)
}

function toggleComposerProgram(program: 2 | 3) {
  newFirstProgram.value = cycleFirstProgram(newFirstProgram.value, program)
}

// Handle date change (updates both start and end with the same date)
function handleDateChange(block: ExtraBlock, date: string) {
  const startTime = extractTime(block.start || '')
  const endTime = extractTime(block.end || '')

  block.start = combineDateTime(date, startTime || '00:00')
  block.end = combineDateTime(date, endTime || '00:00')
  saveBlock(block)
}

/**
 * Converts time string (HH:MM) to minutes since midnight.
 */
function timeToMinutes(timeString: string): number {
  if (!timeString || typeof timeString !== 'string') return 0
  const [hours, minutes] = timeString.split(':').map(Number)
  return (hours || 0) * 60 + (minutes || 0)
}

/**
 * Normalizes time: rounds to 5-minute intervals and clamps to 00:05-23:55
 */
function normalizeTime(time: string): string {
  if (!time || typeof time !== 'string' || !time.includes(':')) return '00:05'

  const [hours, minutes] = time.split(':').map(Number)
  if (isNaN(hours) || isNaN(minutes)) return '00:05'

  // Round to nearest 5 minutes
  const roundedMinutes = Math.round(minutes / 5) * 5
  let totalMinutes = hours * 60 + roundedMinutes

  // Clamp to 00:05 - 23:55
  const minMinutes = 5 // 00:05
  const maxMinutes = 23 * 60 + 55 // 23:55

  if (totalMinutes < minMinutes) totalMinutes = minMinutes
  if (totalMinutes > maxMinutes) totalMinutes = maxMinutes

  // Convert back to hours and minutes
  const finalHours = Math.floor(totalMinutes / 60)
  const finalMinutes = totalMinutes % 60

  return `${String(finalHours).padStart(2, '0')}:${String(finalMinutes).padStart(2, '0')}`
}

// Handle start time change (called on blur)
function handleStartTimeChange(block: ExtraBlock, time: string) {
  const date = extractDate(block.start || block.end || '')
  if (!date || !time) return

  // Normalize start time (round to 5 min, clamp to 00:05-23:55)
  const normalizedStart = normalizeTime(time)
  const startMinutes = timeToMinutes(normalizedStart)

  // Get current end time from block (use current state, not stale)
  const currentEnd = extractTime(block.end || '')
  const normalizedEnd = currentEnd ? normalizeTime(currentEnd) : '23:55'
  let endMinutes = timeToMinutes(normalizedEnd)

  // If start >= end, set end = start + 5 min (capped at 23:55)
  if (startMinutes >= endMinutes) {
    endMinutes = Math.min(startMinutes + 5, 23 * 60 + 55) // Cap at 23:55
    const endHours = Math.floor(endMinutes / 60)
    const endMins = endMinutes % 60
    const newEnd = `${String(endHours).padStart(2, '0')}:${String(endMins).padStart(2, '0')}`

    // Update block immediately (for UI reactivity)
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, newEnd)
  } else {
    // Update block immediately (for UI reactivity)
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, normalizedEnd)
  }

  // Trigger debounce with current block state (this will overwrite any pending update)
  scheduleUpdate('extra_block_update', {...block})
}

// Handle end time change (called on blur)
function handleEndTimeChange(block: ExtraBlock, time: string) {
  const date = extractDate(block.start || block.end || '')
  if (!date || !time) return

  // Normalize end time (round to 5 min, clamp to 00:05-23:55)
  const normalizedEnd = normalizeTime(time)
  const endMinutes = timeToMinutes(normalizedEnd)

  // Get current start time from block (use current state, not stale)
  const currentStart = extractTime(block.start || '')
  const normalizedStart = currentStart ? normalizeTime(currentStart) : '00:05'
  const startMinutes = timeToMinutes(normalizedStart)

  // Ensure end >= start (if not, adjust start down)
  if (endMinutes < startMinutes) {
    // This shouldn't happen if user is editing end, but handle it gracefully
    // Set start to end - 5 min (min 00:05)
    const newStartMinutes = Math.max(endMinutes - 5, 5)
    const startHours = Math.floor(newStartMinutes / 60)
    const startMins = newStartMinutes % 60
    const newStart = `${String(startHours).padStart(2, '0')}:${String(startMins).padStart(2, '0')}`

    // Update block immediately (for UI reactivity)
    block.start = combineDateTime(date, newStart)
    block.end = combineDateTime(date, normalizedEnd)
  } else {
    // Update block immediately (for UI reactivity)
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, normalizedEnd)
  }

  // Trigger debounce with current block state (this will overwrite any pending update)
  scheduleUpdate('extra_block_update', {...block})
}

const deleteMessage = computed(() => {
  if (!blockToDelete.value) return ''
  const name = (blockToDelete.value.name || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

// Check if a block is outside the event date range
function isBlockOutsideEventDates(block: ExtraBlock): boolean {
  if (!props.eventDate) return false
  if (!props.eventDays || props.eventDays < 1) return false

  const blockDateStr = extractDate(block.start || block.end || '')
  if (!blockDateStr) return false

  const eventStartDate = dayjs(props.eventDate)
  const eventEndDate = eventStartDate.add(props.eventDays - 1, 'day')
  const blockDate = dayjs(blockDateStr)

  // Check if block date is before event start or after event end
  return blockDate.isBefore(eventStartDate, 'day') || blockDate.isAfter(eventEndDate, 'day')
}

// Check if any blocks are outside event dates (for showing explanation)
const hasBlocksOutsideEventDates = computed(() => {
  return visibleCustomBlocks.value.some(block => isBlockOutsideEventDates(block))
})
</script>

<template>
  <div class="space-y-8 relative">
    <!-- Error Alert Banner -->
    <div v-if="generatorError" class="glass-alert-error">
      <div class="flex items-start justify-between">
        <div class="flex-1">
          <div class="flex items-center">
            <svg class="h-5 w-5 text-red-500 mr-2 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                    clip-rule="evenodd"/>
            </svg>
            <h3 class="text-red-700 font-semibold text-lg">{{ generatorError }}</h3>
          </div>
          <p v-if="errorDetails" class="mt-2 text-red-600 text-sm">{{ errorDetails }}</p>
        </div>
        <button
            @click="generatorError = null; errorDetails = null"
            class="ml-4 text-red-500 hover:text-red-700 focus:outline-none"
            aria-label="Fehler schließen"
        >
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd"
                  d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                  clip-rule="evenodd"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- CUSTOM BLOCKS -->
    <div class="free-blocks">
      <div v-if="hasBlocksOutsideEventDates" class="glass-alert-warning">
        Freie Blöcke an Tagen außerhalb der Veranstaltung werden in den Plänen nicht angezeigt.
      </div>

      <div class="free-blocks__list">
        <ItemComposer
            ref="composerRef"
            v-model:title="newBlockName"
            :disabled="isCreating || !planId"
            title-placeholder="Neuer Block z. B. Mittagessen"
            empty-hint="Eigener Eintrag im Plan, ohne den generierten Ablauf zu ändern."
            @commit="createCustom"
        >
          <div class="free-block__when">
            <input
                v-model="newBlockDate"
                :disabled="isCreating || !planId"
                class="glass-input glass-input--sm liquid-surface-control free-block__date"
                type="date"
            />
            <input
                v-model="newBlockStart"
                :disabled="isCreating || !planId"
                class="glass-input glass-input--sm liquid-surface-control free-block__time"
                type="time"
                min="00:05"
                max="23:55"
                step="300"
                aria-label="Startzeit"
            />
            <input
                v-model="newBlockEnd"
                :disabled="isCreating || !planId"
                class="glass-input glass-input--sm liquid-surface-control free-block__time"
                type="time"
                min="00:05"
                max="23:55"
                step="300"
                aria-label="Endzeit"
            />
            <div class="free-block__programs">
              <img
                  :src="programLogoSrc('EXPLORE')"
                  :alt="programLogoAlt('EXPLORE')"
                  class="free-block__logo"
                  :class="{
                    'free-block__logo--off': !(newFirstProgram === 2 || newFirstProgram === 0),
                  }"
                  title="FIRST LEGO League Explore"
                  @click="toggleComposerProgram(2)"
              />
              <img
                  :src="programLogoSrc('CHALLENGE')"
                  :alt="programLogoAlt('CHALLENGE')"
                  class="free-block__logo"
                  :class="{
                    'free-block__logo--off': !(newFirstProgram === 3 || newFirstProgram === 0),
                  }"
                  title="FIRST LEGO League Challenge"
                  @click="toggleComposerProgram(3)"
              />
            </div>
          </div>
          <transition name="fade">
            <div v-if="newBlockName.trim().length > 0" class="free-block__composer-extra">
              <input
                  v-model="newBlockDescription"
                  :disabled="isCreating || !planId"
                  class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                  type="text"
                  placeholder="Beschreibung"
              />
              <input
                  v-model="newBlockLink"
                  :disabled="isCreating || !planId"
                  class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                  type="url"
                  placeholder="https://example.com"
              />
            </div>
          </transition>
        </ItemComposer>

        <ItemCard
            v-for="b in visibleCustomBlocks"
            :key="b.id ?? JSON.stringify(b)"
            :inactive="b.active === false"
            :class="{
              'free-block--warning': b.active !== false && isBlockOutsideEventDates(b),
            }"
        >
          <template #leading>
            <ToggleSwitch
                :model-value="b.active !== false"
                :disabled="!b.id"
                @update:modelValue="toggleActive(b, $event)"
            />
          </template>
          <template #title>
            <input
                :value="b.name"
                :disabled="b.active === false"
                class="item-card__title glass-input glass-input--sm liquid-surface-control"
                type="text"
                placeholder="Titel"
                @input="(e) => { b.name = (e.target as HTMLInputElement).value }"
                @blur="saveBlock(b)"
            />
          </template>
          <template #trailing>
            <IconDangerButton
                v-if="b.id"
                label="Block löschen"
                @click="confirmDeleteBlock(b)"
            />
          </template>

          <div class="free-block__when">
            <input
                :value="extractDate(b.start || b.end)"
                :disabled="b.active === false"
                class="glass-input glass-input--sm liquid-surface-control free-block__date"
                type="date"
                @change="handleDateChange(b, ($event.target as HTMLInputElement).value)"
            />
            <input
                :value="extractTime(b.start)"
                :disabled="b.active === false"
                class="glass-input glass-input--sm liquid-surface-control free-block__time"
                type="time"
                min="00:05"
                max="23:55"
                step="300"
                aria-label="Startzeit"
                @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) b.start = combineDateTime(date, (e.target as HTMLInputElement).value) || b.start }"
                @blur="handleStartTimeChange(b, ($event.target as HTMLInputElement).value)"
            />
            <input
                :value="extractTime(b.end)"
                :disabled="b.active === false"
                class="glass-input glass-input--sm liquid-surface-control free-block__time"
                type="time"
                min="00:05"
                max="23:55"
                step="300"
                aria-label="Endzeit"
                @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) b.end = combineDateTime(date, (e.target as HTMLInputElement).value) || b.end }"
                @blur="handleEndTimeChange(b, ($event.target as HTMLInputElement).value)"
            />
            <div class="free-block__programs">
              <img
                  :src="programLogoSrc('EXPLORE')"
                  :alt="programLogoAlt('EXPLORE')"
                  class="free-block__logo"
                  :class="{
                    'free-block__logo--off': b.active === false || !(b.first_program === 2 || b.first_program === 0),
                    'free-block__logo--disabled': b.active === false,
                  }"
                  title="FIRST LEGO League Explore"
                  @click="b.active !== false && toggleProgram(b, 2)"
              />
              <img
                  :src="programLogoSrc('CHALLENGE')"
                  :alt="programLogoAlt('CHALLENGE')"
                  class="free-block__logo"
                  :class="{
                    'free-block__logo--off': b.active === false || !(b.first_program === 3 || b.first_program === 0),
                    'free-block__logo--disabled': b.active === false,
                  }"
                  title="FIRST LEGO League Challenge"
                  @click="b.active !== false && toggleProgram(b, 3)"
              />
            </div>
          </div>

          <input
              :value="b.description"
              :disabled="b.active === false"
              class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
              type="text"
              placeholder="Beschreibung"
              @input="(e) => { b.description = (e.target as HTMLInputElement).value }"
              @blur="saveBlock(b)"
          />
          <input
              :value="b.link ?? ''"
              :disabled="b.active === false"
              class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
              type="url"
              placeholder="https://example.com"
              @input="(e) => { b.link = (e.target as HTMLInputElement).value }"
              @blur="saveBlock(b)"
          />
        </ItemCard>
      </div>
    </div>

    <ConfirmationModal
        :show="!!blockToDelete"
        title="Block löschen"
        :message="deleteMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="deleteBlock"
        @cancel="cancelDeleteBlock"
    />

    <ScheduleToast
        ref="savingToast"
        action="update"
        :is-generating="isGenerating"
        :countdown="countdownSeconds"
        :on-immediate-save="immediateFlush"
        message="Block-Änderungen werden gespeichert..."
    />
  </div>
</template>

<style scoped>
.free-blocks {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.free-blocks__list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.free-block__programs {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: auto;
}

.free-block__logo {
  width: 2rem;
  height: 2rem;
  flex-shrink: 0;
  object-fit: contain;
  cursor: pointer;
  transition: opacity 0.15s ease, transform 0.15s ease, filter 0.15s ease;
}

.free-block__logo:hover:not(.free-block__logo--disabled) {
  transform: scale(1.08);
}

.free-block__logo--off {
  opacity: 0.3;
  filter: grayscale(1);
}

.free-block__logo--disabled {
  cursor: not-allowed;
}

.free-block__when {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.free-block__date.glass-input,
.free-block__time.glass-input {
  display: inline-flex;
  width: auto;
  flex: 0 0 auto;
  max-width: max-content;
}

.free-block__date.glass-input {
  width: 11.25rem;
}

.free-block__time.glass-input {
  width: 7.25rem;
}

.free-block__composer-extra {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.free-block--warning {
  background: color-mix(in srgb, #b45309 14%, var(--color-bg-muted));
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
