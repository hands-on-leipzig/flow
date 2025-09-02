<script setup>
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import QPlanDetails from '@/components/atoms/QPlanDetails.vue'

const props = defineProps({
  qrun: {
    type: Number,
    required: true,
  },
})

const plansRaw = ref([])
const loading = ref(true)
const error = ref(null)
const expandedPlanId = ref(null)

const filterQ = {
  1: ref(false),
  2: ref(false),
  3: ref(false),
  4: ref(false),
}

const filterRounds = {
  4: ref(true),
  5: ref(true),
  6: ref(true),
}

const filterLanes = {
  1: ref(true),
  2: ref(true),
  3: ref(true),
  4: ref(true),
  5: ref(true),
}

const filterTables = {
  2: ref(true),
  4: ref(true),
}

const filterAsym = {
  1: ref(true),
  0: ref(true),
}

const plans = computed(() => {
  return plansRaw.value.filter(plan => {
    // Q-Checks
    const qFilterOk = [1, 2, 3, 4].every(q => {
      if (!filterQ[q].value) return true
      const ok = plan[`q${q}_ok_count`]
      return ok < plan.c_teams
    })

    // Jury-Spuren
    const lanesActive = Object.entries(filterLanes)
      .filter(([_, refVal]) => refVal.value)
      .map(([lane]) => Number(lane))
    const laneFilterOk = lanesActive.length === 0 || lanesActive.includes(plan.j_lanes)

    // Jury-Runden
    const roundsActive = Object.entries(filterRounds)
      .filter(([_, refVal]) => refVal.value)
      .map(([r]) => Number(r))
    const roundFilterOk = roundsActive.length === 0 || roundsActive.includes(plan.j_rounds)

    // RG-Tische
    const tablesActive = Object.entries(filterTables)
      .filter(([_, refVal]) => refVal.value)
      .map(([t]) => Number(t))
    const tableFilterOk = tablesActive.length === 0 || tablesActive.includes(plan.r_tables)

    // RG asym (Ja/Nein)
    const asymActive = Object.entries(filterAsym)
      .filter(([_, refVal]) => refVal.value)
      .map(([a]) => Number(a))
    const asymFilterOk = asymActive.length === 0 || asymActive.includes(plan.r_asym)

    // Kombiniert
    return qFilterOk && laneFilterOk && roundFilterOk && tableFilterOk && asymFilterOk
  })
})
const loadPlans = async () => {
  loading.value = true
  error.value = null
  try {
    const response = await axios.get(`/quality/qplans/${props.qrun}`)
    plansRaw.value = response.data
  } catch (err) {
    console.error('Fehler beim Laden der QPlans', err)
    error.value = 'Fehler beim Laden der Pläne'
  } finally {
    loading.value = false
  }
}

watch(() => props.qrun, loadPlans, { immediate: true })

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

function openPreview(planId) {
  window.open(`/preview/${planId}`, '_blank', 'noopener')
}

const emit = defineEmits(['refreshParent'])

async function startRerun() {
  const ids = plans.value.map(p => p.id)
  try {
    const response = axios.post('/quality/rerun', { plan_ids: ids })
    console.log('ReRun erfolgreich:', response.data)
    emit('refreshParent')
    // Optional: Erfolgsmeldung, Redirect etc.
  } catch (err) {
    console.error('Fehler beim ReRun:', err)
    alert('Fehler beim Starten des ReRuns.')
  }
}


</script>

<template>
  <div class="ml-4 mt-2 border-l-2 border-gray-300 pl-4">

    <div v-if="loading" class="text-gray-500 text-sm">Lade QPläne …</div>
    <div v-else-if="error" class="text-red-500 text-sm">{{ error }}</div>
    <div v-else>
      
      <!-- Button nur wenn Pläne vorhanden -->
      <div v-if="plans.length > 0" class="flex justify-between items-center mb-2">

      <!-- Filter-Kiste: Jury-Runden -->
      <div class="border border-gray-300 rounded-md p-3 bg-white shadow-sm flex justify-between items-center mb-2">
        
        <!-- Label-Teil -->
        <div class="text-sm font-medium text-gray-700">
          Jury-Runden:
        </div>

        <!-- Checkboxen -->
        <div class="flex items-center gap-3 ml-4">
          <label
            v-for="round in [4,5,6]"
            :key="round"
            class="flex items-center gap-1 text-sm text-gray-600"
          >
            <input
              type="checkbox"
              v-model="filterRounds[round].value"
              class="accent-gray-600"
            />
            {{ round }}
          </label>
        </div>

      </div>


      <!-- Filter-Kiste: Jury-Spuren -->
      <div class="border border-gray-300 rounded-md p-3 bg-white shadow-sm flex justify-between items-center mb-2">
        
        <!-- Label-Teil -->
        <div class="text-sm font-medium text-gray-700">
          Jury-Spuren:
        </div>

        <!-- Checkboxen -->
        <div class="flex items-center gap-3 ml-4">
          <label
            v-for="lane in [1,2,3,4,5]"
            :key="lane"
            class="flex items-center gap-1 text-sm text-gray-600"
          >
            <input
              type="checkbox"
              v-model="filterLanes[lane].value"
              class="accent-gray-600"
            />
            {{ lane }}
          </label>
        </div>

      </div>

      <!-- Filter-Kiste: RG-Tische -->
      <div class="border border-gray-300 rounded-md p-3 bg-white shadow-sm flex justify-between items-center mb-2">
        
        <!-- Label-Teil -->
        <div class="text-sm font-medium text-gray-700">
          RG-Tische:
        </div>

        <!-- Checkboxen -->
        <div class="flex items-center gap-3 ml-4">
          <label
            v-for="t in [2, 4]"
            :key="t"
            class="flex items-center gap-1 text-sm text-gray-600"
          >
            <input
              type="checkbox"
              v-model="filterTables[t].value"
              class="accent-gray-600"
            />
            {{ t }}
          </label>
        </div>

      </div>

      <!-- Filter-Kiste: RG asym -->
      <div class="border border-gray-300 rounded-md p-3 bg-white shadow-sm flex justify-between items-center mb-2">
        <div class="text-sm text-gray-600 mr-6">RG asym:</div>
        <div class="flex items-center gap-4">
          <label class="flex items-center gap-1 text-sm text-gray-600">
            <input type="checkbox" v-model="filterAsym[1].value" class="accent-gray-600" />
            Ja
          </label>
          <label class="flex items-center gap-1 text-sm text-gray-600">
            <input type="checkbox" v-model="filterAsym[0].value" class="accent-gray-600" />
            Nein
          </label>
        </div>
      </div>

      <!-- Button rechts außen -->
      <button
        @click.stop="startRerun"
        class="text-sm text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded"
        title="Neuen QRun mit diesen Plänen starten"
      >
        🔁 ReRun für {{ plans.length }} Pläne
      </button>
