<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import axios from 'axios'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

// --- Available Team Programs ---
interface Program {
  id: number
  name: string
}

const availableTeamPrograms = ref<Program[]>([])

// Computed: check if Challenge teams exist (id = 3)
const hasChallengeTeams = computed(() => availableTeamPrograms.value.some(p => p.id === 3))

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

    <!-- Button -->
    <div class="mt-4 flex justify-start">
      <button
        class="px-4 py-2 rounded text-sm flex items-center gap-2 bg-gray-200 hover:bg-gray-300"
        @click="() => {}"
      >
        <span>Match-Plan</span>
      </button>
    </div>
  </div>
</template>
