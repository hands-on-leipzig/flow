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
  { immediate: true }
)

// PDF-Links
const pdfSingleUrl = ref<string>("")
const pdfSinglePreview = ref<string>("")

watch(planId, (id) => {
  if (id) {
    fetchPublishData(id)
    fetchPdfSingleUrl(id)
    fetchPdfSinglePreview(id)
  }
})

async function fetchPdfSingleUrl(planId: number) {
  try {
    const res = await axios.get(`/publish/pdf-single/${planId}`, {
      responseType: "blob",
    })
    const url = URL.createObjectURL(res.data)
    pdfSingleUrl.value = url
  } catch (e) {
    console.error("Fehler beim Laden von PDF-Single:", e)
    pdfSingleUrl.value = ""
  }
}

async function fetchPdfSinglePreview(planId: number) {
  try {
    const res = await axios.get(`/publish/pdf-single-preview/${planId}`)
    pdfSinglePreview.value = res.data.preview // direkt den data:image/png;base64 String
  } catch (e) {
    console.error("Fehler beim Laden von PDF-Preview:", e)
    pdfSinglePreview.value = ""
  }
}

// Link + QR Code
const publishData = ref<{ link: string; qrcode: string } | null>(null)

async function fetchPublishData(planId: number) {
  try {
    const { data } = await axios.get(`/publish/link/${planId}`)
    publishData.value = data
  } catch (e) {
    console.error('Fehler beim Laden von Publish-Daten:', e)
    publishData.value = null
  }
}

// Radio Buttons Detailstufe
const levels = ["Planung", "Nach Anmeldeschluss", "Überblick zum Ablauf", "volle Details"]
const detailLevel = ref(0)

function isCardActive(card: number, level: number) {
  if (card <= 2) return true
  if (card === 3 && level >= 1) return true
  if (card === 4 && level >= 2) return true
  if (card === 5 && level >= 3) return true
  return false
}

// --- QR Codes ---
const qrWifiUrl = ref("")

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

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
})
</script>

<template>

  <div class="p-6 space-y-8">

    <div class="flex items-center gap-2 bg-orange-100 border border-orange-300 rounded p-2 text-orange-800">
      <span>🔧</span>
      <a
        target="_blank"
        :href="'https://dev.flow.hands-on-technology.org/output/zeitplan.cgi?plan=' + planId"
        class="underline hover:text-orange-900"
      >
        Link zum Ö-Plan: https://dev.flow.hands-on-technology.org/output/zeitplan.cgi?plan={{ planId }}
      </a> (kommt noch raus!)
    </div>

  
    <h1 class="text-2xl font-bold">Zugriff auf den Plan</h1>

    <!-- Online Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold">Online – von der Planung bis zur Veranstaltung</h2>

      <!-- Link prominent + Erklärung dezent dahinter -->
      <div class="flex items-center gap-3">
      <a
        v-if="publishData?.link"
        :href="publishData.link"
        target="_blank"
        rel="noopener"
        class="text-blue-600 underline font-medium text-base"
      >
        {{ publishData.link }}
      </a>
        <span class="text-sm text-gray-600">
          gibt Teams, Freiwilligen und dem Publikum alle Informationen zur Veranstaltung.
        </span>
      </div>

    
      <div class="flex items-start gap-6">
        <!-- Radiobuttons links -->
        <div class="flex flex-col space-y-3">
          <h3 class="text-sm font-semibold mb-2">Detaillevel</h3>
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
        <div class="flex-1">
          <h3 class="text-sm font-semibold mb-2">Veröffentlichte Informationen</h3>
        
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
            
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
    </div>


    <!-- Während der Veranstaltung -->
    <div>
      <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

      <div class="flex flex-col lg:flex-row gap-6">
        <!-- Linke Box: fünf QR-Bereiche -->
        <div class="flex-1 rounded-xl shadow bg-white p-6">
          <h3 class="text-lg font-semibold mb-4">QR Codes zum Online-Plan zum Aushängen vor Ort</h3>

          <div class="flex flex-row flex-wrap gap-6 justify-start">

            <!-- 1: QR Plan PNG -->
            <div class="flex flex-col items-center">
              <img
                v-if="publishData?.qrcode"
                :src="publishData.qrcode"
                alt="QR Plan"
                class="mx-auto w-28 h-28"
              />
              <button
                v-if="publishData?.qrcode"
                class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
                @click="downloadPng(publishData.qrcode, 'FLOW_QR_Code_Plan.png')"
              >
                PNG
              </button>
            </div>

            <!-- 2: PDF Preview (Plan) -->
            <div class="flex flex-col items-center">
              <img
                v-if="pdfSinglePreview"
                :src="pdfSinglePreview"
                alt="PDF Preview"
                class="mx-auto h-28 w-auto border"
              />
              <a v-if="pdfSingleUrl" :href="pdfSingleUrl" download="FLOW_QR_Code_Plan.pdf">
                <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
              </a>
            </div>

            <!-- 3: WLAN Felder -->
            <div class="rounded-xl shadow bg-white p-4 flex flex-col justify-center">
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
              <template v-if="qrWifiUrl">
                <img
                  :src="qrWifiUrl"
                  alt="QR Wifi"
                  class="mx-auto w-28 h-28"
                />
                <button
                  class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
                  @click="downloadPng(qrWifiUrl, 'wifi.png')"
                >
                  PNG
                </button>
              </template>
              <template v-else>
                <div
                  class="mx-auto w-28 h-28 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-2xl text-gray-400"
                >
                  ?
                </div>
              </template>
            </div>

            <!-- 5: Fake PDF Preview (Plan + Wifi) -->
