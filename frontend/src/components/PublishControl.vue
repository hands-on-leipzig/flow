<script setup>

import {ref, computed} from 'vue'
import {useEventStore} from '@/stores/event'
import jsPDF from "jspdf";
import QRCode from "qrcode";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const detailLevel = ref(1)
const detailLevelLabel = computed(() => ['grob', 'mittel', 'fein'][detailLevel.value])

const tabs = ['Zeitpläne', 'Namensschilder']
const activeTab = ref(tabs[0])

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
</script>

<template>
  <!--<h1 class="text-2xl font-bold">Veröffentlichung</h1>
  <Card title="Detailgrad des öffentlichen Zeitplans">
    <div class="flex justify-between items-center space-x-4 p-4">
      <span>Grob</span>
      <input type="range" min="0" max="2" step="1" v-model="detailLevel" @change="updateDetailLevel"/>
      <span>Fein</span>
    </div>
  </Card>
  <Card title="QR-Code Downloads">
    <Tabs :tabs="['WLAN', 'Zeitplan']" v-model="activeTab"/>
    <div v-if="activeTab === 'WLAN'" class="p-4">
      <a
          v-if="event?.wifi_ssid"
          :href="`/events/${event.id}/qr-code/wifi`"
          target="_blank"
          class="text-blue-600 underline"
      >WLAN QR-Code herunterladen</a>
    </div>
    <div v-if="activeTab === 'Zeitplan'" class="p-4">
      <a
          :href="`/events/${event.id}/qr-code/schedule`"
          target="_blank"
          class="text-blue-600 underline"
      >Zeitplan QR-Code herunterladen</a>
    </div>
  </Card>-->
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Veröffentlichungskontrolle</h1>

    <div class="grid gap-6 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3">

      <!-- Detail Slider Card -->
      <div class="rounded-xl shadow bg-white p-4 flex flex-col justify-between col-span-2">
        <h2 class="text-lg font-semibold mb-4">Detailgrad der öffentlichen Ansicht</h2>
        <div>
          <span>
            Öffentlicher Link: {{ eventPublicLink }}
          </span>
        </div>
        <div class="flex justify-between items-center space-x-2">
          <span class="text-sm">grob</span>
          <input type="range" min="0" max="2" step="1" v-model="detailLevel" class="flex-1 accent-blue-500">
          <span class="text-sm">fein</span>
        </div>
        <div class="text-center text-sm mt-2 text-gray-500">
          Aktuell: <strong>{{ detailLevelLabel }}</strong>
        </div>
      </div>

      <!-- Downloads Card -->
      <div class="rounded-xl shadow bg-white p-4 flex flex-col justify-between">
        <h2 class="text-lg font-semibold mb-4">Downloads</h2>
        <ul class="space-y-2">
          <li>
            <button
                class="w-full px-4 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 disabled:bg-gray-300"
                :disabled="!event?.wifi_ssid"
                @click="generateWifiPDF"
            >
              WLAN-Zugang als QR
            </button>
          </li>
          <li>
            <button
                class="w-full px-4 py-2 rounded bg-blue-500 text-white hover:bg-blue-600"
                @click="downloadScheduleQr"
            >
              Zeitplan als QR
            </button>
          </li>
        </ul>
      </div>

      <!-- Print Options (Tabbed) -->
      <div class="rounded-xl shadow bg-white p-4 flex flex-col">
        <h2 class="text-lg font-semibold mb-4">Druckoptionen</h2>

        <div class="flex space-x-2 mb-4">
          <button
              v-for="tab in tabs"
              :key="tab"
              :class="['px-4 py-2 rounded', activeTab === tab ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-800']"
              @click="activeTab = tab"
          >
            {{ tab }}
          </button>
        </div>

        <div v-if="activeTab === 'Zeitpläne'" class="space-y-2">
          <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded" @click="printSchedule">
            Zeitpläne drucken
          </button>
        </div>

        <div v-else-if="activeTab === 'Namensschilder'" class="space-y-2">
          <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded" @click="printNameTags">
            Namensschilder drucken
          </button>
        </div>

        <!-- Add future tabs here -->

      </div>

    </div>
  </div>
</template>

<style scoped>

</style>