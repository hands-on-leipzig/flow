<script setup lang="ts">

import { formatTimeOnly, formatDateTime } from '@/utils/dateTimeFormat'

import {ref, watch, onMounted, computed} from 'vue'
import QPlanDetails from '@/components/atoms/QPlanDetails.vue'
import axios from 'axios'
import { useRoute } from 'vue-router'
import { useAuth } from '@/composables/useAuth'

type Header = { key: string; title: string }
type Cell = { render?: boolean; rowspan?: number; colspan?: number; text?: string }
type Row = {
  separator?: boolean
  variant?: 'day'
  timeIso?: string
  timeLabel?: string
  cells?: Record<string, Cell>
}

// Robot-Game types
type Match = {
  match_id: number
  match_no: number
  table_1: number | null
  table_1_team: number | null
  table_2: number | null
  table_2_team: number | null
}

type RobotGameRound = {
  round: number
  name: string
  matches: Match[]
}

type TeamSummary = {
  team: number
  different_tables: number
  different_opponents: number
}

type RobotGameData = {
  has_challenge: boolean
  rounds: RobotGameRound[]
  team_summary: TeamSummary[]
}

const route = useRoute()
const { isAdmin, initializeUserRoles } = useAuth()

// Ensure roles are initialized
onMounted(() => {
  initializeUserRoles()
})

const props = withDefaults(defineProps<{
  planId?: number
  initialView?: 'overview' | 'roles' | 'teams' | 'robot-game' | 'rooms' | 'activities'
  reload?: number
}>(), {
  initialView: 'overview',
})

const effectivePlanId = computed(() => {
  return props.planId ?? Number(route.params.planId)
})

const view = ref<'overview' | 'roles' | 'teams' | 'robot-game' | 'quality' | 'rooms' | 'activities'>(props.initialView as any)

const loading = ref(false)
const error = ref<string | null>(null)

// Bestehende Preview-Struktur
const headers = ref<Header[]>([])
const rows = ref<Row[]>([])
const headerKeys = computed(() => headers.value.map(h => h.key))

// Activities-Datenstruktur (roh von /plans/activities/{id})
type ActivityRow = {
  activity_id: number
  start_time: string
  end_time: string
  program: string|null
  activity_name: string
  lane: number|null
  team: number|null
  table_1_team: number|null
  table_2_team: number|null
  table_1: number|null
  table_2: number|null
  room_type_name: string
}
type ActivityGroup = {
  activity_group_id: number|null
  activity_group_name?: string
  explore_group?: number|null
  activities: ActivityRow[]
}
const activities = ref<ActivityGroup[]>([])

// Robot-Game data
const robotGameData = ref<RobotGameData | null>(null)
const hasChallenge = ref(false)

// Event overview HTML
const overviewHtml = ref<string>('')

