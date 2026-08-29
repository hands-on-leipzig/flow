<script lang="ts" setup>
import {computed, nextTick, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import ExtraBlockProgramPicker from '@/components/atoms/ExtraBlockProgramPicker.vue'
import {useExtraBlockDebouncedSave} from '@/composables/useExtraBlockDebouncedSave'
import {pollPlanUntilReady} from '@/composables/usePlanGeneratorPoll'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import type {FreeExtraBlock} from '@/types/extraBlock'
import {parseExtraBlockSaveError} from '@/utils/extraBlockApiErrors'
import {
  combineDateTime,
  extractDate,
  extractTime,
  normalizeTime,
  timeToMinutes,
  type Maybe,
} from '@/utils/extraBlockDateTime'
import {blockRowKey, nextClientKey, orderDebouncedUpdates} from '@/utils/extraBlockSaveKeys'

const SAVE_PREFIX = 'extra_block'

const props = defineProps<{
  planId: number | null
  eventDate?: string
  eventDays?: number
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

const blocks = ref<FreeExtraBlock[]>([])
const blockToDelete = ref<FreeExtraBlock | null>(null)

const {attachedPrograms} = useScheduleWorkspace()

const {
  isGenerating,
  generatorError,
  errorDetails,
  scheduleBlockSave,
  cancelPendingBlockSave,
  scheduleBlockDelete,
} = useExtraBlockDebouncedSave({
  keyPrefix: SAVE_PREFIX,
  onFlush: flushUpdates,
})

function compareDateTime(a: Maybe<string>, b: Maybe<string>): number {
  const left = a ? a.replace('T', ' ') : ''
  const right = b ? b.replace('T', ' ') : ''
  if (!left && !right) return 0
  if (!left) return 1
  if (!right) return -1
  return left.localeCompare(right)
}

function compareBlocks(a: FreeExtraBlock, b: FreeExtraBlock): number {
  const byStart = compareDateTime(a.start, b.start)
  if (byStart !== 0) return byStart
  return compareDateTime(a.end, b.end)
}

const sortedBlocks = computed(() => blocks.value.slice().sort(compareBlocks))

function toApiPayload(block: FreeExtraBlock) {
  const payload: Record<string, unknown> = {
    plan: block.plan,
    first_program: block.first_program,
    name: block.name,
    description: block.description,
    link: block.link,
    start: block.start,
    end: block.end,
    room: block.room,
    active: block.active,
  }
  if (block.id) payload.id = block.id
  return payload
}

watch(() => props.planId, (v) => {
  if (v != null) void loadBlocks()
}, {immediate: true})

async function loadBlocks() {
  const pid = props.planId
  if (!pid) return
  const {data} = await axios.get<FreeExtraBlock[]>(`/plans/${pid}/extra-blocks`, {
    params: {type: 'free'},
  })
  blocks.value = Array.isArray(data) ? data : []
}

async function flushUpdates(updates: Record<string, unknown>) {
  if (!props.planId) return

  generatorError.value = null
  errorDetails.value = null
  isGenerating.value = true

  try {
    for (const [name, value] of orderDebouncedUpdates(updates, SAVE_PREFIX)) {
      if (name.startsWith(`${SAVE_PREFIX}_update`) && value) {
        const response = await axios.post(
          `/plans/${props.planId}/extra-blocks`,
          toApiPayload(value as FreeExtraBlock),
        )
        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
      }
      if (name.startsWith(`${SAVE_PREFIX}_delete`) && value && (value as FreeExtraBlock).id) {
        const deleteResponse = await axios.delete(`/extra-blocks/${(value as FreeExtraBlock).id}`)
        if (deleteResponse.data?.error) {
          generatorError.value = deleteResponse.data.error
          errorDetails.value = deleteResponse.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
      }
      if (name.startsWith(`${SAVE_PREFIX}_add`) && value) {
        const block = value as FreeExtraBlock
        if (block._clientKey && !blocks.value.some((b) => b._clientKey === block._clientKey)) {
          continue
        }
        const response = await axios.post(
          `/plans/${props.planId}/extra-blocks`,
          toApiPayload(block),
        )
        if (response.data?.error) {
          generatorError.value = response.data.error
          errorDetails.value = response.data.details || null
          isGenerating.value = false
          await loadBlocks()
          return
        }
        const saved = response.data?.block || response.data
        if (block._clientKey && saved?.id) {
          const idx = blocks.value.findIndex((b) => b._clientKey === block._clientKey)
          if (idx !== -1) blocks.value[idx] = saved
        }
      }
    }
    await loadBlocks()
    await pollPlanUntilReady(props.planId, isGenerating, generatorError, errorDetails)
    emit('changed')
  } catch (error: unknown) {
    console.error('Error flushing updates:', error)
    isGenerating.value = false
    const parsed = parseExtraBlockSaveError(error, 'Fehler beim Speichern der Blöcke')
    generatorError.value = parsed.message
    errorDetails.value = parsed.details
  }
}

const newBlockName = ref('')
const newBlockDescription = ref('')
const newBlockLink = ref('')
const newBlockDate = ref('')
const newBlockStart = ref('06:00')
const newBlockEnd = ref('07:00')
const newFirstProgram = ref(0)
const composerRef = ref<{ focusTitle?: () => void } | null>(null)

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

function setBlockFirstProgram(block: FreeExtraBlock, value: number) {
  block.first_program = value
  scheduleBlockSave(block)
}

function createCustom() {
  if (!props.planId) return
  const name = newBlockName.value.trim()
  if (!name) return

  const dateStr = newBlockDate.value || defaultBlockDate()
  const start = combineDateTime(dateStr, newBlockStart.value || '06:00') || `${dateStr} 06:00:00`
  const end = combineDateTime(dateStr, newBlockEnd.value || '07:00') || `${dateStr} 07:00:00`

  const block: FreeExtraBlock = {
    _clientKey: nextClientKey(),
    plan: props.planId,
    first_program: newFirstProgram.value,
    name,
    description: newBlockDescription.value,
    link: newBlockLink.value.trim() || null,
    active: true,
    start,
    end,
  }

  blocks.value.push(block)
  resetComposer()
  scheduleBlockSave(block)
  void nextTick(() => composerRef.value?.focusTitle?.())
}

function confirmDeleteBlock(block: FreeExtraBlock) {
  blockToDelete.value = block
}

function cancelDeleteBlock() {
  blockToDelete.value = null
}

function deleteBlock() {
  if (!blockToDelete.value) return
  const block = blockToDelete.value
  blockToDelete.value = null
  cancelPendingBlockSave(block)
  blocks.value = blocks.value.filter((b) => {
    if (block.id) return b.id !== block.id
    if (block._clientKey) return b._clientKey !== block._clientKey
    return b !== block
  })
  if (block.id) {
    scheduleBlockDelete(block as FreeExtraBlock & {id: number})
  }
}

function toggleActive(block: FreeExtraBlock, active: boolean) {
  block.active = active
  scheduleBlockSave(block)
}

function handleDateChange(block: FreeExtraBlock, date: string) {
  const startTime = extractTime(block.start || '')
  const endTime = extractTime(block.end || '')
  block.start = combineDateTime(date, startTime || '00:00')
  block.end = combineDateTime(date, endTime || '00:00')
  scheduleBlockSave(block)
}

function handleStartTimeChange(block: FreeExtraBlock, time: string) {
  const date = extractDate(block.start || block.end || '')
  if (!date || !time) return

  const normalizedStart = normalizeTime(time)
  const startMinutes = timeToMinutes(normalizedStart)
  const currentEnd = extractTime(block.end || '')
  const normalizedEnd = currentEnd ? normalizeTime(currentEnd) : '23:55'
  let endMinutes = timeToMinutes(normalizedEnd)

  if (startMinutes >= endMinutes) {
    endMinutes = Math.min(startMinutes + 5, 23 * 60 + 55)
    const endHours = Math.floor(endMinutes / 60)
    const endMins = endMinutes % 60
    const newEnd = `${String(endHours).padStart(2, '0')}:${String(endMins).padStart(2, '0')}`
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, newEnd)
  } else {
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, normalizedEnd)
  }
  scheduleBlockSave(block)
}

function handleEndTimeChange(block: FreeExtraBlock, time: string) {
  const date = extractDate(block.start || block.end || '')
  if (!date || !time) return

  const normalizedEnd = normalizeTime(time)
  const endMinutes = timeToMinutes(normalizedEnd)
  const currentStart = extractTime(block.start || '')
  const normalizedStart = currentStart ? normalizeTime(currentStart) : '00:05'
  const startMinutes = timeToMinutes(normalizedStart)

  if (endMinutes < startMinutes) {
    const newStartMinutes = Math.max(endMinutes - 5, 5)
    const startHours = Math.floor(newStartMinutes / 60)
    const startMins = newStartMinutes % 60
    const newStart = `${String(startHours).padStart(2, '0')}:${String(startMins).padStart(2, '0')}`
    block.start = combineDateTime(date, newStart)
    block.end = combineDateTime(date, normalizedEnd)
  } else {
    block.start = combineDateTime(date, normalizedStart)
    block.end = combineDateTime(date, normalizedEnd)
  }
  scheduleBlockSave(block)
}

const deleteMessage = computed(() => {
  if (!blockToDelete.value) return ''
  const name = (blockToDelete.value.name || '').trim() || 'Unbenannt'
  return `„${name}“ wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
})

function isBlockOutsideEventDates(block: FreeExtraBlock): boolean {
  if (!props.eventDate) return false
  if (!props.eventDays || props.eventDays < 1) return false

  const blockDateStr = extractDate(block.start || block.end || '')
  if (!blockDateStr) return false

  const eventStartDate = dayjs(props.eventDate)
  const eventEndDate = eventStartDate.add(props.eventDays - 1, 'day')
  const blockDate = dayjs(blockDateStr)

  return blockDate.isBefore(eventStartDate, 'day') || blockDate.isAfter(eventEndDate, 'day')
}

const hasBlocksOutsideEventDates = computed(() =>
  sortedBlocks.value.some((block) => isBlockOutsideEventDates(block)),
)
</script>

<template>
  <div class="space-y-8 relative">
    <div class="free-blocks">
      <div v-if="hasBlocksOutsideEventDates" class="glass-alert-warning">
        Freie Blöcke an Tagen außerhalb der Veranstaltung werden in den Plänen nicht angezeigt.
      </div>

      <div class="free-blocks__list">
        <ItemComposer
            ref="composerRef"
            v-model:title="newBlockName"
            :disabled="!planId"
            title-placeholder="Neuer Block z. B. Mittagessen"
            empty-hint="Eigener Eintrag im Plan, ohne den generierten Ablauf zu ändern."
            @commit="createCustom"
        >
          <div class="free-block__when">
            <input
                v-model="newBlockDate"
                :disabled="!planId"
                class="glass-input glass-input--sm liquid-surface-control free-block__date"
                type="date"
            />
            <input
                v-model="newBlockStart"
                :disabled="!planId"
                class="glass-input glass-input--sm liquid-surface-control free-block__time"
                type="time"
                min="00:05"
                max="23:55"
                step="300"
                aria-label="Startzeit"
            />
            <input
                v-model="newBlockEnd"
                :disabled="!planId"
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
                  :disabled="!planId"
                  class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                  type="text"
                  placeholder="Beschreibung"
              />
              <input
                  v-model="newBlockLink"
                  :disabled="!planId"
                  class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
                  type="url"
                  placeholder="https://example.com"
              />
            </div>
          </transition>
        </ItemComposer>

        <ItemCard
            v-for="b in sortedBlocks"
            :key="blockRowKey(b)"
            :inactive="b.active === false"
            :class="{
              'free-block--warning': b.active !== false && isBlockOutsideEventDates(b),
            }"
        >
          <template #leading>
            <ToggleSwitch
                :model-value="b.active !== false"
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
                @input="(e) => { b.name = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
            />
          </template>
          <template #trailing>
            <IconDangerButton
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
                @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) { b.start = combineDateTime(date, (e.target as HTMLInputElement).value) || b.start; scheduleBlockSave(b) } }"
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
                @input="(e) => { const date = extractDate(b.start || b.end || ''); if (date) { b.end = combineDateTime(date, (e.target as HTMLInputElement).value) || b.end; scheduleBlockSave(b) } }"
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
              @input="(e) => { b.description = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
          />
          <input
              :value="b.link ?? ''"
              :disabled="b.active === false"
              class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
              type="url"
              placeholder="https://example.com"
              @input="(e) => { b.link = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
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
