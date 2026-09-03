<script setup lang="ts">
/**
 * am Tag → Cockpit: shared page header + flexible settings/preview split.
 */
import {computed, onBeforeUnmount, ref, watch} from 'vue'
import axios from 'axios'
import {RouterLink} from 'vue-router'
import {useEventStore} from '@/stores/event'
import PanelSplitter from '@/components/atoms/PanelSplitter.vue'
import ToggleSwitch from '@/components/atoms/ToggleSwitch.vue'
import SavingToast from '@/components/atoms/SavingToast.vue'
import ConfirmationModal from '@/components/molecules/ConfirmationModal.vue'
import MobileLivePreview from '@/components/molecules/MobileLivePreview.vue'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'EventDayCockpit'})

type Settings = {
  event_id: number
  slug: string | null
  has_slug: boolean
  enabled: boolean
  pin: string
  app_path: string | null
}

const FIELD_SAVE_DEBOUNCE_MS = 450

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id ?? null)

const saving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const leftWidth = ref(36)
const settings = ref<Settings | null>(null)
const loading = ref(false)
const pinDraft = ref('')
const lastSaved = ref({pin: ''})
const iframeKey = ref(0)
const iframeLoading = ref(true)
const showResetConfirm = ref(false)
const resetBusy = ref(false)
const previewNonce = ref(Date.now())
let fieldSaveTimer: ReturnType<typeof setTimeout> | null = null

const appUrl = computed(() => {
  const path = settings.value?.app_path
  if (!path) return ''
  return `${window.location.origin}${path}?preview=${previewNonce.value}`
})

function syncDraftsFromSettings(data: Settings) {
  pinDraft.value = data.pin || ''
  lastSaved.value = {pin: pinDraft.value}
}

function pendingFieldUpdates(): Record<string, string> {
  const payload: Record<string, string> = {}
  const pin = pinDraft.value.replace(/\D/g, '').slice(0, 6)
  if (pin.length === 6 && pin !== lastSaved.value.pin) payload.pin = pin
  return payload
}

async function loadSettings() {
  if (!eventId.value) {
    settings.value = null
    return
  }
  loading.value = true
  try {
    const {data} = await axios.get(`/events/${eventId.value}/cockpit/settings`)
    settings.value = data
    syncDraftsFromSettings(data)
    reloadPreview()
  } catch {
    showGlassToast('Cockpit Einstellungen konnten nicht geladen werden.', 'error')
  } finally {
    loading.value = false
  }
}

async function savePatch(patch: Record<string, unknown>, opts?: {reloadPreview?: boolean}) {
  if (!eventId.value) return
  saving.value?.show()
  try {
    const {data} = await axios.put(`/events/${eventId.value}/cockpit/settings`, patch)
    settings.value = data
    syncDraftsFromSettings(data)
    if (opts?.reloadPreview !== false) reloadPreview()
  } catch (e: any) {
    showGlassToast(e?.response?.data?.error || 'Speichern fehlgeschlagen.', 'error')
    await loadSettings()
  } finally {
    saving.value?.hide()
  }
}

async function onEnabledToggle(next: boolean) {
  await flushFieldSave()
  await savePatch({enabled: next}, {reloadPreview: true})
}

async function flushFieldSave(opts?: {revertIncompletePin?: boolean}) {
  if (fieldSaveTimer) {
    clearTimeout(fieldSaveTimer)
    fieldSaveTimer = null
  }
  if (!eventId.value) return

  const pin = pinDraft.value.replace(/\D/g, '').slice(0, 6)
  pinDraft.value = pin
  if (opts?.revertIncompletePin && pin !== lastSaved.value.pin && pin.length !== 6) {
    showGlassToast('PIN muss aus 6 Ziffern bestehen.', 'error')
    pinDraft.value = lastSaved.value.pin
  }

  const payload = pendingFieldUpdates()
  if (Object.keys(payload).length === 0) return

  await savePatch(payload, {reloadPreview: 'pin' in payload})
}

function scheduleFieldSave() {
  if (fieldSaveTimer) clearTimeout(fieldSaveTimer)
  fieldSaveTimer = setTimeout(() => {
    void flushFieldSave()
  }, FIELD_SAVE_DEBOUNCE_MS)
}

