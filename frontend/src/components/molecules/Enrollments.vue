<script setup>
import {computed, onMounted, ref} from 'vue'
import axios from 'axios'
import {formatDateOnly} from '@/utils/dateTimeFormat'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {programDisplayName, programId} from '@/utils/eventPrograms'
import {useProgramsStore} from '@/stores/programs'
import {showGlassToast} from '@/composables/useGlassToast'
import {useGoToEventSchedule} from '@/composables/useGoToEventSchedule'

defineOptions({name: 'Enrollments'})

const {goToEventSchedule} = useGoToEventSchedule()
const programsStore = useProgramsStore()

const HISTOGRAM_SERIES = [
  {key: 'explore', eventsKey: 'explore_events', name: 'EXPLORE'},
  {key: 'challenge', eventsKey: 'challenge_events', name: 'CHALLENGE'},
  {key: 'future8', eventsKey: 'future8_events', name: 'FUTURE_8'},
]

const loading = ref(true)
const seasonName = ref('')
const eventCount = ref(0)
const histogram = ref([])
const dual = ref([])
const futureStandalone = ref([])

function cell(value) {
  return value > 0 ? String(value) : ''
}

function namesTitle(names) {
  if (!Array.isArray(names) || names.length === 0) return undefined
  return names.join('\n')
}

function enrolledLabel(row) {
  if (!row?.draht_id) return '—'
  return `${row.enrolled} / ${row.capacity}`
}

function overCapacity(row) {
  return !!row?.draht_id && row.capacity > 0 && row.enrolled > row.capacity
}

const histogramSeries = computed(() => {
  const visible = HISTOGRAM_SERIES.filter((series) =>
    histogram.value.some((row) => Number(row[series.key] ?? 0) > 0),
  )
  return visible.slice().sort((a, b) => {
    const rowA = programsStore.catalog.find((program) => String(program.name || '').toUpperCase() === a.name)
    const rowB = programsStore.catalog.find((program) => String(program.name || '').toUpperCase() === b.name)
    const seqA = rowA?.sequence ?? Number.POSITIVE_INFINITY
    const seqB = rowB?.sequence ?? Number.POSITIVE_INFINITY
    if (seqA !== seqB) return seqA - seqB
    return programId(rowA ?? {}) - programId(rowB ?? {})
  })
})

const exploreLabel = () => programDisplayName('EXPLORE')
const challengeLabel = () => programDisplayName('CHALLENGE')
const futureLabel = () => programDisplayName('FUTURE_8')

async function load() {
  loading.value = true
  try {
    const {data} = await axios.get('/admin/enrollments', {timeout: 0})
    seasonName.value = data.season_name || ''
    eventCount.value = data.event_count ?? 0
    histogram.value = Array.isArray(data.histogram) ? data.histogram : []
    dual.value = Array.isArray(data.dual) ? data.dual : []
    futureStandalone.value = Array.isArray(data.future_standalone) ? data.future_standalone : []
  } catch (error) {
    showGlassToast(
      'Anmeldungen konnten nicht geladen werden: ' + (error.response?.data?.message || error.message),
      'error',
    )
    histogram.value = []
    dual.value = []
    futureStandalone.value = []
  } finally {
    loading.value = false
  }
}

function openEvent(row) {
  void goToEventSchedule(row.event_id, row.regional_partner_id)
}

onMounted(async () => {
  await programsStore.ensureLoaded()
  void load()
})
</script>

