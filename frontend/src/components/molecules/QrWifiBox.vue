<script lang="ts" setup>
import {computed, onActivated, onMounted, ref, watch} from 'vue'
import axios from 'axios'
import QRCode from 'qrcode'
import {useEventStore} from '@/stores/event'
import {usePdfExport} from '@/composables/usePdfExport'
import FllEvent from '@/models/FllEvent'

withDefaults(
  defineProps<{
    /** Skip outer card chrome when parent provides the panel. */
    embed?: boolean
  }>(),
  {embed: false}
)

// === Store & Basis ===
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)
const loadingWifiQr = ref(false)
/** Client-side fallback when the API event has SSID but no stored wifi_qrcode yet. */
const localWifiQr = ref('')

// === Password Management ===
const showPassword = ref(false)
const passwordInput = ref<string>('')
const originalPassword = ref<string>('')

// Watch for event changes to update password value
watch(() => event.value?.wifi_password, (newPassword) => {
  if (newPassword !== undefined && newPassword !== null && newPassword !== '') {
    // Backend should always return decrypted password, but if we see encrypted format, fetch fresh
    // Laravel encrypted strings start with "eyJ" (base64 JSON)
    if (newPassword.startsWith('eyJ') && eventId.value) {
      // Password appears encrypted, fetch decrypted version
      axios.get(`/events/${eventId.value}`).then(({data}) => {
        if (data.wifi_password && !data.wifi_password.startsWith('eyJ')) {
          originalPassword.value = data.wifi_password
          if (showPassword.value) {
            passwordInput.value = data.wifi_password
          }
        }
      }).catch(() => {
        // Fallback to what we have
        originalPassword.value = newPassword
      })
    } else {
      // Already decrypted
      originalPassword.value = newPassword
    }

    // If password exists, show asterisks by default (hidden)
    if (!showPassword.value) {
      passwordInput.value = '*****'
    } else {
      passwordInput.value = originalPassword.value
    }
  } else {
    originalPassword.value = ''
    passwordInput.value = ''
  }
}, {immediate: true})

const hasPassword = computed(() => {
  return !!originalPassword.value && originalPassword.value !== ''
})

// Computed for password display
const displayPassword = computed(() => {
  if (!hasPassword.value) {
    return passwordInput.value
  }
  if (showPassword.value) {
    // Show the decrypted password from originalPassword
    // If user is editing (passwordInput is not asterisks and not original), use their input
    if (passwordInput.value !== '*****' && passwordInput.value !== originalPassword.value) {
      return passwordInput.value
    }
    // Otherwise show the original decrypted password
    return originalPassword.value
  }
  // Show asterisks if password exists but is hidden
  return '*****'
})

// Toggle password visibility
async function togglePasswordVisibility() {
  if (!showPassword.value) {
    // When showing password, ensure we have the decrypted version
    // Fetch fresh from backend to guarantee decrypted password
    if (eventId.value && hasPassword.value) {
      try {
        const {data} = await axios.get(`/events/${eventId.value}`)
        if (data.wifi_password) {
          originalPassword.value = data.wifi_password
          passwordInput.value = data.wifi_password
        }
      } catch (e) {
        console.error('Failed to fetch decrypted password:', e)
        // Fallback to stored value
        passwordInput.value = originalPassword.value
      }
    } else {
      passwordInput.value = originalPassword.value
    }
  } else {
    // Hide password with asterisks
    passwordInput.value = '*****'
  }
  showPassword.value = !showPassword.value
}

// Handle password input
function onPasswordInput(value: string) {
  // If user is typing and password is hidden (showing asterisks), reveal it
  if (!showPassword.value && hasPassword.value) {
    if (value === '*****') {
      // User hasn't changed anything yet, keep asterisks
      passwordInput.value = '*****'
      return
    }
    // User is typing, show the actual password and use their input
    showPassword.value = true
    // Remove any leading asterisks from the input
    passwordInput.value = value.replace(/^\*+/, '')
    return
  }

  // Normal input when password is visible
  passwordInput.value = value
}

// Handle password focus - if showing asterisks, select all so user can easily replace
function onPasswordFocus(e: FocusEvent) {
  if (!showPassword.value && hasPassword.value && passwordInput.value === '*****') {
    // Select all asterisks so user can easily type to replace
    ;(e.target as HTMLInputElement).select()
  }
}

// Handle password blur - save if changed
async function onPasswordBlur() {
  if (!eventId.value) return

  // If password is the asterisk placeholder, don't save
  if (passwordInput.value === '*****' && hasPassword.value) {
    return
  }

  // If password is empty, save empty string
  if (!passwordInput.value || passwordInput.value.trim() === '') {
    await updateEventField('wifi_password', '')
    originalPassword.value = ''
    passwordInput.value = ''
    showPassword.value = false
    return
  }

  // If password hasn't changed from original, don't save
  if (passwordInput.value === originalPassword.value) {
    // Hide password again
    if (showPassword.value && hasPassword.value) {
      showPassword.value = false
      passwordInput.value = '*****'
    }
    return
  }

  // Save the new password
  await updateEventField('wifi_password', passwordInput.value)
  // After save, update original password
  originalPassword.value = passwordInput.value
  // Hide password again if it was shown
  if (showPassword.value && passwordInput.value) {
    showPassword.value = false
    passwordInput.value = '*****'
  }
}

// === PDF Download (neu über Composable) ===
const {isDownloading, anyDownloading, downloadPdf} = usePdfExport()

