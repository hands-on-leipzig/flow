<script setup lang="ts">
import {computed, nextTick, onBeforeUnmount, onMounted, ref, watch} from 'vue'
import axios from 'axios'

const props = defineProps<{sheet: any}>()
const emit = defineEmits<{update: [sheet: any]}>()

const values = ref<Record<string, string | number>>({})
const error = ref('')
const missing = ref<string[]>([])
const busy = ref(false)
const sheetEl = ref<HTMLElement | null>(null)
const barStyle = ref<Record<string, string>>({})

const points = computed(() => {
  let total = 0
  for (const section of props.sheet?.sections || []) {
    for (const item of section.items || []) {
      total += pointsFor(item, values.value[String(item.id)])
    }
  }
  return total
})

function placeBar() {
  const el = sheetEl.value
  if (!el) return
  const rect = el.getBoundingClientRect()
  barStyle.value = {
    left: `${rect.left}px`,
    width: `${rect.width}px`,
  }
}

watch(values, () => {
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
      } else if (item.value !== null && item.value !== undefined && item.value !== '') {
        next[String(item.id)] = item.value
      }
    }
  }
  values.value = next
  error.value = ''
  missing.value = []
}, {immediate: true})

function setCount(id: string, next: number, max: number) {
  values.value[id] = Math.max(0, Math.min(max, next))
}

function countMax(item: any) {
  return Number(item.config?.max ?? 0)
}

function countIsScale(item: any) {
  return countMax(item) <= 8
}

function countScale(item: any) {
  return Array.from({length: countMax(item) + 1}, (_, index) => index)
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
    const max = Number(item.config?.max ?? 0)
    const count = Math.max(0, Math.min(max, Number(value) || 0))
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
  await nextTick()
  document.querySelector('.scor-q.is-missing')?.scrollIntoView({block: 'center', behavior: 'smooth'})
}

function payload() {
  return {values: values.value}
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const {data} = await axios.put(url(), payload())
    emit('update', data)
  } catch (e: any) {
    await showFailure(e?.response?.data?.error || 'Speichern fehlgeschlagen.')
  } finally {
    busy.value = false
  }
}