</div>

      <!-- Tabellenkopf -->
      <div class="grid grid-cols-8 text-xs font-semibold text-gray-700 uppercase tracking-wider py-1 border-b border-gray-300">
        <div>Plan</div>
        <div>Name</div>
        <div>Teamanzahl</div>
        <div class="flex items-center gap-1">
          <input
            type="checkbox"
            v-model="filterQ[1].value"
            class="accent-gray-600"
            title="Nur QPläne anzeigen, bei denen Q1 nicht ok ist"
          />
          <span>Transfer</span>
        </div>
        <div class="flex items-center gap-1">  
          <input
            type="checkbox"
            v-model="filterQ[4].value"
            class="accent-gray-600"
            title="Nur QPläne anzeigen, bei denen Q4 nicht ok ist"
          />
          <span>Testrunde</span>
        </div>
        <div class="flex items-center gap-1">
          <input
            type="checkbox"
            v-model="filterQ[2].value"
            class="accent-gray-600"
            title="Nur QPläne anzeigen, bei denen Q2 nicht ok ist"
          />
          <span>Tische</span>
        </div>
        <div class="flex items-center gap-1">
          <input
            type="checkbox"
            v-model="filterQ[3].value"
            class="accent-gray-600"
            title="Nur QPläne anzeigen, bei denen Q3 nicht ok ist"
          />
          <span>Teams</span>
        </div>
        <div>Abstand</div>
      </div>

    <!-- Kein Plan -->
      <div v-if="plans.length === 0" class="text-gray-400 text-sm">Keine passende QPläne gefunden.</div>  
    
      <!-- QPlan-Zeilen -->
      <div
        v-for="qplan in plans"
        :key="qplan.id"
        class="border-b border-gray-100"
      >
        <div
          class="grid grid-cols-8 text-sm py-1 hover:bg-gray-50 cursor-pointer items-center"
          @click="toggleExpanded(qplan.id)"
        >
          <div class="flex items-center gap-2">
            <span>{{ qplan.plan }}</span>
            <button
              @click.stop="openPreview(qplan.plan)"
              class="text-blue-600 hover:text-blue-800"
              title="Vorschau öffnen"
            >
              🧾 
            </button>
          </div>
          <div class="flex items-center gap-2">
            <span>{{ qplan.name || `#${qplan.plan}` }}</span>
          </div>

          <div>{{ qplan.c_teams }}</div>

          <!-- Q1: Transfer -->
          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ1Q4(qplan.q1_ok_count, qplan.c_teams) }}</span>
            <span>{{ qplan.q1_ok_count ?? '–' }}</span>
          </div>

          <!-- Q4: Testrunde -->
          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ1Q4(qplan.q4_ok_count, qplan.c_teams) }}</span>
            <span>{{ qplan.q4_ok_count ?? '–' }}</span>
          </div>

          <!-- Q2: Tische -->
          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ2Q3(qplan.q2_ok_count, qplan.c_teams, qplan.j_lanes) }}</span>
            <span>{{ qplan.q2_ok_count ?? '–' }}</span>
          </div>

          <!-- Q3: Teams -->
          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ2Q3(qplan.q3_ok_count, qplan.c_teams, qplan.j_lanes) }}</span>
            <span>{{ qplan.q3_ok_count ?? '–' }}</span>
          </div>

          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Idle(qplan.q5_idle_avg, qplan.c_teams) }"></div>
            <span class="flex items-center gap-1">{{ qplan.q5_idle_avg?.toFixed(2) ?? '–' }}</span>
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Stddev(qplan.q5_idle_stddev) }"></div>
            <span class="flex items-center gap-1">{{ qplan.q5_idle_stddev?.toFixed(2) ?? '–' }}</span>
          </div>
        </div>

        <!-- Akkordeon für Details -->
        <div v-if="expandedPlanId === qplan.id" class="bg-gray-50 px-2 py-1 border-t border-gray-200">
          <QPlanDetails :planId="qplan.id" />
        </div>
      </div>
    </div>
  </div>
</template>