function onPinInput() {
  pinDraft.value = pinDraft.value.replace(/\D/g, '').slice(0, 6)
  scheduleFieldSave()
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
    await flushFieldSave()
    await axios.post(`/events/${eventId.value}/cockpit/reset`)
    showGlassToast('Auswahl für die Bühne zurückgesetzt.', 'success')
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

onBeforeUnmount(() => {
  void flushFieldSave()
})
</script>

<template>
  <div class="vol-page vol-page--fill settings-split">
    <SavingToast ref="saving" message="Wird gespeichert…"/>
    <ConfirmationModal
        :show="showResetConfirm"
        type="danger"
        title="Cockpit zurücksetzen?"
        message="Die Auswahl der Teams für „Forschung auf der Bühne“ und deren Sperren werden gelöscht. PIN und Zugang bleiben erhalten."
        confirm-text="Zurücksetzen"
        cancel-text="Abbrechen"
        :disable-confirm-button="resetBusy"
        @confirm="confirmReset"
        @cancel="showResetConfirm = false"
    />

    <header class="vol-page__header">
      <div>
        <h1 class="vol-page__title">Cockpit App</h1>
        <p class="vol-page__sub">Einstellen und Ausprobieren der Funktionen zur Steuerung der Veranstaltung</p>
      </div>
    </header>

    <div class="settings-split__workspace">
      <div class="settings-split__split">
        <section
            class="settings-split__left"
            :style="{ flex: `0 0 ${leftWidth}%` }"
        >
          <div class="settings-split__left-scroll">
            <div class="settings-split__settings">
              <p class="glass-settings-hint !mb-0 settings-split__header-hint">
                Diese Funktion ist nur vom Plan verlinkt, wenn die
                <RouterLink to="/plan/publish" class="glass-settings-hint-link">Veröffentlichung</RouterLink>
                auf „volle Details“ gesetzt ist.
              </p>

              <p v-if="settings && !settings.has_slug" class="glass-alert-warning !mb-0 !text-xs">
                Öffentlicher Link fehlt — bitte zuerst unter Ausgabe → Veröffentlichung erzeugen.
              </p>

              <section class="glass-card liquid-surface-inner settings-split__tile">
                <div class="cp-settings__access-head">
                  <h2 class="glass-card__heading !mb-0">Zugang zur App</h2>
                  <div class="cp-settings__access-toggle">
                    <span class="cp-settings__toggle-label">{{ settings?.enabled ? 'Geöffnet' : 'Geschlossen' }}</span>
                    <ToggleSwitch
                        :model-value="!!settings?.enabled"
                        :disabled="loading || !eventId || !settings?.has_slug"
                        @update:model-value="onEnabledToggle"
                    />
                  </div>
                </div>
                <div class="cp-settings__pin-row">
                  <label class="cp-settings__pin-label" for="cockpit-pin">PIN</label>
                  <input
                      id="cockpit-pin"
                      v-model="pinDraft"
                      type="text"
                      inputmode="numeric"
                      maxlength="6"
                      autocomplete="off"
                      class="glass-input cp-settings__pin-input"
                      :disabled="loading || !eventId"
                      @input="onPinInput"
                      @blur="flushFieldSave({revertIncompletePin: true})"
                  />
                </div>
              </section>

              <section class="glass-card liquid-surface-inner settings-split__tile">
                <h2 class="glass-card__heading">Alles zurücksetzen</h2>
                <p class="glass-settings-hint">
                  Alle Eingaben zurücksetzen, z.B. nach Tests.
                </p>
                <button
                    type="button"
                    class="glass-btn-secondary"
                    :disabled="loading || !eventId"
                    @click="showResetConfirm = true"
                >
                  Cockpit zurücksetzen
                </button>
              </section>
            </div>
          </div>
        </section>

        <PanelSplitter
            v-model="leftWidth"
            class="hidden md:flex settings-split__splitter"
            storage-key="flow-cockpit-split-v2"
        />

        <section class="settings-split__right">
          <MobileLivePreview
              label="Cockpit"
              :preview-url="appUrl"
              :iframe-key="iframeKey"
              :loading="iframeLoading"
              empty-text="Kein öffentlicher Link vorhanden."
              iframe-title="Vorschau Cockpit"
              aria-label="Vorschau Cockpit"
              :open-tab-url="appUrl"
              @reload="reloadPreview"
              @load="onIframeLoad"
          />
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped>
.cp-settings__toggle-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.cp-settings__access-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.cp-settings__access-toggle {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-shrink: 0;
}

.cp-settings__pin-row {
  display: flex;
  gap: 0.5rem;
  align-items: center;
}

.cp-settings__pin-label {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text-muted);
  flex-shrink: 0;
}

.cp-settings__pin-input {
  font-size: 1.25rem;
  letter-spacing: 0.2em;
  font-variant-numeric: tabular-nums;
  max-width: 9rem;
  text-align: center;
}
</style>
