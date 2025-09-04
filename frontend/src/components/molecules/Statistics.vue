<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

const data = ref(null)
const loading = ref(true)
const error = ref(null)

const selectedSeasonKey = ref(null)

onMounted(async () => {
  try {
    const res = await axios.get('/stats/plans')
    data.value = res.data

    // Erste Season automatisch auswählen
    if (data.value?.seasons?.length > 0) {
      const first = data.value.seasons[0]
      selectedSeasonKey.value = `${first.season_year}-${first.season_name}`
    }
  } catch (e) {
    error.value = 'Fehler beim Laden der Daten.'
    console.error(e)
  } finally {
    loading.value = false
  }
})

const filteredPlans = computed(() => {
  if (!data.value || !selectedSeasonKey.value) return []

  const season = data.value.seasons.find(
    s => `${s.season_year}-${s.season_name}` === selectedSeasonKey.value
  )
  if (!season) return []

  const rows = []

  for (const partner of season.partners) {
    for (const event of partner.events) {
      for (const plan of event.plans) {
        rows.push({
          partner_id: partner.partner_id,
          partner_name: partner.partner_name,
          event_id: event.event_id,
          event_name: event.event_name,
          event_date: event.event_date,
          plan_id: plan.plan_id,
          plan_created: plan.plan_created,
          plan_last_change: plan.plan_last_change
        })
      }
    }
  }

  return rows
})
</script>

<template>
  <div>
    <div v-if="loading" class="text-gray-500">Lade Daten …</div>
    <div v-else-if="error" class="text-red-500">{{ error }}</div>
    <div v-else>
      <!-- Season Filter -->
      <div class="mb-6">
        <div class="text-lg font-bold mb-2">Saison auswählen:</div>
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
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="row in filteredPlans"
            :key="`${row.partner_id}-${row.event_id}-${row.plan_id}`"
            class="border-t border-gray-200 hover:bg-gray-50"
          >
            <td class="px-3 py-2 text-gray-400">{{ row.partner_id }}</td>
            <td class="px-3 py-2">{{ row.partner_name }}</td>
            <td class="px-3 py-2 text-gray-400">{{ row.event_id }}</td>
            <td class="px-3 py-2">
              {{ row.event_name }} <span class="text-gray-500">({{ row.event_date }})</span>
            </td>
            <td class="px-3 py-2 text-gray-400">{{ row.plan_id }}</td>
            <td class="px-3 py-2">{{ row.plan_created }}</td>
            <td class="px-3 py-2">{{ row.plan_last_change }}</td>
          </tr>
        </tbody>
      </table>

      <div v-if="filteredPlans.length === 0" class="mt-4 text-gray-500 italic">
        Keine Pläne in dieser Saison.
      </div>
    </div>
  </div>
</template>