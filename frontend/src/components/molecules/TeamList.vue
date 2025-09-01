<script setup>
import draggable from 'vuedraggable'
import {computed, toRef, ref, watch, onMounted} from "vue";
import axios from "axios";
import {useEventStore} from "@/stores/event";
import IconDraggable from "@/components/icons/IconDraggable.vue";

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
const colour = props.program === 'explore' ? 'green' : 'red';

const ignoredTeamNumbers = ref(new Set())

watch(() => props.teams, (newVal) => {
  teamList.value = [...newVal]
})

const onSort = async () => {
  const payload = teamList.value.map((team, index) => ({
    team_id: team.id,
    order: index + 1
  }))

  try {
    await axios.post(`/events/${event.value?.id}/teams/update-order`, {
      program: props.program,
      order: payload
    })
  } catch (e) {
    console.error('Order update failed', e)
  }
}

const updateTeamName = async (team) => {
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.id,
      number: team.number,
      name: team.name,
    })
  } catch (e) {
    console.error(`Failed to update team name for ${team.id}`, e)
  }
}

const mergedTeams = computed(() => {
  const result = []

  const localMap = new Map(localTeams.value.map(t => [t.team_number_hot, t]))
  const drahtMap = new Map(props.remoteTeams.map(t => [Number(t.number), t]))

  const allNumbers = new Set([
    ...localTeams.value.map(t => t.team_number_hot),
    ...props.remoteTeams.map(t => Number(t.number))
  ])

  allNumbers.forEach(number => {
    const local = localMap.get(number)
    const draht = drahtMap.get(number)

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

    result.push({number, local, draht, status})
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
  try {
    await axios.put(`/events/${event.value?.id}/teams`, {
      id: team.local?.id, // could be null for new
      team_number_hot: team.draht.number,
      name: team.draht.name,
      event: event.value.id,
      first_program: props.program,
    })

    // Update localTeams to reflect the change
    const index = localTeams.value.findIndex(t => t.team_number_hot === team.number)
    if (index !== -1) {
      localTeams.value[index].name = team.draht.name
    } else {
      localTeams.value.push({
        id: team.draht.id,
        name: team.draht.name,
        team_number_hot: Number(team.draht.number),
      })
    }

    team.status = 'match'
    teamList.value = [...localTeams.value]

    const hasRemainingDiffs = mergedTeams.value.some(t => t.status !== 'match' && t.status !== 'ignored')
    if (!hasRemainingDiffs) {
      showDiffModal.value = false
    }
  } catch (e) {
    console.error(`Fehler beim Übernehmen von Team ${team.number}`, e)
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

onMounted(async () => {
  try {
    const dbRes = await axios.get(`/events/${event.value?.id}/teams?program=${props.program}`)
    localTeams.value = dbRes.data
    teamList.value = [...localTeams.value]

    teamList.value = [...localTeams.value]
    teamsDiffer.value = JSON.stringify(localTeams.value) !== JSON.stringify(props.remoteTeams)
  } catch (err) {
    console.error('Failed to fetch teams', err)
  }
})
</script>

<template>
  <div class="overflow-y-auto max-h-[80vh] lg:max-h-none">
    <div class="p-4 border rounded shadow">
      <div class="flex items-center gap-2 mb-2">
        <img
          v-if="program === 'explore'"
          src="@/assets/FLL_Explore.png"
          alt="Logo Explore"
          class="w-10 h-10 flex-shrink-0"
        />
        <img
          v-else-if="program === 'challenge'"
          src="@/assets/FLL_Challenge.png"
          alt="Logo Challenge"
          class="w-10 h-10 flex-shrink-0"
        />
      <div>
        <h3 class="text-lg font-semibold capitalize">
          <span class="italic">FIRST</span> LEGO League {{ program }}
        </h3>
        <p class="text-sm text-gray-500">Geplant: ??</p>
      </div>
      </div>
      <div v-if="showSyncPrompt" class="mb-2 p-2 bg-yellow-100 border border-yellow-300 text-yellow-800 rounded">
        Die Daten in FLOW weichen von denen der Anmeldung ab.
        <button class="text-sm text-yellow-600" @click="showDiffModal = !showDiffModal">
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
              :class="'bg-'+colour+'-100 rounded px-3 py-1 mb-1 flex justify-between items-center gap-2'"
          >
            <!-- Neue Positionsspalte -->
            <span class="w-8 text-right text-sm text-black">T{{ String(index + 1).padStart(2, '0') }}</span>

            <!-- Teamnummer (grau) -->
            <span class="text-sm w-12 text-gray-500">{{ team.team_number_hot }}</span>

            <!-- Eingabefeld -->
            <input
                v-model="team.name"
                @blur="updateTeamName(team)"
                :class="'flex-1 bg-transparent border-b border-'+colour+'-300 focus:outline-none text-sm'"
            />

            <!-- Drag-Handle -->
            <span class="drag-handle cursor-move text-gray-500"><IconDraggable/></span>
          </li>
        </template>
      </draggable>
    </div>
    <div
        v-if="showDiffModal"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50"
    >
      <div class="bg-white w-full max-w-4xl max-h-[80vh] overflow-y-auto rounded-lg shadow-lg p-6 relative">
        <h2 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Unterschiede zwischen FLOW und der Anmeldung</h2>
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
              <span class="text-sm font-semibold text-gray-700">Team-Nr: {{ team.number }}</span>
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

            <div class="flex justify-end gap-2 mt-4">
              <button
                  class="px-3 py-1 text-sm rounded bg-blue-600 text-white hover:bg-blue-700"
                  @click="applyDrahtTeam(team)"
              >
                Übernehmen
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

