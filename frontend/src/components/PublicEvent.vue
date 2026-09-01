<script setup>
import {ref, computed, onMounted} from 'vue'
import {useRoute} from 'vue-router'
import axios from 'axios'
import dayjs from 'dayjs'
import ProgramLogo from '@/components/atoms/ProgramLogo.vue'
import {imageUrl} from '@/utils/images'
import {eventPrograms, resolveProgramRef} from '@/utils/eventPrograms'
import {cleanEventName, getAbbreviatedCompetitionType} from '@/utils/eventTitle'
import {formatBerlinDateTimeFromUtc, formatBerlinTimeOnly, parseBerlinWallTime} from '@/utils/dateTimeFormat'
import EventMap from '@/components/molecules/EventMap.vue'
import PublicSchedule from '@/components/PublicSchedule.vue'
import VolunteerPublicFormFlow from '@/components/volunteers/VolunteerPublicFormFlow.vue'

const route = useRoute()
const event = ref(null)
const scheduleInfo = ref(null)
const loading = ref(true)
const error = ref(null)
const publicPlanId = ref(null)
const eventLogos = ref([])

const formStep = ref(null)
const formEmail = ref('')

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

const heroTitle = computed(() => {
  const parts = [headingType.value, headingPlace.value]
  if (headingDate.value) parts.push(headingDate.value)
  return parts.join(' · ')
})

const loadEvent = async () => {
  try {
    loading.value = true
    error.value = null

    const eventResponse = await axios.get(`/events/slug/${route.params.slug}`)
    event.value = eventResponse.data

    try {
      let source = 'unknown'
      if (route.query.source === 'qr') {
        source = 'qr'
      } else if (document.referrer) {
        source = 'referrer'
      } else {
        source = 'direct'
      }

      const clientData = {
        event_id: event.value.id,
        source: source,
        screen_width: window.screen.width,
        screen_height: window.screen.height,
        viewport_width: window.innerWidth,
        viewport_height: window.innerHeight,
        device_pixel_ratio: window.devicePixelRatio || 1,
        touch_support: 'ontouchstart' in window || navigator.maxTouchPoints > 0,
        connection_type: navigator.connection?.effectiveType ||
            navigator.connection?.type ||
            null
      }

      axios.post('/one-link-access', clientData).catch(err => {
        console.error('Failed to log access:', err)
      })
    } catch (err) {
      console.error('Error preparing access log:', err)
    }

    const scheduleResponse = await axios.get(`/publish/public-information/${event.value.id}`)
    scheduleInfo.value = scheduleResponse.data

    if (scheduleInfo.value?.level === 4) {
      try {
        const planResponse = await axios.get(`/plans/public/${event.value.id}`)
        publicPlanId.value = planResponse.data.id
      } catch (planError) {
        console.error('Error fetching plan ID:', planError)
        if (planError.response?.status === 404) {
          error.value = 'Plan nicht gefunden'
        } else {
          console.warn('Plan fetch failed, but continuing with page display')
        }
      }
    }

    try {
      const logosResponse = await axios.get(`/events/${event.value.id}/logos`)
      eventLogos.value = logosResponse.data
    } catch (logoError) {
      console.error('Error fetching logos:', logoError)
      eventLogos.value = []
    }
  } catch (err) {
    console.error('Error loading event:', err)
    error.value = err.response?.data?.error || 'Fehler beim Laden der Veranstaltung'
  } finally {
    loading.value = false
  }
}

