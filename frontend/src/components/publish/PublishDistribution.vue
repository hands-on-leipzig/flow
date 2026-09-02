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
import {apiError} from '@/utils/apiError'
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
const leftWidth = ref(50)

const PREVIEW_VIEWPORT_STORAGE_KEY = 'flow-publish-preview-viewport'
const PREVIEW_VIEWPORT_STORAGE_KEY_LEGACY = 'flow-publish-preview-device'

const previewViewports = [
  {id: 'full', label: 'Responsive', width: null, height: null},
  {id: 'galaxy-s24', label: 'Galaxy S24 · 360 × 780', width: 360, height: 780},
  {id: 'iphone-se', label: 'iPhone SE · 375 × 667', width: 375, height: 667},
  {id: 'iphone-15', label: 'iPhone 15 · 390 × 844', width: 390, height: 844},
  {id: 'iphone-15-pro-max', label: 'iPhone 15 Pro Max · 430 × 932', width: 430, height: 932},
  {id: 'pixel-8', label: 'Pixel 8 · 412 × 915', width: 412, height: 915},
  {id: 'ipad-mini', label: 'iPad mini · 1024 × 768 (Querformat)', width: 1024, height: 768},
  {id: 'ipad-pro-11', label: 'iPad Pro 11" · 1194 × 834 (Querformat)', width: 1194, height: 834},
  {id: 'ipad-pro-12', label: 'iPad Pro 12,9" · 1366 × 1024 (Querformat)', width: 1366, height: 1024},
] as const

type PreviewViewportId = (typeof previewViewports)[number]['id']

const legacyViewportIds: Record<string, PreviewViewportId> = {
  full: 'full',
  '360': 'galaxy-s24',
  '390': 'iphone-15',
  '430': 'iphone-15-pro-max',
  '768': 'ipad-mini',
  '1024': 'ipad-pro-12',
}

function isPreviewViewportId(value: string): value is PreviewViewportId {
  return previewViewports.some((viewport) => viewport.id === value)
}

function readPreviewViewportId(): PreviewViewportId {
  try {
    const stored = localStorage.getItem(PREVIEW_VIEWPORT_STORAGE_KEY)
    if (stored && isPreviewViewportId(stored)) {
      return stored
    }
    const legacy = localStorage.getItem(PREVIEW_VIEWPORT_STORAGE_KEY_LEGACY)
    if (legacy && legacyViewportIds[legacy]) {
      return legacyViewportIds[legacy]
    }
  } catch {
    /* ignore */
  }
  return 'full'
}

const previewViewportId = ref<PreviewViewportId>(readPreviewViewportId())

const activePreviewViewport = computed(
  () => previewViewports.find((viewport) => viewport.id === previewViewportId.value) ?? previewViewports[0],
)

const isFixedPreviewViewport = computed(
  () => activePreviewViewport.value.width != null && activePreviewViewport.value.height != null,
)

const deviceShellStyle = computed(() => {
  const {width, height} = activePreviewViewport.value
  if (width == null || height == null) {
    return undefined
  }
  return {
    width: `${width}px`,
    height: `${height}px`,
  }
})

const previewViewportHint = computed(() => {
  const {width, height} = activePreviewViewport.value
  if (width == null || height == null) {
    return 'Responsive'
  }
  return `${width} × ${height}`
})

watch(previewViewportId, (id) => {
  try {
    localStorage.setItem(PREVIEW_VIEWPORT_STORAGE_KEY, id)
  } catch {
    /* ignore */
  }
})

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

const publicFormFields = ref<Array<{field_key: string; label: string; public_form: boolean}>>([])
const publicFormFieldsBusy = ref(false)
const collectTShirt = ref(false)
const collectMeal = ref(false)

async function loadPublicFormChecklist() {
  if (!eventId.value) {
    publicFormFields.value = []
    collectTShirt.value = false
    collectMeal.value = false
    return
  }
  try {
    const [fieldsRes, collectRes] = await Promise.all([
      axios.get(`/events/${eventId.value}/volunteer-fields`),
      axios.get(`/events/${eventId.value}/volunteer-collect`),
    ])
    publicFormFields.value = (fieldsRes.data.fields ?? []).map((field: {field_key: string; label: string; public_form?: boolean}) => ({
      field_key: field.field_key,
      label: field.label,
      public_form: !!field.public_form,
    }))
    collectTShirt.value = !!collectRes.data.collect?.t_shirt
    collectMeal.value = !!collectRes.data.collect?.meal
  } catch {
    publicFormFields.value = []
    collectTShirt.value = false
    collectMeal.value = false
  }
}

