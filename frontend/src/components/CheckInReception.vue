<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import {RouterLink, useRoute} from 'vue-router'
import axios from 'axios'
import QRCode from 'qrcode'
import {imageUrl, programLogoSrc} from '@/utils/images'
import {publicPlanPath} from '@/utils/publicPlanPath'
import {photoConsentStatusClass} from '@/utils/photoConsentStatus'

defineOptions({name: 'CheckInReception'})

type Bootstrap = {
  event_id: number
  event_name: string
  slug: string
  enabled: boolean
  public_link: string | null
}

type SearchHit = {
  subject_type: 'team' | 'volunteer'
  subject_id: number
  label: string
  subtitle?: string | null
  program_id?: number | null
  program_name?: string | null
  logo_stem?: string | null
  status: 'checked_in' | 'no_show' | null
  checked_in_at?: string | null
}

type DisplayField = {
  key: string
  kind: 'photo_consent' | 'text' | string
  label: string
  value: string
  status?: 'pending' | 'granted' | 'denied'
}

type Detail = SearchHit & {
  room?: string | null
  info_text?: string | null
  role_labels?: string[]
  reception_note?: string | null
  no_show_reason?: string | null
  no_show_source?: string | null
  next_activities?: Array<{start: string | null; room?: string | null; title: string}>
  display_fields?: DisplayField[]
}

type OverviewLine = {
  kind: 'global' | 'cross' | 'program' | 'local' | 'spacer' | string
  program_id?: number | null
  program_name?: string
  logo_stem?: string | null
  checked_in: number
  total: number
}

type Overview = {
  teams: OverviewLine[]
  helpers: OverviewLine[]
  totals: {
    teams_checked_in: number
    teams_total: number
    helpers_checked_in: number
    helpers_total: number
  }
}

const route = useRoute()
const slug = computed(() => String(route.params.slug || ''))

const bootstrap = ref<Bootstrap | null>(null)
const bootstrapError = ref('')
const pin = ref('')
const pinError = ref('')
const token = ref('')
const unlocking = ref(false)

const view = ref<'home' | 'detail' | 'qr' | 'noshow'>('home')
const query = ref('')
const searching = ref(false)
const results = ref<SearchHit[]>([])
const detail = ref<Detail | null>(null)
const detailLoading = ref(false)
const note = ref('')
const actionBusy = ref(false)
const actionError = ref('')
const confirmRecheck = ref(false)

const noShowReason = ref('')
const noShowSource = ref('')

const overview = ref<Overview | null>(null)
const organizer = ref<{name: string; mobile: string | null} | null>(null)
const qrDataUrl = ref('')
const toolsError = ref('')

const storageKey = computed(() => `flow:check-in-token:${slug.value}`)

const planPath = computed(() => publicPlanPath(bootstrap.value?.public_link, bootstrap.value?.slug || slug.value))

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  if (token.value) {
    config.headers['X-Check-In-Token'] = token.value
  }
  return config
})

const unlocked = computed(() => !!token.value && !!bootstrap.value?.enabled)

async function loadBootstrap() {
  bootstrapError.value = ''
  try {
    const {data} = await api.get(`/check-in/${slug.value}/bootstrap`, {
      params: {_: Date.now()},
      headers: {'Cache-Control': 'no-cache', Pragma: 'no-cache'},
    })
    bootstrap.value = data
    if (!data.enabled) {
      token.value = ''
      sessionStorage.removeItem(storageKey.value)
    }
  } catch (e: any) {
    bootstrapError.value = e?.response?.data?.error || 'Event nicht gefunden.'
    bootstrap.value = null
  }
}

async function unlock() {
  pinError.value = ''
  unlocking.value = true
  try {
    const {data} = await api.post(`/check-in/${slug.value}/session`, {pin: pin.value})
    token.value = data.token
    sessionStorage.setItem(storageKey.value, data.token)
    pin.value = ''
    view.value = 'home'
    await loadOrganizer()
    await loadOverview()
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 423) {
      await loadBootstrap()
      pinError.value = 'Check-In ist nicht geöffnet.'
    } else {
      pinError.value = e?.response?.data?.error || 'PIN ungültig.'
    }
    token.value = ''
    sessionStorage.removeItem(storageKey.value)
  } finally {
    unlocking.value = false
  }
}

