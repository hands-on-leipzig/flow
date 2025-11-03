<script lang="ts" setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import axios from 'axios'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import SavingToast from "@/components/atoms/SavingToast.vue";
import { useDebouncedSave } from "@/composables/useDebouncedSave";
import { TIMING_FIELDS, DEBOUNCE_DELAY } from "@/constants/extraBlocks";

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

// --- State ---
const blocks = ref<ExtraBlock[]>([])
const blockToDelete = ref<ExtraBlock | null>(null)

// Generator state (must be declared before useDebouncedSave)
const isGenerating = ref(false)

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

// --- Central Flush Logic ---
async function flushUpdates(updates: Record<string, any>) {
  if (!props.planId) return

  // Determine if regeneration is needed before making API calls
  let needsRegeneration = false
  for (const [name, value] of Object.entries(updates)) {
    if (name === 'extra_block_update' && value) {
      const hasTimingChanges = Object.keys(value).some(f => TIMING_FIELDS.includes(f))
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
        const hasTimingChanges = Object.keys(value).some(f => TIMING_FIELDS.includes(f))
        const blockData = { ...value }
        if (!hasTimingChanges) {
          blockData.skip_regeneration = true
        }
        await axios.post(`/plans/${props.planId}/extra-blocks`, blockData)
      }
      if (name === 'extra_block_delete' && value?.id) {
        await axios.delete(`/extra-blocks/${value.id}`)
      }
      if (name === 'extra_block_add' && value) {
        await axios.post(`/plans/${props.planId}/extra-blocks`, value)
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
  } catch (error) {
    console.error('Error flushing updates:', error)
    isGenerating.value = false
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
        console.error('Plan generation failed')
        return
      }
      
      // Keep polling if still running
      await new Promise(resolve => setTimeout(resolve, intervalMs))
    }

    throw new Error('Timeout: Plan generation took too long')
  } catch (error) {
    isGenerating.value = false
    if (error instanceof Error && error.message.includes('Timeout')) {
      console.error('Timeout waiting for plan generation')
    } else {
      throw error
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
function addCustom() {
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
  
  // Optimistically add to UI at the top so it shows immediately
  blocks.value.unshift(draft)
  
  // Schedule update - this will start the countdown timer
  // User can now edit the block before it's saved and regenerated
  scheduleUpdate('extra_block_add', draft)
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

function saveBlock(block: ExtraBlock) {
  scheduleUpdate('extra_block_update', block)
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

// Helper function to round minutes to nearest multiple of 5
function roundTo5Minutes(time: string): string {
  if (!time || !time.includes(':')) return '00:00'
  const [hours, minutes] = time.split(':').map(Number)
  const roundedMinutes = Math.round(minutes / 5) * 5
  // Handle wrap-around if minutes round to 60
  let finalHours = hours
  let finalMinutes = roundedMinutes
  if (finalMinutes >= 60) {
    finalHours = (finalHours + 1) % 24
    finalMinutes = 0
  }
  return `${String(finalHours).padStart(2, '0')}:${String(finalMinutes).padStart(2, '0')}`
}

// Helper function to add 5 minutes to a time string (HH:mm)
function add5Minutes(time: string): string {
  if (!time || !time.includes(':')) return '00:00'
  const [hours, minutes] = time.split(':').map(Number)
  let totalMinutes = hours * 60 + minutes + 5
  // Handle day wrap-around (though max should be 23:55)
  const newHours = Math.floor(totalMinutes / 60) % 24
  const newMinutes = totalMinutes % 60
  return `${String(newHours).padStart(2, '0')}:${String(newMinutes).padStart(2, '0')}`
}

// Helper function to compare two time strings (HH:mm)
function compareTimes(time1: string, time2: string): number {
  if (!time1 || !time2) return 0
  const [h1, m1] = time1.split(':').map(Number)
  const [h2, m2] = time2.split(':').map(Number)
  const minutes1 = h1 * 60 + m1
  const minutes2 = h2 * 60 + m2
  return minutes1 - minutes2
}

// Handle start time change
function handleStartTimeChange(block: ExtraBlock, time: string) {
  // Always use the same date for both start and end
  const date = extractDate(block.start || block.end || '')
  let endTime = extractTime(block.end || '')
  
  if (!date) return // Need date first
  
  // Round time to nearest 5 minutes
  const roundedTime = roundTo5Minutes(time)
  
  // If start time is greater than or equal to end time, set end to start + 5 minutes
  if (endTime && compareTimes(roundedTime, endTime) >= 0) {
    endTime = add5Minutes(roundedTime)
  } else if (!endTime) {
    // If no end time exists, set it to start + 5 minutes
    endTime = add5Minutes(roundedTime)
  } else {
    // Round existing end time as well
    endTime = roundTo5Minutes(endTime)
  }
  
  block.start = combineDateTime(date, roundedTime)
  // Ensure end uses the same date
  block.end = combineDateTime(date, endTime)
  saveBlock(block)
}

// Handle end time change
function handleEndTimeChange(block: ExtraBlock, time: string) {
  // Always use the same date for both start and end
  const date = extractDate(block.start || block.end || '')
  let startTime = extractTime(block.start || '')
  
  if (!date) return // Need date first
  
  // Round time to nearest 5 minutes
  const roundedTime = roundTo5Minutes(time)
  
  // Round existing start time as well
  if (startTime) {
    startTime = roundTo5Minutes(startTime)
  } else {
    startTime = '00:00'
  }
  
  // Ensure start uses the same date
  block.start = combineDateTime(date, startTime)
  block.end = combineDateTime(date, roundedTime)
  saveBlock(block)
}

const deleteMessage = computed(() => {
  if (!blockToDelete.value) return ''
  return `Möchtest du den Block "${blockToDelete.value.name || 'Unbenannt'}" wirklich löschen?`
})
</script>

<template>
  <div class="space-y-8 relative">
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
                     class="w-8 h-8 cursor-pointer transition-all duration-200 hover:scale-110"
                     :class="{ 'opacity-100': b.first_program === 2 || b.first_program === 0, 'opacity-30 grayscale': !(b.first_program === 2 || b.first_program === 0) }"
                     @click="toggleProgram(b, 2)" title="Explore"/>
                <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')"
                     class="w-8 h-8 cursor-pointer transition-all duration-200 hover:scale-110"
                     :class="{ 'opacity-100': b.first_program === 3 || b.first_program === 0, 'opacity-30 grayscale': !(b.first_program === 3 || b.first_program === 0) }"
                     @click="toggleProgram(b, 3)" title="Challenge"/>
              </div>
            </td>

            <td class="px-2 py-2">
              <div class="space-y-2">
                <!-- Date field (first line) -->
                <input 
                  :value="extractDate(b.start || b.end)" 
                  class="w-full border rounded px-2 py-1 text-sm" 
                  type="date"
                  @change="handleDateChange(b, ($event.target as HTMLInputElement).value)"
                />
                <!-- Start and End time fields (second line) -->
                <div class="flex space-x-1">
                  <input 
                    :value="extractTime(b.start)" 
                    class="flex-1 border rounded px-2 py-1 text-sm" 
                    type="time"
                    step="300"
                    min="00:00"
                    max="23:55"
                    placeholder="Start"
                    @change="handleStartTimeChange(b, ($event.target as HTMLInputElement).value)"
                  />
                  <input 
                    :value="extractTime(b.end)" 
                    class="flex-1 border rounded px-2 py-1 text-sm" 
                    type="time"
                    step="300"
                    min="00:00"
                    max="23:55"
                    placeholder="Ende"
                    @change="handleEndTimeChange(b, ($event.target as HTMLInputElement).value)"
                  />
                </div>
              </div>
            </td>

            <td class="px-2 py-2">
              <div class="space-y-2">
                <div class="flex space-x-2">
                  <input v-model="b.name" class="flex-1 border rounded px-2 py-1 text-sm"
                         type="text" placeholder="Titel" @blur="saveBlock(b)"/>
                  <input v-model="b.link" class="flex-1 border rounded px-2 py-1 text-sm"
                         type="url" placeholder="https://example.com" @blur="saveBlock(b)"/>
                </div>
                <input v-model="b.description" class="w-full border rounded px-2 py-1 text-sm"
                       type="text" placeholder="Beschreibung" @blur="saveBlock(b)"/>
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

    <SavingToast 
      ref="savingToast" 
      :is-generating="isGenerating"
      :countdown="countdownSeconds"
      :on-immediate-save="immediateFlush"
      message="Block-Änderungen werden gespeichert..."
    />
  </div>
</template>