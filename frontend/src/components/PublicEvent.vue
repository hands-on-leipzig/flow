<script setup>
import {ref, computed, onMounted} from 'vue'
import {useRoute} from 'vue-router'
import axios from 'axios'
import {programLogoSrc, programLogoAlt, imageUrl} from '@/utils/images'
import {PROGRAM_COLOR_HEX} from '@/utils/programTheme'
import {formatTimeOnly} from '@/utils/dateTimeFormat'
import EventMap from '@/components/molecules/EventMap.vue'
import PublicSchedule from '@/components/PublicSchedule.vue'

const route = useRoute()
const event = ref(null)
const scheduleInfo = ref(null)
const loading = ref(true)
const error = ref(null)
const publicPlanId = ref(null)
const eventLogos = ref([])

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

function toLocalDateString(dateInput) {
  if (!dateInput) return ''
  const d = new Date(dateInput)
  if (isNaN(d.getTime())) return ''
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function formatShortWeekday(dateInput) {
  if (!dateInput) return ''
  const d = new Date(dateInput)
  if (isNaN(d.getTime())) return ''
  return new Intl.DateTimeFormat('de-DE', {weekday: 'short'}).format(d)
}

function eventSpansMultipleDays(plan) {
  if (!plan) return false
  const dates = new Set()
  const add = (arr) => {
    if (!Array.isArray(arr)) return
    arr.forEach((item) => {
      if (item?.value) dates.add(toLocalDateString(item.value))
    })
  }
  add(plan.explore_morning)
  add(plan.explore_afternoon)
  add(plan.explore)
  add(plan.challenge)
  return dates.size > 1
}

function getTimeDisplay(isoDateTime, showWeekday) {
  if (!isoDateTime) return ''
  const timeOnly = formatTimeOnly(isoDateTime, true)
  if (showWeekday) {
    const wd = formatShortWeekday(isoDateTime)
    return wd ? `${wd}, ${timeOnly}` : timeOnly
  }
  return timeOnly
}

function mapTimelineItems(items) {
  if (!Array.isArray(items) || items.length === 0) return []
  const showWeekday = eventSpansMultipleDays(scheduleInfo.value?.plan)
  return items.map(item => {
    const timestamp = new Date(item.value).getTime()
    let type = 'briefing'
    const labelLower = item.label?.toLowerCase() || ''
    if (labelLower.includes('eröffnung') || labelLower.includes('opening') || labelLower.includes('beginn')) {
      type = 'opening'
    } else if (labelLower.includes('ende') || labelLower.includes('end')) {
      type = 'end'
    }
    return {
      time: formatTimeOnly(item.value, true),
      timeDisplay: getTimeDisplay(item.value, showWeekday),
      label: item.label || '',
      type,
      timestamp,
      description: item.description || null
    }
  }).sort((a, b) => a.timestamp - b.timestamp)
}

const getExploreMorningTimelineItems = () => mapTimelineItems(scheduleInfo.value?.plan?.explore_morning)
const getExploreAfternoonTimelineItems = () => mapTimelineItems(scheduleInfo.value?.plan?.explore_afternoon)
const getExploreSingleTimelineItems = () => mapTimelineItems(scheduleInfo.value?.plan?.explore)
const getChallengeTimelineItems = () => mapTimelineItems(scheduleInfo.value?.plan?.challenge)

const timelineMinHeight = computed(() => {
  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()
  const challengeItems = getChallengeTimelineItems()
  const maxExploreItems = Math.max(morningItems.length, afternoonItems.length, singleItems.length)
  const maxItems = Math.max(maxExploreItems, challengeItems.length)
  return `${maxItems * 70}px`
})

const combinedExploreHeight = computed(() => {
  const itemHeight = 70
  const headerHeight = 80
  const sectionSpacing = 16
  const morningItems = getExploreMorningTimelineItems()
  const afternoonItems = getExploreAfternoonTimelineItems()
  const singleItems = getExploreSingleTimelineItems()

  let height = 0
  if (morningItems.length > 0 && afternoonItems.length > 0) {
    height = headerHeight + (morningItems.length * itemHeight) + sectionSpacing + headerHeight + (afternoonItems.length * itemHeight)
  } else if (singleItems.length > 0) {
    height = headerHeight + (singleItems.length * itemHeight)
  } else {
    const items = morningItems.length > 0 ? morningItems : afternoonItems
    if (items.length > 0) {
      height = headerHeight + (items.length * itemHeight)
    }
  }
  return `${height}px`
})

const isContentVisible = (level) => {
  if (!scheduleInfo.value) return false
  return scheduleInfo.value.level >= level
}

const showInteractiveSchedule = computed(() =>
  !loading.value && !error.value && event.value && isContentVisible(4) && !!publicPlanId.value
)

const exploreColor = computed(() => {
  if (!scheduleInfo.value?.teams?.explore?.color_hex) return PROGRAM_COLOR_HEX.EXPLORE
  return `#${scheduleInfo.value.teams.explore.color_hex}`
})

const challengeColor = computed(() => {
  if (!scheduleInfo.value?.teams?.challenge?.color_hex) return PROGRAM_COLOR_HEX.CHALLENGE
  return `#${scheduleInfo.value.teams.challenge.color_hex}`
})

const hasTeamsSection = computed(() => {
  const teams = scheduleInfo.value?.teams
  if (!teams) return false
  return (
    (teams.explore?.list?.length > 0 || teams.explore?.registered > 0) ||
    (teams.challenge?.list?.length > 0 || teams.challenge?.registered > 0)
  )
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
          <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="pe-error-logos__img"/>
          <img :alt="programLogoAlt('C')" :src="programLogoSrc('C')" class="pe-error-logos__img"/>
        </div>
      </div>
    </div>

    <!-- Level 4: interactive schedule -->
    <div v-else-if="showInteractiveSchedule" class="pe-schedule">
      <PublicSchedule :plan-id="publicPlanId" embedded/>
    </div>

    <!-- Levels 1–3 -->
    <div v-else-if="event" class="pe-content">
      <header class="pe-hero glass-card liquid-surface-inner">
        <img
            :src="imageUrl('/flow/hot+fll.png')"
            alt="FLOW Logo"
            class="pe-hero__logo"
        />
        <h1 class="pe-hero__title">{{ event.name }}</h1>
      </header>

      <!-- Zeitplan -->
      <section class="glass-card liquid-surface-inner pe-section">
        <h2 class="glass-card__title">Zeitplan</h2>

        <div
            v-if="(isContentVisible(2) || isContentVisible(3)) && scheduleInfo?.plan"
            class="pe-timeline-grid"
        >
          <div class="pe-timeline-col">
            <div
                v-if="getExploreMorningTimelineItems().length > 0"
                class="pe-program"
                :style="{ '--pe-program': exploreColor }"
            >
              <h3 class="pe-program__title">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="pe-program__logo"/>
                <span><em>FIRST</em> LEGO League Explore · Vormittag</span>
              </h3>
              <div class="pe-timeline" :style="{ minHeight: timelineMinHeight }">
                <div
                    v-for="(item, index) in getExploreMorningTimelineItems()"
                    :key="'em-' + index"
                    class="pe-timeline__item"
                    :data-type="item.type"
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

            <div
                v-if="getExploreAfternoonTimelineItems().length > 0"
                class="pe-program"
                :style="{ '--pe-program': exploreColor }"
            >
              <h3 class="pe-program__title">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="pe-program__logo"/>
                <span><em>FIRST</em> LEGO League Explore · Nachmittag</span>
              </h3>
              <div class="pe-timeline" :style="{ minHeight: timelineMinHeight }">
                <div
                    v-for="(item, index) in getExploreAfternoonTimelineItems()"
                    :key="'ea-' + index"
                    class="pe-timeline__item"
                    :data-type="item.type"
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

            <div
                v-else-if="getExploreSingleTimelineItems().length > 0"
                class="pe-program"
                :style="{ '--pe-program': exploreColor }"
            >
              <h3 class="pe-program__title">
                <img :alt="programLogoAlt('E')" :src="programLogoSrc('E')" class="pe-program__logo"/>
                <span><em>FIRST</em> LEGO League Explore</span>
              </h3>
              <div class="pe-timeline" :style="{ minHeight: timelineMinHeight }">
                <div
                    v-for="(item, index) in getExploreSingleTimelineItems()"
                    :key="'es-' + index"
                    class="pe-timeline__item"
                    :data-type="item.type"
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

          <div
              v-if="getChallengeTimelineItems().length > 0"
              class="pe-program"
              :style="{ '--pe-program': challengeColor, minHeight: combinedExploreHeight }"
          >
            <h3 class="pe-program__title">
              <img :alt="programLogoAlt('C')" :src="programLogoSrc('C')" class="pe-program__logo"/>
              <span><em>FIRST</em> LEGO League Challenge</span>
            </h3>
            <div class="pe-timeline" :style="{ minHeight: timelineMinHeight }">
              <div
                  v-for="(item, index) in getChallengeTimelineItems()"
                  :key="'c-' + index"
                  class="pe-timeline__item"
                  :data-type="item.type"
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

      <!-- Teams -->
      <section
          v-if="isContentVisible(1) && scheduleInfo && hasTeamsSection"
          class="glass-card liquid-surface-inner pe-section"
      >
        <h2 class="glass-card__title">Angemeldete Teams</h2>

        <div v-if="scheduleInfo.teams?.explore?.registered > 0" class="pe-teams-block">
          <div
              v-if="scheduleInfo.teams.explore.list"
              class="pe-teams-table-wrap"
              :style="{ '--pe-program': exploreColor }"
          >
            <table class="pe-teams-table">
              <colgroup>
                <col style="width: 15%;">
                <col style="width: 28.33%;">
                <col style="width: 28.33%;">
                <col style="width: 28.34%;">
              </colgroup>
              <thead>
              <tr>
                <th colspan="4">
                  <img
                      :alt="programLogoAlt('E')"
                      :src="imageUrl('/flow/fll_explore_h.png')"
                      class="pe-teams-table__brand"
                  />
                </th>
              </tr>
              </thead>
              <tbody>
              <tr
                  v-for="team in scheduleInfo.teams.explore.list"
                  :key="team.team_number_hot"
              >
                <td class="pe-teams-table__num">{{ team.team_number_hot || '-' }}</td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-people-fill" aria-hidden="true"/>
                    {{ team.name }}
                  </span>
                </td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-building-fill" aria-hidden="true"/>
                    {{ team.organization || '-' }}
                  </span>
                </td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-pin-map-fill" aria-hidden="true"/>
                    {{ team.location || '-' }}
                  </span>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="pe-teams-count glass-chip liquid-surface-inner">
            <img
                :alt="programLogoAlt('E')"
                :src="imageUrl('/flow/fll_explore_h.png')"
                class="pe-teams-count__logo"
            />
            <span>{{ scheduleInfo.teams.explore.registered }} Team(s) angemeldet</span>
          </div>
        </div>

        <div v-if="scheduleInfo.teams?.challenge?.registered > 0" class="pe-teams-block">
          <div
              v-if="scheduleInfo.teams.challenge.list"
              class="pe-teams-table-wrap"
              :style="{ '--pe-program': challengeColor }"
          >
            <table class="pe-teams-table">
              <colgroup>
                <col style="width: 15%;">
                <col style="width: 28.33%;">
                <col style="width: 28.33%;">
                <col style="width: 28.34%;">
              </colgroup>
              <thead>
              <tr>
                <th colspan="4">
                  <img
                      :alt="programLogoAlt('C')"
                      :src="imageUrl('/flow/fll_challenge_h.png')"
                      class="pe-teams-table__brand"
                  />
                </th>
              </tr>
              </thead>
              <tbody>
              <tr
                  v-for="team in scheduleInfo.teams.challenge.list"
                  :key="team.team_number_hot || team.name"
              >
                <td class="pe-teams-table__num">{{ team.team_number_hot || '-' }}</td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-people-fill" aria-hidden="true"/>
                    {{ team.name }}
                  </span>
                </td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-building-fill" aria-hidden="true"/>
                    {{ team.organization || '-' }}
                  </span>
                </td>
                <td>
                  <span class="pe-teams-table__cell">
                    <i class="bi bi-pin-map-fill" aria-hidden="true"/>
                    {{ team.location || '-' }}
                  </span>
                </td>
              </tr>
              </tbody>
            </table>
          </div>
          <div v-else class="pe-teams-count glass-chip liquid-surface-inner">
            <img
                :alt="programLogoAlt('C')"
                :src="imageUrl('/flow/fll_challenge_h.png')"
                class="pe-teams-count__logo"
            />
            <span>{{ scheduleInfo.teams.challenge.registered }} Team(s) angemeldet</span>
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
    grid-template-columns: 1fr 1fr;
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
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.pe-timeline__label {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: color-mix(in srgb, var(--pe-program) 75%, var(--color-text));
}

.pe-timeline__time {
  font-size: 1.05rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--color-text);
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

.pe-teams-block + .pe-teams-block {
  margin-top: 1.25rem;
}

.pe-teams-table-wrap {
  --pe-program: var(--color-accent);
  overflow-x: auto;
  border-radius: var(--radius-lg, 1rem);
  border: 1px solid color-mix(in srgb, var(--pe-program) 35%, var(--color-border-strong));
  background: color-mix(in srgb, #ffffff 90%, transparent);
  box-shadow: 0 8px 22px color-mix(in srgb, var(--pe-program) 12%, transparent);
}

.pe-teams-table {
  width: 100%;
  min-width: 36rem;
  table-layout: fixed;
  border-collapse: collapse;
}

.pe-teams-table thead th {
  padding: 0.75rem 1rem;
  text-align: right;
  border-bottom: 1px solid color-mix(in srgb, var(--pe-program) 22%, transparent);
  background: color-mix(in srgb, var(--pe-program) 7%, #ffffff);
}

.pe-teams-table__brand {
  height: 3rem;
  width: auto;
  object-fit: contain;
  margin-left: auto;
}

@media (min-width: 768px) {
  .pe-teams-table__brand {
    height: 4rem;
  }
}

.pe-teams-table tbody tr {
  border-top: 1px solid color-mix(in srgb, var(--pe-program) 14%, transparent);
}

.pe-teams-table tbody tr:first-child {
  border-top: none;
}

.pe-teams-table tbody tr:hover {
  background: color-mix(in srgb, var(--pe-program) 8%, transparent);
}

.pe-teams-table td {
  padding: 0.7rem 0.9rem;
  font-size: 0.9rem;
  color: var(--color-text);
  vertical-align: middle;
  word-break: break-word;
}

.pe-teams-table__num {
  font-weight: 700;
  text-align: center;
  color: var(--pe-program) !important;
  white-space: nowrap;
}

.pe-teams-table__cell {
  display: inline-flex;
  align-items: flex-start;
  gap: 0.45rem;
}

.pe-teams-table__cell i {
  color: var(--color-text-subtle);
  margin-top: 0.15rem;
  flex-shrink: 0;
}

.pe-teams-count {
  display: flex !important;
  align-items: center;
  gap: 0.75rem;
}

.pe-teams-count__logo {
  height: 2.25rem;
  width: auto;
  object-fit: contain;
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
