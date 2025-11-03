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

// --- Debounced Saving ---
const savingToast = ref(null)
const countdownSeconds = ref<number | null>(null)

const { scheduleUpdate, flush, immediateFlush } = useDebouncedSave({
  delay: DEBOUNCE_DELAY,
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
  blocks.value = Array.isArray(data) ? data : []
}

// --- Central Flush Logic ---
async function flushUpdates(updates: Record<string, any>) {
  if (!props.planId) return

  try {
    for (const [name, value] of Object.entries(updates)) {
      if (name === 'extra_block_update' && value) {
        const hasTimingChanges = Object.keys(value).some(f => TIMING_FIELDS.includes(f))
        const blockData = { ...value }
        if (!hasTimingChanges) blockData.skip_regeneration = true
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
  } catch (error) {
    console.error('Error flushing updates:', error)
  }
}

// --- Helpers ---
function toLocalInput(dt: Maybe<string>) {
  if (!dt) return ''
  return dt.replace(' ', 'T').slice(0, 16)
}
function fromLocalInput(val: string) {
  if (!val) return null
  return val.replace('T', ' ') + ':00'
}

// --- Actions ---
async function addCustom() {
  if (!props.planId) return
  const baseDate = props.eventDate ? new Date(props.eventDate) : new Date()
  const start = new Date(baseDate); start.setHours(6, 0, 0, 0)
  const end = new Date(baseDate); end.setHours(7, 0, 0, 0)
  const draft: ExtraBlock = {
    plan: props.planId!,
    first_program: 3,
    name: '',
    description: '',
    link: null,
    active: true,
    start: fromLocalInput(start.toISOString().slice(0, 16)),
    end: fromLocalInput(end.toISOString().slice(0, 16))
  }
  scheduleUpdate('extra_block_add', draft)
}

function confirmDeleteBlock(block: ExtraBlock) {
  blockToDelete.value = block
}
function cancelDeleteBlock() {
  blockToDelete.value = null
}
function deleteBlock() {
  if (!blockToDelete.value?.id) return
  scheduleUpdate('extra_block_delete', blockToDelete.value)
  blockToDelete.value = null
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
            <th class="text-center px-2 py-2 w-20">Programme</th>
            <th class="text-left px-2 py-2 w-48">Zeiten</th>
            <th class="text-left px-2 py-2">Inhalt</th>
            <th class="px-2 py-2 w-28">Aktion</th>
          </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
          <tr v-for="b in visibleCustomBlocks" :key="b.id ?? JSON.stringify(b)"
              class="border-b transition-all duration-200"
              :class="{
                'opacity-60 bg-gray-50': b.active === false,
                'hover:bg-gray-50': b.active !== false
              }">
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
                <input :value="toLocalInput(b.start)" class="w-full border rounded px-2 py-1 text-sm" type="datetime-local"
                       placeholder="Beginn" @change="b.start = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
                <input :value="toLocalInput(b.end)" class="w-full border rounded px-2 py-1 text-sm" type="datetime-local"
                       placeholder="Ende" @change="b.end = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
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

            <td class="px-2 py-2 text-right">
              <div class="flex flex-col items-end space-y-2">
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
      :countdown="countdownSeconds"
      :on-immediate-save="immediateFlush"
      message="Block-Änderungen werden gespeichert..."
    />
  </div>
</template>