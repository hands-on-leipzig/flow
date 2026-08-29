<script lang="ts" setup>
import {computed, nextTick, ref, watch} from 'vue'
import axios from 'axios'
import ToggleSwitch from '../atoms/ToggleSwitch.vue'
import ConfirmationModal from './ConfirmationModal.vue'
import IconDangerButton from '@/components/atoms/IconDangerButton.vue'
import ItemCard from '@/components/molecules/ItemCard.vue'
import ItemComposer from '@/components/molecules/ItemComposer.vue'
import ExtraBlockProgramPicker from '@/components/atoms/ExtraBlockProgramPicker.vue'
import SlotTeamPanel, {type TeamSavePayload} from '@/components/molecules/SlotTeamPanel.vue'
import {useExtraBlockDebouncedSave} from '@/composables/useExtraBlockDebouncedSave'
import {runGenerateLite} from '@/composables/usePlanGeneratorPoll'
import {useScheduleWorkspace} from '@/composables/useScheduleWorkspace'
import type {SlotExtraBlock} from '@/types/extraBlock'
import {parseExtraBlockSaveError} from '@/utils/extraBlockApiErrors'
import {
  normalizeDurationMinutes,
  onDurationKeydown,
  SLOT_DURATION_MAX,
  SLOT_DURATION_MIN,
  SLOT_DURATION_STEP,
} from '@/utils/extraBlockDuration'
import {blockRowKey, nextClientKey, orderDebouncedUpdates} from '@/utils/extraBlockSaveKeys'

const SAVE_PREFIX = 'slot_block'

