<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getEventTitleLong } from '@/utils/eventTitle'
import axios from 'axios'
import AccordionArrow from "@/components/icons/IconAccordionArrow.vue"
import {showGlassToast} from '@/composables/useGlassToast'
import {hasChallenge, eventPrograms, programId, programDisplayName, catalogNameFromCode, type EventProgramRef} from '@/utils/eventPrograms'


const props = withDefaults(
  defineProps<{
    /** Hide inner title when the page already provides one. */
    hideHeading?: boolean
    /** Which panels to show (Ausgabe splits plans vs name tags). */
    section?: 'plans' | 'labels' | 'all'
  }>(),
  {hideHeading: false, section: 'all'}
)

const showPlans = computed(() => props.section === 'plans' || props.section === 'all')
const showLabels = computed(() => props.section === 'labels' || props.section === 'all')

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

const roleProgramGroups = computed(() =>
  eventPrograms(event.value)
    .map((program) => ({
      program,
      roles: availableRoles.value.filter((role) => role.first_program === programId(program)),
    }))
    .filter((group) => group.roles.length > 0)
)

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
const availableTeamPrograms = ref<EventProgramRef[]>([])
const selectedProgramIds = ref<Set<number>>(new Set())

const hasChallengeTeams = computed(() => availableTeamPrograms.value.some(p => programId(p) === 3))

async function fetchAvailableTeamPrograms() {
  if (!eventId.value) return
  try {
    const { data } = await axios.get(`/export/available-team-programs/${eventId.value}`)
    availableTeamPrograms.value = eventPrograms({
      programs: (data.programs || []).map((program: { id: number; name: string; sequence?: number }) => ({
        ...program,
        first_program: program.id,
      })),
    })
    selectedProgramIds.value = new Set(availableTeamPrograms.value.map((program) => programId(program)))
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
    await loadPosterPreviews()
  }
})

// --- Wenn Event wechselt, Readiness nachladen ---
watch(() => event.value?.id, async (id) => {
  if (id) {
    await eventStore.refreshReadiness(id)
    await fetchAvailableRoles()
    await fetchAvailableTeamPrograms()
    await loadPosterPreviews()
  }
})

// --- Computed Flags ---
const hasTeamIssues = computed(
  () => !readiness.value?.explore_teams_ok || !readiness.value?.challenge_teams_ok
)
const hasRoomIssues = computed(() => !readiness.value?.room_mapping_ok)
const hasWifiSsid = computed(() => !!event.value?.wifi_ssid?.trim())

// --- PDF Download (Composable) ---
const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()

// --- Aushang-Poster (Online-Plan / WLAN) ---
const previewPlan = ref<string | null>(null)
const previewPlanWifi = ref<string | null>(null)

async function loadPosterPreview(type: 'plan' | 'plan_wifi') {
  if (!eventId.value) return
  try {
    const {data} = await axios.get(`/publish/pdf_preview/${type}/${eventId.value}?_=${Date.now()}`)
    if (type === 'plan') previewPlan.value = data
    else previewPlanWifi.value = data
  } catch (e) {
    console.error(`Poster-Preview (${type}) fehlgeschlagen:`, e)
  }
}

async function loadPosterPreviews() {
  await Promise.all([loadPosterPreview('plan'), loadPosterPreview('plan_wifi')])
}

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
    showGlassToast('Fehler beim Herunterladen der Raumnutzung. Bitte versuche es erneut.', 'error')
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

