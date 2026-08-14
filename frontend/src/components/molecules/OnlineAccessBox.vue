<script setup lang="ts">
import {ref, computed, watch} from 'vue'
import axios from 'axios'
import {useEventStore} from '@/stores/event'
import {useAuth} from '@/composables/useAuth'
import {formatTimeOnly} from '@/utils/dateTimeFormat'
import SavingToast from '@/components/atoms/SavingToast.vue'
import {showGlassToast} from '@/composables/useGlassToast'

withDefaults(
    defineProps<{
      /** embed: parent owns link chrome (Verteilung). */
      embed?: boolean
      /** Hide the visibility matrix / times peek. */
      hidePreview?: boolean
    }>(),
    {
      embed: false,
      hidePreview: false,
    }
)

const emit = defineEmits<{
  'update:detailLevel': [level: number]
}>()

function toLocalDateString(dateInput: string | null | undefined): string {
  if (!dateInput) return ''
  const d = new Date(dateInput)
  if (isNaN(d.getTime())) return ''
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function formatShortWeekday(dateInput: string | null | undefined): string {
  if (!dateInput) return ''
  const d = new Date(dateInput)
  if (isNaN(d.getTime())) return ''
  return new Intl.DateTimeFormat('de-DE', {weekday: 'short'}).format(d)
}

function eventSpansMultipleDays(plan: any): boolean {
  if (!plan) return false
  const dates = new Set<string>()
  const add = (arr: any[] | undefined) => {
    if (!Array.isArray(arr)) return
    arr.forEach((item: any) => {
      if (item?.value) dates.add(toLocalDateString(item.value))
    })
  }
  add(plan.explore_morning)
  add(plan.explore_afternoon)
  add(plan.explore)
  add(plan.challenge)
  return dates.size > 1
}

function getTimeDisplay(isoDateTime: string | null | undefined, showWeekday: boolean): string {
  if (!isoDateTime) return ''
  const timeOnly = formatTimeOnly(isoDateTime, true)
  if (showWeekday) {
    const wd = formatShortWeekday(isoDateTime)
    return wd ? `${wd}, ${timeOnly}` : timeOnly
  }
  return timeOnly
}

const previewShowWeekday = computed(() => eventSpansMultipleDays(scheduleInfo.value?.plan))

const eventStore = useEventStore()
const event = computed(() => eventStore.selectedEvent)
const {isAdmin} = useAuth()

const scheduleInfo = ref<any>(null)
const regenerating = ref(false)
const saving = ref(null)

const levels = ['Planung und Anmeldung', 'Überblick zum Ablauf', 'volle Details']
const levelShort = ['Basis', 'Ablauf', 'Alles']
const levelHints = [
  'Datum, Adresse, Kontakt, Teams',
  'zusätzlich wichtige Zeiten',
  'zusätzlich interaktiver Online-Zeitplan',
]
const detailLevel = ref<number | undefined>(undefined)
const activeLevel = computed(() => detailLevel.value ?? 0)

const visibilityRows = [
  {label: 'Datum, Ort, Kontakt, Teams', from: 0},
  {label: 'Wichtige Zeiten', from: 1},
  {label: 'Online-Zeitplan', from: 2},
]

watch(detailLevel, (level) => {
  if (level != null) emit('update:detailLevel', level)
})

function frontendToBackendLevel(frontendLevel: number): number {
  if (frontendLevel === 0) return 1
  if (frontendLevel === 1) return 3
  return 4
}

function backendToFrontendLevel(backendLevel: number): number {
  if (backendLevel === 1) return 0
  if (backendLevel === 2) return 0
  if (backendLevel === 3) return 1
  if (backendLevel === 4) return 2
  return 0
}

async function fetchPublicationLevel() {
  try {
    const {data} = await axios.get(`/publish/level/${event.value?.id}`)
    detailLevel.value = backendToFrontendLevel(data.level ?? 1)
  } catch (e) {
    if (import.meta.env.DEV) console.error('Fehler beim Laden des Publication Levels:', e)
    detailLevel.value = 0
  }
}

async function updatePublicationLevel(level: number) {
  try {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft
    saving?.value?.show()
    requestAnimationFrame(() => window.scrollTo(scrollLeft, scrollTop))
    await axios.post(`/publish/level/${event.value?.id}`, {level: frontendToBackendLevel(level)})
    await new Promise((resolve) => setTimeout(resolve, 400))
    requestAnimationFrame(() => window.scrollTo(scrollLeft, scrollTop))
  } catch (e) {
    if (import.meta.env.DEV) console.error('Fehler beim Setzen des Publication Levels:', e)
  } finally {
    saving.value?.hide()
  }
}

async function fetchScheduleInformation() {
  try {
    const {data} = await axios.post(`/publish/information/${event.value?.id}`, {level: 4})
    scheduleInfo.value = data
  } catch (e) {
    if (import.meta.env.DEV) console.error('Fehler beim Laden von Schedule Information:', e)
    scheduleInfo.value = null
  }
}

watch(
    () => event.value?.id,
    async (id) => {
      if (!id) return
      await Promise.all([fetchPublicationLevel(), fetchScheduleInformation()])
    },
    {immediate: true}
)

watch(detailLevel, (newLevel, oldLevel) => {
  if (event.value?.id && oldLevel !== undefined && newLevel !== oldLevel) {
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft
    updatePublicationLevel(newLevel).then(() => {
      requestAnimationFrame(() => window.scrollTo(scrollLeft, scrollTop))
    })
  }
})

function previewOnlinePlan() {
  const planId = scheduleInfo.value?.plan?.plan_id
  if (!planId) return
  const baseUrl = import.meta.env.VITE_APP_URL || window.location.origin
  window.open(`${baseUrl}/public-schedule/${planId}`, '_blank')
}

async function regenerateLinkAndQR() {
  if (!event.value?.id) return
  try {
    regenerating.value = true
    const {data} = await axios.post(`/publish/regenerate/${event.value.id}`)
    const baseUrl = import.meta.env.VITE_APP_URL || window.location.origin
    if (eventStore.selectedEvent) {
      eventStore.selectedEvent.link = `${baseUrl}/${data.link}`
      eventStore.selectedEvent.qrcode = data.qrcode.replace('data:image/png;base64,', '')
      eventStore.selectedEvent.slug = data.link
    }
  } catch (error) {
    if (import.meta.env.DEV) console.error('Error regenerating link and QR code:', error)
    showGlassToast('Fehler beim Regenerieren des Links und QR-Codes', 'error')
  } finally {
    regenerating.value = false
  }
}

defineExpose({
  detailLevel,
  levels,
  regenerating,
  regenerateLinkAndQR,
})
</script>

<template>
  <SavingToast ref="saving" message="Sichtbarkeit wird gespeichert…" />

  <section class="vis" style="overflow-anchor: none;" aria-labelledby="vis-heading">
    <div v-if="!embed" class="vis__legacy-link">
      <a
          v-if="event?.link"
          :href="event?.link"
          target="_blank"
          rel="noopener"
          class="vis__legacy-url"
      >{{ event?.link }}</a>
      <button
          v-if="isAdmin && event?.id"
          type="button"
          class="glass-btn-secondary !px-3 !py-1.5 !text-sm"
          :disabled="regenerating"
          @click="regenerateLinkAndQR"
      >
        {{ regenerating ? '…' : 'Link & QR neu' }}
      </button>
    </div>

    <header class="vis__head">
      <div>
        <h2 id="vis-heading" class="vis__title">Sichtbarkeit</h2>
        <p class="vis__sub">Welche Inhalte auf dem öffentlichen Link sichtbar sind.</p>
      </div>
      <span class="vis__status" aria-live="polite">Aktiv: {{ levelShort[activeLevel] }}</span>
    </header>

    <div class="vis__levels" role="radiogroup" aria-label="Sichtbarkeitsstufe">
      <button
          v-for="(label, idx) in levels"
          :key="idx"
          type="button"
          role="radio"
          class="vis__level"
          :class="{'is-active': activeLevel === idx}"
          :aria-checked="activeLevel === idx"
          @click="detailLevel = idx"
      >
        <span class="vis__level-idx">{{ idx + 1 }}</span>
        <span class="vis__level-body">
          <span class="vis__level-name">{{ label }}</span>
          <span class="vis__level-hint">{{ levelHints[idx] }}</span>
        </span>
      </button>
    </div>

    <div v-if="!hidePreview" class="vis__matrix">
      <div class="vis__matrix-head">
        <h3 class="vis__matrix-title">Inhalt</h3>
        <button
            v-if="activeLevel >= 2 && scheduleInfo?.plan?.plan_id"
            type="button"
            class="vis__matrix-action"
            @click="previewOnlinePlan"
        >
          Zeitplan-Vorschau
        </button>
      </div>
      <table class="vis__table">
        <thead>
          <tr>
            <th>Inhalt</th>
            <th>Sichtbar</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibilityRows" :key="row.label">
            <td>{{ row.label }}</td>
            <td>
              <span
                  class="vis__pill"
                  :class="activeLevel >= row.from ? 'is-on' : 'is-off'"
              >
                {{ activeLevel >= row.from ? 'Ja' : 'Nein' }}
              </span>
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="scheduleInfo && activeLevel >= 1" class="vis__times">
        <p class="vis__times-label">Zeiten (Auszug)</p>
        <template v-if="scheduleInfo.plan?.challenge?.length">
          <div
              v-for="(timeEntry, timeIdx) in scheduleInfo.plan.challenge.slice(0, 4)"
              :key="'c' + timeIdx"
              class="vis__times-row"
          >
            <span>{{ timeEntry.label }}</span>
            <span>{{ getTimeDisplay(timeEntry.value, previewShowWeekday) }}</span>
          </div>
        </template>
        <template v-else-if="scheduleInfo.plan?.explore?.length">
          <div
              v-for="(timeEntry, timeIdx) in scheduleInfo.plan.explore.slice(0, 4)"
              :key="'e' + timeIdx"
              class="vis__times-row"
          >
            <span>{{ timeEntry.label }}</span>
            <span>{{ getTimeDisplay(timeEntry.value, previewShowWeekday) }}</span>
          </div>
        </template>
        <p v-else class="vis__times-empty">Keine Zeiten geladen.</p>
      </div>
    </div>
  </section>
</template>

<style scoped>
.vis {
  display: flex;
  flex-direction: column;
  gap: 0.95rem;
  padding: 0.95rem 1.05rem 1.1rem;
  border-radius: 0.75rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 40%, transparent);
  background: #fff;
}

