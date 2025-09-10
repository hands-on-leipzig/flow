<script setup lang="ts">
import { ref, computed, watch } from 'vue'

import {ref, computed} from 'vue'
import {useEventStore} from '@/stores/event'
import jsPDF from "jspdf";
import QRCode from "qrcode";
import axios from 'axios';
import {mdiContentCopy} from "@mdi/js";



// Store + Selected Event
const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const planId = ref<number | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

const tabs = ['Zeitpläne', 'Namensschilder', 'QR-Code WLAN', 'QR-Code Zeitplan']
const activeTab = ref(tabs[0])

const carouselLink = computed(() => {
  return event.value ? `${window.location.origin}/carousel/${event.value.id}` : '';
});

const downloadWifiQr = () => {
  window.open(`/events/${event.value?.id}/wifi-qr`, '_blank')
}

async function generateWifiPDF() {
  const qrContent = `WIFI:T:WPA;S:${event.value.wifi_ssid};P:${event.value.wifi_password};;`
  const qrDataUrl = await QRCode.toDataURL(qrContent)

  const pdf = new jsPDF()
  pdf.setFontSize(16)
  pdf.text('WiFi QR Code', 20, 20)
  pdf.addImage(qrDataUrl, 'PNG', 20, 30, 100, 100)
  pdf.text(`SSID: ${event.value.wifi_ssid}`, 20, 140)
  pdf.text(`Password: ${event.value.wifi_password}`, 20, 150)

  window.open(pdf.output('bloburl'), '_blank')
}

const downloadScheduleQr = () => {
  window.open(`/events/${event.value?.id}/schedule-qr`, '_blank')
}
const printSchedule = () => {
  window.open(`/events/${event.value?.id}/print/schedule`, '_blank')
}
const printNameTags = () => {
  window.open(`/events/${event.value?.id}/print/nametags`, '_blank')
}

const updateEventField = async (field: string, value: any) => {
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

function copyUrl(url) {
  navigator.clipboard.writeText(url);
}
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
    <div>
      <h2 class="text-lg font-semibold mb-4">Während der Veranstaltung</h2>

      <div class="flex flex-col lg:flex-row gap-6">
        <!-- Linke Box: fünf QR-Bereiche -->
        <div class="flex-1 rounded-xl shadow bg-white p-6">
          <h3 class="text-lg font-semibold mb-4">QR Codes & Downloads</h3>

          <div class="flex flex-row flex-wrap gap-6 justify-start">
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
            <div class="flex flex-col items-center">
              <img
                src="@/assets/fake/qr2.png"
                alt="PDF Preview Wifi+Plan"
                class="mx-auto h-28 w-auto border"
              />
              <a v-if="qrWifiUrl" :href="qr2Pdf" download="wifi-plan.pdf">
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


    <!-- Offline Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-4">
      <h2 class="text-lg font-semibold mb-2">Offline</h2>
      <p class="text-sm text-gray-600">Hier kannst du vorbereitete Dokumente für den Druck exportieren.</p>
      <div class="space-y-2">
        <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded">Zeitpläne drucken</button>
        <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded">Namensschilder drucken</button>
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

    </div>
  </div>
</template>

<style scoped>

</style>
