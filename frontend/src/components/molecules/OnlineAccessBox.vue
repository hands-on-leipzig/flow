<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import { imageUrl } from '@/utils/images'
import { formatTimeOnly, formatDateOnly } from '@/utils/dateTimeFormat'
import SavingToast from "@/components/atoms/SavingToast.vue";

// Store + Selected Event (autark)
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const { isAdmin } = useAuth()

const scheduleInfo = ref<any>(null)
const regenerating = ref(false)
const saving = ref(null)

// Detail-Level (3 levels, skipping backend level 2 "Nach Anmeldeschluss")
const levels = ['Planung und Anmeldung', 'Überblick zum Ablauf', 'volle Details']
const detailLevel = ref(undefined)

// Map frontend level (0,1,2) to backend level (1,3,4) - skipping level 2
function frontendToBackendLevel(frontendLevel: number): number {
  if (frontendLevel === 0) return 1 // Planung
  if (frontendLevel === 1) return 3 // Überblick zum Ablauf
  return 4 // volle Details
}

// Map backend level (1,2,3,4) to frontend level (0,1,2) - skipping level 2
function backendToFrontendLevel(backendLevel: number): number {
  if (backendLevel === 1) return 0 // Planung
  if (backendLevel === 2) return 0 // Map level 2 to 0 (skip it, treat as Planung)
  if (backendLevel === 3) return 1 // Überblick zum Ablauf
  if (backendLevel === 4) return 2 // volle Details
  return 0 // Default to Planung
}


async function fetchPublicationLevel() {
  try {
    const { data } = await axios.get(`/publish/level/${event.value?.id}`)
    const backendLevel = data.level ?? 1
    detailLevel.value = backendToFrontendLevel(backendLevel)
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Laden des Publication Levels:', e)
    }
    detailLevel.value = 0
  }
}

async function updatePublicationLevel(level: number) {
  try {
    // Save current scroll position to prevent page movement
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft
    
    saving?.value?.show();
    
    // Restore scroll position immediately to prevent any movement
    requestAnimationFrame(() => {
      window.scrollTo(scrollLeft, scrollTop)
    })
    
    const backendLevel = frontendToBackendLevel(level)
    if (import.meta.env.DEV) {
      console.debug('Updating publication level to:', backendLevel, '(frontend level:', level, ') for event:', event.value?.id)
    }
    await axios.post(`/publish/level/${event.value?.id}`, { level: backendLevel })
    if (import.meta.env.DEV) {
      console.debug('Publication level updated successfully')
    }
    
    // Small delay to show the banner
    await new Promise(resolve => setTimeout(resolve, 500))
    
    // Ensure scroll position is maintained
    requestAnimationFrame(() => {
      window.scrollTo(scrollLeft, scrollTop)
    })
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Setzen des Publication Levels:', e)
    }
  } finally {
    saving.value?.hide();
  }
}

async function fetchScheduleInformation() {
  try {
    const { data } = await axios.post(`/publish/information/${event.value?.id}`, { level: 4 })
    scheduleInfo.value = data
  } catch (e) {
    if (import.meta.env.DEV) {
      console.error('Fehler beim Laden von Schedule Information:', e)
    }
    scheduleInfo.value = null
  }
}

watch(
  () => event.value?.id,
  async (id) => {
    if (!id) return
    await Promise.all([
      fetchPublicationLevel(),
      fetchScheduleInformation()
    ])
  },
  { immediate: true }
)

watch(detailLevel, (newLevel, oldLevel) => {
  // Only update if the level actually changed and we have an event
  if (event.value?.id && oldLevel !== undefined && newLevel !== oldLevel) {
    // Save scroll position before update to prevent page movement
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft
    
    updatePublicationLevel(newLevel).then(() => {
      // Restore scroll position after update
      requestAnimationFrame(() => {
        window.scrollTo(scrollLeft, scrollTop)
      })
    })
  }
})


// ----------------- Helpers -----------------
function isCardActive(card: number, level: number) {
  if (card === 1) return true // First card always active (Level 0: Info, Date, Teams)
  if (card === 2 && level >= 1) return true // Level 2 (frontend level 1): Basic times
  if (card === 3 && level >= 2) return true // Level 3 (frontend level 2): Online plan
  return false
}

function previewOnlinePlan() {
  const url = `${import.meta.env.VITE_APP_URL}/output/zeitplan.cgi?plan=${scheduleInfo.value?.plan.plan_id}`
  window.open(url, '_blank')
}

async function regenerateLinkAndQR() {
  if (!event.value?.id) return
  
  try {
    regenerating.value = true
    const { data } = await axios.post(`/publish/regenerate/${event.value.id}`)
    
    // Update the event in the store with new link and QR code
    if (eventStore.selectedEvent) {
      eventStore.selectedEvent.link = data.link
      eventStore.selectedEvent.qrcode = data.qrcode.replace('data:image/png;base64,', '')
    }
    
    if (import.meta.env.DEV) {
      console.debug('Link and QR code regenerated successfully')
    }
  } catch (error) {
    if (import.meta.env.DEV) {
      console.error('Error regenerating link and QR code:', error)
    }
    alert('Fehler beim Regenerieren des Links und QR-Codes')
  } finally {
    regenerating.value = false
  }
}
</script>

