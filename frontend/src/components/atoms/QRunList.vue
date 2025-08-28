<script setup>
import { ref, onMounted, watch, onBeforeUnmount } from 'vue'
import axios from 'axios'

const props = defineProps({
  reload: {
    type: Number,
    required: false,
    default: 0,
  },
})

const runs = ref([])
const loading = ref(true)
const error = ref(null)
let intervalId = null

const loadRuns = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/quality/runs')
    runs.value = response.data.runs.map(run => ({
      ...run,
      selection: JSON.parse(run.selection),
    }))

    // Automatischer Reload bei laufenden Runs
    if (response.data.has_running && !intervalId) {
      intervalId = setInterval(loadRuns, 30000) // alle 30s neu laden
    } else if (!response.data.has_running && intervalId) {
      clearInterval(intervalId)
      intervalId = null
    }
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
    <div v-if="loading" class="text-gray-500">Lade …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else-if="runs.length === 0" class="text-gray-400">Keine Runs gefunden.</div>
    <div v-else>
      <div
        v-for="run in runs"
        :key="run.id"
        class="grid grid-cols-4 gap-4 border rounded p-4 bg-gray-50 items-start"
      >
        <!-- Linke Spalte -->
        <div>
          <div class="font-bold text-lg">{{ run.name }}</div>
          <div class="text-sm text-gray-600 whitespace-pre-line">
            {{ run.comment || '—' }}
          </div>
        </div>

        <!-- Mittlere Spalte 1 -->
        <div class="text-sm text-gray-600 space-y-1">
          <div><strong>Teams:</strong> {{ run.selection.min_teams }}–{{ run.selection.max_teams }}</div>
          <div><strong>Runden:</strong> {{ run.selection.jury_rounds?.join(', ') || '–' }}</div>
        </div>

        <!-- Mittlere Spalte 2 -->
        <div class="text-sm text-gray-600 space-y-1">
          <div><strong>Spuren:</strong> {{ run.selection.jury_lanes?.join(', ') || '–' }}</div>
          <div><strong>Tische:</strong> {{ run.selection.tables?.join(', ') || '–' }}</div>
        </div>

        <!-- Rechte Spalte -->
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
            >
              {{ run.status }}
            </span>
          </div>
          <div>Start: {{ new Date(run.started_at).toLocaleString('de-DE') }}</div>
          <div v-if="run.finished_at">Ende: {{ new Date(run.finished_at).toLocaleString('de-DE') }}</div>
        </div>
      </div>
    </div>
  </div>
</template>