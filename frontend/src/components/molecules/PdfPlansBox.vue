<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

// --- Readiness direkt aus Store ---
const readiness = computed(() => eventStore.readiness)

// --- Beim Start sicherstellen, dass Event & Readiness geladen sind ---
onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  if (eventStore.selectedEvent?.id) await eventStore.refreshReadiness(eventStore.selectedEvent.id)
})

// --- Wenn Event wechselt, Readiness nachladen ---
watch(() => event.value?.id, async (id) => {
  if (id) await eventStore.refreshReadiness(id)
})

// --- Computed Flags ---
const hasTeamIssues = computed(
  () => !readiness.value?.explore_teams_ok || !readiness.value?.challenge_teams_ok
)
const hasRoomIssues = computed(() => !readiness.value?.room_mapping_ok)

// --- PDF Download (Composable) ---
const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()

const pdfButtons = computed(() => {
  if (!eventId.value) return []
  return [
    { key: 'rooms', label: 'Räume-PDF', url: `/export/pdf_download/rooms/${eventId.value}` },
    { key: 'teams', label: 'Teams-PDF', url: `/export/pdf_download/teams/${eventId.value}` },
    { key: 'roles', label: 'Rollen-PDF', url: `/export/pdf_download/roles/${eventId.value}` },
    { key: 'full',  label: 'Gesamtplan', url: `/export/pdf_download/full/${eventId.value}` },
    { key: 'wifi',  label: 'Plan + WLAN', url: `/publish/pdf_download/plan_wifi/${eventId.value}` },
    { key: 'qr',    label: 'QR Codes', url: `/publish/pdf_download/qr_codes/${eventId.value}` },
  ]
})
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col">
    <h3 class="text-lg font-semibold mb-4">Pläne als PDF</h3>

    <!-- Rollen -->
    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">
          Rollen (Juror:innen / Gutachter:innen / Schiedsrichter:innen)
        </h4>
        <p class="text-sm text-gray-600">Eine Seite pro Rolle mit allen Aktivitäten.</p>

        <div
          v-if="hasTeamIssues"
          class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
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
      </div>

      <button
        class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
        :disabled="isDownloading.roles"
        @click="downloadPdf('roles', `/export/pdf_download/roles/${eventId}`, 'Rollen.pdf')"
      >
        <svg v-if="isDownloading.roles" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>{{ isDownloading.roles ? 'Erzeuge…' : 'PDF' }}</span>
      </button>
    </div>

    <!-- Teams -->
    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Teams</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Team mit allen Aktivitäten.</p>

        <div
          v-if="hasTeamIssues"
          class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
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
      </div>

      <button
        class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
        :disabled="isDownloading.teams"
        @click="downloadPdf('teams', `/export/pdf_download/teams/${eventId}`, 'Teams.pdf')"
      >
        <svg v-if="isDownloading.teams" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>{{ isDownloading.teams ? 'Erzeuge…' : 'PDF' }}</span>
      </button>
    </div>

    <!-- Räume -->
    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Räume</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Raum mit allen Aktivitäten.</p>

        <div
          v-if="hasRoomIssues"
          class="mt-2 flex items-start bg-yellow-50 border-l-4 border-yellow-400 text-yellow-800 p-3 rounded"
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
      </div>

      <button
        class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
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

    <!-- Gesamtplan -->
    <div class="flex justify-between items-center">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Gesamtplan</h4>
        <p class="text-sm text-gray-600">Volle Details, aber in einfacher Formatierung.</p>
        <p class="text-xs text-gray-500">Nur für den Veranstalter – nicht für Teams oder Besucher.</p>
      </div>

      <button
        class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
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