// === QR ===
function toDataUrl(raw: string | null | undefined): string {
  if (!raw) return ''
  return raw.startsWith('data:') ? raw : `data:image/png;base64,${raw}`
}

const qrWifiUrl = computed(() => {
  const fromEvent = toDataUrl(event.value?.wifi_qrcode)
  return fromEvent || localWifiQr.value
})

async function ensureLocalWifiQr() {
  const ssid = event.value?.wifi_ssid?.trim()
  if (!ssid) {
    localWifiQr.value = ''
    return
  }
  if (event.value?.wifi_qrcode) {
    localWifiQr.value = ''
    return
  }
  try {
    const password = originalPassword.value || ''
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
  } catch (e) {
    console.error('Event für QR/WLAN konnte nicht geladen werden:', e)
  }
  await ensureLocalWifiQr()
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

// === WLAN-Daten speichern + Preview neu laden ===
async function updateEventField(field: string, value: string) {
  if (!eventId.value) return
  try {
    loadingWifiQr.value = true
    await axios.put(`/events/${eventId.value}`, {[field]: value})
    const {data} = await axios.get(`/events/${eventId.value}`)
    eventStore.selectedEvent = new FllEvent(data)
    await ensureLocalWifiQr()

    // Wenn WLAN-Daten geändert wurden → Preview neu laden
    if (['wifi_ssid', 'wifi_password', 'wifi_instruction'].includes(field)) {
      await loadPreview('plan_wifi')
    }
  } catch (e) {
    console.error('Fehler beim Aktualisieren:', e)
  } finally {
    loadingWifiQr.value = false
  }
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
  void loadPreview('plan')
  void loadPreview('plan_wifi')
}

onMounted(() => {
  void boot()
})

// keep-alive: refresh when returning to Analog
onActivated(() => {
  void boot()
})

watch(
  () => [event.value?.wifi_ssid, event.value?.wifi_qrcode, originalPassword.value] as const,
  () => {
    void ensureLocalWifiQr()
  }
)
</script>

<template>
  <div :class="embed ? 'qr-wifi qr-wifi--embed' : 'glass-card liquid-surface-inner p-3 qr-wifi'">
    <div class="qr-wifi__grid">
      <!-- Plan QR -->
      <section class="qr-wifi__col">
        <header class="qr-wifi__col-head">
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
      <section class="qr-wifi__col">
        <header class="qr-wifi__col-head">
          <h3 class="qr-wifi__col-title">
            <i class="bi bi-wifi" aria-hidden="true"/>
            WLAN-Zugang
          </h3>
          <p class="qr-wifi__col-sub">
            Netzwerke mit Schlüssel. Bei Web-Login nur SSID setzen — Rest in den Hinweisen.
          </p>
        </header>

        <!-- QR first so it isn’t buried under the form -->
        <div class="qr-wifi__exports qr-wifi__exports--first">
          <div class="qr-wifi__export">
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

          <div class="qr-wifi__export">
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

        <div v-if="event" class="qr-wifi__fields">
          <div class="qr-wifi__field">
            <label class="qr-wifi__label" for="wifi-ssid">SSID</label>
            <input
                id="wifi-ssid"
                v-model="event.wifi_ssid"
                class="qr-wifi__input"
                placeholder="z. B. TH_EVENT_WLAN"
                type="text"
                @blur="updateEventField('wifi_ssid', event.wifi_ssid || '')"
            />
          </div>
          <div class="qr-wifi__field">
            <label class="qr-wifi__label" for="wifi-password">Passwort</label>
            <div class="qr-wifi__password">
              <input
                  id="wifi-password"
                  :placeholder="hasPassword ? '*****' : 'z. B. $N#Uh)eA~ado]tyMXTkG'"
                  :value="displayPassword"
                  class="qr-wifi__input qr-wifi__input--password"
                  type="text"
                  @blur="onPasswordBlur"
                  @focus="onPasswordFocus"
                  @input="(e) => onPasswordInput((e.target as HTMLInputElement).value)"
              />
              <button
                  v-if="hasPassword"
                  class="qr-wifi__eye"
                  tabindex="-1"
                  type="button"
                  :title="showPassword ? 'Passwort verbergen' : 'Passwort zeigen'"
                  @click="togglePasswordVisibility"
              >
                <i class="bi" :class="showPassword ? 'bi-eye-slash' : 'bi-eye'" aria-hidden="true"/>
              </button>
            </div>
          </div>
          <div class="qr-wifi__field qr-wifi__field--top">
            <label class="qr-wifi__label" for="wifi-hint">Hinweise</label>
            <textarea
                id="wifi-hint"
                v-model="event.wifi_instruction"
                class="qr-wifi__input"
                placeholder="z. B. Code 'FLL' eingeben und Nutzungsbedingungen akzeptieren."
                rows="2"
                @blur="updateEventField('wifi_instruction', event.wifi_instruction || '')"
            />
          </div>
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
  .qr-wifi__grid {
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

.qr-wifi__input {
  width: 100%;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  border-radius: 8px;
  padding: 0.4rem 0.65rem;
  font-size: 0.85rem;
  background: #fff;
  color: var(--color-text);
}

.qr-wifi__input:focus {
  outline: none;
  border-color: color-mix(in srgb, var(--color-accent) 55%, transparent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-accent) 18%, transparent);
}

.qr-wifi__password {
  position: relative;
}

.qr-wifi__input--password {
  padding-right: 2.25rem;
}

.qr-wifi__eye {
  position: absolute;
  right: 0.45rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-subtle);
  padding: 0.15rem;
}

.qr-wifi__eye:hover {
  color: var(--color-text-muted);
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
</style>