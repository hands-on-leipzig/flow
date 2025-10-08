<script setup lang="ts">
import axios from 'axios'
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const readiness = ref({
  explore_teams_ok: true,
  challenge_teams_ok: true,
  room_mapping_ok: true,
})

async function checkDataReadiness() {
  if (!event.value?.id) return
  try {
    const { data } = await axios.get(`/export/ready/${event.value.id}`)
    readiness.value = {
      explore_teams_ok: !!data.explore_teams_ok,
      challenge_teams_ok: !!data.challenge_teams_ok,
      room_mapping_ok: !!data.room_mapping_ok,
    }
  } catch (error) {
    console.error('Fehler beim Laden der Daten-Readiness:', error)
    readiness.value = {
      explore_teams_ok: false,
      challenge_teams_ok: false,
      room_mapping_ok: false,
    }
  }
}

onMounted(async () => {
  if (!eventStore.selectedEvent) {
    await eventStore.fetchSelectedEvent()
  }
  await checkDataReadiness()
})

watch(() => event.value?.id, async (id) => {
  if (id) await checkDataReadiness()
})

const hasTeamIssues = computed(
  () => !readiness.value.explore_teams_ok || !readiness.value.challenge_teams_ok
)
const hasRoomIssues = computed(() => !readiness.value.room_mapping_ok)

async function downloadPdf(type: 'rooms' | 'teams' | 'roles' | 'full') {
  if (!event.value?.id) return
  const url = `/export/pdf_download/${type}/${event.value.id}`
  try {
    const response = await axios.get(url, { responseType: 'blob' })
    const filename = response.headers['x-filename'] || `FLOW_${type}.pdf`
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (e) {
    console.error(`Fehler beim Download von ${type}:`, e)
  }
}
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col">
    <h3 class="text-lg font-semibold mb-4">Pläne als PDF</h3>

    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">
          Rollen (Juror:innen / Gutachter:innen / Schiedsrichter:innen)
        </h4>
        <p class="text-sm text-gray-600">
          Eine Seite pro Rolle mit allen Aktivitäten.
        </p>

        <div v-if="hasTeamIssues" class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
          </svg>
          <span class="text-sm">
            Achtung: Die Anzahl der angemeldeten Teams stimmt nicht mit der Anzahl im Plan überein.<br>
            Das PDF sollte so nicht gedruckt werden.
          </span>
        </div>
      </div>

      <button
        class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300"
        @click="downloadPdf('roles')">
        PDF
      </button>
    </div>

    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Teams</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Team mit allen Aktivitäten.</p>

        <div v-if="hasTeamIssues" class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
          </svg>
          <span class="text-sm">
            Achtung: Die Anzahl der angemeldeten Teams stimmt nicht mit der Anzahl im Plan überein.<br>
            Das PDF sollte so nicht gedruckt werden.
          </span>
        </div>

      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('teams')">PDF</button> 
    </div>

    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Räume</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Raum mit allen Aktivitäten.</p>
        
        <div v-if="hasRoomIssues" class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mr-2 mt-0.5 flex-shrink-0 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
              d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
          </svg>
          <span class="text-sm">
            Achtung: Es wurden noch nicht alle Aktivitäten und Teams auf die Räume verteilt.<br>
            Das PDF sollte so nicht gedruckt werden.
          </span>
        </div>

      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('rooms')">PDF</button>
    </div>

    <div class="flex justify-between items-center">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Gesamtplan</h4>
        <p class="text-sm text-gray-600">Volle Details, aber in einfacher Formatierung.</p>
        <p class="text-xs text-gray-500">Nur für den Veranstalter – nicht für Teams oder Besucher.</p>
      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('full')">PDF</button>
    </div>
  </div>
</template>