async function savePublicFormChecklist() {
  if (!eventId.value || publicFormFieldsBusy.value) return
  publicFormFieldsBusy.value = true
  try {
    const keys = publicFormFields.value.filter((f) => f.public_form).map((f) => f.field_key)
    const {data} = await axios.put(`/events/${eventId.value}/volunteer-fields/public-form`, {
      field_keys: keys,
    })
    publicFormFields.value = (data.fields ?? []).map((field: {field_key: string; label: string; public_form?: boolean}) => ({
      field_key: field.field_key,
      label: field.label,
      public_form: !!field.public_form,
    }))
  } catch (e: unknown) {
    showGlassToast(apiError(e, 'Formular-Felder konnten nicht gespeichert werden.'), 'error')
    await loadPublicFormChecklist()
  } finally {
    publicFormFieldsBusy.value = false
  }
}

function togglePublicFormField(fieldKey: string, next: boolean) {
  const row = publicFormFields.value.find((f) => f.field_key === fieldKey)
  if (!row || row.public_form === next) return
  row.public_form = next
  void savePublicFormChecklist()
}

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
    if (saved && next) {
      await loadPublicFormChecklist()
    }
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
  let saved = false
  try {
    dayAppsSaving.value?.show()
    const {data} = await axios.put(`/events/${eventId.value}/check-in/settings`, {enabled: next})
    checkInEnabled.value = !!data?.enabled
    saved = true
  } catch (e: any) {
    checkInEnabled.value = prev
    showGlassToast(e?.response?.data?.error || 'Check-In konnte nicht gespeichert werden.', 'error')
  } finally {
    dayAppsSaving.value?.hide()
    if (saved) reloadPreview()
  }
}

async function onCockpitToggle(next: boolean) {
  if (!eventId.value) return
  const prev = cockpitEnabled.value
  cockpitEnabled.value = next
  let saved = false
  try {
    dayAppsSaving.value?.show()
    const {data} = await axios.put(`/events/${eventId.value}/cockpit/settings`, {enabled: next})
    cockpitEnabled.value = !!data?.enabled
    saved = true
  } catch (e: any) {
    cockpitEnabled.value = prev
    showGlassToast(e?.response?.data?.error || 'Cockpit konnte nicht gespeichert werden.', 'error')
  } finally {
    dayAppsSaving.value?.hide()
    if (saved) reloadPreview()
  }
}

watch(
    () => event.value?.id,
    async (id) => {
      if (!id) return
      await Promise.all([fetchPublicationLevel(), loadDayAppSettings(), loadPublicFormChecklist()])
      reloadPreview()
    }
)

watch(publicUrl, (url, prev) => {
  if (url && url !== prev) reloadPreview()
})

onMounted(async () => {
  if (event.value?.id) {
    await Promise.all([fetchPublicationLevel(), loadDayAppSettings(), loadPublicFormChecklist()])
    reloadPreview()
  }
})
</script>

