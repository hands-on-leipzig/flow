<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, ref, watch} from 'vue'
import {useWiseSync} from '@/composables/useWiseSync'
import {useRouter} from 'vue-router'
import axios from 'axios'

const props = defineProps<{sheet: any}>()
const emit = defineEmits<{update: [sheet: any]}>()
const router = useRouter()

const values = ref<Record<string, string | number>>({})
const error = ref('')
const missing = ref<string[]>([])
const busy = ref(false)
const openSections = ref<number[]>([])
const {setSync} = useWiseSync()
const syncState = ref<'idle' | 'local' | 'syncing' | 'synced' | 'offline'>('idle')
let hydrating = false
let syncTimer = 0

const points = computed(() => {
  if (matchZeroed()) return 0
  let total = 0
  for (const section of props.sheet?.sections || []) {
    for (const item of section.items || []) {
      total += pointsFor(item, values.value[String(item.id)])
    }
  }
  return total
})

watch(values, () => {
  if (!hydrating && !props.sheet?.locked) keepLocal()
  if (!missing.value.length) return
  missing.value = unansweredIds()
  if (!missing.value.length && error.value.includes('jede Zeile')) error.value = ''
}, {deep: true})

watch(() => props.sheet, (sheet) => {
  const next: Record<string, string | number> = {}
  for (const section of sheet?.sections || []) {
    for (const item of section.items || []) {
      if (item.item_type === 'count' && (item.value === null || item.value === undefined || item.value === '')) {
        next[String(item.id)] = 0
      } else if (item.item_type === 'choice' && (item.value === null || item.value === undefined || item.value === '') && item.config?.default !== undefined && item.config?.default !== null) {
        next[String(item.id)] = Number(item.config.default)
      } else if (item.value !== null && item.value !== undefined && item.value !== '') {
        next[String(item.id)] = item.value
      }
    }
  }
  const draft = readDraft()
  if (!sheet?.locked && draft?.values) {
    for (const [id, value] of Object.entries(draft.values)) next[id] = value as string | number
  }
  hydrating = true
  values.value = next
  hydrating = false
  error.value = ''
  missing.value = []
  if (!sheet?.locked && draft) scheduleSync()
}, {immediate: true})

function draftKey() {
  const sheet = props.sheet
  return `wise-ref-${sheet.event.id}-${sheet.match.id}-${sheet.team.id}`
}

function readDraft(): {values?: Record<string, string | number>} | null {
  try {
    const raw = localStorage.getItem(draftKey())
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function keepLocal() {
  try {
    localStorage.setItem(draftKey(), JSON.stringify({at: Date.now(), values: values.value}))
  } catch {
    /* private mode */
  }
  syncState.value = navigator.onLine ? 'local' : 'offline'
  scheduleSync()
}

function scheduleSync() {
  window.clearTimeout(syncTimer)
  syncTimer = window.setTimeout(() => {
    void syncNow().catch(() => {})
  }, 700)
}

async function syncNow() {
  if (props.sheet?.locked) return
  if (!navigator.onLine) {
    syncState.value = 'offline'
    return
  }
  syncState.value = 'syncing'
  try {
    await axios.put(url(), payload())
    syncState.value = 'synced'
  } catch (e) {
    syncState.value = 'offline'
    if (navigator.onLine) throw e
  }
}

function onOnline() {
  void syncNow()
}

watch(syncState, (state) => setSync(state), {immediate: true})

window.addEventListener('online', onOnline)
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline)
  window.clearTimeout(syncTimer)
  setSync('idle')
})

const syncLabel = computed(() => {
  if (syncState.value === 'syncing') return 'wird übertragen'
  if (syncState.value === 'offline') return 'offline, auf dem Gerät gesichert'
  if (syncState.value === 'synced') return 'übertragen'
  if (syncState.value === 'local') return 'auf dem Gerät gesichert'
  return ''
})

function setCount(id: string, next: number, max: number | null) {
  const floor = Math.max(0, next)
  values.value[id] = max == null ? floor : Math.min(max, floor)
}

function countMax(item: any): number | null {
  const max = item.config?.max
  if (max === null || max === undefined || max === '') return null
  return Number(max)
}

