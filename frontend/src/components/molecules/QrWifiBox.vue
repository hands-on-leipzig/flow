<script lang="ts" setup>
import {computed, onActivated, onBeforeUnmount, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import QRCode from 'qrcode'
import {useEventStore} from '@/stores/event'
import {usePdfExport} from '@/composables/usePdfExport'
import FllEvent from '@/models/FllEvent'

const props = withDefaults(
  defineProps<{
    /** Skip outer card chrome when parent provides the panel. */
    embed?: boolean
    /**
     * all — both columns (DuringEventBox)
     * plan — Online-Plan QR/PDF only
     * wifi — credentials + QR (WLAN page; no PDF)
     * print — Online-Plan + WLAN PDF posters (Analog)
     */
    section?: 'all' | 'plan' | 'wifi' | 'print'
  }>(),
  {embed: false, section: 'all'}
)

const showPlan = computed(
  () => props.section === 'all' || props.section === 'plan' || props.section === 'print'
)
const showWifiForm = computed(() => props.section === 'all' || props.section === 'wifi')
const showWifiQr = computed(() => props.section === 'all' || props.section === 'wifi')
const showWifiPdf = computed(() => props.section === 'all' || props.section === 'print')
const showWifi = computed(() => showWifiForm.value || showWifiQr.value || showWifiPdf.value)
const singleSection = computed(() => props.section === 'plan' || props.section === 'wifi')
/** Page already provides chrome + title — no nested card/headers. */
const flat = computed(() => props.embed && singleSection.value)

// === Store & Basis ===
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)
const loadingWifiQr = ref(false)
/** Live preview from current SSID/password (always preferred over stored QR). */
const localWifiQr = ref('')
const lastSavedWifi = ref({
  wifi_ssid: '' as string,
  wifi_password: '' as string,
  wifi_instruction: '' as string,
})
let wifiSaveTimer: ReturnType<typeof setTimeout> | null = null
const WIFI_SAVE_DEBOUNCE_MS = 450

// === PDF Download (neu über Composable) ===
const {isDownloading, anyDownloading, downloadPdf} = usePdfExport()

// === QR ===
function toDataUrl(raw: string | null | undefined): string {
  if (!raw) return ''
  return raw.startsWith('data:') ? raw : `data:image/png;base64,${raw}`
}

const qrWifiUrl = computed(() => localWifiQr.value || toDataUrl(event.value?.wifi_qrcode))

function syncLastSavedFromEvent() {
  lastSavedWifi.value = {
    wifi_ssid: event.value?.wifi_ssid || '',
    wifi_password: event.value?.wifi_password || '',
    wifi_instruction: event.value?.wifi_instruction || '',
  }
}

/** Rebuild preview QR from the form values (SSID / password). */
async function regenerateWifiQr() {
  const ssid = event.value?.wifi_ssid?.trim()
  if (!ssid) {
    localWifiQr.value = ''
    return
  }
  try {
    const password = event.value?.wifi_password || ''
    const content = password
      ? `WIFI:T:WPA;S:${ssid};P:${password};;`
      : `WIFI:T:nopass;S:${ssid};;`
    localWifiQr.value = await QRCode.toDataURL(content, {
      width: 300,
      margin: 2,
      errorCorrectionLevel: 'M',
    })
  } catch (e) {
    console.error('WLAN-QR konnte nicht erzeugt werden:', e)
    localWifiQr.value = ''
  }
}

async function refreshEventFromApi() {
  if (!eventId.value) return
  try {
    const {data} = await axios.get(`/events/${eventId.value}`)
    eventStore.selectedEvent = new FllEvent(data)
    syncLastSavedFromEvent()
  } catch (e) {
    console.error('Event für QR/WLAN konnte nicht geladen werden:', e)
  }
  await regenerateWifiQr()
}

// === Preview-URLs ===
const previewPlan = ref<string | null>(null)
const previewPlanWifi = ref<string | null>(null)

// === Previews laden ===
async function loadPreview(type: 'plan' | 'plan_wifi') {
  if (!event.value?.id) return
  try {
    const timestamp = new Date().getTime() // gegen Cache
    const {data} = await axios.get(`/publish/pdf_preview/${type}/${event.value.id}?_=${timestamp}`)
    if (type === 'plan') previewPlan.value = data
    else previewPlanWifi.value = data
  } catch (e) {
    console.error(`Fehler beim Laden der Preview für ${type}:`, e)
  }
}

function wifiFieldValue(field: keyof typeof lastSavedWifi.value): string {
  return event.value?.[field] || ''
}

