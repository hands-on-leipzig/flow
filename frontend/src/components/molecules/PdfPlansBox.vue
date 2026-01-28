<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getEventTitleLong } from '@/utils/eventTitle'
import axios from 'axios'
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue"

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

// --- Readiness direkt aus Store ---
const readiness = computed(() => eventStore.readiness)

// --- Available Roles ---
interface Role {
  id: number
  name: string
  first_program: number
  differentiation_parameter: string
}

const availableRoles = ref<Role[]>([])
const selectedRoleIds = ref<Set<number>>(new Set())

// Computed: split roles by program (Explore = 2, Challenge = 3)
const exploreRoles = computed(() => availableRoles.value.filter(r => r.first_program === 2))
const challengeRoles = computed(() => availableRoles.value.filter(r => r.first_program === 3))

// Fetch available roles from backend
async function fetchAvailableRoles() {
  if (!eventId.value) return
  try {
    const { data } = await axios.get(`/export/available-roles/${eventId.value}`)
    availableRoles.value = data.roles || []
    // Select all by default
    selectedRoleIds.value = new Set(availableRoles.value.map(r => r.id))
  } catch (error) {
    console.error('Failed to fetch available roles:', error)
    availableRoles.value = []
  }
}

// Toggle role selection
function toggleRole(roleId: number) {
  if (selectedRoleIds.value.has(roleId)) {
    selectedRoleIds.value.delete(roleId)
  } else {
    selectedRoleIds.value.add(roleId)
  }
  selectedRoleIds.value = new Set(selectedRoleIds.value) // Trigger reactivity
}

// Computed: at least one role selected
const hasSelectedRoles = computed(() => selectedRoleIds.value.size > 0)

// --- Available Team Programs ---
interface Program {
  id: number
  name: string
}

const availableTeamPrograms = ref<Program[]>([])
const selectedProgramIds = ref<Set<number>>(new Set())

// Computed: split programs (Explore = 2, Challenge = 3)
const hasExploreTeams = computed(() => availableTeamPrograms.value.some(p => p.id === 2))
const hasChallengeTeams = computed(() => availableTeamPrograms.value.some(p => p.id === 3))

// Fetch available programs for teams from backend
async function fetchAvailableTeamPrograms() {
  if (!eventId.value) return
  try {
    const { data } = await axios.get(`/export/available-team-programs/${eventId.value}`)
    availableTeamPrograms.value = data.programs || []
    // Select all by default
    selectedProgramIds.value = new Set(availableTeamPrograms.value.map(p => p.id))
  } catch (error) {
    console.error('Failed to fetch available team programs:', error)
    availableTeamPrograms.value = []
  }
}

// Toggle program selection for teams
function toggleTeamProgram(programId: number) {
  if (selectedProgramIds.value.has(programId)) {
    selectedProgramIds.value.delete(programId)
  } else {
    selectedProgramIds.value.add(programId)
  }
  selectedProgramIds.value = new Set(selectedProgramIds.value) // Trigger reactivity
}

// Computed: at least one program selected for teams
const hasSelectedPrograms = computed(() => selectedProgramIds.value.size > 0)

// --- Beim Start sicherstellen, dass Event & Readiness geladen sind ---
onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  if (eventStore.selectedEvent?.id) {
    await eventStore.refreshReadiness(eventStore.selectedEvent.id)
    await fetchAvailableRoles()
    await fetchAvailableTeamPrograms()
  }
})

// --- Wenn Event wechselt, Readiness nachladen ---
watch(() => event.value?.id, async (id) => {
  if (id) {
    await eventStore.refreshReadiness(id)
    await fetchAvailableRoles()
    await fetchAvailableTeamPrograms()
  }
})

// --- Computed Flags ---
const hasTeamIssues = computed(
  () => !readiness.value?.explore_teams_ok || !readiness.value?.challenge_teams_ok
)
const hasRoomIssues = computed(() => !readiness.value?.room_mapping_ok)

// --- PDF Download (Composable) ---
const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()

// --- CSV Download State ---
const isDownloadingCsv = ref(false)

// --- CSV Download Function ---
async function downloadRoomUtilizationCsv() {
  if (!eventId.value || isDownloadingCsv.value) return
  
  isDownloadingCsv.value = true
  try {
    const response = await axios.get(
      `/export/csv/room-utilization/${eventId.value}`,
      { responseType: 'blob' }
    )

    const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    const dateStr = new Date().toISOString().split('T')[0]
    link.download = `FLOW_Raumnutzung_(${dateStr}).csv`
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim CSV-Download (Raumnutzung):', error)
    alert('Fehler beim Herunterladen der Raumnutzung. Bitte versuche es erneut.')
  } finally {
    isDownloadingCsv.value = false
  }
}

