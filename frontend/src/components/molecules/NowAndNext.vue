<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'

// Inputs
const planId = ref('')
const usePoint = ref(false)
const dateStr = ref('')   // YYYY-MM-DD
const timeStr = ref('')   // HH:mm
const intervalMin = ref(15)

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
    error.value = 'Fehler beim Abruf (NOW).'
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
    error.value = 'Fehler beim Abruf (NEXT).'
  } finally {
    loading.value = false
  }
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
          <label class="block text-xs text-gray-500 mb-1">Uhrzeit</label>
          <input type="time" v-model="timeStr" class="border rounded px-2 py-1" />
        </div>
      </div>

      

      <div class="flex items-end gap-2">
        <button @click="callNow" class="px-3 py-1 rounded bg-blue-600 text-white hover:bg-blue-700">
          action_now()
        </button>
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
      <div class="text-xs text-gray-500 mb-2">
        Pivot: <code>{{ result.pivot_time_utc }}</code>
        <template v-if="result.window_utc"> | Fenster: <code>{{ result.window_utc.from }}</code> → <code>{{ result.window_utc.to }}</code></template>
      </div>

      <table class="min-w-full text-sm border border-gray-300 bg-white">
        <thead class="bg-gray-100">
          <tr>
            <th class="px-2 py-1 text-left">Group</th>
            <th class="px-2 py-1 text-left">Start</th>
            <th class="px-2 py-1 text-left">Ende</th>
            <th class="px-2 py-1 text-left">Programm</th>
            <th class="px-2 py-1 text-left">Name</th>
            <th class="px-2 py-1 text-left">Mit wem</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="g in result.groups" :key="g.activity_group_id">
            <tr v-for="a in g.activities" :key="a.activity_id" class="border-t">
              <td class="px-2 py-1">{{ g.activity_group_id }}</td>
              <td class="px-2 py-1">{{ a.start_time }}</td>
              <td class="px-2 py-1">{{ a.end_time }}</td>
              <td class="px-2 py-1">{{ a.program || '—' }}</td>
              <td class="px-2 py-1">{{ a.activity_name }}</td>
              <td class="px-2 py-1">{{ a.with || '—' }}</td>
            </tr>
          </template>
          <tr v-if="!result.groups || result.groups.length === 0">
            <td colspan="6" class="px-2 py-3 text-center text-gray-500">Keine passenden Aktivitäten.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>