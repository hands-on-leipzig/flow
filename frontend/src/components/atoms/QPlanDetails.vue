<script setup>
import { computed, ref, watch } from 'vue'
import axios from 'axios'

const props = defineProps({
  planId: {
    type: Number,
    required: true,
  },
})

const details = ref(null)
const loading = ref(true)
const error = ref(null)

const loadDetails = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get(`/quality/details-by-plan/${props.planId}`)
    details.value = response.data
  } catch (err) {
    console.error('Fehler beim Laden der Plan-Details', err)
    error.value = 'Fehler beim Laden der Details'
  } finally {
    loading.value = false
  }
}

watch(() => props.planId, loadDetails, { immediate: true })

const okIcon = (val) => (val == 1 || val === '1') ? '✓' : '⚠️'
const okClass = (val) => (val == 1 || val === '1') ? 'text-gray-300' : 'text-yellow-500 font-semibold'
const warnClass = (condition) => condition ? 'text-yellow-500 font-semibold' : 'text-gray-300'
const mismatchClass = (a, b) => a !== b ? 'text-red-500 font-semibold' : ''

const minRequiredTables = () => Math.min(3, details.value?.r_tables ?? 3)

const warnClassTables = (val) => val < minRequiredTables() ? 'text-yellow-500 font-semibold' : 'text-gray-300'
const iconTables = (val) => val < minRequiredTables() ? '⚠️' : '✓'

const formatTeam = (teamNum) => {
  // Format team display: Team 0 = '–' (volunteer/BYE), null/undefined = empty, others = number
  if (teamNum === null || teamNum === undefined) return ''
  if (teamNum === 0) return '–'
  return String(teamNum)
}

const matchPlanColumns = computed(() => {
  if (Array.isArray(details.value?.match_plan_rounds) && details.value.match_plan_rounds.length > 0) {
    return details.value.match_plan_rounds
  }

  return [
    { key: '0', label: 'Testrunde', matches: [] },
    { key: '1', label: 'Runde 1', matches: [] },
    { key: '2', label: 'Runde 2', matches: [] },
    { key: '3', label: 'Runde 3', matches: [] },
  ]
})

const transferRows = computed(() => {
  if (details.value?.is_two_day_event && Array.isArray(details.value?.transfer_summary)) {
    return details.value.transfer_summary
  }
  return details.value?.teams ?? []
})
</script>

