<script setup>
import draggable from 'vuedraggable'
import {computed, toRef, ref, watch, onMounted} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import IconDraggable from "@/components/icons/IconDraggable.vue";
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import SavingToast from "@/components/atoms/SavingToast.vue"

const props = defineProps({
  program: {type: String, required: true}, // 'explore' or 'challenge'
  remoteTeams: {type: Array, default: () => []},
})

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const localTeams = ref([])
const teamList = ref([])
const teamsDiffer = ref(false)
const showDiffModal = ref(false)
// No background color needed - using subtle grey instead

const savingToast = ref(null)

const ignoredTeamNumbers = ref(new Set())

// Plan parameter values for display
const planParams = ref({
  c_teams: 0,
  e_teams: 0
})

watch(() => props.teams, (newVal) => {
  teamList.value = [...newVal]
})

const onSort = async () => {
  // Only sort real teams (not placeholders)
  const realTeams = teamList.value.filter(t => !t.isPlaceholder && !t.beyondCapacity)
  const payload = realTeams.map((team, index) => ({
    team_id: team.id,
    order: index + 1
  }))

  savingToast?.value?.show()

  try {
    await axios.post(`/events/${event.value?.id}/teams/update-order`, {
      program: props.program,
      order: payload
    })
    // Refresh discrepancy status after team reordering
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Order update failed', e)
    }
  }
}

const updateTeamName = async (team) => {
  savingToast?.value?.show()
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      number: team.number,
      name: team.name,
    })
    // Refresh discrepancy status after team update
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Failed to update team name for ${team.id}`, e)
    }
  }
}

const updateTeamNoshow = async (team) => {
  savingToast?.value?.show()
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      noshow: team.noshow ? 1 : 0,
    })
    // Refresh discrepancy status after team update
    await eventStore.updateTeamDiscrepancyStatus()
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Failed to update team noshow for ${team.id}`, e)
    }
  }
}

const mergedTeams = computed(() => {
  const result = []
  const processedLocalIds = new Set()
  const processedDrahtIds = new Set()

  // Normalize team numbers for comparison (handle null, undefined, strings, 0)
  const normalizeTeamNumber = (num) => {
    if (num == null || num === '' || num === 0) return null
    const normalized = Number(num)
    return isNaN(normalized) || normalized === 0 ? null : normalized
  }

  // Step 1: Match teams by team_number_hot (when both have valid numbers)
  const localMapByNumber = new Map()
  const drahtMapByNumber = new Map()

  localTeams.value.forEach(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) {
      localMapByNumber.set(num, t)
    }
  })

  props.remoteTeams.forEach(t => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) {
      drahtMapByNumber.set(num, t)
    }
  })

  // Collect all valid team numbers
  const allNumbers = new Set()
  localTeams.value.forEach(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    if (num != null) allNumbers.add(num)
  })
  props.remoteTeams.forEach(t => {
    const num = normalizeTeamNumber(t.number)
    if (num != null) allNumbers.add(num)
  })

  // Match by number
  allNumbers.forEach(number => {
    const local = localMapByNumber.get(number)
    const draht = drahtMapByNumber.get(number)

    let status = 'match'
    if (ignoredTeamNumbers.value.has(number)) {
      status = 'ignored'
    } else if (local && draht) {
      status = local.name !== draht.name ? 'conflict' : 'match'
    } else if (draht && !local) {
      status = 'new'
    } else if (local && !draht) {
      status = 'missing'
    }

    if (local) processedLocalIds.add(local.id)
    if (draht) processedDrahtIds.add(draht.id)

    result.push({number, local, draht, status})
  })

  // Step 2: Match teams without team_number_hot by name
  const localWithoutNumber = localTeams.value.filter(t => {
    const num = normalizeTeamNumber(t.team_number_hot)
    return num == null && !processedLocalIds.has(t.id)
  })

  const drahtWithoutNumber = props.remoteTeams.filter(t => {
    const num = normalizeTeamNumber(t.number)
    return num == null && !processedDrahtIds.has(t.id)
  })

  // Match by name for teams without numbers
  drahtWithoutNumber.forEach(draht => {
    const matchingLocal = localWithoutNumber.find(local =>
        local.name === draht.name && !processedLocalIds.has(local.id)
    )

    if (matchingLocal) {
      processedLocalIds.add(matchingLocal.id)
      processedDrahtIds.add(draht.id)
      result.push({
        number: null,
        local: matchingLocal,
        draht: draht,
        status: matchingLocal.name !== draht.name ? 'conflict' : 'match'
      })
    } else {
      processedDrahtIds.add(draht.id)
      result.push({
        number: null,
        local: null,
        draht: draht,
        status: 'new'
      })
    }
  })

  // Add any remaining local teams without numbers or matches
  localWithoutNumber.forEach(local => {
    if (!processedLocalIds.has(local.id)) {
      processedLocalIds.add(local.id)
      result.push({
        number: null,
        local: local,
        draht: null,
        status: 'missing'
      })
    }
  })

  return result
})

