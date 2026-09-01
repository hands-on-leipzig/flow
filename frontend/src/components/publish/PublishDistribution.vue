<script setup lang="ts">
/**
 * Ausgabe → Veröffentlichung
 * Controls left · live iframe of the public page right
 */
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {RouterLink} from 'vue-router'
import {useEventStore} from '@/stores/event'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import PublicLinkStrip from '@/components/molecules/PublicLinkStrip.vue'
import SavingToast from '@/components/atoms/SavingToast.vue'
import {showGlassToast} from '@/composables/useGlassToast'
import {usePublicHelperSearch} from '@/composables/usePublicHelperSearch'
import {usePublicVolunteerDataEntry} from '@/composables/usePublicVolunteerDataEntry'

defineOptions({name: 'PublishDistribution'})

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id ?? null)

const saving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const helperSaving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const volunteerDataEntrySaving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const dayAppsSaving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const detailLevel = ref(0)
const checkInEnabled = ref(false)
const cockpitEnabled = ref(false)
const dayAppsLoading = ref(false)
const iframeKey = ref(0)
const iframeLoading = ref(true)
const leftWidth = ref(32)

const {
  enabled: helperSearchEnabled,
  loading: helperSearchLoading,
  setEnabled: setHelperSearchEnabled,
} = usePublicHelperSearch(eventId)

const {
  enabled: volunteerDataEntryEnabled,
  loading: volunteerDataEntryLoading,
  setEnabled: setVolunteerDataEntryEnabled,
} = usePublicVolunteerDataEntry(eventId)

const levels = [
  {id: 0, short: 'Basis', name: 'Planung und Anmeldung', hint: 'Datum, Ort, Kontakt, Teams'},
  {id: 1, short: 'Ablauf', name: 'Überblick zum Ablauf', hint: '+ wichtige Zeiten'},
  {id: 2, short: 'Alles', name: 'volle Details', hint: '+ Online-Zeitplan'},
]

const helperSearchHiddenByLevel = computed(() => detailLevel.value === 2)

const volunteerDataEntryHiddenByLevel = computed(() => detailLevel.value === 2)

function normalizeLink(raw: string | null | undefined): string {
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw)) return raw
  const base = (import.meta.env.VITE_APP_URL || window.location.origin).replace(/\/$/, '')
  return `${base}/${raw.replace(/^\//, '')}`
}

const publicUrl = computed(() => normalizeLink(event.value?.link))

const previewSrc = computed(() => {
  const url = publicUrl.value
  if (!url) return ''
  const sep = url.includes('?') ? '&' : '?'
  return `${url}${sep}_pv=${iframeKey.value}`
})

const activeLevel = computed(() => levels[detailLevel.value] ?? levels[0])

function frontendToBackendLevel(level: number) {
  if (level === 0) return 1
  if (level === 1) return 3
  return 4
}

function backendToFrontendLevel(level: number) {
  if (level === 1 || level === 2) return 0
  if (level === 3) return 1
  if (level === 4) return 2
  return 0
}

function reloadPreview() {
  iframeLoading.value = true
  iframeKey.value += 1
}

async function fetchPublicationLevel() {
  if (!event.value?.id) return
  try {
    const {data} = await axios.get(`/publish/level/${event.value.id}`)
    detailLevel.value = backendToFrontendLevel(data.level ?? 1)
  } catch {
    detailLevel.value = 0
  }
}

async function setDetailLevel(level: number) {
  if (!event.value?.id || level === detailLevel.value) return
  const prev = detailLevel.value
  detailLevel.value = level
  try {
    saving.value?.show()
    await axios.post(`/publish/level/${event.value.id}`, {level: frontendToBackendLevel(level)})
    reloadPreview()
  } catch {
    detailLevel.value = prev
    showGlassToast('Sichtbarkeit konnte nicht gespeichert werden.', 'error')
  } finally {
    saving.value?.hide()
  }
}

function openPublic() {
  if (publicUrl.value) window.open(publicUrl.value, '_blank', 'noopener')
}

function onIframeLoad() {
  iframeLoading.value = false
}

async function onHelperSearchToggle(next: boolean) {
  let saved = false
  try {
    helperSaving.value?.show()
    saved = await setHelperSearchEnabled(next)
  } catch {
    // toast from composable
  } finally {
    helperSaving.value?.hide()
    if (saved) reloadPreview()
  }
}

async function onVolunteerDataEntryToggle(next: boolean) {
  let saved = false
  try {
    volunteerDataEntrySaving.value?.show()
    saved = await setVolunteerDataEntryEnabled(next)
  } catch {
    // toast from composable
  } finally {
    volunteerDataEntrySaving.value?.hide()
    if (saved) reloadPreview()
  }
}