// Download slot assignments PDF
async function downloadSlotAssignmentsPdf() {
  if (!eventId.value) return
  
  isDownloading.value['slot-assignments'] = true
  try {
    const planResponse = await axios.get(`/plans/event/${eventId.value}`)
    const planId = planResponse.data.id

    const response = await axios.get(
      `/export/slot-assignments/${planId}`,
      { responseType: 'blob' }
    )

    const filename = response.headers['x-filename'] || 'Slot-Zuordnung.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error) {
    console.error('Fehler beim PDF-Download (Slot-Zuordnung):', error)
  } finally {
    isDownloading.value['slot-assignments'] = false
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

// --- Team Label Filters ---
// Track person types per program: { programId: { players: boolean, coaches: boolean } }
const teamLabelFilters = ref<Record<number, { players: boolean; coaches: boolean }>>({})

// Skip labels offset (0-9)
const teamLabelSkipOffset = ref(0)
const volunteerLabelSkipOffset = ref(0)

// Initialize filters for available programs
watch(availableTeamPrograms, (programs) => {
  programs.forEach(program => {
    if (!teamLabelFilters.value[program.id]) {
      teamLabelFilters.value[program.id] = {
        players: true,
        coaches: true
      }
    }
  })
}, { immediate: true })

// Toggle person type for a specific program
function toggleTeamLabelPersonType(programId: number, type: 'players' | 'coaches') {
  if (!teamLabelFilters.value[programId]) {
    teamLabelFilters.value[programId] = { players: true, coaches: true }
  }
  teamLabelFilters.value[programId][type] = !teamLabelFilters.value[programId][type]
  teamLabelFilters.value = { ...teamLabelFilters.value } // Trigger reactivity
}

// Computed: at least one program with at least one person type selected
const canDownloadTeamLabels = computed(() => {
  return Object.keys(teamLabelFilters.value).some(programId => {
    const filters = teamLabelFilters.value[Number(programId)]
    return filters && (filters.players || filters.coaches)
  })
})

// Download name tags PDF with filters
async function downloadNameTagsPdf() {
  if (!eventId.value || !canDownloadTeamLabels.value) return
  
  // Build filter object: for each selected program, include person types
  const programFilters: Record<number, { players: boolean; coaches: boolean }> = {}
  Object.keys(teamLabelFilters.value).forEach(programIdStr => {
    const programId = Number(programIdStr)
    const filters = teamLabelFilters.value[programId]
    if (filters && (filters.players || filters.coaches)) {
      programFilters[programId] = filters
    }
  })
  
  const filters = {
    program_filters: programFilters,
    skip_offset: teamLabelSkipOffset.value
  }
  
  isDownloading.value['name-tags'] = true
  try {
    const response = await axios.post(
      `/export/name-tags/${eventId.value}`,
      filters,
      { responseType: 'blob' }
    )
    
    const filename = response.headers['x-filename'] || 'FLOW_Aufkleber_Teams.pdf'
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (error: any) {
    console.error('Fehler beim PDF-Download (Team Labels):', error)
    const errorMessage = error.response?.data?.message || error.message || 'Unbekannter Fehler'
    showGlassToast('Fehler beim Erstellen des PDFs: ' + errorMessage, 'error')
  } finally {
    isDownloading.value['name-tags'] = false
  }
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
      { 
        volunteers: submittedVolunteers.value,
        skip_offset: volunteerLabelSkipOffset.value
      },
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
    showGlassToast('Fehler beim Erstellen des PDFs: ' + errorMessage, 'error')
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

</script>

<template>
  <div class="pdf-plans">
    <section v-if="showPlans" class="glass-card liquid-surface-inner pdf-plans__panel">
      <h3 v-if="!hideHeading" class="glass-card__heading">Drucksachen</h3>

      <p class="pdf-plans__group-label">
        <i class="bi bi-people" aria-hidden="true"/>
        <span>Zum Aushang bzw. zum Verteilen an Teams und Volunteers</span>
      </p>
      <div class="pdf-plans__grid">

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Online-Plan</h4>
          <p class="pdf-plans__tile-sub">Aushang mit QR zum öffentlichen Plan-Link.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <img
            v-if="previewPlan"
            :src="previewPlan"
            alt="Vorschau Online-Plan PDF"
            class="pdf-plans__preview"
          />
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.plan ? '!opacity-50' : ''"
            :disabled="isDownloading.plan || !eventId"
            @click="downloadPdf('plan', `/publish/pdf_download/plan/${eventId}`, 'Plan.pdf')"
          >
            <svg v-if="isDownloading.plan" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.plan ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">WLAN-Zugang</h4>
          <p class="pdf-plans__tile-sub">Druckposter mit Netzwerkdaten — Zugang unter WLAN vor Ort pflegen.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <template v-if="hasWifiSsid">
            <img
              v-if="previewPlanWifi"
              :src="previewPlanWifi"
              alt="Vorschau WLAN-PDF"
              class="pdf-plans__preview"
            />
          </template>
          <p v-else class="pdf-plans__tile-note">SSID fehlt — unter WLAN vor Ort eintragen.</p>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.plan_wifi || !hasWifiSsid ? '!opacity-50' : ''"
            :disabled="isDownloading.plan_wifi || !eventId || !hasWifiSsid"
            @click="downloadPdf('plan_wifi', `/publish/pdf_download/plan_wifi/${eventId}`, 'Plan_WLAN.pdf')"
          >
            <svg v-if="isDownloading.plan_wifi" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.plan_wifi ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Übersichtsplan</h4>
          <p class="pdf-plans__tile-sub">Alle öffentlichen Aktivitäten des Tages auf einer Seite.</p>
        </header>
        <div class="pdf-plans__tile-body"></div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.overview ? '!opacity-50' : ''"
            :disabled="isDownloading.overview"
            @click="downloadEventOverviewPdf()"
          >
            <svg v-if="isDownloading.overview" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.overview ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Räume</h4>
          <p class="pdf-plans__tile-sub">Eine Seite pro Raum mit allen Aktivitäten.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <p v-if="hasRoomIssues" class="pdf-plans__tile-warn">
            Noch nicht alle Aktivitäten und Teams auf Räume verteilt.
          </p>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloadingCsv ? '!opacity-50' : ''"
            :disabled="isDownloadingCsv"
            @click="downloadRoomUtilizationCsv"
          >
            <svg v-if="isDownloadingCsv" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloadingCsv ? 'Erzeuge…' : 'CSV' }}</span>
          </button>
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.rooms ? '!opacity-50' : ''"
            :disabled="isDownloading.rooms"
            @click="downloadPdf('rooms', `/export/pdf_download/rooms/${eventId}`, 'Räume.pdf')"
          >
            <svg v-if="isDownloading.rooms" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.rooms ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Rollen</h4>
          <p class="pdf-plans__tile-sub">Eine Seite pro Rolle mit allen Aktivitäten.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <p v-if="hasTeamIssues" class="pdf-plans__tile-warn">Teamanzahl weicht vom Plan ab.</p>
          <p v-if="availableRoles.length === 0" class="pdf-plans__tile-note">Keine Rollen mit Aktivitäten im Plan.</p>
          <div v-else class="pdf-plans__tile-scroll">
            <div
              v-for="group in roleProgramGroups"
              :key="programId(group.program)"
              class="pdf-plans__option-group"
            >
              <h5 class="pdf-plans__option-heading">
                <img
                  :src="programLogoSrc(group.program)"
                  :alt="programLogoAlt(group.program)"
                  class="w-5 h-5 flex-shrink-0"
                />
                <span>{{ programDisplayName(group.program) }}</span>
              </h5>
              <label
                v-for="role in group.roles"
                :key="role.id"
                class="pdf-plans__option"
              >
                <input
                  type="checkbox"
                  :checked="selectedRoleIds.has(role.id)"
                  class="accent-[var(--color-accent)]"
                  @change="toggleRole(role.id)"
                />
                <span>{{ role.name }}</span>
              </label>
            </div>
          </div>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button type="button" class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm" @click="showWorkerShiftsModal">
            HERO Schichten
          </button>
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="!(hasSelectedRoles && !isDownloading.roles) ? '!opacity-50' : ''"
            :disabled="!hasSelectedRoles || isDownloading.roles"
            @click="downloadRolesPdf"
          >
            <svg v-if="isDownloading.roles" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.roles ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Teams</h4>
          <p class="pdf-plans__tile-sub">Eine Seite pro Team mit allen Aktivitäten.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <p v-if="hasTeamIssues" class="pdf-plans__tile-warn">Teamanzahl weicht vom Plan ab.</p>
          <p v-if="availableTeamPrograms.length === 0" class="pdf-plans__tile-note">Keine Teams im Plan.</p>
          <div v-else class="pdf-plans__tile-scroll">
            <div
              v-for="program in availableTeamPrograms"
              :key="program.id"
              class="pdf-plans__option-group"
            >
              <h5 class="pdf-plans__option-heading">
                <img
                  :src="programLogoSrc(program)"
                  :alt="programLogoAlt(program)"
                  class="w-5 h-5 flex-shrink-0"
                />
                <span>{{ programDisplayName(program) }}</span>
              </h5>
              <label class="pdf-plans__option">
                <input
                  type="checkbox"
                  :checked="selectedProgramIds.has(program.id)"
                  class="accent-[var(--color-accent)]"
                  @change="toggleTeamProgram(program.id)"
                />
                <span>Alle Teams</span>
              </label>
            </div>
          </div>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="!(hasSelectedPrograms && !isDownloading.teams) ? '!opacity-50' : ''"
            :disabled="!hasSelectedPrograms || isDownloading.teams"
            @click="downloadTeamsPdf"
          >
            <svg v-if="isDownloading.teams" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.teams ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>
      </div>

      <p class="pdf-plans__group-label pdf-plans__group-label--next">
        <i class="bi bi-shield-lock" aria-hidden="true"/>
        <span>Nur für Veranstalter – nicht für Teams oder Besucher.</span>
      </p>
      <div class="pdf-plans__grid">

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Teamliste</h4>
          <p class="pdf-plans__tile-sub">
            Teams mit Räumen und Gutachter-/Jury-Gruppen — für Check-In und Briefings.
          </p>
        </header>
        <div class="pdf-plans__tile-body"></div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading['team-list'] ? '!opacity-50' : ''"
            :disabled="isDownloading['team-list']"
            @click="downloadTeamListPdf"
          >
            <svg v-if="isDownloading['team-list']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['team-list'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Moderation</h4>
          <p class="pdf-plans__tile-sub">
            Moderierte Aktivitäten und vollständiger Robot-Game-Matchplan.
          </p>
        </header>
        <div class="pdf-plans__tile-body"></div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading['moderator-match-plan'] ? '!opacity-50' : ''"
            :disabled="isDownloading['moderator-match-plan']"
            @click="downloadModeratorMatchPlanPdf"
          >
            <svg v-if="isDownloading['moderator-match-plan']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['moderator-match-plan'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Slot-Zuordnung</h4>
          <p class="pdf-plans__tile-sub">
            Pro Slot-Block alle Team-Zuordnungen in chronologischer Reihenfolge.
          </p>
        </header>
        <div class="pdf-plans__tile-body"></div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading['slot-assignments'] ? '!opacity-50' : ''"
            :disabled="isDownloading['slot-assignments']"
            @click="downloadSlotAssignmentsPdf"
          >
            <svg v-if="isDownloading['slot-assignments']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['slot-assignments'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article
        v-if="hasChallengeTeams || hasChallenge(event)"
        class="pdf-plans__tile liquid-surface-inner"
      >
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Match-Plan SCORE</h4>
          <p class="pdf-plans__tile-sub">
            Vorrunden-Matches zum Übernehmen in
            <a
              href="https://evaluation.hands-on-technology.org/"
              target="_blank"
              rel="noopener noreferrer"
              class="text-[var(--color-accent)] underline hover:opacity-80"
            >SCORE</a>.
          </p>
        </header>
        <div class="pdf-plans__tile-body">
          <p class="pdf-plans__tile-note">Reihenfolge in SCORE an FLOW anpassen.</p>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button type="button" class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm" @click="openMatchPlanModal">
            Online
          </button>
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading['match-plan'] ? '!opacity-50' : ''"
            :disabled="isDownloading['match-plan']"
            @click="downloadMatchPlanPdf"
          >
            <svg v-if="isDownloading['match-plan']" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading['match-plan'] ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Gesamtplan</h4>
          <p class="pdf-plans__tile-sub">Volle Details in einfacher Formatierung.</p>
        </header>
        <div class="pdf-plans__tile-body"></div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.full ? '!opacity-50' : ''"
            :disabled="isDownloading.full"
            @click="downloadPdf('full', `/export/pdf_download/full/${eventId}`, 'Gesamtplan.pdf')"
          >
            <svg v-if="isDownloading.full" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.full ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>
      </div>
    </section>

    <template v-if="showLabels">
      <section class="glass-card liquid-surface-inner pdf-plans__panel">
        <h3 v-if="!hideHeading" class="glass-card__heading">Namensschilder</h3>
        <p class="glass-settings-hint !not-italic mb-3">
          Namensaufkleber zum Drucken auf A4-Papier
        </p>
        <p class="text-sm text-[var(--color-text-muted)] mb-3">
          Die PDF-Dateien sind passend zum
          <a
            href="https://www.avery-zweckform.com/vorlage-l4785"
            target="_blank"
            rel="noopener noreferrer"
            class="text-[var(--color-accent)] underline hover:opacity-80"
          >Format Avery L4785</a>
          formatiert.
          Jeder Aufkleber enthält den Namen der Person, den Team-Namen bzw. die Rolle sowie die Logos (Programm, Saison, Veranstalter).
          Als Veranstalter-Logo wird das erste aktive aus dem
          <a
            href="/plan/publish/logos"
            class="text-[var(--color-accent)] underline hover:opacity-80"
          >View Logos</a>
          verwendet.
        </p>
        <p class="text-sm text-[var(--color-text-muted)] mb-0">
          Mit „Überspringen“ können die ersten Aufkleber auf dem ersten Blatt übersprungen werden, um teilweise bereits verwendete Blätter weiter zu nutzen und Material zu sparen.
        </p>
      </section>

      <div class="pdf-plans__labels-cols">
        <section class="glass-card liquid-surface-inner pdf-plans__panel">
          <h4 class="pdf-plans__row-title mb-2">Für Teams</h4>
          <p class="text-sm text-[var(--color-text-muted)] mb-4">
            Ein Aufkleber für jedes Teammitglied und alle Coach:innen. Die Liste wird automatisch aus den Anmeldedaten der Teams generiert.
            „No-Show“-Teams und Teams, die nicht im aktuellen Plan enthalten sind, werden <em>nicht</em> in das PDF übernommen.
          </p>

          <div
            v-if="availableTeamPrograms.length > 0"
            class="mb-4 grid gap-3 grid-cols-1"
          >
            <div
              v-for="program in availableTeamPrograms"
              :key="program.id"
              class="bg-[var(--color-bg-muted)] rounded p-3"
            >
              <h5 class="text-sm font-semibold text-[var(--color-text-muted)] mb-2 flex items-center gap-2">
                <img
                  :src="programLogoSrc(program)"
                  :alt="programLogoAlt(program)"
                  class="w-6 h-6 flex-shrink-0"
                />
                <span>FIRST LEGO League {{ programDisplayName(program) }}</span>
              </h5>
              <div class="space-y-0.5">
                <label class="flex items-center gap-2 cursor-pointer hover:bg-[var(--color-bg-hover)] p-1 rounded">
                  <input
                    type="checkbox"
                    :checked="teamLabelFilters[program.id]?.players ?? true"
                    @change="toggleTeamLabelPersonType(program.id, 'players')"
                    class="accent-blue-600"
                  />
                  <span class="text-sm">Teammitglieder</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer hover:bg-[var(--color-bg-hover)] p-1 rounded">
                  <input
                    type="checkbox"
                    :checked="teamLabelFilters[program.id]?.coaches ?? true"
                    @change="toggleTeamLabelPersonType(program.id, 'coaches')"
                    class="accent-blue-600"
                  />
                  <span class="text-sm">Coach:innen</span>
                </label>
              </div>
            </div>
          </div>

          <div class="flex items-center justify-end gap-2">
            <label class="flex items-center gap-1 text-sm text-[var(--color-text-muted)]">
              <span class="text-xs">Überspringen:</span>
              <input
                type="number"
                v-model.number="teamLabelSkipOffset"
                min="0"
                max="9"
                class="w-12 border border-[var(--color-border)] rounded px-1 py-0.5 text-sm text-center"
              />
            </label>
            <button
              class="glass-btn-secondary !px-4 !py-2 !text-sm inline-flex items-center gap-2"
              :class="!(canDownloadTeamLabels && !isDownloading['name-tags']) ? '!opacity-50' : ''"
              :disabled="!canDownloadTeamLabels || isDownloading['name-tags']"
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
        </section>

        <section class="glass-card liquid-surface-inner pdf-plans__panel">
          <h4 class="pdf-plans__row-title mb-2">Für Volunteers</h4>
          <p class="text-sm text-[var(--color-text-muted)] mb-3">
            Hier kann eine einfache Liste von Rollen und Namen hochgeladen werden, aus der dann ein PDF erzeugt wird.
          </p>
          <p class="text-xs text-[var(--color-text-subtle)] mb-4">
            Format: Name, Rolle, Programm (E für Explore, C für Challenge, leer für kein Logo).
            Spalten können durch Tab oder Komma getrennt sein.
          </p>

          <div class="mb-4">
            <textarea
              v-model="volunteerInputText"
              @input="updateVolunteerPreview"
              placeholder="Max Mustermann&#9;Gutachter&#9;E&#10;Anna Schmidt&#9;Schiedsrichter:in&#9;C&#10;..."
              class="w-full border border-[var(--color-border)] rounded px-3 py-2 text-sm font-mono"
              rows="6"
            ></textarea>
          </div>

          <div v-if="volunteerPreview.length > 0 || submittedVolunteers.length > 0" class="mb-4">
            <div class="text-sm font-semibold text-[var(--color-text-muted)] mb-2">
              Vorschau ({{ (volunteerPreview.length + submittedVolunteers.length) }} Einträge):
            </div>
            <div class="border border-[var(--color-border)] rounded overflow-hidden">
              <div class="overflow-x-auto max-h-64 overflow-y-auto">
                <table class="min-w-full text-sm">
                  <thead class="bg-[var(--color-bg-muted)] sticky top-0">
                    <tr>
                      <th class="px-3 py-2 text-left font-semibold text-[var(--color-text-muted)] border-b">Name</th>
                      <th class="px-3 py-2 text-left font-semibold text-[var(--color-text-muted)] border-b">Rolle</th>
                      <th class="px-3 py-2 text-left font-semibold text-[var(--color-text-muted)] border-b">Programm</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-gray-200">
                    <tr v-for="(vol, idx) in submittedVolunteers" :key="'submitted-' + idx" class="bg-green-50">
                      <td class="px-3 py-2">{{ vol.name }}</td>
                      <td class="px-3 py-2">{{ vol.role }}</td>
                      <td class="px-3 py-2">
                        <img
                          :src="programLogoSrc(catalogNameFromCode(vol.program))"
                          :alt="programLogoAlt(catalogNameFromCode(vol.program))"
                          class="w-5 h-5 inline-block"
                        />
                      </td>
                    </tr>
                    <tr v-for="(vol, idx) in volunteerPreview" :key="'preview-' + idx">
                      <td class="px-3 py-2">{{ vol.name }}</td>
                      <td class="px-3 py-2">{{ vol.role }}</td>
                      <td class="px-3 py-2">
                        <img
                          :src="programLogoSrc(catalogNameFromCode(vol.program))"
                          :alt="programLogoAlt(catalogNameFromCode(vol.program))"
                          class="w-5 h-5 inline-block"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div class="flex gap-2 flex-wrap justify-between items-center">
            <div class="flex gap-2">
              <button
                @click="clearAllVolunteers"
                class="glass-btn-secondary !px-4 !py-2 !text-sm"
                :disabled="volunteerPreview.length === 0 && submittedVolunteers.length === 0"
              >
                Alles Löschen
              </button>
              <button
                @click="submitVolunteers"
                class="px-4 py-2 rounded text-sm bg-green-200 hover:bg-green-300"
                :disabled="volunteerPreview.length === 0"
              >
                Übernehmen
              </button>
            </div>
            <div class="flex items-center gap-2">
              <label class="flex items-center gap-1 text-sm text-[var(--color-text-muted)]">
                <span class="text-xs">Überspringen:</span>
                <input
                  type="number"
                  v-model.number="volunteerLabelSkipOffset"
                  min="0"
                  max="9"
                  class="w-12 border border-[var(--color-border)] rounded px-1 py-0.5 text-sm text-center"
                />
              </label>
              <button
                @click="downloadVolunteerLabelsPdf"
                class="glass-btn-secondary !px-4 !py-2 !text-sm inline-flex items-center gap-2 flex-shrink-0"
                :class="!(hasSubmittedVolunteers && !isDownloading['volunteer-labels']) ? '!opacity-50' : ''"
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
        </section>
      </div>
    </template>

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
        <div class="px-6 py-4 border-b border-[var(--color-border)] flex justify-between items-center">
          <h3 class="text-lg font-semibold text-[var(--color-text)]" v-html="eventTitleNormalized"></h3>
          <button
            @click="closeMatchPlanModal"
            class="text-[var(--color-text-subtle)] hover:text-[var(--color-text-muted)] transition-colors"
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
                  class="w-full text-left px-4 py-2 bg-[var(--color-bg-muted)] font-semibold text-black uppercase flex justify-between items-center"
                  @click="toggleRound(option.value)"
                >
                  {{ option.label }}
                  <AccordionArrow :opened="openRound === option.value"/>
                </button>
                <transition name="fade">
                  <div v-if="openRound === option.value" class="p-4">
                    <div v-if="isLoadingMatches" class="flex items-center justify-center py-8">
                      <svg class="animate-spin h-8 w-8 text-[var(--color-accent)]" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
                      </svg>
                      <span class="ml-3 text-[var(--color-text-muted)]">Lade Matches...</span>
                    </div>
                    
                    <div v-else-if="matches.length === 0" class="text-center py-8 text-[var(--color-text-subtle)]">
                      Keine Matches gefunden
                    </div>
                    
                    <!-- Match Grid -->
                    <div v-else class="grid grid-cols-2 gap-3">
                      <template v-for="match in matches" :key="match.match_no">
                        <!-- Team 1 (Left Column) -->
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="[
                            isEmptySlot(match.team_1) ? 'bg-gray-300 text-[var(--color-text-muted)]' : 'bg-blue-600',
                            isNoshow(match.team_1) ? 'line-through' : ''
                          ]"
                        >
                          {{ formatTeam(match.team_1) }}
                        </div>
                        
                        <!-- Team 2 (Right Column) -->
                        <div
                          class="px-4 py-2 rounded text-white text-sm font-medium"
                          :class="[
                            isEmptySlot(match.team_2) ? 'bg-gray-300 text-[var(--color-text-muted)]' : 'bg-blue-600',
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
        <div class="px-6 py-4 border-b border-[var(--color-border)] flex justify-between items-center">
          <h3 class="text-lg font-semibold text-[var(--color-text)]">HERO Schichten</h3>
          <button
            @click="closeModal"
            class="text-[var(--color-text-subtle)] hover:text-[var(--color-text-muted)] transition-colors"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
        
        <!-- Modal Content -->
        <div class="px-6 py-4 overflow-y-auto max-h-[calc(90vh-120px)]">
          <div v-if="isLoadingShifts" class="flex items-center justify-center py-8">
            <svg class="animate-spin h-8 w-8 text-[var(--color-accent)]" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span class="ml-3 text-[var(--color-text-muted)]">Lade Schichten...</span>
          </div>
          
          <div v-else-if="workerShifts?.error" class="text-center py-8 text-red-600">
            {{ workerShifts.error }}
          </div>
          
          <div v-else-if="workerShifts?.shifts" class="space-y-4">
            <p class="text-sm text-[var(--color-text-muted)] italic">Zu jeder Zeile sollte in HERO eine Schicht angelegt werden.</p>
            <div class="overflow-x-auto">
              <table class="min-w-full border-collapse border border-[var(--color-border)]">
                <thead>
                  <tr class="bg-[var(--color-bg-muted)]">
                    <th class="border border-[var(--color-border)] px-4 py-2 text-left font-semibold text-[var(--color-text-muted)]">Datum</th>
                    <th class="border border-[var(--color-border)] px-4 py-2 text-left font-semibold text-[var(--color-text-muted)]">Treffpunkt</th>
                    <th class="border border-[var(--color-border)] px-4 py-2 text-left font-semibold text-[var(--color-text-muted)]">Beginn</th>
                    <th class="border border-[var(--color-border)] px-4 py-2 text-left font-semibold text-[var(--color-text-muted)]">Ende</th>
                    <th class="border border-[var(--color-border)] px-4 py-2 text-left font-semibold text-[var(--color-text-muted)]">Label</th>
                  </tr>
                </thead>
                <tbody>
                  <template v-for="role in workerShifts.shifts" :key="role.role_name">
                    <tr v-for="(shift, index) in role.shifts" :key="`${role.role_name}-${shift.day}`" class="hover:bg-[var(--color-bg-hover)]">
                      <td class="border border-[var(--color-border)] px-4 py-2 text-[var(--color-text-muted)]">{{ formatDate(shift.day) }}</td>
                      <td class="border border-[var(--color-border)] px-4 py-2 text-[var(--color-text-muted)]">{{ shift.start }}</td>
                      <td class="border border-[var(--color-border)] px-4 py-2 text-[var(--color-text-muted)]">{{ shift.start }}</td>
                      <td class="border border-[var(--color-border)] px-4 py-2 text-[var(--color-text-muted)]">{{ shift.end }}</td>
                      <td class="border border-[var(--color-border)] px-4 py-2 font-medium text-[var(--color-text)]">{{ role.role_name }}</td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
          </div>
          
          <div v-else class="text-center py-8 text-[var(--color-text-subtle)]">
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
      <div class="glass-row-item inline-flex px-4 py-3 gap-2">
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
.pdf-plans {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.pdf-plans__panel {
  min-width: 0;
}

.pdf-plans__labels-cols {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 1rem;
  align-items: start;
}

@media (max-width: 900px) {
  .pdf-plans__labels-cols {
    grid-template-columns: 1fr;
  }
}

.pdf-plans__group-label {
  margin: 0 0 0.75rem;
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  font-size: 0.82rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.pdf-plans__group-label .bi {
  flex-shrink: 0;
  margin-top: 0.12rem;
  font-size: 1rem;
  color: var(--color-accent);
}

.pdf-plans__group-label--next {
  margin-top: 1.35rem;
  padding-top: 1.1rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
}

.pdf-plans__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16.5rem, 1fr));
  gap: 0.85rem;
  align-items: stretch;
}