async function load() {
  if (!effectivePlanId.value) return
  loading.value = true
  error.value = null

  try {
    if (view.value === 'overview') {
      // Event overview HTML
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/overview`)
      overviewHtml.value = data.html
      headers.value = []
      rows.value = []
      activities.value = []
      robotGameData.value = null
  } else if (view.value === 'robot-game') {
      // Robot-Game match plan
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/robot-game`)
      robotGameData.value = data
      hasChallenge.value = data?.has_challenge ?? false
      headers.value = []
      rows.value = []
      activities.value = []
  } else if (view.value === 'quality') {
      // Qualität-Ansicht lädt separat in QPlanDetails
      headers.value = []
      rows.value = []
      activities.value = []
    } else if (view.value === 'activities') {
      // Power-User-Sicht: rohe Activities vom Backend
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/activities`)
      activities.value = Array.isArray(data?.groups) ? data.groups : []
      headers.value = []
      rows.value = []
      robotGameData.value = null
    } else {
      // Bestehende Preview-API nutzen
      const url = `/plans/preview/${effectivePlanId.value}/${view.value}` // roles / teams / rooms
      const { data } = await axios.get(url)
      headers.value = Array.isArray(data?.headers) ? data.headers : []
      rows.value = Array.isArray(data?.rows) ? data.rows : []
      activities.value = []
      robotGameData.value = null
    }
  } catch (e: any) {
    console.error('[Preview] load() error:', e)
    error.value = e?.message || 'Fehler beim Laden'
    headers.value = []
    rows.value = []
    activities.value = []
    robotGameData.value = null
  } finally {
    loading.value = false
  }
}

watch(() => effectivePlanId.value, () => load())
watch(view, () => load())
watch(() => props.reload, () => load())

onMounted(async () => {
  // Check if Challenge exists to toggle Robot-Game button
  if (effectivePlanId.value) {
    try {
      const { data } = await axios.get(`/plans/preview/${effectivePlanId.value}/robot-game`)
      hasChallenge.value = data?.has_challenge ?? false
    } catch (e) {
      console.error('[Preview] Failed to check Challenge existence:', e)
      hasChallenge.value = false
    }
  }
  load()
})

function setView(v: 'overview' | 'roles' | 'teams' | 'quality' | 'rooms' | 'activities') {
  if (view.value !== v) view.value = v
}

// Helper functions for Robot-Game view
function hasTable34(round: RobotGameRound): boolean {
  // Check if any match in this round uses table 3 or 4
  // Table 3/4 only exist when r_tables = 4
  return round.matches.some(m => m.table_1 === 3 || m.table_1 === 4 || m.table_2 === 3 || m.table_2 === 4)
}

function formatTeam(teamNum: number | null): string {
  // Format team display
  // Empty: no team (shouldn't happen in this context, but handle it)
  // '–': Team 0 (volunteer/BYE)
  // Number: Regular team
  if (teamNum === null) return ''
  if (teamNum === 0) return '–'
  return String(teamNum)
}

// Check if any activity group has explore_group filled
const hasExploreGroups = computed(() => {
  return activities.value.some(group => group.explore_group !== null && group.explore_group !== undefined)
})

// Format explore group display
function formatExploreGroup(exploreGroup: number | null | undefined): string {
  if (exploreGroup === null || exploreGroup === undefined) return ''
  if (exploreGroup === 1) return 'Gruppe 1'
  if (exploreGroup === 2) return 'Gruppe 2'
  return ''
}
</script>

<template>
  <div class="flex flex-col gap-3 h-full min-h-0">
    <!-- Kopfbereich: Buttons + Info -->
    <div class="flex flex-wrap items-center gap-2">
      <div class="inline-flex rounded-md overflow-hidden border">
        <button
          class="px-3 py-1 text-sm"
          :class="view === 'overview' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
          @click="setView('overview')"
        >Überblick</button>
      </div>

      <div class="inline-flex items-center rounded-md border bg-gray-50/50 px-2 py-1">
        <div class="inline-flex rounded-md overflow-hidden border">
          <button
            class="px-3 py-1 text-sm"
            :class="view === 'roles' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
            @click="setView('roles')"
          >Rollen</button>

          <button
            class="px-3 py-1 text-sm border-l"
            :class="view === 'teams' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
            @click="setView('teams')"
          >Teams</button>

          <button
            class="px-3 py-1 text-sm border-l"
            :class="view === 'rooms' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
            @click="setView('rooms')"
          >Räume</button>
        </div>

        <div v-if="view === 'roles' || view === 'teams' || view === 'rooms'" class="ml-3 text-xs text-gray-500">
          Freie Blöcke werden hier nicht angezeigt, weil sie den Ablauf nicht beeinflussen.
        </div>
      </div>

      <div class="inline-flex rounded-md overflow-hidden border">
        <button
          v-if="hasChallenge"
          class="px-3 py-1 text-sm"
          :class="view === 'robot-game' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
          @click="setView('robot-game')"
        >Robot-Game</button>
      </div>

      <div v-if="isAdmin" class="inline-flex rounded-md overflow-hidden border">
        <!-- Aktivitäten und Plan-Qualität -->
        <button
          class="px-3 py-1 text-sm"
          :class="view === 'activities' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
          @click="setView('activities')"
        >Aktivitäten</button>
        <button
          v-if="hasChallenge"
          class="px-3 py-1 text-sm border-l"
          :class="view === 'quality' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
          @click="setView('quality')"
        >Plan-Qualität</button>
      </div>

      <div class="ml-3 flex-1 flex items-center justify-end text-xs text-gray-500 min-w-0">
        <span class="whitespace-nowrap">Plan ID: {{ effectivePlanId }}</span>
      </div>
    </div>

    <!-- Fehlermeldung -->
    <div v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-2">
      {{ error }}
    </div>

    <!-- ANSICHT 1–3: Bestehende Preview-Tabellen (roles, teams, rooms) -->
    <div v-if="view === 'roles' || view === 'teams' || view === 'rooms'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white">
      <table class="w-full table-fixed text-sm">
        <thead class="sticky top-0 bg-gray-50">
          <tr>
            <th v-for="h in headers" :key="h.key" class="text-left font-normal px-2 py-2 border-b border-gray-200">
              {{ h.title }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td :colspan="headers.length" class="px-3 py-8 text-left text-gray-500">Wird geladen …</td>
          </tr>

          <template v-else>
            <template v-for="(r, ridx) in rows" :key="ridx">
              <tr v-if="r.separator">
                <td :colspan="headers.length" class="p-0">
                  <div v-if="r.variant === 'day'" class="h-0.5 bg-gray-500"></div>
                  <div v-else class="h-3"></div>
                </td>
              </tr>

              <tr v-else class="odd:bg-gray-50 even:bg-gray-100">
                <td v-if="headerKeys[0]==='time'" class="align-top px-2 py-2 whitespace-pre-line">
                  <template v-if="r.timeLabel">
                    <span class="block">{{ (r.timeLabel || '').split(' ')[0] }}</span>
                    <span class="block">{{ (r.timeLabel || '').split(' ')[1] || '' }}</span>
                  </template>
                </td>

                <template v-for="(h, cidx) in headers.slice(1)" :key="h.key + '-' + ridx">
                  <template v-if="r.cells && r.cells[h.key]">
                    <td v-if="r.cells[h.key].render !== false"
                        class="align-top px-2 py-2 whitespace-pre-line"
                        :rowspan="r.cells[h.key].rowspan || 1"
                        :colspan="r.cells[h.key].colspan || 1"
                        :class="(r.cells[h.key].text && r.cells[h.key].text!.trim() !== '') ? 'bg-white' : ''">
                      {{ r.cells[h.key].text || '' }}
                    </td>
                  </template>
                  <td v-else class="align-top px-2 py-2"></td>
                </template>
              </tr>
            </template>

            <tr v-if="rows.length === 0 && !loading">
              <td :colspan="headers.length" class="px-3 py-6 text-center text-gray-500">
                Keine Aktivitäten im Zeitraum.
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>

    <!-- ANSICHT: Überblick -->
    <div v-if="view === 'overview'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-gray-500">Wird geladen …</div>
      
      <template v-else>
        <div v-if="!overviewHtml" class="px-3 py-6 text-center text-gray-500">
          Keine Übersichtsdaten gefunden.
        </div>
        
        <div v-else v-html="overviewHtml" class="event-overview-container"></div>
      </template>
    </div>

    <!-- ANSICHT: Robot-Game Matchplan -->
    <div v-else-if="view === 'robot-game'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white p-4">
      <div v-if="loading" class="px-3 py-8 text-left text-gray-500">Wird geladen …</div>

      <template v-else>
        <div v-if="!robotGameData || !robotGameData.rounds || robotGameData.rounds.length === 0" class="px-3 py-6 text-center text-gray-500">
          Keine Robot-Game Daten gefunden.
        </div>

        <div v-else class="flex flex-col gap-6">
          <!-- Match plan by rounds -->
          <div class="flex flex-row gap-4 overflow-x-auto">
            <div
              v-for="round in robotGameData.rounds"
              :key="round.round"
              class="min-w-max"
            >
              <div class="text-sm font-semibold text-gray-600 mb-2">
                {{ round.name }}
              </div>
              <table class="table-auto text-sm border-collapse border border-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-2 py-1 border border-gray-200 text-center font-normal">Tisch 1</th>
                    <th class="px-2 py-1 border border-gray-200 text-center font-normal">Tisch 2</th>
                    <th v-if="hasTable34(round)" class="px-2 py-1 border border-gray-200 text-center font-normal">Tisch 3</th>
                    <th v-if="hasTable34(round)" class="px-2 py-1 border border-gray-200 text-center font-normal">Tisch 4</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="match in round.matches"
                    :key="match.match_id"
                    class="border-t"
                  >
                    <td class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 1">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 1">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 2">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 2">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td v-if="hasTable34(round)" class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 3">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 3">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td v-if="hasTable34(round)" class="text-center px-2 py-1">
                      <span v-if="match.table_1 === 4">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 4">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Team summary table -->
          <div v-if="robotGameData.team_summary && robotGameData.team_summary.length > 0" class="mt-4">
            <div class="text-sm font-semibold text-gray-600 mb-2">Übersicht über die Verteilung</div>
            <table class="table-auto text-sm border-collapse border border-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-3 py-2 border border-gray-200 text-left font-normal">Team</th>
                  <th class="px-3 py-2 border border-gray-200 text-center font-normal">Verschiedene Tische</th>
                  <th class="px-3 py-2 border border-gray-200 text-center font-normal">Verschiedene Teams</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="summary in robotGameData.team_summary"
                  :key="summary.team"
                  class="border-t"
                >
                  <td class="px-3 py-2 border border-gray-200">{{ summary.team }}</td>
                  <td class="px-3 py-2 border border-gray-200 text-center">{{ summary.different_tables }}</td>
                  <td class="px-3 py-2 border border-gray-200 text-center">{{ summary.different_opponents }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>

    <!-- ANSICHT: Plan-Qualität (QPlanDetails) -->
    <div v-else-if="view === 'quality'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white p-4">
      <QPlanDetails v-if="effectivePlanId" :plan-id="Number(effectivePlanId)" />
    </div>

    <!-- ANSICHT: Power-User „Aktivitäten" -->
    <div v-else-if="view === 'activities'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white p-3">
      <div v-if="loading" class="px-3 py-8 text-left text-gray-500">Wird geladen …</div>

      <template v-else>
        <div v-if="activities.length === 0" class="px-3 py-6 text-center text-gray-500">
          Keine Aktivitäten gefunden.
        </div>

        <div v-for="group in activities" :key="String(group.activity_group_id)" class="mb-6">
          <div class="font-semibold text-sm mb-2">
            Activity Group ID: {{ group.activity_group_id ?? '–' }} - {{ group.activity_group_name ?? 'Unknown Group' }}
          </div>

          <div class="overflow-x-auto border rounded">
            <table class="min-w-full text-xs">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-2 py-1 text-left">Activity ID</th>
                  <th class="px-2 py-1 text-left">Start</th>
                  <th class="px-2 py-1 text-left">Ende</th>
                  <th class="px-2 py-1 text-left">FIRST Program</th>
                  <th class="px-2 py-1 text-left">Activity Name</th>
                  <th class="px-2 py-1 text-left">Lane</th>
                  <th class="px-2 py-1 text-left">Team</th>
                  <th class="px-2 py-1 text-left">Table 1 Team</th>
                  <th class="px-2 py-1 text-left">Table 2 Team</th>
                  <th class="px-2 py-1 text-left">Table 1</th>
                  <th class="px-2 py-1 text-left">Table 2</th>
                  <th class="px-2 py-1 text-left">Room Type</th>
                  <th v-if="hasExploreGroups" class="px-2 py-1 text-left">Gruppe</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in group.activities" :key="a.activity_id" class="border-t">
                  <td class="px-2 py-1 text-gray-500">{{ a.activity_id }}</td>
                  <td class="px-2 py-1">{{ formatDateTime(a.start_time, true) }}</td>
                  <td class="px-2 py-1">{{ formatTimeOnly(a.end_time, true) }}</td>
                  <td class="px-2 py-1">{{ a.program || '' }}</td>
                  <td class="px-2 py-1">{{ a.activity_name }}</td>
                  <td class="px-2 py-1">{{ a.lane ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_1_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_1 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.room_type_name || '' }}</td>
                  <td v-if="hasExploreGroups" class="px-2 py-1">{{ formatExploreGroup(group.explore_group) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/* alle Spalten gleich breit */
table {
  table-layout: fixed;
}

/* kein fett im Header */
th {
  font-weight: 400;
}

/* Inhalte dürfen Zeilenumbrüche enthalten */
td {
  white-space: pre-line;
}

/* Zeitspalte genau wie Zellen (kein Bold) */

/* Event overview container */
.event-overview-container {
  width: 100%;
  overflow-x: auto;
}

.event-overview-container .event-overview {
  min-width: 100%;
}

.event-overview-container .day-header {
  margin-bottom: 15px;
}

.event-overview-container .overview-table {
  font-size: 11px;
}

.event-overview-container .overview-table th,
.event-overview-container .overview-table td {
  padding: 6px 4px;
  height: 24px; /* Consistent row height for HTML preview */
  vertical-align: top; /* Align content to top of cell */
}

.event-overview-container .overview-table td {
  overflow: hidden; /* Hide overflow text to maintain consistent height */
  text-overflow: ellipsis; /* Show ellipsis for truncated text */
  line-height: 1.1; /* Minimize vertical gap between activity name and time */
}

.event-overview-container .header-logo {
  height: 18px;
}
</style>