let searchTimer: ReturnType<typeof setTimeout> | null = null

function onQueryInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    void runSearch()
  }, 250)
}

async function runSearch() {
  const q = query.value.trim()
  if (q.length < 2) {
    results.value = []
    return
  }
  searching.value = true
  try {
    const {data} = await api.get(`/check-in/${slug.value}/search`, {params: {q}})
    results.value = data.results || []
  } catch (e: any) {
    if (e?.response?.status === 401 || e?.response?.status === 423) {
      token.value = ''
      sessionStorage.removeItem(storageKey.value)
      await loadBootstrap()
    }
    results.value = []
  } finally {
    searching.value = false
  }
}

async function openDetail(hit: SearchHit) {
  view.value = 'detail'
  detailLoading.value = true
  actionError.value = ''
  confirmRecheck.value = false
  try {
    const {data} = await api.get(`/check-in/${slug.value}/${hit.subject_type}/${hit.subject_id}`)
    detail.value = data
    note.value = data.reception_note || ''
  } catch (e: any) {
    actionError.value = e?.response?.data?.error || 'Laden fehlgeschlagen.'
    detail.value = null
  } finally {
    detailLoading.value = false
  }
}

async function doCheckIn() {
  if (!detail.value) return
  if (detail.value.status === 'no_show') {
    actionError.value = 'No-Show — bitte Organisator:in kontaktieren.'
    return
  }
  if (detail.value.status === 'checked_in' && !confirmRecheck.value) {
    confirmRecheck.value = true
    return
  }
  actionBusy.value = true
  actionError.value = ''
  try {
    const {data} = await api.post(
        `/check-in/${slug.value}/${detail.value.subject_type}/${detail.value.subject_id}/check-in`,
        {reception_note: note.value},
    )
    detail.value = data
    confirmRecheck.value = false
  } catch (e: any) {
    actionError.value = e?.response?.data?.error || 'Check-In fehlgeschlagen.'
  } finally {
    actionBusy.value = false
  }
}

function openNoShowForm() {
  noShowReason.value = detail.value?.no_show_reason || ''
  noShowSource.value = detail.value?.no_show_source || ''
  view.value = 'noshow'
}

async function submitNoShow() {
  if (!detail.value) return
  if (!noShowReason.value.trim() || !noShowSource.value.trim()) {
    actionError.value = 'Grund und Quelle sind Pflicht.'
    return
  }
  actionBusy.value = true
  actionError.value = ''
  try {
    const {data} = await api.post(
        `/check-in/${slug.value}/${detail.value.subject_type}/${detail.value.subject_id}/no-show`,
        {
          no_show_reason: noShowReason.value.trim(),
          no_show_source: noShowSource.value.trim(),
        },
    )
    detail.value = data
    view.value = 'detail'
  } catch (e: any) {
    actionError.value = e?.response?.data?.error || 'No-Show speichern fehlgeschlagen.'
  } finally {
    actionBusy.value = false
  }
}

async function loadOverview() {
  try {
    const {data} = await api.get(`/check-in/${slug.value}/overview`)
    overview.value = data
  } catch {
    overview.value = null
  }
}

async function openQr() {
  view.value = 'qr'
  qrDataUrl.value = ''
  const link = bootstrap.value?.public_link
  if (!link) {
    toolsError.value = 'Kein öffentlicher Plan-Link.'
    return
  }
  toolsError.value = ''
  try {
    qrDataUrl.value = await QRCode.toDataURL(link, {width: 280, margin: 1})
  } catch {
    toolsError.value = 'QR-Code konnte nicht erzeugt werden.'
  }
}

async function loadOrganizer() {
  try {
    const {data} = await api.get(`/check-in/${slug.value}/organizer`)
    organizer.value = data.organizer
  } catch {
    organizer.value = null
  }
}