// --- Worker Shifts Modal ---
const showModal = ref(false)
const workerShifts = ref<any>(null)
const isLoadingShifts = ref(false)

// Download roles PDF with selected roles
async function downloadRolesPdf() {
  if (!eventId.value || !hasSelectedRoles.value) return
  
  isDownloading.value['roles'] = true
  try {
    const response = await axios.post(
      `/export/pdf_download/roles/${eventId.value}`,
      { role_ids: Array.from(selectedRoleIds.value) },
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Rollen.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Rollen):', error)
  } finally {
    isDownloading.value['roles'] = false
  }
}

// Download teams PDF with selected programs
async function downloadTeamsPdf() {
  if (!eventId.value || !hasSelectedPrograms.value) return
  
  isDownloading.value['teams'] = true
  try {
    const response = await axios.post(
      `/export/pdf_download/teams/${eventId.value}`,
      { program_ids: Array.from(selectedProgramIds.value) },
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Teams.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Teams):', error)
  } finally {
    isDownloading.value['teams'] = false
  }
}

// Download event overview PDF
async function downloadEventOverviewPdf() {
  if (!eventId.value) return
  
  isDownloading.value['overview'] = true
  try {
    // Get the plan ID for this event
    const planResponse = await axios.get(`/plans/event/${eventId.value}`)
    const planId = planResponse.data.id
    
    const response = await axios.get(
      `/export/event-overview/${planId}`,
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Übersichtsplan.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Übersichtsplan):', error)
  } finally {
    isDownloading.value['overview'] = false
  }
}

// Download moderator match plan PDF
async function downloadModeratorMatchPlanPdf() {
  if (!eventId.value) return
  
  isDownloading.value['moderator-match-plan'] = true
  try {
    // Get the plan ID for this event
    const planResponse = await axios.get(`/plans/event/${eventId.value}`)
    const planId = planResponse.data.id
    
    const response = await axios.get(
      `/export/moderator-match-plan/${planId}`,
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Robot-Game_kompakt.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Robot-Game kompakt):', error)
  } finally {
    isDownloading.value['moderator-match-plan'] = false
  }
}

// Download team list PDF
async function downloadTeamListPdf() {
  if (!eventId.value) return
  
  isDownloading.value['team-list'] = true
  try {
    // Get the plan ID for this event
    const planResponse = await axios.get(`/plans/event/${eventId.value}`)
    const planId = planResponse.data.id
    
    const response = await axios.get(
      `/export/team-list/${planId}`,
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Teamliste.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Teamliste):', error)
  } finally {
    isDownloading.value['team-list'] = false
  }
}

// Download name tags PDF
async function downloadNameTagsPdf() {
  if (!eventId.value) return
  
  await downloadPdf('name-tags', `/export/name-tags/${eventId.value}`, 'Namensaufkleber.pdf')
}

// --- Volunteer Labels State ---
interface Volunteer {
  name: string
  role: string
  program: string // 'E', 'C', or empty
}

const volunteerInputText = ref('')
const volunteerPreview = ref<Volunteer[]>([])
const submittedVolunteers = ref<Volunteer[]>([])

// Parse CSV/tab-separated text into volunteer array
function parseVolunteerInput(text: string): Volunteer[] {
  if (!text.trim()) return []
  
  const lines = text.trim().split(/\r?\n/)
  const volunteers: Volunteer[] = []
  
  for (const line of lines) {
    if (!line.trim()) continue
    
    // Support both tab and comma separation
    const parts = line.split(/\t|,/)
      .map(p => p.trim())
      .filter(p => p.length > 0)
    
    if (parts.length >= 2) {
      const name = parts[0] || ''
      const role = parts[1] || ''
      const program = (parts[2] || '').toUpperCase().trim()
      
      // Only add if name and role are provided
      if (name && role) {
        volunteers.push({
          name,
          role,
          program: (program === 'E' || program === 'C') ? program : ''
        })
      }
    }
  }
  
  return volunteers
}

// Update preview when input changes
function updateVolunteerPreview() {
  volunteerPreview.value = parseVolunteerInput(volunteerInputText.value)
}

// Clear all volunteer data
function clearAllVolunteers() {
  volunteerInputText.value = ''
  volunteerPreview.value = []
  submittedVolunteers.value = []
}

// Insert parsed data into preview (Einfügen)
function insertVolunteers() {
  const parsed = parseVolunteerInput(volunteerInputText.value)
  volunteerPreview.value = [...volunteerPreview.value, ...parsed]
  volunteerInputText.value = '' // Clear input after inserting
}

// Submit preview data (Übernehmen) - add preview to submitted list
function submitVolunteers() {
  submittedVolunteers.value = [...submittedVolunteers.value, ...volunteerPreview.value]
  volunteerPreview.value = []
  volunteerInputText.value = ''
}

