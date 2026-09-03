<script setup lang="ts">
import {computed, nextTick, onMounted, ref, watch} from 'vue'
import {RouterLink, useRoute} from 'vue-router'
import axios from 'axios'
import QRCode from 'qrcode'
import {imageUrl} from '@/utils/images'
import {publicPlanPath} from '@/utils/publicPlanPath'
import CockpitToolShell from '@/components/molecules/CockpitToolShell.vue'
import CockpitPhonebookPanel from '@/components/molecules/CockpitPhonebookPanel.vue'
import CockpitTimeShiftPanel from '@/components/molecules/CockpitTimeShiftPanel.vue'
import CockpitStagePresentationPanel from '@/components/molecules/CockpitStagePresentationPanel.vue'
import RobotGameRoundsPanel from '@/components/molecules/RobotGameRoundsPanel.vue'

defineOptions({name: 'CockpitApp'})

type Bootstrap = {
  event_id: number
  event_name: string
  slug: string
  enabled: boolean
  public_link: string | null
}

type CockpitToolId =
  | 'overview'
  | 'phonebook'
  | 'slideshow'
  | 'robot-rounds'
  | 'timeshift'
  | 'stage-research'

type CockpitTool = {
  id: CockpitToolId
  title: string
  homeLabel: string
  explanation: string
  icon: string
  ready: boolean
}

const route = useRoute()
const slug = computed(() => String(route.params.slug || ''))

const bootstrap = ref<Bootstrap | null>(null)
const bootstrapError = ref('')
const pin = ref('')
const pinError = ref('')
const token = ref('')
const unlocking = ref(false)
const view = ref<'home' | 'qr' | CockpitToolId>('home')
const homeScrollY = ref(0)
const mainEl = ref<HTMLElement | null>(null)
const organizer = ref<{name: string; mobile: string | null} | null>(null)
const qrDataUrl = ref('')
const toolsError = ref('')

const storageKey = computed(() => `flow:cockpit-token:${slug.value}`)

const planPath = computed(() => publicPlanPath(bootstrap.value?.public_link, bootstrap.value?.slug || slug.value))

const showAppHeader = computed(() => view.value === 'home' || view.value === 'qr')

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
})

api.interceptors.request.use((config) => {
  if (token.value) {
    config.headers['X-Cockpit-Token'] = token.value
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status
    if (status === 401 || status === 423) {
      token.value = ''
      sessionStorage.removeItem(storageKey.value)
      if (view.value !== 'home' && view.value !== 'qr') {
        view.value = 'home'
      }
    }
    return Promise.reject(error)
  },
)

const unlocked = computed(() => !!token.value && !!bootstrap.value?.enabled)

const roundsApiPath = computed(() =>
    slug.value ? `/cockpit/${slug.value}/rounds` : null,
)

const tools: CockpitTool[] = [
  {
    id: 'overview',
    title: 'Überblick über Teams und Helfer:innen',
    homeLabel: 'Überblick',
    explanation: 'Wer fehlt oder kommt gar nicht? Wer ist in welcher Jury-Gruppe?',
    icon: 'bi-people',
    ready: false,
  },
  {
    id: 'phonebook',
    title: 'Telefonbuch',
    homeLabel: 'Telefonbuch',
    explanation: '',
    icon: 'bi-telephone',
    ready: true,
  },
  {
    id: 'slideshow',
    title: 'Slide-Show Auswahl',
    homeLabel: 'Slide-Show',
    explanation: 'Wähle, welche Slide-Show im Karussell läuft.',
    icon: 'bi-images',
    ready: false,
  },
  {
    id: 'robot-rounds',
    title: 'Robot-Game Ergebnisse',
    homeLabel: 'Robot-Game',
    explanation: 'Wähle aus, welche Runden öffentlich sichtbar sein sollen.',
    icon: 'bi-trophy',
    ready: true,
  },
  {
    id: 'timeshift',
    title: 'Zeiten im Plan verschieben',
    homeLabel: 'Zeiten verschieben',
    explanation: 'Verschiebe den Rest des Tages, ohne den Zeitplan neu zu generieren.',
    icon: 'bi-clock-history',
    ready: true,
  },
  {
    id: 'stage-research',
    title: 'Forschung auf der Bühne',
    homeLabel: 'Forschung',
    explanation: 'Jury trägt ein, wer kommt — Moderator und Stage Crew sehen es.',
    icon: 'bi-easel',
    ready: true,
  },
]

const activeTool = computed(() =>
  view.value === 'home' || view.value === 'qr'
      ? null
      : tools.find((tool) => tool.id === view.value) ?? null,
)

function openTool(toolId: CockpitToolId) {
  homeScrollY.value = mainEl.value?.scrollTop ?? window.scrollY
  view.value = toolId
  void nextTick(() => {
    if (mainEl.value) mainEl.value.scrollTop = 0
    else window.scrollTo({top: 0})
  })
}

function backHome() {
  view.value = 'home'
  toolsError.value = ''
  void nextTick(() => {
    if (mainEl.value) mainEl.value.scrollTop = homeScrollY.value
    else window.scrollTo({top: homeScrollY.value})
  })
}

async function openQr() {
  homeScrollY.value = mainEl.value?.scrollTop ?? window.scrollY
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
    const {data} = await api.get(`/cockpit/${slug.value}/organizer`)
    organizer.value = data.organizer
  } catch {
    organizer.value = null
  }
}