function countIsScale(item: any) {
  const max = countMax(item)
  return max != null && max <= 8
}

function countScale(item: any) {
  return Array.from({length: (countMax(item) ?? 0) + 1}, (_, index) => index)
}

function matchZeroed() {
  for (const section of props.sheet?.sections || []) {
    for (const item of section.items || []) {
      if (item.item_type !== 'choice') continue
      const selected = values.value[String(item.id)]
      const option = item.config?.options?.[Number(selected)]
      if (option?.zeros_match) return true
    }
  }
  return false
}

function choose(id: string, index: number) {
  if (props.sheet.locked) return
  const next = {...values.value}
  if (next[id] === index) delete next[id]
  else next[id] = index
  values.value = next
}

function pointsFor(item: any, value: string | number | undefined): number {
  if (value === undefined || value === null || value === '') return 0
  if (item.item_type === 'yes_no') {
    if (value === 'yes') return Number(item.config?.yes_points ?? 0)
    if (value === 'no') return Number(item.config?.no_points ?? 0)
    return 0
  }
  if (item.item_type === 'choice') {
    const option = item.config?.options?.[Number(value)]
    return option ? Number(option.points ?? 0) : 0
  }
  if (item.item_type === 'count') {
    const cap = countMax(item)
    const count = cap == null ? Math.max(0, Number(value) || 0) : Math.max(0, Math.min(cap, Number(value) || 0))
    return count * Number(item.config?.points_each ?? 0)
  }
  return 0
}

function choiceOptional(item: any): boolean {
  const options = item.config?.options || []
  return Boolean(item.config?.optional) || options.length === 1
}

function isAnswered(item: any, value: string | number | undefined): boolean {
  if (item.item_type === 'yes_no') return value === 'yes' || value === 'no'
  if (item.item_type === 'choice') {
    if (choiceOptional(item) && (value === undefined || value === null || value === '')) return true
    return item.config?.options?.[Number(value)] != null && value !== ''
  }
  if (item.item_type === 'count') return true
  return true
}

function sectionDone(section: any) {
  return (section.items || []).every((item: any) => isAnswered(item, values.value[String(item.id)]))
}

function sectionPoints(section: any) {
  let total = 0
  for (const item of section.items || []) {
    const selected = item.config?.options?.[Number(values.value[String(item.id)])]
    if (selected?.zeros_match) return null
    total += pointsFor(item, values.value[String(item.id)])
  }
  return total
}

function isOpen(id: number) {
  return openSections.value.includes(id)
}

function toggleSection(id: number) {
  openSections.value = isOpen(id)
      ? openSections.value.filter((entry) => entry !== id)
      : [...openSections.value, id]
}

function unansweredIds(): string[] {
  const ids: string[] = []
  for (const section of props.sheet?.sections || []) {
    for (const item of section.items || []) {
      if (!isAnswered(item, values.value[String(item.id)])) ids.push(String(item.id))
    }
  }
  return ids
}

async function showFailure(message: string) {
  error.value = message
  missing.value = message.includes('jede Zeile') ? unansweredIds() : []
  if (missing.value.length) {
    const open = new Set(openSections.value)
    for (const section of props.sheet?.sections || []) {
      if ((section.items || []).some((item: any) => missing.value.includes(String(item.id)))) open.add(section.id)
    }
    openSections.value = [...open]
  }
  await nextTick()
  document.querySelector('.scor-q.is-missing')?.scrollIntoView({block: 'center', behavior: 'smooth'})
}

function payload() {
  return {values: values.value}
}

async function confirm(side: 'referee' | 'team') {
  busy.value = true
  error.value = ''
  missing.value = []
  try {
    await syncNow()
    if (!navigator.onLine || syncState.value === 'offline') {
      await showFailure('Abschließen braucht eine Verbindung. Der Stand bleibt auf dem Gerät.')
      return
    }
    const {data} = await axios.post(`${url()}/confirm`, {side})
    emit('update', data)
  } catch (e: any) {
    await showFailure(e?.response?.data?.error || 'Abschließen fehlgeschlagen.')
  } finally {
    busy.value = false
  }
}

