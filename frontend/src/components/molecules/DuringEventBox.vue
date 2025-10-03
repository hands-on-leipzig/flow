<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useEventStore } from '@/stores/event'
import { imageUrl } from '@/utils/images'
import { RouterLink } from 'vue-router'

// Store + Selected Event (autark)
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const planId = ref<number | null>(null)

// PDFs & Previews
const pdfSinglePDF = ref<string>('')
const pdfSinglePreview = ref<string>('')
const pdfDoublePDF = ref<string>('')
const pdfDoublePreview = ref<string>('')

const loadingPdfSingle = ref(false)
const loadingPdfDouble = ref(false)

// Link + QR Plan
const publishData = ref<{ link: string; qrcode: string } | null>(null)

// WLAN QR
const qrWifiUrl = computed(() => {
  return event.value?.wifi_qrcode ? `data:image/png;base64,${event.value.wifi_qrcode}` : ''
})

const loadingWifiQr = ref(false)

// ----------------- Fetches -----------------
async function fetchPlanIdByEventId(eventId: number) {
  try {
    const { data } = await axios.get(`/plans/event/${eventId}`)
    planId.value = data?.id ?? null
  } catch (e) {
    console.error('Fehler beim Laden der Plan-ID:', e)
    planId.value = null
  }
}

async function fetchPublishData(planIdNum: number) {
  try {
    const { data } = await axios.get(`/publish/link/${planIdNum}`)
    publishData.value = data
  } catch (e) {
    console.error('Fehler beim Laden von Publish-Daten:', e)
    publishData.value = null
  }
}

async function fetchPdfAndPreview(planIdNum: number, wifi: boolean) {
  if (wifi) {
    loadingPdfDouble.value = true
    pdfDoublePDF.value = ''
    pdfDoublePreview.value = ''
  } else {
    loadingPdfSingle.value = true
    pdfSinglePDF.value = ''
    pdfSinglePreview.value = ''
  }

  try {
    const { data } = await axios.get(`/publish/pdf/${planIdNum}`, { params: { wifi } })
    if (wifi) {
      pdfDoublePDF.value = data.pdf
      pdfDoublePreview.value = data.preview
    } else {
      pdfSinglePDF.value = data.pdf
      pdfSinglePreview.value = data.preview
    }
  } catch (e) {
    console.error('Fehler beim Laden von PDF & Preview:', e)
  } finally {
    if (wifi) loadingPdfDouble.value = false
    else loadingPdfSingle.value = false
  }
}

async function refreshEvent(eventId: number) {
  try {
    const { data } = await axios.get(`/events/${eventId}`)
    eventStore.selectedEvent = data
  } catch (e) {
    console.error('Fehler beim Reload des Events:', e)
  }
}

// ----------------- Reaktionen -----------------
watch(
  () => event.value?.id,
  async (id) => {
    if (!id) return
    await fetchPlanIdByEventId(id)
  },
  { immediate: true }
)

watch(planId, async (id) => {
  if (!id) return
  await fetchPublishData(id)
  await fetchPdfAndPreview(id, false) // Single
  await fetchPdfAndPreview(id, true)  // Double (mit WLAN)
})

// ----------------- Update-Handler -----------------
async function updateEventField(field: string, value: string) {
  if (!event.value?.id) return
  try {
    if (field.startsWith('wifi_')) {
      loadingWifiQr.value = true
      pdfDoublePDF.value = ''
      pdfDoublePreview.value = ''
    }

    await axios.put(`/events/${event.value.id}`, { [field]: value })

    if (planId.value && field.startsWith('wifi_')) {
      await fetchPdfAndPreview(planId.value, true)
    }
  } catch (e) {
    console.error(`Fehler beim Aktualisieren von ${field}:`, e)
  } finally {
    if (field.startsWith('wifi_')) {
      if (event.value?.id) await refreshEvent(event.value.id)
      loadingWifiQr.value = false
    }
  }
}

// ----------------- Downloads -----------------
async function downloadPng(dataUrl: string, filename: string) {
  const a = document.createElement('a')
  a.href = dataUrl
  a.download = filename
  a.click()
}

async function downloadDoublePdf() {
  if (!event.value?.id || !planId.value) return
  await updateEventField('wifi_ssid', event.value.wifi_ssid)
  await updateEventField('wifi_password', event.value.wifi_password)
  await fetchPdfAndPreview(planId.value, true)

  if (pdfDoublePDF.value) {
    const a = document.createElement('a')
    a.href = pdfDoublePDF.value
    a.download = 'FLOW_QR_Code_Plan+WLAN.pdf'
    a.click()
  }
}

