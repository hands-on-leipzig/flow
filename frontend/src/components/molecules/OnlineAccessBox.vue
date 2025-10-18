<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { useAuth } from '@/composables/useAuth'
import { imageUrl } from '@/utils/images'
import { formatDateOnly, formatDateTime, formatTimeOnly } from '@/utils/dateTimeFormat'

// Store + Selected Event (autark)
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const { isAdmin } = useAuth()

const scheduleInfo = ref<any>(null)
const regenerating = ref(false)

// Detail-Level
const levels = ['Planung', 'Nach Anmeldeschluss', 'Überblick zum Ablauf', 'volle Details']
const detailLevel = ref(0)


async function fetchPublicationLevel() {
  try {
    const { data } = await axios.get(`/publish/level/${event.value?.id}`)
    detailLevel.value = (data.level ?? 1) - 1 // Radio startet bei 0
  } catch (e) {
    console.error('Fehler beim Laden des Publication Levels:', e)
    detailLevel.value = 0
  }
}

async function updatePublicationLevel(level: number) {
  try {
    console.log('Updating publication level to:', level + 1, 'for event:', event.value?.id)
    await axios.post(`/publish/level/${event.value?.id}`, { level: level + 1 })
    console.log('Publication level updated successfully')
  } catch (e) {
    console.error('Fehler beim Setzen des Publication Levels:', e)
  }
}

async function fetchScheduleInformation() {
  try {
    const { data } = await axios.post(`/publish/information/${event.value?.id}`, { level: 4 })
    scheduleInfo.value = data
  } catch (e) {
    console.error('Fehler beim Laden von Schedule Information:', e)
    scheduleInfo.value = null
  }
}

watch(
  () => event.value?.id,
  async (id) => {
    if (!id) return
    await Promise.all([
      fetchPublicationLevel()
^     fetchScheduleInformation()
    ])
  },
  { immediate: true }
)

watch(detailLevel, (newLevel, oldLevel) => {
  // Only update if the level actually changed and we have an event
  if (event.value?.id && oldLevel !== undefined && newLevel !== oldLevel) {
    updatePublicationLevel(newLevel)
  }
})


// ----------------- Helpers -----------------
function isCardActive(card: number, level: number) {
  if (card <= 2) return true
  if (card === 3 && level >= 1) return true
  if (card === 4 && level >= 2) return true
  if (card === 5 && level >= 3) return true
  return false
}

const exploreTimes = computed(() => {
  if (!scheduleInfo.value?.plan?.explore) return []
  const e = scheduleInfo.value.plan.explore
  const items: Array<{ label: string; time: string }> = []
  if (e.briefing?.teams) items.push({ label: 'Coach-Briefing', time: e.briefing.teams })
  if (e.briefing?.judges) items.push({ label: 'Gutachter:innen-Briefing', time: e.briefing.judges })
  if (e.opening) items.push({ label: 'Eröffnung', time: e.opening })
  if (e.end) items.push({ label: 'Ende', time: e.end })
  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})

const challengeTimes = computed(() => {
  if (!scheduleInfo.value?.plan?.challenge) return []
  const c = scheduleInfo.value.plan.challenge
  const items: Array<{ label: string; time: string }> = []
  if (c.briefing?.teams) items.push({ label: 'Coach-Briefing', time: c.briefing.teams })
  if (c.briefing?.judges) items.push({ label: 'Jury-Briefing', time: c.briefing.judges })
  if (c.briefing?.referees) items.push({ label: 'Schiedsrichter-Briefing', time: c.briefing.referees })
  if (c.opening) items.push({ label: 'Eröffnung', time: c.opening })
  if (c.end) items.push({ label: 'Ende', time: c.end })
  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})


function previewOlinePlan() {
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
    
    console.log('Link and QR code regenerated successfully')
  } catch (error) {
    console.error('Error regenerating link and QR code:', error)
    alert('Fehler beim Regenerieren des Links und QR-Codes')
  } finally {
    regenerating.value = false
  }
}
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 space-y-4">
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

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
          <template v-for="(card, idx) in 5" :key="idx">
            <div
              class="relative rounded-lg border p-3 text-sm"
              :class="{
                'opacity-100': isCardActive(idx + 1, detailLevel),
                'opacity-50': !isCardActive(idx + 1, detailLevel),
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
              <template v-if="idx === 0 && scheduleInfo">
                <div class="font-semibold mb-1">Datum</div>
                <div>{{ formatDateOnly(scheduleInfo.date) }}</div>
                <div class="mt-2 font-semibold">Adresse</div>
                <div class="whitespace-pre-line text-gray-700 text-xs">
                  {{ scheduleInfo.address }}
                </div>
                <div class="mt-2 font-semibold">Kontakt</div>
                <div class="text-xs space-y-2">
                  <div v-for="(c, i) in scheduleInfo.contact" :key="i">
                    {{ c.contact }}<br />
                    {{ c.contact_email }}
                    <div v-if="c.contact_infos">{{ c.contact_infos }}</div>
                  </div>
                </div>
              </template>

              <template v-else-if="idx === 1 && scheduleInfo">
                <div class="font-semibold mb-1">Zahlen zur Anmeldung</div>
                <div v-if="scheduleInfo.teams.explore.capacity > 0 || scheduleInfo.teams.explore.registered > 0">
                  Explore: {{ scheduleInfo.teams.explore.registered }} von {{ scheduleInfo.teams.explore.capacity }} angemeldet
                </div>
                <div v-if="scheduleInfo.teams.challenge.capacity > 0 || scheduleInfo.teams.challenge.registered > 0">
                  Challenge: {{ scheduleInfo.teams.challenge.registered }} von {{ scheduleInfo.teams.challenge.capacity }} angemeldet
                </div>
              </template>

              <template v-else-if="idx === 2 && scheduleInfo && scheduleInfo.level >= 2">
                <div class="font-semibold mb-1">Angemeldete Teams</div>
                <template v-if="scheduleInfo.teams.explore.list?.length">
                  <div class="font-medium mb-1">Explore</div>
                  <div class="whitespace-pre-line text-gray-700 text-xs">
                    {{ scheduleInfo.teams.explore.list.join(', ') }}
                  </div>
                </template>
                <template v-if="scheduleInfo.teams.challenge.list?.length">
                  <div class="font-medium mt-2 mb-1">Challenge</div>
                  <div class="whitespace-pre-line text-gray-700 text-xs">
                    {{ scheduleInfo.teams.challenge.list.join(', ') }}
                  </div>
                </template>
              </template>

              <template v-else-if="idx === 3 && scheduleInfo && scheduleInfo.level >= 3">
                <div class="font-semibold mb-1">Wichtige Zeiten</div>
                <div class="text-xs text-gray-600 mb-2">
                  Letzte Änderung: {{ formatDateTime(scheduleInfo.plan.last_change) }} 
                </div>

                <div v-if="exploreTimes.length > 0">
                  <div class="font-medium">Explore</div>
                  <div v-for="(item, i) in exploreTimes" :key="i" class="text-xs text-gray-600 mb-0.5">
                    {{ item.label }}: {{ formatTimeOnly(item.time, true) }}
                  </div>
                </div>

                <div v-if="challengeTimes.length > 0" class="mt-2">
                  <div class="font-medium">Challenge</div>
                  <div v-for="(item, i) in challengeTimes" :key="i" class="text-xs text-gray-600 mb-0.5">
                    {{ item.label }}: {{ formatTimeOnly(item.time, true) }}
                  </div>
                </div>
              </template>

              <template v-else-if="idx === 4">
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
                    <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="previewOlinePlan">
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