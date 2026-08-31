<script setup lang="ts">
/**
 * am Tag → Check-In
 * Controls left · live iframe of reception app right
 */
import {computed, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import SavingToast from '@/components/atoms/SavingToast.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'EventDayCheckIn'})

type Settings = {
  event_id: number
  slug: string | null
  has_slug: boolean
  enabled: boolean
  pin: string
  text_teams: string | null
  text_helpers: string | null
  reception_path: string | null
}

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id ?? null)

const saving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const leftWidth = ref(36)
const settings = ref<Settings | null>(null)
const loading = ref(false)
const pinDraft = ref('')
const textTeams = ref('')
const textHelpers = ref('')
const iframeKey = ref(0)
const iframeLoading = ref(true)
const previewNonce = ref(Date.now())
const showResetConfirm = ref(false)
const resetBusy = ref(false)

/** Same-origin reception URL; nonce forces a real network reload after settings change. */
const receptionUrl = computed(() => {
  const path = settings.value?.reception_path
  if (!path) return ''
  return `${window.location.origin}${path}?preview=${previewNonce.value}`
})

async function loadSettings() {
  if (!eventId.value) {
    settings.value = null
    return
  }
  loading.value = true
  try {
    const {data} = await axios.get(`/events/${eventId.value}/check-in/settings`)
    settings.value = data
    pinDraft.value = data.pin || ''
    textTeams.value = data.text_teams || ''
    textHelpers.value = data.text_helpers || ''
    reloadPreview()
  } catch {
    showGlassToast('Check-In Einstellungen konnten nicht geladen werden.', 'error')
  } finally {
    loading.value = false
  }
}

async function savePatch(patch: Record<string, unknown>) {
  if (!eventId.value) return
  saving.value?.show()
  try {
    const {data} = await axios.put(`/events/${eventId.value}/check-in/settings`, patch)
    settings.value = data
    pinDraft.value = data.pin || ''
    textTeams.value = data.text_teams || ''
    textHelpers.value = data.text_helpers || ''
    reloadPreview()
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Speichern fehlgeschlagen.', 'error')
    await loadSettings()
  } finally {
    saving.value?.hide()
  }
}

async function onEnabledToggle(next: boolean) {
  await savePatch({enabled: next})
}

async function savePin() {
  const pin = pinDraft.value.replace(/\D/g, '').slice(0, 6)
  pinDraft.value = pin
  if (pin.length !== 6) {
    showGlassToast('PIN muss aus 6 Ziffern bestehen.', 'error')
    return
  }
  await savePatch({pin})
}

async function saveTexts() {
  await savePatch({
    text_teams: textTeams.value,
    text_helpers: textHelpers.value,
  })
}

function reloadPreview() {
  iframeLoading.value = true
  previewNonce.value = Date.now()
  iframeKey.value += 1
}

function onIframeLoad() {
  iframeLoading.value = false
}

async function confirmReset() {
  if (!eventId.value || resetBusy.value) return
  resetBusy.value = true
  try {
    await axios.post(`/events/${eventId.value}/check-in/reset`)
    showGlassToast('Check-In Einträge zurückgesetzt.', 'success')
    showResetConfirm.value = false
    reloadPreview()
  } catch {
    showGlassToast('Zurücksetzen fehlgeschlagen.', 'error')
  } finally {
    resetBusy.value = false
  }
}

watch(eventId, () => {
  void loadSettings()
}, {immediate: true})
</script>

