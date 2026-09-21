<template>
  <div class="glass-modal stats-plan-modal p-6 max-h-[90vh] overflow-y-auto overflow-x-hidden">
    <h3 class="text-lg font-bold mb-4">
      Extra-Blöcke für Plan {{ planId }}
    </h3>

    <div v-if="loading" class="text-[var(--color-text-subtle)] py-4">
      Lade Extra-Blöcke…
    </div>

    <div v-else-if="!hasBlocks" class="text-[var(--color-text-subtle)] py-4">
      Keine aktiven Extra-Blöcke gefunden.
    </div>

    <div v-else class="space-y-6">
      <div v-if="freeBlocks.length > 0">
        <h4 class="text-md font-semibold mb-2">Freie Blöcke</h4>
        <table class="w-full text-sm border-collapse table-fixed">
          <thead class="bg-[var(--color-bg-muted)] text-left">
            <tr>
              <th class="px-3 py-2 border border-[var(--color-border)] w-[7rem]">Datum</th>
              <th class="px-3 py-2 border border-[var(--color-border)] w-[5rem]">Start</th>
              <th class="px-3 py-2 border border-[var(--color-border)] w-[5rem]">Ende</th>
              <th class="px-3 py-2 border border-[var(--color-border)]">Titel</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="block in freeBlocks"
              :key="block.id"
              class="hover:bg-[var(--color-bg-hover)]"
            >
              <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.date ?? '–' }}</td>
              <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.start ?? '–' }}</td>
              <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.end ?? '–' }}</td>
              <td class="px-3 py-2 border border-[var(--color-border)] break-words">{{ block.name ?? '–' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="slotBlocks.length > 0">
        <h4 class="text-md font-semibold mb-2">Slot-Blöcke</h4>
        <table class="w-full text-sm border-collapse table-fixed">
          <thead class="bg-[var(--color-bg-muted)] text-left">
            <tr>
              <th class="px-3 py-2 border border-[var(--color-border)] w-[8rem]">Dauer</th>
              <th class="px-3 py-2 border border-[var(--color-border)]">Titel</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="block in slotBlocks"
              :key="block.id"
              class="hover:bg-[var(--color-bg-hover)]"
            >
              <td class="px-3 py-2 border border-[var(--color-border)]">{{ durationLabel(block.duration) }}</td>
              <td class="px-3 py-2 border border-[var(--color-border)] break-words">{{ block.name ?? '–' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {showGlassToast} from '@/composables/useGlassToast'

type FreeBlockRow = {
  id: number
  name: string
  date: string | null
  start: string | null
  end: string | null
}

type SlotBlockRow = {
  id: number
  name: string
  duration: number | null
}

const props = defineProps<{
  planId: number
}>()

defineEmits<{
  (e: 'close'): void
}>()

const freeBlocks = ref<FreeBlockRow[]>([])
const slotBlocks = ref<SlotBlockRow[]>([])
const loading = ref(false)

function durationLabel(minutes: number | null): string {
  if (minutes == null) return '–'
  return `${minutes} Min`
}

const hasBlocks = computed(() => freeBlocks.value.length > 0 || slotBlocks.value.length > 0)

async function loadExtraBlocks() {
  if (!props.planId) return

  loading.value = true
  freeBlocks.value = []
  slotBlocks.value = []

  try {
    const response = await axios.get(`/stats/extra-blocks/${props.planId}`)
    freeBlocks.value = response.data.free_blocks || []
    slotBlocks.value = response.data.slot_blocks || []
  } catch (err) {
    console.error('Error loading extra blocks:', err)
    showGlassToast('Fehler beim Laden der Extra-Blöcke', 'error')
  } finally {
    loading.value = false
  }
}

watch(() => props.planId, () => {
  if (props.planId) {
    loadExtraBlocks()
  }
}, { immediate: true })
</script>