<template>
  <div class="space-y-4">
    <div>
      <h2 class="text-xl font-bold mb-1">Anmeldungen</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Live aus DRAHT
        <span v-if="seasonName"> · {{ seasonName }}</span>
        <span v-if="!loading"> · {{ eventCount }} Events</span>
      </p>
    </div>

    <p v-if="loading" class="text-sm text-[var(--color-text-subtle)]">
      Lade Anmeldungen aus DRAHT… das kann etwas dauern.
    </p>

    <div v-else class="enrollments-grid">
      <div class="glass-card liquid-surface-inner overflow-auto">
        <h3 class="glass-card__title">Teams je Event</h3>
        <p class="text-xs text-[var(--color-text-muted)] mb-3">
          Anzahl Events mit genau so vielen angemeldeten Teams. Nur Programme mit DRAHT-ID.
        </p>
        <table class="table-auto w-full text-sm border-collapse glass-list">
          <thead>
            <tr class="text-xs text-[var(--color-text-muted)] uppercase tracking-wider">
              <th class="text-left font-semibold py-1.5 pr-3">Teams</th>
              <th
                v-for="series in histogramSeries"
                :key="series.key"
                class="text-right font-semibold py-1.5 px-2"
              >
                <img
                  :src="programLogoSrc(series.name)"
                  :alt="programLogoAlt(series.name)"
                  :title="programDisplayName(series.name)"
                  class="inline-block h-6 w-6 object-contain"
                />
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in histogram"
              :key="String(row.teams)"
              class="border-t border-[var(--color-border)]"
              :class="row.teams === '26+' ? 'font-medium' : ''"
            >
              <td class="py-1 pr-3 tabular-nums text-[var(--color-text-muted)]">{{ row.teams }}</td>
              <td
                v-for="series in histogramSeries"
                :key="`${row.teams}-${series.key}`"
                class="py-1 px-2 text-right tabular-nums"
                :class="row[series.key] ? 'cursor-help' : ''"
                :title="namesTitle(row[series.eventsKey])"
              >{{ cell(row[series.key]) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="enrollments-stack">
        <div class="glass-card liquid-surface-inner overflow-auto">
          <h3 class="glass-card__title">{{ challengeLabel() }} + {{ futureLabel() }}</h3>
          <p class="text-xs text-[var(--color-text-muted)] mb-3">
            Events mit beiden Programmen. Angemeldet / DRAHT-Kapazität.
          </p>
          <p v-if="dual.length === 0" class="text-sm text-[var(--color-text-subtle)]">
            Keine Events mit {{ challengeLabel() }} und {{ futureLabel() }}.
          </p>
          <table v-else class="table-auto w-full text-sm border-collapse glass-list">
            <thead>
              <tr class="text-xs text-[var(--color-text-muted)] uppercase tracking-wider">
                <th class="text-left font-semibold py-1.5 pr-3">Event</th>
                <th class="text-left font-semibold py-1.5 px-2">Datum</th>
                <th class="text-right font-semibold py-1.5 px-2">
                  <img
                    :src="programLogoSrc('CHALLENGE')"
                    :alt="programLogoAlt('CHALLENGE')"
                    :title="challengeLabel()"
                    class="inline-block h-6 w-6 object-contain"
                  />
                </th>
                <th class="text-right font-semibold py-1.5 pl-2">
                  <img
                    :src="programLogoSrc('FUTURE_8')"
                    :alt="programLogoAlt('FUTURE_8')"
                    :title="futureLabel()"
                    class="inline-block h-6 w-6 object-contain"
                  />
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in dual"
                :key="row.event_id"
                class="border-t border-[var(--color-border)] glass-table-row--hover"
              >
                <td class="py-1.5 pr-3">
                  <button
                    type="button"
                    class="text-left hover:text-[var(--color-accent)]"
                    @click="openEvent(row)"
                  >
                    {{ row.event_name }}
                  </button>
                </td>
                <td class="py-1.5 px-2 whitespace-nowrap text-[var(--color-text-muted)]">
                  {{ formatDateOnly(row.event_date) }}
                </td>
                <td
                  class="py-1.5 px-2 text-right tabular-nums whitespace-nowrap"
                  :class="overCapacity(row.challenge) ? 'text-red-700' : ''"
                >
                  {{ enrolledLabel(row.challenge) }}
                </td>
                <td
                  class="py-1.5 pl-2 text-right tabular-nums whitespace-nowrap"
                  :class="overCapacity(row.future8) ? 'text-red-700' : ''"
                >
                  {{ enrolledLabel(row.future8) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="glass-card liquid-surface-inner overflow-auto">
          <h3 class="glass-card__title">{{ futureLabel() }} allein</h3>
          <p class="text-xs text-[var(--color-text-muted)] mb-3">
            Events mit {{ futureLabel() }}, ohne {{ challengeLabel() }}. Angemeldet / DRAHT-Kapazität.
          </p>
          <p v-if="futureStandalone.length === 0" class="text-sm text-[var(--color-text-subtle)]">
            Keine eigenständigen {{ futureLabel() }}-Events.
          </p>
          <table v-else class="table-auto w-full text-sm border-collapse glass-list">
            <thead>
              <tr class="text-xs text-[var(--color-text-muted)] uppercase tracking-wider">
                <th class="text-left font-semibold py-1.5 pr-3">Event</th>
                <th class="text-left font-semibold py-1.5 px-2">Datum</th>
                <th class="text-right font-semibold py-1.5 pl-2">
                  <img
                    :src="programLogoSrc('FUTURE_8')"
                    :alt="programLogoAlt('FUTURE_8')"
                    :title="futureLabel()"
                    class="inline-block h-6 w-6 object-contain"
                  />
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in futureStandalone"
                :key="row.event_id"
                class="border-t border-[var(--color-border)] glass-table-row--hover"
              >
                <td class="py-1.5 pr-3">
                  <button
                    type="button"
                    class="text-left hover:text-[var(--color-accent)]"
                    @click="openEvent(row)"
                  >
                    {{ row.event_name }}
                  </button>
                </td>
                <td class="py-1.5 px-2 whitespace-nowrap text-[var(--color-text-muted)]">
                  {{ formatDateOnly(row.event_date) }}
                </td>
                <td
                  class="py-1.5 pl-2 text-right tabular-nums whitespace-nowrap"
                  :class="overCapacity(row.future8) ? 'text-red-700' : ''"
                >
                  {{ enrolledLabel(row.future8) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.enrollments-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
  align-items: start;
}

.enrollments-stack {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

@media (min-width: 64rem) {
  .enrollments-grid {
    grid-template-columns: minmax(16rem, 22rem) minmax(0, 1fr);
  }
}
</style>
