<script setup lang="ts">
/**
 * Ausgabe → Verteilung
 * Controls left · live iframe of the public page right
 */
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import SavingToast from '@/components/atoms/SavingToast.vue'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'PublishDistribution'})

const eventStore = useEventStore()
const {isAdmin} = useAuth()
const event = computed(() => eventStore.selectedEvent)

const saving = ref<{show: (ms?: number) => void; hide: () => void} | null>(null)
const regenerating = ref(false)
const detailLevel = ref(0)
const showQr = ref(false)
const iframeKey = ref(0)
const iframeLoading = ref(true)

const levels = [
  {id: 0, short: 'Basis', name: 'Planung und Anmeldung', hint: 'Datum, Ort, Kontakt, Teams'},
  {id: 1, short: 'Ablauf', name: 'Überblick zum Ablauf', hint: '+ wichtige Zeiten'},
  {id: 2, short: 'Alles', name: 'volle Details', hint: '+ Online-Zeitplan'},
]

const qrSrc = computed(() => {
  const raw = event.value?.qrcode
  if (!raw) return null
  return raw.startsWith('data:') ? raw : `data:image/png;base64,${raw}`
})

const publicUrl = computed(() => event.value?.link || '')

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

async function copyLink() {
  const link = publicUrl.value
  if (!link) return
  try {
    await navigator.clipboard.writeText(link)
    showGlassToast('Link kopiert', 'success')
  } catch {
    showGlassToast('Link konnte nicht kopiert werden', 'error')
  }
}

async function regenerateLinkAndQR() {
  if (!event.value?.id) return
  try {
    regenerating.value = true
    const {data} = await axios.post(`/publish/regenerate/${event.value.id}`)
    const baseUrl = import.meta.env.VITE_APP_URL || window.location.origin
    if (eventStore.selectedEvent) {
      eventStore.selectedEvent.link = `${baseUrl}/${data.link}`
      eventStore.selectedEvent.qrcode = data.qrcode.replace('data:image/png;base64,', '')
      eventStore.selectedEvent.slug = data.link
    }
    showGlassToast('Link und QR neu erzeugt', 'success')
    reloadPreview()
  } catch {
    showGlassToast('Neu erzeugen fehlgeschlagen', 'error')
  } finally {
    regenerating.value = false
  }
}

function openPublic() {
  if (publicUrl.value) window.open(publicUrl.value, '_blank', 'noopener')
}

function onIframeLoad() {
  iframeLoading.value = false
}

watch(
    () => event.value?.id,
    async (id) => {
      if (!id) return
      await fetchPublicationLevel()
      reloadPreview()
    }
)

onMounted(async () => {
  if (event.value?.id) {
    await fetchPublicationLevel()
    reloadPreview()
  }
})
</script>

<template>
  <SavingToast ref="saving" message="Sichtbarkeit wird gespeichert…" />

  <div class="pub">
    <div class="pub__shell">
      <aside class="pub__controls">
        <header class="pub__controls-head">
          <h1 class="pub__title">Verteilung</h1>
          <p class="pub__lede">Link freigeben · Sichtbarkeit steuern</p>
        </header>

        <div class="pub__section">
          <div class="pub__label-row">
            <span class="pub__label">Link</span>
            <button
                v-if="qrSrc"
                type="button"
                class="pub__qr-toggle"
                :aria-expanded="showQr"
                @click="showQr = !showQr"
            >
              <i class="bi bi-qr-code" aria-hidden="true"/>
              QR
            </button>
          </div>

          <div class="pub__urlbar">
            <i class="bi bi-link-45deg pub__urlbar-icon" aria-hidden="true"/>
            <a
                v-if="publicUrl"
                :href="publicUrl"
                target="_blank"
                rel="noopener"
                class="pub__url"
                :title="publicUrl"
            >{{ publicUrl }}</a>
            <span v-else class="pub__url pub__url--empty">Kein Link</span>
          </div>

          <div class="pub__btn-row">
            <button
                v-if="publicUrl"
                type="button"
                class="pub__btn pub__btn--primary"
                @click="copyLink"
            >
              <i class="bi bi-clipboard" aria-hidden="true"/>
              Kopieren
            </button>
            <button
                v-if="publicUrl"
                type="button"
                class="pub__btn"
                @click="openPublic"
            >
              <i class="bi bi-box-arrow-up-right" aria-hidden="true"/>
              Öffnen
            </button>
            <button
                v-if="isAdmin && event?.id"
                type="button"
                class="pub__btn pub__btn--ghost"
                :disabled="regenerating"
                @click="regenerateLinkAndQR"
            >
              <i class="bi bi-arrow-repeat" aria-hidden="true"/>
              {{ regenerating ? '…' : 'Neu' }}
            </button>
          </div>

          <div v-if="showQr && qrSrc" class="pub__qr-box">
            <img :src="qrSrc" alt="QR-Code zur öffentlichen Seite" class="pub__qr"/>
          </div>
        </div>

        <div class="pub__section">
          <span class="pub__label">Sichtbarkeit</span>
          <div class="pub__levels" role="radiogroup" aria-label="Sichtbarkeitsstufe">
            <button
                v-for="level in levels"
                :key="level.id"
                type="button"
                role="radio"
                class="pub__level"
                :class="{'is-active': detailLevel === level.id}"
                :aria-checked="detailLevel === level.id"
                @click="setDetailLevel(level.id)"
            >
              <span class="pub__level-mark" aria-hidden="true">
                <i v-if="detailLevel === level.id" class="bi bi-check2"/>
                <span v-else>{{ level.id + 1 }}</span>
              </span>
              <span class="pub__level-copy">
                <span class="pub__level-name">{{ level.name }}</span>
                <span class="pub__level-hint">{{ level.hint }}</span>
              </span>
            </button>
          </div>
        </div>
      </aside>

      <section class="pub__preview" aria-label="Live-Vorschau der öffentlichen Seite">
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
              :src="publicUrl"
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
  min-height: min(72vh, 48rem);
  padding-bottom: max(0.75rem, env(safe-area-inset-bottom));
}

