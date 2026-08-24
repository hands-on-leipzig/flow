<script setup>
import {ref, watch, onMounted} from 'vue'
import axios from 'axios'
import dayjs from 'dayjs'
import {showGlassToast} from '@/composables/useGlassToast'

defineOptions({name: 'CalendarFeedsAdmin'})

const feeds = ref([])
const selectedKey = ref('all')
const preview = ref(null)
const loadingFeeds = ref(false)
const loadingPreview = ref(false)
const error = ref(null)
let previewSeq = 0

const loadFeeds = async () => {
  loadingFeeds.value = true
  error.value = null
  try {
    const {data} = await axios.get('/admin/calendar/feeds')
    feeds.value = Array.isArray(data) ? data : []
    if (!feeds.value.some((feed) => feed.key === selectedKey.value)) {
      selectedKey.value = feeds.value[0]?.key || 'all'
    }
  } catch (err) {
    error.value = err.response?.data?.error || 'Kalender-Feeds konnten nicht geladen werden.'
    feeds.value = []
  } finally {
    loadingFeeds.value = false
  }
}

const loadPreview = async () => {
  const key = selectedKey.value
  if (!key) {
    preview.value = null
    return
  }
  const seq = ++previewSeq
  loadingPreview.value = true
  error.value = null
  try {
    const {data} = await axios.get(`/admin/calendar/feeds/${key}`)
    if (seq !== previewSeq) return
    preview.value = data
  } catch (err) {
    if (seq !== previewSeq) return
    preview.value = null
    error.value = err.response?.status === 404
      ? 'Unbekannter Kalender-Feed'
      : (err.response?.data?.error || 'Vorschau konnte nicht geladen werden.')
  } finally {
    if (seq === previewSeq) {
      loadingPreview.value = false
    }
  }
}

const copyUrl = async () => {
  const url = preview.value?.url
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    showGlassToast('Abo-URL kopiert', 'success')
  } catch {
    showGlassToast('URL konnte nicht kopiert werden', 'error')
  }
}

const openIcs = () => {
  const url = preview.value?.url
  if (url) window.open(url, '_blank', 'noopener')
}

const isCancelled = (event) => String(event?.status || '').toUpperCase() === 'CANCELLED'

const formatIsoDate = (iso) => {
  if (!iso) return ''
  const parsed = dayjs(iso)
  return parsed.isValid() ? parsed.format('DD.MM.YYYY') : iso
}

const inclusiveEnd = (start, end) => {
  if (!start) return ''
  if (!end) return start
  const last = dayjs(end).subtract(1, 'day')
  if (!last.isValid() || last.isBefore(dayjs(start), 'day')) return start
  return last.format('YYYY-MM-DD')
}

const dateRangeLabel = (event) => {
  const start = event?.dtstart
  if (!start) return '—'
  const last = inclusiveEnd(start, event?.dtend)
  if (!last || last === start) return formatIsoDate(start)
  return `${formatIsoDate(start)} – ${formatIsoDate(last)}`
}

const weekdayShort = (iso) => {
  const parsed = dayjs(iso)
  return parsed.isValid() ? parsed.format('dd') : ''
}

const dayNumber = (iso) => {
  const parsed = dayjs(iso)
  return parsed.isValid() ? parsed.format('D') : ''
}

const monthShort = (iso) => {
  const parsed = dayjs(iso)
  return parsed.isValid() ? parsed.format('MMM') : ''
}

const formatBuiltAt = (iso) => {
  if (!iso) return ''
  const parsed = dayjs(iso)
  return parsed.isValid() ? parsed.format('DD.MM.YYYY HH:mm') : iso
}

watch(selectedKey, () => {
  void loadPreview()
}, {immediate: true})

onMounted(() => {
  void loadFeeds()
})
</script>