.pdf-plans__tile {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-height: 13.75rem;
  height: 100%;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.pdf-plans__tile-head {
  min-height: 3.6rem;
}

.pdf-plans__tile-title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  color: var(--color-text);
  line-height: 1.3;
}

.pdf-plans__tile-sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.pdf-plans__tile-body {
  flex: 1 1 auto;
  min-height: 3.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.pdf-plans__tile-scroll {
  max-height: 5.75rem;
  overflow-y: auto;
  padding-right: 0.15rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.pdf-plans__tile-actions {
  margin-top: auto;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  justify-content: flex-end;
  align-items: center;
}

.pdf-plans__tile-note,
.pdf-plans__tile-warn {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
}

.pdf-plans__tile-note {
  color: var(--color-text-muted);
}

.pdf-plans__tile-warn {
  color: color-mix(in srgb, #b45309 75%, var(--color-text));
  background: color-mix(in srgb, #f59e0b 12%, transparent);
  border: 1px solid color-mix(in srgb, #f59e0b 28%, transparent);
  border-radius: 8px;
  padding: 0.4rem 0.55rem;
}

.pdf-plans__option-group + .pdf-plans__option-group {
  margin-top: 0.2rem;
  padding-top: 0.35rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.pdf-plans__option-heading {
  margin: 0 0 0.25rem;
  display: flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.72rem;
  font-weight: 650;
  color: var(--color-text-muted);
}

.pdf-plans__option {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.15rem 0.2rem;
  border-radius: 6px;
  font-size: 0.8rem;
  color: var(--color-text);
  cursor: pointer;
}

.pdf-plans__option:hover {
  background: var(--color-bg-hover);
}

.pdf-plans__preview {
  height: 3.25rem;
  width: auto;
  max-width: 5.5rem;
  object-fit: contain;
  border-radius: 6px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: #fff;
}

/* Aufkleber keeps stacked list layout */
.pdf-plans__list {
  display: flex;
  flex-direction: column;
}

.pdf-plans__row {
  padding: 0.85rem 0;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
}

.pdf-plans__row:last-child {
  border-bottom: none;
  padding-bottom: 0.15rem;
}

.pdf-plans__row-title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.01em;
  color: var(--color-text);
  line-height: 1.3;
}

.pdf-plans__row-sub {
  margin: 0.2rem 0 0;
  font-size: 0.82rem;
  color: var(--color-text-muted);
  line-height: 1.4;
}

.fade-enter-active, .fade-leave-active {
  transition: all 0.2s ease;
}

.fade-enter-from, .fade-leave-to {
  opacity: 0;
  transform: translateY(-0.5rem);
}

@media (max-width: 640px) {
  .pdf-plans__grid {
    grid-template-columns: 1fr;
  }
}
</style>