function url() {
  return `/scor/events/${props.sheet.event.id}/matches/${props.sheet.match.id}/teams/${props.sheet.team.id}`
}

function leave() {
  void router.push(`/scor/${props.sheet.event.id}`)
}
</script>

<template>
  <div class="scor-sheet">
    <div class="scor-sheet__body">
    <header class="scor-sheet__head">
      <div>
        <p class="scor-sheet__clock">{{ sheet.match.start_label || '–' }}</p>
        <p class="scor-sheet__meta">{{ sheet.match.round_label }} · Match {{ sheet.match.match_no }} · {{ sheet.team.table_label }}</p>
      </div>
      <div>
        <div class="scor-sheet__team-row">
          <h2 class="scor-sheet__team">{{ sheet.team.number }} {{ sheet.team.name }}</h2>
          <span
              v-if="syncState !== 'idle'"
              class="scor-status"
              :class="'is-' + syncState"
              role="status"
              :aria-label="syncLabel"
          >
            <span class="scor-status__dot" aria-hidden="true"/>
          </span>
        </div>
        <p v-if="sheet.opponent?.id" class="scor-sheet__meta">gegen {{ sheet.opponent.number }} {{ sheet.opponent.name }}</p>
      </div>
    </header>

    <p v-if="!sheet.sections?.length" class="text-lg text-[var(--color-text-muted)]">Für diese Saison gibt es noch keinen Schiri-Bogen. Den legt die FLOW-Admin an.</p>

    <div v-for="section in sheet.sections" :key="section.id" class="scor-lift">
    <section class="glass-card liquid-surface-inner scor-block" :class="{'is-done': sectionDone(section), 'is-open': isOpen(section.id)}">
      <button
          type="button"
          class="scor-acc"
          :aria-expanded="isOpen(section.id)"
          @click="toggleSection(section.id)"
      >
        <span class="scor-acc__title">{{ section.title }}</span>
        <span v-if="sectionDone(section)" class="scor-acc__points">{{ sectionPoints(section) === null ? 'Match 0' : sectionPoints(section) }}</span>
        <i class="bi bi-chevron-down scor-acc__chevron" aria-hidden="true"/>
      </button>
      <div class="scor-acc__body" :class="{'is-open': isOpen(section.id)}">
      <div class="scor-acc__inner">
      <p v-if="section.note" class="scor-block__note">{{ section.note }}</p>
      <div v-for="item in section.items" :key="item.id" class="scor-q" :class="{'is-missing': missing.includes(String(item.id)), 'is-done': isAnswered(item, values[String(item.id)])}">
        <div class="scor-q__top">
          <p class="scor-q__label">{{ item.label }}</p>
          <p v-if="item.item_type === 'count'" class="scor-q__hint">
            <template v-if="countMax(item) == null || countIsScale(item)">je {{ item.config?.points_each ?? 0 }}</template>
            <template v-else>max. {{ countMax(item) }}, je {{ item.config?.points_each ?? 0 }}</template>
          </p>
        </div>

        <div v-if="item.item_type === 'yes_no'" class="scor-choices">
          <button
              type="button"
              class="scor-choice"
              :class="{'is-on': values[String(item.id)] === 'yes'}"
              :disabled="sheet.locked"
              @click="values[String(item.id)] = 'yes'"
          >
            <span>{{ item.config?.yes_label || 'Ja' }}</span>
            <span class="scor-choice__points">{{ item.config?.yes_points ?? 0 }}</span>
          </button>
          <button
              type="button"
              class="scor-choice"
              :class="{'is-on': values[String(item.id)] === 'no'}"
              :disabled="sheet.locked"
              @click="values[String(item.id)] = 'no'"
          >
            <span>{{ item.config?.no_label || 'Nein' }}</span>
            <span class="scor-choice__points">{{ item.config?.no_points ?? 0 }}</span>
          </button>
        </div>

        <div v-else-if="item.item_type === 'choice'" class="scor-choices scor-choices--stack">
          <button
              v-for="(option, index) in item.config?.options || []"
              :key="index"
              type="button"
              class="scor-choice"
              :class="{'is-on': values[String(item.id)] === index}"
              :disabled="sheet.locked"
              @click="choose(String(item.id), index)"
          >
            <span>{{ option.label }}</span>
            <span class="scor-choice__points">{{ option.zeros_match ? 'Match 0' : option.points }}</span>
          </button>
        </div>

        <div v-else-if="item.item_type === 'count' && countIsScale(item)" class="scor-scale">
          <button
              v-for="n in countScale(item)"
              :key="n"
              type="button"
              class="scor-scale__btn"
              :class="{'is-on': Number(values[String(item.id)] ?? 0) === n}"
              :disabled="sheet.locked"
              @click="setCount(String(item.id), n, countMax(item))"
          >{{ n }}</button>
        </div>

        <div v-else-if="item.item_type === 'count'" class="scor-count">
          <button type="button" class="glass-btn-secondary scor-count__btn" aria-label="weniger" :disabled="sheet.locked" @click="setCount(String(item.id), Number(values[String(item.id)] || 0) - 1, countMax(item))">−</button>
          <span class="scor-count__value">{{ values[String(item.id)] || 0 }}</span>
          <button type="button" class="glass-btn-secondary scor-count__btn" aria-label="mehr" :disabled="sheet.locked" @click="setCount(String(item.id), Number(values[String(item.id)] || 0) + 1, countMax(item))">+</button>
        </div>
        </div>
      </div>
      </div>
      </section>
    </div>
    </div>

      <div class="scor-sheet__bar">
        <p v-if="error" class="scor-sheet__alert" role="alert">{{ error }}</p>
        <p class="scor-sheet__points"><span>{{ points }}</span> Punkte</p>
        <div class="scor-finish">
          <div class="scor-confirm" :class="sheet.confirmed_referee ? 'is-done' : 'is-open'">
            <span class="scor-confirm__who">Schiri</span>
            <span class="scor-confirm__state">{{ sheet.confirmed_referee ? 'abgeschlossen' : 'offen' }}</span>
            <button v-if="!sheet.confirmed_referee" type="button" class="glass-btn-accent" :disabled="busy || sheet.locked" @click="confirm('referee')">
              Abschließen
            </button>
            <i v-else class="bi bi-check-circle-fill scor-confirm__mark" aria-hidden="true"/>
          </div>
          <div class="scor-confirm" :class="sheet.confirmed_team ? 'is-done' : 'is-open'">
            <span class="scor-confirm__who">Team</span>
            <span class="scor-confirm__state">{{ sheet.confirmed_team ? 'abgeschlossen' : 'offen' }}</span>
            <button v-if="!sheet.confirmed_team" type="button" class="glass-btn-accent" :disabled="busy || sheet.locked" @click="confirm('team')">
              Abschließen
            </button>
            <i v-else class="bi bi-check-circle-fill scor-confirm__mark" aria-hidden="true"/>
          </div>
        </div>
        <button v-if="sheet.locked" type="button" class="glass-btn-accent scor-sheet__leave" @click="leave">zurück zur Übersicht</button>
      </div>
  </div>