<template>
  <div class="space-y-6">
    <div>
      <h2 class="text-xl font-bold mb-2">Kalender-Feeds</h2>
      <p class="text-sm text-[var(--color-text-muted)]">
        Vorschau der gespeicherten iCalendar-Einträge. Die Abo-URL kann in Kalender-Apps abonniert werden.
        Kalender-Apps holen den Feed oft erst Stunden später. Neue Texte erscheinen hier sofort nach einem Rebuild —
        einzeln über Plan/Veröffentlichung/DRAHT, oder gesammelt unter
        <router-link to="/plan/admin/hilfsfunktionen" class="text-blue-600 hover:underline">Hilfsfunktionen</router-link>.
      </p>
    </div>

    <div class="glass-surface-lg border border-[var(--color-border)] space-y-4">
      <div class="flex flex-wrap items-end gap-4">
        <label class="block min-w-[16rem] flex-1">
          <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Feed</span>
          <select
              v-model="selectedKey"
              class="w-full px-4 py-2 border border-[var(--color-border)] rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              :disabled="loadingFeeds || feeds.length === 0"
          >
            <option
                v-for="feed in feeds"
                :key="feed.key"
                :value="feed.key"
            >
              {{ feed.label }}
            </option>
          </select>
        </label>
      </div>

      <div>
        <span class="block text-sm font-medium text-[var(--color-text-muted)] mb-1">Abo-URL</span>
        <div class="flex flex-wrap items-center gap-2">
          <input
              type="text"
              readonly
              :value="preview?.url || ''"
              class="flex-1 min-w-[16rem] px-3 py-2 border border-[var(--color-border)] rounded-md bg-[var(--color-bg-muted)] text-sm"
              placeholder="Wird geladen…"
          />
          <button
              type="button"
              class="px-4 py-2 rounded bg-blue-500 text-white hover:bg-blue-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="!preview?.url"
              @click="copyUrl"
          >
            <i class="bi bi-clipboard mr-1" aria-hidden="true"/>
            Kopieren
          </button>
          <button
              type="button"
              class="px-4 py-2 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="!preview?.url"
              @click="openIcs"
          >
            <i class="bi bi-box-arrow-up-right mr-1" aria-hidden="true"/>
            ICS öffnen
          </button>
        </div>
      </div>
    </div>

    <p v-if="loadingPreview" class="text-[var(--color-text-subtle)]">Lade Vorschau…</p>
    <p v-else-if="error" class="text-sm text-red-600">{{ error }}</p>
    <p
        v-else-if="preview && preview.events.length === 0"
        class="text-[var(--color-text-subtle)]"
    >
      Keine Einträge in diesem Feed.
    </p>

    <div v-else-if="preview" class="space-y-3">
      <p class="text-sm text-[var(--color-text-muted)]">
        {{ preview.events.length }}
        {{ preview.events.length === 1 ? 'Eintrag' : 'Einträge' }}
      </p>
      <article
          v-for="event in preview.events"
          :key="event.uid || event.event_id"
          class="glass-card liquid-surface-inner flex gap-4"
          :class="isCancelled(event) ? 'border-l-4 border-l-red-500' : ''"
      >
        <div class="shrink-0 w-16 text-center rounded-lg border border-[var(--color-border)] py-2 px-1 bg-[var(--color-bg-muted)]">
          <div class="text-xs uppercase tracking-wide text-[var(--color-text-subtle)]">
            {{ weekdayShort(event.dtstart) }}
          </div>
          <div class="text-2xl font-semibold leading-none my-1">
            {{ dayNumber(event.dtstart) }}
          </div>
          <div class="text-xs text-[var(--color-text-muted)]">
            {{ monthShort(event.dtstart) }}
          </div>
        </div>

        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2 mb-1">
            <span
                v-if="isCancelled(event)"
                class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded bg-red-100 text-red-800"
            >
              Abgesagt
            </span>
            <h3 class="text-base font-semibold text-[var(--color-text)]">
              {{ event.summary || 'Ohne Titel' }}
            </h3>
          </div>
          <p class="text-sm text-[var(--color-text-muted)] mb-1">
            {{ dateRangeLabel(event) }}
          </p>
          <p
              v-if="event.location !== null && event.location !== undefined"
              class="text-sm text-[var(--color-text-muted)]"
          >
            <i class="bi bi-geo-alt mr-1" aria-hidden="true"/>
            {{ event.location || '—' }}
          </p>
          <p
              v-if="event.description"
              class="text-sm text-[var(--color-text)] whitespace-pre-wrap mt-2 max-h-40 overflow-y-auto"
          >
            {{ event.description }}
          </p>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-2 text-xs text-[var(--color-text-subtle)]">
            <a
                v-if="event.url"
                :href="event.url"
                target="_blank"
                rel="noopener"
                class="text-blue-600 hover:underline"
            >
              Öffentliche Seite
            </a>
            <span v-if="event.sequence != null">Sequenz {{ event.sequence }}</span>
            <span v-if="event.built_at">Stand {{ formatBuiltAt(event.built_at) }}</span>
          </div>
        </div>
      </article>
    </div>
  </div>
</template>
