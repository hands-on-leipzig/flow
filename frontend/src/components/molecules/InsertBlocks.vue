<script lang="ts" setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import ToggleSwitch from "@/components/atoms/ToggleSwitch.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";
import { programLogoSrc, programLogoAlt } from '@/utils/images'
// Note: Block changes trigger debounce in Schedule.vue, blocks are saved to DB when countdown triggers  

type InsertPoint = {
  id: number
  first_program: number // 2 = Explore, 3 = Challenge (from your usage elsewhere)
  level: number
  sequence: number
  ui_label: string
  ui_description?: string | null
}

type ExtraBlock = {
  id?: number
  plan: number
  first_program: number | null | 0   // 2=Explore, 3=Challenge, null=None, 0=Both (convention; adjust in backend)
  name: string
  description: string
  link?: string | null
  active?: boolean

  // fixed (insert-point) flavor:
  insert_point?: number | null
  buffer_before?: number | null
  duration?: number | null
  buffer_after?: number | null

  // free (custom) flavor:
  start?: string | null          // 'YYYY-MM-DD HH:mm:ss' (server)
  end?: string | null
  room?: number | null
}

const props = defineProps<{
  planId: number | null
  eventLevel?: number | null
  onUpdate?: (updates: Array<{name: string, value: any, triggerGenerator?: boolean}>) => void
  showExplore?: boolean
  showChallenge?: boolean
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

// --- State ---
const loading = ref(false)
const saving = ref(false)
const insertPoints = ref<InsertPoint[]>([])
const blocks = ref<ExtraBlock[]>([])

// --- Batch save system (save on countdown) ---
// Note: Block changes trigger debounce immediately, blocks are saved to DB when countdown reaches 0 or is clicked

// Field classification
const TIMING_FIELDS = ['buffer_before', 'duration', 'buffer_after', 'insert_point', 'first_program']
const TOGGLE_FIELDS = ['active']
const TEXT_FIELDS = ['name', 'description', 'link']

function isTimingField(field: string): boolean {
  return TIMING_FIELDS.includes(field)
}

function isToggleField(field: string): boolean {
  return TOGGLE_FIELDS.includes(field)
}

function needsGenerator(field: string): boolean {
  // All fields trigger generator (toggle, timing, and text fields)
  return isTimingField(field) || isToggleField(field) || TEXT_FIELDS.includes(field)
}

// Update local state and trigger debounce (no DB save until countdown)
function handleFieldChange(blockId: number, field: string, value: any) {
  if (!props.planId) return
  
  const block = blocks.value.find(b => b.id === blockId)
  if (!block) {
    console.warn(`Block ${blockId} not found`)
    return
  }
  
  // Update local state immediately (for UI responsiveness)
  ;(block as any)[field] = value
  
  // Trigger debounce immediately (DB save will happen when countdown reaches 0)
  if (props.onUpdate) {
    props.onUpdate([{ 
      name: `block_${blockId}_${field}`, 
      value: value,
      triggerGenerator: true 
    }])
  }
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
        insert_point: block.insert_point,
        buffer_before: block.buffer_before,
        duration: block.duration,
        buffer_after: block.buffer_after,
        active: block.active
      }
      
      // Only include id if block already exists in DB
      if (block.id) {
        blockData.id = block.id
      }
      
      const response = await axios.post(`/plans/${props.planId}/extra-blocks`, blockData)
      const saved = response.data?.block || response.data
      
      if (saved?.id) {
        const index = blocks.value.findIndex(b => 
          (b.id && b.id === saved.id) || 
          (!b.id && b.insert_point === saved.insert_point && b.plan === saved.plan)
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
    throw error // Re-throw so Schedule can handle it
  } finally {
    saving.value = false
  }
}

// --- Loaders ---
async function loadInsertPoints() {
  const level = props.eventLevel
  if (level == null) return
  try {
    const {data} = await axios.get<InsertPoint[]>('/insert-points', {
      params: {level: Number(level)}
    })
    const rows = Array.isArray(data) ? data : []
    rows.sort((a, b) =>
        (a.sequence ?? 0) - (b.sequence ?? 0) ||
        (a.first_program ?? 0) - (b.first_program ?? 0)
    )
    insertPoints.value.splice(0, insertPoints.value.length, ...rows)
  } catch (err) {
    console.error('Failed to load insert points', err)
    insertPoints.value.splice(0)
  }
}

async function loadBlocks() {
  const pid = props.planId
  if (pid == null) return
  const {data} = await axios.get<ExtraBlock[]>(`/plans/${pid}/extra-blocks`)
  const rows = Array.isArray(data) ? data : []
  blocks.value.splice(0, blocks.value.length, ...rows)
}

async function loadAll() {
  loading.value = true
  try {
    await Promise.all([loadInsertPoints(), loadBlocks()])
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  if (props.planId != null) loadBlocks()
  if (props.eventLevel != null) loadInsertPoints()
})

// Reload blocks when plan changes
watch(() => props.planId, (v) => {
  if (v != null) loadBlocks()
}, {immediate: true})

// 🔑 Reload insert points when level becomes available/changes
watch(() => props.eventLevel, (lvl) => {
  if (lvl != null) loadInsertPoints()
}, {immediate: true})

// --- Fixed blocks (insert_point) ---
// Note: We now load all blocks and filter by active state in the UI

// Filter insert points based on toggle states
const visibleInsertPoints = computed(() => {
  return insertPoints.value.filter(point => {
    if (point.first_program === 2 && props.showExplore === false) return false // Explore disabled
    if (point.first_program === 3 && props.showChallenge === false) return false // Challenge disabled
    return true
  })
})

// For quick lookup: insert_point -> block (includes all blocks, active and inactive)
const fixedByPoint = computed<Record<number, ExtraBlock>>(() => {
  const map: Record<number, ExtraBlock> = {}
  for (const b of blocks.value) {
    if (b.insert_point) map[b.insert_point] = b
  }
  return map
})

function isPointEnabled(pointId: number) {
  const block = blocks.value.find(b => b.insert_point === pointId)
  return block ? (block.active !== false) : false
}

function isBlockEditable(pointId: number) {
  const block = fixedByPoint.value[pointId]
  return block && block.active !== false
}

async function togglePoint(point: InsertPoint, enabled: boolean) {
  if (props.planId == null) return // guard
  
  const existing = blocks.value.find(b => b.insert_point === point.id && b.plan === props.planId)
  const activeValue = enabled ? 1 : 0

  if (existing?.id) {
    // Toggle active/inactive - update local state, trigger debounce
    handleFieldChange(existing.id, 'active', activeValue)
  } else if (enabled) {
    // Create new block - add to local state, trigger debounce
    // Note: Block will be saved to DB when countdown triggers
    const draft: ExtraBlock = {
      plan: props.planId,
      first_program: point.first_program ?? null,
      insert_point: point.id,
      name: point.ui_label, // Default title from insert point
      description: '',
      link: null,
      buffer_before: 5, // Default values for time fields
      duration: 5,
      buffer_after: 5,
      active: true
    }
    
    // Add to local state (no DB save yet)
    blocks.value.push(draft)
    
    // Trigger debounce (save will happen when countdown triggers)
    if (props.onUpdate) {
      // Use a temporary ID for the trigger - will be resolved when saved
      props.onUpdate([{ 
        name: `block_new_${point.id}`, 
        value: draft,
        triggerGenerator: true 
      }])
    }
  } else {
    // Disabling a new block (that doesn't exist yet) - nothing to do
  }
}

function updateFixed(pointId: number, patch: Partial<ExtraBlock>) {
  const b = fixedByPoint.value[pointId]
  if (!b) return
  Object.assign(b, patch)
}

function onFixedNumInput(pointId: number, field: 'buffer_before' | 'duration' | 'buffer_after', e: Event) {
  const v = Number((e.target as HTMLInputElement).value)
  const value = Number.isFinite(v) ? v : 0
  updateFixed(pointId, {[field]: value} as any)
  
  // Value updated locally, will be saved on blur
}

function onFixedNumBlur(pointId: number, field: 'buffer_before' | 'duration' | 'buffer_after', e: Event) {
  const value = Number((e.target as HTMLInputElement).value)
  const finalValue = Number.isFinite(value) ? value : 0
  
  const block = fixedByPoint.value[pointId]
  if (block?.id) {
    handleFieldChange(block.id, field, finalValue)
  }
}

function onFixedTextInput(pointId: number, field: 'name' | 'description' | 'link', e: Event) {
  const value = (e.target as HTMLInputElement).value
  updateFixed(pointId, {[field]: value} as any)
  
  // Value updated locally, will be saved on blur
}

function onFixedTextBlur(pointId: number, field: 'name' | 'description' | 'link', e: Event) {
  const value = (e.target as HTMLInputElement).value
  
  const block = fixedByPoint.value[pointId]
  if (block?.id) {
    handleFieldChange(block.id, field, value)
  }
}

// Cleanup handled by composable (it will flush on unmount)

// Expose functions to parent
defineExpose({
  loadAll,
  saveAllEnabledBlocks
})
</script>

<template>
  <div class="bg-white shadow-sm rounded-xl border border-gray-200">
    <div class="flex items-center gap-2 mb-2">
          <img
          :src="programLogoSrc('C')"
          :alt="programLogoAlt('C')"
          class="w-10 h-10 flex-shrink-0"
        />
      <h3 class="text-lg font-semibold capitalize">
        <span class="italic">FIRST</span> LEGO League Challenge
      </h3>
    </div>
    
    
    <div class="px-4 py-3 border-b border-gray-100">
      <h3 class="text-sm font-semibold text-gray-700">
        Die Blöcke werden <em>nach</em> dem angegebenen Zeitpunkt eingeschoben.
      </h3>
      <p class="text-xs text-gray-500 mt-1">
        Diese Blöcke verändern direkt die Zeiten im Robot-Game. Die Jury-Runden werden davon nur indirekt beeinflusst. 
      </p>
    </div>
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
        <tr class="text-gray-500 text-xs uppercase tracking-wide">
          <th class="text-left px-4 py-2 w-64">Zeitpunkt</th>
          <th class="text-center px-2 py-2 w-20">Davor</th>
          <th class="text-center px-2 py-2 w-20">Dauer</th>
          <th class="text-center px-2 py-2 w-20">Danach</th>
          <th class="text-left px-2 py-2">Titel</th>
          <th class="text-left px-2 py-2">Beschreibung</th>
          <th class="text-left px-2 py-2 w-64">Link</th>
        </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <tr v-for="p in visibleInsertPoints" :key="p.id" class="border-b">
          <td class="px-4 py-2">
            <label class="inline-flex items-center space-x-3">
              <ToggleSwitch
                  :model-value="isPointEnabled(p.id)"
                  @update:modelValue="togglePoint(p, $event)"
              />

              <span class="text-gray-900">
                <span class="font-medium">{{ p.ui_label }}</span>
                  <InfoPopover v-if="p.ui_description" :text="p.ui_description"/>
              </span>
            </label>
          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!isBlockEditable(p.id)" :value="fixedByPoint[p.id]?.buffer_before ?? ''"
                   :class="['w-16 border rounded px-2 py-1 text-center', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   min="5"
                   step="5"
                   type="number"
                   @blur="onFixedNumBlur(p.id, 'buffer_before', $event)"
                   @input="onFixedNumInput(p.id, 'buffer_before', $event)"
            />

          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!isBlockEditable(p.id)" :value="fixedByPoint[p.id]?.duration ?? ''"
                   :class="['w-16 border rounded px-2 py-1 text-center', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   min="5"
                   step="5"
                   type="number"
                   @blur="onFixedNumBlur(p.id, 'duration', $event)"
                   @input="onFixedNumInput(p.id, 'duration', $event)"
            />
          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!isBlockEditable(p.id)" :value="fixedByPoint[p.id]?.buffer_after ?? ''"
                   :class="['w-16 border rounded px-2 py-1 text-center', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   min="5"
                   step="5"
                   type="number"
                   @blur="onFixedNumBlur(p.id, 'buffer_after', $event)"
                   @input="onFixedNumInput(p.id, 'buffer_after', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!isBlockEditable(p.id)"
                   :value="fixedByPoint[p.id]?.name ?? ''"
                   :class="['w-full border rounded px-2 py-1', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   type="text"
                   @blur="onFixedTextBlur(p.id, 'name', $event)"
                   @input="onFixedTextInput(p.id, 'name', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!isBlockEditable(p.id)"
                   :value="fixedByPoint[p.id]?.description ?? ''"
                   :class="['w-full border rounded px-2 py-1', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   type="text"
                   @blur="onFixedTextBlur(p.id, 'description', $event)"
                   @input="onFixedTextInput(p.id, 'description', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!isBlockEditable(p.id)"
                   :value="fixedByPoint[p.id]?.link ?? ''"
                   :class="['w-full border rounded px-2 py-1', 
                            isBlockEditable(p.id) 
                              ? 'bg-white border-gray-300' 
                              : 'bg-gray-100 border-gray-200 text-gray-400 cursor-not-allowed']"
                   type="text"
                   @blur="onFixedTextBlur(p.id, 'link', $event)"
                   @input="onFixedTextInput(p.id, 'link', $event)"
            />
          </td>
        </tr>
        <tr v-if="!insertPoints.length">
          <td class="px-4 py-6 text-gray-500 text-center" colspan="7">Keine Einfügepunkte für dieses Level.</td>
        </tr>
        </tbody>
      </table>
    </div>

    <div v-if="loading || saving" class="text-sm text-gray-500 px-4 py-2">Speichere / lade…</div>
  </div>
</template>