async function loadBootstrap() {
  bootstrapError.value = ''
  try {
    const {data} = await api.get(`/cockpit/${slug.value}/bootstrap`, {
      params: {_: Date.now()},
      headers: {'Cache-Control': 'no-cache', Pragma: 'no-cache'},
    })
    bootstrap.value = data
    if (!data.enabled) {
      token.value = ''
      sessionStorage.removeItem(storageKey.value)
      view.value = 'home'
    }
  } catch (e: any) {
    bootstrapError.value = e?.response?.data?.error || 'Event nicht gefunden.'
    bootstrap.value = null
    view.value = 'home'
  }
}

async function unlock() {
  pinError.value = ''
  unlocking.value = true
  try {
    const {data} = await api.post(`/cockpit/${slug.value}/session`, {pin: pin.value})
    token.value = data.token
    sessionStorage.setItem(storageKey.value, data.token)
    pin.value = ''
    view.value = 'home'
    await loadOrganizer()
  } catch (e: any) {
    const status = e?.response?.status
    if (status === 423) {
      await loadBootstrap()
      pinError.value = 'Cockpit ist nicht geöffnet.'
    } else {
      pinError.value = e?.response?.data?.error || 'PIN ungültig.'
    }
    token.value = ''
    sessionStorage.removeItem(storageKey.value)
  } finally {
    unlocking.value = false
  }
}

watch(token, (next) => {
  if (next) {
    sessionStorage.setItem(storageKey.value, next)
  } else {
    sessionStorage.removeItem(storageKey.value)
  }
})

watch(slug, async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  view.value = 'home'
  organizer.value = null
  await loadBootstrap()
  if (token.value && bootstrap.value?.enabled) {
    await loadOrganizer()
  }
})

onMounted(async () => {
  token.value = sessionStorage.getItem(storageKey.value) || ''
  await loadBootstrap()
  if (token.value && bootstrap.value?.enabled) {
    await loadOrganizer()
  }
})
</script>

