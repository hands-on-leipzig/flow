<script setup lang="ts">
import {computed, onBeforeUnmount, ref, watch} from 'vue'
import {useRouter} from 'vue-router'
import axios from 'axios'
import ScorGear from '@/components/scor/ScorGear.vue'
import {useWiseSync, wiseSyncLabel} from '@/composables/useWiseSync'

const props = defineProps<{sheet: any}>()
const emit = defineEmits<{update: [sheet: any]}>()
const router = useRouter()

const values = ref<Record<string, number>>({})
const comments = ref<Record<string, string>>({})
const texts = ref<Record<string, string>>({})
const disabled = ref<number[]>([])
const error = ref('')
const busy = ref(false)
const {setSync} = useWiseSync()
const syncState = ref<'idle' | 'local' | 'syncing' | 'synced' | 'offline'>('idle')
let hydrating = false
let fromSync = false
let syncTimer = 0

watch([values, comments, texts, disabled], () => {
  if (!hydrating && canSync()) keepLocal()
}, {deep: true})

watch(() => props.sheet, (sheet) => {
  if (fromSync) {
    fromSync = false
    return
  }
  const nextValues: Record<string, number> = {}
  const nextComments: Record<string, string> = {}
  const nextTexts: Record<string, string> = {}
  const nextDisabled: number[] = []
  for (const section of sheet?.sections || []) {
    if (section.disabled) nextDisabled.push(section.id)
    for (const item of section.items || []) {
      if (item.item_type === 'text') {
        nextTexts[String(item.id)] = item.value || ''
      } else if (item.value !== null && item.value !== undefined && item.value !== '') {
        nextValues[String(item.id)] = Number(item.value)
      }
      nextComments[String(item.id)] = item.comment || ''
    }
  }
  const draft = readDraft()
  const writable = sheet && !sheet.completed && !sheet.lifecycle?.closed
  if (writable && draft) {
    Object.assign(nextValues, draft.values || {})
    Object.assign(nextComments, draft.comments || {})
    Object.assign(nextTexts, draft.texts || {})
    if (Array.isArray(draft.disabled)) nextDisabled.splice(0, nextDisabled.length, ...draft.disabled)
  }
  hydrating = true
  values.value = nextValues
  comments.value = nextComments
  texts.value = nextTexts
  disabled.value = nextDisabled
  hydrating = false
  error.value = ''
  if (writable && draft) scheduleSync()
}, {immediate: true})

function canSync() {
  return !props.sheet?.completed && !props.sheet?.lifecycle?.closed
}

function draftKey() {
  return `wise-jury-${props.sheet.event.id}-${props.sheet.team.id}`
}

function readDraft(): {values?: Record<string, number>, comments?: Record<string, string>, texts?: Record<string, string>, disabled?: number[]} | null {
  try {
    const raw = localStorage.getItem(draftKey())
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

function keepLocal() {
  try {
    localStorage.setItem(draftKey(), JSON.stringify({
      at: Date.now(),
      values: values.value,
      comments: comments.value,
      texts: texts.value,
      disabled: disabled.value,
    }))
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
  if (!canSync()) return
  if (!navigator.onLine) {
    syncState.value = 'offline'
    return
  }
  syncState.value = 'syncing'
  try {
    const {data} = await axios.put(url(), payload())
    syncState.value = 'synced'
    fromSync = true
    emit('update', data)
  } catch (e) {
    syncState.value = 'offline'
    if (navigator.onLine) throw e
  }
}

function onOnline() {
  void syncNow().catch(() => {})
}

watch(syncState, (state) => setSync(state), {immediate: true})
window.addEventListener('online', onOnline)
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline)
  window.clearTimeout(syncTimer)
  setSync('idle')
})

const syncLabel = computed(() => wiseSyncLabel(syncState.value))

const rubricSections = computed(() => (props.sheet?.sections || []).filter((section: any) => section.kind === 'rubric'))
const teamAbsent = computed(() => rubricSections.value.length > 0 && rubricSections.value.every((section: any) => disabled.value.includes(section.id)))

function hasLevelText(item: any) {
  return (item.config?.cells || []).some((cell: string) => String(cell || '').trim() !== '')
}

