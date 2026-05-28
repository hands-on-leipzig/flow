<script lang="ts" setup>
import {computed, onMounted, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import dayjs from 'dayjs'
import FreeBlocks from '@/components/molecules/FreeBlocks.vue'
import EventMap from '@/components/molecules/EventMap.vue'
import SharePointDocumentsBox from '@/components/molecules/SharePointDocumentsBox.vue'
import {programLogoSrc, programLogoAlt} from '@/utils/images'
import {getEventTitleLong, getCompetitionType, cleanEventName} from '@/utils/eventTitle'

const eventStore = useEventStore()
const {isAdmin} = useAuth()
const router = useRouter()
const event = computed(() => eventStore.selectedEvent)
const hasMultipleEvents = ref(false)
const challengeData = ref(null)
const exploreData = ref(null)
const planId = ref<number | null>(null)

// Team statistics
const teamStats = ref({
  explore: {capacity: 0, registered: 0},
  challenge: {capacity: 0, registered: 0},
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

// Format title with italic FIRST and accent-colored event name for display
const formattedEventTitle = computed(() => {
  if (!eventTitleLong.value) return ''

  const title = eventTitleLong.value
  const cleanedEventName = cleanEventName(event.value)

  if (!cleanedEventName) {
    return title.replace('FIRST', '<em>FIRST</em>')
  }

  const withoutEventName = title.replace(new RegExp(` ${cleanedEventName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}$`), '')
  const formatted = withoutEventName.replace('FIRST', '<em>FIRST</em>')
  return `${formatted} <span class="event-overview__name">${cleanedEventName}</span>`
})

async function fetchPlanId() {
  if (!event.value?.id) return
  try {
    const response = await axios.get(`/plans/event/${event.value.id}`)
    planId.value = response.data.id
  } catch (error) {
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

async function fetchSelectableEventCount() {
  try {
    const {data} = await axios.get('/events/selectable')
    const events = (data || []).flatMap((rp: { events?: unknown[] }) => rp.events || [])
    hasMultipleEvents.value = events.length > 1
  } catch {
    hasMultipleEvents.value = false
  }
}

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  await loadEventData()
  await fetchSelectableEventCount()
})

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
  <div class="space-y-6">
    <div>
      <div class="flex flex-wrap items-center justify-between gap-2 w-full">
        <h1 class="text-xl lg:text-2xl font-bold text-[var(--color-text)]" v-html="formattedEventTitle"/>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mt-4">
        <!-- Left column: Daten, Adresse, Kontakt -->
        <div class="lg:col-span-1 space-y-4 order-1">
          <div class="glass-card liquid-surface-inner">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div>
                <h3 class="glass-card__heading">Daten</h3>
                <p>Datum: {{ dayjs(event?.date).format('dddd, DD.MM.YYYY') }}</p>
                <p v-if="event?.days > 1">bis:
                  {{ dayjs(event?.date).add(event?.days - 1, 'day').format('dddd, DD.MM.YYYY') }}</p>
                <p>Art: {{ event?.level_rel.name }}</p>
                <p>Saison: {{ event?.season_rel.name }}</p>
              </div>

              <div>
                <div
                    v-if="teamStats.explore.capacity > 0 || teamStats.explore.registered > 0"
                    class="flex items-start gap-2 mb-3"
                >
                  <img :src="programLogoSrc('E')" :alt="programLogoAlt('E')" class="w-10 h-10 flex-shrink-0"/>
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.explore.registered }} von {{ teamStats.explore.capacity }} Teams
                    </span>
                    <span class="text-[var(--color-text-muted)] block">angemeldet</span>
                  </div>
                </div>

                <div
                    v-if="teamStats.challenge.capacity > 0 || teamStats.challenge.registered > 0"
                    class="flex items-start gap-2"
                >
                  <img :src="programLogoSrc('C')" :alt="programLogoAlt('C')" class="w-10 h-10 flex-shrink-0"/>
                  <div class="flex-1">
                    <span class="font-medium block">
                      {{ teamStats.challenge.registered }} von {{ teamStats.challenge.capacity }} Teams
                    </span>
                    <span class="text-[var(--color-text-muted)] block">angemeldet</span>
                  </div>
                </div>

                <div
                    v-if="
                    teamStats.explore.capacity === 0 &&
                    teamStats.explore.registered === 0 &&
                    teamStats.challenge.capacity === 0 &&
                    teamStats.challenge.registered === 0
                  "
                    class="text-[var(--color-text-subtle)] text-xs"
                >
                  Keine Team-Daten verfügbar
                </div>
              </div>
            </div>
          </div>

          <div class="glass-card liquid-surface-inner">
            <h3 class="glass-card__heading">Adresse</h3>
            <p class="mb-3">{{ event?.address }}</p>
            <EventMap
                v-if="event?.address && event?.id"
                :address="event.address"
                :event-id="event.id"
                :event-name="event.name"
                :show-q-r-code="false"
            />
          </div>

          <div class="glass-card liquid-surface-inner">
            <h3 class="glass-card__title">Kontakt</h3>
            <div class="grid gap-3">
              <div
                  v-for="(person, index) in event?.contact"
                  :key="index"
                  class="glass-chip liquid-surface-inner"
              >
                <div class="flex items-center justify-between mb-1 gap-2">
                  <span class="glass-chip__label">{{ person.contact }}</span>
                  <span class="glass-chip__badge">Kontaktperson</span>
                </div>
                <div class="text-sm text-[var(--color-text-muted)] flex items-center gap-1">
                  <svg class="w-4 h-4 flex-shrink-0 opacity-70" fill="currentColor" viewBox="0 0 20 20">
                    <path
                        d="M2.94 5.5a1.5 1.5 0 011.5-1.5h11.12a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5H4.44a1.5 1.5 0 01-1.5-1.5v-9zm1.62.4v.28l5.5 3.44 5.5-3.44v-.28H4.56zm0 1.48v6.12h10.88V7.38L10 10.75 4.56 7.38z"
                    />
                  </svg>
                  {{ person.contact_email }}
                </div>
                <p v-if="person.contact_infos" class="text-xs text-[var(--color-text-subtle)] mt-1">
                  {{ person.contact_infos }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <!-- Right column: FreeBlocks -->
        <div class="lg:col-span-2 space-y-4 h-fit order-2">
          <div class="glass-card liquid-surface-inner">
            <h2 class="glass-card__title">Aktivitäten, die den Ablauf nicht beeinflussen</h2>
            <FreeBlocks
                :event-date="event?.date"
                :event-days="event?.days"
                :plan-id="planId"
                :show-challenge="showChallenge"
                :show-explore="showExplore"
            />
          </div>
          <div class="glass-card liquid-surface-inner">
            <SharePointDocumentsBox/>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.event-overview__name {
  color: var(--color-accent);
}
</style>
