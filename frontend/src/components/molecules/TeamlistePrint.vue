<script setup lang="ts">
import {computed, ref} from 'vue'
import axios from 'axios'
import Spinner from '@/components/atoms/Spinner.vue'
import {useEventStore} from '@/stores/event'
import {showGlassToast} from '@/composables/useGlassToast'
import {flowFilename} from '@/utils/flowFilename'

defineOptions({name: 'TeamlistePrint'})

const eventStore = useEventStore()
const eventId = computed(() => eventStore.selectedEvent?.id)
const eventDate = computed(() => eventStore.selectedEvent?.date)
const busy = ref(false)

async function downloadPdf() {
  if (!eventId.value || busy.value) return
  busy.value = true
  try {
    const response = await axios.post(
      `/print/${eventId.value}/teamliste`,
      {},
      {responseType: 'blob'},
    )
    const url = window.URL.createObjectURL(response.data)
    const link = document.createElement('a')
    link.href = url
    link.download = response.headers['x-filename'] || flowFilename('Teamliste', 'pdf', eventDate.value)
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
  <article class="liquid-surface-inner role-sheets">
    <header class="role-sheets__head">
      <h2 class="role-sheets__title">Teamliste</h2>
      <p class="role-sheets__sub">Teams mit Räumen und Gutachter-/Jury-Gruppen — für Check-In und Briefings.</p>
    </header>
    <footer class="role-sheets__actions">
      <button
          type="button"
          class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
          :disabled="!eventId || busy"
          @click="downloadPdf"
      >
        <Spinner v-if="busy" size="sm"/>
        <span>{{ busy ? 'Erzeuge…' : 'PDF erzeugen' }}</span>
      </button>
    </footer>
  </article>
</template>

<style scoped>
.role-sheets {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-width: 0;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.role-sheets__title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  line-height: 1.3;
}

.role-sheets__sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.role-sheets__actions {
  display: flex;
  justify-content: flex-end;
  align-items: center;
}
</style>