const formatDateOnly = (dateString, daysToAdd = 0) => {
  if (!dateString) return ''
  const date = new Date(dateString)
  if (daysToAdd > 0) {
    date.setDate(date.getDate() + daysToAdd)
  }
  return date.toLocaleDateString('de-DE', {
    weekday: 'long',
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

function mapTimelineItems(items) {
  if (!Array.isArray(items) || items.length === 0) return []
  return items.map(item => {
    const timestamp = parseBerlinWallTime(item.value) ?? 0
    return {
      time: formatBerlinTimeOnly(item.value),
      timeDisplay: formatBerlinTimeOnly(item.value),
      label: item.label || '',
      joint: item.joint === true,
      timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

const planLanes = computed(() => {
  const lanes = scheduleInfo.value?.plan?.lanes
  return Array.isArray(lanes) ? lanes : []
})

const planLastChangeDisplay = computed(() => {
  const raw = scheduleInfo.value?.plan?.last_change
  return raw ? formatBerlinDateTimeFromUtc(raw) : ''
})

function laneColor(lane) {
  if (!lane?.color_hex) return 'var(--color-accent)'
  return `#${lane.color_hex}`
}

function laneProgramRef(lane) {
  return resolveProgramRef(event.value, lane.program_id)
}

function laneTimelineItems(lane) {
  return mapTimelineItems(lane?.times)
}

const timelineMinHeight = computed(() => {
  const maxItems = planLanes.value.reduce(
    (max, lane) => Math.max(max, lane.times?.length ?? 0),
    0,
  )
  return `${maxItems * 70}px`
})

const isContentVisible = (level) => {
  if (!scheduleInfo.value) return false
  return scheduleInfo.value.level >= level
}

const showInteractiveSchedule = computed(() =>
  !loading.value && !error.value && event.value && isContentVisible(4) && !!publicPlanId.value
)

const teamLanes = computed(() => {
  const lanes = scheduleInfo.value?.teams?.lanes
  return Array.isArray(lanes) ? lanes : []
})

const hasTeamsSection = computed(() => teamLanes.value.length > 0)

const helperSearch = computed(() => scheduleInfo.value?.helper_search ?? null)

const showHelperSearchSection = computed(() => {
  if (!scheduleInfo.value || !helperSearch.value) return false
  const level = scheduleInfo.value.level
  return level >= 1 && level < 4
})

const volunteerDataEntry = computed(() => scheduleInfo.value?.volunteer_data_entry ?? null)

const showVolunteerDataEntrySection = computed(() => {
  if (!scheduleInfo.value || !volunteerDataEntry.value?.enabled) return false
  const level = scheduleInfo.value.level
  return level >= 1 && level < 4
})

function openVolunteerForm() {
  formStep.value = 'email'
  formEmail.value = ''
}

function closeVolunteerForm() {
  formStep.value = null
  formEmail.value = ''
}

const helperSearchPrimaryScopes = computed(() => {
  const scopes = helperSearch.value?.scopes
  if (!Array.isArray(scopes)) return []
  const byKey = new Map(scopes.map((s) => [s.key, s]))
  return ['cross', 'local'].map((key) => byKey.get(key)).filter(Boolean)
})

const helperSearchProgramScopes = computed(() => {
  const scopes = helperSearch.value?.scopes
  if (!Array.isArray(scopes)) return []
  return scopes.filter((s) => typeof s.key === 'string' && s.key.startsWith('program:'))
})

function helperScopeColor(scope) {
  if (scope?.color_hex) return `#${scope.color_hex}`
  return 'var(--color-accent)'
}

function helperScopeProgramRef(scope) {
  if (!scope?.program_id) return null
  return resolveProgramRef(event.value, scope.program_id)
}

const exploreProgram = computed(() => resolveProgramRef(event.value, 'EXPLORE'))
const challengeProgram = computed(() => resolveProgramRef(event.value, 'CHALLENGE'))

const errorFooterPrograms = computed(() => {
  const programs = eventPrograms(event.value)
  if (programs.length > 0) return programs
  return [exploreProgram.value, challengeProgram.value].filter(Boolean)
})

onMounted(async () => {
  await loadEvent()
})
</script>

<template>
  <div
      class="pe"
      :class="showInteractiveSchedule ? 'pe--schedule' : 'pe--page'"
  >
    <!-- Loading -->
    <div v-if="loading" class="pe-state">
      <div class="pe-state__card glass-card liquid-surface-inner">
        <div class="pe-spinner" aria-hidden="true"/>
        <p class="pe-state__text">Veranstaltung wird geladen…</p>
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="pe-state">
      <div class="pe-state__card glass-card liquid-surface-inner pe-state__card--wide">
        <div class="pe-error-badge">
          <i class="bi bi-search" aria-hidden="true"/>
        </div>
        <h1 class="pe-error-title">
          {{ error === 'Plan nicht gefunden' ? 'Plan nicht gefunden' : 'Event nicht gefunden' }}
        </h1>
        <p class="pe-muted">
          Für die Adresse, die du aufgerufen hast, konnten wir leider
          {{ error === 'Plan nicht gefunden' ? 'keinen Plan' : 'kein Event' }} finden.
          Bitte prüfe die Adresse noch einmal.
        </p>
        <div class="pe-slug glass-chip liquid-surface-inner">
          <span class="pe-slug__label">Aufgerufene Adresse</span>
          <code class="pe-slug__value">{{ route.params.slug || 'N/A' }}</code>
        </div>
        <div class="pe-error-logos">
          <ProgramLogo
              v-for="program in errorFooterPrograms"
              :key="program.first_program ?? program.name"
              :event="event"
              :program="program"
              class="pe-error-logos__img"
          />
        </div>
      </div>
    </div>

    <!-- Level 4: interactive schedule -->
    <div v-else-if="showInteractiveSchedule" class="pe-schedule">
      <PublicSchedule :plan-id="publicPlanId" embedded/>
    </div>

    <!-- Levels 1–3 -->
    <div v-else-if="event" class="pe-content">
      <VolunteerPublicFormFlow
          v-if="formStep"
          :step="formStep"
          :email="formEmail"
          :slug="String(route.params.slug ?? '')"
          @update:email="formEmail = $event"
          @update:step="formStep = $event"
          @cancel="closeVolunteerForm"
      />

      <template v-else>
      <header class="pe-hero glass-card liquid-surface-inner">
        <img
            :src="imageUrl('/flow/hot+fll.png')"
            alt="FLOW Logo"
            class="pe-hero__logo"
        />
        <h1 class="pe-hero__title">{{ heroTitle }}</h1>
      </header>

      <!-- Zeitplan (Basis) / Wichtige Zeiten (Ablauf+) -->
      <section class="glass-card liquid-surface-inner pe-section">
        <template v-if="!isContentVisible(3)">
          <h2 class="glass-card__title">Zeitplan</h2>
          <p class="pe-muted">
            Das Veranstaltungsteam hat noch keinen Zeitplan veröffentlicht. Sobald dies geschieht,
            wirst du ihn hier sehen können. Bitte kontaktiere sie direkt, um weitere Informationen
            zu erhalten.
          </p>
        </template>

        <template v-else>
          <h2 class="glass-card__title">
            <template v-if="planLastChangeDisplay">
              Wichtige Zeiten - Stand {{ planLastChangeDisplay }}.
            </template>
            <template v-else>
              Wichtige Zeiten
            </template>
          </h2>

          <div
              v-if="planLanes.length > 0"
              class="pe-timeline-grid"
              :style="{ '--pe-lane-count': planLanes.length }"
          >
            <div
                v-for="lane in planLanes"
                :key="lane.program_id"
                class="pe-program"
                :style="{ '--pe-program': laneColor(lane) }"
            >
              <h3 class="pe-program__title">
                <ProgramLogo
                    v-if="laneProgramRef(lane)"
                    :event="event"
                    :program="laneProgramRef(lane)"
                    class="pe-program__logo"
                />
                <span>{{ lane.name }}</span>
              </h3>
              <div class="pe-timeline" :style="{ minHeight: timelineMinHeight }">
                <div
                    v-for="(item, index) in laneTimelineItems(lane)"
                    :key="`${lane.program_id}-${index}`"
                    class="pe-timeline__item"
                    :data-joint="item.joint ? 'true' : 'false'"
                >
                  <div class="pe-timeline__dot"/>
                  <div class="pe-timeline__card">
                    <div class="pe-timeline__row">
                      <span class="pe-timeline__label">{{ item.label }}</span>
                      <span class="pe-timeline__time">{{ item.timeDisplay }}</span>
                    </div>
                    <p v-if="item.description" class="pe-timeline__desc">{{ item.description }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <p v-else class="pe-muted">
            Das Veranstaltungsteam hat noch keinen Zeitplan veröffentlicht. Sobald dies geschieht,
            wirst du ihn hier sehen können. Bitte kontaktiere sie direkt, um weitere Informationen
            zu erhalten.
          </p>
        </template>
      </section>

      <!-- Allgemeine Infos -->
      <section v-if="isContentVisible(1) && scheduleInfo" class="glass-card liquid-surface-inner pe-section">
        <h2 class="glass-card__title">Allgemeine Infos</h2>
        <div class="pe-info-grid">
          <div class="pe-info-block">
            <h3 class="pe-info-block__title">
              <i class="bi bi-pin-map-fill" aria-hidden="true"/>
              Datum & Ort
            </h3>
            <p class="pe-info-block__text">{{ formatDateOnly(scheduleInfo.date) }}</p>
            <p v-if="scheduleInfo.days > 1" class="pe-info-block__text">
              bis {{ formatDateOnly(scheduleInfo.date, scheduleInfo.days - 1) }}
            </p>
            <div v-if="scheduleInfo.address" class="pe-map">
              <EventMap
                  :address="scheduleInfo.address"
                  :event-id="event.id"
                  :event-name="event.name"
                  :show-q-r-code="true"
              />
            </div>
          </div>

          <div v-if="scheduleInfo.contact?.length" class="pe-info-block">
            <h3 class="pe-info-block__title">
              <i class="bi bi-envelope" aria-hidden="true"/>
              Kontakt
            </h3>
            <div class="pe-contacts">
              <div
                  v-for="(contact, index) in scheduleInfo.contact"
                  :key="index"
                  class="pe-contact glass-chip liquid-surface-inner"
              >
                <div class="pe-contact__name">{{ contact.contact }}</div>
                <a
                    v-if="contact.contact_email"
                    class="pe-contact__email"
                    :href="`mailto:${contact.contact_email}`"
                >{{ contact.contact_email }}</a>
                <div v-if="contact.contact_infos" class="pe-contact__meta">{{ contact.contact_infos }}</div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- Dateneingabe für Helfer:innen -->
      <section
          v-if="showVolunteerDataEntrySection"
          class="glass-card liquid-surface-inner pe-section"
      >
        <h2 class="glass-card__title">Dateneingabe für Helfer:innen</h2>
        <p class="pe-muted pe-volunteer-form-intro">
          Helfer:innen können hier ihre Daten für diese Veranstaltung prüfen und ergänzen.
        </p>
        <button type="button" class="glass-btn-accent" @click="openVolunteerForm">
          Daten eingeben
        </button>
      </section>

      <!-- Suche nach Helfer:innen -->
      <section
          v-if="showHelperSearchSection"
          class="glass-card liquid-surface-inner pe-section"
      >
        <h2 class="glass-card__title">Suche nach Helfer:innen</h2>

        <div
            class="pe-timeline-grid pe-teams-grid"
            :style="{ '--pe-lane-count': Math.max(helperSearchPrimaryScopes.length, 1) }"
        >
          <div
              v-for="scope in helperSearchPrimaryScopes"
              :key="scope.key"
              class="pe-program"
              :style="{ '--pe-program': helperScopeColor(scope) }"
          >
            <h3 class="pe-program__title">
              <i
                  v-if="scope.key === 'cross'"
                  class="bi bi-intersect pe-program__logo pe-helper-scope-icon"
                  aria-hidden="true"
              />
              <i
                  v-else-if="scope.key === 'local'"
                  class="bi bi-star pe-program__logo pe-helper-scope-icon"
                  aria-hidden="true"
              />
              <span>{{ scope.label }}</span>
            </h3>
            <ul v-if="scope.roles?.length" class="pe-team-list">
              <li
                  v-for="(role, index) in scope.roles"
                  :key="`${scope.key}-${index}`"
                  class="pe-team-list__item pe-team-list__item--name-only"
              >
                <span class="pe-team-list__name">{{ role }}</span>
              </li>
            </ul>
            <p v-else class="pe-muted pe-helper-scope-empty">komplett</p>
          </div>
        </div>

        <div
            v-if="helperSearchProgramScopes.length"
            class="pe-timeline-grid pe-teams-grid pe-helper-programs"
            :style="{ '--pe-lane-count': helperSearchProgramScopes.length }"
        >
          <div
              v-for="scope in helperSearchProgramScopes"
              :key="scope.key"
              class="pe-program"
              :style="{ '--pe-program': helperScopeColor(scope) }"
          >
            <h3 class="pe-program__title">
              <ProgramLogo
                  v-if="helperScopeProgramRef(scope)"
                  :event="event"
                  :program="helperScopeProgramRef(scope)"
                  class="pe-program__logo"
              />
              <span>{{ scope.label }}</span>
            </h3>
            <ul v-if="scope.roles?.length" class="pe-team-list">
              <li
                  v-for="(role, index) in scope.roles"
                  :key="`${scope.key}-${index}`"
                  class="pe-team-list__item pe-team-list__item--name-only"
              >
                <span class="pe-team-list__name">{{ role }}</span>
              </li>
            </ul>
            <p v-else class="pe-muted pe-helper-scope-empty">komplett</p>
          </div>
        </div>

        <p class="pe-helper-cta">Bei Interesse bitte einfach melden.</p>
      </section>

      <!-- Teams -->
      <section
          v-if="isContentVisible(1) && scheduleInfo && hasTeamsSection"
          class="glass-card liquid-surface-inner pe-section"
      >
        <h2 class="glass-card__title">Angemeldete Teams</h2>

        <div
            class="pe-timeline-grid pe-teams-grid"
            :style="{ '--pe-lane-count': teamLanes.length }"
        >
          <div
              v-for="lane in teamLanes"
              :key="lane.program_id"
              class="pe-program"
              :style="{ '--pe-program': laneColor(lane) }"
          >
            <h3 class="pe-program__title">
              <ProgramLogo
                  v-if="laneProgramRef(lane)"
                  :event="event"
                  :program="laneProgramRef(lane)"
                  class="pe-program__logo"
              />
              <span>{{ lane.name }}</span>
            </h3>
            <ul class="pe-team-list">
              <li
                  v-for="(team, index) in lane.teams"
                  :key="`${lane.program_id}-${team.ref ?? index}`"
                  class="pe-team-list__item"
              >
                <span class="pe-team-list__ref">{{ team.ref || '–' }}</span>
                <span class="pe-team-list__name">{{ team.name }}</span>
              </li>
            </ul>
          </div>
        </div>
      </section>

      <!-- Logos -->
      <footer v-if="eventLogos.length > 0" class="pe-logos glass-card liquid-surface-inner">
        <div class="pe-logos__grid">
          <a
              v-for="logo in eventLogos"
              :key="logo.id"
              :href="logo.link || undefined"
              :rel="logo.link ? 'noopener noreferrer' : undefined"
              :target="logo.link ? '_blank' : undefined"
              class="pe-logos__item"
              :class="{ 'pe-logos__item--static': !logo.link }"
              @click="!logo.link && $event.preventDefault()"
          >
            <img :alt="logo.title || 'Logo'" :src="logo.url"/>
          </a>
        </div>
      </footer>
      </template>
    </div>
  </div>
</template>

<style scoped>
.pe--page {
  min-height: 100dvh;
  color: var(--color-text);
}

.pe--schedule {
  width: 100%;
  height: 100dvh;
  overflow: hidden;
  background: #fff;
}

.pe-schedule {
  width: 100%;
  height: 100%;
}

.pe-content {
  max-width: 72rem;
  margin: 0 auto;
  padding: 1.25rem 1rem 2.5rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

@media (min-width: 768px) {
  .pe-content {
    padding: 1.75rem 1.5rem 3rem;
    gap: 1.25rem;
  }
}

.pe-state {
  min-height: 100dvh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem;
}

.pe-state__card {
  text-align: center;
  max-width: 22rem;
  width: 100%;
}

.pe-state__card--wide {
  max-width: 32rem;
}

.pe-state__text {
  margin-top: 1rem;
  font-weight: 600;
  color: var(--color-text);
}

.pe-spinner {
  width: 2.75rem;
  height: 2.75rem;
  margin: 0 auto;
  border-radius: 999px;
  border: 3px solid color-mix(in srgb, var(--color-accent) 25%, transparent);
  border-top-color: var(--color-accent);
  animation: pe-spin 0.8s linear infinite;
}

@keyframes pe-spin {
  to { transform: rotate(360deg); }
}

.pe-error-badge {
  width: 3.25rem;
  height: 3.25rem;
  margin: 0 auto 1rem;
  display: grid;
  place-items: center;
  border-radius: var(--radius-lg, 1rem);
  background: var(--color-accent-soft);
  color: var(--color-accent);
  font-size: 1.35rem;
}

.pe-error-title {
  font-size: clamp(1.35rem, 3vw, 1.85rem);
  font-weight: 700;
  letter-spacing: -0.02em;
  margin-bottom: 0.65rem;
}

.pe-muted {
  color: var(--color-text-muted);
  line-height: 1.55;
  font-size: 0.95rem;
}

.pe-slug {
  margin-top: 1.25rem;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.35rem;
  text-align: left;
}

.pe-slug__label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-subtle);
}

.pe-slug__value {
  font-size: 0.9rem;
  word-break: break-all;
  color: var(--color-text);
}

.pe-error-logos {
  margin-top: 1.25rem;
  display: flex;
  justify-content: center;
  gap: 0.75rem;
}

.pe-error-logos__img {
  width: 2.25rem;
  height: 2.25rem;
}

.pe-hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.85rem;
  text-align: center;
  padding: 1.25rem 1.35rem !important;
}

@media (min-width: 768px) {
  .pe-hero {
    flex-direction: row;
    text-align: left;
    gap: 1.25rem;
    padding: 1.5rem 1.75rem !important;
  }
}

.pe-hero__logo {
  height: 2.75rem;
  width: auto;
  flex-shrink: 0;
}

@media (min-width: 768px) {
  .pe-hero__logo {
    height: 3.5rem;
  }
}

.pe-hero__title {
  flex: 1;
  font-size: clamp(1.35rem, 3.5vw, 2rem);
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--color-text);
  line-height: 1.2;
}

.pe-section {
  padding: 1.25rem 1.25rem 1.4rem !important;
}

@media (min-width: 768px) {
  .pe-section {
    padding: 1.5rem 1.6rem 1.65rem !important;
  }
}

.pe-timeline-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
}

@media (min-width: 768px) {
  .pe-timeline-grid {
    grid-template-columns: repeat(var(--pe-lane-count, 1), minmax(0, 1fr));
    gap: 1.25rem;
  }
}

.pe-timeline-col {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.pe-program {
  --pe-program: var(--color-accent);
  border-radius: var(--radius-lg, 1rem);
  border: 1px solid color-mix(in srgb, var(--pe-program) 28%, var(--color-border-strong));
  background: color-mix(in srgb, var(--pe-program) 6%, #ffffff);
  padding: 1rem 1.05rem 1.15rem;
  display: flex;
  flex-direction: column;
}

.pe-program__title {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text);
  margin-bottom: 1rem;
  line-height: 1.3;
}

.pe-program__title em {
  font-style: italic;
  font-weight: 700;
}

.pe-program__logo {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
}

.pe-timeline {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding-left: 0.15rem;
  flex: 1;
}

.pe-timeline::before {
  content: '';
  position: absolute;
  left: 0.55rem;
  top: 0.35rem;
  bottom: 0.35rem;
  width: 2px;
  background: color-mix(in srgb, var(--pe-program) 45%, transparent);
  border-radius: 999px;
}

.pe-timeline__item {
  position: relative;
  padding-left: 2rem;
}

.pe-timeline__dot {
  position: absolute;
  left: 0.25rem;
  top: 0.85rem;
  width: 0.7rem;
  height: 0.7rem;
  border-radius: 999px;
  background: #fff;
  border: 2px solid var(--pe-program);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--pe-program) 18%, transparent);
}

.pe-timeline__item[data-joint='true'] .pe-timeline__dot {
  border-color: #9ca3af;
  box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.22);
}

.pe-timeline__item[data-type='opening'] .pe-timeline__dot {
  border-color: #16a34a;
  box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.18);
}

.pe-timeline__item[data-type='end'] .pe-timeline__dot {
  border-color: #dc2626;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.18);
}

.pe-timeline__card {
  border-radius: calc(var(--radius-lg, 1rem) - 4px);
  background: color-mix(in srgb, #ffffff 92%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  padding: 0.65rem 0.8rem;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.04);
}

.pe-timeline__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 4.5rem;
  column-gap: 0.75rem;
  align-items: baseline;
}

.pe-timeline__label {
  font-size: 0.85rem;
  font-weight: 600;
  color: color-mix(in srgb, var(--pe-program) 75%, var(--color-text));
  min-width: 0;
}

.pe-timeline__time {
  font-size: 1.05rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
  text-align: right;
  white-space: nowrap;
}

.pe-timeline__desc {
  margin-top: 0.25rem;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.pe-info-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
}

@media (min-width: 768px) {
  .pe-info-grid {
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
  }
}

.pe-info-block {
  border-radius: var(--radius-lg, 1rem);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 60%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 55%, transparent);
  padding: 1rem 1.1rem;
}