.pub__shell {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.85rem;
  align-items: stretch;
  min-height: min(72vh, 48rem);
}

@media (min-width: 960px) {
  .pub__shell {
    grid-template-columns: minmax(17rem, 20.5rem) minmax(0, 1fr);
    gap: 1rem;
  }
}

.pub__controls {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  padding: 1.1rem 1.15rem 1.25rem;
  border-radius: 0.85rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 55%, #fff);
}

.pub__title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 750;
  letter-spacing: -0.025em;
}

.pub__lede {
  margin: 0.2rem 0 0;
  font-size: 0.84rem;
  color: var(--color-text-muted);
}

.pub__section {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
}

.pub__label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
}

.pub__label {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-text-subtle);
}

.pub__qr-toggle {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.2rem 0.45rem;
  border-radius: 0.35rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: #fff;
  font-size: 0.75rem;
  font-weight: 650;
  color: var(--color-text-muted);
  cursor: pointer;
}

.pub__qr-toggle:hover { color: var(--color-text); }

.pub__urlbar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  padding: 0.55rem 0.65rem;
  border-radius: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: #fff;
}

.pub__urlbar-icon {
  flex-shrink: 0;
  color: var(--color-text-subtle);
}

.pub__url {
  min-width: 0;
  flex: 1;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-text);
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pub__url:hover { color: var(--color-accent); }

.pub__url--empty {
  color: var(--color-text-muted);
  font-weight: 500;
}

.pub__btn-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.pub__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0.7rem;
  border-radius: 0.45rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  background: #fff;
  font-size: 0.8rem;
  font-weight: 650;
  color: var(--color-text);
  text-decoration: none;
  cursor: pointer;
}

.pub__btn:hover {
  border-color: color-mix(in srgb, var(--color-border-strong) 65%, transparent);
}

.pub__btn--primary {
  background: var(--color-accent);
  border-color: var(--color-accent);
  color: var(--color-on-accent, #fff);
}

.pub__btn--primary:hover { filter: brightness(1.04); }

.pub__btn--ghost {
  background: transparent;
  color: var(--color-text-muted);
}

.pub__btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.pub__qr-box {
  display: flex;
  justify-content: center;
  padding: 0.65rem;
  border-radius: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
  background: #fff;
}

.pub__qr {
  width: 7.5rem;
  height: 7.5rem;
  object-fit: contain;
}

.pub__levels {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.pub__level {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  width: 100%;
  text-align: left;
  padding: 0.65rem 0.7rem;
  border-radius: 0.55rem;
  border: 1px solid transparent;
  background: transparent;
  cursor: pointer;
}

.pub__level:hover {
  background: #fff;
  border-color: color-mix(in srgb, var(--color-border-strong) 30%, transparent);
}

.pub__level.is-active {
  background: #fff;
  border-color: color-mix(in srgb, var(--color-accent) 45%, transparent);
}

.pub__level-mark {
  flex-shrink: 0;
  width: 1.45rem;
  height: 1.45rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.35rem;
  font-size: 0.75rem;
  font-weight: 750;
  color: var(--color-text-muted);
  background: #fff;
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

.pub__level-hint {
  font-size: 0.76rem;
  color: var(--color-text-muted);
  line-height: 1.3;
}

.pub__preview {
  display: flex;
  flex-direction: column;
  min-height: 28rem;
  border-radius: 0.85rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, transparent);
  background: #fff;
  overflow: hidden;
}

@media (min-width: 960px) {
  .pub__preview {
    min-height: 0;
    height: 100%;
  }
}

.pub__preview-bar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.55rem 0.85rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 20%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 65%, #fff);
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
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.2rem 0.5rem;
  border: 0;
  background: none;
  font-size: 0.75rem;
  font-weight: 700;
  color: var(--color-accent);
  cursor: pointer;
}

.pub__preview-open:hover { text-decoration: underline; }

.pub__frame-wrap {
  position: relative;
  flex: 1;
  min-height: 24rem;
  background: color-mix(in srgb, var(--color-bg-muted) 40%, #fff);
}

@media (min-width: 960px) {
  .pub__frame-wrap {
    min-height: 0;
  }
}

.pub__frame {
  display: block;
  width: 100%;
  height: 100%;
  min-height: 24rem;
  border: 0;
  background: #fff;
}

@media (min-width: 960px) {
  .pub__frame {
    position: absolute;
    inset: 0;
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
  background: color-mix(in srgb, var(--color-bg-muted) 50%, #fff);
  z-index: 1;
}

.pub__frame-loading {
  pointer-events: none;
}
</style>
