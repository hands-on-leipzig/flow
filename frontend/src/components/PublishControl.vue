<script setup lang="ts">
import { ref, computed, watch } from 'vue'

import { useEventStore } from '@/stores/event'
import { imageUrl } from '@/utils/images'  
import { formatDateOnly, formatDateTime , formatTimeOnly} from '@/utils/dateTimeFormat'
import QRCode from "qrcode"
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

// Event-ID ändert sich → Daten laden
watch(
  () => event.value?.id,
  (id) => {
    if (id) {
      fetchPublicationLevel(id)
      fetchPlanIdByEventId(id)
      fetchScheduleInformation(id)  
    }
  },
  { immediate: true }
)


// --- Schedule Information ---
const scheduleInfo = ref<any>(null)

async function fetchScheduleInformation(eventId: number) {
  try {
    const { data } = await axios.post(`/publish/information/${eventId}`,  {
      level: 4 // überschreibt den Wert aus der DB, um alle Infos zu bekommen
    })
    scheduleInfo.value = data
  } catch (e) {
    console.error('Fehler beim Laden von Schedule Information:', e)
    scheduleInfo.value = null
  }
}

// PDF und Preview

watch(planId, (id) => {
  if (id) {
    fetchPublishData(id)
    fetchPdfAndPreview(id, false) // Single
    fetchPdfAndPreview(id, true)  // Double (mit WLAN)
  }
})

const pdfSinglePDF = ref<string>("")
const pdfSinglePreview = ref<string>("")
const pdfDoublePDF = ref<string>("")
const pdfDoublePreview = ref<string>("")

