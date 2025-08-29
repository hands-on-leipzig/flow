<script setup>
import { ref, onMounted, watch, onBeforeUnmount } from 'vue'
import axios from 'axios'
import QPlanList from './QPlanList.vue'

const props = defineProps({
  reload: { type: Number, required: false, default: 0 },
})

const runs = ref([])
const loading = ref(true)
const error = ref(null)
const expandedRunId = ref(null)
let intervalId = null

const toggleExpanded = (id) => {
  expandedRunId.value = expandedRunId.value === id ? null : id
}

const loadRuns = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/quality/runs')
    runs.value = response.data.runs.map(run => ({
      ...run,
      selection: JSON.parse(run.selection),
    }))

  } catch (err) {
    console.error('Fehler beim Laden der Runs', err)
    error.value = 'Fehler beim Laden der Liste'
  } finally {
    loading.value = false
  }
}

onMounted(loadRuns)
watch(() => props.reload, loadRuns)
onBeforeUnmount(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<template>
  <div class="space-y-2 mt-4">
    <div v-if="loading" class="text-gray-500">Lade Runs …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else-if="runs.length === 0" class="text-gray-400">Keine Runs gefunden.</div>
    <div v-else>
      <div
        v-for="run in runs"
        :key="run.id"
        class="border rounded bg-gray-50 overflow-hidden"
      >
        <div
          class="grid grid-cols-4 gap-4 p-4 items-start cursor-pointer hover:bg-gray-100"
          @click="toggleExpanded(run.id)"
        >
          <div>
            <div class="font-bold text-lg">{{ run.name }}</div>
            <div class="text-sm text-gray-600 whitespace-pre-line">{{ run.comment || '—' }}</div>
          </div>
          <div class="text-sm text-gray-600 space-y-1">
            <div><strong>Teams:</strong> {{ run.selection.min_teams }}–{{ run.selection.max_teams }}</div>
            <div><strong>Runden:</strong> {{ run.selection.jury_rounds?.join(', ') || '–' }}</div>
          </div>
          <div class="text-sm text-gray-600 space-y-1">
            <div><strong>Spuren:</strong> {{ run.selection.jury_lanes?.join(', ') || '–' }}</div>
            <div><strong>Tische:</strong> {{ run.selection.tables?.join(', ') || '–' }}</div>
          </div>
          <div class="text-right text-sm space-y-1">
            <div class="flex justify-end items-center gap-2">
              <div>QPlans: {{ run.qplans_calculated }} / {{ run.qplans_total }}</div>
              <span
                class="inline-block rounded px-2 py-0.5 text-white text-xs"
                :class="{
                  'bg-gray-400': run.status === 'pending',
                  'bg-yellow-500': run.status === 'running',
                  'bg-green-600': run.status === 'done',
                }"
              >{{ run.status }}</span>
            </div>
            <div>Start: {{ new Date(run.started_at).toLocaleString('de-DE') }}</div>
            <div v-if="run.finished_at">Ende: {{ new Date(run.finished_at).toLocaleString('de-DE') }}</div>
          </div>
        </div>

        <div v-if="expandedRunId === run.id" class="border-t border-gray-200 bg-white px-4 py-2">
          <QPlanList :run-id="run.id" />
        </div>
      </div>
    </div>
  </div>
</template>


