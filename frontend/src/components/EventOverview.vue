<script lang="ts" setup>
import {computed, onMounted, onUnmounted, ref} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import dayjs from "dayjs";
import ExtraBlocks from "@/components/molecules/ExtraBlocks.vue";
import {programLogoSrc, programLogoAlt} from '@/utils/images'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const challengeData = ref(null)
const exploreData = ref(null)
const planId = ref(null)

// Team statistics from DRAHT data
const teamStats = ref({
  explore: {
    capacity: 0,
    registered: 0
  },
  challenge: {
    capacity: 0,
    registered: 0
  }
})

// Debounced saving mechanism (same as Schedule.vue)
const pendingUpdates = ref<Record<string, any>>({})
const updateTimeoutId = ref<NodeJS.Timeout | null>(null)
const DEBOUNCE_DELAY = 2000

// Toast notification system
const showToast = ref(false)
const progress = ref(100)
const progressIntervalId = ref<NodeJS.Timeout | null>(null)

// Derive toggle states from parameters
const showExplore = computed(() => {
  // If we have parameters, check e_mode > 0
  if (event.value?.parameters) {
    const eMode = event.value.parameters.find(p => p.name === 'e_mode')
    return eMode ? Number(eMode.value) > 0 : true
  }
  return true // Default to true if no parameters
})

const showChallenge = computed(() => {
  // If we have parameters, check c_teams > 0
  if (event.value?.parameters) {
    const cTeams = event.value.parameters.find(p => p.name === 'c_teams')
    return cTeams ? Number(cTeams.value) > 0 : true
  }
  return true // Default to true if no parameters
})

async function fetchPlanId() {
  if (!event.value?.id) return
  try {
    const response = await axios.get(`/plans/event/${event.value.id}`)
    planId.value = response.data.id
  } catch (error) {
    console.error('Error fetching plan ID:', error)
  }
}

// Handle block updates from ExtraBlocks component
function handleBlockUpdates(updates: Array<{ name: string, value: any }>) {
  console.log('EventOverview: Received block updates:', updates)

  // Add all block updates to pending updates
  updates.forEach(update => {
    pendingUpdates.value[update.name] = update.value
  })

  console.log('EventOverview: Pending updates:', pendingUpdates.value)

  // Show toast and start progress animation
  showToast.value = true
  console.log('EventOverview: showToast set to true')
  startProgressAnimation()

  // Clear existing timeout
  if (updateTimeoutId.value) {
    clearTimeout(updateTimeoutId.value)
  }

  // Schedule batch update
  updateTimeoutId.value = setTimeout(() => {
    flushUpdates()
  }, DEBOUNCE_DELAY)
}

// Start progress animation
function startProgressAnimation() {
  // Reset progress
  progress.value = 100

  // Clear existing interval
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
  }

  // Calculate step size (100 steps over the debounce delay)
  const stepSize = 100 / (DEBOUNCE_DELAY / 50) // Update every 50ms

  progressIntervalId.value = setInterval(() => {
    progress.value -= stepSize
    if (progress.value <= 0) {
      progress.value = 0
      clearInterval(progressIntervalId.value!)
      progressIntervalId.value = null
    }
  }, 50)
}

