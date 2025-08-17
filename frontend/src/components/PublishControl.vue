<script setup>

import {ref, computed} from 'vue'
import {useEventStore} from '@/stores/event'
import jsPDF from "jspdf";
import QRCode from "qrcode";
import Card from "@/components/atoms/Card.vue";

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

const detailLevel = ref(1)
const detailLevelLabel = computed(() => ['grob', 'mittel', 'fein'][detailLevel.value])

const tabs = ['Zeitpläne', 'Namensschilder', 'QR-Code WLAN', 'QR-Code Zeitplan']
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
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-6">Veröffentlichungskontrolle</h1>
    <div class="grid gap-6 sm:grid-cols-1 md:grid-cols-2 lg:grid-cols-3">

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

      <div class="rounded-xl shadow bg-white p-4 flex flex-col">
        <h2 class="text-lg font-semibold mb-4">PDFs exportieren</h2>

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

        <div v-else-if="activeTab === 'QR-Code WLAN'" class="space-y-2">
          <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded" :disabled="!event?.wifi_ssid"
                  @click="generateWifiPDF">
            PDF exportieren
          </button>
        </div>

        <div v-else-if="activeTab === 'QR-Code Zeitplan'" class="space-y-2">
          <button class="w-full bg-gray-100 hover:bg-gray-200 px-4 py-2 rounded" @click="downloadScheduleQr">
            PDF exportieren
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>