<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { getEventTitleLong } from '@/utils/eventTitle'
import { usePdfExport } from '@/composables/usePdfExport'
import axios from 'axios'
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue"

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

// --- PDF Download (Composable) ---
const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()

// --- Available Team Programs ---
interface Program {
  id: number
  name: string
}

const availableTeamPrograms = ref<Program[]>([])

// Computed: check if Challenge teams exist (id = 3)
const hasChallengeTeams = computed(() => availableTeamPrograms.value.some(p => p.id === 3))

// --- Modal State ---
const showModal = ref(false)
const selectedRound = ref<number | null>(null) // Currently selected round (1-3)
const openRound = ref<number | null>(null) // Currently open accordion (1-3)
const matches = ref<Array<{
  match_no: number
  team_1: { name: string; hot_number: number } | null
  team_2: { name: string; hot_number: number } | null
}>>([])
const isLoadingMatches = ref(false)

// Round options
const roundOptions = [
  { value: 1, label: 'Vorrunde 1' },
  { value: 2, label: 'Vorrunde 2' },
  { value: 3, label: 'Vorrunde 3' },
]

// Toggle accordion round
function toggleRound(round: number) {
  if (openRound.value === round) {
    openRound.value = null
    matches.value = []
    selectedRound.value = null
  } else {
    openRound.value = round
    selectedRound.value = round
    fetchMatches()
  }
}

// Get plan ID from event
async function getPlanId(): Promise<number | null> {
  if (!eventId.value) return null
  try {
    const response = await axios.get(`/plans/event/${eventId.value}`)
    return response.data.id
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('Failed to fetch plan ID:', error)
    }
    return null
  }
}

// Fetch matches for selected round
async function fetchMatches() {
  const plan = await getPlanId()
  if (!plan || isLoadingMatches.value) return

  isLoadingMatches.value = true
  try {
    const { data } = await axios.get(`/export/match-teams/${plan}/${selectedRound.value}`)
    matches.value = data.matches || []
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('Failed to fetch matches:', error)
    }
    matches.value = []
  } finally {
    isLoadingMatches.value = false
  }
}

// Watch for round changes (when accordion opens)
watch(selectedRound, () => {
  if (showModal.value && selectedRound.value !== null) {
    fetchMatches()
  }
})

// Open modal
function openModal() {
  showModal.value = true
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

// Close modal
function closeModal() {
  showModal.value = false
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

// Format team display: "Team Name [HOT Number]" or "Freier Slot"
function formatTeam(team: { name: string; hot_number: number } | null): string {
  if (!team) return 'Freier Slot'
  return `${team.name} [${team.hot_number}]`
}

// Download match plan PDF
async function downloadMatchPlanPdf() {
  if (!eventId.value) return
  
  const plan = await getPlanId()
  if (!plan) return
  
  await downloadPdf('match-plan', `/export/match-plan/${plan}`, 'Match-Plan.pdf')
}

// Check if team is empty slot
function isEmptySlot(team: { name: string; hot_number: number } | null): boolean {
  return team === null
}

// Computed: normalized event title for modal header
const eventTitleNormalized = computed(() => {
  const title = getEventTitleLong(event.value)
  // Format "FIRST" as italic for display
  return title.replace(/FIRST/, '<em>FIRST</em>')
})

// Fetch available programs for teams from backend
async function fetchAvailableTeamPrograms() {
  if (!eventId.value) return
  try {
    const { data } = await axios.get(`/export/available-team-programs/${eventId.value}`)
    availableTeamPrograms.value = data.programs || []
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('Failed to fetch available team programs:', error)
    }
    availableTeamPrograms.value = []
  }
}

// --- Beim Start sicherstellen, dass Event geladen ist ---
onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  if (eventStore.selectedEvent?.id) {
    await fetchAvailableTeamPrograms()
  }
})

// --- Wenn Event wechselt, nachladen ---
watch(() => event.value?.id, async (id) => {
  if (id) {
    await fetchAvailableTeamPrograms()
  }
})
</script>