// Force immediate update of all pending changes
async function flushUpdates() {
  console.log('EventOverview: flushUpdates called')

  if (updateTimeoutId.value) {
    clearTimeout(updateTimeoutId.value)
    updateTimeoutId.value = null
  }

  // Clear progress animation
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
    progressIntervalId.value = null
  }

  // Hide toast
  showToast.value = false
  console.log('EventOverview: showToast set to false')

  // Process all pending updates
  const updates = Object.entries(pendingUpdates.value)
  if (updates.length === 0) return

  console.log('Flushing updates:', updates)

  // Clear pending updates
  pendingUpdates.value = {}

  // Save each update to the database
  try {
    for (const [name, value] of updates) {
      if (name === 'extra_block_update' && value) {
        // Check if only non-timing fields changed
        const timingFields = ['start', 'end', 'buffer_before', 'duration', 'buffer_after', 'insert_point', 'first_program']
        const hasTimingChanges = Object.keys(value).some(field => timingFields.includes(field))
        
        // Add skip_regeneration flag if only non-timing fields changed
        const blockData = { ...value }
        if (!hasTimingChanges) {
          blockData.skip_regeneration = true
        }
        
        // Save extra block update
        await axios.post(`/plans/${planId.value}/extra-blocks`, blockData)
        console.log('Saved extra block:', blockData)
      }
      // Add more update types here as needed
    }
  } catch (error) {
    console.error('Error saving updates:', error)
  }
}

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  const drahtData = await axios.get(`/events/${event.value?.id}/draht-data`)

  exploreData.value = drahtData.data.event_explore
  challengeData.value = drahtData.data.event_challenge

  event.value.address = drahtData.data.address
  event.value.contact = drahtData.data.contact
  event.value.information = drahtData.data.information

  event.value.wifi_ssid ??= ''
  event.value.wifi_password ??= ''

  // Extract team statistics from DRAHT data
  teamStats.value = {
    explore: {
      capacity: drahtData.data.capacity_explore || 0,
      registered: drahtData.data.teams_explore ? Object.keys(drahtData.data.teams_explore).length : 0
    },
    challenge: {
      capacity: drahtData.data.capacity_challenge || 0,
      registered: drahtData.data.teams_challenge ? Object.keys(drahtData.data.teams_challenge).length : 0
    }
  }

  await Promise.all([fetchTableNames(), fetchPlanId()])
})

onUnmounted(() => {
  // Clean up timeouts and intervals
  if (updateTimeoutId.value) {
    clearTimeout(updateTimeoutId.value)
  }
  if (progressIntervalId.value) {
    clearInterval(progressIntervalId.value)
  }
})


const tableNames = ref(['', '', '', ''])

const fetchTableNames = async () => {
  if (!event.value?.id) return
  try {
    const response = await axios.get(`/events/${event.value.id}/table-names`)
    const tables = response.data.table_names

    const names = Array(4).fill('')
    tables.forEach(t => {
      if (t.table_number >= 1 && t.table_number <= 4) {
        names[t.table_number - 1] = t.table_name ?? ''
      }
    })
    tableNames.value = names
  } catch (e) {
    console.error('Fehler beim Laden der Tischbezeichnungen:', e)
    tableNames.value = Array(4).fill('')
  }
}

const updateTableName = async () => {
  if (!event.value?.id) return

  try {
    const payload = {
      table_names: tableNames.value.map((name, i) => ({
        table_number: i + 1,
        table_name: name ?? '',
      })),
    }

    await axios.put(`/events/${event.value.id}/table-names`, payload)
  } catch (e) {
    console.error('Fehler beim Speichern der Tischnamen:', e)
  }
}
</script>