const props = defineProps<{
  planId: number | null
  eventDate?: string
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

const blocks = ref<SlotExtraBlock[]>([])
const blockToDelete = ref<SlotExtraBlock | null>(null)
const pendingScopeChange = ref<{block: SlotExtraBlock; value: number} | null>(null)
const selectedId = ref<number | null>(null)
const teamPanelRef = ref<InstanceType<typeof SlotTeamPanel> | null>(null)
const savingAssignments = ref(false)

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

const sortedBlocks = computed(() =>
  blocks.value.slice().sort((a, b) =>
    (a.name || '').localeCompare(b.name || '', 'de', {sensitivity: 'base'}),
  ),
)

const selectedBlock = computed(() =>
  blocks.value.find((b) => b.id === selectedId.value) ?? null,
)

const newBlockName = ref('')
const newBlockDescription = ref('')
const newBlockLink = ref('')
const newBlockDuration = ref(30)
const newFirstProgram = ref(0)
const composerRef = ref<{ focusTitle?: () => void } | null>(null)

function toApiPayload(block: SlotExtraBlock) {
  return {
    name: block.name,
    description: block.description || null,
    link: block.link || null,
    duration: normalizeDurationMinutes(block.duration),
    first_program: block.first_program ?? 0,
    active: block.active,
  }
}

watch(() => props.planId, (v) => {
  if (v != null) void loadBlocks()
}, {immediate: true})

async function loadBlocks() {
  const pid = props.planId
  if (!pid) return
  const {data} = await axios.get<SlotExtraBlock[]>(`/plans/${pid}/extra-blocks/slot`)
  blocks.value = Array.isArray(data)
    ? data.map((b) => ({
      ...b,
      duration: normalizeDurationMinutes(Number(b.duration) || 30),
      first_program: b.first_program ?? 0,
    }))
    : []

  if (selectedId.value && !blocks.value.some((b) => b.id === selectedId.value)) {
    selectedId.value = blocks.value[0]?.id ?? null
  } else if (!selectedId.value && blocks.value.length) {
    selectedId.value = blocks.value[0].id ?? null
  }
}

async function flushUpdates(updates: Record<string, unknown>) {
  if (!props.planId) return

  generatorError.value = null
  errorDetails.value = null
  let needsLite = false

  try {
    const ordered = orderDebouncedUpdates(updates, SAVE_PREFIX)

    for (const [name, value] of ordered) {
      if (name.startsWith(`${SAVE_PREFIX}_delete`) && value && (value as SlotExtraBlock).id) {
        const block = value as SlotExtraBlock
        const deleteResponse = await axios.delete(
          `/plans/${props.planId}/extra-blocks/slot/${block.id}`,
        )
        if (deleteResponse.data?.error) {
          generatorError.value = deleteResponse.data.error
          errorDetails.value = deleteResponse.data.details || null
          await loadBlocks()
          return
        }
        // Backend slotDestroy already runs generateLite.
        emit('changed')
      }

      if (name.startsWith(`${SAVE_PREFIX}_add`) && value) {
        const block = value as SlotExtraBlock
        if (block._clientKey && !blocks.value.some((b) => b._clientKey === block._clientKey)) {
          continue
        }
        const response = await axios.post(
          `/plans/${props.planId}/extra-blocks/slot`,
          toApiPayload(block),
        )
        const saved = response.data
        if (block._clientKey && saved?.id) {
          const idx = blocks.value.findIndex((b) => b._clientKey === block._clientKey)
          if (idx !== -1) {
            blocks.value[idx] = {
              ...blocks.value[idx],
              ...saved,
              duration: normalizeDurationMinutes(saved.duration),
            }
            selectedId.value = saved.id
          }
        }
        needsLite = true
      }

      if (name.startsWith(`${SAVE_PREFIX}_update`) && value && (value as SlotExtraBlock).id) {
        const block = value as SlotExtraBlock
        await axios.put(
          `/plans/${props.planId}/extra-blocks/slot/${block.id}`,
          toApiPayload(block),
        )
        needsLite = true
      }
    }

    await loadBlocks()
    await teamPanelRef.value?.reload()

    if (needsLite) {
      const ok = await runGenerateLite(
        props.planId,
        isGenerating,
        generatorError,
        errorDetails,
      )
      if (ok) emit('changed')
    }
  } catch (error: unknown) {
    console.error('Error flushing slot updates:', error)
    isGenerating.value = false
    const parsed = parseExtraBlockSaveError(error, 'Fehler beim Speichern der Slots')
    generatorError.value = parsed.message
    errorDetails.value = parsed.details
    await loadBlocks()
  }
}

async function saveAssignments(payloads: TeamSavePayload[]) {
  if (!props.planId || !payloads.length || savingAssignments.value) return

  savingAssignments.value = true
  generatorError.value = null
  errorDetails.value = null

  try {
    for (const payload of payloads) {
      await axios.patch(
        `/plans/${props.planId}/extra-blocks/slot/${payload.blockId}/teams/${payload.first_program}/${payload.team_number_plan}`,
        {start: payload.start},
      )
    }

    const ok = await runGenerateLite(
      props.planId,
      isGenerating,
      generatorError,
      errorDetails,
    )
    await teamPanelRef.value?.reload()
    if (ok) emit('changed')
  } catch (error: unknown) {
    isGenerating.value = false
    const parsed = parseExtraBlockSaveError(error, 'Fehler beim Speichern der Zuordnungen')
    generatorError.value = parsed.message
    errorDetails.value = parsed.details
    await teamPanelRef.value?.reload()
  } finally {
    savingAssignments.value = false
  }
}

function createBlock() {
  if (!props.planId) return
  const name = newBlockName.value.trim()
  if (!name) return

  const block: SlotExtraBlock = {
    _clientKey: nextClientKey(),
    plan: props.planId,
    first_program: newFirstProgram.value,
    name,
    description: newBlockDescription.value,
    link: newBlockLink.value.trim() || null,
    active: true,
    duration: normalizeDurationMinutes(newBlockDuration.value),
  }

  blocks.value.push(block)
  resetComposer()
  scheduleBlockSave(block)
  void nextTick(() => composerRef.value?.focusTitle?.())
}

function resetComposer() {
  newBlockName.value = ''
  newBlockDescription.value = ''
  newBlockLink.value = ''
  newBlockDuration.value = 30
  newFirstProgram.value = 0
}

function selectBlock(block: SlotExtraBlock) {
  if (block.id) selectedId.value = block.id
}

function confirmDeleteBlock(block: SlotExtraBlock) {
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
  if (selectedId.value === block.id) {
    selectedId.value = blocks.value[0]?.id ?? null
  }
  if (block.id) {
    scheduleBlockDelete(block as SlotExtraBlock & {id: number})
  }
}

function toggleActive(block: SlotExtraBlock, active: boolean) {
  block.active = active
  scheduleBlockSave(block)
}

function setBlockFirstProgram(block: SlotExtraBlock, value: number) {
  if (block.id && Number(block.first_program) !== Number(value)) {
    pendingScopeChange.value = {block, value}
    return
  }
  block.first_program = value
  scheduleBlockSave(block)
}

function cancelScopeChange() {
  pendingScopeChange.value = null
}

function confirmScopeChange() {
  const pending = pendingScopeChange.value
  if (!pending) return
  pending.block.first_program = pending.value
  pendingScopeChange.value = null
  scheduleBlockSave(pending.block)
  void teamPanelRef.value?.reload()
}

function onDurationInput(block: SlotExtraBlock, el: HTMLInputElement) {
  const v = normalizeDurationMinutes(Number(el.value) || SLOT_DURATION_MIN)
  el.value = String(v)
  if (v === block.duration) return
  block.duration = v
  scheduleBlockSave(block)
}

function onNewDurationInput(el: HTMLInputElement) {
  const v = normalizeDurationMinutes(Number(el.value) || SLOT_DURATION_MIN)
  el.value = String(v)
  newBlockDuration.value = v
}

const deleteMessage = computed(() => {
  if (!blockToDelete.value) return ''
  const name = (blockToDelete.value.name || '').trim() || 'Unbenannt'
  return `„${name}“ und alle Team-Zeiten dazu wirklich löschen?`
})

const scopeChangeMessage = computed(() => {
  if (!pendingScopeChange.value) return ''
  const name = (pendingScopeChange.value.block.name || '').trim() || 'Unbenannt'
  return `Programm-Zuordnung für „${name}“ ändern? Alle gespeicherten Team-Startzeiten für diesen Slot werden gelöscht.`
})
</script>

<template>
  <div class="slot-blocks space-y-6">
    <div class="slot-blocks__list">
      <ItemComposer
          ref="composerRef"
          v-model:title="newBlockName"
          :disabled="!planId"
          title-placeholder="Neuer Slot z. B. Führung"
          empty-hint="Zeitfenster pro Team, unabhängig vom generierten Ablauf."
          @commit="createBlock"
      >
        <div class="slot-block__meta">
          <label class="slot-block__duration">
            <span>Min.</span>
            <input
                :value="newBlockDuration"
                :disabled="!planId"
                class="glass-input glass-input--sm liquid-surface-control"
                type="number"
                :min="SLOT_DURATION_MIN"
                :max="SLOT_DURATION_MAX"
                :step="SLOT_DURATION_STEP"
                inputmode="none"
                @keydown="onDurationKeydown"
                @paste.prevent
                @input="onNewDurationInput($event.target as HTMLInputElement)"
            />
          </label>
          <ExtraBlockProgramPicker
              v-model="newFirstProgram"
              :programs="attachedPrograms"
              :disabled="!planId"
              match-input-height
          />
        </div>
        <transition name="fade">
          <div v-if="newBlockName.trim().length > 0" class="slot-block__composer-extra">
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
          interactive
          :selected="b.id === selectedId"
          @click="selectBlock(b)"
      >
        <template #leading>
          <ToggleSwitch
              :model-value="b.active !== false"
              @update:modelValue="toggleActive(b, $event)"
              @click.stop
          />
        </template>
        <template #title>
          <input
              :value="b.name"
              :disabled="b.active === false"
              class="item-card__title glass-input glass-input--sm liquid-surface-control"
              type="text"
              placeholder="Titel"
              @click.stop
              @input="(e) => { b.name = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
          />
        </template>
        <template #trailing>
          <IconDangerButton
              label="Slot löschen"
              @click.stop="confirmDeleteBlock(b)"
          />
        </template>

        <div class="slot-block__meta slot-block__meta--block" @click.stop>
          <label class="slot-block__duration">
            <span>Min.</span>
            <input
                :value="b.duration"
                :disabled="b.active === false"
                class="glass-input glass-input--sm liquid-surface-control"
                type="number"
                :min="SLOT_DURATION_MIN"
                :max="SLOT_DURATION_MAX"
                :step="SLOT_DURATION_STEP"
                inputmode="none"
                @keydown="onDurationKeydown"
                @paste.prevent
                @input="onDurationInput(b, $event.target as HTMLInputElement)"
            />
          </label>
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
            @click.stop
            @input="(e) => { b.description = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
        />
        <input
            :value="b.link ?? ''"
            :disabled="b.active === false"
            class="glass-input glass-input--sm liquid-surface-control w-full min-w-0"
            type="url"
            placeholder="https://example.com"
            @click.stop
            @input="(e) => { b.link = (e.target as HTMLInputElement).value; scheduleBlockSave(b) }"
        />
      </ItemCard>
    </div>

    <section v-if="planId" class="slot-blocks__teams">
      <SlotTeamPanel
          ref="teamPanelRef"
          :plan-id="planId"
          :block-id="selectedId"
          :block-first-program="selectedBlock?.first_program ?? 0"
          :block-active="selectedBlock?.active !== false"
          :event-date="eventDate"
          :saving="savingAssignments"
          @save-assignments="saveAssignments"
      />
    </section>

    <ConfirmationModal
        :show="!!blockToDelete"
        title="Slot löschen"
        :message="deleteMessage"
        type="danger"
        confirm-text="Löschen"
        cancel-text="Abbrechen"
        @confirm="deleteBlock"
        @cancel="cancelDeleteBlock"
    />

    <ConfirmationModal
        :show="!!pendingScopeChange"
        title="Programm-Zuordnung ändern"
        :message="scopeChangeMessage"
        type="warning"
        confirm-text="Ändern und Zeiten löschen"
        cancel-text="Abbrechen"
        @confirm="confirmScopeChange"
        @cancel="cancelScopeChange"
    />
  </div>
</template>

<style scoped>
.slot-blocks__list {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.slot-block__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.slot-block__meta--block :deep(.extra-block-program-picker) {
  margin-left: auto;
  display: flex;
  align-items: stretch;
}

.slot-block__meta--block :deep(.extra-block-program-picker__trigger--field) {
  height: auto;
  align-self: stretch;
}

.slot-block__duration {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.slot-block__duration .glass-input {
  width: 4.5rem;
}

.slot-block__composer-extra {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
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