<template>
  <div class="cp-app">
    <header v-if="showAppHeader" class="cp-app__header liquid-surface-inner">
      <RouterLink
          v-if="planPath"
          :to="planPath"
          class="cp-app__brand cp-app__brand-link"
          aria-label="Zum öffentlichen Plan"
      >
        <img
            class="cp-app__logo"
            :src="imageUrl('/flow/flow.png')"
            alt="FLOW"
        />
      </RouterLink>
      <div v-else class="cp-app__brand">
        <img
            class="cp-app__logo"
            :src="imageUrl('/flow/flow.png')"
            alt="FLOW"
        />
      </div>
      <div v-if="unlocked" class="cp-app__tools">
        <button type="button" class="cp-tool" title="QR öffentlicher Plan" @click="openQr">
          <i class="bi bi-qr-code" aria-hidden="true"/>
        </button>
        <a
            v-if="organizer?.mobile"
            class="cp-tool"
            :href="`tel:${organizer.mobile}`"
            :title="`Organisator:in anrufen (${organizer.name})`"
        >
          <i class="bi bi-telephone" aria-hidden="true"/>
        </a>
        <button
            v-else
            type="button"
            class="cp-tool cp-tool--disabled"
            aria-disabled="true"
            :title="organizer
              ? 'Keine Handynummer für Organisator:in'
              : 'Keine Organisator:in zugewiesen'"
            @click.prevent
        >
          <i class="bi bi-telephone" aria-hidden="true"/>
        </button>
      </div>
    </header>

    <main
        ref="mainEl"
        class="cp-app__main"
        :class="{'cp-app__main--tool': !!activeTool}"
    >
      <p v-if="bootstrapError" class="glass-alert-error !mb-0">{{ bootstrapError }}</p>

      <template v-else-if="bootstrap && !bootstrap.enabled">
        <div class="cp-panel glass-card liquid-surface-inner">
          <h1 class="cp-panel__h">Cockpit geschlossen</h1>
          <p class="cp-muted">Das Cockpit ist derzeit nicht geöffnet.</p>
        </div>
      </template>

      <template v-else-if="bootstrap && !unlocked">
        <div class="cp-panel glass-card liquid-surface-inner">
          <h1 class="cp-panel__h">PIN eingeben</h1>
          <input
              v-model="pin"
              type="text"
              inputmode="numeric"
              maxlength="6"
              autocomplete="one-time-code"
              class="glass-input cp-pin"
              @keydown.enter.prevent="unlock"
          />
          <p v-if="pinError" class="glass-alert-error !mb-0">{{ pinError }}</p>
          <button
              type="button"
              class="glass-btn-accent cp-btn-block"
              :disabled="unlocking || pin.length !== 6"
              @click="unlock"
          >
            Entsperren
          </button>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'home'">
        <div class="cp-panel cp-panel--wide">
          <div class="cp-home-brand">
            <div class="cp-home-brand__title">Cockpit</div>
            <div class="cp-home-brand__event">{{ bootstrap?.event_name || slug }}</div>
          </div>

          <div class="cp-grid" role="list">
            <button
                v-for="tool in tools"
                :key="tool.id"
                type="button"
                class="cp-grid__cell glass-card liquid-surface-inner"
                role="listitem"
                @click="openTool(tool.id)"
            >
              <i class="bi cp-grid__icon" :class="tool.icon" aria-hidden="true"/>
              <span class="cp-grid__title">{{ tool.homeLabel }}</span>
              <span v-if="!tool.ready" class="cp-grid__badge">Bald</span>
            </button>
          </div>
        </div>
      </template>

      <template v-else-if="unlocked && view === 'qr'">
        <div class="cp-panel cp-panel--center glass-card liquid-surface-inner">
          <button type="button" class="cp-link" @click="backHome">← Zurück</button>
          <h1 class="cp-panel__h">Öffentlicher Plan</h1>
          <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR-Code öffentlicher Plan" class="cp-qr"/>
          <p v-if="toolsError" class="glass-alert-error !mb-0">{{ toolsError }}</p>
          <p v-if="bootstrap?.public_link" class="cp-muted cp-break">{{ bootstrap.public_link }}</p>
        </div>
      </template>

      <template v-else-if="unlocked && activeTool">
        <CockpitToolShell
            :title="activeTool.title"
            :explanation="activeTool.explanation"
            @back="backHome"
        >
          <RobotGameRoundsPanel
              v-if="activeTool.id === 'robot-rounds'"
              embedded
              :event-id="bootstrap?.event_id ?? null"
              :rounds-api-path="roundsApiPath"
              :http="api"
          />
          <CockpitPhonebookPanel
              v-else-if="activeTool.id === 'phonebook'"
              :slug="slug"
              :http="api"
          />
          <CockpitTimeShiftPanel
              v-else-if="activeTool.id === 'timeshift'"
              :slug="slug"
              :http="api"
          />
          <CockpitStagePresentationPanel
              v-else-if="activeTool.id === 'stage-research'"
              :slug="slug"
              :http="api"
          />
          <p v-else class="cp-stub">Kommt bald.</p>
        </CockpitToolShell>
      </template>
    </main>
  </div>