const statusLabels = {
  match: '✔ Identisch',
  conflict: '⚠ Unterschied',
  new: '➕ Nur angemeldet',
  missing: '❌ Nur in FLOW'
}

const applyDrahtTeam = async (team) => {
  if (!team.draht) {
    if (import.meta.env.DEV) {
      console.error('Cannot apply team: draht data is missing', team)
    }
    return
  }

  // Validate that team number exists (required field)
  // In Teams.vue, we map DRAHT's 'ref' field to 'number' field
  // Note: ref can be 0, which is a valid team number, so we check for null/undefined only
  const teamNumberHot = team.draht.number ?? team.number ?? null
  if (teamNumberHot == null) {
    alert('Fehler: Team-Nummer ist erforderlich. Das Team in DRAHT hat keine gültige "ref" (Team-Nummer).')
    return
  }

  try {
    const response = await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.local?.id, // null for new teams (triggers create)
      team_number_hot: teamNumberHot,
      name: team.draht.name,
      event: event.value.id,
      first_program: props.program,
      location: team.draht.location || null,
      organization: team.draht.organization || null,
    })

    // Refresh teams from server to get the updated/created team with correct ID
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Refresh discrepancy status
    await eventStore.updateTeamDiscrepancyStatus()

    team.status = 'match'

    const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
    if (!hasRemainingDiffs) {
      showDiffModal.value = false
    }
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Fehler beim Übernehmen von Team ${team.number || team.draht.name}`, e)
    }
    alert('Fehler beim Übernehmen des Teams: ' + (e.response?.data?.message || e.message))
  }
}

const deleteTeam = async (team) => {
  if (!team.local?.id) {
    if (import.meta.env.DEV) {
      console.error('Cannot delete team: team ID is missing', team)
    }
    return
  }

  if (!confirm(`Möchtest du das Team "${team.local.name}" wirklich löschen?`)) {
    return
  }

  try {
    savingToast?.value?.show()
    await axios.delete(`/teams/${team.local.id}`)

    // Refresh teams from server
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Refresh discrepancy status
    await eventStore.updateTeamDiscrepancyStatus()

    const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
    if (!hasRemainingDiffs) {
      showDiffModal.value = false
    }
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error(`Fehler beim Löschen von Team ${team.local.name}`, e)
    }
    alert('Fehler beim Löschen des Teams: ' + (e.response?.data?.message || e.message))
  } finally {
    savingToast?.value?.hide()
  }
}

const ignoreDiff = (team) => {
  // Mark as resolved but not updated
  ignoredTeamNumbers.value.add(team.number)

  const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
  if (!hasRemainingDiffs) {
    showDiffModal.value = false
  }
}

const showSyncPrompt = computed(() =>
    mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
)

// Computed: Get plan capacity for current program
const planCapacity = computed(() => {
  return props.program === 'explore' ? planParams.value.e_teams : planParams.value.c_teams
})

// Computed: Get enrolled count for current program
const enrolledCount = computed(() => {
  return props.program === 'explore' 
    ? (event.value?.drahtTeamsExplore || 0) 
    : (event.value?.drahtTeamsChallenge || 0)
})

// Computed: Get placeholder rows if plan > enrolled
const placeholderRows = computed(() => {
  const capacity = planCapacity.value
  const enrolled = enrolledCount.value
  const currentTeams = teamList.value.length
  
  // If plan has more teams than enrolled, add empty rows to fill up to plan capacity
  if (capacity > enrolled) {
    const count = Math.max(0, capacity - currentTeams)
    return Array(count).fill(null).map((_, idx) => ({
      id: `empty-${currentTeams + idx}`,
      index: currentTeams + idx + 1 // 1-based index for display
    }))
  }
  return []
})

// Computed: Check if any teams are beyond capacity
const teamsBeyondCapacity = computed(() => {
  const capacity = planCapacity.value
  const currentTeams = teamList.value.length
  return currentTeams > capacity
})

onMounted(async () => {
  try {
    // Fetch plan parameters
    try {
      const planRes = await axios.get(`/plans/public/${event.value?.id}`)
      const planId = planRes.data?.id
      if (planId) {
        const paramsRes = await axios.get(`/plans/${planId}/parameters`)
        const params = Array.isArray(paramsRes.data) ? paramsRes.data : []
        planParams.value = {
          c_teams: Number(params.find(p => p.name === 'c_teams')?.value || 0),
          e_teams: Number(params.find(p => p.name === 'e_teams')?.value || 0)
        }
      }
    } catch (paramErr) {
      if (import.meta.env.DEV) {
        console.debug('Failed to fetch plan parameters', paramErr)
      }
    }

    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}&sort=plan_order`)
    // Handle both array format and object format (for Explore teams with metadata)
    const teamsArray = Array.isArray(dbRes.data) ? dbRes.data : (dbRes.data.teams || [])
    // Normalize noshow values to boolean (handle null, 0, 1, true, false)
    localTeams.value = teamsArray.map(team => ({
      ...team,
      noshow: team.noshow === 1 || team.noshow === true || team.noshow === '1'
    }))
    teamList.value = [...localTeams.value]

    // Teams loaded successfully

    teamList.value = [...localTeams.value]
    teamsDiffer.value = JSON.stringify(localTeams.value) !== JSON.stringify(props.remoteTeams)
  } catch (err) {
    if (import.meta.env.DEV) {
      console.error('Failed to fetch teams', err)
    }
  }
})
</script>

