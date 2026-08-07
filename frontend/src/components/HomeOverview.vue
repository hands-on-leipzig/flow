<script lang="ts" setup>
import {computed, onMounted, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import axios from 'axios'
import dayjs from 'dayjs'
import {useEventStore} from '@/stores/event'
import {schedulePlanPrefetch, usePlanCacheStore} from '@/stores/planCache'
import SharePointDocumentsBox from '@/components/molecules/SharePointDocumentsBox.vue'
import EventMap from '@/components/molecules/EventMap.vue'
import {imageUrl, programLogoAlt, programLogoSrc, seasonLogoAlt, seasonLogoSrc} from '@/utils/images'
import {cleanEventName, getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import EventSelectModal from '@/components/molecules/EventSelectModal.vue'

defineOptions({name: 'HomeOverview'})

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const router = useRouter()
const event = computed(() => eventStore.selectedEvent)
const showEventModal = ref(false)

const teamStats = ref({
  explore: {capacity: 0, registered: 0},
  challenge: {capacity: 0, registered: 0},
})
const hasPlan = ref(false)
const publicationLevel = ref<number | null>(null)
const loading = ref(true)

const seasonName = computed(() =>
    (event.value as any)?.season_rel?.name
    || (event.value as any)?.seasonRel?.name
    || null
)
const headingType = computed(() => getAbbreviatedCompetitionType(event.value) || 'Veranstaltung')
const headingPlace = computed(() => cleanEventName(event.value) || event.value?.name || '—')
const headingDate = computed(() => {
  if (!event.value?.date) return ''
  const start = dayjs(event.value.date)
  if (!start.isValid()) return ''
  if ((event.value.days || 1) > 1) {
    const end = start.add(event.value.days - 1, 'day')
    return `${start.format('DD.MM.YYYY')}–${end.format('DD.MM.YYYY')}`
  }
  return start.format('DD.MM.YYYY')
})

const daysUntilEvent = computed(() => {
  if (!event.value?.date) return null
  const start = dayjs(event.value.date).startOf('day')
  if (!start.isValid()) return null
  return start.diff(dayjs().startOf('day'), 'day')
})

const eventSoon = computed(() => {
  const days = daysUntilEvent.value
  return days !== null && days >= 0 && days <= 2
})

const hasTeamDiscrepancy = computed(() => !!event.value?.hasTeamDiscrepancy)

const readiness = computed(() => eventStore.readiness)

const publicationLabel = computed(() => {
  const level = publicationLevel.value
  if (level === null) return 'Status unbekannt'
  if (level <= 1) return 'Nur Planung / Anmeldung'
  if (level === 2 || level === 3) return 'Ablauf-Überblick veröffentlicht'
  return 'Volle Details veröffentlicht'
})

const checklist = computed(() => {
  const r = readiness.value
  return [
    {
      key: 'teams',
      label: 'Team-Anmeldung geprüft',
      ok: !hasTeamDiscrepancy.value,
      warnText: 'DRAHT und FLOW weichen voneinander ab',
      path: '/plan/teams/explore',
    },
    {
      key: 'schedule',
      label: 'Ablauf bereit',
      ok: !!r?.explore_teams_ok && !!r?.challenge_teams_ok && hasPlan.value,
      warnText: hasPlan.value
          ? 'Teamzahlen oder Kapazitäten prüfen'
          : 'Noch kein Ablauf erzeugt',
      path: '/schedule',
    },
    {
      key: 'rooms',
      label: 'Räume zugeordnet',
      ok: !!r?.room_mapping_ok,
      warnText: 'Raumzuordnung unvollständig',
      path: '/rooms',
    },
    {
      key: 'publish',
      label: 'Ausgabe freigeschaltet',
      ok: (publicationLevel.value ?? 1) >= 3,
      warnText: 'Öffentlicher Überblick noch nicht freigeschaltet',
      path: '/publish',
    },
  ]
})

const openChecklistCount = computed(() => checklist.value.filter((item) => !item.ok).length)

const videoUrl =
    'https://handsontechnology-my.sharepoint.com/:v:/g/personal/jr_hands-on-technology_org/EYLes-Kq4GlDuBpUaxolgn4B4naGZakiVMW7Dq0xgWmskA?nav=eyJyZWZlcnJhbEluZm8iOnsicmVmZXJyYWxBcHAiOiJTdHJlYW1XZWJBcHAiLCJyZWZlcnJhbFZpZXciOiJTaGFyZURpYWxvZy1MaW5rIiwicmVmZXJyYWxBcHBQbGF0Zm9ybSI6IldlYiIsInJlZmVycmFsTW9kZSI6InZpZXcifX0%3D&e=T5yiJJ'
const presentationUrl = imageUrl('/flow/FLOW 2025-2026.pdf')
const noshowUrl = imageUrl('/flow/FLOW - Wenn Teams am Tag der Veranstaltung nicht erscheinen.pdf')

async function loadOverviewData() {
  if (!event.value?.id) return
  loading.value = true
  const eventId = event.value.id

  try {
    const [drahtRes, planRes, publishRes] = await Promise.allSettled([
      planCache.getDrahtData(eventId),
      planCache.getPlan(eventId),
      axios.get(`/publish/level/${eventId}`),
      eventStore.refreshReadiness(eventId),
    ])

    if (drahtRes.status === 'fulfilled') {
      const data = drahtRes.value
      event.value.address = data.address
      event.value.contact = data.contact
      event.value.information = data.information
      teamStats.value = {
        explore: {
          capacity: data.capacity_explore || 0,
          registered: data.teams_explore ? Object.keys(data.teams_explore).length : 0,
        },
        challenge: {
          capacity: data.capacity_challenge || 0,
          registered: data.teams_challenge ? Object.keys(data.teams_challenge).length : 0,
        },
      }
    }

    hasPlan.value = planRes.status === 'fulfilled' && !!planRes.value?.id
    publicationLevel.value =
        publishRes.status === 'fulfilled' ? (publishRes.value.data?.level ?? 1) : null
  } finally {
    loading.value = false
  }

  // Discrepancy check shares planCache draht-data + short in-flight dedupe with
  // fetchSelectedEvent — only one draht-data / teams pair per load.
  try {
    await eventStore.updateTeamDiscrepancyStatus()
  } catch {
    // non-blocking
  }

  schedulePlanPrefetch(eventId)
}

function goTo(path: string) {
  router.push(path)
}

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  await loadOverviewData()
})

