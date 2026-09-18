<script setup lang="ts">
import {computed, ref} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'

defineOptions({name: 'RoomSheetsPrint'})

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)
const busy = ref(false)

async function downloadPdf() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const response = await axios.post(
      `/print/${eventId.value}/room-sheets`,
      {},
      {responseType: 'blob'},
    )
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Raumplaene', 'pdf', eventDate.value)
    link.click()
    window.URL.revokeObjectURL(url)
  } catch {
    showGlassToast('PDF erzeugen fehlgeschlagen', 'error')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="glass-card liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <div>
        <h2 class="role-sheets__title">Raumpläne</h2>
        <p class="role-sheets__sub">Eine Seite pro Raum.</p>
      </div>
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="!eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </header>
  </section>
</template>

<style scoped>
.role-sheets {
  padding: 1rem 1.25rem;
  margin-bottom: 1rem;
}

.role-sheets__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0;
}

.role-sheets__title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 650;
}

.role-sheets__sub {
  margin: 0.2rem 0 0;
  font-size: 0.875rem;
  color: var(--color-text-subtle);
}
</style>
