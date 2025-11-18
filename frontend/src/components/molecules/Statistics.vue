<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

import { formatDateOnly, formatDateTime } from '@/utils/dateTimeFormat'
import { programLogoSrc, programLogoAlt } from '@/utils/images'  

import { useRouter } from 'vue-router'
import { useEventStore } from '@/stores/event'

type FlattenedRow = {
  partner_id: number | null
  partner_name: string | null
  event_id: number | null
  event_name: string | null
  event_date: string | null
  event_link: string | null
  event_explore: number | null
  event_challenge: number | null
  event_teams_explore: number
  event_teams_challenge: number
  plan_id: number | null
  plan_created: string | null
  plan_last_change: string | null
  generator_stats: number | null
  expert_param_changes?: number
  extra_blocks?: number
  publication_level?: number | null
  publication_date?: string | null
  publication_last_change?: string | null
}

const data = ref<any>(null)
const totals = ref<any>(null)
const loading = ref(true)
const error = ref<string | null>(null)
const selectedSeasonKey = ref<string | null>(null)

const router = useRouter()
const eventStore = useEventStore()

async function selectEvent(eventId, regionalPartnerId) {
  await axios.post('/user/select-event', {
    event: eventId,
    regional_partner: regionalPartnerId
  })
  await eventStore.fetchSelectedEvent()
  router.push('/event')
}

onMounted(async () => {
  try {
    const [plansRes, totalsRes] = await Promise.all([
      axios.get('/stats/plans'),
      axios.get('/stats/totals'),
    ])
    data.value = plansRes.data
    totals.value = totalsRes.data

    if (data.value?.seasons?.length > 0) {
      // Default: preselect the most recent season
      const last = data.value.seasons[data.value.seasons.length - 1]
      selectedSeasonKey.value = `${last.season_year}-${last.season_name}`
    }
  } catch (e) {
    error.value = 'Fehler beim Laden der Statistiken.'
    console.error(e)
  } finally {
    loading.value = false
  }
})

// Map for quick access to totals per "year-name"
const totalsByKey = computed(() => {
  const map = new Map()
  if (!totals.value?.seasons) return map
  for (const s of totals.value.seasons) {
    map.set(`${s.season_year}-${s.season_name}`, s.totals ?? null)
  }
  return map
})

// Replaces the previous seasonTotals definition
const seasonTotals = computed(() => {
  const ZERO = {
    rp_total: 0,
    rp_with_events: 0,
    events_total: 0,
    events_with_plan: 0,
    plans_total: 0,
    activity_groups_total: 0,
    activities_total: 0,
  }
  if (!totals.value?.seasons || !selectedSeasonKey.value) return ZERO
  const s = totals.value.seasons.find(
    t => `${t.season_year}-${t.season_name}` === selectedSeasonKey.value
  )
  if (!s) return ZERO
  return {
    rp_total: s.rp?.total ?? 0,
    rp_with_events: s.rp?.with_events ?? 0,
    events_total: s.events?.total ?? 0,
    events_with_plan: s.events?.with_plan ?? 0,   // uses the new field
    plans_total: s.plans?.total ?? 0,
    activity_groups_total: s.activity_groups?.total ?? 0,
    activities_total: s.activities?.total ?? 0,
  }
})

const orphans = computed(() => ({
  events: totals.value?.global_orphans?.events?.orphans ?? 0,
  plans: totals.value?.global_orphans?.plans?.orphans ?? 0,
  ags: totals.value?.global_orphans?.activity_groups?.orphans ?? 0,
  acts: totals.value?.global_orphans?.activities?.orphans ?? 0,
}))

type CleanupTarget = 'events' | 'plans' | 'activity-groups' | 'activities'
type ModalMode = 'plan-delete' | 'cleanup' | 'expert-parameters'

const cleanupMeta: Record<
  CleanupTarget,
  { title: string; description: string; confirmLabel: string; orphanKey: 'events' | 'plans' | 'ags' | 'acts' }
> = {
  events: {
    title: 'Events bereinigen?',
    description: 'Alle Events ohne gültigen Regionalpartner werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'events',
  },
  plans: {
    title: 'Pläne bereinigen?',
    description: 'Alle Pläne ohne gültiges Event werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'plans',
  },
  'activity-groups': {
    title: 'Activity Groups bereinigen?',
    description: 'Alle Activity Groups ohne gültigen Plan werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'ags',
  },
  activities: {
    title: 'Activities bereinigen?',
    description: 'Alle Activities ohne gültige Activity Group werden dauerhaft gelöscht.',
    confirmLabel: 'Bereinigen',
    orphanKey: 'acts',
  },
}