</template>

<style scoped>
.scor-sheet {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding-inline: 0.85rem;
}

.scor-lift {
  border-radius: var(--radius-lg, 1rem);
  box-shadow:
    0 12px 22px rgba(15, 23, 42, 0.1),
    0 3px 8px rgba(15, 23, 42, 0.05);
}

.scor-sheet__body {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.scor-sheet__head {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.85rem 1rem;
  align-items: end;
}

.scor-sheet__clock {
  font-size: 2.4rem;
  font-weight: 800;
  line-height: 0.9;
  font-variant-numeric: tabular-nums;
}

.scor-sheet__meta {
  margin-top: 0.25rem;
  font-size: 1.125rem;
  color: var(--color-text-muted);
}

.scor-sheet__team-row {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.scor-sheet__team {
  font-size: 1.45rem;
  font-weight: 800;
  line-height: 1.15;
}

.scor-status {
  display: none;
  flex: 0 0 auto;
  width: 0.7rem;
  height: 0.7rem;
}

.scor-status__dot {
  display: block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #1f8a4c;
}

.scor-status.is-syncing .scor-status__dot,
.scor-status.is-local .scor-status__dot {
  animation: scor-sync-pulse 1.1s ease-in-out infinite;
}

.scor-status.is-offline .scor-status__dot {
  background: #b42318;
}

@keyframes scor-sync-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.35; transform: scale(0.82); }
}

