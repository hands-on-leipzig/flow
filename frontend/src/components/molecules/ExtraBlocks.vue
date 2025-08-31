<script lang="ts" setup>
import {computed, onMounted, onUnmounted, ref, watch} from 'vue'
import axios from 'axios'

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
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

// Expose flush function to parent if needed
defineExpose({
  flushPendingSaves
})

// --- State ---
const loading = ref(false)
const saving = ref(false)
const blocks = ref<ExtraBlock[]>([])

// Debounced save system
const pendingSaves = ref<Map<string, ExtraBlock>>(new Map())
const saveTimeoutId = ref<NodeJS.Timeout | null>(null)
const DEBOUNCE_DELAY = 5000 // 5 seconds

// Track if there are pending saves
const hasPendingSaves = computed(() => pendingSaves.value.size > 0)

function scheduleSave(block: ExtraBlock) {
  // Use a unique key for each block
  const key = block.id ? `id-${block.id}` : `temp-${JSON.stringify(block)}`

  // Add/update the pending save
  pendingSaves.value.set(key, {...block})

  // Clear existing timeout
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value)
  }

  // Schedule new save
  saveTimeoutId.value = setTimeout(() => {
    // Get all pending saves
    const saves = Array.from(pendingSaves.value.values())

    // Clear pending saves
    pendingSaves.value.clear()
    saveTimeoutId.value = null

    // Save all blocks
    saves.forEach(block => saveBlockImmediate(block))
  }, DEBOUNCE_DELAY)
}

// Force immediate save of all pending changes
function flushPendingSaves() {
  if (saveTimeoutId.value) {
    clearTimeout(saveTimeoutId.value)
    saveTimeoutId.value = null
  }

  if (pendingSaves.value.size > 0) {
    const saves = Array.from(pendingSaves.value.values())
    pendingSaves.value.clear()

    saves.forEach(block => saveBlockImmediate(block))
  }
}

// Cleanup on unmount
onUnmounted(() => {
  flushPendingSaves()
})

// Only custom blocks (no insert_point)
const customBlocks = computed(() => blocks.value.filter(b => !('insert_point' in b) || !b.insert_point))

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
    // FIX: destructure `data`, not `res`
    const {data: saved} = await axios.post<ExtraBlock>(`/plans/${planId}/extra-blocks`, block)

    if (saved?.id != null) {
      const i = blocks.value.findIndex(b => b.id === saved.id)
      if (i !== -1) blocks.value.splice(i, 1, saved)
      else blocks.value.push(saved)
    } else {
      blocks.value.push(saved)
    }
    emit('changed')
  } finally {
    saving.value = false
  }
}

// Debounced save function (public interface)
function saveBlock(block: ExtraBlock) {
  scheduleSave(block)
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
  const now = new Date()
  const oneHour = new Date(now.getTime() + 60 * 60 * 1000)

  const draft: ExtraBlock = {
    plan: props.planId!,
    first_program: null,         // none by default; user can flip to 2/3/0
    name: '',
    description: '',
    link: null,
    start: fromLocalInput(now.toISOString().slice(0, 16)),
    end: fromLocalInput(oneHour.toISOString().slice(0, 16))
  }
  await saveBlockImmediate(draft)
}

function labelForProgram(v: number | null | 0) {
  if (v === 2) return 'Explore'
  if (v === 3) return 'Challenge'
  if (v === 0) return 'Beide'
  return '—'
}
</script>

<template>
  <div class="space-y-8">
    <!-- Pending saves indicator -->
    <div v-if="hasPendingSaves"
         class="flex items-center gap-2 text-orange-600 text-sm bg-orange-50 border border-orange-200 rounded-lg px-4 py-2">
      <div class="w-3 h-3 bg-orange-400 rounded-full animate-pulse"></div>
      <span>Änderungen werden in Kürze gespeichert...</span>
    </div>

    <!-- CUSTOM: blocks without insert_point -->
    <div class="bg-white shadow-sm rounded-xl border border-gray-200">
      <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
        <h3 class="text-sm font-semibold text-gray-700">Freie Zusatzblöcke - beeinflussen den Ablauf nicht</h3>
        <button class="bg-green-500 hover:bg-green-600 text-white text-xs font-medium px-3 py-1.5 rounded-md shadow-sm"
                @click="addCustom">
          + Block hinzufügen
        </button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full text-sm">
          <thead>
          <tr class="text-gray-500 text-xs uppercase tracking-wide">
            <th class="text-center px-2 py-2 w-24">Explore</th>
            <th class="text-center px-2 py-2 w-24">Challenge</th>
            <th class="text-left px-2 py-2 w-64">Beginn</th>
            <th class="text-left px-2 py-2 w-64">Ende</th>
            <th class="text-left px-2 py-2">Titel</th>
            <th class="text-left px-2 py-2">Beschreibung</th>
            <th class="text-left px-2 py-2 w-64">Link</th>
            <th class="px-2 py-2 w-28">Aktion</th>
          </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
          <tr v-for="b in customBlocks" :key="b.id ?? JSON.stringify(b)" class="border-b">
            <td class="px-2 py-2 text-center">
              <input :checked="b.first_program === 2 || b.first_program === 0"
                     class="h-5 w-5 rounded"
                     type="checkbox"
                     @change="b.first_program = ($event.target as HTMLInputElement).checked
                               ? (b.first_program === 3 ? 0 : 2)
                               : (b.first_program === 0 ? 3 : null);
                               saveBlock(b)"/>

            </td>
            <td class="px-2 py-2 text-center">
              <input :checked="b.first_program === 3 || b.first_program === 0"
                     class="h-5 w-5 rounded"
                     type="checkbox"
                     @change="b.first_program = ($event.target as HTMLInputElement).checked
                               ? (b.first_program === 2 ? 0 : 3)
                               : (b.first_program === 0 ? 2 : null);
                               saveBlock(b)"/>
            </td>

            <td class="px-2 py-2">
              <input :value="toLocalInput(b.start)"
                     class="w-56 border rounded px-2 py-1"
                     type="datetime-local"
                     @change="b.start = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
            </td>
            <td class="px-2 py-2">
              <input :value="toLocalInput(b.end)"
                     class="w-56 border rounded px-2 py-1"
                     type="datetime-local"
                     @change="b.end = fromLocalInput(($event.target as HTMLInputElement).value); saveBlock(b)"/>
            </td>

            <td class="px-2 py-2">
              <input v-model="b.name" class="w-full border rounded px-2 py-1" type="text" @blur="saveBlock(b)"/>
            </td>
            <td class="px-2 py-2">
              <input v-model="b.description" class="w-full border rounded px-2 py-1" type="text" @blur="saveBlock(b)"/>
            </td>
            <td class="px-2 py-2">
              <input v-model="b.link" class="w-full border rounded px-2 py-1" type="text" @blur="saveBlock(b)"/>
            </td>

            <td class="px-2 py-2 text-right">
              <div class="text-xs text-gray-500 mb-1">{{ labelForProgram(b.first_program) }}</div>
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
    </div>

    <div v-if="loading || saving" class="text-sm text-gray-500">Speichere / lade…</div>
  </div>
</template>