const modalState = ref<{
  visible: boolean
  mode: ModalMode | null
  planId: number | null
  cleanupType: CleanupTarget | null
}>({
  visible: false,
  mode: null,
  planId: null,
  cleanupType: null,
})

const expertParameters = ref<Array<{
  name: string
  ui_label: string | null
  set_value: string | null
  default_value: string | null
  sequence: number
}>>([])
const loadingExpertParams = ref(false)

const modalConfirmLabel = computed(() => {
  if (modalState.value.mode === 'cleanup' && modalState.value.cleanupType) {
    return cleanupMeta[modalState.value.cleanupType].confirmLabel
  }
  return 'Löschen'
})

const badgeClass = (n) =>
  n > 0
    ? 'bg-red-100 text-red-800 border border-red-300'
    : 'bg-gray-100 text-gray-700 border border-gray-300'

const publicationTotals = computed(() => ({
  total: totals.value?.publication_totals?.total ?? 0,
  level_1: totals.value?.publication_totals?.level_1 ?? 0,
  level_2: totals.value?.publication_totals?.level_2 ?? 0,
  level_3: totals.value?.publication_totals?.level_3 ?? 0,
  level_4: totals.value?.publication_totals?.level_4 ?? 0,
}))

const flattenedRows = computed<FlattenedRow[]>(() => {
  const season = data.value?.seasons.find(
    s => `${s.season_year}-${s.season_name}` === selectedSeasonKey.value
  )
  if (!season) return []

  const rows: FlattenedRow[] = []

  for (const partner of season.partners) {
    if (!partner.events || partner.events.length === 0) {
      rows.push({
        partner_id: partner.partner_id,
        partner_name: partner.partner_name,
        event_id: null,
        event_name: null,
        event_date: null,
        event_link: null,
        event_explore: null,
        event_challenge: null,
        event_teams_explore: 0,
        event_teams_challenge: 0,
        plan_id: null,
        plan_created: null,
        plan_last_change: null,
        generator_stats: null,
      })
      continue
    }

    for (const event of partner.events) {
      const teamsExplore = Number(event.teams_explore ?? 0)
      const teamsChallenge = Number(event.teams_challenge ?? 0)
      if (!event.plans || event.plans.length === 0) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          event_link: event.event_link ?? null,
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
          event_teams_explore: teamsExplore,
          event_teams_challenge: teamsChallenge,
          plan_id: null,
          plan_created: null,
          plan_last_change: null,
          generator_stats: null,
        })
        continue
      }

      for (const plan of event.plans) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          event_link: event.event_link ?? null,
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
          event_teams_explore: teamsExplore,
          event_teams_challenge: teamsChallenge,
          plan_id: plan.plan_id,
          plan_created: plan.plan_created,
          plan_last_change: plan.plan_last_change,
          generator_stats: plan.generator_stats ?? null,
          expert_param_changes: plan.expert_param_changes ?? 0,
          extra_blocks: plan.extra_blocks ?? 0,
          publication_level: plan.publication_level ?? null,
          publication_date: plan.publication_date ?? null,
          publication_last_change: plan.publication_last_change ?? null,
        })
      }
    }
  }

  return rows
})

function shouldShowPartner(index) {
  if (index === 0) return true
  return flattenedRows.value[index].partner_id !== flattenedRows.value[index - 1].partner_id
}

function shouldShowEvent(index) {
  if (index === 0) return true
  const current = flattenedRows.value[index]
  const previous = flattenedRows.value[index - 1]
  return (
    current.partner_id !== previous.partner_id ||
    current.event_id !== previous.event_id
  )
}

const getPlanCount = (eventId) => {
  return flattenedRows.value.filter(r => r.event_id === eventId && r.plan_id !== null).length
}

function openPreview(planId) {
  window.open(`/preview/${planId}`, '_blank', 'noopener')
}

function formatNumber(num) {
  if (num === null || num === undefined) return '0'
  return Number(num).toLocaleString('de-DE')
}