.vis__legacy-link {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.65rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 18%, transparent);
}

.vis__legacy-url {
  flex: 1;
  min-width: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: var(--color-text);
  word-break: break-all;
}

.vis__head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
}

.vis__title {
  margin: 0;
  font-size: 0.82rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.vis__sub {
  margin: 0.2rem 0 0;
  font-size: 0.88rem;
  color: var(--color-text-muted);
}

.vis__status {
  font-size: 0.8rem;
  font-weight: 650;
  color: var(--color-text);
  padding: 0.25rem 0.55rem;
  border-radius: 0.4rem;
  background: color-mix(in srgb, var(--color-bg-muted) 70%, #fff);
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 28%, transparent);
}

.vis__levels {
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.4rem;
}

@media (min-width: 720px) {
  .vis__levels {
    grid-template-columns: repeat(3, 1fr);
  }
}

.vis__level {
  display: flex;
  align-items: flex-start;
  gap: 0.6rem;
  width: 100%;
  text-align: left;
  padding: 0.7rem 0.75rem;
  border-radius: 0.55rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 32%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 35%, #fff);
  cursor: pointer;
  transition: border-color 0.12s ease, background 0.12s ease;
}

.vis__level:hover {
  background: #fff;
  border-color: color-mix(in srgb, var(--color-border-strong) 55%, transparent);
}

.vis__level.is-active {
  background: #fff;
  border-color: color-mix(in srgb, var(--color-accent) 50%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-accent) 25%, transparent);
}

