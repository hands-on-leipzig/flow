<script setup>
import { ref, onMounted, computed } from 'vue'
import axios from 'axios'

const data = ref(null)
const loading = ref(true)
const error = ref(null)
const selectedSeasonKey = ref(null)

onMounted(async () => {
  try {
    const res = await axios.get('/stats/plans')
    data.value = res.data
    if (data.value?.seasons?.length > 0) {
      const first = data.value.seasons[0]
      selectedSeasonKey.value = `${first.season_year}-${first.season_name}`
    }
  } catch (e) {
    error.value = 'Fehler beim Laden der Statistiken.'
    console.error(e)
  } finally {
    loading.value = false
  }
})

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
        plan_id: null,
        plan_created: null,
        plan_last_change: null,
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
          plan_id: null,
          plan_created: null,
          plan_last_change: null,
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
          plan_id: plan.plan_id,
          plan_created: plan.plan_created,
          plan_last_change: plan.plan_last_change,
          generator_stats: plan.generator_stats ?? null, // <-- HIER
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

function formatDateTime(datetimeString) {
  if (!datetimeString) return ''
  const date = new Date(datetimeString)
  if (isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(date)
}
function formatDateOnly(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  if (isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat(navigator.language, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  }).format(date)
}

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
            </template>
            <template v-else>
              &nbsp;
            </template>
          </td>

          <!-- Plan ID -->
          <td class="px-3 py-2 text-gray-400">{{ row.plan_id }}</td>

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