.pe-info-block__title {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.95rem;
  font-weight: 700;
  margin-bottom: 0.65rem;
  color: var(--color-text);
}

.pe-info-block__title i {
  color: var(--color-accent);
}

.pe-info-block__text {
  font-size: 1.05rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.4;
}

.pe-map {
  margin-top: 0.85rem;
}

.pe-contacts {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.pe-contact {
  display: flex !important;
  flex-direction: column;
  align-items: flex-start !important;
  gap: 0.15rem;
  text-align: left;
}

.pe-contact__name {
  font-weight: 650;
  color: var(--color-text);
}

.pe-contact__email {
  color: var(--color-accent);
  font-weight: 550;
  text-decoration: none;
}

.pe-contact__email:hover {
  text-decoration: underline;
}

.pe-contact__meta {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.pe-teams-grid {
  margin-top: 0.25rem;
}

.pe-helper-programs {
  margin-top: 1rem;
}

.pe-helper-scope-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.15rem;
  line-height: 1;
  color: var(--pe-program);
}

.pe-helper-scope-empty {
  margin: 0;
  font-size: 0.9rem;
}

.pe-helper-cta {
  margin: 0.85rem 0 0;
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.pe-volunteer-form-intro {
  margin-bottom: 1rem;
}

.pe-team-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.pe-team-list__item {
  display: grid;
  grid-template-columns: 4.5rem minmax(0, 1fr);
  column-gap: 0.75rem;
  align-items: baseline;
  padding: 0.35rem 0;
  border-top: 1px solid color-mix(in srgb, var(--pe-program) 14%, transparent);
}

.pe-team-list__item--name-only {
  grid-template-columns: minmax(0, 1fr);
}

.pe-team-list__item:first-child {
  border-top: none;
  padding-top: 0;
}

.pe-team-list__ref {
  font-size: 0.85rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--pe-program);
  text-align: right;
  white-space: nowrap;
}

.pe-team-list__name {
  font-size: 0.9rem;
  font-weight: 550;
  color: var(--color-text);
  min-width: 0;
}

.pe-logos {
  margin-top: 0.25rem;
}

.pe-logos__grid {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.85rem 1.25rem;
}

.pe-logos__item {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.65rem 0.85rem;
  border-radius: calc(var(--radius-lg, 1rem) - 2px);
  background: color-mix(in srgb, #ffffff 88%, transparent);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 55%, transparent);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.pe-logos__item:not(.pe-logos__item--static):hover {
  transform: translateY(-1px);
  box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}

.pe-logos__item img {
  height: 2.5rem;
  max-width: 7rem;
  object-fit: contain;
}

@media (min-width: 768px) {
  .pe-logos__item img {
    height: 3rem;
    max-width: 8.5rem;
  }
}
</style>
