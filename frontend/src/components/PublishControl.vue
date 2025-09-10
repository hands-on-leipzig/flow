<script setup lang="ts">
import { ref, computed, watch } from 'vue'

import { useEventStore } from '@/stores/event'
import QRCode from "qrcode"
import jsPDF from "jspdf"
import axios from 'axios'



// Store + Selected Event
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const planId = ref<number | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

async function fetchPlanIdByEventId(eventId: number) {
  loading.value = true
  error.value = null
  try {
    const { data } = await axios.get(`/plans/event/${eventId}`)
    planId.value = data?.id ?? null
  } catch (e) {
    console.error('Fehler beim Laden der Plan-ID:', e)
    error.value = 'Plan-ID konnte nicht geladen werden'
    planId.value = null
  } finally {
    loading.value = false
  }
}

// Reaktiv laden, sobald/solange es eine Event-ID gibt
watch(
  () => event.value?.id,
  (id) => {
    if (id) fetchPlanIdByEventId(id)
  },
  { immediate: true } // triggert sofort und auch bei späterem Setzen
)

// Assets (Fake)
import qr1Png from '@/assets/fake/qr1.png'
import qr1Pdf from '@/assets/fake/qr1.pdf'
import qr2Png from '@/assets/fake/qr2.png'
import qr2Pdf from '@/assets/fake/qr2.pdf'

// Fake Link
const publicLink = ref("https://flow.hands-on-technology.org/braunschweig")

// Radio Buttons Detailstufe
const levels = ["Planung", "Nach Anmeldeschluss", "Überblick zum Ablauf", "volle Details"]
const detailLevel = ref(0)

function isCardActive(card: number, level: number) {
  if (card <= 2) return true        // Kachel 1,2 immer aktiv
  if (card === 3 && level >= 1) return true
  if (card === 4 && level >= 2) return true
  if (card === 5 && level >= 3) return true
  return false
}

// --- QR Codes ---
const qrPlanUrl = ref("")
const qrWifiUrl = ref("")

async function generateQRCodes() {
  qrPlanUrl.value = await QRCode.toDataURL(publicLink.value)
  if (event.value?.wifi_ssid && event.value?.wifi_password) {
    const qrContent = `WIFI:T:WPA;S:${event.value.wifi_ssid};P:${event.value.wifi_password};;`
    qrWifiUrl.value = await QRCode.toDataURL(qrContent)
  }
}
generateQRCodes()


watch(
  () => [event.value?.wifi_ssid, event.value?.wifi_password],
  async ([ssid, pw]) => {
    if (ssid && pw) {
      const qrContent = `WIFI:T:WPA;S:${ssid};P:${pw};;`
      qrWifiUrl.value = await QRCode.toDataURL(qrContent)
    } else {
      qrWifiUrl.value = ''
    }
  },
  { immediate: true }
)

// --- Downloads ---
async function downloadPng(dataUrl: string, filename: string) {
  const a = document.createElement("a")
  a.href = dataUrl
  a.download = filename
  a.click()
}


</script>

<template>

  <div class="p-6 space-y-8">

    <div>
        <a target="_blank" :href="'https://dev.flow.hands-on-technology.org/output/zeitplan.cgi?plan=' + planId">
          Link zum öPlan: https://dev.flow.hands-on-technology.org/output/zeitplan.cgi?plan={{ planId }}
        </a>
    </div>

  
    <h1 class="text-2xl font-bold">Zugriff auf den Plan</h1>

    <!-- Online Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold">Online – von der Planung bis zur Veranstaltung</h2>

      <!-- Link prominent + Erklärung dezent dahinter -->
      <div class="flex items-center gap-3">
        <a
          :href="publicLink"
          target="_blank"
          rel="noopener"
          class="text-blue-600 underline font-medium text-base"
        >
          {{ publicLink }}
        </a>
        <span class="text-sm text-gray-600">
          gibt Teams, Freiwilligen und dem Publikum alle Informationen zur Veranstaltung.
        </span>
      </div>

    
