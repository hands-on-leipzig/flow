<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'

import { formatTimeOnly, formatDateOnly, formatDateTime } from '@/utils/dateTimeFormat'

// Inputs
const planId = ref('9333')
const usePoint = ref(true)
const dateStr = ref('2026-01-16')   // YYYY-MM-DD
const timeStr = ref('11:00')   // HH:mm
const intervalMin = ref(60)

// Output
const loading = ref(false)
const error = ref(null)
const result = ref(null)

function buildPointInTimeParam() {
  if (!usePoint.value) return {}
  if (!dateStr.value || !timeStr.value) return {}
  // Wir schicken „YYYY-MM-DD HH:mm“ – Backend parst in UTC
  return { point_in_time: `${dateStr.value} ${timeStr.value}` }
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

const fmtWith = (a: any) => {
  const parts: string[] = []
  if (a.lane) parts.push(`Lane ${a.lane}`)
  if (a.team) parts.push(`Team ${String(a.team).padStart(2, '0')}`)
  if (a.table_1) parts.push(`T${a.table_1}${a.table_1_team ? `→${a.table_1_team}` : ''}`)
  if (a.table_2) parts.push(`T${a.table_2}${a.table_2_team ? `→${a.table_2_team}` : ''}`)
  return parts.length ? parts.join(' · ') : '—'
}

</script>

<template>
  <div class="space-y-4">
    <!-- Controls -->
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs text-gray-500 mb-1">Plan ID</label>
        <input v-model="planId" class="border rounded px-2 py-1 w-40" placeholder="z.B. 9255" />
      </div>

      <div class="flex items-center gap-2">
        <label class="text-sm">
          <input type="checkbox" v-model="usePoint" class="mr-1" />
          point_in_time setzen
        </label>
      </div>

      <div v-if="usePoint" class="flex items-end gap-3">
        <div>
          <label class="block text-xs text-gray-500 mb-1">Datum</label>
          <input type="date" v-model="dateStr" class="border rounded px-2 py-1" />
        </div>
        <div>
          <label class="block text-xs text-gray-500 mb-1">Uhrzeit (lokal)</label>
          <input type="time" v-model="timeStr" class="border rounded px-2 py-1" />
        </div>
      </div>
     <button @click="callNow" class="px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700">
          action_now()
      </button>
      

      <div class="flex items-end gap-2">
   
        <div>
        <label class="block text-xs text-gray-500 mb-1">Intervall (min)</label>
        <input type="number" min="1" v-model.number="intervalMin" class="border rounded px-2 py-1 w-24" />
      </div>
        <button @click="callNext" class="px-3 py-1 rounded bg-emerald-600 text-white hover:bg-emerald-700">
          action_next()
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
                src="@/assets/FLL_Explore.png"
                alt="Explore"
                class="w-6 h-6 flex-shrink-0"
              />
              <img
                v-else-if="g.group_meta?.first_program_id === 3"
                src="@/assets/FLL_Challenge.png"
                alt="Challenge"
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
              <!-- Zeile 1: Zeit groß, Name klein -->
              <div class="flex items-baseline justify-between gap-3">
                <div class="text-base font-semibold whitespace-nowrap">
                  {{ formatTimeOnly(a.start_time) }}–{{ formatTimeOnly(a.end_time) }}
                </div>
                <div class="text-xs text-gray-600 truncate">
                  {{ a.activity_name || a.meta?.name || ('Activity #' + a.activity_id) }}
                </div>
              </div>

              <!-- Zeile 2: Team/Ort genauso groß wie Zeit -->
              <div class="mt-0.5 text-base text-gray-700">
                {{ fmtWith(a) }}
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