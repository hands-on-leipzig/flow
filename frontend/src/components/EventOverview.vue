<script lang="ts" setup>
import { computed, onMounted, ref, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import dayjs from 'dayjs'
import FreeBlocks from '@/components/molecules/FreeBlocks.vue'
import { programLogoSrc, programLogoAlt } from '@/utils/images'
import { getEventTitleLong, getCompetitionType, cleanEventName } from '@/utils/eventTitle'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const challengeData = ref(null)
const exploreData = ref(null)
const planId = ref<number | null>(null)

// Team statistics
const teamStats = ref({
  explore: { capacity: 0, registered: 0 },
  challenge: { capacity: 0, registered: 0 },
})

// --- Visibility toggles ---
const showExplore = computed(() => {
  if (event.value?.parameters) {
    const eMode = event.value.parameters.find(p => p.name === 'e_mode')
    return eMode ? Number(eMode.value) > 0 : true
  }
  return true
})

const showChallenge = computed(() => {
  if (event.value?.parameters) {
    const cTeams = event.value.parameters.find(p => p.name === 'c_teams')
    return cTeams ? Number(cTeams.value) > 0 : true
  }
  return true
})

// Use normalized event title utilities
const eventTitleLong = computed(() => getEventTitleLong(event.value))
const competitionType = computed(() => getCompetitionType(event.value))

// Format title with italic FIRST and blue event name for display
const formattedEventTitle = computed(() => {
  if (!eventTitleLong.value) return ''
  
  const title = eventTitleLong.value
  // Use cleaned event name to match what's actually in the title
  const cleanedEventName = cleanEventName(event.value)
  
  if (!cleanedEventName) {
    // Just format FIRST in italics
    return title.replace('FIRST', '<em>FIRST</em>')
  }
  
  // Split title: "FIRST LEGO League [competitionType] [cleanedEventName]"
  // We want: <em>FIRST</em> LEGO League [competitionType] <br> <span class="text-blue-600">[cleanedEventName]</span>
  const withoutEventName = title.replace(new RegExp(` ${cleanedEventName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`), '')
  const formatted = withoutEventName.replace('FIRST', '<em>FIRST</em>')
  return `${formatted}<br><span class="text-blue-600">${cleanedEventName}</span>`
})

async function fetchPlanId() {
  if (!event.value?.id) return
  try {
    const response = await axios.get(`/plans/event/${event.value.id}`)
    planId.value = response.data.id
  } catch (error) {
    // Silently handle missing plan - plan generation might be in progress
    // Only log in development mode
    if (import.meta.env.DEV) {
      console.debug('Plan not found for event:', event.value?.id)
    }
  }
}

async function loadEventData() {
  if (!event.value?.id) return
  
  const drahtData = await axios.get(`/events/${event.value.id}/draht-data`)

  exploreData.value = drahtData.data.event_explore
  challengeData.value = drahtData.data.event_challenge

  event.value.address = drahtData.data.address
  event.value.contact = drahtData.data.contact
  event.value.information = drahtData.data.information

  teamStats.value = {
    explore: {
      capacity: drahtData.data.capacity_explore || 0,
      registered: drahtData.data.teams_explore ? Object.keys(drahtData.data.teams_explore).length : 0,
    },
    challenge: {
      capacity: drahtData.data.capacity_challenge || 0,
      registered: drahtData.data.teams_challenge ? Object.keys(drahtData.data.teams_challenge).length : 0,
    },
  }

  await fetchPlanId()
}

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  await loadEventData()
})

// Watch for event changes and reload data
watch(
  () => event.value?.id,
  async (newEventId, oldEventId) => {
    if (newEventId && newEventId !== oldEventId) {
      await loadEventData()
    }
  }
)
</script>

<template>
  <div class="p-6 space-y-6">
    <div>
      <h1 class="text-2xl font-bold" v-html="formattedEventTitle"></h1>

      <div class="grid grid-cols-3 gap-4 mt-4">
        <!-- LINKE SPALTE -->
        <div class="col-span-1 space-y-4">
          <div class="p-4 border rounded shadow">
            <div class="grid grid-cols-2 gap-6">
              <div>
                <h3 class="font-semibold mb-2">Daten</h3>
                <p>Datum: {{ dayjs(event?.date).format('dddd, DD.MM.YYYY') }}</p>
                <p v-if="event?.days > 1">bis: {{ dayjs(event?.date).add(event?.days - 1, 'day').format('dddd, DD.MM.YYYY') }}</p>
                <p>Art: {{ event?.level_rel.name }}</p>
                <p>Saison: {{ event?.season_rel.name }}</p>
              </div>

              <!-- Teamstatistik -->
              <div>
                <div
                  v-if="teamStats.explore.capacity > 0 || teamStats.explore.registered > 0"
                  class="flex items-start gap-2 mb-3"
                >
                  <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-10 h-10 flex-shrink-0" />
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.explore.registered }} von {{ teamStats.explore.capacity }} Teams
                    </span>
                    <span class="text-gray-600 block">angemeldet</span>
                  </div>
                </div>

                <div
                  v-if="teamStats.challenge.capacity > 0 || teamStats.challenge.registered > 0"
                  class="flex items-start gap-2"
                >
                  <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-10 h-10 flex-shrink-0" />
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.challenge.registered }} von {{ teamStats.challenge.capacity }} Teams
                    </span>
                    <span class="text-gray-600 block">angemeldet</span>
                  </div>
                </div>

                <div
                  v-if="
                    teamStats.explore.capacity === 0 &&
                    teamStats.explore.registered === 0 &&
                    teamStats.challenge.capacity === 0 &&
                    teamStats.challenge.registered === 0
                  "
                  class="text-gray-500 text-xs"
                >
                  Keine Team-Daten verfügbar
                </div>
              </div>
            </div>
          </div>

          <div class="p-4 border rounded shadow">
            <h3 class="font-semibold mb-2">Adresse</h3>
            <p>{{ event?.address }}</p>
          </div>

          <div class="p-4 border rounded shadow">
            <h3 class="text-lg font-semibold mb-4">Kontakt</h3>
            <div class="grid gap-4">
              <div
                v-for="(person, index) in event?.contact"
                :key="index"
                class="p-3 border rounded-md bg-gray-50 shadow-sm"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-semibold text-blue-800 text-sm">{{ person.contact }}</span>
                  <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded-full">Kontaktperson</span>
                </div>
                <div class="text-sm text-gray-700 flex items-center gap-1">
                  <svg class="w-4 h-4 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                    <path
                      d="M2.94 5.5a1.5 1.5 0 011.5-1.5h11.12a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H4.44a1.5 1.5 0 01-1.5-1.5v-9zm1.62.4v.28l5.5 3.44 5.5-3.44v-.28H4.56zm0 1.48v6.12h10.88V7.38L10 10.75 4.56 7.38z"
                    />
                  </svg>
                  {{ person.contact_email }}
                </div>
                <p v-if="person.contact_infos" class="text-xs text-gray-600 mt-1">{{ person.contact_infos }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- RECHTE SPALTE -->
        <div class="col-span-2 p-4 border rounded shadow h-fit">
          <h2 class="text-lg font-semibold mb-2">Aktivitäten, die den Ablauf nicht beeinflussen</h2>
          <FreeBlocks
            :event-date="event?.date"
            :event-days="event?.days"
            :plan-id="planId"
            :show-challenge="showChallenge"
            :show-explore="showExplore"
          />
        </div>
      </div>
    </div>
  </div>
</template>