<template>
  <SavingToast ref="savingToast" message="Änderungen werden gespeichert..."/>

  <div class="overflow-y-auto max-h-[80vh] lg:max-h-none mx-4">
    <div class="p-4 border rounded shadow">
      <div class="flex items-center gap-2 mb-2">
        <img
            :src="programLogoSrc(program)"
            :alt="programLogoAlt(program)"
            class="w-10 h-10 flex-shrink-0"
        />
        <div>
          <h3 class="text-lg font-semibold capitalize">
            <span class="italic">FIRST</span> LEGO League {{ program }}
          </h3>
          <div class="text-sm text-gray-500">
            <span>
              <span :class="planCapacity !== enrolledCount ? 'bg-yellow-100 px-1 rounded text-red-800' : ''">Plan für: {{ program === 'explore' ? planParams.e_teams : planParams.c_teams }}</span>, <span :class="planCapacity !== enrolledCount ? 'bg-yellow-100 px-1 rounded text-red-800' : ''">Angemeldet: {{ program === 'explore' ? event?.drahtTeamsExplore || 0 : event?.drahtTeamsChallenge || 0 }}</span>, Kapazität: {{
                program === 'explore' ? event?.drahtCapacityExplore || 0 : event?.drahtCapacityChallenge || 0
              }}
            </span>
          </div>
        </div>
      </div>
      <div v-if="showSyncPrompt" class="mb-2 p-2 bg-yellow-100 border border-yellow-300 text-red-800 rounded">
        Die Daten in FLOW weichen von denen der Anmeldung ab.
        <button class="text-sm text-red-700" @click="showDiffModal = !showDiffModal">
          Unterschiede anzeigen
          ({{ mergedTeams.filter(t => !['match', 'ignored'].includes(t.status)).length }})
        </button>
      </div>
      <draggable
          v-model="teamList"
          item-key="id"
          handle=".drag-handle"
          @end="onSort"
          ghost-class="drag-ghost"
          chosen-class="drag-chosen"
          drag-class="drag-dragging"
          animation="150"
      >
        <template #item="{element: team, index}">
          <li
              :class="[
                'rounded px-3 py-2 mb-1 flex justify-between items-center gap-2 transition-opacity',
                (teamsBeyondCapacity && index >= planCapacity) 
                  ? 'bg-yellow-100 border border-yellow-300 text-red-800' 
                  : 'bg-gray-50',
                team.noshow ? 'opacity-50' : 'opacity-100'
              ]"
          >
            <!-- Drag-Handle -->
            <span class="drag-handle cursor-move text-gray-500"><IconDraggable/></span>

            <!-- Neue Positionsspalte (Txx) - empty if beyond capacity -->
            <span v-if="!teamsBeyondCapacity || index < planCapacity" class="w-8 text-right text-sm" :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : 'text-black'">T{{ String(index + 1).padStart(2, '0') }}</span>
            <span v-else class="w-8 text-right text-sm text-red-800">–</span>

            <!-- Teamnummer (grau) -->
            <span class="text-sm w-12" :class="(teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : 'text-gray-500'">{{ team.team_number_hot || '–' }}</span>

            <!-- Eingabefeld -->
            <input
                v-model="team.name"
                @blur="updateTeamName(team)"
                :class="[
                  'editable-input flex-1 text-sm px-2 py-1 border border-transparent rounded hover:border-gray-300 focus:border-blue-500 focus:outline-none transition-colors cursor-pointer',
                  (teamsBeyondCapacity && index >= planCapacity) ? 'text-red-800' : ''
                ]"
                placeholder="Click to edit team name"
            />

            <!-- No-Show Checkbox -->
            <label class="flex items-center gap-1 text-sm text-gray-600 cursor-pointer">
              <input
                  type="checkbox"
                  v-model="team.noshow"
                  @change="updateTeamNoshow(team)"
                  class="w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
              />
              <span class="text-xs">No-Show</span>
            </label>
          </li>
        </template>
      </draggable>
      
      <!-- Placeholder rows for plan > enrolled -->
      <template v-for="placeholder in placeholderRows" :key="placeholder.id">
        <li
            class="bg-yellow-100 border border-yellow-300 text-red-800 rounded px-3 py-2 mb-1 flex justify-between items-center gap-2"
        >
          <!-- Empty space for drag handle -->
          <span class="w-6"></span>
          
          <!-- Empty Txx column (no Txx shown as per requirements) -->
          <span class="w-8"></span>
          
          <!-- Empty team number -->
          <span class="text-sm w-12 text-red-800">–</span>
          
          <!-- Placeholder text -->
          <span class="flex-1 text-sm text-red-800 italic">Fehlendes Team</span>
          
          <!-- Empty space for checkbox -->
          <span class="w-16"></span>
        </li>
      </template>
    </div>
    <div
        v-if="showDiffModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
    >
      <div class="bg-white w-full max-w-4xl max-h-[80vh] overflow-y-auto rounded-lg shadow-lg p-6 relative">
        <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Abweichungen zwischen FLOW und der Anmeldung</h2>
        <button
            class="absolute top-3 right-3 text-gray-500 hover:text-black"
            @click="showDiffModal = false"
        >
          &times;
        </button>

        <div class="space-y-4">
          <div
              v-for="team in mergedTeams.filter(t => t.status !== 'match' && t.status !== 'ignored')"
              :key="team.number"
              class="rounded-md p-4 border-l-4 bg-gray-50"
              :class="{
      'border-yellow-400': team.status === 'conflict',
      'border-green-500': team.status === 'new',
      'border-red-500': team.status === 'missing'
    }"
          >
            <div class="flex justify-between items-center mb-2">
              <span class="text-sm font-semibold text-gray-700">
                Team-Nr: {{ team.number ?? (team.draht?.number ?? 'Keine Nummer') }}
              </span>
              <span
                  class="text-xs font-medium uppercase"
                  :class="{
          'text-yellow-700': team.status === 'conflict',
          'text-green-700': team.status === 'new',
          'text-red-700': team.status === 'missing'
        }"
              >
        {{ statusLabels[team.status] }}
      </span>
            </div>

            <div class="grid grid-cols-2 gap-4 text-sm text-black">
              <div>
                <div class="text-gray-500">FLOW:</div>
                <div>{{ team.local?.name || '–' }}</div>
              </div>
              <div>
                <div class="text-gray-500">Anmeldung:</div>
                <div>{{ team.draht?.name || '–' }}</div>
              </div>
            </div>

            <div v-if="!team.draht?.number && team.draht" class="mt-2 text-xs text-yellow-700 bg-yellow-50 p-2 rounded">
              ⚠️ Dieses Team hat keine Team-Nummer in DRAHT und kann nicht importiert werden.
            </div>

            <div class="flex justify-end gap-2 mt-4">
              <button
                  v-if="team.status === 'missing'"
                  class="px-3 py-1 text-sm rounded bg-red-600 text-white hover:bg-red-700"
                  @click="deleteTeam(team)"
              >
                Löschen
              </button>
              <button
                  v-else
                  class="px-3 py-1 text-sm rounded"
                  :class="{
                    'bg-blue-600 text-white hover:bg-blue-700': team.draht?.number || team.number,
                    'bg-gray-300 text-gray-500 cursor-not-allowed': !team.draht?.number && !team.number
                  }"
                  :disabled="!team.draht?.number && !team.number"
                  @click="applyDrahtTeam(team)"
              >
                {{
                  (!team.draht?.number && !team.number) ? 'Keine Team-Nummer' : (team.status === 'new' ? 'Hinzufügen' : 'Übernehmen')
                }}
              </button>
              <button
                  class="px-3 py-1 text-sm rounded bg-gray-300 hover:bg-gray-400"
                  @click="ignoreDiff(team)"
              >
                Ignorieren
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<style scoped>
.drag-ghost {
  opacity: 0.4;
  transform: scale(0.98);
}

.drag-chosen {
  background-color: #fde68a; /* yellow-200 */
  box-shadow: 0 0 0 2px #facc15; /* yellow-400 */
}

.drag-dragging {
  cursor: grabbing;
}
</style>

<style scoped>
.editable-input {
  border: 1px solid transparent;
  background-color: transparent;
  transition: all 0.2s ease;
  position: relative;
}

.editable-input:hover {
  background: rgba(255, 255, 255, 0.8);
  border-color: #d1d5db;
  cursor: text;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.editable-input:focus {
  background: white;
  border-color: #3b82f6;
  box-shadow: 0 0 0 1px #3b82f6, 0 2px 4px rgba(0, 0, 0, 0.1);
  outline: none;
}

.editable-input::placeholder {
  color: #9ca3af;
  font-style: italic;
}
</style>