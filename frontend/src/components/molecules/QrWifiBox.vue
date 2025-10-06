<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const loadingWifiQr = ref(false)

const qrWifiUrl = computed(() => {
  return event.value?.wifi_qrcode ? `data:image/png;base64,${event.value.wifi_qrcode}` : ''
})

async function updateEventField(field: string, value: string) {
  if (!event.value?.id) return
  try {
    loadingWifiQr.value = true
    await axios.put(`/events/${event.value.id}`, { [field]: value })
    const { data } = await axios.get(`/events/${event.value.id}`)
    eventStore.selectedEvent = data
  } catch (e) {
    console.error('Fehler beim Aktualisieren:', e)
  } finally {
    loadingWifiQr.value = false
  }
}

async function downloadPng(dataUrl: string, filename: string) {
  const a = document.createElement('a')
  a.href = dataUrl
  a.download = filename
  a.click()
}
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col gap-6">
    <h3 class="text-lg font-semibold mb-4">
      QR Codes zum Online-Plan und WLAN-Zugang
    </h3>

    <!-- Plan QR -->
    <div class="flex flex-col gap-3">
      <p class="text-sm text-gray-600">
        Teams, Freiwillige und Gäste gelangen über diesen QR-Code zum Online-Zeitplan.
      </p>

      <!-- QR + Preview nebeneinander -->
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
          <div
            class="w-full aspect-[4/3] border-2 border-dashed border-gray-300 rounded flex items-center justify-center text-gray-400 text-sm"
            style="height: 80px;"
          >
            Preview
          </div>
          <button
            class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300"
          >
            PDF
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
          <input
            v-model="event.wifi_password"
            @blur="updateEventField('wifi_password', event.wifi_password)"
            class="flex-1 border px-3 py-1 rounded text-sm"
            type="text"
            placeholder="z. B. $N#Uh)eA~ado]tyMXTkG"
          />
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
      <!-- Linke Seite: QR-Code + PNG -->
      <div class="flex flex-col items-center w-36">
        <template v-if="!event?.wifi_ssid">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-xl text-gray-400 mb-2"
          >
            ?
          </div>
        </template>
        <template v-else-if="loadingWifiQr">
          <div
            class="w-20 h-20 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-lg text-gray-500 mb-2"
          >
            ⏳
          </div>
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

      <!-- Rechte Seite: Preview + PDF -->
      <div class="flex flex-col items-center w-44">
        <div
          class="w-full aspect-[4/3] border-2 border-dashed border-gray-300 rounded flex items-center justify-center text-gray-400 text-sm"
          style="height: 80px;"
        >
          Preview
        </div>
        <button
          class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300"
        >
          PDF
        </button>
      </div>
    </div>
  </div>
</template>