<template>
  <SavingToast ref="saving" message="Wird gespeichert…"/>
  <ConfirmationModal
      :show="showResetConfirm"
      type="danger"
      title="Check-In zurücksetzen?"
      message="Alle Check-In- und No-Show-Einträge sowie Empfangsnotizen dieses Events werden gelöscht. PIN, Öffnung und Infotexte bleiben erhalten."
      confirm-text="Zurücksetzen"
      cancel-text="Abbrechen"
      :disable-confirm-button="resetBusy"
      @confirm="confirmReset"
      @cancel="showResetConfirm = false"
  />

  <div class="ci-settings vol-page">
    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Check-In App</h1>
        <p class="vol-page__sub">Empfang am Veranstaltungstag. Vorschau rechts ist die echte Rezeptionsansicht.</p>
      </div>
    </header>

    <p v-if="settings && !settings.has_slug" class="glass-alert-warning !mb-0 !text-xs">
      Öffentlicher Link fehlt — bitte zuerst unter Ausgabe → Veröffentlichung erzeugen.
    </p>

    <div class="ci-settings__shell">
      <section class="ci-settings__left" :style="{ flex: `0 0 ${leftWidth}%` }">
        <section class="glass-card liquid-surface-inner ci-settings__tile">
          <div class="ci-settings__access-head">
            <h2 class="glass-card__heading !mb-0">Zugang zur App</h2>
            <div class="ci-settings__access-toggle">
              <span class="ci-settings__toggle-label">{{ settings?.enabled ? 'Geöffnet' : 'Geschlossen' }}</span>
              <ToggleSwitch
                  :model-value="!!settings?.enabled"
                  :disabled="loading || !eventId || !settings?.has_slug"
                  @update:model-value="onEnabledToggle"
              />
            </div>
          </div>
          <div class="ci-settings__pin-row">
            <input
                v-model="pinDraft"
                type="text"
                inputmode="numeric"
                maxlength="6"
                autocomplete="off"
                class="glass-input"
                :disabled="loading || !eventId"
                aria-label="PIN"
                @keydown.enter.prevent="savePin"
            />
            <button
                type="button"
                class="glass-btn-secondary"
                :disabled="loading || !eventId"
                @click="savePin"
            >
              Speichern
            </button>
          </div>
        </section>

        <section class="glass-card liquid-surface-inner ci-settings__tile">
          <h2 class="glass-card__heading">Infotext Teams</h2>
          <textarea
              v-model="textTeams"
              rows="3"
              class="glass-input ci-settings__textarea"
              :disabled="loading || !eventId"
              placeholder="Optionaler Hinweis für alle Teams…"
          />
        </section>

        <section class="glass-card liquid-surface-inner ci-settings__tile">
          <h2 class="glass-card__heading">Infotext Helfer:innen</h2>
          <textarea
              v-model="textHelpers"
              rows="3"
              class="glass-input ci-settings__textarea"
              :disabled="loading || !eventId"
              placeholder="Optionaler Hinweis für alle Helfer:innen…"
          />
          <button
              type="button"
              class="glass-btn-secondary mt-2"
              :disabled="loading || !eventId"
              @click="saveTexts"
          >
            Texte speichern
          </button>
        </section>

        <section class="glass-card liquid-surface-inner ci-settings__tile">
          <h2 class="glass-card__heading">Testen</h2>
          <p class="glass-settings-hint">
            Setzt alle Empfangseinträge zurück (nicht PIN/Texte/Status).
          </p>
          <button
              type="button"
              class="glass-btn-secondary"
              :disabled="loading || !eventId"
              @click="showResetConfirm = true"
          >
            Check-In zurücksetzen
          </button>
        </section>
      </section>

      <PanelSplitter v-model="leftWidth" storage-key="flow-check-in-split"/>

      <section class="glass-card liquid-surface-inner ci-settings__preview" aria-label="Vorschau Check-In">
        <div class="ci-settings__preview-bar">
          <span class="ci-settings__preview-dot" aria-hidden="true"/>
          <span class="ci-settings__preview-dot" aria-hidden="true"/>
          <span class="ci-settings__preview-dot" aria-hidden="true"/>
          <span class="ci-settings__preview-path">
            Live-Vorschau · Check-In
          </span>
          <button
              v-if="receptionUrl"
              type="button"
              class="ci-settings__preview-open"
              title="Vorschau neu laden"
              @click="reloadPreview"
          >
            <i class="bi bi-arrow-clockwise" aria-hidden="true"/>
          </button>
        </div>
        <div class="ci-settings__frame-wrap">
          <div v-if="iframeLoading && receptionUrl" class="ci-settings__frame-loading">
            Lade Vorschau…
          </div>
          <iframe
              v-if="receptionUrl"
              :key="iframeKey"
              class="ci-settings__frame"
              :src="receptionUrl"
              title="Vorschau Check-In Empfang"
              @load="onIframeLoad"
          />
          <div v-else class="ci-settings__frame-empty">
            Kein öffentlicher Link vorhanden.
          </div>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.ci-settings {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-height: min(72vh, 48rem);
}

.ci-settings__toggle-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.ci-settings__access-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.ci-settings__access-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.ci-settings__shell {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: stretch;
  flex: 1;
  min-height: min(64vh, 42rem);
  min-width: 0;
}

@media (min-width: 768px) {
  .ci-settings__shell {
    flex-direction: row;
    gap: 0.55rem;
  }
}

.ci-settings__left {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  min-width: 0;
}

.ci-settings__tile {
  padding: 1rem;
}

.ci-settings__pin-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.ci-settings__pin-row .glass-input {
  font-size: 1.25rem;
  letter-spacing: 0.2em;
  font-variant-numeric: tabular-nums;
  max-width: 9rem;
  text-align: center;
}

.ci-settings__textarea {
  width: 100%;
  min-height: 4.5rem;
  resize: vertical;
}

.ci-settings__preview {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  padding: 0;
}

.ci-settings__preview-bar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.45rem 0.65rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
}

.ci-settings__preview-dot {
  width: 0.45rem;
  height: 0.45rem;
  border-radius: 999px;
  background: color-mix(in srgb, var(--color-text-subtle) 55%, transparent);
}

.ci-settings__preview-path {
  margin-left: 0.35rem;
  flex: 1;
  font-size: 0.75rem;
  color: var(--color-text-muted);
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ci-settings__preview-open {
  border: none;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: 0.2rem 0.35rem;
  border-radius: 0.35rem;
}

.ci-settings__preview-open:hover {
  color: var(--color-text);
  background: color-mix(in srgb, var(--color-text) 6%, transparent);
}

.ci-settings__frame-wrap {
  position: relative;
  flex: 1;
  min-height: 28rem;
  background: color-mix(in srgb, var(--color-bg) 80%, #000);
}

.ci-settings__frame {
  width: 100%;
  height: 100%;
  min-height: 28rem;
  border: 0;
  background: #fff;
}

.ci-settings__frame-loading,
.ci-settings__frame-empty {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--color-text-muted);
  font-size: 0.875rem;
  pointer-events: none;
}

.ci-settings__frame-empty {
  position: relative;
  min-height: 28rem;
}
</style>