<!-- 2: PDF Preview (Plan) -->
<div class="flex flex-col items-center">
  <embed
    v-if="pdfSingleUrl"
    :src="pdfSingleUrl"
    type="application/pdf"
    class="mx-auto h-28 w-auto border"
  />
  <a v-if="pdfSingleUrl" :href="pdfSingleUrl" download="FLOW_QR_Code_Plan.pdf">
    <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
  </a>
</div>
          </div>
        </div>

        <!-- Rechte Box: Karussell -->
        <div class="w-100 rounded-xl shadow bg-white p-6 flex flex-col items-center">
          <h3 class="text-lg font-semibold mb-4">Präsentation über Bildschirme</h3>
          
          <img
            src="@/assets/fake/Karussell.png"
            alt="Karussell Vorschau"
            class="h-28 w-auto border"
          />

          <div class="flex gap-3 mt-4">
            <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">
              Anzeigen
            </button>
            <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">
              Konfigurieren
            </button>
          </div>
        </div>

      </div>
    </div>

     <div class="rounded-xl shadow bg-white p-4 flex flex-col col-span-2">
        <h2 class="text-lg font-semibold mb-2">Präsentation</h2>
        <span class="text-sm mt-2 text-gray-500 mb-4">
          Halt die Teams am Wettbewerb immer auf dem Laufenden.
          Hier kannst du Folien konfigurieren, die während des Wettbewerbs angezeigt werden.
        </span>
        <div class="mb-4">
          <div class="d-flex align-items-center gap-2">
            <span class="text-break">Link zur öffentlichen Ansicht:
              <a :href="carouselLink" target="_blank" rel="noopener noreferrer">{{ carouselLink }}</a>
            </span>
            <button
                type="button"
                class="btn btn-outline-secondary btn-sm"
                @click="copyUrl(carouselLink)"
                title="Link kopieren"
            >
              <svg-icon type="mdi" :path="mdiContentCopy" size="16" class="ml-1 mt-1"></svg-icon>
            </button>
          </div>
          <router-link to="/presentation" class="mt-2 px-4 py-2 rounded bg-blue-600 hover:bg-blue-400 text-white">
            Präsentation bearbeiten
          </router-link>
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