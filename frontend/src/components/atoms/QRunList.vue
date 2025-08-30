<script setup>
import { ref, onMounted, watch, onBeforeUnmount } from 'vue'
import QPlanList from './QPlanList.vue'
import axios from 'axios'

const props = defineProps({
  reload: { type: Number, required: false, default: 0 },
})

const qruns = ref([])
const loading = ref(true)
const error = ref(null)
const expandedQRunId = ref(null)
let intervalId = null

const toggleExpanded = (id) => {
  expandedQRunId.value = expandedQRunId.value === id ? null : id
}

const loadQRuns = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get('/quality/qruns')
    qruns.value = response.data.qruns.map(qrun => {
      let selection = {}
      try {
        selection = qrun.selection ? JSON.parse(qrun.selection) : {}
      } catch (e) {
        console.warn(`Ungültiges JSON in selection für qrun ${qrun.id}`)
      }
      return {
        ...qrun,
        selection,
      }
    })
      } catch (err) {
    console.error('Fehler beim Laden der QRuns', err)
    error.value = 'Fehler beim Laden der Liste'
  } finally {
    loading.value = false
  }
}

onMounted(loadQRuns)
watch(() => props.reload, loadQRuns)
onBeforeUnmount(() => {
  if (intervalId) clearInterval(intervalId)
})
</script>

<template>
  <div class="space-y-2 mt-4">
    <div v-if="loading" class="text-gray-500">Lade QRuns …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else-if="qruns.length === 0" class="text-gray-400">Keine QRuns gefunden.</div>
    <div v-else>
      <div
        v-for="qrun in qruns"
        :key="qrun.id"
        class="border rounded bg-gray-50 overflow-hidden"
      >
        <div
          class="flex p-4 items-start hover:bg-gray-100 cursor-pointer"
          @click="toggleExpanded(qrun.id)"
        >
          <!-- Spalte 1: Name + Kommentar -->
          <div class="basis-[35%] flex-shrink-0">
            <div class="font-bold text-lg"> {{ qrun.id }} {{ qrun.name }}</div>
            <div class="text-sm text-gray-600 whitespace-pre-line">{{ qrun.comment || '—' }}</div>
          </div>

          <!-- Spalte 2: Teams + Runden -->
          <div class="basis-[20%] flex-shrink-0 text-sm text-gray-600 space-y-1">
            <div><strong>Teams:</strong> {{ qrun.selection.min_teams ?? '?' }}–{{ qrun.selection.max_teams ?? '?' }}</div>
            <div><strong>Runden:</strong> {{ qrun.selection.jury_rounds?.join(', ') ?? '?' }}</div>
          </div>

          <!-- Spalte 3: Spuren + Tische -->
          <div class="basis-[20%] flex-shrink-0 text-sm text-gray-600 space-y-1">
            <div><strong>Spuren:</strong> {{ qrun.selection.jury_lanes?.join(', ') ?? '?' }}</div>
            <div><strong>Tische:</strong> {{ qrun.selection.tables?.join(', ') ?? '?' }}</div>
          </div>

          <!-- Spalte 4: QPlans + Status + Start/Ende -->
          <div class="basis-[20%] flex-shrink-0 text-right text-sm space-y-1">
            <div class="flex justify-end items-center gap-2">
              <div>QPlans: {{ qrun.qplans_calculated }} / {{ qrun.qplans_total }}</div>
              <span
                class="inline-block rounded px-2 py-0.5 text-white text-xs"
                :class="{
                  'bg-gray-400': qrun.status === 'pending',
                  'bg-yellow-500': qrun.status === 'running',
                  'bg-green-600': qrun.status === 'done',
                }"
              >
                {{ qrun.status }}
              </span>
            </div>
            <div>Start: {{ new Date(qrun.started_at).toLocaleString('de-DE') }}</div>
            <div v-if="qrun.finished_at">Ende: {{ new Date(qrun.finished_at).toLocaleString('de-DE') }}</div>
          </div>

          <!-- kein @click.stop hier -->
          <div class="basis-[5%] flex-shrink-0 flex flex-col gap-2 items-end ml-4">
            <button
              @click.stop="handleRerun(qrun.id)"
              class="..."
            >
              ↩️
            </button>
            <button
              @click.stop="handleDelete(qrun.id)"
              class="..."
            >
              🗑️
            </button>
          </div>

        </div>

        <div v-if="expandedQRunId === qrun.id" class="border-t border-gray-200">
           <div class="bg-white px-4 py-2">
            <QPlanList :qrun="qrun.id" />
          </div>
        </div>

      </div>
    </div>
  </div>
</template>