<template>
  <div v-if="hasChallengeTeams || event?.event_challenge" class="rounded-xl shadow bg-white p-6 flex flex-col">
    <div class="mb-2">
      <h4 class="text-base font-semibold text-gray-800">Robot-Game Match-Plan</h4>
      <p class="text-sm text-gray-600">
        Vorrunden-Matches zum Übernehmen in die Auswertesoftware 
        <a 
          href="https://evaluation.hands-on-technology.org/" 
          target="_blank" 
          rel="noopener noreferrer"
          class="text-blue-600 underline hover:text-blue-800"
        >
          SCORE
        </a>.
      </p>
    </div>

    <!-- Buttons -->
    <div class="mt-4 flex justify-between">
      <button
        class="px-4 py-2 rounded text-sm flex items-center gap-2 bg-gray-200 hover:bg-gray-300"
        @click="openModal"
      >
        <span>Match-Plan</span>
      </button>
      
      <!-- PDF Button -->
      <button
        class="px-4 py-2 rounded text-sm flex items-center gap-2"
        :class="!isDownloading['match-plan'] 
          ? 'bg-gray-200 hover:bg-gray-300' 
          : 'bg-gray-100 cursor-not-allowed opacity-50'"
        :disabled="isDownloading['match-plan']"
        @click="downloadMatchPlanPdf()"
      >
        <svg v-if="isDownloading['match-plan']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>{{ isDownloading['match-plan'] ? 'Erzeuge…' : 'PDF' }}</span>
      </button>
    </div>

    <!-- Match Plan Modal -->
    <div
      v-if="showModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click="closeModal"
    >
      <div 
        class="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-hidden"
        @click.stop
      >
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h3 class="text-lg font-semibold text-gray-900" v-html="eventTitleNormalized"></h3>
          <button
            @click="closeModal"
            class="text-gray-400 hover:text-gray-600 transition-colors"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        
        <!-- Modal Content -->
        <div class="px-6 py-4 overflow-y-auto max-h-[calc(90vh-120px)]">
          <!-- Accordion for rounds -->
          <div class="space-y-2">
            <template v-for="option in roundOptions" :key="option.value">
              <div class="bg-white border rounded-lg shadow">
                <button
                  class="w-full text-left px-4 py-2 bg-gray-100 font-semibold text-black uppercase flex justify-between items-center"
                  @click="toggleRound(option.value)"
                >
                  {{ option.label }}
                  <AccordionArrow :opened="openRound === option.value"/>
                </button>
                <transition name="fade">
                  <div v-if="openRound === option.value" class="p-4">
                    <div v-if="isLoadingMatches" class="flex items-center justify-center py-8">
                      <svg class="animate-spin h-8 w-8 text-blue-600" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                      </svg>
                      <span class="ml-3 text-gray-600">Lade Matches...</span>
                    </div>
                    
                    <div v-else-if="matches.length === 0" class="text-center py-8 text-gray-500">
                      Keine Matches gefunden
                    </div>
                    
                    <!-- Match Grid -->
                    <div v-else class="grid grid-cols-2 gap-3">
                      <template v-for="match in matches" :key="match.match_no">
                        <!-- Team 1 (Left Column) -->
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="isEmptySlot(match.team_1) ? 'bg-gray-300 text-gray-700' : 'bg-blue-600'"
                        >
                          {{ formatTeam(match.team_1) }}
                        </div>
                        
                        <!-- Team 2 (Right Column) -->
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="isEmptySlot(match.team_2) ? 'bg-gray-300 text-gray-700' : 'bg-blue-600'"
                        >
                          {{ formatTeam(match.team_2) }}
                        </div>
                      </template>
                    </div>
                  </div>
                </transition>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- Globaler Ladeindikator für PDF-Generierung -->
    <div
      v-if="anyDownloading"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/20"
    >
      <div class="bg-white px-4 py-3 rounded shadow flex items-center gap-2">
        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>PDF wird erzeugt…</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}
</style>