// Check if we have submitted volunteers
const hasSubmittedVolunteers = computed(() => submittedVolunteers.value.length > 0)

// Download volunteer labels PDF
async function downloadVolunteerLabelsPdf() {
  if (!eventId.value || !hasSubmittedVolunteers.value) return
  
  isDownloading.value['volunteer-labels'] = true
  try {
    const response = await axios.post(
      `/export/volunteer-labels/${eventId.value}`,
      { volunteers: submittedVolunteers.value },
      { responseType: 'blob' }
    )
    
    const filename = response.headers['x-filename'] || `FLOW_Aufkleber_Volunteers.pdf`
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error: any) {
    console.error('Fehler beim PDF-Download (Volunteer Labels):', error)
    const errorMessage = error.response?.data?.message || error.message || 'Unbekannter Fehler'
    alert('Fehler beim Erstellen des PDFs: ' + errorMessage)
  } finally {
    isDownloading.value['volunteer-labels'] = false
  }
}

// Fetch worker shifts and show modal
async function showWorkerShiftsModal() {
  if (!eventId.value) return
  
  isLoadingShifts.value = true
  showModal.value = true
  
  try {
    const { data } = await axios.get(`/export/worker-shifts/${eventId.value}`)
    workerShifts.value = data
  } catch (error) {
    console.error('Failed to fetch worker shifts:', error)
    workerShifts.value = { error: 'Fehler beim Laden der Schichten' }
  } finally {
    isLoadingShifts.value = false
  }
}

// Close modal
function closeModal() {
  showModal.value = false
  workerShifts.value = null
}

// Format date as dd.mm.yyyy
function formatDate(dateString: string): string {
  const date = new Date(dateString)
  const day = date.getDate().toString().padStart(2, '0')
  const month = (date.getMonth() + 1).toString().padStart(2, '0')
  const year = date.getFullYear()
  return `${day}.${month}.${year}`
}

