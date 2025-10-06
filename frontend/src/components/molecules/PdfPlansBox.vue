<script setup lang="ts">
import axios from 'axios'
import { computed } from 'vue'
import { useEventStore } from '@/stores/event'

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)

async function downloadPdf(type: 'rooms' | 'teams' | 'roles' | 'full') {
  if (!event.value?.id) return
  const url = `/export/pdf_download/${type}/${event.value.id}`

  try {
    const response = await axios.get(url, { responseType: 'blob' })
    const filename = response.headers['x-filename'] || `FLOW_${type}.pdf`

    const blob = new Blob([response.data], { type: 'application/pdf' })
    const link = document.createElement('a')
    link.href = window.URL.createObjectURL(blob)
    link.download = filename
    link.click()
    window.URL.revokeObjectURL(link.href)
  } catch (e) {
    console.error(`Fehler beim Download von ${type}:`, e)
  }
}

</script>

<template>
  <div class="rounded-xl shadow bg-white p-6 flex flex-col">
    <h3 class="text-lg font-semibold mb-4">Pläne als PDF</h3>

    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Rollen (Juror:innen / Gutachter:innen / Schiedsrichter:innen)</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Rolle mit allen Aktivitäten.</p>
      </div>
 <!--     <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('roles')">PDF</button> -->
    </div>

        <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Teams</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Team mit allen Aktivitäten.</p>
      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('teams')">PDF</button> 
    </div>

    <div class="flex justify-between items-center border-b border-gray-200 pb-3 mb-3">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Räume</h4>
        <p class="text-sm text-gray-600">Eine Seite pro Raum mit allen Aktivitäten.</p>
      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('rooms')">PDF</button>
    </div>

    <div class="flex justify-between items-center">
      <div class="flex-1 pr-4">
        <h4 class="text-base font-semibold text-gray-800">Gesamtplan</h4>
        <p class="text-sm text-gray-600">Volle Details, aber in einfacher Formatierung.</p>
        <p class="text-xs text-gray-500">Nur für den Veranstalter – nicht für Teams oder Besucher.</p>
      </div>
      <button class="px-4 py-2 bg-gray-200 rounded text-sm hover:bg-gray-300" @click="downloadPdf('full')">PDF</button>
    </div>
  </div>
</template>