.vis__level-idx {
  flex-shrink: 0;
  width: 1.4rem;
  height: 1.4rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.35rem;
  font-size: 0.75rem;
  font-weight: 750;
  color: var(--color-text-muted);
  background: #fff;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 35%, transparent);
}

.vis__level.is-active .vis__level-idx {
  color: var(--color-on-accent, #fff);
  background: var(--color-accent);
  border-color: var(--color-accent);
}

.vis__level-body {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  min-width: 0;
}

.vis__level-name {
  font-size: 0.88rem;
  font-weight: 700;
  color: var(--color-text);
  line-height: 1.25;
}

.vis__level-hint {
  font-size: 0.76rem;
  color: var(--color-text-muted);
  line-height: 1.35;
}

.vis__matrix {
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
  padding-top: 0.85rem;
  border-top: 1px solid color-mix(in srgb, var(--color-border-strong) 18%, transparent);
}

.vis__matrix-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.vis__matrix-title {
  margin: 0;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.02em;
  text-transform: uppercase;
  color: var(--color-text-muted);
}

.vis__matrix-action {
  padding: 0;
  border: 0;
  background: none;
  font-size: 0.8rem;
  font-weight: 650;
  color: var(--color-accent);
  cursor: pointer;
  text-decoration: underline;
}

.vis__table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.86rem;
}

.vis__table th,
.vis__table td {
  padding: 0.45rem 0.35rem;
  border-bottom: 1px solid color-mix(in srgb, var(--color-border-strong) 16%, transparent);
  text-align: left;
}

.vis__table th {
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--color-text-subtle);
}

.vis__table td:last-child {
  width: 5.5rem;
  text-align: right;
}

.vis__pill {
  display: inline-block;
  min-width: 2.4rem;
  padding: 0.12rem 0.45rem;
  border-radius: 0.3rem;
  font-size: 0.74rem;
  font-weight: 700;
  text-align: center;
}

.vis__pill.is-on {
  color: #166534;
  background: #dcfce7;
}

.vis__pill.is-off {
  color: var(--color-text-subtle);
  background: color-mix(in srgb, var(--color-bg-muted) 80%, #fff);
}

.vis__times {
  margin-top: 0.15rem;
  padding: 0.65rem 0.75rem;
  border-radius: 0.5rem;
  border: 1px solid color-mix(in srgb, var(--color-border-strong) 22%, transparent);
  background: color-mix(in srgb, var(--color-bg-muted) 40%, #fff);
}

.vis__times-label {
  margin: 0 0 0.4rem;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--color-text-subtle);
}

.vis__times-row {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  font-size: 0.8rem;
  padding: 0.18rem 0;
  color: var(--color-text-muted);
}

.vis__times-row span:last-child {
  font-weight: 650;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.vis__times-empty {
  margin: 0;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}
</style>
