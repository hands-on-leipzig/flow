<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'

import { formatTimeOnly } from '@/utils/dateTimeFormat'
import { programLogoSrc, programLogoAlt } from '@/utils/images'  

// Inputs
const planId = ref('6')
const role = ref(14)            // <-- neu: Default 14 = Publikum
const usePoint = ref(true)
const timeStr = ref('11:00')    // HH:mm
const intervalMin = ref(60)

// Rollendefinitionen
const roles = [
  { id: 14, label: 'Besucher Allgemein' },
  { id: 6,  label: 'Besucher Challenge' },
  { id: 10, label: 'Besucher Explore' }
]

// Output
const loading = ref(false)
const error = ref(null)
const result = ref(null)

function buildPointInTimeParam() {
  const params: any = {}
  if (usePoint.value && timeStr.value) {
    params.point_in_time = timeStr.value
  }
  if (role.value) {
    params.role = role.value
  }
  return params
}

async function callNow() {
  if (!planId.value) return
  loading.value = true
  error.value = null
  result.value = null
  try {
    const params = buildPointInTimeParam()
    const { data } = await axios.get(`/plans/action-now/${planId.value}`, { params })
    result.value = data
    console.log('data:', data)
  } catch (e) {
    console.error(e)
    error.value = 'Fehler beim Abruf von now()).'
  } finally {
    loading.value = false
  }
}

async function callNext() {
  if (!planId.value) return
  loading.value = true
  error.value = null
  result.value = null
  try {
    const params = { ...buildPointInTimeParam(), interval: intervalMin.value }
    const { data } = await axios.get(`/plans/action-next/${planId.value}`, { params })
    result.value = data
  } catch (e) {
    console.error(e)
    error.value = 'Fehler beim Abruf von next().'
  } finally {
    loading.value = false
  }
}

const padTeam = (n: any) =>
  typeof n === 'number' || /^\d+$/.test(String(n))
    ? String(Number(n)).padStart(2, '0')
    : String(n ?? '').trim()

const teamLabel = (name?: string | null, num?: any) => {
  const nm = (name ?? '').trim()
  if (nm) return nm
  if (num != null && String(num).trim() !== '') return `Team ${padTeam(num)}`
  return ''
}

const splitWith = (a: any) => {
  const roomName: string | null = a?.room?.room_name ?? a?.room_name ?? null

  // Lane
  if (a?.lane) {
    const right = roomName || `Lane ${a.lane}`
    const bottom = teamLabel(a?.team_name, a?.team) || ''
    return { right, bottom }
  }

  // Table-Fall
  if (a?.table_1 || a?.table_2) {
    const t1Right = a?.table_1 ? (a?.table_1_name || `Tisch ${a.table_1}`) : ''
    const t2Right = a?.table_2 ? (a?.table_2_name || `Tisch ${a.table_2}`) : ''
    const right = [t1Right, t2Right].filter(Boolean).join(' : ')

    const t1Team = a?.table_1
      ? (teamLabel(a?.table_1_team_name, a?.table_1_team) || (a?.table_1_team ? `Team ${padTeam(a.table_1_team)}` : ''))
      : ''
    const t2Team = a?.table_2
      ? (teamLabel(a?.table_2_team_name, a?.table_2_team) || (a?.table_2_team ? `Team ${padTeam(a.table_2_team)}` : ''))
      : ''
    const bottom = [t1Team, t2Team].filter(Boolean).join(' : ')

    return { right, bottom }
  }

  // Sonst: nur Raum rechts, keine Teams unten
  return { right: roomName || '', bottom: '' }
}

function openPreview(id: string | number) {
  if (!id) return
  window.open(`/preview/${id}`, '_blank', 'noopener')
}

</script>

