<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import EventQualityTable from '@/components/molecules/EventQualityTable.vue'
import { showGlassToast } from '@/composables/useGlassToast'

const events = ref([])
const seasons = ref([])
const selectedSeasonId = ref(null)
const loading = ref(true)
const running = ref(false)
const runningEventId = ref(null)
const progress = ref(null)
const programErrors = ref({})

const stalePrograms = computed(() => {
  const items = []
  for (const event of events.value) {
    if (event.status !== 'evaluable' || !event.plan_id) continue
    for (const program of event.programs || []) {
      if (program.stale) {
        items.push({ event, program })
      }
    }
  }
  return items
})

const canRun = computed(() => !running.value && stalePrograms.value.length > 0)

const runButtonLabel = computed(() => {
  if (running.value) return 'Prüfe …'
  if (stalePrograms.value.length === 0) return 'Alles aktuell'
  return `Qualität prüfen (${stalePrograms.value.length})`
})

async function loadSeasons() {
  const [seasonsRes, currentRes] = await Promise.all([
    axios.get('/seasons'),
    axios.get('/current-season'),
  ])
  seasons.value = seasonsRes.data
  selectedSeasonId.value = currentRes.data.id
}

async function loadEvents() {
  if (!selectedSeasonId.value) return
  loading.value = true
  programErrors.value = {}
  try {
    const { data } = await axios.get('/admin/plan-quality/events', {
      params: { season: selectedSeasonId.value },
    })
    events.value = data.events
  } catch (err) {
    console.error('Fehler beim Laden der Events', err)
    showGlassToast('Events konnten nicht geladen werden.', 'error')
  } finally {
    loading.value = false
  }
}

function patchProgram(eventId, firstProgram, qPlan) {
  const event = events.value.find((e) => e.event_id === eventId)
  if (!event) return
  const program = event.programs?.find((p) => p.first_program === firstProgram)
  if (!program) return
  program.q_plan = qPlan
  program.stale = false
  program.stale_reason = null
}

function rowKey(eventId, firstProgram) {
  return `${eventId}_${firstProgram}`
}

async function evaluatePrograms(items) {
  if (items.length === 0) return

  running.value = true
  const total = items.length
  let done = 0

  for (const { event, program } of items) {
    done += 1
    runningEventId.value = event.event_id
    progress.value = {
      current: done,
      total,
      eventName: event.event_name,
      programLabel: program.label,
    }

    try {
      const { data } = await axios.post(`/admin/plan-quality/evaluate/${event.plan_id}`, {
        first_program: program.first_program,
      })
      patchProgram(event.event_id, program.first_program, data)
    } catch (err) {
      console.error('Evaluation fehlgeschlagen', err)
      const key = rowKey(event.event_id, program.first_program)
      programErrors.value = {
        ...programErrors.value,
        [key]: 'Fehler',
      }
      showGlassToast(
        `${event.event_name} (${program.label}): Prüfung fehlgeschlagen.`,
        'error',
      )
    }
  }

  running.value = false
  runningEventId.value = null
  progress.value = null
}

async function runQualityCheck() {
  if (!canRun.value) return

  const toRun = [...stalePrograms.value]
  programErrors.value = {}
  await evaluatePrograms(toRun)

  if (stalePrograms.value.length === 0) {
    showGlassToast('Qualitätsprüfung abgeschlossen.', 'success')
  }
}

async function runEventQuality(event) {
  if (running.value || event.status !== 'evaluable' || !event.plan_id) return

  const toRun = (event.programs || [])
    .filter((p) => p.stale)
    .map((program) => ({ event, program }))

  if (toRun.length === 0) return

  await evaluatePrograms(toRun)

  if ((event.programs || []).every((p) => !p.stale)) {
    showGlassToast(`${event.event_name}: Qualitätsprüfung abgeschlossen.`, 'success')
  }
}

watch(selectedSeasonId, () => {
  void loadEvents()
})

onMounted(async () => {
  await loadSeasons()
  await loadEvents()
})
</script>

<template>
  <div class="space-y-4">
    <div v-if="!loading" class="flex flex-wrap items-center gap-3">
      <label class="flex items-center gap-2 text-sm text-[var(--color-text-muted)]">
        <span>Saison</span>
        <select
          v-model="selectedSeasonId"
          class="glass-input !py-1.5 !text-sm"
          :disabled="running"
        >
          <option v-for="s in seasons" :key="s.id" :value="s.id">
            {{ s.name }} ({{ s.year }})
          </option>
        </select>
      </label>

      <button
        type="button"
        class="glass-btn-accent !px-4 !py-2 !text-sm inline-flex items-center gap-2"
        :disabled="!canRun"
        @click="runQualityCheck"
      >
        <i class="bi bi-clipboard-check" aria-hidden="true" />
        {{ runButtonLabel }}
      </button>

      <span v-if="progress" class="text-sm text-[var(--color-text-muted)]">
        {{ progress.current }}/{{ progress.total }} · {{ progress.eventName }} · {{ progress.programLabel }}
      </span>
    </div>

    <div v-if="loading" class="text-[var(--color-text-subtle)] text-sm">
      Lade Events …
    </div>

    <EventQualityTable
      v-else
      :events="events"
      :program-errors="programErrors"
      :running="running"
      :running-event-id="runningEventId"
      @run-event="runEventQuality"
    />
  </div>
</template>