async function shareStatus() {
  toolsError.value = ''
  try {
    const {data} = await api.get(`/check-in/${slug.value}/share`)
    const text = data.text || ''
    if (navigator.share) {
      await navigator.share({title: 'Check-In Status', text})
    } else if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      toolsError.value = 'Status in Zwischenablage kopiert.'
    } else {
      toolsError.value = text
    }
  } catch {
    toolsError.value = 'Teilen fehlgeschlagen.'
  }
}

function backHome() {
  view.value = 'home'
  detail.value = null
  confirmRecheck.value = false
  actionError.value = ''
  void runSearch()
  void loadOverview()
}

function statusLabel(hit: {status: string | null; checked_in_at?: string | null}) {
  if (hit.status === 'no_show') return 'No-Show'
  if (hit.status === 'checked_in') {
    const time = checkInTime(hit)
    return time ? `Da · ${time}` : 'Da'
  }
  return 'Offen'
}

function statusIcon(status: string | null) {
  if (status === 'no_show') return 'bi-x-circle-fill'
  if (status === 'checked_in') return 'bi-check-circle-fill'
  return 'bi-circle'
}

/** Display check-in time as hh:mm when present. */
function checkInTime(hit: {checked_in_at?: string | null}): string | null {
  const raw = hit.checked_in_at?.trim()
  if (!raw) return null
  const match = raw.match(/(\d{1,2}):(\d{2})/)
  if (!match) return null
  return `${match[1].padStart(2, '0')}:${match[2]}`
}

function roleLabel(hit: {subject_type?: string; subtitle?: string | null; role_labels?: string[]}) {
  if (hit.subtitle) return hit.subtitle
  if (hit.subject_type === 'team') return 'Team'
  if (hit.role_labels?.length) return hit.role_labels.join(', ')
  return ''
}

function statsLogo(line: OverviewLine) {
  if (line.kind === 'program' && line.logo_stem) return programLogoSrc({logo_stem: line.logo_stem})
  return ''
}

/** Match Helfer:innen → Zuordnung scope icons (Übergreifend / Zusätzlich). */
function statsIcon(line: OverviewLine) {
  if (line.kind === 'cross') return 'bi-intersect'
  if (line.kind === 'local') return 'bi-star'
  return ''
}

/** Keep program rows aligned: empty Teams slot where Helfer has Übergreifend. */
function teamStatLines(lines: OverviewLine[] | undefined, helperLines: OverviewLine[] | undefined): OverviewLine[] {
  const teams = [...(lines || [])]
  const helpersHaveCross = (helperLines || []).some((line) => line.kind === 'cross')
  const teamsHaveCross = teams.some((line) => line.kind === 'cross')
  if (!helpersHaveCross || teamsHaveCross) return teams

  const insertAt = teams.findIndex((line) => line.kind === 'global')
  teams.splice(insertAt >= 0 ? insertAt + 1 : 0, 0, {
    kind: 'spacer',
    checked_in: 0,
    total: 0,
  })
  return teams
}

const showSearchResults = computed(() => query.value.trim().length >= 2 && results.value.length > 0)
const homeTeamStats = computed(() => teamStatLines(overview.value?.teams, overview.value?.helpers))
const homeHelperStats = computed(() => overview.value?.helpers || [])

watch(slug, async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  await loadBootstrap()
  if (unlocked.value) {
    await loadOrganizer()
    await loadOverview()
  }
})

onMounted(async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  await loadBootstrap()
  if (unlocked.value) {
    await loadOrganizer()
    await loadOverview()
  }
})
</script>

