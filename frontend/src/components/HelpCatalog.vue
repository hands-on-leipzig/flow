<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import {RouterLink} from 'vue-router'
import axios from 'axios'
import HelpActionFeedback from '@/components/atoms/HelpActionFeedback.vue'
import {useEventStore} from '@/stores/event'
import {helpJumpPath} from '@/utils/helpRoutes'
import NoticePane from '@/components/molecules/NoticePane.vue'

defineOptions({name: 'HelpCatalog'})

type Topic = {id: number; key: string; name: string; sort_order: number}
type Screen = {id: number; key: string; name: string; route_path: string; description: string | null}
type ActionScreen = {id: number; key: string; name: string; route_path: string}
type Action = {
  id: number
  title: string
  body: string | null
  sort_order: number
  help_topic: number
  open_count: number
  help_screen_ids: number[]
  screens: ActionScreen[]
  topic: {id: number; key: string; name: string} | null
}

const VIDEO_URL = 'https://handsontechnology-my.sharepoint.com/:v:/g/personal/jr_hands-on-technology_org/EYLes-Kq4GlDuBpUaxolgn4B4naGZakiVMW7Dq0xgWmskA?nav=eyJyZWZlcnJhbEluZm8iOnsicmVmZXJyYWxBcHAiOiJTdHJlYW1XZWJBcHAiLCJyZWZlcnJhbFZpZXciOiJTaGFyZURpYWxvZy1MaW5rIiwicmVmZXJyYWxBcHBQbGF0Zm9ybSI6IldlYiIsInJlZmVycmFsTW9kZSI6InZpZXcifX0%3D&e=T5yiJJ'

const eventStore = useEventStore()
const topics = ref<Topic[]>([])
const screens = ref<Screen[]>([])
const actions = ref<Action[]>([])
const query = ref('')
const loading = ref(true)

onMounted(async () => {
  try {
    const {data} = await axios.get('/help/catalog')
    topics.value = data.topics ?? []
    screens.value = data.screens ?? []
    actions.value = data.actions ?? []
  } finally {
    loading.value = false
  }
})

function byCountThenTitle(a: Action, b: Action) {
  if (a.open_count !== b.open_count) return b.open_count - a.open_count
  const title = a.title.localeCompare(b.title, 'de')
  return title !== 0 ? title : a.id - b.id
}

function actionMatches(action: Action, q: string): boolean {
  const needle = q.toLowerCase()
  if ((action.title || '').toLowerCase().includes(needle)) return true
  if ((action.body || '').toLowerCase().includes(needle)) return true
  if ((action.topic?.name || '').toLowerCase().includes(needle)) return true
  for (const sid of action.help_screen_ids ?? []) {
    const screen = screens.value.find((s) => s.id === sid)
    if ((screen?.name || '').toLowerCase().includes(needle)) return true
    if ((screen?.description || '').toLowerCase().includes(needle)) return true
    const linked = action.screens?.find((s) => s.id === sid)
    if ((linked?.name || '').toLowerCase().includes(needle)) return true
  }
  return false
}

const topTen = computed(() => {
  const q = query.value.trim().toLowerCase()
  const candidates = q
    ? actions.value.filter((action) => actionMatches(action, q))
    : actions.value.slice()
  return candidates.sort(byCountThenTitle).slice(0, 10)
})

const grouped = computed(() => {
  const byTopic = new Map<number, Action[]>()
  for (const action of topTen.value) {
    const list = byTopic.get(action.help_topic) ?? []
    list.push(action)
    byTopic.set(action.help_topic, list)
  }
  return topics.value
    .slice()
    .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
    .map((topic) => ({topic, actions: byTopic.get(topic.id) ?? []}))
    .filter((group) => group.actions.length > 0)
})

const listEmpty = computed(() => !loading.value && topTen.value.length === 0)

const missingQuery = computed(() => query.value.trim())

const mailto = computed(() => {
  const q = missingQuery.value
  const subject = q ? `Aufgabe nicht in der Hilfe: ${q}` : 'Aufgabe nicht in der Hilfe'
  return 'mailto:flow@hands-on-technology.org?subject=' + encodeURIComponent(subject)
})

function onToggle(event: Event, id: number) {
  const details = event.currentTarget as HTMLDetailsElement
  const opened = ('newState' in event && (event as ToggleEvent).newState === 'open') || details.open
  if (!opened) return
  axios.post(`/help/actions/${id}/open`).catch((e) => console.warn(e))
}

