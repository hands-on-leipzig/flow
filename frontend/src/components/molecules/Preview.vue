<script setup lang="ts">

import { formatTimeOnly, formatDateTime } from '@/utils/dateTimeFormat'

import {ref, watch, onMounted, computed} from 'vue'
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

const route = useRoute()
const { isAdmin, initializeUserRoles } = useAuth()

// Ensure roles are initialized
onMounted(() => {
  initializeUserRoles()
})

const props = withDefaults(defineProps<{
  planId?: number
  initialView?: 'roles' | 'teams' | 'rooms' | 'activities' // ← erweitert
  reload?: number
}>(), {
  initialView: 'roles',
})

const effectivePlanId = computed(() => {
  return props.planId ?? Number(route.params.planId)
})

// ↓↓↓ erweitert: activities als mögliche Ansicht
const view = ref<'roles' | 'teams' | 'rooms' | 'activities'>(props.initialView)

const loading = ref(false)
const error = ref<string | null>(null)

// Bestehende Preview-Struktur
const headers = ref<Header[]>([])
const rows = ref<Row[]>([])
const headerKeys = computed(() => headers.value.map(h => h.key))

// NEU: Activities-Datenstruktur (roh von /plans/activities/{id})
type ActivityRow = {
  activity_id: number
  start_time: string
  end_time: string
  program: string|null
  activity_name: string
  lane: number|null
  team: number|null
  table_1: number|null
  table_1_team: number|null
  table_2: number|null
  table_2_team: number|null
}
type ActivityGroup = {
  activity_group_id: number|null
  activities: ActivityRow[]
}
const activities = ref<ActivityGroup[]>([]) // ← neu

async function load() {
  if (!effectivePlanId.value) return
  loading.value = true
  error.value = null

  try {
    if (view.value === 'activities') {
      // Power-User-Sicht: rohe Activities vom Backend
      const { data } = await axios.get(`/plans/activities/${effectivePlanId.value}`)
      activities.value = Array.isArray(data?.groups) ? data.groups : []
      // Vorsichtshalber die Preview-Strukturen leeren, damit Template klar verzweigt
      headers.value = []
      rows.value = []
    } else {
      // Bestehende Preview-API nutzen
      const url = `/plans/preview/${effectivePlanId.value}/${view.value}` // roles / teams / rooms
      const { data } = await axios.get(url)
      headers.value = Array.isArray(data?.headers) ? data.headers : []
      rows.value = Array.isArray(data?.rows) ? data.rows : []
      activities.value = []
    }
  } catch (e: any) {
    console.error('[Preview/Activities] load() error:', e)
    error.value = e?.message || 'Fehler beim Laden'
    headers.value = []
    rows.value = []
    activities.value = []
  } finally {
    loading.value = false
  }
}

watch(() => effectivePlanId.value, () => load())
watch(view, () => load())
watch(() => props.reload, () => load())

onMounted(load)

function setView(v: 'roles' | 'teams' | 'rooms' | 'activities') {
  if (view.value !== v) view.value = v
}
</script>

<template>
  <div class="flex flex-col gap-3 h-full min-h-0">
    <!-- Kopfbereich: Buttons + Info -->
    <div class="flex flex-wrap items-center gap-2">
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

        <!-- NEU: Aktivitäten - nur für Admins -->
        <button
          v-if="isAdmin"
          class="px-3 py-1 text-sm border-l"
          :class="view === 'activities' ? 'bg-gray-900 text-white' : 'bg-white text-gray-800 hover:bg-gray-100'"
          @click="setView('activities')"
        >Aktivitäten</button>
      </div>

      <div class="ml-3 flex-1 flex items-center justify-between text-xs text-gray-500 min-w-0">
        <span class="truncate">
          Freie Blöcke werden hier nicht angezeigt, weil sie den Ablauf nicht beeinflussen.
        </span>
        <span class="whitespace-nowrap">Plan ID: {{ effectivePlanId }}</span>
      </div>
    </div>

    <!-- Fehlermeldung -->
    <div v-if="error" class="text-sm text-red-600 bg-red-50 border border-red-200 rounded-md p-2">
      {{ error }}
    </div>

    <!-- ANSICHT 1–3: Bestehende Preview-Tabellen -->
    <div v-if="view !== 'activities'" class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white">
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

    <!-- ANSICHT 4: Power-User „Aktivitäten“ -->
    <div v-else class="flex-1 min-h-0 overflow-y-auto rounded-md border border-gray-200 bg-white p-3">
      <div v-if="loading" class="px-3 py-8 text-left text-gray-500">Wird geladen …</div>

      <template v-else>
        <div v-if="activities.length === 0" class="px-3 py-6 text-center text-gray-500">
          Keine Aktivitäten gefunden.
        </div>

        <div v-for="group in activities" :key="String(group.activity_group_id)" class="mb-6">
          <div class="font-semibold text-sm mb-2">
            Activity Group ID: {{ group.activity_group_id ?? '–' }}
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
                  <th class="px-2 py-1 text-left">Table 1</th>
                  <th class="px-2 py-1 text-left">Table 1 Team</th>
                  <th class="px-2 py-1 text-left">Table 2</th>
                  <th class="px-2 py-1 text-left">Table 2 Team</th>
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
                  <td class="px-2 py-1">{{ a.table_1 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_1_team ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2 ?? '' }}</td>
                  <td class="px-2 py-1">{{ a.table_2_team ?? '' }}</td>
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
</style>