<template>
  <div class="ci-app">
    <header class="ci-app__header liquid-surface-inner">
      <RouterLink
          v-if="planPath"
          :to="planPath"
          class="ci-app__brand ci-app__brand-link"
          aria-label="Zum öffentlichen Plan"
      >
        <img
            class="ci-app__logo"
            :src="imageUrl('/flow/flow.png')"
            alt="FLOW"
        />
      </RouterLink>
      <div v-else class="ci-app__brand">
        <img
            class="ci-app__logo"
            :src="imageUrl('/flow/flow.png')"
            alt="FLOW"
        />
      </div>
      <div v-if="unlocked" class="ci-app__tools">
        <button type="button" class="ci-tool" title="QR öffentlicher Plan" @click="openQr">
          <i class="bi bi-qr-code" aria-hidden="true"/>
        </button>
        <a
            v-if="organizer?.mobile"
            class="ci-tool"
            :href="`tel:${organizer.mobile}`"
            :title="`Organisator:in anrufen (${organizer.name})`"
        >
          <i class="bi bi-telephone" aria-hidden="true"/>
        </a>
        <button
            v-else
            type="button"
            class="ci-tool ci-tool--disabled"
            aria-disabled="true"
            :title="organizer
              ? 'Keine Handynummer für Organisator:in'
              : 'Keine Organisator:in zugewiesen'"
            @click.prevent
        >
          <i class="bi bi-telephone" aria-hidden="true"/>
        </button>
        <button type="button" class="ci-tool" title="Status teilen" @click="shareStatus">
          <i class="bi bi-share" aria-hidden="true"/>
        </button>
      </div>
    </header>

    <main class="ci-app__main">
      <p v-if="bootstrapError" class="glass-alert-error !mb-0">{{ bootstrapError }}</p>

      <template v-else-if="bootstrap && !bootstrap.enabled">
        <div class="ci-panel glass-card liquid-surface-inner">
          <h1 class="ci-panel__h">Check-In geschlossen</h1>
          <p class="ci-muted">Der Empfang ist derzeit nicht geöffnet.</p>
        </div>
      </template>

      <template v-else-if="bootstrap && !unlocked">
        <div class="ci-panel glass-card liquid-surface-inner">
          <h1 class="ci-panel__h">PIN eingeben</h1>
          <input
              v-model="pin"
              type="text"
              inputmode="numeric"
              maxlength="6"
              autocomplete="one-time-code"
              class="glass-input ci-pin"
              @keydown.enter.prevent="unlock"
          />
          <p v-if="pinError" class="glass-alert-error !mb-0">{{ pinError }}</p>
          <button
              type="button"
              class="glass-btn-accent ci-btn-block"
              :disabled="unlocking || pin.length !== 6"
              @click="unlock"
          >
            Entsperren
          </button>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'home'">
        <div class="ci-panel">
          <div class="ci-home-brand">
            <div class="ci-home-brand__title">Check-In</div>
            <div class="ci-home-brand__event">{{ bootstrap?.event_name || slug }}</div>
          </div>
          <input
              id="ci-search"
              v-model="query"
              type="search"
              class="glass-input ci-input"
              placeholder="Suche nach Name, Team, E-Mail…"
              autocomplete="off"
              aria-label="Suche"
              @input="onQueryInput"
          />
          <p v-if="query.trim().length > 0 && query.trim().length < 2" class="ci-muted">Mindestens 2 Zeichen.</p>
          <p v-else-if="searching" class="ci-muted">Suche…</p>
          <p v-else-if="query.trim().length >= 2 && !results.length" class="ci-muted">Keine Treffer.</p>
          <ul v-if="showSearchResults" class="ci-list">
            <li v-for="hit in results" :key="`${hit.subject_type}-${hit.subject_id}`">
              <button type="button" class="ci-hit liquid-surface-inner" @click="openDetail(hit)">
                <span class="ci-hit__row">
                  <span class="ci-hit__label">{{ hit.label }}</span>
                  <span class="ci-hit__trailing">
                    <span v-if="checkInTime(hit)" class="ci-hit__time">{{ checkInTime(hit) }}</span>
                    <span
                        class="ci-hit__status"
                        :class="{
                          'ci-hit__status--in': hit.status === 'checked_in',
                          'ci-hit__status--no': hit.status === 'no_show',
                        }"
                        :title="statusLabel(hit)"
                    >
                      <i
                          class="bi"
                          :class="statusIcon(hit.status)"
                          aria-hidden="true"
                      />
                      <span class="sr-only">{{ statusLabel(hit) }}</span>
                    </span>
                  </span>
                </span>
                <span v-if="hit.logo_stem || hit.subtitle" class="ci-hit__row ci-hit__row--sub">
                  <img
                      v-if="hit.logo_stem"
                      class="ci-hit__program"
                      :src="programLogoSrc({logo_stem: hit.logo_stem})"
                      alt=""
                      aria-hidden="true"
                  />
                  <span v-if="hit.subtitle" class="ci-hit__sub">{{ hit.subtitle }}</span>
                </span>
              </button>
            </li>
          </ul>

          <div class="ci-stats" aria-label="Check-In Stand">
            <section class="ci-stats__box glass-card liquid-surface-inner">
              <h2 class="ci-stats__heading">Teams</h2>
              <ul class="ci-stats__lines">
                <li
                    v-for="(line, i) in homeTeamStats"
                    :key="`t-${i}`"
                    class="ci-stats__line"
                    :class="{'ci-stats__line--spacer': line.kind === 'spacer'}"
                >
                  <template v-if="line.kind === 'spacer'">
                    <span class="ci-stats__spacer" aria-hidden="true"/>
                  </template>
                  <template v-else-if="line.kind === 'global'">
                    <span class="ci-stats__label">Gesamt</span>
                    <span class="ci-stats__count">{{ line.checked_in }} von {{ line.total }}</span>
                  </template>
                  <template v-else>
                    <img
                        v-if="statsLogo(line)"
                        class="ci-stats__logo"
                        :src="statsLogo(line)"
                        alt=""
                        aria-hidden="true"
                    />
                    <i
                        v-else-if="statsIcon(line)"
                        class="bi ci-stats__icon"
                        :class="statsIcon(line)"
                        aria-hidden="true"
                    />
                    <span class="ci-stats__count">{{ line.checked_in }} von {{ line.total }}</span>
                  </template>
                </li>
              </ul>
            </section>
            <section class="ci-stats__box glass-card liquid-surface-inner">
              <h2 class="ci-stats__heading">Helfer:innen</h2>
              <ul class="ci-stats__lines">
                <li v-for="(line, i) in homeHelperStats" :key="`h-${i}`" class="ci-stats__line">
                  <template v-if="line.kind === 'global'">
                    <span class="ci-stats__label">Gesamt</span>
                    <span class="ci-stats__count">{{ line.checked_in }} von {{ line.total }}</span>
                  </template>
                  <template v-else>
                    <img
                        v-if="statsLogo(line)"
                        class="ci-stats__logo"
                        :src="statsLogo(line)"
                        alt=""
                        aria-hidden="true"
                    />
                    <i
                        v-else-if="statsIcon(line)"
                        class="bi ci-stats__icon"
                        :class="statsIcon(line)"
                        aria-hidden="true"
                    />
                    <span class="ci-stats__count">{{ line.checked_in }} von {{ line.total }}</span>
                  </template>
                </li>
              </ul>
            </section>
          </div>

          <p v-if="toolsError" class="ci-muted">{{ toolsError }}</p>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'detail'">
        <div class="ci-panel">
          <button type="button" class="ci-link" @click="backHome">← Zurück</button>
          <div v-if="detailLoading" class="ci-muted">Laden…</div>
          <template v-else-if="detail">
            <div class="ci-hit ci-hit--detail liquid-surface-inner" aria-live="polite">
              <div class="ci-hit__row">
                <span class="ci-hit__label">{{ detail.label }}</span>
                <span class="ci-hit__trailing">
                  <span v-if="checkInTime(detail)" class="ci-hit__time">{{ checkInTime(detail) }}</span>
                  <span
                      class="ci-hit__status"
                      :class="{
                        'ci-hit__status--in': detail.status === 'checked_in',
                        'ci-hit__status--no': detail.status === 'no_show',
                      }"
                      :title="statusLabel(detail)"
                  >
                    <i
                        class="bi"
                        :class="statusIcon(detail.status)"
                        aria-hidden="true"
                    />
                    <span class="sr-only">{{ statusLabel(detail) }}</span>
                  </span>
                </span>
              </div>
              <div v-if="detail.logo_stem || roleLabel(detail)" class="ci-hit__row ci-hit__row--sub">
                <img
                    v-if="detail.logo_stem"
                    class="ci-hit__program"
                    :src="programLogoSrc({logo_stem: detail.logo_stem})"
                    alt=""
                    aria-hidden="true"
                />
                <span v-if="roleLabel(detail)" class="ci-hit__sub">{{ roleLabel(detail) }}</span>
              </div>
              <div
                  v-if="detail.display_fields?.length"
                  class="ci-display-fields"
              >
                <template v-for="field in detail.display_fields" :key="field.key">
                  <div
                      v-if="field.kind === 'photo_consent'"
                      class="ci-photo-consent"
                      :class="photoConsentStatusClass(field.status ?? 'pending')"
                      role="status"
                  >
                    <i class="bi bi-camera ci-photo-consent__icon" aria-hidden="true"/>
                    <span>{{ field.value }}</span>
                  </div>
                  <div v-else class="ci-display-field">
                    <span class="ci-display-field__label">{{ field.label }}</span>
                    <span class="ci-display-field__value">{{ field.value }}</span>
                  </div>
                </template>
              </div>
            </div>

            <div v-if="detail.room" class="ci-card glass-card liquid-surface-inner">
              <div class="ci-card__label">Raum</div>
              <div class="ci-card__value">{{ detail.room }}</div>
            </div>

            <div v-if="detail.next_activities?.length" class="ci-card glass-card liquid-surface-inner">
              <div class="ci-card__label">Nächste Aktivitäten</div>
              <ul class="ci-acts">
                <li v-for="(act, idx) in detail.next_activities" :key="idx" class="ci-acts__item">
                  <span class="ci-acts__time">{{ act.start || '—' }}</span>
                  <span class="ci-acts__body">
                    <span class="ci-acts__room">{{ act.room || 'Raum offen' }}</span>
                    <span class="ci-acts__title">{{ act.title }}</span>
                  </span>
                </li>
              </ul>
            </div>

            <div v-if="detail.info_text" class="ci-card glass-card liquid-surface-inner">
              <div class="ci-card__label">Hinweis</div>
              <div class="ci-card__value ci-card__value--pre">{{ detail.info_text }}</div>
            </div>

            <label class="ci-label" for="ci-note">Notiz</label>
            <textarea id="ci-note" v-model="note" rows="3" class="glass-input ci-input" :disabled="actionBusy"/>

            <p v-if="actionError" class="glass-alert-error !mb-0">{{ actionError }}</p>
            <p v-if="confirmRecheck" class="glass-alert-warning !mb-0">Bereits eingecheckt — erneut bestätigen?</p>

            <div class="ci-actions">
              <button
                  type="button"
                  class="glass-btn-accent"
                  :disabled="actionBusy || detail.status === 'no_show'"
                  @click="doCheckIn"
              >
                {{ detail.status === 'checked_in' ? (confirmRecheck ? 'Erneut check-in' : 'Check-In') : 'Check-In' }}
              </button>
              <button
                  type="button"
                  class="ci-btn-danger"
                  :disabled="actionBusy || detail.status === 'no_show'"
                  @click="openNoShowForm"
              >
                No-Show
              </button>
            </div>
            <p v-if="detail.status === 'no_show'" class="ci-muted">
              No-Show erfasst. Check-In nur über Organisator:in möglich.
            </p>
          </template>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'noshow'">
        <div class="ci-panel">
          <button type="button" class="ci-link" @click="view = 'detail'">← Zurück</button>
          <h1 class="ci-panel__h">No-Show</h1>
          <p class="ci-muted">{{ detail?.label }}</p>
          <label class="ci-label" for="ci-reason">Grund</label>
          <textarea id="ci-reason" v-model="noShowReason" rows="2" class="glass-input ci-input"/>
          <label class="ci-label" for="ci-source">Wie wurde die Info übermittelt?</label>
          <textarea id="ci-source" v-model="noShowSource" rows="2" class="glass-input ci-input"/>
          <p v-if="actionError" class="glass-alert-error !mb-0">{{ actionError }}</p>
          <button type="button" class="ci-btn-danger ci-btn-block" :disabled="actionBusy" @click="submitNoShow">
            No-Show speichern
          </button>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'qr'">
        <div class="ci-panel ci-panel--center glass-card liquid-surface-inner">
          <button type="button" class="ci-link" @click="view = 'home'">← Zurück</button>
          <h1 class="ci-panel__h">Öffentlicher Plan</h1>
          <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR-Code öffentlicher Plan" class="ci-qr"/>
          <p v-if="toolsError" class="glass-alert-error !mb-0">{{ toolsError }}</p>
          <p v-if="bootstrap?.public_link" class="ci-muted ci-break">{{ bootstrap.public_link }}</p>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
