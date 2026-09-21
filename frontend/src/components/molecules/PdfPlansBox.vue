<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue'
import { useEventStore } from '@/stores/event'
import { usePdfExport } from '@/composables/usePdfExport'
import Spinner from '@/components/atoms/Spinner.vue'
import axios from 'axios'
import {flowFilename} from '@/utils/flowFilename'

const props = withDefaults(
  defineProps<{
    /** Hide inner title when the page already provides one. */
    hideHeading?: boolean
    /** Which panels to show. Labels live on Namensschilder now. */
    section?: 'plans' | 'all'
    /** Fixed 50/50 dual panes for the two plans groups (Drucksachen). */
    splitPanes?: boolean
  }>(),
  {hideHeading: false, section: 'all', splitPanes: false}
)

const showPlans = computed(() => props.section === 'plans' || props.section === 'all')

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const eventId = computed(() => event.value?.id)

onMounted(async () => {
  if (!eventStore.selectedEvent) await eventStore.fetchSelectedEvent()
  if (eventStore.selectedEvent?.id) {
    await loadPosterPreviews()
  }
})

watch(() => event.value?.id, async (id) => {
  if (id) {
    await loadPosterPreviews()
  }
})

const hasWifiSsid = computed(() => !!event.value?.wifi_ssid?.trim())

const { isDownloading, downloadPdf } = usePdfExport()

const flowHint = (name: string, ext = 'pdf') => flowFilename(name, ext, event.value?.date)

const previewPlan = ref<string | null>(null)
const previewPlanWifi = ref<string | null>(null)

async function loadPosterPreview(type: 'plan' | 'plan_wifi') {
  if (!eventId.value) return
  try {
    const {data} = await axios.get(`/publish/pdf_preview/${type}/${eventId.value}?_=${Date.now()}`)
    if (type === 'plan') previewPlan.value = data
    else previewPlanWifi.value = data
  } catch (e) {
    console.error(`Poster-Preview (${type}) fehlgeschlagen:`, e)
  }
}

async function loadPosterPreviews() {
  await Promise.all([loadPosterPreview('plan'), loadPosterPreview('plan_wifi')])
}
</script>

<template>
  <div class="pdf-plans">
    <div
        v-if="showPlans"
        class="pdf-plans__plans"
        :class="{'pdf-plans__plans--split': splitPanes}"
    >
      <section class="glass-card liquid-surface-inner pdf-plans__panel">
        <h3 v-if="!hideHeading" class="glass-card__heading">Drucksachen</h3>
      <p class="pdf-plans__group-label">
        <i class="bi bi-people" aria-hidden="true"/>
        <span>Zum Aushang bzw. zum Verteilen an Teams und Volunteers</span>
      </p>
      <div class="pdf-plans__grid">

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">Online-Plan</h4>
          <p class="pdf-plans__tile-sub">Aushang mit QR zum Link zur öffentlichen Seite.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <img
            v-if="previewPlan"
            :src="previewPlan"
            alt="Vorschau Online-Plan PDF"
            class="pdf-plans__preview"
          />
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.plan ? '!opacity-50' : ''"
            :disabled="isDownloading.plan || !eventId"
            @click="downloadPdf('plan', `/publish/pdf_download/plan/${eventId}`, flowHint('Plan'))"
          >
            <Spinner v-if="isDownloading.plan" size="sm"/>
            <span>{{ isDownloading.plan ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>

      <article class="pdf-plans__tile liquid-surface-inner">
        <header class="pdf-plans__tile-head">
          <h4 class="pdf-plans__tile-title">WLAN-Zugang</h4>
          <p class="pdf-plans__tile-sub">Druckposter mit Netzwerkdaten — Zugang unter WLAN vor Ort pflegen.</p>
        </header>
        <div class="pdf-plans__tile-body">
          <template v-if="hasWifiSsid">
            <img
              v-if="previewPlanWifi"
              :src="previewPlanWifi"
              alt="Vorschau WLAN-PDF"
              class="pdf-plans__preview"
            />
          </template>
          <p v-else class="pdf-plans__tile-note">SSID fehlt — unter WLAN vor Ort eintragen.</p>
        </div>
        <footer class="pdf-plans__tile-actions">
          <button
            type="button"
            class="glass-btn-secondary !px-3.5 !py-1.5 !text-sm inline-flex items-center gap-2"
            :class="isDownloading.plan_wifi || !hasWifiSsid ? '!opacity-50' : ''"
            :disabled="isDownloading.plan_wifi || !eventId || !hasWifiSsid"
            @click="downloadPdf('plan_wifi', `/publish/pdf_download/plan_wifi/${eventId}`, flowHint('Plan_mit_WLAN'))"
          >
            <Spinner v-if="isDownloading.plan_wifi" size="sm"/>
            <span>{{ isDownloading.plan_wifi ? 'Erzeuge…' : 'PDF' }}</span>
          </button>
        </footer>
      </article>
      </div>

      </section>
    </div>
  </div>
</template>

<style scoped>
.pdf-plans {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.pdf-plans__panel {
  min-width: 0;
}

.pdf-plans__plans {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  min-width: 0;
}

.pdf-plans__plans--split {
  flex: 1 1 0%;
  min-height: 0;
  flex-direction: row;
  gap: 1rem 1.25rem;
  overflow: hidden;
}

.pdf-plans__plans--split > .pdf-plans__panel {
  flex: 1 1 0%;
  min-width: 0;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
}

.pdf-plans__group-label {
  margin: 0 0 0.75rem;
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  font-size: 0.82rem;
  line-height: 1.4;
  color: var(--color-text-muted);
}

.pdf-plans__group-label .bi {
  flex-shrink: 0;
  margin-top: 0.12rem;
  font-size: 1rem;
  color: var(--color-accent);
}

.pdf-plans__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(16.5rem, 1fr));
  gap: 0.85rem;
  align-items: stretch;
}

.pdf-plans__tile {
  display: flex;
  flex-direction: column;
  gap: 0.7rem;
  min-height: 13.75rem;
  height: 100%;
  padding: 1rem 1.05rem 1.05rem;
  border-radius: var(--radius-lg);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 38%, var(--liquid-border-soft));
  background: color-mix(in srgb, #ffffff 90%, var(--liquid-tile-bg-inner));
  box-shadow:
    0 8px 20px rgba(15, 23, 42, 0.045),
    inset 0 1px 0 rgba(255, 255, 255, 0.9);
}

.pdf-plans__tile-head {
  min-height: 3.6rem;
}

.pdf-plans__tile-title {
  margin: 0;
  font-size: 0.98rem;
  font-weight: 650;
  letter-spacing: -0.015em;
  color: var(--color-text);
  line-height: 1.3;
}

.pdf-plans__tile-sub {
  margin: 0.28rem 0 0;
  font-size: 0.8rem;
  line-height: 1.4;
  color: var(--color-text-muted);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.pdf-plans__tile-body {
  flex: 1 1 auto;
  min-height: 3.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.pdf-plans__tile-actions {
  margin-top: auto;
  display: flex;
  flex-wrap: wrap;
  gap: 0.45rem;
  justify-content: flex-end;
  align-items: center;
}

.pdf-plans__tile-note {
  margin: 0;
  font-size: 0.78rem;
  line-height: 1.35;
  color: var(--color-text-muted);
}

.pdf-plans__preview {
  height: 3.25rem;
  width: auto;
  max-width: 5.5rem;
  object-fit: contain;
  border-radius: 6px;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
  background: #fff;
}

@media (max-width: 640px) {
  .pdf-plans__grid {
    grid-template-columns: 1fr;
  }
}
</style>