async function downloadOfflinePdf() {
  if (!planId.value) return
  try {
    const url = `/export/pdf/${planId.value}`
    const response = await axios.get(url, { responseType: 'blob' })
    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = `FLOW_Plan_${planId.value}.pdf`
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (e) {
    console.error('Fehler beim Download des Offline-PDF:', e)
  }
}

async function downloadRoomPdf() {
  if (!planId.value) return
  try {
    const url = `/publish/rooms/${planId.value}`
    const response = await axios.get(url, { responseType: "blob" })

    const blob = new Blob([response.data], { type: "application/pdf" })
    const link = document.createElement("a")
    link.href = window.URL.createObjectURL(blob)
    link.download = `FLOW_Raumbeschilderung_${planId.value}.pdf`
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (e) {
    console.error("Fehler beim Download der Raumbeschilderung:", e)
  }
}



const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : ''
})
</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 space-y-4">
    <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

    <!-- 3 gleich breite Spalten -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <!-- Spalte 1: QR / WLAN -->
      <div class="rounded-xl shadow bg-white p-6">
        <h3 class="text-lg font-semibold mb-4">
          QR Codes zum Online-Plan und WLAN-Zugang zum Aushängen vor Ort
        </h3>

        <div class="flex flex-col gap-6">
          <!-- Zeile 1: Plan PNG + PDF -->
          <div class="flex flex-row gap-6 justify-start">
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
          </div>

          <!-- Zeile 2: WLAN Felder -->
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
                  placeholder="z. B. Code FLL eingebeben und Nutzungbedingungen akzeptieren."
                ></textarea>
              </div>
            </div>
          </div>

          <!-- Zeile 3: WLAN PNG + PDF -->
          <div class="flex flex-row gap-6 justify-start">
            <div class="flex flex-col items-center">
              <template v-if="!event?.wifi_ssid">
                <div class="mx-auto w-28 h-28 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-2xl text-gray-400">
                  ?
                </div>
              </template>
              <template v-else-if="loadingWifiQr">
                <div class="mx-auto w-28 h-28 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-xl text-gray-500">
                  ⏳
                </div>
              </template>
              <template v-else-if="qrWifiUrl">
                <img :src="qrWifiUrl" alt="QR Wifi" class="mx-auto w-28 h-28" />
                <button
                  class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm"
                  @click="downloadPng(qrWifiUrl, 'FLOW_QR_Code_WLAN.png')"
                >
                  PNG
                </button>
              </template>
            </div>

            <div class="flex flex-col items-center">
              <template v-if="!event?.wifi_ssid">
                <div class="mx-auto w-28 h-28 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-2xl text-gray-400">
                  ?
                </div>
              </template>
              <template v-else-if="loadingWifiQr || loadingPdfDouble">
                <div class="mx-auto w-28 h-28 flex items-center justify-center border-2 border-dashed border-gray-300 rounded text-xl text-gray-500">
                  ⏳
                </div>
              </template>
              <template v-else-if="pdfDoublePreview">
                <div class="relative h-28 w-auto aspect-[1.414/1] border">
                  <img :src="pdfDoublePreview" alt="PDF Preview" class="h-full w-full object-contain" />
                </div>
                <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm" @click="downloadDoublePdf">
                  PDF
                </button>
              </template>
            </div>
          </div>
        </div>
      </div>

      <!-- Spalte 2: Raumbeschilderung + Notfallplan -->
      <div class="space-y-6">
        <div class="rounded-xl shadow bg-white p-6 flex flex-col">
          <h3 class="text-lg font-semibold mb-4">Raumbeschilderung</h3>
          <p class="text-sm text-gray-600 mb-2">Ein PDF mit je einer Seite pro Raum mit alle Aktivitäten.
          </p>
        <div class="flex justify-center mt-auto">
            <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadRoomPdf">
              PDF
            </button>
          </div>

        </div>

        <div class="rounded-xl shadow bg-white p-6 flex flex-col">
          <h3 class="text-lg font-semibold mb-4">Der ganze Plan in einem Dokument</h3>
          <p class="text-sm text-gray-600 mb-2">
            Volle Details, aber in einfacher Formatierung: Je eine Tabelle pro Team, Gutachter:innen-/Jury-Gruppe und Robot-Game-Tisch.
          </p>
          <p class="text-xs text-gray-500 mb-4">
            Dieses Dokument ist für den Veranstalter gedacht, nicht zum Verteilen an Teams, Freiwillige und Besucher! Für die gibt es den Link oben.
          </p>
          <div class="flex justify-center mt-auto">
            <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadOfflinePdf">
              PDF
            </button>
          </div>
        </div>
      </div>

      <!-- Spalte 3: Karussell -->
      <div class="rounded-xl shadow bg-white p-6 flex flex-col items-center">
        <h3 class="text-lg font-semibold mb-4">Präsentation über Bildschirme</h3>
        <img
          :src="imageUrl('/flow/karussell.png')"
          alt="Karussell Vorschau"
          class="h-28 w-auto border"
        />
        <div class="flex gap-3 mt-4">
          <a :href="carouselLink" target="_blank" rel="noopener noreferrer">
            <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">Anzeigen</button>
          </a>
          <RouterLink to="/presentation">
            <button class="px-3 py-1 bg-gray-200 rounded text-sm hover:bg-gray-300">Konfigurieren</button>
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>