@media (min-width: 1367px) {
  .scor-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
}

.scor-sheet .scor-block.liquid-surface-inner {
  position: relative;
  overflow: hidden;
  padding: 0.55rem 0.85rem;
  background: var(--liquid-tile-bg);
  backdrop-filter: blur(var(--liquid-blur)) saturate(var(--liquid-saturate));
  -webkit-backdrop-filter: blur(var(--liquid-blur)) saturate(var(--liquid-saturate));
  border: 1px solid var(--liquid-border);
  box-shadow:
    inset 0 1.5px 0 rgba(255, 255, 255, 0.95),
    inset 0 -1px 0 rgba(15, 23, 42, 0.05);
  transition: background 0.4s ease, border-color 0.4s ease;
}

.scor-sheet .scor-block.liquid-surface-inner::before {
  content: "";
  position: absolute;
  inset: 0;
  pointer-events: none;
  border-radius: inherit;
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.5) 0%, rgba(255, 255, 255, 0.08) 28%, transparent 52%);
}

.scor-acc {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  width: 100%;
  min-height: 3.4rem;
  padding: 0.35rem 0.15rem;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
}

.scor-acc__chevron {
  transition: transform 0.48s cubic-bezier(0.22, 1.35, 0.36, 1);
}

.scor-block.is-open .scor-acc__chevron {
  transform: rotate(180deg);
}

.scor-acc__title {
  flex: 1;
  font-size: 1.2rem;
  font-weight: 800;
}

