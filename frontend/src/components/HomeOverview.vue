<script lang="ts" setup>
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {useEventStore} from '@/stores/event'
import {schedulePlanPrefetch, usePlanCacheStore} from '@/stores/planCache'
import SharePointDocumentsBox from '@/components/molecules/SharePointDocumentsBox.vue'
import EventMap from '@/components/molecules/EventMap.vue'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {seasonLogoAlt, seasonLogoSrc} from '@/utils/images'
import {cleanEventName, getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import {eventPrograms, programDisplayName, teamPathFor, programCompact} from '@/utils/eventPrograms'
import {staffingSummaryFromReadiness, type StaffingScopeSummary} from '@/utils/volunteerStaffingSummary'
import VolunteerStaffingSummary from '@/components/volunteers/VolunteerStaffingSummary.vue'
import EventSelectModal from '@/components/molecules/EventSelectModal.vue'
import PublicLinkStrip from '@/components/molecules/PublicLinkStrip.vue'
import NoticePane from '@/components/molecules/NoticePane.vue'
import AdminDbIdsBox from '@/components/molecules/AdminDbIdsBox.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {apiError} from '@/utils/apiError'

defineOptions({name: 'HomeOverview'})

const eventStore = useEventStore()
const planCache = usePlanCacheStore()
const event = computed(() => eventStore.selectedEvent)
const showEventModal = ref(false)

const teamStats = ref<Array<{
  first_program: number | string
  name: string
  programName: string
  capacity: number
  registered: number
}>>([])
const loading = ref(true)

const programList = computed(() => eventPrograms(event.value))

const staffingSummary = computed<StaffingScopeSummary[]>(() =>
  staffingSummaryFromReadiness(readiness.value?.staffing_summary, programList.value),
)

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

function programHasDiscrepancy(programName: string): boolean {
  return !!event.value?.discrepancyByProgram?.[programCompact(programName)]
}

const readiness = computed(() => eventStore.readiness)

async function loadOverviewData() {
  if (!event.value?.id) return
  loading.value = true
  const eventId = event.value.id

  try {
    let ensure: {
      plan_id?: number
      existing?: boolean
      generated?: boolean
      staffing_synced?: boolean
      locked?: boolean
    } | null = null

    try {
      const {data} = await axios.post(`/events/${eventId}/ensure-workspace`)
      ensure = data
      planCache.invalidatePlan()
      if (ensure?.generated || ensure?.staffing_synced) {
        const parts: string[] = []
        if (ensure.generated) parts.push('Ablauf wurde erzeugt')
        if (ensure.staffing_synced) parts.push('Helfer:innen-Rollen wurden angelegt')
        showGlassToast(parts.join('. ') + '.', 'success')
      }
    } catch (e: unknown) {
      showGlassToast(apiError(e, 'Workspace konnte nicht vorbereitet werden'), 'error')
    }

    const [drahtRes] = await Promise.allSettled([
      planCache.getDrahtData(eventId),
      planCache.getPlan(eventId),
      eventStore.refreshReadiness(eventId),
    ])

    if (drahtRes.status === 'fulfilled') {
      const data = drahtRes.value
      event.value.address = data.address
      event.value.contact = data.contact
      event.value.information = data.information
      const programs = Array.isArray(data.programs) ? data.programs : []
      teamStats.value = eventPrograms({ programs }).map((p: any) => ({
        first_program: p.first_program ?? p.name,
        name: programDisplayName(p),
        programName: p.name,
        capacity: Number(p.capacity || 0),
        registered: p.teams ? Object.keys(p.teams).length : 0,
      }))
    }
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
        <AdminDbIdsBox/>
      </div>
    </div>

    <EventSelectModal :open="showEventModal" @close="showEventModal = false"/>

    <PublicLinkStrip/>

    <NoticePane/>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
      <div class="xl:col-span-1 space-y-4 order-2 xl:order-1">
        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__title">Teams</h2>

          <div class="space-y-2">
            <RouterLink
                v-for="stat in teamStats"
                :key="stat.first_program"
                :to="teamPathFor({ name: stat.programName, first_program: Number(stat.first_program) })"
                class="flex items-center gap-2 rounded-lg px-3 py-1.5 liquid-surface-inner hover:bg-[var(--color-bg-hover)] transition-colors no-underline text-inherit"
            >
              <ProgramLogo
                  :program="{first_program: stat.first_program, name: stat.programName}"
                  size="lg"
              />
              <div class="min-w-0 flex-1">
                <div class="font-medium flex items-center justify-between gap-2">
                  <span class="inline-flex items-center gap-1.5 min-w-0">
                    <span>{{ stat.registered }} von {{ stat.capacity }} Teams angemeldet</span>
                    <span
                        v-if="programHasDiscrepancy(stat.programName)"
                        class="inline-block h-2 w-2 rounded-full bg-red-500 shrink-0"
                        title="Abweichung zu DRAHT"
                        aria-label="Abweichung zu DRAHT"
                    />
                  </span>
                  <i class="bi bi-chevron-right text-[var(--color-text-subtle)] shrink-0" aria-hidden="true"/>
                </div>
              </div>
            </RouterLink>

            <p
                v-if="teamStats.length === 0"
                class="text-sm text-[var(--color-text-subtle)]"
            >
              {{ loading ? 'Lade Teamdaten…' : 'Keine Team-Daten verfügbar' }}
            </p>
          </div>
        </div>

        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__title">Helfer:innen</h2>

          <VolunteerStaffingSummary
              :scopes="staffingSummary"
              :programs="programList"
              :loading="loading"
              layout="teams"
              link-to="/plan/volunteers/staffing"
          />
        </div>

        <div class="glass-card liquid-surface-inner">
          <h2 class="glass-card__title">Hilfe & Einstieg</h2>
          <ul class="space-y-2 text-sm">
            <li>
              <RouterLink to="/plan/help" class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline">
                Hilfe
              </RouterLink>
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