async function fetchPdfAndPreview(planId: number, wifi: boolean) {
  try {
    const { data } = await axios.get(`/publish/pdf/${planId}`, {
      params: { wifi }   // übergibt ?wifi=true/false
    })

    if (wifi) {
      pdfDoublePDF.value = data.pdf
      pdfDoublePreview.value = data.preview
    } else {
      pdfSinglePDF.value = data.pdf
      pdfSinglePreview.value = data.preview
    }
  } catch (e) {
    console.error("Fehler beim Laden von PDF & Preview:", e)
    if (wifi) {
      pdfDoublePDF.value = ""
      pdfDoublePreview.value = ""
    } else {
      pdfSinglePDF.value = ""
      pdfSinglePreview.value = ""
    }
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

// Level vom Backend holen
async function fetchPublicationLevel(eventId: number) {
  try {
    const { data } = await axios.get(`/publish/level/${eventId}`)
    detailLevel.value = (data.level ?? 1) - 1 // -1, da Radio Buttons bei 0 starten
  } catch (e) {
    console.error("Fehler beim Laden des Publication Levels:", e)
    detailLevel.value = 0
  }
}

// Level im Backend speichern
async function updatePublicationLevel(eventId: number, level: number) {
  try {
    await axios.post(`/publish/level/${eventId}`, { level: level + 1 }) // +1, da Backend bei 1 startet
    console.log("Publication Level aktualisiert:", level + 1)
  } catch (e) {
    console.error("Fehler beim Setzen des Publication Levels:", e)
  }
}

// Wenn Radio Button geändert wird → Level im Backend speichern
watch(detailLevel, (newLevel) => {
  if (event.value?.id) {
    updatePublicationLevel(event.value.id, newLevel)
  }
})

function isCardActive(card: number, level: number) {
 
  if (card <= 2) return true
  if (card === 3 && level >= 1) return true
  if (card === 4 && level >= 2) return true
  if (card === 5 && level >= 3) return true
  return false
}



// Explore Zeiten vorbereiten
const exploreTimes = computed(() => {
  if (!scheduleInfo.value?.schedule?.explore) return []
  const e = scheduleInfo.value.schedule.explore
  const items = []

  if (e.briefing?.teams) items.push({ label: "Coach-Briefing", time: e.briefing.teams })
  if (e.briefing?.judges) items.push({ label: "Gutachter:innen-Briefing", time: e.briefing.judges })
  if (e.opening) items.push({ label: "Eröffnung", time: e.opening })
  if (e.end) items.push({ label: "Ende", time: e.end })

  // nach Uhrzeit sortieren
  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})

// Challenge Zeiten vorbereiten
const challengeTimes = computed(() => {
  if (!scheduleInfo.value?.schedule?.challenge) return []
  const c = scheduleInfo.value.schedule.challenge
  const items = []

  if (c.briefing?.teams) items.push({ label: "Coach-Briefing", time: c.briefing.teams })
  if (c.briefing?.judges) items.push({ label: "Jury-Briefing", time: c.briefing.judges })
  if (c.briefing?.referees) items.push({ label: "Schiedsrichter-Briefing", time: c.briefing.referees })
  if (c.opening) items.push({ label: "Eröffnung", time: c.opening })
  if (c.end) items.push({ label: "Ende", time: c.end })

  return items.sort((a, b) => new Date(a.time).getTime() - new Date(b.time).getTime())
})



// --- QR Codes ---
const qrWifiUrl = ref("")

watch(
  () => [event.value?.wifi_ssid, event.value?.wifi_password],
  async ([ssid, pw]) => {
    if (ssid) {
      const qrContent = pw
        ? `WIFI:T:WPA;S:${ssid};P:${pw};;`
        : `WIFI:T:nopass;S:${ssid};;`
      qrWifiUrl.value = await QRCode.toDataURL(qrContent)
    } else {
      qrWifiUrl.value = ''
    }
  },
  { immediate: true }
)

// --- Update einzelnes Event-Feld ---
async function updateEventField(field: string, value: string) {
  if (!event.value?.id) return

  try {
    await axios.put(`/events/${event.value.id}`, {
      [field]: value,
    })
    console.log(`Feld ${field} erfolgreich aktualisiert`)

    // PDF und Preview neu laden
    await fetchPdfAndPreview(planId.value, true)
    
  } catch (e) {
    console.error(`Fehler beim Aktualisieren von ${field}:`, e)
  }
}

// Vor Doqwnload WLAN-Daten speichern, PDF neu generieren, Download anstoßen
async function downloadDoublePdf() {
  if (!event.value?.id || !planId.value) return

  // 1. Sicherstellen, dass aktuelle Daten gespeichert sind
  await updateEventField('wifi_ssid', event.value.wifi_ssid)
  await updateEventField('wifi_password', event.value.wifi_password)

  // 2. Neu generieren (Backend mit ?wifi=true)
  await fetchPdfAndPreview(planId.value, true)

  // 3. Download anstoßen
  if (pdfDoublePDF.value) {
    const a = document.createElement("a")
    a.href = pdfDoublePDF.value
    a.download = "FLOW_QR_Code_Plan+WLAN.pdf"
    a.click()
  }
}

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

function previewOlinePlan() {
  if (!planId.value) return
  const url = `${import.meta.env.VITE_APP_URL}/output/zeitplan.cgi?plan=${planId.value}`
  window.open(url, '_blank')
}


</script>

<template>

  <div class="p-6 space-y-8">
  
    <h1 class="text-2xl font-bold">Zugriff auf den Ablaufplan</h1>

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
          <label
            v-for="(label, idx) in levels"
            :key="idx"
            class="flex items-start gap-2 cursor-pointer"
          >
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
                <template v-if="idx === 0 && scheduleInfo">
                  <div class="font-semibold mb-1">Datum</div>
                  <div>{{ formatDateOnly(scheduleInfo.date) }}</div>
                  <div class="mt-2 font-semibold">Adresse</div>
                  <div class="whitespace-pre-line text-gray-700 text-xs">
                    {{ scheduleInfo.address }}
                  </div>
                  <div class="mt-2 font-semibold">Kontakt</div>
                  <div class="text-xs space-y-2">
                    <div v-for="(c, idx) in scheduleInfo.contact" :key="idx">
                      {{ c.contact }}<br />
                      {{ c.contact_email }}
                      <div v-if="c.contact_infos">{{ c.contact_infos }}</div>
                    </div>
                  </div>
                </template>

                <template v-else-if="idx === 1 && scheduleInfo">
                  <div class="font-semibold mb-1">Zahlen zur Anmeldung</div>

                  <!-- Explore nur anzeigen, wenn > 0 -->
                  <div v-if="scheduleInfo.teams.explore.capacity > 0 || scheduleInfo.teams.explore.registered > 0">
                    Explore: {{ scheduleInfo.teams.explore.registered }} von {{ scheduleInfo.teams.explore.capacity }} angemeldet
                  </div>

                  <!-- Challenge nur anzeigen, wenn > 0 -->
                  <div v-if="scheduleInfo.teams.challenge.capacity > 0 || scheduleInfo.teams.challenge.registered > 0">
                    Challenge: {{ scheduleInfo.teams.challenge.registered }} von {{ scheduleInfo.teams.challenge.capacity }} angemeldet
                  </div>
                </template>

                <template v-else-if="idx === 2 && scheduleInfo && scheduleInfo.level >= 2">
                  <div class="font-semibold mb-1">Angemeldete Teams</div>
                  <!-- Explore nur anzeigen, wenn Teams existieren -->
                  <template v-if="scheduleInfo.teams.explore.list && scheduleInfo.teams.explore.list.length > 0">
                    <div class="font-medium mb-1">Explore</div>
                    <div class="whitespace-pre-line text-gray-700 text-xs">
                      {{ scheduleInfo.teams.explore.list.join(', ') }}
                    </div>
                  </template>

                  <!-- Challenge nur anzeigen, wenn Teams existieren -->
                  <template v-if="scheduleInfo.teams.challenge.list && scheduleInfo.teams.challenge.list.length > 0">
                    <div class="font-medium mt-2 mb-1">Challenge</div>
                    <div class="whitespace-pre-line text-gray-700 text-xs">
                      {{ scheduleInfo.teams.challenge.list.join(', ') }}
                    </div>
                  </template>
                </template>

                <template v-else-if="idx === 3 && scheduleInfo && scheduleInfo.level >= 3">
                  <div class="font-semibold mb-1">Wichtige Zeiten</div>
                  <div class="text-xs text-gray-600 mb-2">
                    Letzte Änderung: {{ formatDateTime(scheduleInfo.schedule.last_changed) }}
                  </div>

                  <!-- Explore -->
                  <div v-if="exploreTimes.length > 0">
                    <div class="font-medium">Explore</div>
                    <div 
                      v-for="(item, i) in exploreTimes" 
                      :key="i" 
                      class="text-xs text-gray-600 mb-0.5"
                    >
                      {{ item.label }}: {{ formatTimeOnly(item.time, true) }}
                    </div>
                  </div>

                  <!-- Challenge -->
                  <div v-if="challengeTimes.length > 0" class="mt-2">
                    <div class="font-medium">Challenge</div>
                    <div 
                      v-for="(item, i) in challengeTimes" 
                      :key="i" 
                      class="text-xs text-gray-600 mb-0.5"
                    >
                      {{ item.label }}: {{ formatTimeOnly(item.time, true) }}
                    </div>
                  </div>

                </template>

                <template v-else-if="idx === 4">
                  <div class="h-full flex flex-col justify-between">
                    <!-- Inhalt der Kachel -->
                    <div>
                      <div class="font-semibold mb-1">Online Zeitplan</div>
                      <img
                        :src="imageUrl('/flow/öplan.png')"
                        alt="Karussell Vorschau"
                        class="h-28 w-auto border mx-auto"
                      />
                    </div>

                    <!-- Button immer unten -->
                    <div class="mt-4 flex justify-center">
                    <button
                      class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300"
                      @click="previewOlinePlan"
                    >
                      Vorschau
                    </button>
                    </div>
                  </div>
                </template>

              </div>
            </template>
          </div>
        </div>
      </div>
    </div>


    <!-- Während der Veranstaltung -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold mb-4">Online - Während der Veranstaltung</h2>

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
              <div class="relative h-28 w-auto aspect-[1.414/1] border">
                <img
                  v-if="pdfSinglePreview"
                  :src="pdfSinglePreview"
                  alt="PDF Preview"
                  class="h-full w-full object-contain"
                />
              </div>
              <a v-if="pdfSinglePDF" :href="pdfSinglePDF" download="FLOW_QR_Code_Plan.pdf">
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
                <!-- Weitere Anweisungen -->
                <div class="flex items-start gap-3">
                  <label class="w-20 text-sm text-gray-700 mt-1">Hinweise</label>
                  <textarea
                    v-model="event.wifi_instruction"
                    @blur="updateEventField('wifi_instruction', event.wifi_instruction)"
                    class="flex-1 border px-3 py-1 rounded text-sm"
                    rows="3"
                    placeholder="z. B. Code FLL eingebeben und Nutzungbedingungen akzeptieren."
                  ></textarea>
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
                  @click="downloadPng(qrWifiUrl, 'FLOW_QR_Code_WLAN.png')"
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

            <!-- 5: PDF Preview (Plan + WiFi) -->
            <div class="flex flex-col items-center">
              <template v-if="qrWifiUrl">
                <div class="relative h-28 w-auto aspect-[1.414/1] border">
                  <img
                    :src="pdfDoublePreview"
                    alt="PDF Preview"
                    class="h-full w-full object-contain"
                  />
                </div>
                <button
                  class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
                  @click="downloadDoublePdf"
                >
                  PDF
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


          </div>

        </div>

        <!-- Rechte Box: Karussell -->
        <div class="w-100 rounded-xl shadow bg-white p-6 flex flex-col items-center">
          <h3 class="text-lg font-semibold mb-4">Präsentation über Bildschirme</h3>
          
          <img
            :src="imageUrl('/flow/karussell.png')"
            alt="Karussell Vorschau"
            class="h-28 w-auto border"
          />

          <div class="flex gap-3 mt-4">
            <a :href="carouselLink" target="_blank" rel="noopener noreferrer">
              <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">
                Anzeigen
              </button>
            </a>
            <router-link to="/presentation">
              <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">
                Konfigurieren
              </button>
            </router-link>
          </div>
        </div>

      </div>
    </div>

    <!-- Offline Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold mb-2">Offline - PDF-Download</h2>
      <p class="text-sm text-gray-600">
        Dokumente für den Veranstalter – volle Details in einfacher Formatierung.
      </p>

      <!-- Platzhalter -->
      <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center text-gray-400 text-sm">
        📄 Hier kommt noch was ...
      </div>
    </div>
  </div>

</template>