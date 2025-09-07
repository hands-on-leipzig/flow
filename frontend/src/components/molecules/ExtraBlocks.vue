<script lang="ts" setup>
import {computed, onMounted, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'
import LoaderFlow from '../atoms/LoaderFlow.vue'

type Maybe<T> = T | null | undefined

type ExtraBlock = {
  id?: number
  plan: number
  first_program: number | null | 0   // 2=Explore, 3=Challenge, null=None, 0=Both (convention; adjust in backend)
  name: string
  description: string
  link?: string | null

  // free (custom) flavor:
  start?: string | null          // 'YYYY-MM-DD HH:mm:ss' (server)
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
  (e: 'blockUpdate', updates: Array<{ name: string, value: any }>): void
}>()


// --- State ---
const loading = ref(false)
const saving = ref(false)
const blocks = ref<ExtraBlock[]>([])

// Debounced saving is now handled by parent component


// Cleanup on unmount
onUnmounted(() => {
  // Cleanup handled by parent component
})

// Only custom blocks (no insert_point)
const customBlocks = computed(() => blocks.value.filter(b => !('insert_point' in b) || !b.insert_point))

// Filter custom blocks based on toggle states
const visibleCustomBlocks = computed(() => {
  return customBlocks.value.filter(block => {
    // If both toggles are off, show all blocks (user might want to configure them)
    if (props.showExplore === false && props.showChallenge === false) return true
    
    // If explore is off, hide blocks that are explore-only or both
    if (props.showExplore === false) {
      if (block.first_program === 2 || block.first_program === 0) return false
    }
    
    // If challenge is off, hide blocks that are challenge-only or both
    if (props.showChallenge === false) {
      if (block.first_program === 3 || block.first_program === 0) return false
    }
    
    return true
  })
})

// --- Loaders ---
async function loadBlocks() {
  const pid = props.planId
  if (pid == null) return
  const {data} = await axios.get<ExtraBlock[]>(`/plans/${pid}/extra-blocks`)
  const rows = Array.isArray(data) ? data : []
  blocks.value.splice(0, blocks.value.length, ...rows)
}

onMounted(() => {
  if (props.planId != null) loadBlocks()
})

// Reload blocks when plan changes
watch(() => props.planId, (v) => {
  if (v != null) loadBlocks()
}, {immediate: true})

// Immediate save function (internal use)
async function saveBlockImmediate(block: ExtraBlock) {
  if (props.planId == null) return
  
  saving.value = true
  try {
    const planId = props.planId
    const {data: saved} = await axios.post<ExtraBlock>(`/plans/${planId}/extra-blocks`, block)

    if (saved?.id != null) {
      const i = blocks.value.findIndex(b => b.id === saved.id)
      if (i !== -1) blocks.value.splice(i, 1, saved)
      else blocks.value.push(saved)
    } else {
      blocks.value.push(saved)
    }
    emit('changed')
  } catch (error) {
    console.error('Error in saveBlockImmediate:', error)
  } finally {
    saving.value = false
  }
}

// Debounced save function (public interface)
function saveBlock(block: ExtraBlock) {
  console.log('ExtraBlocks: saveBlock called with:', block)
  // Emit block update to parent for debounced handling
  emit('blockUpdate', [{ name: 'extra_block_update', value: block }])
  console.log('ExtraBlocks: Emitted blockUpdate event')
}

// --- Helpers (datetime-local <-> MySQL-ish) ---
function toLocalInput(dt: Maybe<string>) {
  if (!dt) return ''
  // 'YYYY-MM-DD HH:mm:ss' -> 'YYYY-MM-DDTHH:mm'
  return dt.replace(' ', 'T').slice(0, 16)
}

function fromLocalInput(val: string) {
  if (!val) return null
  // 'YYYY-MM-DDTHH:mm' -> 'YYYY-MM-DD HH:mm:00'
  return val.replace('T', ' ') + ':00'
}

async function removeBlock(id: number) {
  saving.value = true
  try {
    await axios.delete(`/extra-blocks/${id}`)
    blocks.value = blocks.value.filter(b => b.id !== id)
    emit('changed')
  } finally {
    saving.value = false
  }
}

// --- Custom blocks (free rows) ---
async function addCustom() {
  if (!props.planId) return
  
  // Use event date if available, otherwise use current date
  const baseDate = props.eventDate ? new Date(props.eventDate) : new Date()
  
  // Set default time to 9:00 AM on the event date
  const startTime = new Date(baseDate)
  startTime.setHours(9, 0, 0, 0)
  
  // Set end time to 10:00 AM (1 hour later)
  const endTime = new Date(startTime)
  endTime.setHours(10, 0, 0, 0)

  const draft: ExtraBlock = {
    plan: props.planId!,
    first_program: 3,            // Challenge by default
    name: '',
    description: '',
    link: null,
    start: fromLocalInput(startTime.toISOString().slice(0, 16)),
    end: fromLocalInput(endTime.toISOString().slice(0, 16))
  }
  
  // Use immediate save for new blocks (they need to appear in the list)
  await saveBlockImmediate(draft)
}

function labelForProgram(v: number | null | 0) {
  if (v === 2) return 'Explore'
  if (v === 3) return 'Challenge'
  if (v === 0) return 'Beide'
  return '—'
}

function toggleProgram(block: ExtraBlock, program: 2 | 3) {
  if (program === 2) {
    // Toggle Explore
    if (block.first_program === 2) {
      // Currently Explore only -> turn off
      block.first_program = null
    } else if (block.first_program === 3) {
      // Currently Challenge only -> both
      block.first_program = 0
    } else if (block.first_program === 0) {
      // Currently both -> Challenge only
      block.first_program = 3
    } else {
      // Currently none -> Explore only
      block.first_program = 2
    }
  } else if (program === 3) {
    // Toggle Challenge
    if (block.first_program === 3) {
      // Currently Challenge only -> turn off
      block.first_program = null
    } else if (block.first_program === 2) {
      // Currently Explore only -> both
      block.first_program = 0
    } else if (block.first_program === 0) {
      // Currently both -> Explore only
      block.first_program = 2
    } else {
      // Currently none -> Challenge only
      block.first_program = 3
    }
  }
  
  saveBlock(block)
}
</script>

<template>
  <div class="space-y-8 relative">
    <!-- Pending saves indicator is now handled by parent component -->

    <!-- CUSTOM: blocks without insert_point -->
    <div class="bg-white shadow-sm rounded-xl border border-gray-200 relative">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <h3 class="text-sm font-semibold text-gray-700">Freie Zusatzblöcke - beeinflussen den Ablauf nicht</h3>
        <button class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm disabled:bg-gray-400 disabled:cursor-not-allowed"
                :disabled="saving || !planId"
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
          <tr v-for="b in visibleCustomBlocks" :key="b.id ?? JSON.stringify(b)" class="border-b">
            <td class="px-2 py-2 text-center">
              <div class="flex justify-center space-x-1">
                <!-- Explore Logo -->
                <img 
                  src="@/assets/FLL_Explore.png" 
                  alt="Explore" 
                  class="w-8 h-8 cursor-pointer transition-all duration-200 hover:scale-110"
                  :class="{
                    'opacity-100': b.first_program === 2 || b.first_program === 0,
                    'opacity-30 grayscale': !(b.first_program === 2 || b.first_program === 0)
                  }"
                  @click="toggleProgram(b, 2)"
                  title="Explore"
                />
                <!-- Challenge Logo -->
                <img 
                  src="@/assets/FLL_Challenge.png" 
                  alt="Challenge" 
                  class="w-8 h-8 cursor-pointer transition-all duration-200 hover:scale-110"
                  :class="{
                    'opacity-100': b.first_program === 3 || b.first_program === 0,
                    'opacity-30 grayscale': !(b.first_program === 3 || b.first_program === 0)
                  }"
                  @click="toggleProgram(b, 3)"
                  title="Challenge"
                />
              </div>
            </td>

            <td class="px-2 py-2">
              <div class="space-y-2">
                <!-- Start Time -->
                <input :value="toLocalInput(b.start)"
                       class="w-full border rounded px-2 py-1 text-sm"
                       type="datetime-local"
                       placeholder="Beginn"
                       @change="b.start = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
                <!-- End Time -->
                <input :value="toLocalInput(b.end)"
                       class="w-full border rounded px-2 py-1 text-sm"
                       type="datetime-local"
                       placeholder="Ende"
                       @change="b.end = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
              </div>
            </td>
            <td class="px-2 py-2">
              <div class="space-y-2">
                <!-- Title and Link in one row -->
                <div class="flex space-x-2">
                  <input v-model="b.name" 
                         class="flex-1 border rounded px-2 py-1 text-sm" 
                         type="text" 
                         placeholder="Titel"
                         @blur="saveBlock(b)"/>
                  <input v-model="b.link" 
                         class="flex-1 border rounded px-2 py-1 text-sm" 
                         type="url" 
                         placeholder="https://example.com"
                         @blur="saveBlock(b)"/>
                </div>
                <!-- Description below -->
                <input v-model="b.description" 
                       class="w-full border rounded px-2 py-1 text-sm" 
                       type="text" 
                       placeholder="Beschreibung"
                       @blur="saveBlock(b)"/>
              </div>
            </td>

            <td class="px-2 py-2 text-right">
              <button v-if="b.id"
                      class="bg-red-500 hover:bg-red-600 text-white text-sm font-medium px-3 py-1 rounded"
                      @click="removeBlock(b.id)">
                Löschen
              </button>
            </td>
          </tr>

          <tr v-if="!customBlocks.length">
            <td class="px-4 py-6 text-gray-500 text-center" colspan="8">
              Noch keine freien Zusatzblöcke. Füge oben welche hinzu.
            </td>
          </tr>
          </tbody>
        </table>
      </div>

      <!-- Loading overlay -->
      <div v-if="loading || saving" 
           class="absolute inset-0 bg-gray-500 bg-opacity-50 flex items-center justify-center z-10">
        <LoaderFlow />
      </div>
    </div>
  </div>
</template>