.ci-app {
  min-height: 100dvh;
  color: var(--color-text);
  display: flex;
  flex-direction: column;
}

.ci-app__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  padding-top: max(0.85rem, env(safe-area-inset-top));
  border-bottom: 1px solid var(--liquid-border-soft);
  position: sticky;
  top: 0;
  z-index: 2;
  border-radius: 0;
}

.ci-app__brand {
  display: flex;
  align-items: center;
  min-width: 0;
}

.ci-app__brand-link {
  text-decoration: none;
  color: inherit;
  border-radius: 0.35rem;
}

.ci-app__brand-link:active {
  opacity: 0.85;
}

.ci-app__logo {
  display: block;
  height: 1.75rem;
  width: auto;
}

.ci-app__tools {
  display: flex;
  gap: 0.25rem;
}

.ci-tool {
  width: 2.5rem;
  height: 2.5rem;
  border-radius: var(--radius);
  border: 1px solid var(--liquid-border);
  background: var(--liquid-tile-bg);
  color: var(--color-text);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  cursor: pointer;
  box-shadow:
    0 4px 12px rgba(15, 23, 42, 0.06),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.ci-tool:hover:not(.ci-tool--disabled) {
  background: var(--color-bg-hover);
}

.ci-tool--disabled,
.ci-tool:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  color: var(--color-text-muted);
  box-shadow: none;
}

