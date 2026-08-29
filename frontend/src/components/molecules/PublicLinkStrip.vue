<script setup lang="ts">
import {computed, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import {RouterLink} from 'vue-router'
import {useEventStore} from '@/stores/event'
import {useAnchoredPanel} from '@/composables/useAnchoredPanel'
import {useInfoPopover} from '@/composables/useInfoPopover'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'

defineOptions({name: 'PublicLinkStrip'})

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const linkLoading = ref(false)
const showQrModal = ref(false)

const popoverId = 'public-link-strip-help'
const {toggle, isOpen, close} = useInfoPopover()
const helpOpen = computed(() => isOpen(popoverId))
const helpButtonRef = ref<HTMLElement | null>(null)

const {panelRef, panelStyle} = useAnchoredPanel({
  isOpen: helpOpen,
  anchor: helpButtonRef,
  fallbackWidth: 288,
  fallbackHeight: 120,
  onClose: close,
})

function normalizeLink(raw: string | null | undefined): string {
  if (!raw) return ''
  if (/^https?:\/\//i.test(raw)) return raw
  const base = (import.meta.env.VITE_APP_URL || window.location.origin).replace(/\/$/, '')
  return `${base}/${raw.replace(/^\//, '')}`
}

function applyPublishResponse(data: {link?: string; qrcode?: string}) {
  if (!eventStore.selectedEvent) return
  if (data.link) {
    eventStore.selectedEvent.link = normalizeLink(data.link)
  }
  if (data.qrcode) {
    eventStore.selectedEvent.qrcode = data.qrcode.replace(/^data:image\/png;base64,/, '')
  }
}

async function ensurePublicLink() {
  const id = event.value?.id
  if (!id) return

  if (event.value?.link && event.value?.qrcode) return

  linkLoading.value = true
  try {
    const {data} = await axios.get(`/publish/link/${id}`)
    applyPublishResponse(data)
  } catch {
    showGlassToast('Öffentlicher Link konnte nicht geladen werden.', 'error')
  } finally {
    linkLoading.value = false
  }
}

const publicUrl = computed(() => normalizeLink(event.value?.link))

const qrSrc = computed(() => {
  const raw = event.value?.qrcode
  if (!raw) return null
  return raw.startsWith('data:') ? raw : `data:image/png;base64,${raw}`
})

const qrFilename = computed(() => flowFilename('QR_Code', 'png', event.value?.date))

const actionsDisabled = computed(() => linkLoading.value || !publicUrl.value)

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

function downloadQr() {
  if (!qrSrc.value) return
  const a = document.createElement('a')
  a.href = qrSrc.value
  a.download = qrFilename.value
  a.click()
}

function openQrModal() {
  if (!qrSrc.value || actionsDisabled.value) return
  showQrModal.value = true
}

function toggleHelp() {
  toggle(popoverId)
}

watch(
    () => event.value?.id,
    (id) => {
      if (id) void ensurePublicLink()
    },
)

onMounted(() => {
  void ensurePublicLink()
})
</script>

<template>
  <div class="glass-card liquid-surface-inner public-link-strip">
    <div class="public-link-strip__row">
      <div class="public-link-strip__label-group shrink-0">
        <h2 class="glass-card__title !mb-0">Öffentlicher Link</h2>
        <button
            ref="helpButtonRef"
            type="button"
            class="public-link-strip__info"
            title="Mehr Informationen"
            aria-label="Mehr Informationen zum öffentlichen Link"
            @click.stop="toggleHelp"
        >
          ⓘ
        </button>
      </div>

      <a
          v-if="publicUrl && !linkLoading"
          :href="publicUrl"
          target="_blank"
          rel="noopener noreferrer"
          class="flex items-center gap-2 rounded-lg px-3 py-1.5 liquid-surface-inner hover:bg-[var(--color-bg-hover)] transition-colors no-underline text-inherit min-w-0 flex-1"
          :title="publicUrl"
      >
        <div class="min-w-0 flex-1">
          <div class="font-medium flex items-center justify-between gap-2">
            <span class="min-w-0 truncate">{{ publicUrl }}</span>
            <i class="bi bi-chevron-right text-[var(--color-text-subtle)] shrink-0" aria-hidden="true"/>
          </div>
        </div>
      </a>
      <div
          v-else
          class="flex items-center gap-2 rounded-lg px-3 py-1.5 liquid-surface-inner min-w-0 flex-1"
      >
        <span class="text-sm text-[var(--color-text-subtle)]">
          {{ linkLoading ? 'Link wird erzeugt…' : 'Kein Link verfügbar' }}
        </span>
      </div>

      <div class="public-link-strip__actions shrink-0">
        <button
            type="button"
            class="glass-btn-secondary public-link-strip__icon-btn"
            aria-label="Link kopieren"
            title="Link kopieren"
            :disabled="actionsDisabled"
            @click="copyLink"
        >
          <i class="bi bi-clipboard" aria-hidden="true"/>
        </button>
        <button
            type="button"
            class="glass-btn-secondary public-link-strip__icon-btn"
            aria-label="QR-Code anzeigen"
            title="QR-Code anzeigen"
            :disabled="actionsDisabled || !qrSrc"
            @click="openQrModal"
        >
          <i class="bi bi-qr-code" aria-hidden="true"/>
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
          v-if="helpOpen"
          ref="panelRef"
          class="public-link-strip__help-panel"
          :style="panelStyle"
          role="tooltip"
      >
        Dieser Link führt zum öffentlichen Zeitplan. Was angezeigt wird, wird unter
        <RouterLink
            to="/plan/publish"
            class="public-link-strip__help-link"
            @click="close"
        >Ausgabe → Veröffentlichung</RouterLink>
        festgelegt. Der Link bleibt unverändert — er sollte immer verwendet werden, wenn es um Ablauf und Zeiten geht,
        damit niemand veraltete Informationen erhält.
      </div>
    </Teleport>

    <Teleport to="body">
      <div
          v-if="showQrModal && qrSrc"
          class="glass-scrim fixed inset-0 flex items-center justify-center z-50 p-4"
          @click="showQrModal = false"
      >
        <div class="public-link-strip__qr-modal" @click.stop>
          <div class="public-link-strip__qr-head">
            <button
                type="button"
                class="public-link-strip__qr-close"
                aria-label="Schließen"
                @click="showQrModal = false"
            >
              <i class="bi bi-x-lg" aria-hidden="true"/>
            </button>
          </div>
          <div class="public-link-strip__qr-body">
            <img :src="qrSrc" alt="QR-Code zum öffentlichen Zeitplan" class="public-link-strip__qr-img"/>
            <button
                type="button"
                class="glass-btn-secondary public-link-strip__icon-btn"
                aria-label="QR-Code herunterladen"
                title="QR-Code herunterladen"
                @click="downloadQr"
            >
              <i class="bi bi-download" aria-hidden="true"/>
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.public-link-strip {
  padding: 0.55rem 0.75rem;
}

.public-link-strip__row {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  min-width: 0;
}

.public-link-strip__label-group {
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.public-link-strip__info {
  border: none;
  background: transparent;
  padding: 0;
  line-height: 1;
  font-size: 0.9rem;
  color: var(--color-text-subtle);
  cursor: pointer;
}

.public-link-strip__info:hover {
  color: var(--color-accent);
}

.public-link-strip__actions {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.public-link-strip__icon-btn {
  padding: 0.4rem 0.5rem !important;
  line-height: 1;
}

.public-link-strip__icon-btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.public-link-strip__help-panel {
  z-index: 4000;
  width: 18rem;
  max-width: calc(100vw - 1rem);
  padding: 0.55rem 0.7rem;
  border-radius: 8px;
  border: 1px solid var(--color-border);
  background: #fff;
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
  font-size: 0.875rem;
  line-height: 1.45;
  color: var(--color-text-muted);
}

.public-link-strip__help-link {
  color: var(--color-accent);
  font-weight: 600;
  text-decoration: none;
}

.public-link-strip__help-link:hover {
  text-decoration: underline;
}

.public-link-strip__qr-modal {
  background: #fff;
  border-radius: 12px;
  padding: 0.75rem 0.75rem 1rem;
  max-width: 20rem;
  width: 100%;
}

.public-link-strip__qr-head {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 0.25rem;
}

.public-link-strip__qr-close {
  border: none;
  background: transparent;
  color: var(--color-text-subtle);
  padding: 0.25rem;
  cursor: pointer;
  line-height: 1;
}

.public-link-strip__qr-close:hover {
  color: var(--color-text-muted);
}

.public-link-strip__qr-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
}

.public-link-strip__qr-img {
  width: 200px;
  height: 200px;
  object-fit: contain;
}
</style>
