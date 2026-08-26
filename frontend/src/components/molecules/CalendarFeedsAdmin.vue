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

const ICS_PRODID = '-//HANDS on TECHNOLOGY//FLOW//DE'

const toCrlf = (text) => String(text).replace(/\r\n/g, '\n').replace(/\n/g, '\r\n')

const wrapOneEventIcs = (calName, vevent) => {
  const block = toCrlf(String(vevent).trim())
  const name = String(calName || 'HANDS on TECHNOLOGY Veranstaltungen')
      .replace(/\\/g, '\\\\')
      .replace(/;/g, '\\;')
      .replace(/,/g, '\\,')
  return [
    'BEGIN:VCALENDAR',
    'VERSION:2.0',
    `PRODID:${ICS_PRODID}`,
    'CALSCALE:GREGORIAN',
    'METHOD:PUBLISH',
    `X-WR-CALNAME:${name}`,
    block,
    'END:VCALENDAR',
  ].join('\r\n') + '\r\n'
}

const openEventIcs = (event) => {
  const vevent = event?.vevent
  if (!vevent) {
    showGlassToast('Kein ICS-Eintrag vorhanden', 'info')
    return
  }
  const ics = wrapOneEventIcs(preview.value?.label, vevent)
  const blob = new Blob([ics], {type: 'text/calendar;charset=utf-8'})
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `event-${event.event_id || 'event'}.ics`
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

const isCancelled = (event) => String(event?.status || '').toUpperCase() === 'CANCELLED'

const hasLocationProperty = (event) => event?.location !== null && event?.location !== undefined

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
        <router-link to="/plan/admin/wartung" class="text-[var(--color-accent)] hover:underline">Wartung</router-link>.
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
          class="glass-card liquid-surface-inner"
          :class="isCancelled(event) ? 'border-l-4 border-l-red-500' : ''"
      >
        <div class="flex flex-wrap items-start justify-between gap-2 mb-3">
          <p v-if="isCancelled(event)" class="text-xs font-semibold uppercase tracking-wide px-2 py-0.5 rounded bg-red-100 text-red-800">
            STATUS: CANCELLED
          </p>
          <button
              type="button"
              class="shrink-0 ml-auto px-3 py-1.5 rounded border border-[var(--color-border)] hover:bg-[var(--color-bg-hover)] transition-colors text-sm disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="!event.vevent"
              title="Einen .ics für diesen Eintrag herunterladen und in der Kalender-App öffnen"
              @click="openEventIcs(event)"
          >
            <i class="bi bi-calendar-plus mr-1" aria-hidden="true"/>
            In Kalender öffnen
          </button>
        </div>

        <dl class="grid gap-3 text-sm">
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">SUMMARY</dt>
            <dd class="whitespace-pre-wrap break-words">{{ event.summary || '' }}</dd>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">DTSTART</dt>
              <dd class="break-all">{{ event.dtstart || '' }}</dd>
            </div>
            <div>
              <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">DTEND</dt>
              <dd class="break-all">{{ event.dtend || '' }}</dd>
            </div>
          </div>
          <div v-if="hasLocationProperty(event)">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">LOCATION</dt>
            <dd class="whitespace-pre-wrap break-words">{{ event.location }}</dd>
          </div>
          <div v-if="event.url">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">URL</dt>
            <dd class="break-all">{{ event.url }}</dd>
          </div>
          <div v-if="event.description">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">DESCRIPTION</dt>
            <dd class="whitespace-pre-wrap break-words">{{ event.description }}</dd>
          </div>
          <div v-if="event.uid">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">UID</dt>
            <dd class="break-all">{{ event.uid }}</dd>
          </div>
          <div v-if="event.sequence != null">
            <dt class="text-xs font-medium uppercase tracking-wide text-[var(--color-text-subtle)]">SEQUENCE</dt>
            <dd>{{ event.sequence }}</dd>
          </div>
        </dl>
        <p v-if="event.built_at" class="mt-3 text-xs text-[var(--color-text-subtle)]">
          event_calendar.built_at {{ formatBuiltAt(event.built_at) }}
        </p>
      </article>
    </div>
  </div>
</template>