function pendingWifiUpdates(): Record<string, string> {
  const payload: Record<string, string> = {}
  ;(['wifi_ssid', 'wifi_password', 'wifi_instruction'] as const).forEach((field) => {
    const next = wifiFieldValue(field)
    if (next !== lastSavedWifi.value[field]) {
      payload[field] = next
    }
  })
  return payload
}

/** Persist all dirty WLAN fields; regenerates server QR when SSID/password changed. */
async function flushWifiSave() {
  if (wifiSaveTimer) {
    clearTimeout(wifiSaveTimer)
    wifiSaveTimer = null
  }
  if (!eventId.value || !event.value) return

  const payload = pendingWifiUpdates()
  if (Object.keys(payload).length === 0) return

  const touchedCredentials = 'wifi_ssid' in payload || 'wifi_password' in payload

  try {
    if (touchedCredentials) loadingWifiQr.value = true
    await axios.put(`/events/${eventId.value}`, payload)
    const {data} = await axios.get(`/events/${eventId.value}`)
    eventStore.selectedEvent = new FllEvent(data)
    syncLastSavedFromEvent()
    await regenerateWifiQr()

    if (showWifiPdf.value) {
      await loadPreview('plan_wifi')
    }
  } catch (e) {
    console.error('Fehler beim Aktualisieren:', e)
  } finally {
    loadingWifiQr.value = false
  }
}

function scheduleWifiSave() {
  if (wifiSaveTimer) clearTimeout(wifiSaveTimer)
  wifiSaveTimer = setTimeout(() => {
    void flushWifiSave()
  }, WIFI_SAVE_DEBOUNCE_MS)
}

function onWifiCredentialsInput() {
  void regenerateWifiQr()
  scheduleWifiSave()
}

function onWifiInstructionInput() {
  scheduleWifiSave()
}

// === PNG-Download für QR ===
async function downloadPng(dataUrl: string, filename: string) {
  const a = document.createElement('a')
  a.href = dataUrl
  a.download = filename
  a.click()
}

async function boot() {
  await refreshEventFromApi()
  if (showPlan.value) void loadPreview('plan')
  if (showWifiPdf.value) void loadPreview('plan_wifi')
}

onMounted(() => {
  void boot()
})

// keep-alive: refresh when returning to this pane
onActivated(() => {
  void boot()
})

onBeforeUnmount(() => {
  void flushWifiSave()
})

watch(
  () => [event.value?.wifi_ssid, event.value?.wifi_password] as const,
  () => {
    void regenerateWifiQr()
  }
)
</script>