function getHoursSince(timestamp: string | null): number | null {
  if (!timestamp) return null
  const date = new Date(timestamp)
  if (isNaN(date.getTime())) return null
  const now = new Date()
  const diffMs = now.getTime() - date.getTime()
  return Math.floor(diffMs / (1000 * 60 * 60))
}

function getLastChangeClass(timestamp: string | null): string {
  const hours = getHoursSince(timestamp)
  if (hours === null) return ''
  
  if (hours <= 24) {
    return 'bg-blue-600 text-white' // Darkest blue - last 24 hours
  } else if (hours <= 72) {
    return 'bg-blue-400 text-white' // Medium blue - last 72 hours
  } else if (hours <= 168) {
    return 'bg-blue-200 text-gray-800' // Lightest blue - last 7 days
  }
  return '' // No highlight for older changes
}


function openPlanDelete(planId: number) {
  modalState.value = {
    visible: true,
    mode: 'plan-delete',
    planId,
    cleanupType: null,
  }
}

function askCleanup(target: CleanupTarget) {
  const meta = cleanupMeta[target]
  const count = (orphans.value as Record<string, number>)[meta.orphanKey] ?? 0
  if (count === 0) return

  modalState.value = {
    visible: true,
    mode: 'cleanup',
    planId: null,
    cleanupType: target,
  }
}

async function openExpertParameters(planId: number) {
  modalState.value = {
    visible: true,
    mode: 'expert-parameters',
    planId,
    cleanupType: null,
  }
  
  loadingExpertParams.value = true
  expertParameters.value = []
  
  try {
    const response = await axios.get(`/plans/${planId}/expert-parameters`)
    expertParameters.value = response.data
  } catch (err) {
    console.error('Error loading expert parameters:', err)
    alert('Fehler beim Laden der Expert-Parameter')
  } finally {
    loadingExpertParams.value = false
  }
}

function closeModal() {
  modalState.value = {
    visible: false,
    mode: null,
    planId: null,
    cleanupType: null,
  }
  expertParameters.value = []
}

async function reloadStats() {
  const [plansRes, totalsRes] = await Promise.all([
    axios.get('/stats/plans'),
    axios.get('/stats/totals'),
  ])
  data.value = plansRes.data
  totals.value = totalsRes.data
}

async function confirmModal() {
  if (!modalState.value.mode) return

  try {
    if (modalState.value.mode === 'plan-delete' && modalState.value.planId) {
      await axios.delete(`/plans/${modalState.value.planId}`)
    } else if (modalState.value.mode === 'cleanup' && modalState.value.cleanupType) {
      await axios.delete(`/stats/orphans/${modalState.value.cleanupType}/cleanup`)
    } else {
      return
    }
    await reloadStats()
  } catch (e) {
    if (modalState.value.mode === 'plan-delete') {
      console.error('Fehler beim Löschen des Plans:', e)
    } else {
      console.error('Fehler bei der Orphan-Bereinigung:', e)
    }
  } finally {
    closeModal()
  }
}


</script>

