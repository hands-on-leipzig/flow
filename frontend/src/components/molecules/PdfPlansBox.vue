<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import axios from 'axios'

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
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col">
    <h3 class="text-lg font-semibold mb-4">Pläne als PDF</h3>

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

      <!-- PDF Button -->
      <div class="mt-4 flex justify-end">
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

      <!-- PDF Button -->
      <div class="mt-4 flex justify-end">
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

    <!-- Gesamtplan -->
    <div>
      <div class="mb-2">
        <h4 class="text-base font-semibold text-gray-800">Gesamtplan</h4>
        <p class="text-sm text-gray-600">Volle Details, aber in einfacher Formatierung.</p>
        <p class="text-xs text-gray-500">Nur für den Veranstalter – nicht für Teams oder Besucher.</p>
      </div>

      <!-- PDF Button -->
      <div class="mt-4 flex justify-end">
        <button
          class="px-4 py-2 rounded text-sm flex items-center gap-2"
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