<template>
  <div class="pub">
    <SavingToast ref="saving" message="Sichtbarkeit wird gespeichert…" />
    <SavingToast ref="helperSaving" message="Einstellung wird gespeichert…" />
    <SavingToast ref="volunteerDataEntrySaving" message="Einstellung wird gespeichert…" />
    <SavingToast ref="dayAppsSaving" message="Wird gespeichert…" />

    <div class="pub__workspace">
      <div class="pub__split">
        <section
            class="pub__left"
            :style="{ flex: `0 0 ${leftWidth}%` }"
        >
          <div class="pub__left-scroll">
            <div class="pub__settings">
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
              Helfer:innen können ihre Daten eingeben.<br>
              Hier wird festgelegt, welche Felder erscheinen. Welche Felder es überhaupt gibt, kann unter
              <RouterLink to="/plan/volunteers/roster" class="pub__helper-link">
                Helfer:innen → Helfer:innenliste
              </RouterLink>
              festgelegt werden.
            </p>
            <div
                v-if="volunteerDataEntryEnabled"
                class="pub__form-checklist"
            >
              <p class="pub__form-checklist-title">Felder im Formular</p>
              <div
                  v-if="collectTShirt"
                  class="pub__form-check pub__form-check--fixed"
                  title="Immer im Formular, solange T-Shirt in der Helferliste aktiv ist"
              >
                <input type="checkbox" checked disabled>
                <span>T-Shirt Größe</span>
              </div>
              <div
                  v-if="collectMeal"
                  class="pub__form-check pub__form-check--fixed"
                  title="Immer im Formular, solange Essen in der Helferliste aktiv ist"
              >
                <input type="checkbox" checked disabled>
                <span>Essen</span>
              </div>
              <label
                  v-for="field in publicFormFields"
                  :key="field.field_key"
                  class="pub__form-check"
              >
                <input
                    type="checkbox"
                    :checked="field.public_form"
                    :disabled="publicFormFieldsBusy"
                    @change="togglePublicFormField(field.field_key, ($event.target as HTMLInputElement).checked)"
                >
                <span>{{ field.label }}</span>
              </label>
            </div>
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
              Details unter
              <RouterLink to="/plan/live/check-in" class="pub__helper-link">
                am Tag → Check-In
              </RouterLink>
              .
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
              Details unter
              <RouterLink to="/plan/live/cockpit" class="pub__helper-link">
                am Tag → Cockpit
              </RouterLink>
              .
            </p>
          </div>
        </section>
            </div>
          </div>
        </section>

        <PanelSplitter
            v-model="leftWidth"
            class="hidden md:flex pub__splitter"
            storage-key="flow-publish-split-v2"
        />

        <section class="pub__right">
          <div
              class="pub__preview glass-card liquid-surface-inner"
              aria-label="Live-Vorschau der öffentlichen Seite"
          >
            <div class="pub__preview-bar">
              <span class="pub__preview-dot" aria-hidden="true"/>
              <span class="pub__preview-dot" aria-hidden="true"/>
              <span class="pub__preview-dot" aria-hidden="true"/>
              <span class="pub__preview-path">
                Live-Vorschau · {{ activeLevel.short }}
                <span v-if="isFixedPreviewViewport" class="pub__preview-viewport"> · {{ previewViewportHint }}</span>
              </span>
              <div class="pub__preview-actions">
                <label class="pub__preview-device glass-btn-accent">
                  <span class="pub__preview-device-label">Gerät</span>
                  <select
                      v-model="previewViewportId"
                      class="pub__preview-device-select"
                      aria-label="Vorschau-Gerät"
                  >
                    <option v-for="viewport in previewViewports" :key="viewport.id" :value="viewport.id">
                      {{ viewport.label }}
                    </option>
                  </select>
                </label>
                <button
                    v-if="publicUrl"
                    type="button"
                    class="pub__preview-icon-btn"
                    title="Vorschau neu laden"
                    @click="reloadPreview"
                >
                  <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
                </button>
                <button
                    v-if="publicUrl"
                    type="button"
                    class="glass-btn-secondary pub__preview-tab-btn"
                    @click="openPublic"
                >
                  In Tab öffnen
                </button>
              </div>
            </div>

            <div
                class="pub__frame-stage"
                :class="{'pub__frame-stage--fixed': isFixedPreviewViewport}"
            >
              <div
                  class="pub__device-shell"
                  :class="{'pub__device-shell--full': !isFixedPreviewViewport}"
                  :style="deviceShellStyle"
              >
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
            </div>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.pub {
  display: flex;
  flex: 1 1 0%;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.pub__workspace {
  display: flex;
  flex: 1 1 0%;
  flex-direction: column;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
}

.pub__split {
  display: flex;
  flex: 1 1 0%;
  min-height: 0;
  height: 100%;
  flex-direction: column;
  gap: 0.75rem;
  align-items: stretch;
  overflow: hidden;
}

@media (min-width: 768px) {
  .pub__split {
    flex-direction: row;
    gap: 0.55rem;
  }

  .pub__left {
    height: 100%;
    max-height: 100%;
  }
}

.pub__left {
  display: flex;
  flex-direction: column;
  align-self: stretch;
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}

.pub__left-scroll {
  flex: 1 1 0%;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
}

.pub__right {
  flex: 1 1 auto;
  align-self: stretch;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

@media (max-width: 767px) {
  .pub__left {
    flex: 1 1 auto !important;
    max-height: 50vh;
  }
}

.pub__splitter {
  flex-shrink: 0;
}

.pub__settings {
  display: flex;
  flex-direction: column;
  gap: 1.15rem;
  padding: 1.15rem 1.2rem 1.4rem;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
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

.pub__form-checklist {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-top: 0.4rem;
  padding-top: 0.65rem;
  border-top: 1px solid var(--liquid-border-soft);
}

.pub__form-checklist-title {
  margin: 0 0 0.15rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.pub__form-check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.pub__form-check--fixed {
  cursor: default;
  color: var(--color-text-muted);
  opacity: 0.85;
}

.pub__form-check--fixed input {
  cursor: not-allowed;
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

.pub__tile {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
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
  min-height: 0;
  min-width: 0;
  padding: 0 !important;
  overflow: hidden;
  background: var(--glass-tab-surface, #ffffff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 65%, transparent);
  border-radius: var(--radius-lg, 16px);
  box-shadow:
    0 10px 28px rgba(15, 23, 42, 0.07),
    0 2px 6px rgba(15, 23, 42, 0.04);
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
  flex-wrap: wrap;
}

.pub__preview-actions {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  margin-left: auto;
  flex-shrink: 0;
}

.pub__preview-device.glass-btn-accent {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.35rem 0.55rem 0.35rem 0.75rem;
  cursor: default;
}

.pub__preview-device-label {
  font-size: 0.75rem;
  font-weight: 650;
  color: var(--color-on-accent);
  white-space: nowrap;
}

.pub__preview-device-select {
  appearance: none;
  min-width: 12.5rem;
  max-width: 16rem;
  padding: 0.2rem 1.65rem 0.2rem 0.45rem;
  border: 1px solid color-mix(in srgb, #fff 28%, transparent);
  border-radius: calc(var(--radius) - 2px);
  background:
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='white' viewBox='0 0 16 16'%3E%3Cpath d='M4.646 6.646a.5.5 0 0 1 .708 0L8 9.293l2.646-2.647a.5.5 0 0 1 .708.708l-3 3a.5.5 0 0 1-.708 0l-3-3a.5.5 0 0 1 0-.708'/%3E%3C/svg%3E")
    no-repeat right 0.45rem center / 0.75rem,
    color-mix(in srgb, #fff 18%, transparent);
  color: var(--color-on-accent);
  font-size: 0.75rem;
  font-weight: 650;
  line-height: 1.3;
  cursor: pointer;
}

.pub__preview-device-select:focus-visible {
  outline: 2px solid color-mix(in srgb, #fff 75%, transparent);
  outline-offset: 1px;
}

.pub__preview-device-select option {
  color: var(--color-text);
  background: #fff;
}

.pub__preview-tab-btn {
  padding: 0.375rem 0.75rem;
  font-size: 0.75rem;
  white-space: nowrap;
}

.pub__preview-icon-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.85rem;
  height: 1.85rem;
  padding: 0;
  border: none;
  border-radius: var(--radius);
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
}

.pub__preview-icon-btn:hover {
  color: var(--color-accent);
  background: color-mix(in srgb, var(--color-accent) 10%, transparent);
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

.pub__frame-stage {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  min-width: 0;
  overflow: auto;
  background: color-mix(in srgb, var(--color-bg-muted) 38%, #eef2f7);
}

.pub__preview-viewport {
  font-variant-numeric: tabular-nums;
}

.pub__frame-stage--fixed {
  display: flex;
  justify-content: center;
  align-items: flex-start;
  padding: 0.85rem;
}

.pub__device-shell {
  position: relative;
  flex: 1 1 auto;
  min-height: 0;
  height: 100%;
  background: #fff;
  overflow: hidden;
}

.pub__device-shell--full {
  width: 100%;
}

.pub__device-shell:not(.pub__device-shell--full) {
  flex: 0 0 auto;
  max-width: calc(100% - 1.7rem);
  border-radius: calc(var(--radius-lg, 16px) + 2px);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  box-shadow:
    0 16px 36px rgba(15, 23, 42, 0.14),
    0 2px 8px rgba(15, 23, 42, 0.06);
}

.pub__frame {
  width: 100%;
  height: 100%;
  border: none;
  display: block;
  background: #fff;
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