// --- Match Plan Modal State (from MatchPlanBox) ---
const showMatchPlanModal = ref(false)
const selectedRound = ref<number | null>(null)
const openRound = ref<number | null>(null)
const matches = ref<Array<{
  match_no: number
  team_1: { name: string; hot_number: number; noshow?: boolean } | null
  team_2: { name: string; hot_number: number; noshow?: boolean } | null
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

// Fetch matches for selected round
async function fetchMatches() {
  if (!eventId.value || isLoadingMatches.value) return
  
  const planResponse = await axios.get(`/plans/event/${eventId.value}`)
  const planId = planResponse.data.id
  if (!planId || !selectedRound.value) return

  isLoadingMatches.value = true
  try {
    const { data } = await axios.get(`/export/match-teams/${planId}/${selectedRound.value}`)
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

// Watch for round changes
watch(selectedRound, () => {
  if (showMatchPlanModal.value && selectedRound.value !== null) {
    fetchMatches()
  }
})

// Open match plan modal
function openMatchPlanModal() {
  showMatchPlanModal.value = true
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

// Close match plan modal
function closeMatchPlanModal() {
  showMatchPlanModal.value = false
  openRound.value = null
  selectedRound.value = null
  matches.value = []
}

// Format team display
function formatTeam(team: { name: string; hot_number: number; noshow?: boolean } | null): string {
  if (!team) return 'Freier Slot'
  return `${team.name} [${team.hot_number}]`
}

// Check if team is no-show
function isNoshow(team: { name: string; hot_number: number; noshow?: boolean } | null): boolean {
  return team !== null && (team.noshow === true)
}

// Check if team is empty slot
function isEmptySlot(team: { name: string; hot_number: number; noshow?: boolean } | null): boolean {
  return team === null
}

// Download match plan PDF
async function downloadMatchPlanPdf() {
  if (!eventId.value) return
  
  const planResponse = await axios.get(`/plans/event/${eventId.value}`)
  const planId = planResponse.data.id
  if (!planId) return
  
  await downloadPdf('match-plan', `/export/match-plan/${planId}`, 'Match-Plan.pdf')
}

// Computed: normalized event title for modal header
const eventTitleNormalized = computed(() => {
  const title = getEventTitleLong(event.value)
  return title.replace(/FIRST/, '<em>FIRST</em>')
})

// Tab state
const activeTab = ref<'public' | 'organisation' | 'aufkleber'>('public')
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col">
    <h3 class="text-lg font-semibold mb-4">Drucksachen</h3>

    <!-- Tabs -->
    <div class="flex mb-4 border-b text-lg font-semibold relative">
      <button
        :class="activeTab === 'public' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
        class="px-4 py-2 relative"
        @click="activeTab = 'public'"
      >
        Öffentlich
      </button>
      <button
        :class="activeTab === 'organisation' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
        class="px-4 py-2 ml-4 relative"
        @click="activeTab = 'organisation'"
      >
        Organisation
      </button>
      <button
        :class="activeTab === 'aufkleber' ? 'border-b-2 border-blue-500 text-blue-600' : 'text-gray-600'"
        class="px-4 py-2 ml-4 relative"
        @click="activeTab = 'aufkleber'"
      >
        Aufkleber
      </button>
    </div>

    <!-- Subtitle -->
    <p v-if="activeTab === 'public'" class="text-sm text-blue-600 mb-4">
      Zum Aushang bzw zum Verteilen an Teams und Volunteers
    </p>
    <p v-if="activeTab === 'organisation'" class="text-sm text-blue-600 mb-4">
      Nur für den Veranstalter – nicht für Teams oder Besucher.
    </p>

    <!-- Tab Content: Öffentlich -->
    <div v-show="activeTab === 'public'">
      <!-- Übersichtsplan -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-gray-800">Übersichtsplan für das Publikum</h4>
            <p class="text-sm text-gray-600">Alle öffentlichen Aktivitäten des Tages auf einer Seite.</p>
          </div>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
            :class="!isDownloading.overview 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading.overview"
            @click="downloadEventOverviewPdf()"
          >
            <svg v-if="isDownloading.overview" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.overview ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>

    <!-- Räume -->
    <div class="border-b border-gray-200 pb-3 mb-3">
      <div class="mb-2">
        <h4 class="text-base font-semibold text-gray-800">Räume</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Raum mit allen Aktivitäten.</p>
      </div>

      <!-- Warning box -->
      <div
        v-if="hasRoomIssues"
        class="mt-3 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
      >
        <svg xmlns="http://www.w3.org/2000/svg"
             class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500"
             fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
        </svg>
        <span class="text-sm">
          Achtung: Es wurden noch nicht alle Aktivitäten und Teams auf die Räume verteilt.<br>
          Das PDF sollte so nicht gedruckt werden.
        </span>
      </div>

      <!-- Buttons -->
      <div class="mt-4 flex justify-between">
        <!-- Raumnutzung CSV Button -->
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2"
          :class="!isDownloadingCsv 
            ? 'bg-gray-200 hover:bg-gray-300' 
            : 'bg-gray-100 cursor-not-allowed opacity-50'"
          :disabled="isDownloadingCsv"
          @click="downloadRoomUtilizationCsv"
        >
          <svg v-if="isDownloadingCsv" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
          </svg>
          <span>{{ isDownloadingCsv ? 'Erzeuge…' : 'Raumnutzung' }}</span>
        </button>

        <!-- PDF Button -->
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2"
          :class="!isDownloading.rooms 
            ? 'bg-gray-200 hover:bg-gray-300' 
            : 'bg-gray-100 cursor-not-allowed opacity-50'"
          :disabled="isDownloading.rooms"
          @click="downloadPdf('rooms', `/export/pdf_download/rooms/${eventId}`, 'Räume.pdf')"
        >
          <svg v-if="isDownloading.rooms" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
          </svg>
          <span>{{ isDownloading.rooms ? 'Erzeuge…' : 'PDF' }}</span>
        </button>
      </div>
    </div>

    <!-- Rollen -->
    <div class="border-b border-gray-200 pb-3 mb-3">
      <div class="mb-2">
        <h4 class="text-base font-semibold text-gray-800">Rollen</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Rolle mit allen Aktivitäten.</p>
      </div>

      <!-- Warning box -->
      <div
        v-if="hasTeamIssues"
        class="mt-3 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
      >
        <svg xmlns="http://www.w3.org/2000/svg"
             class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500"
             fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
        </svg>
        <span class="text-sm">
          Achtung: Die Anzahl der angemeldeten Teams stimmt nicht mit der Anzahl im Plan überein.<br>
          Das PDF sollte so nicht gedruckt werden.
        </span>
      </div>

      <!-- No roles available message -->
      <div v-if="availableRoles.length === 0" class="mt-4 p-4 bg-gray-50 rounded text-center text-sm text-gray-600">
        Keine Rollen mit Aktivitäten im Plan vorhanden.
      </div>

      <!-- Role Selector - Two columns (or single column if only one program has roles) -->
      <div 
        v-else
        class="mt-4 grid gap-4"
        :class="{
          'grid-cols-2': exploreRoles.length > 0 && challengeRoles.length > 0,
          'grid-cols-1': exploreRoles.length === 0 || challengeRoles.length === 0
        }"
      >
        <!-- Explore Roles -->
        <div v-if="exploreRoles.length > 0" class="bg-gray-50 rounded p-3">
          <h5 class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
            <img 
              :src="programLogoSrc('E')" 
              :alt="programLogoAlt('E')"
              class="w-6 h-6 flex-shrink-0"
            />
            <span>FIRST LEGO League Explore</span>
          </h5>
          <div class="space-y-0.5">
            <label 
              v-for="role in exploreRoles" 
              :key="role.id"
              class="flex items-center gap-2 cursor-pointer hover:bg-gray-100 p-1 rounded"
            >
              <input 
                type="checkbox" 
                :checked="selectedRoleIds.has(role.id)"
                @change="toggleRole(role.id)"
                class="accent-blue-600"
              />
              <span class="text-sm">{{ role.name }}</span>
            </label>
          </div>
        </div>

        <!-- Challenge Roles -->
        <div v-if="challengeRoles.length > 0" class="bg-gray-50 rounded p-3">
          <h5 class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
            <img 
              :src="programLogoSrc('C')" 
              :alt="programLogoAlt('C')"
              class="w-6 h-6 flex-shrink-0"
            />
            <span>FIRST LEGO League Challenge</span>
          </h5>
          <div class="space-y-0.5">
            <label 
              v-for="role in challengeRoles" 
              :key="role.id"
              class="flex items-center gap-2 cursor-pointer hover:bg-gray-100 p-1 rounded"
            >
              <input 
                type="checkbox" 
                :checked="selectedRoleIds.has(role.id)"
                @change="toggleRole(role.id)"
                class="accent-blue-600"
              />
              <span class="text-sm">{{ role.name }}</span>
            </label>
          </div>
        </div>
      </div>

      <!-- Buttons -->
      <div class="mt-4 flex justify-between">
        <!-- HERO Schichten Button -->
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2 bg-gray-200 hover:bg-gray-300"
          @click="showWorkerShiftsModal"
        >
          <span>HERO Schichten</span>
        </button>
        
        <!-- PDF Button -->
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2"
          :class="hasSelectedRoles && !isDownloading.roles 
            ? 'bg-gray-200 hover:bg-gray-300' 
            : 'bg-gray-100 cursor-not-allowed opacity-50'"
          :disabled="!hasSelectedRoles || isDownloading.roles"
          @click="downloadRolesPdf"
        >
          <svg v-if="isDownloading.roles" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
          </svg>
          <span>{{ isDownloading.roles ? 'Erzeuge…' : 'PDF' }}</span>
        </button>
      </div>
    </div>

    <!-- Teams -->
    <div class="border-b border-gray-200 pb-3 mb-3">
      <div class="mb-2">
        <h4 class="text-base font-semibold text-gray-800">Teams</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Team mit allen Aktivitäten.</p>
      </div>

      <!-- Warning box -->
      <div
        v-if="hasTeamIssues"
        class="mt-3 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
      >
        <svg xmlns="http://www.w3.org/2000/svg"
             class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500"
             fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
        </svg>
        <span class="text-sm">
          Achtung: Die Anzahl der angemeldeten Teams stimmt nicht mit der Anzahl im Plan überein.<br>
          Das PDF sollte so nicht gedruckt werden.
        </span>
      </div>

      <!-- No teams available message -->
      <div v-if="availableTeamPrograms.length === 0" class="mt-4 p-4 bg-gray-50 rounded text-center text-sm text-gray-600">
        Keine Teams im Plan vorhanden.
      </div>

      <!-- Program Selector - Two columns (or single column if only one program has teams) -->
      <div 
        v-else
        class="mt-4 grid gap-4"
        :class="{
          'grid-cols-2': hasExploreTeams && hasChallengeTeams,
          'grid-cols-1': !hasExploreTeams || !hasChallengeTeams
        }"
      >
        <!-- Explore Teams -->
        <div v-if="hasExploreTeams" class="bg-gray-50 rounded p-3">
          <h5 class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
            <img 
              :src="programLogoSrc('E')" 
              :alt="programLogoAlt('E')"
              class="w-6 h-6 flex-shrink-0"
            />
            <span>FIRST LEGO League Explore</span>
          </h5>
          <div class="space-y-0.5">
            <label 
              class="flex items-center gap-2 cursor-pointer hover:bg-gray-100 p-1 rounded"
            >
              <input 
                type="checkbox" 
                :checked="selectedProgramIds.has(2)"
                @change="toggleTeamProgram(2)"
                class="accent-blue-600"
              />
              <span class="text-sm">Alle Teams</span>
            </label>
          </div>
        </div>

        <!-- Challenge Teams -->
        <div v-if="hasChallengeTeams" class="bg-gray-50 rounded p-3">
          <h5 class="text-sm font-semibold text-gray-700 mb-2 flex items-center gap-2">
            <img 
              :src="programLogoSrc('C')" 
              :alt="programLogoAlt('C')"
              class="w-6 h-6 flex-shrink-0"
            />
            <span>FIRST LEGO League Challenge</span>
          </h5>
          <div class="space-y-0.5">
            <label 
              class="flex items-center gap-2 cursor-pointer hover:bg-gray-100 p-1 rounded"
            >
              <input 
                type="checkbox" 
                :checked="selectedProgramIds.has(3)"
                @change="toggleTeamProgram(3)"
                class="accent-blue-600"
              />
              <span class="text-sm">Alle Teams</span>
            </label>
          </div>
        </div>
      </div>

      <!-- PDF Button -->
      <div class="mt-4 flex justify-end">
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2"
          :class="hasSelectedPrograms && !isDownloading.teams 
            ? 'bg-gray-200 hover:bg-gray-300' 
            : 'bg-gray-100 cursor-not-allowed opacity-50'"
          :disabled="!hasSelectedPrograms || isDownloading.teams"
          @click="downloadTeamsPdf"
        >
          <svg v-if="isDownloading.teams" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
          </svg>
          <span>{{ isDownloading.teams ? 'Erzeuge…' : 'PDF' }}</span>
        </button>
      </div>
    </div>
    </div>

    <!-- Tab Content: Organisation -->
    <div v-show="activeTab === 'organisation'">
      <!-- 1. Teamliste -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-gray-800">Teamliste</h4>
            <p class="text-sm text-gray-600">Alle Teams mit Teamräume und Zuordnung zu Guterachter:innen- bzw. Jury Gruppen.</p>
            <p class="text-sm text-gray-600 mt-2">Diese Liste hilft beim Check-In und bei den Briefings und Beratungen.</p>
          </div>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
            :class="!isDownloading['team-list'] 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading['team-list']"
            @click="downloadTeamListPdf"
          >
            <svg v-if="isDownloading['team-list']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['team-list'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>

      <!-- 2. Moderation -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-gray-800">Moderation</h4>
            <p class="text-sm text-gray-600">Zeiten für alle Aktivitäten mit Moderation und kompletter Robot-Game-Matchplan</p>
          </div>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
            :class="!isDownloading['moderator-match-plan'] 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading['moderator-match-plan']"
            @click="downloadModeratorMatchPlanPdf"
          >
            <svg v-if="isDownloading['moderator-match-plan']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['moderator-match-plan'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>

      <!-- 3. Match-Plan für SCORE -->
      <div v-if="hasChallengeTeams || event?.event_challenge" class="border-b border-gray-200 pb-3 mb-3">
        <div class="mb-2">
          <h4 class="text-base font-semibold text-gray-800">Match-Plan für SCORE</h4>
          <p class="text-sm text-gray-600">Vorrunden-Matches zum Übernehmen in die Auswertesoftware 
            <a 
              href="https://evaluation.hands-on-technology.org/" 
              target="_blank" 
              rel="noopener noreferrer"
              class="text-blue-600 underline hover:text-blue-800"
            >
              SCORE
            </a>.
          </p>
          <p class="text-sm text-gray-600 mt-2">
            Damit die Schiedsrichter:innen die Matches in der Reihenfolge angezeigt bekommen, wie in den den Plänen aus FLOW, müssen in SCORE die Matches exakt so angepasst werden, wie hier gezeigt.
          </p>
        </div>

        <!-- Buttons -->
        <div class="mt-4 flex justify-between">
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 bg-gray-200 hover:bg-gray-300"
            @click="openMatchPlanModal"
          >
            <span>Online anzeigen</span>
          </button>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2"
            :class="!isDownloading['match-plan'] 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading['match-plan']"
            @click="downloadMatchPlanPdf"
          >
            <svg v-if="isDownloading['match-plan']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['match-plan'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>

      <!-- 4. Gesamtplan -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-gray-800">Gesamtplan</h4>
            <p class="text-sm text-gray-600">Volle Details, aber in einfacher Formatierung.</p>
          </div>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
            :class="!isDownloading.full 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading.full"
            @click="downloadPdf('full', `/export/pdf_download/full/${eventId}`, 'Gesamtplan.pdf')"
          >
            <svg v-if="isDownloading.full" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.full ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- Tab Content: Aufkleber -->
    <div v-show="activeTab === 'aufkleber'">
      <p class="text-sm text-blue-600 mb-4">
        Aufkleber und Etiketten zum Drucken
      </p>
      <p class="text-sm text-gray-600 mb-3">
        Die PDF-Dateien passen zu dem  
        <a 
          href="https://www.avery-zweckform.com/vorlage-l4785" 
          target="_blank" 
          rel="noopener noreferrer"
          class="text-blue-600 underline hover:text-blue-800"
        >
          Format Avery L4785</a>.
      </p>
      <p class="text-sm text-gray-600 mb-4">
        Jeder Aufkleber enthält den Namen der Person, den Team-Namen sowie die Logos (Programm, Saison, Veranstalter).
      </p>
      
      <!-- Namensaufkleber für Teams -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div class="flex items-center justify-between">
          <div>
            <h4 class="text-base font-semibold text-gray-800 mb-2">Namensaufkleber für Teams</h4>
            <p class="text-sm text-gray-600">Ein Aufkleber für jedes Teammitglied und alle Coach:innen. Die Liste wird automatisch aus den Anmeldedaten der Teams generiert.</p>
          </div>
          <button
            class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
            :class="!isDownloading['name-tags'] 
              ? 'bg-gray-200 hover:bg-gray-300' 
              : 'bg-gray-100 cursor-not-allowed opacity-50'"
            :disabled="isDownloading['name-tags']"
            @click="downloadNameTagsPdf"
          >
            <svg v-if="isDownloading['name-tags']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['name-tags'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>

      <!-- Namensaufkleber für Volunteer -->
      <div class="border-b border-gray-200 pb-3 mb-3">
        <div>
          <h4 class="text-base font-semibold text-gray-800 mb-2">Namensaufkleber für Volunteers</h4>
          <p class="text-sm text-gray-600 mb-3">
            Hier kann eine einfache Liste von Rollen und Namen hochgeladen werden, aus der dann ein PDF erzeugt wird.
          </p>
          <p class="text-xs text-gray-500 mb-4">
            Format: Name, Rolle, Programm (E für Explore, C für Challenge, leer für kein Logo). 
            Spalten können durch Tab oder Komma getrennt sein.
          </p>
          
          <!-- Input Textarea -->
          <div class="mb-4">
            <textarea
              v-model="volunteerInputText"
              @input="updateVolunteerPreview"
              placeholder="Max Mustermann&#9;Schiedsrichter&#9;E&#10;Anna Schmidt&#9;Zeitnehmer&#9;C&#10;..."
              class="w-full border border-gray-300 rounded px-3 py-2 text-sm font-mono"
              rows="6"
            ></textarea>
          </div>
          
          <!-- Preview Grid -->
          <div v-if="volunteerPreview.length > 0 || submittedVolunteers.length > 0" class="mb-4">
            <div class="text-sm font-semibold text-gray-700 mb-2">
              Vorschau ({{ (volunteerPreview.length + submittedVolunteers.length) }} Einträge):
            </div>
            <div class="border border-gray-300 rounded overflow-hidden">
              <div class="overflow-x-auto max-h-64 overflow-y-auto">
                <table class="min-w-full text-sm">
                  <thead class="bg-gray-50 sticky top-0">
                    <tr>
                      <th class="px-3 py-2 text-left font-semibold text-gray-700 border-b">Name</th>
                      <th class="px-3 py-2 text-left font-semibold text-gray-700 border-b">Rolle</th>
                      <th class="px-3 py-2 text-left font-semibold text-gray-700 border-b">Programm</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-200">
                    <!-- Submitted volunteers (persistent) -->
                    <tr v-for="(vol, idx) in submittedVolunteers" :key="'submitted-' + idx" class="bg-green-50">
                      <td class="px-3 py-2">{{ vol.name }}</td>
                      <td class="px-3 py-2">{{ vol.role }}</td>
                      <td class="px-3 py-2">
                        <img 
                          v-if="vol.program === 'E' || vol.program === 'C'"
                          :src="programLogoSrc(vol.program)" 
                          :alt="programLogoAlt(vol.program)" 
                          class="w-5 h-5 inline-block"
                        />
                        <span v-else class="text-gray-400">–</span>
                      </td>
                    </tr>
                    <!-- Preview volunteers (pending) -->
                    <tr v-for="(vol, idx) in volunteerPreview" :key="'preview-' + idx">
                      <td class="px-3 py-2">{{ vol.name }}</td>
                      <td class="px-3 py-2">{{ vol.role }}</td>
                      <td class="px-3 py-2">
                        <img 
                          v-if="vol.program === 'E' || vol.program === 'C'"
                          :src="programLogoSrc(vol.program)" 
                          :alt="programLogoAlt(vol.program)" 
                          class="w-5 h-5 inline-block"
                        />
                        <span v-else class="text-gray-400">–</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          
          <!-- Action Buttons -->
          <div class="flex gap-2 flex-wrap">
            <button
              @click="clearAllVolunteers"
              class="px-4 py-2 rounded text-sm bg-gray-200 hover:bg-gray-300"
              :disabled="volunteerPreview.length === 0 && submittedVolunteers.length === 0"
            >
              Alles Löschen
            </button>
            <button
              @click="insertVolunteers"
              class="px-4 py-2 rounded text-sm bg-blue-200 hover:bg-blue-300"
              :disabled="!volunteerInputText.trim()"
            >
              Einfügen
            </button>
            <button
              @click="submitVolunteers"
              class="px-4 py-2 rounded text-sm bg-green-200 hover:bg-green-300"
              :disabled="volunteerPreview.length === 0"
            >
              Übernehmen
            </button>
            <button
              @click="downloadVolunteerLabelsPdf"
              class="px-4 py-2 rounded text-sm flex items-center gap-2 flex-shrink-0"
              :class="hasSubmittedVolunteers && !isDownloading['volunteer-labels']
                ? 'bg-gray-200 hover:bg-gray-300' 
                : 'bg-gray-100 cursor-not-allowed opacity-50'"
              :disabled="!hasSubmittedVolunteers || isDownloading['volunteer-labels']"
            >
              <svg v-if="isDownloading['volunteer-labels']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor"
                      d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
              </svg>
              <span>{{ isDownloading['volunteer-labels'] ? 'Erzeuge…' : 'PDF' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Match Plan Modal -->
    <div
      v-if="showMatchPlanModal"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/50"
      @click="closeMatchPlanModal"
    >
      <div 
        class="bg-white rounded-lg shadow-xl max-w-4xl w-full mx-4 max-h-[90vh] overflow-hidden"
        @click.stop
      >
        <!-- Modal Header -->
        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
          <h3 class="text-lg font-semibold text-gray-900" v-html="eventTitleNormalized"></h3>
          <button
            @click="closeMatchPlanModal"
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
                          :class="[
                            isEmptySlot(match.team_1) ? 'bg-gray-300 text-gray-700' : 'bg-blue-600',
                            isNoshow(match.team_1) ? 'line-through' : ''
                          ]"
                        >
                          {{ formatTeam(match.team_1) }}
                        </div>
                        
                        <!-- Team 2 (Right Column) -->
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="[
                            isEmptySlot(match.team_2) ? 'bg-gray-300 text-gray-700' : 'bg-blue-600',
                            isNoshow(match.team_2) ? 'line-through' : ''
                          ]"
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

    <!-- Worker Shifts Modal -->
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
          <h3 class="text-lg font-semibold text-gray-900">HERO Schichten</h3>
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
          <div v-if="isLoadingShifts" class="flex items-center justify-center py-8">
            <svg class="animate-spin h-8 w-8 text-blue-600" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span class="ml-3 text-gray-600">Lade Schichten...</span>
          </div>
          
          <div v-else-if="workerShifts?.error" class="text-center py-8 text-red-600">
            {{ workerShifts.error }}
          </div>
          
          <div v-else-if="workerShifts?.shifts" class="space-y-4">
            <p class="text-sm text-gray-600 italic">Zu jeder Zeile sollte in HERO eine Schicht angelegt werden.</p>
            <div class="overflow-x-auto">
              <table class="min-w-full border-collapse border border-gray-300">
                <thead>
                  <tr class="bg-gray-50">
                    <th class="border border-gray-300 px-4 py-2 text-left font-semibold text-gray-700">Datum</th>
                    <th class="border border-gray-300 px-4 py-2 text-left font-semibold text-gray-700">Treffpunkt</th>
                    <th class="border border-gray-300 px-4 py-2 text-left font-semibold text-gray-700">Beginn</th>
                    <th class="border border-gray-300 px-4 py-2 text-left font-semibold text-gray-700">Ende</th>
                    <th class="border border-gray-300 px-4 py-2 text-left font-semibold text-gray-700">Label</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="role in workerShifts.shifts" :key="role.role_name">
                    <tr v-for="(shift, index) in role.shifts" :key="`${role.role_name}-${shift.day}`" class="hover:bg-gray-50">
                      <td class="border border-gray-300 px-4 py-2 text-gray-700">{{ formatDate(shift.day) }}</td>
                      <td class="border border-gray-300 px-4 py-2 text-gray-700">{{ shift.start }}</td>
                      <td class="border border-gray-300 px-4 py-2 text-gray-700">{{ shift.start }}</td>
                      <td class="border border-gray-300 px-4 py-2 text-gray-700">{{ shift.end }}</td>
                      <td class="border border-gray-300 px-4 py-2 font-medium text-gray-900">{{ role.role_name }}</td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </div>
          
          <div v-else class="text-center py-8 text-gray-500">
            Keine Schichten verfügbar
          </div>
        </div>
      </div>
    </div>

    <!-- Optional: globales Overlay -->
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