watch(
    () => event.value?.id,
    async (newId, oldId) => {
      if (newId && newId !== oldId) {
        await loadOverviewData()
      }
    }
)
</script>

<template>
  <div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-2.5 min-w-0 flex-1">
        <img
            :src="seasonLogoSrc(seasonName)"
            :alt="seasonLogoAlt(seasonName)"
            class="h-9 w-auto shrink-0 object-contain"
        />
        <h1 class="min-w-0 text-lg sm:text-xl lg:text-2xl font-bold text-[var(--color-text)] truncate">
          <span>{{ headingType }}</span>
          <span class="text-[var(--color-text-muted)] font-semibold mx-1.5">·</span>
          <span>{{ headingPlace }}</span>
          <template v-if="headingDate">
            <span class="text-[var(--color-text-muted)] font-semibold mx-1.5">·</span>
            <span class="tabular-nums font-semibold">{{ headingDate }}</span>
          </template>
        </h1>
        <button
            type="button"
            class="glass-btn-secondary !px-2.5 !py-1.5 !text-sm shrink-0 inline-flex items-center gap-1.5"
            title="Veranstaltung wechseln"
            @click="showEventModal = true"
        >
          <i class="bi bi-arrow-left-right" aria-hidden="true"/>
          <span class="hidden sm:inline">Wechseln</span>
        </button>
      </div>

      <div v-if="eventSoon" class="glass-chip liquid-surface-inner flex items-center gap-2 !px-3 !py-2">
        <i class="bi bi-calendar-check text-lg" aria-hidden="true"/>
        <div class="text-sm">
          <div class="font-medium">
            <template v-if="daysUntilEvent === 0">Heute ist Veranstaltungstag</template>
            <template v-else-if="daysUntilEvent === 1">Morgen ist Veranstaltungstag</template>
            <template v-else>Veranstaltung in {{ daysUntilEvent }} Tagen</template>
          </div>
          <button type="button" class="text-[var(--color-accent)] hover:underline" @click="goTo('/live')">
            Zu den Live-Tools →
          </button>
        </div>
      </div>
    </div>

    <EventSelectModal :open="showEventModal" @close="showEventModal = false"/>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="xl:col-span-1 space-y-4 order-2 xl:order-1">
        <div class="glass-card liquid-surface-inner">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="glass-card__title !mb-0">Teams</h2>
            <button type="button" class="glass-btn-secondary !px-3 !py-1.5 !text-sm" @click="goTo('/teams')">
              Zur Teams-Seite
            </button>
          </div>

          <div
              v-if="hasTeamDiscrepancy"
              class="mb-3 rounded-lg px-3 py-2 text-sm bg-[color-mix(in_srgb,var(--color-warning,#f59e0b)_18%,transparent)] border border-[color-mix(in_srgb,var(--color-warning,#f59e0b)_40%,transparent)]"
          >
            <div class="flex items-start gap-2">
              <i class="bi bi-exclamation-triangle-fill mt-0.5" aria-hidden="true"/>
              <div>
                <p class="font-medium">Sync mit DRAHT nötig</p>
                <p class="text-[var(--color-text-muted)]">
                  Anmeldungen weichen von FLOW ab. Bitte auf der Teams-Seite abgleichen.
                </p>
              </div>
            </div>
          </div>

          <div class="space-y-3">
            <div
                v-if="teamStats.explore.capacity > 0 || teamStats.explore.registered > 0"
                class="flex items-start gap-2"
            >
              <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="w-9 h-9 flex-shrink-0"/>
              <div>
                <span class="font-medium block">
                  {{ teamStats.explore.registered }} von {{ teamStats.explore.capacity }} Teams
                </span>
                <span class="text-sm text-[var(--color-text-muted)]">Explore angemeldet</span>
              </div>
            </div>

            <div
                v-if="teamStats.challenge.capacity > 0 || teamStats.challenge.registered > 0"
                class="flex items-start gap-2"
            >
              <img :alt="programLogoAlt('C')" :src="programLogoSrc('C')" class="w-9 h-9 flex-shrink-0"/>
              <div>
                <span class="font-medium block">
                  {{ teamStats.challenge.registered }} von {{ teamStats.challenge.capacity }} Teams
                </span>
                <span class="text-sm text-[var(--color-text-muted)]">Challenge angemeldet</span>
              </div>
            </div>

            <p
                v-if="
                  teamStats.explore.capacity === 0 &&
                  teamStats.explore.registered === 0 &&
                  teamStats.challenge.capacity === 0 &&
                  teamStats.challenge.registered === 0
                "
                class="text-sm text-[var(--color-text-subtle)]"
            >
              {{ loading ? 'Lade Teamdaten…' : 'Keine Team-Daten verfügbar' }}
            </p>
          </div>
        </div>

        <div class="glass-card liquid-surface-inner">
          <div class="flex items-center justify-between gap-2 mb-3">
            <h2 class="glass-card__title !mb-0">Nächste Schritte</h2>
            <span
                v-if="openChecklistCount > 0"
                class="text-xs font-medium px-2 py-1 rounded-full bg-[color-mix(in_srgb,var(--color-warning,#f59e0b)_20%,transparent)]"
            >
              {{ openChecklistCount }} offen
            </span>
            <span
                v-else
                class="text-xs font-medium px-2 py-1 rounded-full bg-[color-mix(in_srgb,var(--color-success,#22c55e)_20%,transparent)]"
            >
              Alles erledigt
            </span>
          </div>

          <ul class="space-y-2">
            <li v-for="item in checklist" :key="item.key">
              <button
                  type="button"
                  class="w-full text-left rounded-lg px-3 py-2 liquid-surface-inner hover:bg-[var(--color-bg-hover)] transition-colors"
                  @click="goTo(item.path)"
              >
                <div class="flex items-start gap-2">
                  <i
                      class="bi mt-0.5"
                      :class="item.ok ? 'bi-check-circle-fill text-green-600' : 'bi-circle text-[var(--color-text-subtle)]'"
                      aria-hidden="true"
                  />
                  <div class="min-w-0 flex-1">
                    <div class="font-medium flex items-center justify-between gap-2">
                      <span>{{ item.label }}</span>
                      <i class="bi bi-chevron-right text-[var(--color-text-subtle)]" aria-hidden="true"/>
                    </div>
                    <p v-if="!item.ok" class="text-sm text-[var(--color-text-muted)]">{{ item.warnText }}</p>
                  </div>
                </div>
              </button>
            </li>
          </ul>

          <p class="mt-3 text-sm text-[var(--color-text-muted)]">
            Ausgabe:
            <button type="button" class="text-[var(--color-accent)] hover:underline" @click="goTo('/publish')">
              {{ publicationLabel }}
            </button>
          </p>
        </div>

        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__title">Hilfe & Einstieg</h2>
          <ul class="space-y-2 text-sm">
            <li>
              <a
                  :href="videoUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline"
              >
                <i class="bi bi-play-circle" aria-hidden="true"/>
                Einführungsvideo
              </a>
            </li>
            <li>
              <a
                  :href="presentationUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline"
              >
                <i class="bi bi-file-earmark-slides" aria-hidden="true"/>
                FLOW-Präsentation
              </a>
            </li>
            <li>
              <a
                  :href="noshowUrl"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline"
              >
                <i class="bi bi-file-earmark-text" aria-hidden="true"/>
                Wenn Teams nicht erscheinen
              </a>
            </li>
            <li>
              <button
                  type="button"
                  class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline"
                  @click="goTo('/schedule/free')"
              >
                <i class="bi bi-calendar2-plus" aria-hidden="true"/>
                Freie Blöcke
              </button>
            </li>
          </ul>
        </div>
      </div>

      <div class="xl:col-span-2 order-1 xl:order-2 space-y-4">
        <div class="glass-card liquid-surface-inner">
          <SharePointDocumentsBox/>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
          <div class="glass-card liquid-surface-inner">
            <h2 class="glass-card__title">Adresse</h2>
            <p class="mb-3">{{ event?.address || (loading ? 'Lade Adresse…' : 'Keine Adresse hinterlegt') }}</p>
            <EventMap
                v-if="event?.address && event?.id"
                :address="event.address"
                :event-id="event.id"
                :event-name="event.name"
                :show-q-r-code="false"
            />
          </div>

          <div class="glass-card liquid-surface-inner">
            <h2 class="glass-card__title">Kontakt</h2>
            <div v-if="event?.contact?.length" class="grid gap-3">
              <div
                  v-for="(person, index) in event.contact"
                  :key="index"
                  class="glass-chip liquid-surface-inner"
              >
                <div class="flex items-center justify-between mb-1 gap-2">
                  <span class="glass-chip__label">{{ person.contact }}</span>
                  <span class="glass-chip__badge">Kontaktperson</span>
                </div>
                <div class="text-sm text-[var(--color-text-muted)] flex items-center gap-1">
                  <i class="bi bi-envelope" aria-hidden="true"/>
                  {{ person.contact_email }}
                </div>
                <p v-if="person.contact_infos" class="text-xs text-[var(--color-text-subtle)] mt-1">
                  {{ person.contact_infos }}
                </p>
              </div>
            </div>
            <p v-else class="text-sm text-[var(--color-text-subtle)]">
              {{ loading ? 'Lade Kontakte…' : 'Keine Kontakte hinterlegt' }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