async function loadDayAppSettings() {
  if (!eventId.value) {
    checkInEnabled.value = false
    cockpitEnabled.value = false
    return
  }
  dayAppsLoading.value = true
  try {
    const [checkInRes, cockpitRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/check-in/settings`),
      axios.get(`/events/${eventId.value}/cockpit/settings`),
    ])
    checkInEnabled.value = !!checkInRes.data?.enabled
    cockpitEnabled.value = !!cockpitRes.data?.enabled
  } catch {
    showGlassToast('Einstellungen für am Tag konnten nicht geladen werden.', 'error')
  } finally {
    dayAppsLoading.value = false
  }
}

async function onCheckInToggle(next: boolean) {
  if (!eventId.value) return
  const prev = checkInEnabled.value
  checkInEnabled.value = next
  try {
    dayAppsSaving.value?.show()
    const {data} = await axios.put(`/events/${eventId.value}/check-in/settings`, {enabled: next})
    checkInEnabled.value = !!data?.enabled
  } catch (e: any) {
    checkInEnabled.value = prev
    showGlassToast(e?.response?.data?.error || 'Check-In konnte nicht gespeichert werden.', 'error')
  } finally {
    dayAppsSaving.value?.hide()
  }
}

async function onCockpitToggle(next: boolean) {
  if (!eventId.value) return
  const prev = cockpitEnabled.value
  cockpitEnabled.value = next
  try {
    dayAppsSaving.value?.show()
    const {data} = await axios.put(`/events/${eventId.value}/cockpit/settings`, {enabled: next})
    cockpitEnabled.value = !!data?.enabled
  } catch (e: any) {
    cockpitEnabled.value = prev
    showGlassToast(e?.response?.data?.error || 'Cockpit konnte nicht gespeichert werden.', 'error')
  } finally {
    dayAppsSaving.value?.hide()
  }
}

watch(
    () => event.value?.id,
    async (id) => {
      if (!id) return
      await Promise.all([fetchPublicationLevel(), loadDayAppSettings()])
      reloadPreview()
    }
)

watch(publicUrl, (url, prev) => {
  if (url && url !== prev) reloadPreview()
})

onMounted(async () => {
  if (event.value?.id) {
    await Promise.all([fetchPublicationLevel(), loadDayAppSettings()])
    reloadPreview()
  }
})
</script>

<template>
  <SavingToast ref="saving" message="Sichtbarkeit wird gespeichert…" />
  <SavingToast ref="helperSaving" message="Einstellung wird gespeichert…" />
  <SavingToast ref="volunteerDataEntrySaving" message="Einstellung wird gespeichert…" />
  <SavingToast ref="dayAppsSaving" message="Wird gespeichert…" />

  <div class="pub">
    <div class="pub__shell">
      <section
          class="pub__left"
          :style="{ flex: `0 0 ${leftWidth}%` }"
      >
        <header class="pub__page-head">
          <h1 class="pub__page-title">Veröffentlichung</h1>
        </header>

        <PublicLinkStrip on-publish-page/>

        <section class="pub__tile glass-card liquid-surface-inner">
          <h2 class="glass-card__heading">Sichtbarkeit</h2>
          <div class="pub__levels" role="radiogroup" aria-label="Sichtbarkeitsstufe">
            <div
                v-for="level in levels"
                :key="level.id"
                role="radio"
                tabindex="0"
                class="pub__level liquid-surface-inner"
                :class="{'is-active': detailLevel === level.id}"
                :aria-checked="detailLevel === level.id"
                @click="setDetailLevel(level.id)"
                @keydown.enter.prevent="setDetailLevel(level.id)"
                @keydown.space.prevent="setDetailLevel(level.id)"
            >
              <div class="pub__level-main">
                <span class="pub__level-mark" aria-hidden="true">
                  <i v-if="detailLevel === level.id" class="bi bi-check2"/>
                  <span v-else>{{ level.id + 1 }}</span>
                </span>
                <span class="pub__level-copy">
                  <span class="pub__level-name">{{ level.name }}</span>
                  <span class="glass-settings-hint !mb-0">{{ level.hint }}</span>
                </span>
              </div>
              <div
                  v-if="level.id === 1"
                  class="pub__level-explain glass-settings-hint !mb-0"
              >
                <p class="pub__level-explain-p">
                  Die wichtigsten Zeiten werden automatisch aus dem Veranstaltungsplan übernommen.
                </p>
                <p class="pub__level-explain-p">
                  <RouterLink
                      to="/plan/schedule/free"
                      class="pub__level-explain-link"
                      @click.stop
                  >Zusätzliche Aktivitäten</RouterLink>
                  z.&nbsp;B. „Check-In“ werden übernommen, wenn sie als „öffentlich zeigen“ gekennzeichnet sind.
                </p>
              </div>
            </div>
          </div>
        </section>

        <section class="pub__tile glass-card liquid-surface-inner">
          <h2 class="glass-card__heading">Helfer:innen</h2>

          <div class="pub__app-block">
            <div class="pub__app-row">
              <span class="glass-settings-hint-link pub__app-link">Dateneingabe durch Helfer:innen</span>
              <ToggleSwitch
                  :model-value="volunteerDataEntryEnabled"
                  :disabled="volunteerDataEntryLoading || !eventId"
                  @update:modelValue="onVolunteerDataEntryToggle"
              />
            </div>
            <p class="glass-settings-hint !mb-0">
              Dateneingabe durch Helfer:innen. Die Felder dafür werden in
              <RouterLink to="/plan/volunteers/roster" class="pub__helper-link">
                Helfer:innen &gt; Helfer:innenliste
              </RouterLink>
              festlegt.
            </p>
            <p
                v-if="volunteerDataEntryEnabled && volunteerDataEntryHiddenByLevel"
                class="glass-settings-hint !mb-0 pub__helper-warn"
            >
              Bei Sichtbarkeit „Alles“ wird dieser Bereich auf dem öffentlichen Plan nicht angezeigt.
            </p>
          </div>

          <div class="pub__app-block">
            <div class="pub__app-row">
              <RouterLink to="/plan/volunteers/staffing" class="glass-settings-hint-link pub__app-link">
                Suche nach Helfer:innen
              </RouterLink>
              <ToggleSwitch
                  :model-value="helperSearchEnabled"
                  :disabled="helperSearchLoading || !eventId"
                  @update:modelValue="onHelperSearchToggle"
              />
            </div>
            <p class="glass-settings-hint !mb-0">
              Zeigt offene Positionen aus
              <RouterLink to="/plan/volunteers/staffing" class="pub__helper-link">
                Helfer:innen → Zuordnung
              </RouterLink>
              auf dem öffentlichen Plan zwischen Allgemeine Infos und Angemeldete Teams.
            </p>
            <p
                v-if="helperSearchEnabled && helperSearchHiddenByLevel"
                class="glass-settings-hint !mb-0 pub__helper-warn"
            >
              Bei Sichtbarkeit „Alles“ wird dieser Bereich auf dem öffentlichen Plan nicht angezeigt.
            </p>
          </div>
        </section>

        <section class="pub__tile glass-card liquid-surface-inner">
          <h2 class="glass-card__heading">Apps speziell für den Tag der Veranstaltung</h2>

          <p class="glass-settings-hint !mb-0 pub__day-apps-hint">
            Diese Apps sind nur vom Plan verlinkt, wenn die Sichtbarkeit auf „volle Details“ gesetzt ist.
          </p>

          <div class="pub__app-block">
            <div class="pub__app-row">
              <RouterLink to="/plan/live/check-in" class="glass-settings-hint-link pub__app-link">
                Check-In
              </RouterLink>
              <ToggleSwitch
                  :model-value="checkInEnabled"
                  :disabled="dayAppsLoading || !eventId"
                  @update:modelValue="onCheckInToggle"
              />
            </div>
            <p class="glass-settings-hint !mb-0">
              Empfang am Veranstaltungstag. Vorschau rechts ist die echte Rezeptionsansicht.
            </p>
          </div>

          <div class="pub__app-block">
            <div class="pub__app-row">
              <RouterLink to="/plan/live/cockpit" class="glass-settings-hint-link pub__app-link">
                Cockpit
              </RouterLink>
              <ToggleSwitch
                  :model-value="cockpitEnabled"
                  :disabled="dayAppsLoading || !eventId"
                  @update:modelValue="onCockpitToggle"
              />
            </div>
            <p class="glass-settings-hint !mb-0">
              Steuerung am Veranstaltungstag. Vorschau rechts ist die echte Cockpit-Ansicht.
            </p>
          </div>
        </section>
      </section>

      <PanelSplitter
          v-model="leftWidth"
          class="hidden md:flex pub__splitter"
          :min="24"
          :max="48"
          storage-key="flow-publish-split"
      />

      <section
          class="glass-card liquid-surface-inner pub__preview"
          aria-label="Live-Vorschau der öffentlichen Seite"
      >
        <div class="pub__preview-bar">
          <span class="pub__preview-dot" aria-hidden="true"/>
          <span class="pub__preview-dot" aria-hidden="true"/>
          <span class="pub__preview-dot" aria-hidden="true"/>
          <span class="pub__preview-path">
            Live-Vorschau · {{ activeLevel.short }}
          </span>
          <button
              v-if="publicUrl"
              type="button"
              class="pub__preview-open"
              title="Vorschau neu laden"
              @click="reloadPreview"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
          </button>
          <button
              v-if="publicUrl"
              type="button"
              class="pub__preview-open"
              @click="openPublic"
          >
            In Tab öffnen
          </button>
        </div>

        <div class="pub__frame-wrap">
          <div v-if="iframeLoading && publicUrl" class="pub__frame-loading">
            Lade Vorschau…
          </div>
          <iframe
              v-if="publicUrl"
              :key="iframeKey"
              class="pub__frame"
              :src="previewSrc"
              title="Vorschau der öffentlichen Veranstaltungsseite"
              @load="onIframeLoad"
          />
          <div v-else class="pub__frame-empty">
            Kein öffentlicher Link vorhanden.
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.pub {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: min(72vh, 48rem);
  padding-bottom: max(1rem, env(safe-area-inset-bottom));
}

.pub__shell {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: stretch;
  flex: 1;
  min-height: min(64vh, 42rem);
  min-width: 0;
}

@media (min-width: 768px) {
  .pub__shell {
    flex-direction: row;
    gap: 0.55rem;
  }
}

.pub__left {
  display: flex;
  flex-direction: column;
  gap: 1.15rem;
  min-width: 0;
  min-height: 0;
  overflow: auto;
}

.pub__page-head {
  margin: 0;
}

.pub__page-title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 650;
  line-height: 1.2;
}

.pub__app-block {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.pub__app-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.pub__app-link {
  font-size: 0.875rem;
  font-weight: 650;
}

.pub__day-apps-hint {
  line-height: 1.45;
}

@media (max-width: 767px) {
  .pub__left {
    flex: 1 1 auto !important;
  }
}

.pub__tile {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.pub__helper-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.pub__helper-warn {
  color: var(--color-text-muted);
}

.pub__helper-link {
  color: var(--color-accent);
  font-weight: 600;
  text-decoration: none;
}

.pub__helper-link:hover {
  text-decoration: underline;
}

.pub__preview {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 28rem;
  min-width: 0;
  padding: 0 !important;
  overflow: hidden;
}

@media (min-width: 768px) {
  .pub__preview {
    min-height: 0;
    height: auto;
    align-self: stretch;
  }
}

.pub__levels {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
}

.pub__level {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  width: 100%;
  text-align: left;
  padding: 0.7rem 0.75rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 32%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 6px 14px rgba(15, 23, 42, 0.04),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
  cursor: pointer;
  transition: border-color 0.12s ease, box-shadow 0.12s ease;
}

.pub__level:hover {
  border-color: color-mix(in srgb, var(--color-border-strong) 55%, var(--liquid-border-soft));
}

.pub__level.is-active {
  border-color: color-mix(in srgb, var(--color-accent) 48%, transparent);
  box-shadow:
    0 8px 18px rgba(15, 23, 42, 0.055),
    inset 0 1px 0 rgba(255, 255, 255, 0.95),
    inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 18%, transparent);
}

.pub__level-main {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
}

.pub__level-mark {
  flex-shrink: 0;
  width: 1.45rem;
  height: 1.45rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.4rem;
  font-size: 0.75rem;
  font-weight: 750;
  color: var(--color-text-muted);
  background: color-mix(in srgb, #ffffff 88%, var(--liquid-tile-bg-inner));
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
}

.pub__level.is-active .pub__level-mark {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: var(--color-on-accent, #fff);
}

.pub__level-copy {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  min-width: 0;
}

.pub__level-name {
  font-size: 0.88rem;
  font-weight: 700;
  color: var(--color-text);
  line-height: 1.25;
}

.pub__level-explain {
  padding-left: calc(1.45rem + 0.65rem);
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.pub__level-explain-p {
  margin: 0;
}

.pub__level-explain-link {
  color: var(--color-accent);
  font-weight: 600;
  text-decoration: none;
}

.pub__level-explain-link:hover {
  text-decoration: underline;
}

.pub__preview-bar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.55rem 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 45%, #fff);
  flex-shrink: 0;
}

.pub__preview-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-border-strong) 45%, transparent);
}

.pub__preview-path {
  margin-left: 0.45rem;
  flex: 1;
  min-width: 0;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pub__preview-open {
  border: none;
  background: transparent;
  color: var(--color-accent);
  font-size: 0.75rem;
  font-weight: 650;
  cursor: pointer;
  padding: 0.2rem 0.35rem;
}

.pub__preview-open:hover { text-decoration: underline; }

.pub__frame-wrap {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  background: #fff;
}

.pub__frame {
  width: 100%;
  height: 100%;
  min-height: 24rem;
  border: none;
  display: block;
  background: #fff;
}

@media (min-width: 768px) {
  .pub__frame {
    min-height: 0;
  }
}

.pub__frame-loading,
.pub__frame-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.9rem;
  color: var(--color-text-muted);
  background: color-mix(in srgb, #ffffff 92%, var(--color-bg-muted));
  pointer-events: none;
}

.pub__frame-empty {
  pointer-events: auto;
}
</style>
