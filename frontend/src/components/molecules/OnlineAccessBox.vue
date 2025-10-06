<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { imageUrl } from '@/utils/images'
import { formatDateOnly, formatDateTime, formatTimeOnly } from '@/utils/dateTimeFormat'

// Store + Selected Event (autark)
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const scheduleInfo = ref<any>(null)

// Detail-Level
const levels = ['Planung', 'Nach Anmeldeschluss', 'Überblick zum Ablauf', 'volle Details']
const detailLevel = ref(0)


async function fetchPublicationLevel(eventId: number) {
  try {
    const { data } = await axios.get(`/publish/level/${eventId}`)
    detailLevel.value = (data.level ?? 1) - 1 // Radio startet bei 0
  } catch (e) {
    console.error('Fehler beim Laden des Publication Levels:', e)
    detailLevel.value = 0
  }
}

async function updatePublicationLevel(eventId: number, level: number) {
  try {
    await axios.post(`/publish/level/${eventId}`, { level: level + 1 })
  } catch (e) {
    console.error('Fehler beim Setzen des Publication Levels:', e)
  }
}

async function fetchScheduleInformation(eventId: number) {
  try {
    const { data } = await axios.post(`/publish/information/${eventId}`, { level: 4 })
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
      fetchPublicationLevel(id),
      fetchScheduleInformation(id),
    ])
  },
  { immediate: true }
)

watch(detailLevel, (lvl) => {
  if (event.value?.id) updatePublicationLevel(event.value.id, lvl)
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
  if (!scheduleInfo.value?.schedule?.explore) return []
  const e = scheduleInfo.value.schedule.explore
  const items: Array<{ label: string; time: string }> = []
  if (e.briefing?.teams) items.push({ label: 'Coach-Briefing', time: e.briefing.teams })
  if (e.briefing?.judges) items.push({ label: 'Gutachter:innen-Briefing', time: e.briefing.judges })
  if (e.opening) items.push({ label: 'Eröffnung', time: e.opening })
  if (e.end) items.push({ label: 'Ende', time: e.end })
  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})

const challengeTimes = computed(() => {
  if (!scheduleInfo.value?.schedule?.challenge) return []
  const c = scheduleInfo.value.schedule.challenge
  const items: Array<{ label: string; time: string }> = []
  if (c.briefing?.teams) items.push({ label: 'Coach-Briefing', time: c.briefing.teams })
  if (c.briefing?.judges) items.push({ label: 'Jury-Briefing', time: c.briefing.judges })
  if (c.briefing?.referees) items.push({ label: 'Schiedsrichter-Briefing', time: c.briefing.referees })
  if (c.opening) items.push({ label: 'Eröffnung', time: c.opening })
  if (c.end) items.push({ label: 'Ende', time: c.end })
  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})


function previewOlinePlan() {
  const url = `${import.meta.env.VITE_APP_URL}/output/zeitplan.cgi?plan=${planId.value}`
  window.open(url, '_blank')
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
                <div>{{ formatDateOnly(scheduleInfo.date) }} {{scheduleInfo.date }}</div>
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
                  Letzte Änderung: {{ formatDateTime(scheduleInfo.schedule.last_changed) }}
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