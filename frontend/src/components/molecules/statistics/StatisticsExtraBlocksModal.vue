<template>
  <div class="glass-modal p-6 w-[90vw] max-w-4xl max-h-[90vh] overflow-auto">
    <h3 class="text-lg font-bold mb-4">
      Extra-Blöcke für Plan {{ planId }}
      <template v-if="eventName && eventDate">
        - Event "{{ eventName }}" ({{ eventDate }})
      </template>
    </h3>
    
    <div v-if="loading" class="text-[var(--color-text-subtle)] py-4">
      Lade Extra-Blöcke...
    </div>
    
    <div v-else-if="freeBlocks.length === 0 && insertedBlocks.length === 0" class="text-[var(--color-text-subtle)] py-4">
      Keine aktiven Extra-Blöcke gefunden.
    </div>
    
    <div v-else class="space-y-6">
      <!-- Free Blocks Table -->
      <div v-if="freeBlocks.length > 0">
        <h4 class="text-md font-semibold mb-2">Freie Blöcke</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-[var(--color-bg-muted)] text-left">
              <tr>
                <th class="px-3 py-2 border border-[var(--color-border)]">Datum</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Start</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Ende</th>
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
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.name ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      
      <!-- Inserted Blocks Table -->
      <div v-if="insertedBlocks.length > 0">
        <h4 class="text-md font-semibold mb-2">Eingefügte Blöcke</h4>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-[var(--color-bg-muted)] text-left">
              <tr>
                <th class="px-3 py-2 border border-[var(--color-border)]">Einfügepunkt</th>
                <th class="px-3 py-2 border border-[var(--color-border)]">Titel</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="block in insertedBlocks"
                :key="block.id"
                class="hover:bg-[var(--color-bg-hover)]"
              >
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.insert_point_name ?? '–' }}</td>
                <td class="px-3 py-2 border border-[var(--color-border)]">{{ block.name ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
    
    <div class="flex justify-end gap-2 mt-6">
      <button class="px-4 py-2 text-[var(--color-text-muted)] hover:text-black" @click="$emit('close')">Schließen</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps<{
  planId: number
}>()

const emit = defineEmits<{
  (e: 'close'): void
}>()

const freeBlocks = ref<Array<{
  id: number
  name: string
  date: string | null
  start: string | null
  end: string | null
}>>([])
const insertedBlocks = ref<Array<{
  id: number
  name: string
  insert_point_name: string | null
}>>([])
const eventName = ref<string | null>(null)
const eventDate = ref<string | null>(null)
const loading = ref(false)

async function loadExtraBlocks() {
  if (!props.planId) return
  
  loading.value = true
  freeBlocks.value = []
  insertedBlocks.value = []
  eventName.value = null
  eventDate.value = null
  
  try {
    const response = await axios.get(`/stats/extra-blocks/${props.planId}`)
    freeBlocks.value = response.data.free_blocks || []
    insertedBlocks.value = response.data.inserted_blocks || []
    eventName.value = response.data.event_name || null
    eventDate.value = response.data.event_date || null
  } catch (err) {
    console.error('Error loading extra blocks:', err)
    alert('Fehler beim Laden der Extra-Blöcke')
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
