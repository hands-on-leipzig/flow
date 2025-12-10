<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { useDebouncedSave } from "@/composables/useDebouncedSave";
import { TIMING_FIELDS, DEBOUNCE_DELAY } from "@/constants/extraBlocks";
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

const { scheduleUpdate, flush, immediateFlush } = useDebouncedSave({
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
const customBlocks = computed(() => blocks.value.filter(b => !('insert_point' in b) || !b.insert_point))

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
watch(() => props.planId, v => { if (v != null) loadBlocks() }, { immediate: true })

// Cleanup handled by composable

// --- Load blocks ---
async function loadBlocks() {
  const pid = props.planId
  if (!pid) return
  const { data } = await axios.get<ExtraBlock[]>(`/plans/${pid}/extra-blocks`)
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

// Expose functions to parent (for consistency with InsertBlocks)
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
        const blockData = { ...value }
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

// --- Actions ---
async function addCustom() {
  if (!props.planId) return
  const baseDate = props.eventDate ? new Date(props.eventDate) : new Date()
  // Format as YYYY-MM-DD
  const dateStr = baseDate.toISOString().slice(0, 10)
  
  const draft: ExtraBlock = {
    plan: props.planId!,
    first_program: 3,
    name: '',
    description: '',
    link: null,
    active: true,
    start: combineDateTime(dateStr, '06:00') || `${dateStr} 06:00:00`,
    end: combineDateTime(dateStr, '07:00') || `${dateStr} 07:00:00`
  }
  
  try {
    // Save immediately with default timing but empty content
    // This gives the block an ID so it behaves like any other block
    const response = await axios.post(`/plans/${props.planId}/extra-blocks`, {
      ...draft,
      skip_regeneration: true // Don't regenerate for empty block
    })
    
    // Check for errors
    if (response.data?.error) {
      generatorError.value = response.data.error
      errorDetails.value = response.data.details || null
      return
    }
    
    // Reload blocks to get the new block with its ID
    await loadBlocks()
    
    // Emit changed event
    emit('changed')
  } catch (error: any) {
    console.error('Failed to create block:', error)
    generatorError.value = 'Fehler beim Erstellen des Blocks'
    errorDetails.value = error.message || 'Unbekannter Fehler'
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
  scheduleUpdate('extra_block_update', { ...block })
}

function toggleActive(block: ExtraBlock, active: boolean) {
  if (!block.id) return
  block.active = active
  // Ensure toggle change is caught by debouncer with countdown
  scheduleUpdate('extra_block_update', { ...block, active })
}

function toggleProgram(block: ExtraBlock, program: 2 | 3) {
  if (program === 2) {
    if (block.first_program === 2) block.first_program = null
    else if (block.first_program === 3) block.first_program = 0
    else if (block.first_program === 0) block.first_program = 3
    else block.first_program = 2
  } else {
    if (block.first_program === 3) block.first_program = null
    else if (block.first_program === 2) block.first_program = 0
    else if (block.first_program === 0) block.first_program = 2
    else block.first_program = 3
  }
  saveBlock(block)
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
  scheduleUpdate('extra_block_update', { ...block })
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
  scheduleUpdate('extra_block_update', { ...block })
}

const deleteMessage = computed(() => {
  if (!blockToDelete.value) return ''
  return `Block "${blockToDelete.value.name || 'Unbenannt'}" wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})
</script>

<template>
  <div class="space-y-8 relative">
    <!-- Error Alert Banner -->
    <div v-if="generatorError" class="bg-red-50 border-l-4 border-red-500 p-4 rounded shadow-lg">
      <div class="flex items-start justify-between">
        <div class="flex-1">
          <div class="flex items-center">
            <svg class="h-5 w-5 text-red-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
            </svg>
            <h3 class="text-red-800 font-semibold text-lg">{{ generatorError }}</h3>
          </div>
          <p v-if="errorDetails" class="mt-2 text-red-700 text-sm">{{ errorDetails }}</p>
        </div>
        <button
          @click="generatorError = null; errorDetails = null"
          class="ml-4 text-red-500 hover:text-red-700 focus:outline-none"
          aria-label="Fehler schließen"
        >
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
          </svg>
        </button>
      </div>
    </div>

    <!-- CUSTOM BLOCKS -->
    <div class="bg-white shadow-sm rounded-xl border border-gray-200 relative">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <span class="text-sm text-gray-600">Diese Blöcke werden direkt in den generierten Plan kopiert.</span>
        <button class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm disabled:bg-gray-400 disabled:cursor-not-allowed"
                :disabled="!planId"
                @click="addCustom">
          + Block hinzufügen
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
          <tr class="text-gray-500 text-xs uppercase tracking-wide">
            <th class="text-left px-2 py-2 w-20">Aktion</th>
            <th class="text-center px-2 py-2 w-20">Programme</th>
            <th class="text-left px-2 py-2 w-28">Zeit</th>
            <th class="text-left px-2 py-2">Inhalt</th>
          </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
          <tr v-for="b in visibleCustomBlocks" :key="b.id ?? JSON.stringify(b)"
              class="border-b transition-all duration-200"
              :class="{
                'opacity-60 bg-gray-50': b.active === false,
                'hover:bg-gray-50': b.active !== false
              }">
            <td class="px-2 py-2">
              <div class="flex flex-col items-center space-y-2">
                <ToggleSwitch
                  :model-value="b.active !== false"
                  @update:modelValue="toggleActive(b, $event)"
                  :disabled="!b.id"
                />
                <button
                  v-if="b.id"
                  @click="confirmDeleteBlock(b)"
                  class="text-red-500 hover:text-red-700"
                  title="Block löschen"
                >
                  🗑️
                </button>
              </div>
            </td>

            <td class="px-2 py-2 text-center">
              <div class="flex justify-center space-x-1">
                <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')"
                     :class="[
                       'w-8 h-8 transition-all duration-200',
                       b.active === false
                         ? 'opacity-30 grayscale cursor-not-allowed'
                         : (b.first_program === 2 || b.first_program === 0
                            ? 'opacity-100 cursor-pointer hover:scale-110'
                            : 'opacity-30 grayscale cursor-pointer hover:scale-110')
                     ]"
                     @click="b.active !== false && toggleProgram(b, 2)" title="FIRST LEGO League Explore"/>
                <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')"
                     :class="[
                       'w-8 h-8 transition-all duration-200',
                       b.active === false
                         ? 'opacity-30 grayscale cursor-not-allowed'
                         : (b.first_program === 3 || b.first_program === 0
                            ? 'opacity-100 cursor-pointer hover:scale-110'
                            : 'opacity-30 grayscale cursor-pointer hover:scale-110')
                     ]"
                     @click="b.active !== false && toggleProgram(b, 3)" title="FIRST LEGO League Challenge"/>
              </div>
            </td>

            <td class="px-2 py-2">
              <div class="space-y-2">
                <!-- Date field (first line) -->
                <input 
                  :value="extractDate(b.start || b.end)" 
                  :disabled="b.active === false"
                  :class="['w-full border rounded px-2 py-1 text-sm',
                           b.active !== false
                             ? 'bg-white border-gray-300'
                             : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                  type="date"
                  @change="handleDateChange(b, ($event.target as HTMLInputElement).value)"
                />
                <!-- Start and End time fields (second line) -->
                <div class="flex space-x-1">
                  <input 
                    :value="extractTime(b.start)" 
                    :disabled="b.active === false"
                    :class="['flex-1 border rounded px-2 py-1 text-sm',
                             b.active !== false
                               ? 'bg-white border-gray-300'
                               : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                    type="time"
                    min="00:05"
                    max="23:55"
                    step="300"
                    placeholder="Start"
                    @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) b.start = combineDateTime(date, (e.target as HTMLInputElement).value) || b.start }"
                    @blur="handleStartTimeChange(b, ($event.target as HTMLInputElement).value)"
                  />
                  <input 
                    :value="extractTime(b.end)" 
                    :disabled="b.active === false"
                    :class="['flex-1 border rounded px-2 py-1 text-sm',
                             b.active !== false
                               ? 'bg-white border-gray-300'
                               : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                    type="time"
                    min="00:05"
                    max="23:55"
                    step="300"
                    placeholder="Ende"
                    @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) b.end = combineDateTime(date, (e.target as HTMLInputElement).value) || b.end }"
                    @blur="handleEndTimeChange(b, ($event.target as HTMLInputElement).value)"
                  />
                </div>
              </div>
            </td>

            <td class="px-2 py-2">
              <div class="space-y-2">
                <div class="flex space-x-2">
                  <input :value="b.name"
                         :disabled="b.active === false"
                         :class="['flex-1 border rounded px-2 py-1 text-sm',
                                  b.active !== false
                                    ? 'bg-white border-gray-300'
                                    : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                         type="text" placeholder="Titel"
                         @input="(e) => { b.name = (e.target as HTMLInputElement).value }"
                         @blur="saveBlock(b)"/>
                  <input :value="b.link ?? ''"
                         :disabled="b.active === false"
                         :class="['flex-1 border rounded px-2 py-1 text-sm',
                                  b.active !== false
                                    ? 'bg-white border-gray-300'
                                    : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                         type="url" placeholder="https://example.com"
                         @input="(e) => { b.link = (e.target as HTMLInputElement).value }"
                         @blur="saveBlock(b)"/>
                </div>
                <input :value="b.description"
                       :disabled="b.active === false"
                       :class="['w-full border rounded px-2 py-1 text-sm',
                                b.active !== false
                                  ? 'bg-white border-gray-300'
                                  : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                       type="text" placeholder="Beschreibung"
                       @input="(e) => { b.description = (e.target as HTMLInputElement).value }"
                       @blur="saveBlock(b)"/>
              </div>
            </td>
          </tr>

          <tr v-if="!customBlocks.length">
            <td class="px-4 py-6 text-gray-500 text-center" colspan="4">
              Noch keine freien Zusatzblöcke. Füge oben welche hinzu.
            </td>
          </tr>
          </tbody>
        </table>
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
      :is-generating="isGenerating"
      :countdown="countdownSeconds"
      :on-immediate-save="immediateFlush"
      message="Block-Änderungen werden gespeichert..."
    />
  </div>
</template>