<script setup lang="ts">
import {computed, onMounted, ref} from 'vue'
import {RouterLink} from 'vue-router'
import axios from 'axios'

defineOptions({name: 'HelpCatalog'})

type Topic = {id: number; key: string; name: string; sort_order: number}
type Screen = {id: number; key: string; name: string; route_path: string; description: string | null}
type Step = {id: number; body: string; sort_order: number}
type Action = {
  id: number
  title: string
  sort_order: number
  help_topic: number
  help_screen: number
  steps: Step[]
  screen: {key: string; name: string; route_path: string} | null
  topic: {key: string; name: string} | null
}

const VIDEO_URL = 'https://handsontechnology-my.sharepoint.com/:v:/g/personal/jr_hands-on-technology_org/EYLes-Kq4GlDuBpUaxolgn4B4naGZakiVMW7Dq0xgWmskA?nav=eyJyZWZlcnJhbEluZm8iOnsicmVmZXJyYWxBcHAiOiJTdHJlYW1XZWJBcHAiLCJyZWZlcnJhbFZpZXciOiJTaGFyZURpYWxvZy1MaW5rIiwicmVmZXJyYWxBcHBQbGF0Zm9ybSI6IldlYiIsInJlZmVycmFsTW9kZSI6InZpZXcifX0%3D&e=T5yiJJ'
const MAILTO = 'mailto:flow@hands-on-technology.org?subject=' + encodeURIComponent('Frage oder Idee zu FLOW')

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

function screenFor(action: Action): Screen | undefined {
  return screens.value.find((s) => s.id === action.help_screen)
}

function actionMatches(action: Action, q: string): 'title' | 'other' | null {
  const needle = q.toLowerCase()
  if ((action.title || '').toLowerCase().includes(needle)) return 'title'
  const screen = screenFor(action)
  const other = [
    ...(action.steps ?? []).map((s) => s.body || ''),
    action.screen?.name || screen?.name || '',
    screen?.description || '',
    action.topic?.name || '',
  ].join(' ').toLowerCase()
  if (other.includes(needle)) return 'other'
  return null
}

const ranked = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) {
    return actions.value.slice().sort((a, b) => a.sort_order - b.sort_order || a.id - b.id)
  }
  const titleHits: Action[] = []
  const otherHits: Action[] = []
  for (const action of actions.value) {
    const kind = actionMatches(action, q)
    if (kind === 'title') titleHits.push(action)
    else if (kind === 'other') otherHits.push(action)
  }
  const byOrder = (a: Action, b: Action) => a.sort_order - b.sort_order || a.id - b.id
  return [...titleHits.sort(byOrder), ...otherHits.sort(byOrder)]
})

const grouped = computed(() => {
  const byTopic = new Map<number, Action[]>()
  for (const action of ranked.value) {
    const list = byTopic.get(action.help_topic) ?? []
    list.push(action)
    byTopic.set(action.help_topic, list)
  }
  return topics.value
    .map((topic) => ({topic, actions: byTopic.get(topic.id) ?? []}))
    .filter((group) => group.actions.length > 0)
})

const noActionsAtAll = computed(() => !loading.value && actions.value.length === 0)
const noHits = computed(() => !loading.value && actions.value.length > 0 && query.value.trim() !== '' && ranked.value.length === 0)

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

    <input
        v-model="query"
        type="search"
        class="w-full px-3 py-2 mb-4 border border-[var(--color-border)] rounded-lg bg-white text-[var(--color-text)]"
        placeholder="Suchen …"
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

    <p v-if="noActionsAtAll" class="text-[var(--color-text-muted)]">Noch keine Einträge.</p>
    <div v-else-if="noHits">
      <p class="text-[var(--color-text-muted)]">Keine Treffer.</p>
      <p class="text-sm text-[var(--color-text-muted)] mt-2">
        Fragen oder Ideen gerne per Mail an
        <a :href="MAILTO" class="text-[var(--color-accent)] hover:underline">flow@hands-on-technology.org</a>
      </p>
    </div>

    <div v-else class="space-y-6">
      <section v-for="group in grouped" :key="group.topic.id">
        <h3 class="text-lg font-semibold mb-2">{{ group.topic.name }}</h3>
        <details v-for="action in group.actions" :key="action.id" class="glass-card liquid-surface-inner p-3 mb-2">
          <summary class="cursor-pointer font-medium" v-html="highlight(action.title)"/>
          <ol class="list-decimal ml-5 mt-3 space-y-1 text-sm">
            <li v-for="step in action.steps" :key="step.id">{{ step.body }}</li>
          </ol>
          <p v-if="action.screen" class="mt-3 text-sm text-[var(--color-text-muted)]">{{ action.screen.name }}</p>
          <RouterLink
              v-if="action.screen"
              :to="action.screen.route_path"
              class="inline-block mt-2 glass-btn-accent !px-3 !py-1.5 !text-sm"
          >
            Zur Seite
          </RouterLink>
        </details>
      </section>
    </div>
  </div>
</template>