async function confirm(side: 'referee' | 'team') {
  busy.value = true
  error.value = ''
  missing.value = []
  try {
    await axios.put(url(), payload())
    const {data} = await axios.post(`${url()}/confirm`, {side})
    emit('update', data)
  } catch (e: any) {
    await showFailure(e?.response?.data?.error || 'Bestätigen fehlgeschlagen.')
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

function url() {
  return `/scor/events/${props.sheet.event.id}/matches/${props.sheet.match.id}/teams/${props.sheet.team.id}`
}

onMounted(() => {
  placeBar()
  requestAnimationFrame(placeBar)
  window.addEventListener('resize', placeBar)
  window.addEventListener('scroll', placeBar, true)
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', placeBar)
  window.removeEventListener('scroll', placeBar, true)
})
</script>

<template>
  <div ref="sheetEl" class="scor-sheet">
    <div class="scor-sheet__body">
    <header class="scor-sheet__head">
      <div>
        <p class="scor-sheet__clock">{{ sheet.match.start_label || '–' }}</p>
        <p class="scor-sheet__meta">{{ sheet.match.round_label }} · Match {{ sheet.match.match_no }} · {{ sheet.team.table_label }}</p>
      </div>
      <div>
        <h2 class="scor-sheet__team">{{ sheet.team.number }} {{ sheet.team.name }}</h2>
        <p v-if="sheet.opponent?.id" class="scor-sheet__meta">gegen {{ sheet.opponent.number }} {{ sheet.opponent.name }}</p>
      </div>
    </header>

    <p v-if="!sheet.sections?.length" class="text-lg text-[var(--color-text-muted)]">Für diese Saison gibt es noch keinen Schiri-Bogen. Den legt die FLOW-Admin an.</p>

    <section v-for="section in sheet.sections" :key="section.id" class="glass-card liquid-surface-inner scor-block">
      <h2 class="scor-block__title">{{ section.title }}</h2>
      <div v-for="item in section.items" :key="item.id" class="scor-q" :class="{'is-missing': missing.includes(String(item.id))}">
        <p class="scor-q__label">{{ item.label }}</p>

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
            <span class="scor-choice__points">{{ option.points }}</span>
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
          <span class="scor-count__hint">je {{ item.config?.points_each ?? 0 }}</span>
        </div>

        <div v-else-if="item.item_type === 'count'" class="scor-count">
          <button type="button" class="glass-btn-secondary scor-count__btn" aria-label="weniger" :disabled="sheet.locked" @click="setCount(String(item.id), Number(values[String(item.id)] || 0) - 1, item.config?.max ?? 0)">−</button>
          <span class="scor-count__value">{{ values[String(item.id)] || 0 }}</span>
          <button type="button" class="glass-btn-secondary scor-count__btn" aria-label="mehr" :disabled="sheet.locked" @click="setCount(String(item.id), Number(values[String(item.id)] || 0) + 1, item.config?.max ?? 0)">+</button>
          <span class="scor-count__hint">max. {{ item.config?.max ?? 0 }}, je {{ item.config?.points_each ?? 0 }}</span>
        </div>
      </div>
      </section>
    </div>

    <Teleport to="body">
      <div v-if="barStyle.width" class="scor-sheet__bar" :style="barStyle">
        <p v-if="error" class="scor-sheet__alert" role="alert">{{ error }}</p>
        <p class="scor-sheet__points">{{ points }} <span>Punkte</span></p>
        <div class="scor-confirm" :class="sheet.confirmed_referee ? 'is-done' : 'is-open'">
          <span class="scor-confirm__who">Schiri</span>
          <span class="scor-confirm__state">{{ sheet.confirmed_referee ? 'bestätigt' : 'offen' }}</span>
          <button v-if="!sheet.confirmed_referee" type="button" class="glass-btn-accent" :disabled="busy || sheet.locked" @click="confirm('referee')">
            Bestätigen
          </button>
          <i v-else class="bi bi-check-circle-fill scor-confirm__mark" aria-hidden="true"/>
        </div>
        <div class="scor-confirm" :class="sheet.confirmed_team ? 'is-done' : 'is-open'">
          <span class="scor-confirm__who">Team</span>
          <span class="scor-confirm__state">{{ sheet.confirmed_team ? 'bestätigt' : 'offen' }}</span>
          <button v-if="!sheet.confirmed_team" type="button" class="glass-btn-accent" :disabled="busy || sheet.locked" @click="confirm('team')">
            Bestätigen
          </button>
          <i v-else class="bi bi-check-circle-fill scor-confirm__mark" aria-hidden="true"/>
        </div>
        <button type="button" class="glass-btn-secondary" :disabled="busy || sheet.locked" @click="save">Speichern</button>
        <button v-if="sheet.roles?.admin && sheet.locked" type="button" class="glass-btn-secondary" :disabled="busy" @click="reopen">Wieder öffnen</button>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
.scor-sheet {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.scor-sheet__body {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding-bottom: 16rem;
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

.scor-sheet__team {
  font-size: 1.45rem;
  font-weight: 800;
  line-height: 1.15;
}

.scor-sheet .scor-block.liquid-surface-inner {
  padding: 0.9rem;
  box-shadow: none;
}

.scor-block__title {
  font-size: 1.3rem;
  font-weight: 800;
  margin-bottom: 0.35rem;
}

.scor-q {
  padding-top: 0.85rem;
  margin-top: 0.85rem;
  border-top: 1px solid var(--color-border);
}

.scor-q.is-missing {
  margin-left: -0.35rem;
  margin-right: -0.35rem;
  padding: 0.85rem 0.35rem 0.35rem;
  border: 3px solid #b42318;
  border-radius: var(--radius-lg, 0.85rem);
  background: color-mix(in srgb, #b42318 12%, transparent);
}

.scor-q__label {
  font-size: 1.25rem;
  font-weight: 700;
  margin-bottom: 0.55rem;
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
  min-height: 4.25rem;
  padding: 0.75rem 0.9rem;
  border: 2px solid var(--color-border);
  border-radius: var(--radius-lg, 0.85rem);
  background: color-mix(in srgb, var(--color-bg) 72%, transparent);
  font-size: 1.15rem;
  font-weight: 700;
  text-align: left;
}

.scor-choice.is-on {
  border-color: var(--color-accent);
  background: var(--color-bg-hover);
}

.scor-choice__points {
  font-variant-numeric: tabular-nums;
  color: var(--color-text-muted);
}

.scor-choice.is-on .scor-choice__points {
  color: inherit;
}

.scor-scale {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(3.4rem, 1fr));
  gap: 0.5rem;
}

.scor-scale__btn {
  min-height: 3.75rem;
  border: 2px solid var(--color-border);
  border-radius: var(--radius-lg, 0.85rem);
  background: color-mix(in srgb, var(--color-bg) 72%, transparent);
  font-size: 1.45rem;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.scor-scale__btn.is-on {
  border-color: var(--color-accent);
  background: var(--color-bg-hover);
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

.scor-count__hint {
  grid-column: 1 / -1;
  font-size: 1.125rem;
  color: var(--color-text-muted);
}

.scor-sheet__bar {
  position: fixed;
  bottom: 0.75rem;
  z-index: 40;
  display: grid;
  grid-template-columns: 1fr;
  gap: 0.5rem;
  box-sizing: border-box;
  padding: 0.85rem;
  border: 1px solid rgba(255, 255, 255, 0.38);
  border-radius: var(--radius-lg, 0.85rem);
  background: rgba(255, 255, 255, 0.12);
  backdrop-filter: blur(42px) saturate(1.25);
  -webkit-backdrop-filter: blur(42px) saturate(1.25);
  box-shadow: 0 12px 40px rgba(15, 23, 42, 0.06);
}

:global(html[data-theme='dark']) .scor-sheet__bar {
  background: rgba(24, 20, 18, 0.22);
  border-color: rgba(255, 255, 255, 0.12);
}

.scor-sheet__alert {
  margin: 0;
  padding: 0.95rem 1rem;
  border-radius: var(--radius-lg, 0.85rem);
  background: #b42318;
  color: #fff;
  font-size: 1.45rem;
  font-weight: 800;
  line-height: 1.2;
  text-align: center;
}

.scor-confirm {
  display: grid;
  grid-template-columns: auto 1fr auto;
  align-items: center;
  gap: 0.65rem;
  min-height: 3.4rem;
  padding: 0.4rem 0.55rem 0.4rem 0.85rem;
  border: 2px solid var(--color-border);
  border-radius: var(--radius-lg, 0.85rem);
  background: rgba(255, 255, 255, 0.28);
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
  font-size: 1.35rem;
  font-weight: 800;
}

.scor-sheet__points span {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text-muted);
}

.scor-sheet__bar .glass-btn-secondary,
.scor-sheet__bar .glass-btn-accent {
  min-height: 3.25rem;
  justify-content: center;
}

.scor-sheet__bar .glass-btn-secondary {
  background: rgba(255, 255, 255, 0.42);
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
}
</style>