function isFirstFeedback(section: any) {
  const first = (props.sheet?.sections || []).find((entry: any) => entry.kind === 'feedback')
  return first?.id === section.id
}

function groups(items: any[]) {
  const rows: {group: string, hint: string, items: any[]}[] = []
  for (const item of items) {
    const group = item.config?.group || ''
    const last = rows[rows.length - 1]
    if (last && last.group === group) {
      last.items.push(item)
    } else {
      rows.push({group, hint: item.config?.group_hint || '', items: [item]})
    }
  }
  return rows
}

function payload() {
  return {
    values: values.value,
    comments: comments.value,
    texts: texts.value,
    disabled_sections: disabled.value,
  }
}

function url() {
  return `/scor/events/${props.sheet.event.id}/teams/${props.sheet.team.id}/jury`
}

function leave() {
  void router.push(`/scor/${props.sheet.event.id}?sicht=jury`)
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    await syncNow()
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Speichern fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function complete() {
  busy.value = true
  error.value = ''
  try {
    await syncNow()
    if (!navigator.onLine || syncState.value === 'offline') {
      error.value = 'Abschließen braucht eine Verbindung. Der Stand bleibt auf dem Gerät.'
      return
    }
    const {data} = await axios.post(`${url()}/complete`)
    emit('update', data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Abschließen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function reopenEvent() {
  busy.value = true
  error.value = ''
  try {
    const {data} = await axios.post(`/scor/events/${props.sheet.event.id}/reopen`)
    emit('update', {...props.sheet, lifecycle: data})
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Wieder öffnen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

async function reopen() {
  busy.value = true
  error.value = ''
  try {
    const {data} = await axios.post(`${url()}/reopen`)
    emit('update', data)
  } catch (e: any) {
    error.value = e?.response?.data?.error || 'Wieder öffnen fehlgeschlagen.'
  } finally {
    busy.value = false
  }
}

function toggleSection(id: number, checked: boolean) {
  disabled.value = checked ? [...disabled.value, id] : disabled.value.filter((entry) => entry !== id)
}

function setLevel(id: number, level: number) {
  if (props.sheet.completed) return
  values.value = {...values.value, [String(id)]: level}
}
</script>

<template>
  <div class="jury-sheet">
    <header class="jury-sheet__head">
      <h2>{{ sheet.team.number }} {{ sheet.team.name }}</h2>
      <span
          v-if="syncState !== 'idle'"
          class="jury-sync"
          :class="'is-' + syncState"
          role="status"
          :aria-label="syncLabel"
      >
        <span class="jury-sync__dot" aria-hidden="true"/>
      </span>
    </header>

    <p v-if="sheet.lifecycle?.closed" class="jury-sheet__alert" role="alert">
      Die Auswertung ist geschlossen. Speichern geht erst wieder nach dem Öffnen.
      <button v-if="sheet.roles?.admin && !sheet.lifecycle?.released" type="button" class="glass-btn-accent" :disabled="busy" @click="reopenEvent">Wieder öffnen</button>
    </p>
    <p v-if="error" class="jury-sheet__alert" role="alert">{{ error }}</p>

    <section v-for="section in sheet.sections" :key="section.id" class="jury-block">
      <div class="jury-block__head">
        <h2>{{ section.title }}</h2>
        <button
            v-if="section.kind === 'rubric'"
            type="button"
            class="jury-skip"
            :class="{'is-on': disabled.includes(section.id)}"
            :disabled="sheet.completed"
            @click="toggleSection(section.id, !disabled.includes(section.id))"
        >
          {{ disabled.includes(section.id) ? 'Nicht angetreten' : 'Nicht angetreten?' }}
        </button>
      </div>

      <p v-if="section.note && !(section.kind === 'feedback' && teamAbsent)" class="jury-note">{{ section.note }}</p>

      <template v-if="section.kind === 'rubric' && !disabled.includes(section.id)">
        <div v-for="group in groups(section.items)" :key="group.group + group.items[0].id" class="jury-group">
          <p v-if="group.group" class="jury-group__title">{{ group.group }}</p>
          <p v-if="group.hint" class="jury-group__hint">{{ group.hint }}</p>
          <div v-for="item in group.items" :key="item.id" class="jury-row" :class="{'is-done': values[String(item.id)]}">
            <p v-if="!hasLevelText(item)" class="jury-prompt">
              <ScorGear v-if="item.core_value"/>
              <span>{{ item.config?.prompt || item.label }}</span>
            </p>
            <div class="jury-levels" :class="{'jury-levels--plain': !hasLevelText(item)}">
              <template v-if="hasLevelText(item)">
                <button
                    v-for="(cell, index) in (item.config?.cells || [])"
                    :key="index"
                    type="button"
                    class="jury-level"
                    :class="{'is-on': values[String(item.id)] === index + 1}"
                    :disabled="sheet.completed"
                    @click="setLevel(item.id, index + 1)"
                >
                  <span class="jury-level__n">{{ index + 1 }}</span>
                  <span class="jury-level__name">{{ sheet.columns?.[index]?.label }}</span>
                  <span class="jury-level__text">{{ cell }}</span>
                </button>
                <button
                    type="button"
                    class="jury-level"
                    :class="{'is-on': values[String(item.id)] === 4}"
                    :disabled="sheet.completed"
                    @click="setLevel(item.id, 4)"
                >
                  <span class="jury-level__n">4</span>
                  <span class="jury-level__name">Herausragend</span>
                  <span class="jury-level__text">Kurz begründen</span>
                </button>
              </template>
              <template v-else>
                <button
                    v-for="column in sheet.columns || []"
                    :key="column.value"
                    type="button"
                    class="jury-level"
                    :class="{'is-on': values[String(item.id)] === column.value}"
                    :disabled="sheet.completed"
                    @click="setLevel(item.id, column.value)"
                >
                  <span class="jury-level__n">{{ column.value }}</span>
                  <span class="jury-level__name">{{ column.label }}</span>
                </button>
              </template>
            </div>
            <textarea
                v-if="values[String(item.id)] === 4"
                v-model="comments[String(item.id)]"
                class="glass-input jury-comment"
                rows="2"
                maxlength="200"
                placeholder="Kommentar, mindestens 20 Zeichen"
                :disabled="sheet.completed"
            />
          </div>
        </div>
        <p v-if="sheet.core_values_note" class="jury-legend">
          <ScorGear/>
          <span>{{ sheet.core_values_note }}</span>
        </p>
      </template>

      <template v-else-if="section.kind === 'feedback'">
        <p v-if="teamAbsent && isFirstFeedback(section)" class="jury-note">Das Team ist nicht angetreten. Die Feedbackfelder bleiben leer.</p>
        <label v-for="item in section.items" :key="item.id" class="jury-feedback">
          {{ item.label }}
          <textarea
              v-model="texts[String(item.id)]"
              class="glass-input"
              rows="3"
              :disabled="sheet.completed || teamAbsent"
          />
        </label>
      </template>
    </section>

    <div class="jury-bar">
      <p class="jury-bar__points">{{ sheet.points }} <span>Punkte</span></p>
      <p class="jury-bar__core">Grundwerte {{ sheet.core_points }}</p>
      <button v-if="!sheet.completed" type="button" class="glass-btn-accent" :disabled="busy || sheet.lifecycle?.closed" @click="complete">Bewertung abschließen</button>
      <button v-if="!sheet.completed" type="button" class="glass-btn-secondary" :disabled="busy || sheet.lifecycle?.closed" @click="save">Speichern</button>
      <button v-if="sheet.completed" type="button" class="glass-btn-accent jury-bar__leave" @click="leave">zurück zur Übersicht</button>
      <button v-if="sheet.roles?.admin && sheet.completed" type="button" class="glass-btn-secondary" :disabled="busy" @click="reopen">Wieder öffnen</button>
    </div>
  </div>
</template>

<style scoped>
.jury-sheet {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.jury-sheet__head {
  display: flex;
  align-items: center;
  gap: 0.55rem;
}

.jury-sheet__head h2 {
  margin: 0;
  font-size: 1.7rem;
  font-weight: 800;
  line-height: 1.1;
}

.jury-sync {
  display: none;
  flex: 0 0 auto;
  width: 0.7rem;
  height: 0.7rem;
  margin-left: auto;
}

.jury-sync__dot {
  display: block;
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 50%;
  background: #1f8a4c;
}

.jury-sync.is-syncing .jury-sync__dot,
.jury-sync.is-local .jury-sync__dot {
  animation: jury-sync-pulse 1.1s ease-in-out infinite;
}

.jury-sync.is-offline .jury-sync__dot {
  background: #b42318;
}

@keyframes jury-sync-pulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50% { opacity: 0.35; transform: scale(0.82); }
}

@media (min-width: 1367px) {
  .jury-sync {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }
}

@media (prefers-reduced-motion: reduce) {
  .jury-sync__dot {
    animation: none;
  }
}

.jury-sheet__alert {
  margin: 0;
  padding: 0.85rem 1rem;
  border-radius: 0.75rem;
  background: #b42318;
  color: #fff;
  font-size: 1.25rem;
  font-weight: 800;
}

.jury-block__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.65rem;
}

.jury-block__head h2 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
}

.jury-skip {
  min-height: 3rem;
  padding: 0.4rem 0.8rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.8rem;
  background: #fff;
  font-size: 1rem;
  font-weight: 800;
}

.jury-skip.is-on {
  border-color: #8a5a00;
  background: #8a5a00;
  color: #fff;
}

.jury-group {
  margin-bottom: 1rem;
}

.jury-note {
  margin: 0 0 0.75rem;
  color: var(--color-text-muted);
  font-size: 1rem;
  line-height: 1.35;
}

.jury-group__title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
}

.jury-group__hint {
  margin: 0.15rem 0 0.55rem;
  color: var(--color-text-muted);
  font-size: 1rem;
}

.jury-prompt {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0 0 0.45rem;
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.3;
}

.jury-legend {
  display: flex;
  align-items: flex-start;
  gap: 0.45rem;
  margin: 0.35rem 0 0;
  color: var(--color-text-muted);
  font-size: 0.95rem;
  line-height: 1.35;
}

.jury-row {
  margin-bottom: 0.75rem;
}

.jury-row.is-done {
  position: relative;
  padding-left: 0.85rem;
}

.jury-row.is-done::before {
  content: "";
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 4px;
  border-radius: 4px;
  background: #146c36;
}

.jury-levels {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0.35rem;
}

.jury-level {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.1rem;
  min-width: 0;
  min-height: 0;
  padding: 0.45rem 0.4rem;
  border: 3px solid #c8c8c8;
  border-radius: 0.85rem;
  background: #fff;
  color: #1a1a1a;
  text-align: left;
}

.jury-level.is-on {
  border-color: #146c36;
  background: #146c36;
  color: #fff;
}

.jury-level__n {
  font-size: 1.25rem;
  font-weight: 800;
  line-height: 1;
}

.jury-level__name {
  font-size: 0.78rem;
  font-weight: 800;
  line-height: 1.15;
}

.jury-level__text {
  font-size: 0.68rem;
  line-height: 1.2;
}

.jury-comment {
  margin-top: 0.45rem;
  width: 100%;
  font-size: 1.15rem;
}

.jury-feedback {
  display: block;
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 800;
}

.jury-feedback textarea {
  margin-top: 0.35rem;
  width: 100%;
  font-size: 1.15rem;
  font-weight: 500;
}

.jury-bar {
  position: sticky;
  bottom: 0;
  z-index: 40;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.45rem;
  margin-top: 0.5rem;
  padding: 0.65rem 0.75rem calc(0.65rem + env(safe-area-inset-bottom));
  background: #fff;
  box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.12);
}

.jury-bar__points {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 2rem;
  font-weight: 800;
  line-height: 1;
}

.jury-bar__points span,
.jury-bar__core {
  font-size: 1.05rem;
  font-weight: 700;
  color: #5c5c5c;
}

.jury-bar__core {
  grid-column: 1 / -1;
  margin: 0;
}

.jury-bar .glass-btn-accent,
.jury-bar .glass-btn-secondary {
  min-height: 3.4rem;
  justify-content: center;
}

.jury-bar__leave {
  grid-column: 1 / -1;
}

.jury-levels--plain .jury-level {
  align-items: center;
  text-align: center;
}
</style>