<template>
  <div class="mt-2 border-t border-gray-300 pt-2">
    <div v-if="loading" class="text-sm text-gray-500">Lade Plan-Details …</div>
    <div v-else-if="error" class="text-sm text-red-500">{{ error }}</div>
    <div v-else>
      <div class="flex flex-row justify-between items-start gap-4">
        <!-- Linker Block: Timing -->
        <div class="basis-[25%] flex-shrink-0 overflow-x-auto">
          <div class="text-sm font-semibold text-gray-600 mb-1">Transfer</div>
          <table class="table-auto text-sm border-collapse">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-2 py-1 text-left">Team</th>
                <th class="px-2 py-1">Tr.</th>
                <template v-if="details.is_two_day_event">
                  <th class="px-2 py-1">T1 1→2</th>
                  <th class="px-2 py-1">T1 2→3</th>
                  <th class="px-2 py-1">T2 1→2</th>
                  <th class="px-2 py-1">T2 2→3</th>
                  <th class="px-2 py-1">T2 3→4</th>
                </template>
                <template v-else>
                  <th class="px-2 py-1">1→2</th>
                  <th class="px-2 py-1">2→3</th>
                  <th class="px-2 py-1">3→4</th>
                  <th class="px-2 py-1">4→5</th>
                </template>
                <th v-if="!details.is_two_day_event" class="px-2 py-1">Δ</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="team in transferRows" :key="team.id ?? team.team" class="border-t">
                <td class="px-2 py-1">{{ team.team }}</td>
                <td class="text-center">
                  <span :class="okClass(team.q1_ok)">{{ okIcon(team.q1_ok) }}</span>
                </td>
                <template v-if="details.is_two_day_event">
                  <td class="text-center" :class="team.day1_1_2 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">{{ team.day1_1_2 }}</td>
                  <td class="text-center" :class="team.day1_2_3 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">{{ team.day1_2_3 }}</td>
                  <td class="text-center" :class="team.day2_1_2 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">{{ team.day2_1_2 }}</td>
                  <td class="text-center" :class="team.day2_2_3 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">{{ team.day2_2_3 }}</td>
                  <td class="text-center" :class="team.day2_3_4 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">{{ team.day2_3_4 }}</td>
                </template>
                <template v-else>
                  <td class="text-center" :class="team.q1_transition_1_2 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">
                    {{ team.q1_transition_1_2 }}
                  </td>
                  <td class="text-center" :class="team.q1_transition_2_3 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">
                    {{ team.q1_transition_2_3 }}
                  </td>
                  <td class="text-center" :class="team.q1_transition_3_4 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">
                    {{ team.q1_transition_3_4 }}
                  </td>
                  <td class="text-center" :class="team.q1_transition_4_5 < details.c_duration_transfer ? 'text-red-500 font-semibold' : ''">
                    {{ team.q1_transition_4_5 }}
                  </td>
                </template>
                <td v-if="!details.is_two_day_event" class="text-center">{{ team.q5_idle_avg?.toFixed(2) ?? '–' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mittlerer Block: Tisch-Zuordnung -->
        <div class="basis-[30%] flex-shrink-0 overflow-x-auto">
          <div class="text-sm font-semibold text-gray-600 mb-1">Testrunde, Tische und Teams gegenüber</div>
          <table class="table-auto text-sm border-collapse">
            <thead class="bg-gray-100">
              <tr>
                <th class="px-2 py-1 text-left">Team</th>
                <th class="px-2 py-1">TR</th>
                <th class="px-2 py-1">R1</th>
                <th class="px-2 py-1">R2</th>
                <th class="px-2 py-1">R3</th>
                <th class="px-2 py-1">Tische</th>
                <th class="px-2 py-1">R1</th>
                <th class="px-2 py-1">R2</th>
                <th class="px-2 py-1">R3</th>
                <th class="px-2 py-1">Teams</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in details.match_summary" :key="row.team" class="border-t">
                <td class="px-2 py-1">{{ row.team }}</td>
                <td class="text-center" :class="mismatchClass(row.tr_table, row.r1_table)">
                  {{ row.tr_table ?? '–' }}
                </td>
                <td class="text-center" :class="mismatchClass(row.tr_table, row.r1_table)">
                  {{ row.r1_table ?? '–' }}
                </td>
                <td class="text-center">{{ row.r2_table ?? '–' }}</td>
                <td class="text-center">{{ row.r3_table ?? '–' }}</td>
                <td class="text-center">
                  <span :class="warnClassTables(row.tables)">
                    {{ iconTables(row.tables) }}
                  </span>
                  {{ row.tables ?? '–' }}
                </td>
                <td class="text-center">{{ row.r1_opponent ?? '–' }}</td>
                <td class="text-center">{{ row.r2_opponent ?? '–' }}</td>
                <td class="text-center">{{ row.r3_opponent ?? '–' }}</td>
                <td class="text-center">
                  <span :class="warnClass(row.teams < 3)">
                    {{ row.teams < 3 ? '⚠️' : '✓' }}
                  </span>
                  {{ row.teams ?? '–' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Rechter Block: Matchplan -->
        <div class="basis-[50%] flex-shrink-0 overflow-x-auto">
          <div class="text-sm font-semibold text-gray-600 mb-1">Matchplan</div>
          <div class="flex flex-row gap-4">
            <div
              v-for="col in matchPlanColumns"
              :key="`${col.key}-${col.label}`"
              class="min-w-max"
            >
              <div class="text-sm font-semibold text-gray-600 mb-1">
                {{ col.label }}
              </div>
              <table class="table-auto text-sm border-collapse">
                <thead class="bg-gray-100">
                  <tr>
                    <th class="px-2 py-1">T1</th>
                    <th class="px-2 py-1">T2</th>
                    <th class="px-2 py-1">T3</th>
                    <th class="px-2 py-1">T4</th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="match in col.matches"
                    :key="match.id"
                    class="border-t"
                  >
                    <td class="text-center">
                      <span v-if="match.table_1 === 1">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 1">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td class="text-center">
                      <span v-if="match.table_1 === 2">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 2">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td class="text-center">
                      <span v-if="match.table_1 === 3">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 3">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                    <td class="text-center">
                      <span v-if="match.table_1 === 4">{{ formatTeam(match.table_1_team) }}</span>
                      <span v-else-if="match.table_2 === 4">{{ formatTeam(match.table_2_team) }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>