<script setup lang="ts">
import { ref, computed } from 'vue'
import { useEventStore } from '@/stores/event'
import QRCode from "qrcode"
import jsPDF from "jspdf"
import axios from 'axios'

// Assets (Fake)
import qr1Png from '@/assets/fake/qr1.png'
import qr1Pdf from '@/assets/fake/qr1.pdf'
import qr2Png from '@/assets/fake/qr2.png'
import qr2Pdf from '@/assets/fake/qr2.pdf'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

// Fake Link
const publicLink = ref("https://flow.hands-on-technology.org/braunschweig")

// Slider
const levels = ["Planung", "Stand nach Anmeldeschluss", "Überblick", "volle Details"]
const detailLevel = ref(0)

// Kopieren / Öffnen
function openLink() {
  window.open(publicLink.value, "_blank", "noopener")
}
async function copyLink() {
  try {
    await navigator.clipboard.writeText(publicLink.value)
    alert("Link kopiert!")
  } catch (e) {
    console.error("Kopieren fehlgeschlagen", e)
  }
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

// --- Downloads ---
async function downloadPng(dataUrl: string, filename: string) {
  const a = document.createElement("a")
  a.href = dataUrl
  a.download = filename
  a.click()
}

async function downloadPdf(dataUrl: string, filename: string) {
  const pdf = new jsPDF()
  pdf.addImage(dataUrl, "PNG", 20, 20, 100, 100)
  pdf.save(filename)
}
</script>

<template>
  <div class="p-6 space-y-8">
    <h1 class="text-2xl font-bold">Zugriff auf den Plan</h1>

    <!-- Online Box -->
    <div class="rounded-xl shadow bg-white p-6 space-y-6">
      <h2 class="text-lg font-semibold mb-2">Online</h2>
      <p class="text-sm text-gray-600">
        Dieser Link gibt Teams, Freiwilligen und dem Publikum alle Informationen zur Veranstaltung.
      </p>

      <div class="flex items-center gap-2">
        <span class="text-blue-600 underline">{{ publicLink }}</span>
        <button @click="openLink" title="In neuem Fenster öffnen">🔗</button>
        <button @click="copyLink" title="In Zwischenablage kopieren">📋</button>
      </div>

      <!-- Slider -->
      <div class="mt-4">
        <input type="range" min="0" max="3" step="1" v-model="detailLevel" class="w-full accent-blue-600" />
        <div class="text-center text-sm mt-2">
          Aktuell: <strong>{{ levels[detailLevel] }}</strong>
        </div>
      </div>

      <!-- 5 Bereiche -->
      <div class="rounded-xl shadow bg-white p-4">
        <h2 class="text-lg font-semibold mb-4">Für die Veranstaltung</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-6 text-center">

          <!-- 1: QR Plan PNG -->
          <div>
            <img :src="qr1Png" alt="QR Plan Preview" class="mx-auto w-28 h-28" />
            <a :href="qr1Png" download="plan.png">
              <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PNG</button>
            </a>
          </div>

          <!-- 2: Fake PDF -->
          <div>
            <img :src="qr1Png" alt="QR Plan PDF Preview" class="mx-auto w-28 h-28 border" />
            <a :href="qr1Pdf" download="plan.pdf">
              <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
            </a>
          </div>

          <!-- 3: WLAN Felder -->
          <div>
            <div>
              <label class="block text-xs text-gray-700 mb-1">SSID</label>
              <input v-model="event.wifi_ssid" class="w-full border px-2 py-1 rounded text-sm" />
            </div>
            <div class="mt-2">
              <label class="block text-xs text-gray-700 mb-1">Passwort</label>
              <input v-model="event.wifi_password" class="w-full border px-2 py-1 rounded text-sm" />
            </div>
          </div>

          <!-- 4: QR Wifi PNG -->
          <div>
            <img :src="qr2Png" alt="QR Wifi Preview" class="mx-auto w-28 h-28" />
            <a :href="qr2Png" download="wifi.png">
              <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PNG</button>
            </a>
          </div>

          <!-- 5: Fake PDF beide -->
          <div>
            <img :src="qr2Png" alt="QR Both Preview" class="mx-auto w-28 h-28 border" />
            <a :href="qr2Pdf" download="wifi-plan.pdf">
              <button class="mt-2 px-3 py-1 bg-gray-200 rounded text-sm">PDF</button>
            </a>
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
    </div>
  </div>
</template>