</template>

<style scoped>
.cp-app {
  min-height: 100dvh;
  color: var(--color-text);
  display: flex;
  flex-direction: column;
}

.cp-app__header {
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

.cp-app__brand {
  display: flex;
  align-items: center;
  min-width: 0;
}

.cp-app__brand-link {
  text-decoration: none;
  color: inherit;
  border-radius: 0.35rem;
}

.cp-app__brand-link:active {
  opacity: 0.85;
}

.cp-app__logo {
  display: block;
  height: 1.75rem;
  width: auto;
}

.cp-app__tools {
  display: flex;
  gap: 0.25rem;
}

.cp-tool {
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
  font-size: 1.15rem;
}

.cp-tool:active:not(.cp-tool--disabled) {
  opacity: 0.85;
}

.cp-tool--disabled,
.cp-tool:disabled {
  opacity: 0.4;
  cursor: not-allowed;
  color: var(--color-text-muted);
}

.cp-app__main {
  flex: 1;
  padding: 1rem;
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
  overflow: auto;
}

.cp-app__main--tool {
  padding-top: 0;
}

.cp-panel {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  max-width: 40rem;
  margin: 0 auto;
}

.cp-panel--wide {
  max-width: 48rem;
}

.cp-panel--center {
  align-items: center;
  text-align: center;
}

.cp-panel__h {
  font-size: 1.35rem;
  font-weight: 750;
  margin: 0;
  color: var(--color-text);
}

.cp-link {
  appearance: none;
  border: 0;
  background: transparent;
  padding: 0;
  margin: 0;
  align-self: flex-start;
  font: inherit;
  font-size: 0.95rem;
  font-weight: 600;
  color: var(--color-accent, var(--color-text));
  cursor: pointer;
  min-height: 2.5rem;
  display: inline-flex;
  align-items: center;
}

.cp-link:active {
  opacity: 0.75;
}

.cp-qr {
  width: min(100%, 17.5rem);
  height: auto;
  border-radius: var(--radius);
  background: #fff;
  padding: 0.5rem;
}

.cp-break {
  overflow-wrap: anywhere;
  word-break: break-word;
}

.cp-muted {
  color: var(--color-text-muted);
  font-size: 0.9rem;
  margin: 0;
}

.cp-pin {
  width: 100%;
  text-align: center;
  letter-spacing: 0.35em;
  font-size: 1.6rem;
  font-variant-numeric: tabular-nums;
}

.cp-btn-block {
  width: 100%;
}

.cp-home-brand {
  text-align: center;
  margin: 0.15rem 0 0.35rem;
}

.cp-home-brand__title {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--color-text-muted);
}

.cp-home-brand__event {
  font-weight: 750;
  font-size: 1.2rem;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0.75rem;
}

.cp-grid__cell {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 0.55rem;
  min-height: 7.5rem;
  padding: 1rem 0.75rem;
  border: 0;
  cursor: pointer;
  text-align: center;
  color: inherit;
  font: inherit;
  -webkit-tap-highlight-color: transparent;
}

.cp-grid__cell:active {
  transform: scale(0.98);
  opacity: 0.92;
}

.cp-grid__icon {
  font-size: 2rem;
  line-height: 1;
  color: var(--color-accent, var(--color-text));
}

.cp-grid__title {
  font-size: 0.95rem;
  font-weight: 700;
  line-height: 1.25;
  color: var(--color-text);
}

.cp-grid__badge {
  position: absolute;
  top: 0.55rem;
  right: 0.55rem;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-muted);
  background: color-mix(in srgb, var(--color-text-muted) 14%, transparent);
  border-radius: 999px;
  padding: 0.15rem 0.45rem;
}

.cp-stub {
  margin: 0;
  padding: 1.25rem 0;
  font-size: 1rem;
  color: var(--color-text-muted);
}
</style>