<template>
  <div :class="embed ? 'qr-wifi qr-wifi--embed' : 'glass-card liquid-surface-inner p-3 qr-wifi'">
    <div :class="['qr-wifi__grid', singleSection && 'qr-wifi__grid--single']">
      <!-- Plan QR -->
      <section v-if="showPlan" :class="flat ? 'qr-wifi__flat' : 'qr-wifi__col'">
        <header v-if="!flat" class="qr-wifi__col-head">
          <h3 class="qr-wifi__col-title">
            <i class="bi bi-qr-code" aria-hidden="true"/>
            Online-Plan
          </h3>
          <p class="qr-wifi__col-sub">
            QR enthält den öffentlichen Link — zum Scannen vor Ort.
          </p>
        </header>

        <div class="qr-wifi__exports">
          <div class="qr-wifi__export">
            <img
                v-if="event?.qrcode"
                :src="`data:image/png;base64,${event.qrcode}`"
                alt="QR Plan"
                class="qr-wifi__thumb"
            />
            <div v-else class="qr-wifi__placeholder">Kein QR</div>
            <button
                v-if="event?.qrcode"
                type="button"
                class="glass-btn-secondary !px-3 !py-1 !text-sm"
                @click="downloadPng(`data:image/png;base64,${event.qrcode}`, 'FLOW_QR_Code_Plan.png')"
            >
              PNG
            </button>
          </div>

          <div class="qr-wifi__export">
            <img
                v-if="previewPlan"
                :src="previewPlan"
                alt="Preview Plan-QR als PDF"
                class="qr-wifi__preview"
            />
            <div v-else class="qr-wifi__placeholder">Preview</div>
            <button
                type="button"
                :disabled="isDownloading.plan"
                class="glass-btn-secondary !px-3 !py-1 !text-sm inline-flex items-center gap-2"
                @click="downloadPdf('plan', `/publish/pdf_download/plan/${eventId}`, 'Plan.pdf')"
            >
              <svg v-if="isDownloading.plan" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"/>
              </svg>
              <span>{{ isDownloading.plan ? 'Erzeuge…' : 'PDF' }}</span>
            </button>
          </div>
        </div>
      </section>

      <!-- WLAN -->
      <section v-if="showWifi" :class="flat ? 'qr-wifi__flat' : 'qr-wifi__col'">
        <header v-if="!flat" class="qr-wifi__col-head">
          <h3 class="qr-wifi__col-title">
            <i class="bi bi-wifi" aria-hidden="true"/>
            WLAN-Zugang
          </h3>
          <p v-if="showWifiForm" class="qr-wifi__col-sub">
            Netzwerke mit Schlüssel. Bei Web-Login nur SSID setzen — Rest in den Hinweisen.
          </p>
          <p v-else class="qr-wifi__col-sub">
            Druckposter mit Netzwerkdaten — Zugang unter WLAN vor Ort pflegen.
          </p>
        </header>

        <!-- Compact exports first when not on the dedicated WLAN page -->
        <div
            v-if="!flat && (showWifiQr || showWifiPdf)"
            :class="['qr-wifi__exports', showWifiForm && 'qr-wifi__exports--first']"
        >
          <div v-if="showWifiQr" class="qr-wifi__export">
            <template v-if="!event?.wifi_ssid">
              <div class="qr-wifi__placeholder">SSID fehlt</div>
            </template>
            <template v-else-if="loadingWifiQr">
              <div class="qr-wifi__placeholder">…</div>
            </template>
            <template v-else-if="qrWifiUrl">
              <img :src="qrWifiUrl" alt="QR Wifi" class="qr-wifi__thumb"/>
              <button
                  type="button"
                  class="glass-btn-secondary !px-3 !py-1 !text-sm"
                  @click="downloadPng(qrWifiUrl, 'FLOW_QR_Code_WLAN.png')"
              >
                PNG
              </button>
            </template>
            <template v-else>
              <div class="qr-wifi__placeholder">Kein QR</div>
            </template>
          </div>

          <div v-if="showWifiPdf" class="qr-wifi__export">
            <template v-if="!event?.wifi_ssid">
              <div class="qr-wifi__placeholder">SSID fehlt</div>
            </template>
            <template v-else-if="loadingWifiQr">
              <div class="qr-wifi__placeholder">…</div>
            </template>
            <template v-else-if="previewPlanWifi">
              <img
                  :src="previewPlanWifi"
                  alt="Preview WLAN-PDF"
                  class="qr-wifi__preview"
              />
            </template>
            <template v-else>
              <div class="qr-wifi__placeholder">Preview</div>
            </template>

            <button
                type="button"
                :disabled="isDownloading.plan_wifi"
                class="glass-btn-secondary !px-3 !py-1 !text-sm inline-flex items-center gap-2"
                @click="downloadPdf('plan_wifi', `/publish/pdf_download/plan_wifi/${eventId}`, 'Plan_WLAN.pdf')"
            >
              <svg v-if="isDownloading.plan_wifi" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"/>
              </svg>
              <span>{{ isDownloading.plan_wifi ? 'Erzeuge…' : 'PDF' }}</span>
            </button>
          </div>
        </div>

        <div
            v-if="event && showWifiForm"
            class="qr-wifi__fields"
            :class="flat && 'qr-wifi__fields--flat'"
        >
          <div class="qr-wifi__field">
            <label class="qr-wifi__label" for="wifi-ssid">SSID</label>
            <input
                id="wifi-ssid"
                v-model="event.wifi_ssid"
                class="glass-input glass-input--sm liquid-surface-control w-full"
                placeholder="z. B. TH_EVENT_WLAN"
                type="text"
                @input="onWifiCredentialsInput"
                @blur="flushWifiSave"
            />
          </div>
          <p class="qr-wifi__pw-hint">
            Bei Web-Login Passwort leer lassen — Anleitung in die Hinweise.
          </p>
          <div class="qr-wifi__field">
            <label class="qr-wifi__label" for="wifi-password">Passwort</label>
            <input
                id="wifi-password"
                v-model="event.wifi_password"
                class="glass-input glass-input--sm liquid-surface-control w-full"
                placeholder="z. B. $N#Uh)eA~ado]tyMXTkG"
                type="text"
                @input="onWifiCredentialsInput"
                @blur="flushWifiSave"
            />
          </div>
          <div class="qr-wifi__field qr-wifi__field--top">
            <label class="qr-wifi__label" for="wifi-hint">Hinweise</label>
            <textarea
                id="wifi-hint"
                v-model="event.wifi_instruction"
                class="glass-input glass-input--sm liquid-surface-control w-full"
                placeholder="z. B. Code 'FLL' eingeben und Nutzungsbedingungen akzeptieren."
                rows="2"
                @input="onWifiInstructionInput"
                @blur="flushWifiSave"
            />
          </div>
        </div>

        <!-- Flat WLAN page: large centered QR -->
        <div v-if="flat && showWifiQr" class="qr-wifi__hero">
          <template v-if="!event?.wifi_ssid">
            <div class="qr-wifi__placeholder qr-wifi__placeholder--lg">SSID fehlt</div>
          </template>
          <template v-else-if="loadingWifiQr">
            <div class="qr-wifi__placeholder qr-wifi__placeholder--lg">…</div>
          </template>
          <template v-else-if="qrWifiUrl">
            <img :src="qrWifiUrl" alt="QR Wifi" class="qr-wifi__hero-qr"/>
            <button
                type="button"
                class="glass-btn-secondary !px-3 !py-1 !text-sm"
                @click="downloadPng(qrWifiUrl, 'FLOW_QR_Code_WLAN.png')"
            >
              PNG
            </button>
          </template>
          <template v-else>
            <div class="qr-wifi__placeholder qr-wifi__placeholder--lg">Kein QR</div>
          </template>
        </div>
      </section>
    </div>

    <div
        v-if="anyDownloading"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/20"
    >
      <div class="glass-row-item inline-flex px-4 py-3 gap-2">
        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"/>
        </svg>
        <span>PDF wird erzeugt…</span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.qr-wifi--embed {
  padding: 0;
}