<template>
  <div class="space-y-4">

    <!-- Controls -->
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs text-gray-500 mb-1">Plan ID</label>
        <input v-model="planId" class="border rounded px-2 py-1 w-20" placeholder="z.B. 9255" />
      </div>
      <button
        v-if="planId"
        class="mb-1 text-blue-600 hover:text-blue-800"
        title="Vorschau öffnen"
        @click="openPreview(planId)"
      >
        🧾
      </button>

      <div>
        <label class="block text-xs text-gray-500 mb-1">Rolle</label>
        <select v-model.number="role" class="border rounded px-2 py-1">
          <option v-for="r in roles" :key="r.id" :value="r.id">
            {{ r.label }}
          </option>
        </select>
      </div>

      <div class="flex items-center gap-2">
        <label class="text-sm">
          <input type="checkbox" v-model="usePoint" class="mr-1" />
          Aktuelle Uhrzeit übersteuern
        </label>
      </div>

      <div v-if="usePoint" class="flex items-end gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Uhrzeit im Plan</label>
          <input type="time" v-model="timeStr" class="border rounded px-2 py-1" />
        </div>
      </div>

      <button @click="callNow" class="px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700">
        actionNow
      </button>

      <div class="flex items-end gap-2">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Intervall (min)</label>
          <input type="number" min="1" v-model.number="intervalMin" class="border rounded px-2 py-1 w-24" />
        </div>
        <button @click="callNext" class="px-3 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700">
          actionNext
        </button>
      </div>
    </div>

    <!-- Result -->
    <div v-if="loading" class="text-gray-500">Wird geladen …</div>
    <div v-else-if="error" class="text-red-600">{{ error }}</div>

    <div v-else-if="result">

      <!-- Eine Spalte pro Activity-Group -->
      <div
        class="grid gap-4"
        style="grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));"
      >
        <div
          v-for="g in (result.groups || [])"
          :key="g.activity_group_id"
          class="border rounded-lg bg-white shadow-sm overflow-hidden"
        >
          <!-- Group-Header -->
          <div class="px-3 py-2 bg-gray-50 border-b">
            <div class="flex items-start gap-2">
              <!-- Program Icon -->
              <img
                v-if="g.group_meta?.first_program_id === 2"
                :src="programLogoSrc('E')"
                :alt="programLogoAlt('E')"
                class="w-6 h-6 flex-shrink-0"
              />
              <img
                v-else-if="g.group_meta?.first_program_id === 3"
                :src="programLogoSrc('C')"
                :alt="programLogoAlt('C')"
                class="w-6 h-6 flex-shrink-0"
              />

              <!-- Textbereich -->
              <div class="flex-1">
                <div class="text-sm font-semibold">
                  {{ g.group_meta?.name || ('Group #' + g.activity_group_id) }}
                </div>
                <div v-if="g.group_meta?.description" class="text-xs text-gray-500 mt-0.5">
                  {{ g.group_meta.description }}
                </div>
              </div>
            </div>
          </div>
<!-- Activities der Gruppe -->
<ul class="divide-y">
  <li
    v-for="a in (g.activities || [])"
    :key="a.activity_id"
    class="px-3 py-2"
  >
    <!-- Zeile 1: Activity-Name (kleiner) -->
    <div class="text-sm text-gray-700 font-medium">
      {{ a.meta?.name || a.activity_name || ('Activity #' + a.activity_id) }}
    </div>

    <!-- Zeile 2: Zeit fett links, rechts Ort/Tische nicht fett -->
    <div class="mt-0.5 flex items-baseline justify-between gap-3">
      <div class="text-base font-semibold whitespace-nowrap">
        {{ formatTimeOnly(a.start_time, true) }}–{{ formatTimeOnly(a.end_time, true) }}
      </div>
      <div class="text-base text-gray-700">
        {{ splitWith(a).right }}
      </div>
    </div>

    <!-- Zeile 3: Teams (Lane: ein Team; Tables: Team A : Team B). Sonst leer -->
    <div v-if="splitWith(a).bottom" class="mt-0.5 text-base text-gray-800">
      {{ splitWith(a).bottom }}
    </div>
  </li>

  <li v-if="!g.activities || g.activities.length === 0" class="px-3 py-3 text-xs text-gray-500">
    Keine Aktivitäten in dieser Gruppe.
  </li>
</ul>

        </div>
      </div>

      <div v-if="!result.groups || result.groups.length === 0" class="mt-4 text-center text-gray-500">
        Keine passenden Aktivitäten.
      </div>
    </div>

  </div>
</template>