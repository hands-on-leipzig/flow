<script setup>
import { ref, watch } from 'vue'
import axios from 'axios'
import QRunCard from '@/components/atoms/QRunCard.vue'

const props = defineProps({
  runId: {
    type: Number,
    required: true,
  },
})

const plans = ref([])
const loading = ref(true)
const error = ref(null)
const expandedPlanId = ref(null)

const loadPlans = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get(`/quality/plans/${props.runId}`)
    plans.value = response.data
  } catch (err) {
    console.error('Fehler beim Laden der QPlans', err)
    error.value = 'Fehler beim Laden der Pläne'
  } finally {
    loading.value = false
  }
}

watch(() => props.runId, loadPlans, { immediate: true })

function ampelfarbeQ1Q4(ok, teams) {
  return ok === teams ? '🟢' : '🔴'
}

function ampelfarbeQ2Q3(ok, teams, lanes) {
  if (ok === teams) return '🟢'
  if (ok > teams - lanes) return '🟡'
  return '🔴'
}

function farbeQ5Idle(avg, teams) {
  const max = (teams - 1) / 2
  const ratio = Math.min(Math.max(avg / max, 0), 1)
  const r = Math.round(255 * (1 - ratio))
  const g = Math.round(255 * ratio)
  return `rgb(${r},${g},0)`
}

function farbeQ5Stddev(stddev) {
  const ratio = Math.min(stddev / 2.0, 1)
  const r = Math.round(255 * ratio)
  const g = Math.round(255 * (1 - ratio))
  return `rgb(${r},${g},0)`
}

function toggleExpanded(planId) {
  expandedPlanId.value = expandedPlanId.value === planId ? null : planId
}
</script>

<template>
  <div class="ml-4 mt-2 border-l-2 border-gray-300 pl-4">
    <div v-if="loading" class="text-gray-500 text-sm">Lade Pläne …</div>
    <div v-else-if="error" class="text-red-500 text-sm">{{ error }}</div>
    <div v-else-if="plans.length === 0" class="text-gray-400 text-sm">Keine Pläne gefunden.</div>
    <div v-else>
      <!-- Tabellenkopf -->
      <div class="grid grid-cols-7 text-xs font-semibold text-gray-700 uppercase tracking-wider py-1 border-b border-gray-300">
        <div>Name</div>
        <div>Teamanzahl</div>
        <div>Q1 Transfer</div>
        <div>Q2 Tische</div>
        <div>Q3 Teams</div>
        <div>Q4 Testrunde</div>
        <div>Q5 Abstand</div>
      </div>

      <!-- Datenzeilen -->
      <div
        v-for="plan in plans"
        :key="plan.id"
        class="border-b border-gray-100"
      >
        <!-- Plan-Zeile -->
        <div
          class="grid grid-cols-7 text-sm py-1 hover:bg-gray-50 cursor-pointer items-center"
          @click="toggleExpanded(plan.id)"
        >
          <div class="flex items-center gap-1">{{ plan.name || `#${plan.id}` }}</div>
          <div>{{ plan.c_teams }}</div>

          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ1Q4(plan.q1_ok_count, plan.c_teams) }}</span>
            <span>{{ plan.q1_ok_count ?? '–' }}</span>
          </div>

          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ2Q3(plan.q2_ok_count, plan.c_teams, plan.j_lanes) }}</span>
            <span>{{ plan.q2_ok_count ?? '–' }}</span>
          </div>

          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ2Q3(plan.q3_ok_count, plan.c_teams, plan.j_lanes) }}</span>
            <span>{{ plan.q3_ok_count ?? '–' }}</span>
          </div>

          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ1Q4(plan.q4_ok_count, plan.c_teams) }}</span>
            <span>{{ plan.q4_ok_count ?? '–' }}</span>
          </div>

          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Idle(plan.q5_idle_avg, plan.c_teams) }"></div>
            <span class="flex items-center gap-1">{{ plan.q5_idle_avg?.toFixed(2) ?? '–' }}</span>
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Stddev(plan.q5_idle_stddev) }"></div>
            <span class="flex items-center gap-1">{{ plan.q5_idle_stddev?.toFixed(2) ?? '–' }}</span>
          </div>
        </div>

        <!-- Akkordeon für Details -->
        <div v-if="expandedPlanId === plan.id" class="bg-gray-50 px-2 py-1 border-t border-gray-200">
          <QRunCard :planId="plan.id" />
        </div>
      </div>
    </div>
  </div>
</template>