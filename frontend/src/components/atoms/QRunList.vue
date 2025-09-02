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

async function handleDelete(qrunId) {
  if (!confirm(`QRun ${qrunId} wirklich löschen?`)) return

  try {
    await axios.delete(`/quality/delete/${qrunId}`)
    await loadQRuns()
  } catch (err) {
    console.error('Fehler beim Löschen des QRuns:', err)
    alert('Löschen fehlgeschlagen.')
  }
}



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
          <!-- Spalte 1: Name -->
          <div class="basis-[20%] flex-shrink-0">
            <div class="font-bold text-lg"> {{ qrun.id }} {{ qrun.name }}</div>
            <div class="text-xs text-gray-400 italic"> {{ qrun.host || 'unknown' }} </div>
          </div>

          <!-- Spalte 2: Kommentar -->
          <div class="basis-[55%] flex-shrink-0">
            <div class="text-sm text-gray-600 whitespace-pre-line">{{ qrun.comment || '—' }}</div>
          </div>

          <!-- Spalte 3: QPlans + Status + Start/Ende -->
          <div class="basis-[15%] flex-shrink-0 text-left text-sm space-y-1">
            <div class="flex justify-start items-center gap-2">
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

            <div>
              Dauer:
              <template v-if="qrun.finished_at">
                {{
                  Math.round(
                    (new Date(qrun.finished_at) - new Date(qrun.started_at)) / 60000
                  )
                }} Minuten
              </template>
              <template v-else>... läuft noch ...</template>
            </div>
          </div>

          <!-- kein @click.stop hier -->
        <div class="basis-[10%] flex-shrink-0 flex items-center justify-center ml-4">
          <button @click.stop="handleDelete(qrun.id)">🗑️</button>
        </div>

        </div>

        <div v-if="expandedQRunId === qrun.id" class="border-t border-gray-200">
           <div class="bg-white px-4 py-2">
            <QPlanList
              :qrun="qrun.id"
              @refreshParent="loadQRuns"
            />
          </div>
        </div>

      </div>
    </div>
  </div>
</template>