.ci-app__main {
  flex: 1;
  padding: 1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.ci-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 40rem;
  margin: 0 auto;
}

.ci-home-brand {
  text-align: center;
  margin: 0.15rem 0 0.35rem;
}

.ci-home-brand__title {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-text-muted);
}

.ci-home-brand__event {
  font-weight: 750;
  font-size: 1.2rem;
  line-height: 1.25;
  color: var(--color-text);
}

.ci-panel--center {
  align-items: stretch;
  text-align: center;
  padding: 1rem;
}

.ci-panel__h {
  font-size: 1.35rem;
  font-weight: 750;
  margin: 0;
  color: var(--color-text);
}

.ci-muted {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin: 0;
}

.ci-label {
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.ci-input,
.ci-pin {
  width: 100%;
}

.ci-pin {
  text-align: center;
  letter-spacing: 0.35em;
  font-size: 1.6rem;
  font-variant-numeric: tabular-nums;
}

.ci-btn-block {
  width: 100%;
}

.ci-btn-danger {
  padding: 0.5rem 1rem;
  font-size: var(--text-sm);
  font-weight: 600;
  border-radius: var(--radius);
  border: 1px solid color-mix(in srgb, #dc2626 55%, var(--color-border-strong));
  background: color-mix(in srgb, #dc2626 12%, var(--color-bg-muted));
  color: color-mix(in srgb, #dc2626 75%, var(--color-text));
  cursor: pointer;
}

.ci-btn-danger:hover:not(:disabled) {
  background: color-mix(in srgb, #dc2626 18%, var(--color-bg-muted));
}

.ci-btn-danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.ci-actions {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ci-actions .glass-btn-accent,
.ci-actions .ci-btn-danger {
  width: 100%;
  padding: 0.85rem 1rem;
  font-size: 1rem;
}

.ci-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ci-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
  margin-top: 0.35rem;
}

.ci-stats__box {
  padding: 0.75rem;
  min-width: 0;
}

.ci-stats__heading {
  margin: 0 0 0.65rem;
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.ci-stats__lines {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.ci-stats__line {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  min-width: 0;
  min-height: 1.5rem;
}

.ci-stats__line--spacer {
  visibility: hidden;
}

.ci-stats__spacer {
  display: block;
  width: 100%;
  height: 1.5rem;
}

.ci-stats__label {
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-text);
  min-width: 4.25rem;
}

.ci-stats__logo,
.ci-stats__icon {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
}

.ci-stats__logo {
  object-fit: contain;
}

.ci-stats__icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
  color: var(--color-text-muted);
}

.ci-stats__count {
  font-size: 1.05rem;
  font-variant-numeric: tabular-nums;
  font-weight: 650;
  white-space: nowrap;
  color: var(--color-text);
}

.ci-hit {
  width: 100%;
  text-align: left;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  padding: 0.85rem;
  border-radius: var(--radius-lg);
  border: 1px solid var(--liquid-border-soft);
  color: var(--color-text);
  cursor: pointer;
}

.ci-hit:hover {
  background: var(--color-bg-hover);
}

.ci-hit--detail {
  cursor: default;
}

.ci-hit--detail:hover {
  background: inherit;
}

.ci-hit__row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-width: 0;
}

.ci-hit__row--sub {
  justify-content: flex-start;
  gap: 0.45rem;
}

.ci-photo-consent {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin-top: 0.45rem;
  padding: 0.4rem 0.55rem;
  border-radius: var(--radius, 0.5rem);
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.3;
}

.ci-photo-consent__icon {
  flex-shrink: 0;
  margin-top: 0.05rem;
  font-size: 1rem;
  line-height: 1;
}

.ci-display-fields {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-top: 0.45rem;
}

.ci-display-fields .ci-photo-consent {
  margin-top: 0;
}

.ci-display-field {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: 0.15rem 0;
}

.ci-display-field__label {
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.ci-display-field__value {
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--color-text);
  line-height: 1.3;
}

.ci-hit__label {
  font-weight: 700;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ci-hit__trailing {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  flex-shrink: 0;
}

.ci-hit__time {
  font-size: 0.85rem;
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
}

.ci-hit__program {
  width: 1.15rem;
  height: 1.15rem;
  object-fit: contain;
  flex-shrink: 0;
}

.ci-hit__sub {
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.ci-hit__status {
  font-size: 1.25rem;
  line-height: 1;
  flex-shrink: 0;
  color: var(--color-text-muted);
}

.ci-hit__status--in {
  color: #059669;
}

.ci-hit__status--no {
  color: #dc2626;
}

.ci-card {
  padding: 0.85rem;
  text-align: left;
}

.ci-card__label {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-bottom: 0.25rem;
}

.ci-card__value {
  font-weight: 700;
  font-size: 1.05rem;
  color: var(--color-text);
}

.ci-card__value--pre {
  white-space: pre-wrap;
  font-weight: 500;
  font-size: 0.95rem;
}

.ci-acts {
  list-style: none;
  margin: 0.35rem 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.ci-acts__item {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.ci-acts__time {
  flex-shrink: 0;
  min-width: 3rem;
  color: var(--color-text-muted);
  font-variant-numeric: tabular-nums;
  font-size: 0.95rem;
  padding-top: 0.1rem;
}

.ci-acts__body {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.ci-acts__room {
  font-weight: 750;
  font-size: 1.15rem;
  line-height: 1.2;
  color: var(--color-text);
}

.ci-acts__title {
  font-size: 0.9rem;
  color: var(--color-text-muted);
}

.ci-link {
  border: 0;
  background: transparent;
  color: var(--color-accent);
  text-align: left;
  padding: 0;
  width: fit-content;
  cursor: pointer;
  font: inherit;
}

.ci-link:hover {
  text-decoration: underline;
}

.ci-qr {
  width: min(280px, 80vw);
  height: auto;
  margin: 0 auto;
  border-radius: var(--radius);
  background: #fff;
  padding: 0.5rem;
  border: 1px solid var(--liquid-border-soft);
}

.ci-break {
  word-break: break-all;
}
</style>