<template>
  <div>
    <div v-if="loading" class="text-gray-500">Lade Daten …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else>
      <!-- Global orphans -->
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <button
          type="button"
          :disabled="orphans.events === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.events),
            orphans.events > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('events')"
        >
          Events (ohne/ungültiger RP): {{ orphans.events }}
        </button>
        <button
          type="button"
          :disabled="orphans.plans === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.plans),
            orphans.plans > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('plans')"
        >
          Pläne (ohne/ungültiges Event): {{ orphans.plans }}
        </button>
        <button
          type="button"
          :disabled="orphans.ags === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.ags),
            orphans.ags > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('activity-groups')"
        >
          ActGroups (ohne/ungültiger Plan): {{ orphans.ags }}
        </button>
        <button
          type="button"
          :disabled="orphans.acts === 0"
          :class="[
            'px-3 py-1 rounded-full text-sm font-semibold transition',
            badgeClass(orphans.acts),
            orphans.acts > 0 ? 'cursor-pointer hover:ring-2 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500' : 'opacity-70 cursor-not-allowed',
          ]"
          @click="askCleanup('activities')"
        >
          Activities (ohne/ungültiger ActGroup): {{ orphans.acts }}
        </button>
      </div>
        <!-- Season filter -->
        <div class="mb-6">
          <div class="flex flex-wrap gap-4">
            <label
              v-for="season in data.seasons"
              :key="`${season.season_year}-${season.season_name}`"
              class="cursor-pointer"
            >
              <input
                type="radio"
                :value="`${season.season_year}-${season.season_name}`"
                v-model="selectedSeasonKey"
                class="mr-1"
              />
              {{ season.season_year }} – {{ season.season_name }}
            </label>
          </div>
        </div>

        <!-- Season totals (3 boxes) -->
        <div class="mb-6 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Box 1: regional partners -->
          <div class="bg-white border rounded shadow-sm p-4 space-y-1">
            <div class="flex justify-between text-gray-700">
              <span>Regionalpartner</span>
              <span class="font-semibold">{{ seasonTotals.rp_total }}</span>
            </div>
            <div class="flex justify-between text-gray-700">
              <span>mit Event</span>
              <span class="font-semibold">{{ seasonTotals.rp_with_events }}</span>
            </div>
          </div>

          <!-- Box 2: events -->
          <div class="bg-white border rounded shadow-sm p-4 space-y-1">
            <div class="flex justify-between text-gray-700">
              <span>Events</span>
              <span class="font-semibold">{{ seasonTotals.events_total }}</span>
            </div>
            <div class="flex justify-between text-gray-700">
              <span>mit Plan</span>
              <span class="font-semibold">{{ seasonTotals.events_with_plan }}</span>
            </div>
          </div>

        <!-- Box 3: plans & activities -->
        <div class="bg-white border rounded shadow-sm p-4 space-y-1">
          <div class="flex justify-between text-gray-700">
            <span>Pläne</span>
            <span class="font-semibold">{{ formatNumber(seasonTotals.plans_total) }}</span>
          </div>
          <div class="flex justify-between text-gray-700">
            <span>Activity Groups | Activities</span>
            <span class="font-semibold">
              {{ formatNumber(seasonTotals.activity_groups_total) }} | {{ formatNumber(seasonTotals.activities_total) }}
            </span>
          </div>
        </div>

        <!-- Box 4: Publications -->
        <div class="bg-white border rounded shadow-sm p-4 space-y-1">
          <div class="flex justify-between text-gray-700">
            <span>Veröffentlichte Pläne</span>
            <span class="font-semibold">{{ formatNumber(publicationTotals.total) }}</span>
          </div>
          <div class="flex justify-between text-gray-700">
            <span>Level 1 | 2 | 3 | 4</span>
            <span class="font-semibold">
              {{ formatNumber(publicationTotals.level_1) }} | {{ formatNumber(publicationTotals.level_2) }} | {{ formatNumber(publicationTotals.level_3) }} | {{ formatNumber(publicationTotals.level_4) }}
            </span>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="border border-gray-300 bg-white rounded shadow-sm overflow-hidden">
        <div class="max-h-[60vh] overflow-y-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-gray-100 text-left sticky top-0 z-10">
              <tr>
                <th class="px-3 py-2">RP</th>
                <th class="px-3 py-2">Partner</th>
                <th class="px-3 py-2">Event</th>
                <th class="px-3 py-2">Name, Datum, Anmeldungen</th>
                <th class="px-3 py-2">Plan</th>
                <th class="px-3 py-2">Erstellt</th>
                <th class="px-3 py-2">Letzte Änderung</th>
                <th class="px-3 py-2">Generierungen</th>
                <th class="px-3 py-2">Expert-Parameter</th>
                <th class="px-3 py-2">Extra-Blöcke</th>
                <th class="px-3 py-2">Publikations-Level / -Link</th>
                <th class="px-3 py-2">Publiziert</th>
                <th class="px-3 py-2">Letzte Änderung</th>
              </tr>
            </thead>
            <tbody>
        <tr
            v-for="(row, index) in flattenedRows"
          :key="`${row.partner_id}-${row.event_id}-${row.plan_id}`"
          class="border-t border-gray-200 hover:bg-gray-50"
        >
          <!-- RP ID -->
          <td class="px-3 py-2 text-gray-400">
            <template v-if="shouldShowPartner(index)">
              {{ row.partner_id }}
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- RP name -->
          <td class="px-3 py-2">
            <template v-if="shouldShowPartner(index)">
              {{ row.partner_name }}
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Event ID -->
          <td class="px-3 py-2 text-gray-400">
            <template v-if="shouldShowEvent(index)">
              {{ row.event_id }}
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Event name + date -->
          <td class="px-3 py-2">
            <template v-if="shouldShowEvent(index)">
              <span class="mr-2">
                <template v-if="row.plan_id === null">
                  <!-- ⬜️  No plan -->
                  ⬜️ 
                </template>
                <template v-else-if="getPlanCount(row.event_id) === 1">
                  <!-- ✅ Exactly one plan -->
                  ✅
                </template>
                <template v-else>
                  <!-- ⚠️ Multiple plans -->
                  ⚠️
                </template>
              </span>
              <!-- Clickable name -->
              <a
                href="#"
                class="text-blue-600 hover:underline cursor-pointer"
                @click.prevent="selectEvent(row.event_id, row.partner_id)"
              >
                {{ row.event_name }}
              </a>

              <span class="text-gray-500"> ({{ formatDateOnly(row.event_date) }})</span>
              <span
                v-if="row.event_explore || row.event_challenge"
                class="inline-flex items-center space-x-2 ml-2"
              >
                <span
                  v-if="row.event_explore"
                  class="inline-flex items-center space-x-1"
                >
                  <img
                    :src="programLogoSrc('E')"
                    :alt="programLogoAlt('E')"
                    class="w-5 h-5 inline-block"
                  />
                  <span class="text-xs text-gray-600">
                    {{ row.event_teams_explore ?? 0 }}
                  </span>
                </span>
                <span
                  v-if="row.event_challenge"
                  class="inline-flex items-center space-x-1"
                >
                  <img
                    :src="programLogoSrc('C')"
                    :alt="programLogoAlt('C')"
                    class="w-5 h-5 inline-block"
                  />
                  <span class="text-xs text-gray-600">
                    {{ row.event_teams_challenge ?? 0 }}
                  </span>
                </span>
              </span>
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Plan ID + buttons -->
          <td class="px-3 py-2 text-gray-400">
            <div class="flex flex-col items-start">
              <span>{{ row.plan_id }}</span>
              <div v-if="row.plan_id" class="flex gap-2 mt-1">
                <!-- Preview -->
                <button
                  class="text-blue-600 hover:text-blue-800"
                  title="Vorschau öffnen"
                  @click="openPreview(row.plan_id)"
                >
                  🧾
                </button>
                <!-- Delete -->
                <button
                  class="text-red-600 hover:text-red-800"
                  title="Plan löschen"
                  @click="openPlanDelete(row.plan_id)"
                >
                  🗑️
                </button>
              </div>
            </div>
          </td>

          <!-- Plan created -->
          <td class="px-3 py-2">{{ formatDateTime(row.plan_created) }}</td>

          <!-- Plan last change -->
          <td class="px-3 py-2" :class="getLastChangeClass(row.plan_last_change)">
            {{ formatDateTime(row.plan_last_change) }}
          </td>
  
          <!-- Generator stats -->
          <td class="px-3 py-2 text-right">
            <template v-if="row.plan_id && row.generator_stats !== null">
              {{ row.generator_stats }}
            </template>
            <template v-else>
              –
            </template>
          </td>     

          <!-- Expert parameter changes -->
          <td class="px-3 py-2 text-right">
            <template v-if="row.plan_id">
              <template v-if="(row.expert_param_changes ?? 0) > 0">
                <a
                  href="#"
                  class="text-blue-600 hover:text-blue-800 hover:underline cursor-pointer"
                  @click.prevent="openExpertParameters(row.plan_id)"
                >
                  {{ row.expert_param_changes }}
                </a>
              </template>
              <template v-else>
                {{ row.expert_param_changes }}
              </template>
            </template>
            <template v-else>–</template>
          </td>

          <!-- Extra blocks -->
          <td class="px-3 py-2 text-right">
            <template v-if="row.plan_id">
              {{ row.extra_blocks }}
            </template>
            <template v-else>–</template>
          </td>

          <!-- Publication level -->
          <td class="px-3 py-2">
            <template v-if="(row.publication_level ?? 0) >= 1 && row.event_link">
              <a
                :href="row.event_link"
                class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800"
                target="_blank"
                rel="noopener noreferrer"
              >
                <span class="flex">
                  <span
                    v-for="n in 4"
                    :key="n"
                    class="w-3 h-3 rounded-full mx-0.5"
                    :class="n <= (row.publication_level ?? 0)
                      ? 'bg-blue-600'
                      : 'bg-gray-300'"
                  ></span>
                </span>
                <span>{{ row.publication_level ?? '–' }}</span>
              </a>
            </template>
            <template v-else>
              <span class="inline-flex items-center gap-1 text-gray-700">
                <span class="flex">
                  <span
                    v-for="n in 4"
                    :key="n"
                    class="w-3 h-3 rounded-full mx-0.5"
                    :class="n <= (row.publication_level ?? 0)
                      ? 'bg-blue-600'
                      : 'bg-gray-300'"
                  ></span>
                </span>
                <span>{{ row.publication_level ?? '–' }}</span>
              </span>
            </template>
          </td>

          <!-- Publication date -->
          <td class="px-3 py-2">
            <template v-if="row.plan_id && row.publication_date">
              {{ formatDateTime(row.publication_date) }}
            </template>
            <template v-else>–</template>
          </td>

          <!-- Publication last change -->
          <td class="px-3 py-2" :class="getLastChangeClass(row.publication_last_change ?? null)">
            <template v-if="row.plan_id && row.publication_last_change">
              {{ formatDateTime(row.publication_last_change) }}
            </template>
            <template v-else>–</template>
          </td>




        </tr>

            </tbody>
          </table>
        </div>
      </div>

      <div v-if="flattenedRows.length === 0" class="mt-4 text-gray-500 italic">
        Keine Pläne in dieser Saison.
      </div>
    </div>
  </div>


  <!-- Delete/Cleanup/Expert Parameters modal -->
  <teleport to="body">
    <div v-if="modalState.visible" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <!-- Expert Parameters Modal -->
      <div v-if="modalState.mode === 'expert-parameters'" class="bg-white p-6 rounded-lg shadow-lg w-[90vw] max-w-4xl max-h-[90vh] overflow-auto">
        <h3 class="text-lg font-bold mb-4">
          Expert-Parameter für Plan {{ modalState.planId }}
        </h3>
        
        <div v-if="loadingExpertParams" class="text-gray-500 py-4">
          Lade Parameter...
        </div>
        
        <div v-else-if="expertParameters.length === 0" class="text-gray-500 py-4">
          Keine Expert-Parameter gefunden.
        </div>
        
        <div v-else class="overflow-x-auto">
          <table class="min-w-full text-sm border-collapse">
            <thead class="bg-gray-100 text-left">
              <tr>
                <th class="px-3 py-2 border border-gray-300">Name</th>
                <th class="px-3 py-2 border border-gray-300">UI Label</th>
                <th class="px-3 py-2 border border-gray-300">Set Value</th>
                <th class="px-3 py-2 border border-gray-300">Default Value</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="param in expertParameters"
                :key="param.name"
                class="hover:bg-gray-50"
              >
                <td class="px-3 py-2 border border-gray-300">{{ param.name }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.ui_label ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.set_value ?? '–' }}</td>
                <td class="px-3 py-2 border border-gray-300">{{ param.default_value ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        
        <div class="flex justify-end gap-2 mt-6">
          <button class="px-4 py-2 text-gray-600 hover:text-black" @click="closeModal">Schließen</button>
        </div>
      </div>
      
      <!-- Delete/Cleanup Modal -->
      <div v-else class="bg-white p-6 rounded-lg shadow-lg w-96 max-w-full">
        <h3 class="text-lg font-bold mb-4">
          <template v-if="modalState.mode === 'plan-delete'">
            Plan löschen?
          </template>
          <template v-else-if="modalState.mode === 'cleanup' && modalState.cleanupType">
            {{ cleanupMeta[modalState.cleanupType].title }}
          </template>
        </h3>
        <p class="mb-6 text-sm text-gray-700">
          <template v-if="modalState.mode === 'plan-delete'">
            Bist du sicher, dass du den Plan mit der ID
            <span class="font-semibold">{{ modalState.planId }}</span>
            löschen möchtest? Diese Aktion kann nicht rückgängig gemacht werden.
          </template>
          <template v-else-if="modalState.mode === 'cleanup' && modalState.cleanupType">
            {{ cleanupMeta[modalState.cleanupType].description }}
          </template>
        </p>
        <div class="flex justify-end gap-2">
          <button class="px-4 py-2 text-gray-600 hover:text-black" @click="closeModal">Abbrechen</button>
          <button class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700" @click="confirmModal">
            {{ modalConfirmLabel }}
          </button>
        </div>
      </div>
    </div>
  </teleport>



</template>