<script lang="ts" setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import ToggleSwitch from "@/components/atoms/ToggleSwitch.vue";
import InfoPopover from "@/components/atoms/InfoPopover.vue";

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
}>()

const emit = defineEmits<{
  (e: 'changed'): void
}>()

// --- State ---
const loading = ref(false)
const saving = ref(false)
const insertPoints = ref<InsertPoint[]>([])
const blocks = ref<ExtraBlock[]>([])

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
const fixedBlocks = computed(() => blocks.value.filter(b => !!b.insert_point))

// For quick lookup: insert_point -> block
const fixedByPoint = computed<Record<number, ExtraBlock>>(() => {
  const map: Record<number, ExtraBlock> = {}
  for (const b of fixedBlocks.value) {
    if (b.insert_point) map[b.insert_point] = b
  }
  return map
})

function isPointEnabled(pointId: number) {
  return !!fixedByPoint.value[pointId]
}

async function togglePoint(point: InsertPoint, enabled: boolean) {
  if (props.planId == null) return // guard
  const existing = fixedByPoint.value[point.id]
  if (enabled && !existing) {
    const draft: ExtraBlock = {
      plan: props.planId,
      first_program: point.first_program ?? null,
      insert_point: point.id,
      name: point.ui_label,
      description: '',
      link: null,
      buffer_before: 5,
      duration: 5,
      buffer_after: 5
    }
    await saveBlockImmediate(draft)
  } else if (!enabled && existing?.id) {
    await removeBlock(existing.id)
  }
}

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

function updateFixed(pointId: number, patch: Partial<ExtraBlock>, save = false) {
  const b = fixedByPoint.value[pointId]
  if (!b) return
  Object.assign(b, patch)
  if (save) saveBlockImmediate(b)
}

function onFixedNumInput(pointId: number, field: 'buffer_before' | 'duration' | 'buffer_after', e: Event) {
  const v = Number((e.target as HTMLInputElement).value)
  updateFixed(pointId, {[field]: Number.isFinite(v) ? v : 0} as any)
}

function onFixedTextInput(pointId: number, field: 'name' | 'description' | 'link', e: Event) {
  updateFixed(pointId, {[field]: (e.target as HTMLInputElement).value} as any)
}

function onFixedBlur(pointId: number) {
  const b = fixedByPoint.value[pointId]
  if (b) saveBlockImmediate(b)
}

// Expose loadAll function to parent if needed
defineExpose({
  loadAll
})
</script>

<template>
  <div class="bg-white shadow-sm rounded-xl border border-gray-200">
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-100">
      <h3 class="text-sm font-semibold text-gray-700">Mit festen Einfügepunkten</h3>
    </div>

    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <thead>
        <tr class="text-gray-500 text-xs uppercase tracking-wide">
          <th class="text-left px-4 py-2 w-64">Zeitpunkt</th>
          <th class="text-center px-2 py-2 w-20">Davor</th>
          <th class="text-center px-2 py-2 w-20">Dauer</th>
          <th class="text-center px-2 py-2 w-20">Nach</th>
          <th class="text-left px-2 py-2">Titel</th>
          <th class="text-left px-2 py-2">Beschreibung</th>
          <th class="text-left px-2 py-2 w-64">Link</th>
        </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
        <tr v-for="p in insertPoints" :key="p.id" class="border-b">
          <td class="px-4 py-2">
            <label class="inline-flex items-center space-x-3">
              <ToggleSwitch
                  :model-value="isPointEnabled(p.id)"
                  @update:modelValue="togglePoint(p, $event)"
              />

              <span>
                <span class="font-medium">{{ p.ui_label }}</span>
                  <InfoPopover v-if="p.ui_description" :text="p.ui_description"/>
              </span>
            </label>
          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!fixedByPoint[p.id]" :value="fixedByPoint[p.id]?.buffer_before ?? ''"
                   class="w-16 border rounded px-2 py-1 text-center"
                   min="0"
                   type="number"
                   @change="onFixedBlur(p.id)"
                   @input="onFixedNumInput(p.id, 'buffer_before', $event)"
            />

          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!fixedByPoint[p.id]" :value="fixedByPoint[p.id]?.duration ?? ''"
                   class="w-16 border rounded px-2 py-1 text-center"
                   min="0"
                   type="number"
                   @change="onFixedBlur(p.id)"
                   @input="onFixedNumInput(p.id, 'duration', $event)"
            />
          </td>

          <td class="px-2 py-2 text-center">
            <input :disabled="!fixedByPoint[p.id]" :value="fixedByPoint[p.id]?.buffer_after ?? ''"
                   class="w-16 border rounded px-2 py-1 text-center"
                   min="0"
                   type="number"
                   @change="onFixedBlur(p.id)"
                   @input="onFixedNumInput(p.id, 'buffer_after', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!fixedByPoint[p.id]"
                   :value="fixedByPoint[p.id]?.name ?? ''"
                   class="w-full border rounded px-2 py-1"
                   type="text"
                   @blur="onFixedBlur(p.id)"
                   @input="onFixedTextInput(p.id, 'name', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!fixedByPoint[p.id]"
                   :value="fixedByPoint[p.id]?.description ?? ''"
                   class="w-full border rounded px-2 py-1"
                   type="text"
                   @blur="onFixedBlur(p.id)"
                   @input="onFixedTextInput(p.id, 'description', $event)"
            />
          </td>

          <td class="px-2 py-2">
            <input :disabled="!fixedByPoint[p.id]"
                   :value="fixedByPoint[p.id]?.link ?? ''"
                   class="w-full border rounded px-2 py-1"
                   type="text"
                   @blur="onFixedBlur(p.id)"
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
