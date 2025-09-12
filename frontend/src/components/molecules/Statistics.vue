<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

import { formatDateOnly, formatDateTime } from '@/utils/dateTimeFormat'
import { programLogoSrc, programLogoAlt } from '@/utils/images'  


const data = ref(null)
const totals = ref(null)
const loading = ref(true)
const error = ref(null)
const selectedSeasonKey = ref(null)

onMounted(async () => {
  try {
    const [plansRes, totalsRes] = await Promise.all([
      axios.get('/stats/plans'),
      axios.get('/stats/totals'),
    ])
    data.value = plansRes.data
    totals.value = totalsRes.data

    if (data.value?.seasons?.length > 0) {
      // Default: letzte Saison vorselektieren
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

// Map für schnellen Zugriff auf Totals per "year-name"
const totalsByKey = computed(() => {
  const map = new Map()
  if (!totals.value?.seasons) return map
  for (const s of totals.value.seasons) {
    map.set(`${s.season_year}-${s.season_name}`, s.totals ?? null)
  }
  return map
})

// ersetzt deine aktuelle seasonTotals-Definition
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
    events_with_plan: s.events?.with_plan ?? 0,   // nutzt neues Feld
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

const badgeClass = (n) =>
  n > 0
    ? 'bg-red-100 text-red-800 border border-red-300'
    : 'bg-gray-100 text-gray-700 border border-gray-300'

const flattenedRows = computed(() => {
  const season = data.value?.seasons.find(
    s => `${s.season_year}-${s.season_name}` === selectedSeasonKey.value
  )
  if (!season) return []

  const rows = []

  for (const partner of season.partners) {
    if (!partner.events || partner.events.length === 0) {
      rows.push({
        partner_id: partner.partner_id,
        partner_name: partner.partner_name,
        event_id: null,
        event_name: null,
        event_date: null,
        event_explore: null,
        event_challenge: null,
        plan_id: null,
        plan_created: null,
        plan_last_change: null,
        generator_stats: null,
      })
      continue
    }

    for (const event of partner.events) {
      if (!event.plans || event.plans.length === 0) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
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
          event_explore: event.event_explore,
          event_challenge: event.event_challenge,
          plan_id: plan.plan_id,
          plan_created: plan.plan_created,
          plan_last_change: plan.plan_last_change,
          generator_stats: plan.generator_stats ?? null,
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
</script>

<template>
  <div>
    <div v-if="loading" class="text-gray-500">Lade Daten …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else>
      <!-- Globale Orphans -->
      <div class="mb-4 flex flex-wrap items-center gap-3">
        <div :class="['px-3 py-1 rounded-full text-sm font-semibold', badgeClass(orphans.events)]">
          Events (ohne/ungültiger RP): {{ orphans.events }}
        </div>
        <div :class="['px-3 py-1 rounded-full text-sm font-semibold', badgeClass(orphans.plans)]">
          Pläne (ohne/ungültiges Event): {{ orphans.plans }}
        </div>
        <div :class="['px-3 py-1 rounded-full text-sm font-semibold', badgeClass(orphans.ags)]">
          ActGroups (ohne/ungültiger Plan): {{ orphans.ags }}
        </div>
        <div :class="['px-3 py-1 rounded-full text-sm font-semibold', badgeClass(orphans.acts)]">
          Activities (ohne/ungültiger ActGroup): {{ orphans.acts }}
        </div>
      </div>
        <!-- Season Filter -->
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

        <!-- Saison-Totals (3 Boxen) -->
        <div class="mb-6 grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Box 1: RP -->
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

          <!-- Box 2: Events -->
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

        <!-- Box 3: Plan & Aktivitäten -->
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
      </div>

      <!-- Tabelle -->
      <table class="min-w-full text-sm border border-gray-300 bg-white">
        <thead class="bg-gray-100 text-left">
          <tr>
            <th class="px-3 py-2">RP</th>
            <th class="px-3 py-2">Partner</th>
            <th class="px-3 py-2">Event</th>
            <th class="px-3 py-2">Eventname</th>
            <th class="px-3 py-2">Plan</th>
            <th class="px-3 py-2">Erstellt</th>
            <th class="px-3 py-2">Letzte Änderung</th>
            <th class="px-3 py-2">Generierungen</th>
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

          <!-- RP Name -->
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

          <!-- Event Name + Date -->
          <td class="px-3 py-2">
            <template v-if="shouldShowEvent(index)">
              <span class="mr-2">
                <template v-if="row.plan_id === null">
                  <!-- ⬜️  Kein Plan -->
                  ⬜️ 
                </template>
                <template v-else-if="getPlanCount(row.event_id) === 1">
                  <!-- ✅ Genau ein Plan -->
                  ✅
                </template>
                <template v-else>
                  <!-- ⚠️ Mehrere Pläne -->
                  ⚠️
                </template>
              </span>
              {{ row.event_name }}
              <span class="text-gray-500">({{ formatDateOnly(row.event_date) }})</span>
              <span class="inline-flex items-center space-x-1 ml-2">
                <img
                  v-if="row.event_explore"
                  :src="programLogoSrc('E')"
                  :alt="programLogoAlt('E')"
                  class="w-5 h-5 inline-block"
                />
                <img
                  v-if="row.event_challenge"
                  :src="programLogoSrc('C')"
                  :alt="programLogoAlt('C')"
                  class="w-5 h-5 inline-block"
                />
              </span>
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Plan ID + Button -->
          <td class="px-3 py-2 text-gray-400">
            {{ row.plan_id }}
            <template v-if="row.plan_id">
              <button
                class="ml-2 text-blue-600 hover:text-blue-800"
                title="Vorschau öffnen"
                @click="openPreview(row.plan_id)"
              >
                🧾
              </button>
            </template>
          </td>

          <!-- Plan Created -->
          <td class="px-3 py-2">{{ formatDateTime(row.plan_created) }}</td>

          <!-- Plan Last Change -->
          <td class="px-3 py-2">{{ formatDateTime(row.plan_last_change) }}</td>
  
          <!-- Generator Stats -->
          <td class="px-3 py-2 text-right">
            <template v-if="row.plan_id && row.generator_stats !== null">
              {{ row.generator_stats }}
            </template>
            <template v-else>
              –
            </template>
          </td>     

        </tr>

      </tbody>
      </table>

      <div v-if="flattenedRows.length === 0" class="mt-4 text-gray-500 italic">
        Keine Pläne in dieser Saison.
      </div>
    </div>
  </div>
</template>