<template>
  <SavingToast ref="saving" message="Publikations-Level wird gespeichert..." />

  <div class="rounded-xl shadow bg-white p-6 space-y-4" style="overflow-anchor: none;">
    <h2 class="text-lg font-semibold">Online – von der Planung bis zur Veranstaltung</h2>

    <!-- Link + Erklärung -->
    <div class="flex items-center gap-3">
      <a
        v-if="event?.link"
        :href="event?.link"
        target="_blank"
        rel="noopener"
        class="text-blue-600 underline font-medium text-base"
      >
        {{ event?.link }} 
      </a>
      <span class="text-sm text-gray-600">
        gibt Teams, Freiwilligen und dem Publikum alle Informationen zur Veranstaltung.
      </span>
      
      <!-- Regenerate Button for Admins -->
      <button
        v-if="isAdmin && event?.id"
        @click="regenerateLinkAndQR"
        :disabled="regenerating"
        class="ml-auto px-3 py-1 bg-blue-600 text-white rounded text-sm hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
      >
        <svg v-if="regenerating" class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        {{ regenerating ? 'Regeneriere...' : 'Link & QR neu generieren' }}
      </button>
    </div>

    <div class="flex items-start gap-6">
      <!-- Radiobuttons -->
      <div class="flex flex-col space-y-3">
        <h3 class="text-sm font-semibold mb-2">Detaillevel</h3>
        <label
          v-for="(label, idx) in levels"
          :key="idx"
          class="flex items-start gap-2 cursor-pointer"
        >
          <input
            type="radio"
            :value="idx"
            v-model="detailLevel"
            class="mt-1 accent-blue-600"
            @focus="(e: Event) => { (e.target as HTMLInputElement)?.blur() }"
          />
          <span class="text-sm leading-tight">
            {{ label.split(' ')[0] }} <br />
            {{ label.split(' ').slice(1).join(' ') }}
          </span>
        </label>
      </div>

      <!-- Info-Kacheln -->
      <div class="flex-1">
        <h3 class="text-sm font-semibold mb-2">Veröffentlichte Informationen</h3>

        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
          <template v-for="(_, idx) in Array(3)" :key="idx">
            <div
              class="relative rounded-lg border p-3 text-sm"
              :class="{
                'opacity-100': isCardActive(idx + 1, detailLevel),
                'opacity-50': !isCardActive(idx + 1, detailLevel),
                'sm:col-span-2': idx === 0,
                'sm:col-span-1': idx === 1 || idx === 2,
              }"
            >
              <div class="absolute top-2 right-2">
                <div
                  v-if="isCardActive(idx + 1, detailLevel)"
                  class="w-4 h-4 bg-green-500 text-white flex items-center justify-center rounded-sm text-xs"
                >
                  ✓
                </div>
                <div v-else class="w-4 h-4 bg-gray-300 rounded-sm"></div>
              </div>

              <!-- Card Inhalte -->
              <!-- Card 1: Level 0 (Planung) - Basic event information -->
              <template v-if="idx === 0 && scheduleInfo">
                <div class="grid grid-cols-2 gap-4">
                  <!-- Left column: Datum, Adresse, Kontakt -->
                  <div>
                    <div class="font-semibold mb-1">Datum</div>
                    <div v-if="scheduleInfo.date" class="text-gray-700 mb-3">{{ formatDateOnly(scheduleInfo.date) }}</div>
                    <div v-else class="text-gray-400 mb-3 italic">–</div>
                    
                    <div class="font-semibold mb-1">Adresse</div>
                    <div v-if="scheduleInfo.address" class="text-gray-700 mb-3 whitespace-pre-line text-xs">{{ scheduleInfo.address }}</div>
                    <div v-else class="text-gray-400 mb-3 italic text-xs">–</div>
                    
                    <div class="font-semibold mb-1">Kontakt</div>
                    <div v-if="scheduleInfo.contact && scheduleInfo.contact.length > 0" class="text-gray-700 mb-3 text-xs">
                      <div v-for="(contact, contactIdx) in scheduleInfo.contact" :key="contactIdx" class="mb-1">
                        <div class="font-medium">{{ contact.contact }}</div>
                        <div v-if="contact.contact_email" class="text-gray-600">{{ contact.contact_email }}</div>
                        <div v-if="contact.contact_infos" class="text-gray-500">{{ contact.contact_infos }}</div>
                      </div>
                    </div>
                    <div v-else class="text-gray-400 mb-3 italic text-xs">–</div>
                  </div>
                  
                  <!-- Right column: Angemeldete Teams -->
                  <div>
                    <div class="font-semibold mb-1">Angemeldete Teams</div>
                    <div v-if="scheduleInfo.teams" class="text-xs">
                      <div v-if="scheduleInfo.teams.explore" class="mb-4">
                        <div class="font-medium mb-2 text-sm">
                          FIRST LEGO League Explore
                          <span class="text-gray-600 text-xs font-normal ml-2">
                            {{ scheduleInfo.teams.explore.registered }} von {{ scheduleInfo.teams.explore.capacity }} angemeldet
                          </span>
                        </div>
                        <div v-if="scheduleInfo.teams.explore.list && scheduleInfo.teams.explore.list.length > 0" class="text-gray-600 pl-2 text-xs">
                          <div v-for="(team, teamIdx) in scheduleInfo.teams.explore.list" :key="teamIdx" class="mb-0.5">
                            {{ team.name || '–' }}<span v-if="team.team_number_hot" class="text-gray-500"> ({{ team.team_number_hot }})</span>
                          </div>
                        </div>
                      </div>
                      <div v-if="scheduleInfo.teams.challenge">
                        <div class="font-medium mb-2 text-sm">
                          FIRST LEGO League Challenge
                          <span class="text-gray-600 text-xs font-normal ml-2">
                            {{ scheduleInfo.teams.challenge.registered }} von {{ scheduleInfo.teams.challenge.capacity }} angemeldet
                          </span>
                        </div>
                        <div v-if="scheduleInfo.teams.challenge.list && scheduleInfo.teams.challenge.list.length > 0" class="text-gray-600 pl-2 text-xs">
                          <div v-for="(team, teamIdx) in scheduleInfo.teams.challenge.list" :key="teamIdx" class="mb-0.5">
                            {{ team.name || '–' }}<span v-if="team.team_number_hot" class="text-gray-500"> ({{ team.team_number_hot }})</span>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div v-else class="text-gray-400 italic text-xs">–</div>
                  </div>
                </div>
              </template>

              <!-- Card 2: Level 2 (Überblick zum Ablauf) - What's actually shown on the page -->
              <template v-else-if="idx === 1 && scheduleInfo && scheduleInfo.plan">
                <div class="font-semibold mb-3">Wichtige Zeiten</div>
                
                <!-- Explore Section - Only show if there are Explore times -->
                <div v-if="scheduleInfo.plan?.explore && (
                    scheduleInfo.plan.explore.briefing?.teams ||
                    scheduleInfo.plan.explore.briefing?.judges ||
                    scheduleInfo.plan.explore.opening ||
                    scheduleInfo.plan.explore.end
                  )" class="mb-4">
                  <div class="font-medium mb-2">FIRST LEGO League Explore</div>
                  <div class="space-y-1 text-xs">
                    <div v-if="scheduleInfo.plan.explore.briefing?.teams" class="flex justify-between">
                      <span class="text-gray-600">Coach-Briefing:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.briefing.teams, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.explore.briefing?.judges" class="flex justify-between">
                      <span class="text-gray-600">Gutachter:innen-Briefing:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.briefing.judges, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.explore.opening" class="flex justify-between">
                      <span class="text-gray-600">Eröffnung:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.opening, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.explore.end" class="flex justify-between">
                      <span class="text-gray-600">Ende:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.explore.end, true) }}</span>
                    </div>
                  </div>
                </div>

                <!-- Challenge Section - Only show if there are Challenge times -->
                <div v-if="scheduleInfo.plan?.challenge && (
                    scheduleInfo.plan.challenge.briefing?.teams ||
                    scheduleInfo.plan.challenge.briefing?.judges ||
                    scheduleInfo.plan.challenge.briefing?.referees ||
                    scheduleInfo.plan.challenge.opening ||
                    scheduleInfo.plan.challenge.end
                  )">
                  <div class="font-medium mb-2">FIRST LEGO League Challenge</div>
                  <div class="space-y-1 text-xs">
                    <div v-if="scheduleInfo.plan.challenge.briefing?.teams" class="flex justify-between">
                      <span class="text-gray-600">Coach-Briefing:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.teams, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.challenge.briefing?.judges" class="flex justify-between">
                      <span class="text-gray-600">Jury-Briefing:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.judges, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.challenge.briefing?.referees" class="flex justify-between">
                      <span class="text-gray-600">Schiedsrichter:innen-Briefing:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.briefing.referees, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.challenge.opening" class="flex justify-between">
                      <span class="text-gray-600">Eröffnung:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.opening, true) }}</span>
                    </div>
                    <div v-if="scheduleInfo.plan.challenge.end" class="flex justify-between">
                      <span class="text-gray-600">Ende:</span>
                      <span class="font-medium">{{ formatTimeOnly(scheduleInfo.plan.challenge.end, true) }}</span>
                    </div>
                  </div>
                </div>
              </template>

              <!-- Card 3: Level 3 (volle Details) - Online plan -->
              <template v-else-if="idx === 2">
                <div class="h-full flex flex-col justify-between">
                  <div>
                    <div class="font-semibold mb-1">Online Zeitplan</div>
                    <img
                      :src="imageUrl('/flow/öplan.png')"
                      alt="Plan Vorschau"
                      class="h-28 w-auto border mx-auto"
                    />
                  </div>
                  <div class="mt-4 flex justify-center">
                    <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="previewOnlinePlan">
                      Vorschau
                    </button>
                  </div>
                </div>
              </template>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>