.scor-acc__points {
  font-size: 1.35rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.scor-sheet .scor-block.is-done.liquid-surface-inner {
  background: linear-gradient(165deg, rgba(229, 246, 236, 0.92) 0%, rgba(255, 255, 255, 0.55) 55%, rgba(255, 255, 255, 0.28) 100%);
  border-color: color-mix(in srgb, #146c36 42%, white);
  box-shadow:
    inset 0 1.5px 0 rgba(255, 255, 255, 0.95),
    inset 0 0 0 1px color-mix(in srgb, #146c36 28%, transparent);
}

.scor-block.is-done .scor-acc__points {
  color: #146c36;
}

html[data-theme='dark'] .scor-sheet .scor-block.is-done.liquid-surface-inner {
  background: linear-gradient(165deg, rgba(20, 90, 52, 0.62) 0%, rgba(255, 255, 255, 0.08) 100%);
  border-color: color-mix(in srgb, #3dce7a 40%, transparent);
}

html[data-theme='dark'] .scor-block.is-done .scor-acc__points {
  color: #8ee0b0;
}

.scor-acc__body {
  display: grid;
  grid-template-rows: 0fr;
  transition: grid-template-rows 0.48s cubic-bezier(0.22, 1.25, 0.36, 1);
}

.scor-acc__body.is-open {
  grid-template-rows: 1fr;
}

.scor-acc__inner {
  position: relative;
  z-index: 1;
  min-height: 0;
  overflow: hidden;
}

@media (prefers-reduced-motion: reduce) {
  .scor-acc__body,
  .scor-acc__chevron,
  .scor-sheet .scor-block.liquid-surface-inner {
    transition: none;
  }

  .scor-status__dot {
    animation: none;
  }
}

.scor-block__title {
  font-size: 1.3rem;
  font-weight: 800;
  margin-bottom: 0.35rem;
}

.scor-block__note {
  margin: 0 0 0.35rem;
  color: var(--color-text-muted);
  font-size: 1rem;
  line-height: 1.35;
}

.scor-q {
  position: relative;
  padding-top: 0.85rem;
  margin-top: 0.85rem;
  border-top: 1px solid var(--color-border);
}

.scor-q.is-done {
  padding-left: 0.85rem;
}

.scor-q.is-done::before {
  content: "";
  position: absolute;
  top: 0.85rem;
  bottom: 0;
  left: 0;
  width: 4px;
  border-radius: 4px;
  background: #146c36;
}

.scor-q.is-missing {
  margin-left: -0.35rem;
  margin-right: -0.35rem;
  padding: 0.85rem 0.35rem 0.35rem;
  border: 3px solid #b42318;
  border-radius: 0.9rem;
  background: #fde8e6;
}

.scor-q__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.55rem;
}

.scor-q__label {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
}

.scor-q__hint {
  margin: 0;
  flex: none;
  font-size: 1rem;
  font-weight: 700;
  color: var(--color-text-muted);
  white-space: nowrap;
}

.scor-choices {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.55rem;
}

.scor-choices--stack {
  grid-template-columns: 1fr;
}

.scor-choice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  min-height: 4.75rem;
  padding: 0.85rem 1rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.9rem;
  background: #fff;
  font-size: 1.25rem;
  font-weight: 800;
  text-align: left;
  color: #1a1a1a;
}

.scor-choice.is-on {
  border-color: #146c36;
  background: #146c36;
  color: #fff;
}

.scor-choice__points {
  font-size: 1.45rem;
  font-variant-numeric: tabular-nums;
  color: #5c5c5c;
}

.scor-choice.is-on .scor-choice__points {
  color: #fff;
}

.scor-scale {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.scor-scale__btn {
  width: 3.6rem;
  min-height: 3.6rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.9rem;
  background: #fff;
  color: #1a1a1a;
  font-size: 1.7rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.scor-scale__btn.is-on {
  border-color: #146c36;
  background: #146c36;
  color: #fff;
}

.scor-count {
  display: grid;
  grid-template-columns: 4.25rem 1fr 4.25rem;
  gap: 0.55rem;
  align-items: center;
}

.scor-count__btn {
  width: 100%;
  min-height: 4.25rem;
  padding: 0;
  font-size: 2rem;
  font-weight: 700;
  border-radius: var(--radius-lg, 0.85rem);
}

.scor-count__value {
  text-align: center;
  font-size: 2.4rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.scor-count__btn {
  box-sizing: border-box;
}

.scor-sheet__bar {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  padding: 0.35rem 0.15rem calc(0.85rem + env(safe-area-inset-bottom));
  background: transparent;
  box-shadow: none;
}

.scor-sheet__alert {
  grid-column: 1 / -1;
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: 0.75rem;
  background: #b42318;
  color: #fff;
  font-size: 1.35rem;
  font-weight: 800;
  line-height: 1.2;
  text-align: center;
}

.scor-finish {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.65rem;
}

.scor-confirm {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 0.35rem;
  min-height: 3.4rem;
  padding: 0.75rem;
  border: 1px solid var(--liquid-border);
  border-radius: 1.1rem;
  background: var(--liquid-tile-bg);
  box-shadow: var(--liquid-shadow-inset);
}

.scor-confirm .glass-btn-accent {
  width: 100%;
  justify-content: center;
}

.scor-confirm.is-done {
  border-color: #1f8a4c;
  background: color-mix(in srgb, #1f8a4c 18%, white);
}

.scor-confirm__who {
  font-size: 1.05rem;
  font-weight: 800;
}

.scor-confirm__state {
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--color-text-muted);
}

.scor-confirm.is-done .scor-confirm__state {
  color: #1f8a4c;
}

.scor-confirm__mark {
  font-size: 1.6rem;
  color: #1f8a4c;
  margin-right: 0.35rem;
}

.scor-confirm .glass-btn-accent {
  min-height: 2.75rem;
}

.scor-sheet__points {
  margin: 0;
  padding-right: 1.7rem;
  text-align: right;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-text-muted);
  line-height: 1;
}

.scor-sheet__points span {
  margin-right: 0.25rem;
  font-size: 2rem;
  font-weight: 800;
  color: var(--color-text);
}

.scor-sheet__bar .glass-btn-secondary,
.scor-sheet__bar .glass-btn-accent {
  min-height: 3.4rem;
  justify-content: center;
}

.scor-sheet__leave {
  grid-column: 1 / -1;
}

@media (min-width: 800px) {
  .scor-sheet__alert {
    grid-column: 1 / -1;
  }
}
</style>
