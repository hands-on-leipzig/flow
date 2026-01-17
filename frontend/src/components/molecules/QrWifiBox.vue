<script setup lang="ts">
import { computed, ref, onMounted, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'

// === Store & Basis ===
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)
const loadingWifiQr = ref(false)

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
      axios.get(`/events/${eventId.value}`).then(({ data }) => {
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
}, { immediate: true })

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
        const { data } = await axios.get(`/events/${eventId.value}`)
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
const { isDownloading, anyDownloading, downloadPdf } = usePdfExport()

// === QR ===
const qrWifiUrl = computed(() => {
  return event.value?.wifi_qrcode ? `data:image/png;base64,${event.value.wifi_qrcode}` : ''
})

// === Preview-URLs ===
const previewPlan = ref<string | null>(null)
const previewPlanWifi = ref<string | null>(null)

// === Previews laden ===
async function loadPreview(type: 'plan' | 'plan_wifi') {
  if (!event.value?.id) return
  try {
    const timestamp = new Date().getTime() // gegen Cache
    const { data } = await axios.get(`/publish/pdf_preview/${type}/${event.value.id}?_=${timestamp}`)
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
    await axios.put(`/events/${eventId.value}`, { [field]: value })
    const { data } = await axios.get(`/events/${eventId.value}`)
    eventStore.selectedEvent = data

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

// === Initial Previews laden ===
onMounted(() => {
  loadPreview('plan')
  loadPreview('plan_wifi')
})
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col gap-6">
    <h3 class="text-lg font-semibold mb-4">
      QR Codes zum Online-Plan und WLAN-Zugang
    </h3>

    <!-- Plan QR -->
    <div class="flex flex-col gap-3">
      <div class="flex flex-row gap-6 items-start">
        <!-- Linke Seite: QR-Code + PNG -->
        <div class="flex flex-col items-center w-36">
          <img
            v-if="event?.qrcode"
            :src="`data:image/png;base64,${event.qrcode}`"
            alt="QR Plan"
            class="w-20 h-20 mb-2 object-contain"
          />
          <button
            v-if="event?.qrcode"
            class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300"
            @click="downloadPng(`data:image/png;base64,${event.qrcode}`, 'FLOW_QR_Code_Plan.png')"
          >
            PNG
          </button>
        </div>

        <!-- Rechte Seite: Preview + PDF -->
        <div class="flex flex-col items-center w-44">
          <template v-if="previewPlan">
            <img
              :src="previewPlan"
              alt="Preview Plan mit WLAN"
              class="h-20 mb-2 object-contain rounded border border-gray-200"
            />
          </template>

          <template v-else>
            <div
              class="h-20 w-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-gray-400 text-sm mb-2"
            >
              Preview
            </div>
          </template>

          <button
            class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
            :disabled="isDownloading.plan"
            @click="downloadPdf('plan', `/publish/pdf_download/plan/${eventId}`, 'Plan.pdf')"
          >
            <svg v-if="isDownloading.plan" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
            </svg>
            <span>{{ isDownloading.plan ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </div>
      </div>
    </div>

    <!-- WLAN -->
    <div class="rounded-xl shadow bg-white p-4 flex flex-col justify-center">
      <h3 class="text-sm font-semibold mb-2">WLAN-Zugangsdaten</h3>
      <div v-if="event" class="space-y-3">
        <div class="flex items-center gap-3">
          <label class="w-20 text-sm text-gray-700">SSID</label>
          <input
            v-model="event.wifi_ssid"
            @blur="updateEventField('wifi_ssid', event.wifi_ssid)"
            class="flex-1 border px-3 py-1 rounded text-sm"
            type="text"
            placeholder="z. B. TH_EVENT_WLAN"
          />
        </div>
        <div class="flex items-center gap-3">
          <label class="w-20 text-sm text-gray-700">Passwort</label>
          <div class="flex-1 relative">
            <input
              :value="displayPassword"
              @input="(e) => onPasswordInput((e.target as HTMLInputElement).value)"
              @focus="onPasswordFocus"
              @blur="onPasswordBlur"
              class="w-full border px-3 py-1 pr-10 rounded text-sm"
              type="text"
              :placeholder="hasPassword ? '*****' : 'z. B. $N#Uh)eA~ado]tyMXTkG'"
            />
            <button
              v-if="hasPassword"
              type="button"
              @click="togglePasswordVisibility"
              class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-gray-700 focus:outline-none"
              tabindex="-1"
            >
              <!-- Eye icon (show password) -->
              <svg
                v-if="!showPassword"
                xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                />
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"
                />
              </svg>
              <!-- Eye slash icon (hide password) -->
              <svg
                v-else
                xmlns="http://www.w3.org/2000/svg"
                class="h-5 w-5"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"
                />
              </svg>
            </button>
          </div>
        </div>
        <div class="flex items-start gap-3">
          <label class="w-20 text-sm text-gray-700 mt-1">Hinweise</label>
          <textarea
            v-model="event.wifi_instruction"
            @blur="updateEventField('wifi_instruction', event.wifi_instruction)"
            class="flex-1 border px-3 py-1 rounded text-sm"
            rows="3"
            placeholder="z. B. Code 'FLL' eingeben und Nutzungbedingungen akzeptieren."
          ></textarea>
        </div>
      </div>
    </div>

    <!-- QR WLAN -->
    <div class="flex flex-row gap-6 items-start">
      <div class="flex flex-col items-center w-36">
        <template v-if="!event?.wifi_ssid">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-xl text-gray-400 mb-2"
          >?</div>
        </template>
        <template v-else-if="loadingWifiQr">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-lg text-gray-500 mb-2"
          >⏳</div>
        </template>
        <template v-else-if="qrWifiUrl">
          <img :src="qrWifiUrl" alt="QR Wifi" class="w-20 h-20 mb-2 object-contain" />
          <button
            class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300"
            @click="downloadPng(qrWifiUrl, 'FLOW_QR_Code_WLAN.png')"
          >
            PNG
          </button>
        </template>
      </div>

      <div class="flex flex-col items-center w-44">
        <template v-if="!event?.wifi_ssid">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-gray-400 text-sm mb-2"
          >?</div>
        </template>
        <template v-else-if="loadingWifiQr">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-gray-500 text-sm mb-2"
          >⏳</div>
        </template>
        <template v-else-if="previewPlanWifi">
          <img
            :src="previewPlanWifi"
            alt="Preview Plan mit WLAN"
            class="h-20 mb-2 object-contain rounded border border-gray-200"
          />
        </template>
        <template v-else>
          <div
            class="h-20 w-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-gray-400 text-sm mb-2"
          >Preview</div>
        </template>

        <button
          class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300 flex items-center gap-2"
          :disabled="isDownloading.plan_wifi"
          @click="downloadPdf('plan_wifi', `/publish/pdf_download/plan_wifi/${eventId}`, 'Plan_WLAN.pdf')"
        >
          <svg v-if="isDownloading.plan_wifi" class="animate-spin h-4 w-4" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor"
                  d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
          </svg>
          <span>{{ isDownloading.plan_wifi ? 'Erzeuge…' : 'PDF' }}</span>
        </button>
      </div>
    </div>

    <!-- Globaler Ladeindikator -->
    <div
      v-if="anyDownloading"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black/20"
    >
      <div class="bg-white px-4 py-3 rounded shadow flex items-center gap-2">
        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
          <path class="opacity-75" fill="currentColor"
                d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"/>
        </svg>
        <span>PDF wird erzeugt…</span>
      </div>
    </div>
  </div>
</template>