function jumpScreens(action: Action): {screen: ActionScreen; to: string}[] {
  return (action.screens ?? [])
    .map((screen) => ({screen, to: helpJumpPath(screen, eventStore.selectedEvent)}))
    .filter((row): row is {screen: ActionScreen; to: string} => row.to != null)
}

function highlight(text: string): string {
  const q = query.value.trim()
  if (!q) return escapeHtml(text)
  const lower = text.toLowerCase()
  const needle = q.toLowerCase()
  const idx = lower.indexOf(needle)
  if (idx < 0) return escapeHtml(text)
  return (
    escapeHtml(text.slice(0, idx))
    + '<mark>' + escapeHtml(text.slice(idx, idx + q.length)) + '</mark>'
    + escapeHtml(text.slice(idx + q.length))
  )
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}
</script>

<template>
  <div class="p-4 lg:p-6 max-w-3xl">
    <h1 class="text-2xl font-bold mb-4">Hilfe</h1>

    <NoticePane/>

    <input
        v-model="query"
        type="search"
        class="w-full px-3 py-2 mb-4 border border-[var(--color-border)] rounded-lg bg-white text-[var(--color-text)]"
        placeholder="Suche über alle Bereiche von FLOW …"
    />

    <p class="mb-6">
      <a
          :href="VIDEO_URL"
          target="_blank"
          rel="noopener noreferrer"
          class="inline-flex items-center gap-2 text-[var(--color-accent)] hover:underline font-medium"
      >
        <i class="bi bi-play-circle" aria-hidden="true"/>
        Einführungsvideo ansehen
      </a>
    </p>

    <h2 class="text-xl font-semibold mb-3">Typische Aufgaben</h2>

    <div v-if="listEmpty" class="help-catalog__missing" role="status">
      <p class="help-catalog__missing-text">
        <template v-if="missingQuery">
          Die Aufgabe „{{ missingQuery }}“ ist in der Hilfe noch nicht beschrieben.
        </template>
        <template v-else>
          Diese Aufgabe ist in der Hilfe noch nicht beschrieben.
        </template>
        Schreib uns an
        <a :href="mailto" class="help-catalog__missing-mail">flow@hands-on-technology.org</a>,
        dann ergänzen wir sie.
      </p>
    </div>

    <div v-else class="space-y-6">
      <section v-for="group in grouped" :key="group.topic.id">
        <h3 class="text-lg font-semibold mb-2">{{ group.topic.name }}</h3>
        <details
            v-for="action in group.actions"
            :key="action.id"
            class="glass-card liquid-surface-inner p-3 mb-2"
            @toggle="onToggle($event, action.id)"
        >
          <summary class="help-catalog__action-title" v-html="highlight(action.title)"/>
          <p class="help-catalog__action-body">{{ action.body }}</p>
          <div class="help-catalog__action-feedback">
            <HelpActionFeedback :action-id="action.id"/>
          </div>
          <div v-if="jumpScreens(action).length" class="help-catalog__pages">
            <RouterLink
                v-for="row in jumpScreens(action)"
                :key="row.screen.id"
                :to="row.to"
                class="help-catalog__page"
            >
              {{ row.screen.name }}
              <i class="bi bi-arrow-right" aria-hidden="true"/>
            </RouterLink>
          </div>
        </details>
      </section>
    </div>
  </div>
</template>

<style scoped>
.help-catalog__missing {
  padding: 0.85rem 1rem;
  border-radius: var(--radius-lg, 0.75rem);
  border: 1px solid var(--color-accent);
  background: color-mix(in srgb, var(--color-accent) 14%, #fff);
}
.help-catalog__missing-text {
  margin: 0;
  font-size: 0.9375rem;
  line-height: 1.45;
  color: var(--color-text);
}
.help-catalog__missing-mail {
  color: var(--color-accent);
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 0.12em;
}
.help-catalog__missing-mail:hover {
  text-decoration-thickness: 2px;
}
.help-catalog__action-title {
  cursor: pointer;
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1.3;
}
.help-catalog__action-body {
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
  font-weight: 400;
  line-height: 1.45;
  white-space: pre-wrap;
}
.help-catalog__action-feedback {
  margin-top: 0.5rem;
}
.help-catalog__pages {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem 0.65rem;
  margin-top: 0.75rem;
}
.help-catalog__page {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  padding: 0.15rem 0;
  color: var(--color-accent);
  font-size: 0.875rem;
  font-weight: 500;
  line-height: 1.3;
  text-decoration: none;
}
.help-catalog__page:hover {
  text-decoration: underline;
  text-underline-offset: 0.14em;
}
.help-catalog__page i {
  font-size: 0.8em;
}
</style>
