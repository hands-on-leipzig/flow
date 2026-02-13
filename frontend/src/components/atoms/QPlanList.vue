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
  3: ref(true),
  4: ref(true),
  5: ref(true),
  6: ref(true),
}

const filterTeamCount = {
  even: ref(true),
  odd: ref(true),
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

const filterRobotCheck = {
  1: ref(true),
  0: ref(true),
}

const filterAsym = {
  1: ref(true),
  0: ref(true),
}

const plans = computed(() => {
  return plansRaw.value.filter(plan => {
    // Q-Checks - filter for red (problematic) plans only
    const qFilterOk = [1, 2, 3, 4].every(q => {
      if (!filterQ[q].value) return true
      
      // Q1 and Q4: red if not all teams pass
      if (q === 1 || q === 4) {
        const ok = plan[`q${q}_ok_count`]
        return ok < plan.c_teams  // Show if red (some teams failed)
      }
      
      // Q2 (Tische): red based on distribution and table count
      if (q === 2) {
        const count1 = plan.q2_1_count ?? 0
        const isRed = count1 > 0  // Any team with only 1 table
        return isRed
      }
      
      // Q3 (Teams): red if any team has only 1 opponent
      if (q === 3) {
        const count1 = plan.q3_1_count ?? 0
        const isRed = count1 > 0
        return isRed
      }
      
      return true
    })

    // Team count (even/odd)
    const teamCountEven = plan.c_teams % 2 === 0
    const teamCountFilterOk = (filterTeamCount.even.value && teamCountEven) || 
                              (filterTeamCount.odd.value && !teamCountEven)

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

    // Robot-Check (An/Aus)
    const robotCheckActive = Object.entries(filterRobotCheck)
      .filter(([_, refVal]) => refVal.value)
      .map(([rc]) => Number(rc))
    const robotCheckFilterOk = robotCheckActive.length === 0 || robotCheckActive.includes(Number(plan.r_robot_check))

    // RG asym (Ja/Nein)
    const asymActive = Object.entries(filterAsym)
      .filter(([_, refVal]) => refVal.value)
      .map(([a]) => Number(a))
    const asymFilterOk = asymActive.length === 0 || asymActive.includes(Number(plan.r_asym))

    // Kombiniert
    return qFilterOk && teamCountFilterOk && laneFilterOk && roundFilterOk && tableFilterOk && robotCheckFilterOk && asymFilterOk
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

function ampelfarbeQ2(count1, count2, rTables) {
  // 2 tables: red if any team saw only 1 table, else green
  if (rTables === 2) {
    if (count1 > 0) return '🔴'
    return '🟢'
  }
  
  // 4 tables: red if _1 > 0, yellow if _2 > 0, else green
  if (rTables === 4) {
    if (count1 > 0) return '🔴'
    if (count2 > 0) return '🟡'
    return '🟢'
  }
  
  // Fallback for unknown table count
  return '⚪'
}

function ampelfarbeQ3(count1, count2) {
  // If any team has only 1 opponent: red
  if (count1 > 0) return '🔴'
  // Else if any team has 2 opponents: yellow
  if (count2 > 0) return '🟡'
  // Else all teams have 3 opponents: green
  return '🟢'
}

function formatDistribution(count1, count2, count3, scoreAvg) {
  // Format: "5-3-2 (77%)" meaning 5 with 3, 3 with 2, 2 with 1, avg 77%
  // Always show all three counts, even if 0: "10-0-0 (67%)"
  const count3Val = count3 ?? 0
  const count2Val = count2 ?? 0
  const count1Val = count1 ?? 0
  
  const distStr = `${count3Val}-${count2Val}-${count1Val}`
  const scoreStr = scoreAvg != null ? `(${scoreAvg.toFixed(0)}%)` : ''
  
  return `${distStr} ${scoreStr}`.trim()
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

function formatDuration(minutes) {
  if (minutes == null) return '–'
  const hours = Math.floor(minutes / 60)
  const mins = minutes % 60
  return `${hours}:${String(mins).padStart(2, '0')}`
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
      
      <!-- Filter & Button nebeneinander -->
      <div class="flex justify-between items-start mb-2 gap-4">

        <!-- Linker Teil: Filter-Kisten -->
        <div class="flex flex-wrap gap-2">

          <!-- Filter-Kiste: Team-Anzahl (Gerade/Ungerade) -->
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            
            <!-- Label-Teil -->
            <div class="text-sm font-medium text-gray-700">
              Anzahl:
            </div>

            <!-- Checkboxen -->
            <div class="flex items-center gap-2 ml-3">
              <label class="flex items-center gap-1 text-sm text-gray-600">
                <input type="checkbox" v-model="filterTeamCount.even.value" class="accent-gray-600" />
                Gerade
              </label>
              <label class="flex items-center gap-1 text-sm text-gray-600">
                <input type="checkbox" v-model="filterTeamCount.odd.value" class="accent-gray-600" />
                Ungerade
              </label>
            </div>

          </div>

          <!-- Filter-Kiste: Jury-Spuren -->
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            
            <!-- Label-Teil -->
            <div class="text-sm font-medium text-gray-700">
              Spuren:
            </div>

            <!-- Checkboxen -->
            <div class="flex items-center gap-2 ml-3">
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
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            
            <!-- Label-Teil -->
            <div class="text-sm font-medium text-gray-700">
              Tische:
            </div>

            <!-- Checkboxen -->
            <div class="flex items-center gap-2 ml-3">
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

          <!-- Filter-Kiste: Jury-Runden -->
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            
            <!-- Label-Teil -->
            <div class="text-sm font-medium text-gray-700">
              Runden:
            </div>

            <!-- Checkboxen -->
            <div class="flex items-center gap-2 ml-3">
              <label
                v-for="round in [3,4,5,6]"
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

          <!-- Filter-Kiste: RG asym -->
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            <div class="text-sm font-medium text-gray-700">RG asym:</div>
            <div class="flex items-center gap-2 ml-3">
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

          <!-- Filter-Kiste: Robot-Check -->
          <div class="border border-gray-300 rounded-md px-2 py-2 bg-white shadow-sm flex justify-between items-center">
            
            <!-- Label-Teil -->
            <div class="text-sm font-medium text-gray-700">
              Check:
            </div>

            <!-- Checkboxen -->
            <div class="flex items-center gap-2 ml-3">
              <label class="flex items-center gap-1 text-sm text-gray-600">
                <input type="checkbox" v-model="filterRobotCheck[0].value" class="accent-gray-600" />
                Aus
              </label>
              <label class="flex items-center gap-1 text-sm text-gray-600">
                <input type="checkbox" v-model="filterRobotCheck[1].value" class="accent-gray-600" />
                An
              </label>
            </div>

          </div>
        </div>
      
        <!-- Button nur wenn Pläne vorhanden -->
        <div v-if="plans.length > 0" class="flex justify-between items-center mb-2">

          <!-- Button rechts außen -->
          <button
            @click.stop="startRerun"
            class="text-sm text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded"
            title="Neuen QRun mit diesen Plänen starten"
          >
            🔁 ReRun für {{ plans.length }} Pläne
          </button>
        </div>

      </div>
      <!-- Tabellenkopf -->
      <div class="grid grid-cols-13 text-xs font-semibold text-gray-700 uppercase tracking-wider py-1 border-b border-gray-300">
        <div>Plan</div>
        <div>Teams</div>
        <div>Spuren</div>
        <div>RG-Tische</div>
        <div>Runden</div>
        <div>RG asym</div>
        <div>Robot check</div>
        <div>Dauer</div>
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
          class="grid grid-cols-13 text-sm py-1 hover:bg-gray-50 cursor-pointer items-center"
          @click="toggleExpanded(qplan.id)"
        >
          <div class="flex items-center gap-2">
            <template v-if="qplan.plan && qplan.plan !== 0">
              <span>{{ qplan.plan }}</span>
              <button
                @click.stop="openPreview(qplan.plan)"
                class="text-blue-600 hover:text-blue-800"
                title="Vorschau öffnen"
              >
                🧾
              </button>
            </template>
            <template v-else>
              <span>---</span>
            </template>
          </div>
          <div>{{ qplan.c_teams }}</div>
          <div>{{ qplan.j_lanes }}</div>
          <div>{{ qplan.r_tables }}</div>
          <div>{{ qplan.j_rounds }}</div>
          <div>{{ qplan.r_asym ? 'Ja' : 'Nein' }}</div>
          <div>{{ qplan.r_robot_check ? 'An' : 'Aus' }}</div>
          
          <!-- Q6: Dauer -->
          <div>{{ formatDuration(qplan.q6_duration) }}</div>

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
            <span>{{ ampelfarbeQ2(qplan.q2_1_count, qplan.q2_2_count, qplan.r_tables) }}</span>
            <span class="text-xs">{{ formatDistribution(qplan.q2_1_count, qplan.q2_2_count, qplan.q2_3_count, qplan.q2_score_avg) }}</span>
          </div>

          <!-- Q3: Teams -->
          <div class="flex items-center gap-1">
            <span>{{ ampelfarbeQ3(qplan.q3_1_count, qplan.q3_2_count) }}</span>
            <span class="text-xs">{{ formatDistribution(qplan.q3_1_count, qplan.q3_2_count, qplan.q3_3_count, qplan.q3_score_avg) }}</span>
          </div>

          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Idle(qplan.q5_idle_avg, qplan.c_teams) }"></div>
            <span class="flex items-center gap-1">{{ qplan.q5_idle_avg ? qplan.q5_idle_avg.toFixed(2) : '–' }}</span>
            <div class="w-3 h-3 rounded-sm" :style="{ backgroundColor: farbeQ5Stddev(qplan.q5_idle_stddev) }"></div>
            <span class="flex items-center gap-1">{{ qplan.q5_idle_stddev ? qplan.q5_idle_stddev.toFixed(2) : '–' }}</span>
          </div>
        </div>

        <!-- Akkordeon für Details -->
        <div v-if="expandedPlanId === qplan.id" class="bg-gray-50 px-2 py-1 border-t border-gray-200">
          <QPlanDetails :planId="qplan.plan || 0" />
        </div>
      </div>
    </div>
  </div>
</template>