.qr-wifi__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1rem;
}

@media (min-width: 900px) {
  .qr-wifi__grid:not(.qr-wifi__grid--single) {
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
  }
}

.qr-wifi__col {
  min-width: 0;
  padding: 0.95rem 1rem 1.1rem;
  border-radius: 12px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 28%, #fff);
}

.qr-wifi__flat {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.qr-wifi:not(.qr-wifi--embed) .qr-wifi__col {
  background: #fff;
}

.qr-wifi__col-head {
  margin-bottom: 0.9rem;
}

.qr-wifi__col-title {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 0.45rem;
  font-size: 0.98rem;
  font-weight: 750;
  letter-spacing: -0.015em;
}

.qr-wifi__col-title .bi {
  color: var(--color-accent);
}

.qr-wifi__col-sub {
  margin: 0.3rem 0 0;
  font-size: 0.8rem;
  color: var(--color-text-muted);
  line-height: 1.4;
}

.qr-wifi__fields {
  display: flex;
  flex-direction: column;
  gap: 0.55rem;
  margin-bottom: 0;
}

.qr-wifi__fields--flat {
  gap: 0.7rem;
}

.qr-wifi__pw-hint {
  margin: 0;
  grid-column: 1 / -1;
  padding: 0.15rem 0 0.15rem 4.5rem;
  font-size: 0.78rem;
  line-height: 1.35;
  color: var(--color-text-muted);
}

.qr-wifi__fields--flat .qr-wifi__pw-hint {
  padding-left: 4.5rem;
}

.qr-wifi__field {
  display: grid;
  grid-template-columns: 4.5rem 1fr;
  align-items: center;
  gap: 0.55rem;
}

.qr-wifi__field--top {
  align-items: start;
}

.qr-wifi__label {
  font-size: 0.78rem;
  font-weight: 650;
  color: var(--color-text-muted);
  padding-top: 0.35rem;
}

.qr-wifi__hero {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding-top: 0.35rem;
}

.qr-wifi__hero-qr {
  width: 13rem;
  height: 13rem;
  object-fit: contain;
}

.qr-wifi__exports {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-around;
  gap: 0.75rem 1rem;
}

.qr-wifi__exports--first {
  margin-bottom: 0.95rem;
  padding-bottom: 0.9rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
}

.qr-wifi__export {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.45rem;
  min-width: 5.5rem;
}

.qr-wifi__thumb {
  width: 5rem;
  height: 5rem;
  object-fit: contain;
}

.qr-wifi__preview {
  height: 5rem;
  width: auto;
  max-width: 7rem;
  object-fit: contain;
  border-radius: 6px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: #fff;
}

.qr-wifi__placeholder {
  width: 5rem;
  height: 5rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px dashed color-mix(in srgb, var(--color-border-strong) 45%, transparent);
  border-radius: 8px;
  color: var(--color-text-subtle);
  font-size: 0.72rem;
  text-align: center;
  padding: 0.35rem;
}

.qr-wifi__placeholder--lg {
  width: 13rem;
  height: 13rem;
  font-size: 0.85rem;
}
</style>