<template>
  <div class="p-6 space-y-6">
    <div>
      <h1 class="text-2xl font-bold">Veranstaltung {{ event?.name }}</h1>

      <!-- Zwei Spalten -->
      <div class="grid grid-cols-3 gap-4 mt-4">

        <!-- LINKE SPALTE (1/3 Breite) -->
        <div class="col-span-1 space-y-4">

          <!-- Daten -->
          <div class="p-4 border rounded shadow">
            <div class="grid grid-cols-2 gap-6">
              <div>
                <h3 class="font-semibold mb-2">Daten</h3>
                <p>Datum: {{ dayjs(event?.date).format('dddd, DD.MM.YYYY') }}</p>
                <p v-if="event?.days > 1">
                  bis: {{ dayjs(event?.enddate).format('dddd, DD.MM.YYYY') }}
                </p>
                <p>Art: {{ event?.level_rel.name }}</p>
                <p>Saison: {{ event?.season_rel.name }}</p>
              </div>

              <!-- Teamstatistik -->
              <div>
                <!-- Explore -->
                <div
                    v-if="teamStats.explore.capacity > 0 || teamStats.explore.registered > 0"
                    class="flex items-start gap-2 mb-3"
                >
                  <img
                      :src="programLogoSrc('E')"
                      :alt="programLogoAlt('E')"
                      class="w-10 h-10 flex-shrink-0"
                  />
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.explore.registered }} von {{ teamStats.explore.capacity }} Teams
                    </span>
                    <span class="text-gray-600 block">angemeldet</span>
                  </div>
                </div>

                <!-- Challenge -->
                <div
                    v-if="teamStats.challenge.capacity > 0 || teamStats.challenge.registered > 0"
                    class="flex items-start gap-2"
                >
                  <img
                      :src="programLogoSrc('C')"
                      :alt="programLogoAlt('C')"
                      class="w-10 h-10 flex-shrink-0"
                  />
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.challenge.registered }} von {{ teamStats.challenge.capacity }} Teams
                    </span>
                    <span class="text-gray-600 block">angemeldet</span>
                  </div>
                </div>

                <!-- Fallback -->
                <div
                    v-if="teamStats.explore.capacity === 0 && teamStats.explore.registered === 0 && teamStats.challenge.capacity === 0 && teamStats.challenge.registered === 0"
                    class="text-gray-500 text-xs"
                >
                  Keine Team-Daten verfügbar
                </div>
              </div>
            </div>
          </div>

          <!-- Adresse -->
          <div class="p-4 border rounded shadow">
            <h3 class="font-semibold mb-2">Adresse</h3>
            <p>{{ event?.address }}</p>
          </div>

          <!-- Kontakt -->
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
                        d="M2.94 5.5a1.5 1.5 0 011.5-1.5h11.12a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H4.44a1.5 1.5 0 01-1.5-1.5v-9zm1.62.4v.28l5.5 3.44 5.5-3.44v-.28H4.56zm0 1.48v6.12h10.88V7.38L10 10.75 4.56 7.38z"/>
                  </svg>
                  {{ person.contact_email }}
                </div>
                <p v-if="person.contact_infos" class="text-xs text-gray-600 mt-1">{{ person.contact_infos }}</p>
              </div>
            </div>
          </div>

          <!-- Robot-Game-Tische -->
          <div class="p-4 border rounded shadow">
            <h2 class="text-lg font-semibold mb-2">Bezeichnung der Robot-Game-Tische</h2>
            <div class="grid grid-cols-2 gap-4">
              <div v-for="(name, i) in tableNames" :key="i">
                <label class="block text-sm text-gray-700 mb-1">Tisch {{ i + 1 }}</label>
                <input
                    v-model="tableNames[i]"
                    class="w-full border px-3 py-1 rounded text-sm"
                    :placeholder="`leer lassen für >>Tisch ${i + 1}<<`"
                    type="text"
                    @blur="updateTableName"
                />
              </div>
            </div>
          </div>

        </div>

        <!-- RECHTE SPALTE (2/3 Breite) -->
        <div class="col-span-2 p-4 border rounded shadow h-fit">
          <h2 class="text-lg font-semibold mb-2">Aktivitäten, die den Ablauf nicht beeinflussen</h2>
          <ExtraBlocks
              :event-date="event?.date"
              :plan-id="planId"
              :show-challenge="showChallenge"
              :show-explore="showExplore"
              @block-update="handleBlockUpdates"
          />
        </div>

      </div>
    </div>
  </div>

  <!-- Toast notification -->
  <div
      v-if="showToast"
      class="fixed top-4 right-4 z-50 bg-green-50 border border-green-200 rounded-lg shadow-lg p-4 min-w-80 max-w-md">
    <div class="flex items-center gap-3">
      <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
      <span class="text-green-800 font-medium">Block-Änderungen werden gespeichert...</span>
    </div>
    <div class="mt-3 bg-green-200 rounded-full h-2 overflow-hidden">
      <div
          :style="{ width: progress + '%' }"
          class="bg-green-500 h-full transition-all duration-75 ease-linear"></div>
    </div>
  </div>
</template>