<div class="flex items-start gap-6">
  <!-- Radiobuttons links -->
  <div class="flex flex-col space-y-3">
    <label v-for="(label, idx) in levels" :key="idx" class="flex items-start gap-2 cursor-pointer">
      <input
        type="radio"
        :value="idx"
        v-model="detailLevel"
        class="mt-1 accent-blue-600"
      />
      <span class="text-sm leading-tight">
        {{ label.split(" ")[0] }} <br />
        {{ label.split(" ").slice(1).join(" ") }}
      </span>
    </label>
  </div>

  <!-- Info-Kacheln rechts -->
  <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4 flex-1">
    <!-- Helper: Kachel-Wrapper -->
    <template v-for="(card, idx) in 5" :key="idx">
      <div
        class="relative rounded-lg border p-3 text-sm"
        :class="{
          'opacity-100': isCardActive(idx + 1, detailLevel),
          'opacity-50': !isCardActive(idx + 1, detailLevel),
        }"
      >
        <!-- Icon oben rechts -->
        <div class="absolute top-2 right-2">
          <div
            v-if="isCardActive(idx + 1, detailLevel)"
            class="w-4 h-4 bg-green-500 text-white flex items-center justify-center rounded-sm text-xs"
          >
            ✓
          </div>
          <div
            v-else
            class="w-4 h-4 bg-gray-300 flex items-center justify-center rounded-sm"
          ></div>
        </div>

        <!-- Inhalt -->
        <template v-if="idx === 0">
          <div class="font-semibold mb-1">Datum</div>
          <div>Mittwoch, 28.01.2026</div>
          <div class="mt-2 font-semibold">Adresse</div>
          <div class="whitespace-pre-line text-gray-700 text-xs">
            ROBIGS c/o ROCARE GmbH  
            Am Seitenkanal 8  
            49811 Lingen (Ems)
          </div>
          <div class="mt-2 font-semibold">Kontakt</div>
          <div class="text-xs">Lena Helle<br/>lhelle@rosen-group.com</div>
        </template>

        <template v-else-if="idx === 1">
          <div class="font-semibold mb-1">Teams</div>
          <div>Explore: 5 / 12</div>
          <div>Challenge: 12 / 16</div>
        </template>

        <template v-else-if="idx === 2">
          <div class="font-semibold mb-1">Explore Teams</div>
          <div>Zwerge, Gurkentruppe</div>
          <div class="font-semibold mt-2 mb-1">Challenge Teams</div>
          <div>Rocky, Ironman, Gandalf</div>
        </template>

        <template v-else-if="idx === 3">
          <div class="font-semibold mb-1">Zeitplan</div>
          <div>Briefings ab 8:30 Uhr</div>
          <div>Eröffnung 9:00 Uhr</div>
          <div>Ende 17:15 Uhr</div>
        </template>

        <template v-else-if="idx === 4">
          <div class="font-semibold mb-1">Ablaufplan</div>
          <div class="text-xs text-gray-600">mit allen Details</div>
        </template>
      </div>
    </template>
  </div>
</div>

    </div>


    <!-- Während der Veranstaltung -->
    <div class="rounded-xl shadow bg-white p-6 space-y-6">
      <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

      <div class="flex flex-wrap items-start gap-6 justify-start">
        <!-- 1: QR Plan PNG -->
        <div class="flex flex-col items-center">
          <img
            v-if="qrPlanUrl"
            :src="qrPlanUrl"
            alt="QR Plan"
            class="mx-auto w-28 h-28"
          />
          <button
            v-if="qrPlanUrl"
            class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
            @click="downloadPng(qrPlanUrl, 'plan.png')"
          >
            PNG
          </button>
        </div>

        <!-- 2: Fake PDF Preview (Plan) -->
        <div class="flex flex-col items-center">
          <img
            src="@/assets/fake/qr1.png"
            alt="PDF Preview Plan"
            class="mx-auto h-28 w-auto border"
          />
          <a :href="qr1Pdf" download="plan.pdf">
            <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
          </a>
        </div>

        <!-- 3: WLAN Felder -->
        <div class="rounded-xl shadow bg-white p-4 flex flex-col">
          <h3 class="text-sm font-semibold mb-2">WLAN-Zugangsdaten</h3>
          <div v-if="event" class="space-y-3">
            <!-- SSID -->
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

            <!-- Passwort -->
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
          </div>
        </div>  

        <!-- 4: QR Wifi PNG -->
        <div class="flex flex-col items-center">
          <img
            v-if="qrWifiUrl"
            :src="qrWifiUrl"
            alt="QR Wifi"
            class="mx-auto w-28 h-28"
          />
          <button
            v-if="qrWifiUrl"
            class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
            @click="downloadPng(qrWifiUrl, 'wifi.png')"
          >
            PNG
          </button>
        </div>

        <!-- 5: Fake PDF Preview (Plan + Wifi) -->
        <div class="flex flex-col items-center">
          <img
            src="@/assets/fake/qr2.png"
            alt="PDF Preview Wifi+Plan"
            class="mx-auto h-28 w-auto border"
          />
          <a :href="qr2Pdf" download="wifi-plan.pdf">
            <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
          </a>
        </div>
      </div>
    </div>




    <!-- Offline Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold mb-2">Offline</h2>
      <p class="text-sm text-gray-600">Hier kannst du vorbereitete Dokumente für den Druck exportieren.</p>
      <div class="space-y-2">
        <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded">Zeitpläne drucken</button>
        <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded">Namensschilder drucken</button>
      </div>
    </div>
  </div>
</template>