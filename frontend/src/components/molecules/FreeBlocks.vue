<script lang="ts" setup>
import {computed, nextTick, onMounted, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import ExtraBlockProgramPicker from '@/components/atoms/ExtraBlockProgramPicker.vue'
import {useDebouncedSave} from '@/composables/useDebouncedSave'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import {DEBOUNCE_DELAY} from '@/constants/extraBlocks'

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

const {
  attachedPrograms,
  isGenerating,
  generatorError,
  errorDetails,
  countdownSeconds,
  registerExtraBlockImmediateFlush,
} = useScheduleWorkspace()

const {scheduleUpdate, immediateFlush} = useDebouncedSave({
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
  },
})

onMounted(() => {
  registerExtraBlockImmediateFlush(immediateFlush)
  if (props.planId != null) loadBlocks()
})

onUnmounted(() => {
  registerExtraBlockImmediateFlush(null)
})

// --- Batch save system (save on countdown) ---
// Note: Block changes trigger debounce immediately, blocks are saved to DB when countdown reaches 0 or is clicked

function compareDateTime(a: Maybe<string>, b: Maybe<string>): number {
  const left = a ? a.replace('T', ' ') : ''
  const right = b ? b.replace('T', ' ') : ''
  if (!left && !right) return 0
  if (!left) return 1
  if (!right) return -1
  return left.localeCompare(right)
}

function compareBlocks(a: ExtraBlock, b: ExtraBlock): number {
  const byStart = compareDateTime(a.start, b.start)
  if (byStart !== 0) return byStart
  return compareDateTime(a.end, b.end)
}

const customBlocks = computed(() => blocks.value)

const sortedBlocks = computed(() => customBlocks.value.slice().sort(compareBlocks))

// --- Lifecycle ---
watch(() => props.planId, v => {
  if (v != null) loadBlocks()
}, {immediate: true})

// --- Load blocks ---
async function loadBlocks() {
  const pid = props.planId
  if (!pid) return
  const {data} = await axios.get<ExtraBlock[]>(`/plans/${pid}/extra-blocks`, {
    params: {type: 'free'},
  })
  blocks.value = Array.isArray(data) ? data : []
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

      const response = await axios.post(`/plans/${props.planId}/extra-blocks`, blockData)
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

  generatorError.value = null
  errorDetails.value = null
  isGenerating.value = true

  try {
    for (const [name, value] of Object.entries(updates)) {
      if (name === 'extra_block_update' && value) {
        const response = await axios.post(`/plans/${props.planId}/extra-blocks`, value)

        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
      }
      if (name === 'extra_block_delete' && value?.id) {
        const deleteResponse = await axios.delete(`/extra-blocks/${value.id}`)

        if (deleteResponse.data?.error) {
          generatorError.value = deleteResponse.data.error
          errorDetails.value = deleteResponse.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
      }
      if (name === 'extra_block_add' && value) {
        const response = await axios.post(`/plans/${props.planId}/extra-blocks`, value)

        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
      }
    }
    await loadBlocks()
    await pollUntilReady(props.planId)
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

function setBlockFirstProgram(block: ExtraBlock, value: number) {
  block.first_program = value
  saveBlock(block)
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
  scheduleUpdate('extra_block_update', {...block, active})
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

const hasBlocksOutsideEventDates = computed(() => {
  return sortedBlocks.value.some(block => isBlockOutsideEventDates(block))
})
</script>

<template>
  <div class="space-y-8 relative">
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
            v-for="b in sortedBlocks"
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

          <div class="free-block__when free-block__when--block">
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
            <ExtraBlockProgramPicker
                :model-value="b.first_program"
                :programs="attachedPrograms"
                :disabled="b.active === false"
                match-input-height
                @update:model-value="setBlockFirstProgram(b, $event)"
            />
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

.free-block__when {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.free-block__when--block {
  align-items: stretch;
}

.free-block__when--block :deep(.extra-block-program-picker) {
  margin-left: auto;
  display: flex;
  align-items: stretch;
}

.free-block__when--block :deep(.extra-block-program-picker__trigger--field) {
  height: auto;
  align-self: stretch;
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
